<?php

namespace Splicewire\Beam\Ux\Containment;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR-0233 d4 — the **Page Fields** a page's parent declares for its child pages. One declaration serves three readers:
 * the fields page settings shows on each child, the save check ({@see check()}), and the Record fields of the parent's
 * child-page Collection (ADR-0229 d1).
 *
 * The host registers Data classes by name in `beam.ux.page_fields`; that map is the only way a class is named,
 * never client input. ADR-0238 d3 also permits a tenant's registered schema stem through the host-supplied
 * RegisteredPageFields registry. Neither path requires a Collection marker. Page Fields remain flat scalars
 * (string, int, float, bool, each nullable), so a child's values print as frontmatter (ADR-0233 d3) and read back typed.
 * A child's values are page settings held in its version (`fields`), keyed by the class's property names.
 */
final class PageFields
{
    /** The registered name a parent may choose, or null when the host registers no class by that name. */
    public static function classNamed(?string $name): ?string
    {
        $class = $name === null ? null : (config('beam.ux.page_fields', [])[$name] ?? null);

        return is_string($class) && class_exists($class) ? $class : null;
    }

    /** A legacy host class or a tenant's registered schema stem (ADR-0238 d3). */
    public static function named(?string $name): ?string
    {
        return self::classNamed($name) ?? ($name !== null && self::registeredSchema($name) !== null ? $name : null);
    }

    private static function registeredSchema(string $name): ?array
    {
        return app()->bound(RegisteredPageFields::class) ? app(RegisteredPageFields::class)->schema($name) : null;
    }

    /** @return list<string> the names a parent's `childFields` may take */
    public static function names(): array
    {
        $classes = array_values(array_filter(array_keys(config('beam.ux.page_fields', [])), fn ($name) => self::classNamed((string) $name) !== null));
        $schemas = app()->bound(RegisteredPageFields::class) ? array_keys(app(RegisteredPageFields::class)->schemas()) : [];

        return array_values(array_unique([...$classes, ...$schemas]));
    }

    /** The class or registered schema stem a page follows under its current parent. */
    public static function forChild(BeamUxEntry $child): ?string
    {
        return self::forParent($child, $child->parent_id);
    }

    /**
     * The class or registered schema stem under `$parentId`: what that parent's published `childFields`
     * names. A null parent is a root page, which no parent declares fields for. The caller names the parent
     * explicitly, so a draft that moves the page (to the root, or to another parent) is checked against its new parent.
     */
    public static function forParent(BeamUxEntry $child, ?string $parentId): ?string
    {
        if ($parentId === null || ! Schema::connection($child->getConnectionName())->hasColumn('beam_ux_entries', 'child_fields')) {
            return null;
        }

        return self::named($child->newQuery()->whereKey($parentId)->value('child_fields'));
    }

    /**
     * An unclaimed key may name nothing a page declares: no page setting, no key registration reads onto the row, and
     * none of the Page Fields its parent declares. Otherwise the printed file would carry the key twice, or a value the
     * version does not hold.
     *
     * @param  array<string, string>  $unclaimed
     */
    public static function assertUnclaimed(?string $class, array $unclaimed): void
    {
        $declared = EntryPageSettingsData::reservedFrontmatterKeys();
        foreach ($class === null ? [] : array_keys(self::fieldTypes($class)) as $name) {
            $declared[] = FrontmatterParser::canonicalize($name);
        }
        foreach (array_keys($unclaimed) as $key) {
            if (in_array(FrontmatterParser::canonicalize((string) $key), $declared, true)) {
                throw ValidationException::withMessages(["pageSettings.unclaimed.{$key}" => 'This key is declared, so it cannot be kept as unclaimed. Set it as the page setting or Page Field it names.']);
            }
        }
    }

