<?php

namespace Splicewire\Beam\Ux\Intake;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Intake\PublicIntakeWriteGate;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

/**
 * EP-07d — the page forms that write to the submissions ledger through laravel-beam's public intake door
 * (`POST /beam/intake/{schema}`, `beam.intake.submit`). An `IntakeForm` node names the door's capture key, and this
 * resolves the key in the door's own order (`PublicIntakeController`: the `beam.core.intake.slugs` map, then the
 * `public_schemas` allow-list, then the schema registry). So an author can place a form only where the door would
 * accept its submissions, and a page carries the schema a form renders only for such a key.
 */
final class IntakeForms
{
    public const NODE = 'IntakeForm';

    public function __construct(private SchemaTargetResolver $targets) {}

    /** Is the public intake door on for this host (laravel-beam mounts it only then)? Without it a form has nowhere to post. */
    public static function doorEnabled(): bool
    {
        return (bool) config('beam.core.intake.enabled', false);
    }

    /**
     * The schema a submission addressed to `$captureKey` is checked against, or null where the door would refuse it:
     * the door is off, the schema is unknown, or it is not on the public allow-list.
     *
     * @return array<string, mixed>|null
     */
    public function schemaFor(string $captureKey): ?array
    {
        if ($captureKey === '' || ! self::doorEnabled()) {
            return null;
        }
        $slugs = (array) config('beam.core.intake.slugs', []);
        $stem = isset($slugs[$captureKey]) && $slugs[$captureKey] !== '' ? (string) $slugs[$captureKey] : $captureKey;
        if (! (new PublicIntakeWriteGate((array) config('beam.core.intake.public_schemas', [])))->authorizes($stem, [])) {
            return null;
        }
        $schema = $this->targets->targetFor($stem);

        return $schema === [] ? null : $schema;
    }

    /**
     * The save check: every changed `IntakeForm` names a capture key the door accepts, so no page posts to a private or
     * missing schema.
     *
     * @param  array<int, array<string, mixed>>  $body
     * @param  array<string, true>|null  $checkIds  the changed node ids (null: every node)
     */
    public function assertBody(array $body, ?array $checkIds = null): void
    {
        $refused = [];
        foreach ($this->forms($body) as [$path, $node]) {
            $id = isset($node['id']) ? (string) $node['id'] : null;
            if ($checkIds !== null && $id !== null && ! isset($checkIds[$id])) {
                continue;
            }
            $key = self::captureKey($node);
            if ($key === null || $this->schemaFor($key) === null) {
                $refused[] = ($id !== null ? "node #{$id}" : 'node')." at [{$path}] posts to [".($key ?? '').'], which is not a public intake schema on this host';
            }
        }
        if ($refused !== []) {
            throw ValidationException::withMessages(['body' => $refused]);
        }
    }

    /**
     * The schema each form in the body renders, by capture key, for the keys the door accepts (EP-07d slice 2).
     *
     * @param  array<int, array<string, mixed>>  $body
     * @return array<string, array<string, mixed>>
     */
    public function schemasFor(array $body): array
    {
        $schemas = [];
        foreach ($this->forms($body) as [, $node]) {
            $key = self::captureKey($node);
            if ($key !== null && ! isset($schemas[$key]) && ($schema = $this->schemaFor($key)) !== null) {
                $schemas[$key] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * Every `IntakeForm` node in a JsonDoc, with its path, through children, slots and a Repeat's empty state.
     *
     * @param  array<int|string, mixed>  $nodes
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function forms(array $nodes, string $prefix = ''): array
    {
        $found = [];
        foreach ($nodes as $i => $node) {
            if (! is_array($node) || ($node['kind'] ?? null) !== 'block') {
                continue;
            }
            $path = $prefix === '' ? (string) $i : "{$prefix}.{$i}";
            if (($node['name'] ?? null) === self::NODE) {
                $found[] = [$path, $node];
            }
            $found = [...$found, ...$this->forms($node['children'] ?? [], $path), ...$this->forms($node['repeat']['empty'] ?? [], $path.'.empty')];
            foreach ((array) ($node['slots'] ?? []) as $slot => $children) {
                $found = [...$found, ...$this->forms(is_array($children) ? $children : [], "{$path}.slots.{$slot}")];
            }
        }

        return $found;
    }

    /** @param  array<string, mixed>  $node */
    private static function captureKey(array $node): ?string
    {
        foreach ($node['props'] ?? [] as $prop) {
            if (($prop['name'] ?? null) === 'captureKey' && ($prop['kind'] ?? null) === 'string' && is_string($prop['value'] ?? null)) {
                return $prop['value'];
            }
        }

        return null;
    }
}
