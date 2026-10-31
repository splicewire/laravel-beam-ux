<?php

namespace Splicewire\Beam\Ux\Containment;

use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Schema\SchemaId;

/** The host supplies its tenant registry; no global registry fallback (ADR-0238 d3). */
final class RegisteredPageFields
{
    public function __construct(private SchemaRegistry $registry, private bool $enabled = true) {}

    /** @return array<string, array<string, mixed>> latest flat Page Fields schemas, keyed by stem */
    public function schemas(): array
    {
        if (! $this->enabled) {
            return [];
        }
        $latest = [];
        foreach ($this->registry->ids() as $id) {
            try {
                $parsed = SchemaId::from($id);
            } catch (InvalidArgumentException) {
                continue;
            }
            $version = $parsed->version();
            if ($version !== null && filter_var($parsed->stem(), FILTER_VALIDATE_URL)
                && $version > ($latest[$parsed->stem()]['version'] ?? 0)) {
                $latest[$parsed->stem()] = ['version' => $version, 'id' => $id];
            }
        }
        $schemas = [];
        foreach ($latest as $stem => $entry) {
            $schema = $this->registry->get($entry['id']);
            if ($schema !== null && $this->isFlat($schema)) {
                $schemas[$stem] = $schema;
            }
        }
        ksort($schemas);

        return $schemas;
    }

    /** @return array<string, mixed>|null */
    public function schema(string $stem): ?array
    {
        return $this->schemas()[$stem] ?? null;
    }

    private function isFlat(array $schema): bool
    {
        if (($schema['type'] ?? null) !== 'object' || ! is_array($schema['properties'] ?? null)) {
            return false;
        }
        foreach ($schema['properties'] as $name => $field) {
            if (! is_array($field)) {
                return false;
            }
            $types = (array) ($field['type'] ?? []);
            if (! is_string($name) || $types === []
                || array_diff($types, ['string', 'integer', 'number', 'boolean', 'null']) !== []
                || count(array_intersect($types, ['string', 'integer', 'number', 'boolean'])) !== 1
                || in_array(\Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser::canonicalize($name), \Splicewire\Beam\Ux\Data\EntryPageSettingsData::reservedFrontmatterKeys(), true)) {
                return false;
            }
        }

        return true;
    }
}