    /**
     * The save check: a child's fields against its parent's class, returned normalized (declaration order, typed,
     * nulls left out). A field the class doesn't declare, or any field where the parent declares none, is refused.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, scalar>
     */
    public static function check(?string $class, array $fields): array
    {
        if ($class === null) {
            if ($fields !== []) {
                throw ValidationException::withMessages(['pageSettings.fields' => 'This page\'s parent declares no Page Fields (ADR-0233 d4).']);
            }

            return [];
        }
        if (! class_exists($class)) {
            $schema = self::registeredSchema($class);
            if ($schema === null) {
                throw ValidationException::withMessages(['pageSettings.fields' => 'The parent names no available Page Fields schema.']);
            }
            try {
                $fields = app(\Splicewire\Beam\Ux\Bindings\BindingFieldEvolution::class)->pageFields($class, $fields);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['pageSettings.fields' => $e->getMessage()]);
            }
            foreach (array_keys(array_diff_key($fields, $schema['properties'])) as $name) {
                throw ValidationException::withMessages(["pageSettings.fields.{$name}" => 'The parent\'s Page Fields do not declare this field.']);
            }
            // Validate precisely what the version and frontmatter retain. In particular,
            // a required nullable field cannot pass as null and disappear after validation.
            $fields = array_filter($fields, static fn ($value) => $value !== null);
            // The declared shape is an object even when no optional fields were entered.
            // Preserve {} through the shared gate's array envelope instead of validating [].
            if (! app(\Schemastud\DataSchemas\Migration\AcceptanceGate::class)->accepts(
                ['fields' => (object) $fields],
                ['type' => 'object', 'properties' => ['fields' => $schema]],
            )) {
                throw ValidationException::withMessages(['pageSettings.fields' => 'These Page Fields do not conform to the parent\'s registered schema.']);
            }

            return $fields;
        }
        $parameters = self::parameters($class);
        foreach (array_keys(array_diff_key($fields, $parameters)) as $name) {
            throw ValidationException::withMessages(["pageSettings.fields.{$name}" => 'The parent\'s Page Fields do not declare this field.']);
        }
        try {
            $data = $class::validateAndCreate($fields);
        } catch (ValidationException $failure) {
            $errors = [];
            foreach ($failure->errors() as $key => $messages) {
                $errors["pageSettings.fields.{$key}"] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $out = [];
        foreach (array_keys($parameters) as $name) {
            if ($data->{$name} !== null) {
                $out[$name] = $data->{$name};
            }
        }

        return $out;
    }

    /**
     * Split frontmatter keys into the class's fields, typed by its properties, and the rest.
     *
     * @param  array<string, string>  $keys  canonical snake_case frontmatter keys
     * @return array{0: array<string, scalar>, 1: array<string, string>}
     */
    public static function fromFrontmatter(?string $class, array $keys): array
    {
        if ($class === null) {
            return [[], $keys];
        }
        $declared = [];
        foreach (self::fieldTypes($class) as $name => $type) {
            $declared[FrontmatterParser::canonicalize($name)] = ['name' => $name, 'type' => $type];
        }
        $fields = [];
        $rest = [];
        foreach ($keys as $key => $value) {
            isset($declared[$key])
                ? $fields[$declared[$key]['name']] = self::typed($declared[$key]['type'], $value)
                : $rest[$key] = $value;
        }

        return [$fields, $rest];
    }

    /**
     * A child's fields as frontmatter values, in the order they are held.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    public static function toFrontmatter(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $out[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> the declared schema for page settings and child-page Collection fields */
    public static function schema(string $class): array
    {
        if (! class_exists($class)) {
            return self::registeredSchema($class) ?? throw new InvalidArgumentException('No available registered Page Fields schema.');
        }

        return app(Generator::class)->forResponse()->generate(new ReflectionClass($class));
    }

    /** @return array<string, string> scalar types shared by schema and class-backed frontmatter */
    private static function fieldTypes(string $class): array
    {
        if (class_exists($class)) {
            return array_map(static fn (ReflectionParameter $parameter) => $parameter->getType()->getName(), self::parameters($class));
        }
        $types = [];
        foreach (self::schema($class)['properties'] as $name => $field) {
            $type = array_values(array_diff((array) $field['type'], ['null']))[0];
            $types[$name] = match ($type) {
                'integer' => 'int', 'number' => 'float', 'boolean' => 'bool', default => 'string',
            };
        }

        return $types;
    }

    /** @return array<string, ReflectionParameter> keyed by property name, in declaration order */
    private static function parameters(string $class): array
    {
        $parameters = [];
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || ! in_array($type->getName(), ['string', 'int', 'float', 'bool'], true)) {
                throw new InvalidArgumentException("Page Fields class [{$class}] declares [{$parameter->getName()}] as a non-scalar; Page Fields are flat scalars (ADR-0233 d4).");
            }
            if (in_array(FrontmatterParser::canonicalize($parameter->getName()), EntryPageSettingsData::reservedFrontmatterKeys(), true)) {
                throw new InvalidArgumentException("Page Fields class [{$class}] declares [{$parameter->getName()}], which a page setting already names; a Page Field needs its own key.");
            }
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }

    private static function typed(string $type, string $value): mixed
    {
        return match ($type) {
            'bool' => match ($value) {
                'true' => true,
                'false' => false,
                default => $value,
            },
            'int' => preg_match('/^-?[0-9]+$/D', $value) === 1 ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            default => $value,
        };
    }
}
