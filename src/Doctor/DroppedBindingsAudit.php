<?php

namespace Splicewire\Beam\Ux\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Collections\CollectionDefinition;
use Splicewire\Beam\Collections\CollectionSchemaCatalog;
use Splicewire\Beam\Ux\Bindings\BindingFieldEvolution;
use Splicewire\Beam\Ux\Bindings\DroppedBindingDiagnostics;
use Splicewire\Beam\Ux\Bindings\ProfileCollections;
use Splicewire\Beam\Ux\Bindings\PublishedBindingDocument;
use Splicewire\Beam\Ux\Bindings\RegisteredCollectionAliases;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Operator-only, read-only active-connection inspection. It does not manufacture an author offer or read Records. */
final class DroppedBindingsAudit implements DoctorAudit
{
    public function __construct(
        private BindingFieldEvolution $evolution,
        private DroppedBindingDiagnostics $diagnostics,
        private CanvasBodyGuard $bodies,
        private PublishedBindingDocument $published,
        private ?SchemaRegistry $registry = null,
    ) {}

    public function run(): array
    {
        if ($this->registry === null) {
            return [Finding::inconclusive('Dropped bindings', 'No schema registry is bound; no bodies examined.')];
        }
        $model = new BeamUxEntry;
        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            return [Finding::inconclusive('Dropped bindings', 'The entry table is unavailable; no bodies examined.')];
        }
        // The author manifest is actor-gated. Like the pre-freeze guard, the operator inspects the
        // actual registry and shared alias naming, without changing that gate or creating an offer.
        $aliases = RegisteredCollectionAliases::fromAddresses((new CollectionSchemaCatalog($this->registry))->addresses());
        $offers = [];
        foreach ($aliases as $name => $stem) {
            $offers[] = ['name' => $name, ...$this->scope($stem)];
        }
        $out = [];
        $examined = 0;
        $nodes = 0;
        $incomplete = 0;
        foreach ($model->newQuery()->cursor() as $entry) {
            try {
                $entryOffers = array_column($offers, null, 'name');
                $profiles = app(ProfileCollections::class);
                foreach ($profiles->sources($entry) as $name => $source) {
                    // Preserve actual host-owned resource/child-page offers beside registered Collections.
                    $entryOffers[$name] = ['name' => $name, 'source' => $source, 'schema' => $profiles->fieldSchema($source)];
                    $stem = $this->evolution->stem($entry, $source);
                    if ($stem !== null) {
                        $entryOffers[$name]['schemaStem'] = $stem;
                    }
                }
                $working = $this->bodies->workingRecordPage($entry);
                $publishedSettings = $this->published->pageSettings($entry);
                $published = array_key_exists('recordPage', $publishedSettings) ? $publishedSettings['recordPage'] : $entry->getOriginal('record_page');
                foreach (['working' => [$this->bodies->workingHead($entry), $working],
                    'published' => [$entry->published_version === null ? [] : $this->published->read($entry), $published]] as $phase => [$body, $recordPage]) {
                    if (! JsonDocShape::is($body)) {
                        continue;
                    }
                    $root = is_array($recordPage) && isset($entryOffers[$recordPage['collection'] ?? ''])
                        ? [...$entryOffers[$recordPage['collection']], 'recordPageRoot' => true] : null;
                    $issues = $this->diagnostics->inspect($entry, $body, ['x-collections' => array_values($entryOffers), 'x-record' => $root],
                        function ($scope, $relation) {
                            $schema = $this->latest($scope['schemaStem'] ?? throw new \LogicException('This host-owned source has no inspectable registered relation schema.'));
                            $edge = (new CollectionDefinition($schema))->relations()[$relation] ?? null;
                            if ($edge === null || ! $edge['many']) {
                                throw new \LogicException('A stored relation has no current to-many declaration.');
                            }

                            return $this->scope($edge['collection']);
                        });
                    if ($root === null && is_array($recordPage)) {
                        $issues['/'][] = 'Record Page Collection '.($recordPage['collection'] ?? '?').' is unavailable';
                    }
                    $examined++;
                    $nodes += count($issues);
                    foreach ($issues as $path => $fields) {
                        $out[] = Finding::warn('Dropped bindings', 'Entry '.$entry->getKey().' '.$phase.' node '.$path.': '.implode(', ', $fields).'. Rebind or remove this node; its missing values read empty.');
                    }
                }
            } catch (\Throwable $error) {
                $incomplete++;
                // A schema/connection/codec failure is not absence and cannot produce a false clean count.
                $out[] = Finding::inconclusive('Dropped bindings', 'Entry '.$entry->getKey().' could not be fully inspected: '.$error->getMessage());
            }
        }
        $summary = $nodes.' affected structural nodes across '.$examined.' inspected working/published JsonDoc bodies on this connection.';
        $out[] = $incomplete > 0 || $examined === 0
            ? Finding::inconclusive('Dropped bindings', $summary.' '.$incomplete.' entries incomplete; this is not a clean-bill result.')
            : ($nodes > 0 ? Finding::warn('Dropped bindings', $summary) : Finding::pass('Dropped bindings', $summary));

        return $out;
    }

    private function latest(string $stem): array
    {
        $history = $this->evolution->history($stem);

        return $history[array_key_last($history)] ?? throw new \LogicException('A declared Collection has no frozen history.');
    }

    private function scope(string $stem): array
    {
        $schema = $this->latest($stem);

        return ['schemaStem' => $stem, 'schema' => ['properties' => ['fields' => $schema],
            '$defs' => $schema['$defs'] ?? [], 'definitions' => $schema['definitions'] ?? []], 'hasRecordPage' => false];
    }
}
