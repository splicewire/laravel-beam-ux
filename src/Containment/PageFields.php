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
 * The host registers the Data classes by name in `beam.ux.page_fields`; a parent page's `childFields` setting names
 * one, and that map is the only way a class is named, never client input. A class's properties are flat scalars
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

    /** @return list<string> the names a parent's `childFields` may take */
    public static function names(): array
    {
        return array_values(array_filter(array_keys(config('beam.ux.page_fields', [])), fn ($name) => self::classNamed((string) $name) !== null));
    }

    /** The class a page's fields follow under its current parent (the row's `parent_id`). */
    public static function forChild(BeamUxEntry $child): ?string
    {
        return self::forParent($child, $child->parent_id);
    }

    /**
     * The class a page's fields follow under the parent `$parentId`: the one that parent's published `childFields`
     * names. A null parent is a root page, which no parent declares fields for. The caller names the parent
     * explicitly, so a draft that moves the page (to the root, or to another parent) is checked against its new parent.
     */
    public static function forParent(BeamUxEntry $child, ?string $parentId): ?string
    {
        if ($parentId === null || ! Schema::connection($child->getConnectionName())->hasColumn('beam_ux_entries', 'child_fields')) {
            return null;
        }

        return self::classNamed($child->newQuery()->whereKey($parentId)->value('child_fields'));
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
        foreach ($class === null ? [] : array_keys(self::parameters($class)) as $name) {
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
        foreach (self::parameters($class) as $name => $parameter) {
            $declared[FrontmatterParser::canonicalize($name)] = $parameter;
        }
        $fields = [];
        $rest = [];
        foreach ($keys as $key => $value) {
            isset($declared[$key])
                ? $fields[$declared[$key]->getName()] = self::typed($declared[$key], $value)
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

    /** @return array<string, mixed> the class's JSON schema, for page settings and the Collection's Record fields */
    public static function schema(string $class): array
    {
        return app(Generator::class)->forResponse()->generate(new ReflectionClass($class));
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

    private static function typed(ReflectionParameter $parameter, string $value): mixed
    {
        $type = $parameter->getType();

        return match ($type instanceof ReflectionNamedType ? $type->getName() : 'string') {
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
