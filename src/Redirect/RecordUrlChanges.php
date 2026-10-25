<?php

namespace Splicewire\Beam\Ux\Redirect;

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Ux\Bindings\ProfileCollections;
use Splicewire\Beam\Ux\Bindings\RecordPages;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * A Record's URL change leaves a Redirect (ADR-0236 d3; EP-31 ruling 2). When the field its Collection's one Record
 * Page binds by (ADR-0229 d11) changes on a Record, caught on the write signal every pipeline write emits, an exact
 * Redirect goes from the old URL to the Record by identity, so the old URL reaches it at whatever URL it has now. A
 * write that bypasses `ParticleWriter` emits no signal and leaves none.
 */
final class RecordUrlChanges
{
    public function __construct(
        private ProfileCollections $profiles,
        private RecordPages $records,
        private EntryPathResolver $paths,
        private PublicEntryGate $gate,
    ) {}

    public function handle(BeamParticlePersisted $event): void
    {
        $record = $event->record;
        $stem = method_exists($record, 'recordType') ? $record->recordType() : null;
        $previous = $record->getPrevious()['payload'] ?? null;
        $before = is_string($previous) ? json_decode($previous, true) : $previous;
        if ($stem === null || ! is_array($before) || ! Schema::connection($record->getConnectionName())->hasTable('beam_ux_redirects')) {
            return;
        }
        foreach ($this->recordPages($stem) as $realm => [$page, $collection]) {
            $field = (string) $page->getAttribute('record_page')['field'];
            $was = RecordPages::slug($before[$field] ?? null);
            if ($was === null || $was === RecordPages::slug($event->payload[$field] ?? null)) {
                continue;
            }
            $from = $this->records->url($page, ['fields' => [$field => $was]]);
            if ($from === null) {
                continue;
            }
            BeamUxRedirect::on($record->getConnectionName())
                ->firstOrNew(['realm' => $realm, 'path' => $from, 'match' => 'exact'])
                ->forceFill(['target_kind' => 'record', 'target_collection' => $collection, 'target_record_id' => (string) $record->getKey(),
                    'target_entry_id' => null, 'target_path' => null, 'target_url' => null, 'permanent' => true,
                    'origin' => 'record', 'origin_ref' => (string) $record->getKey()])
                ->save();
        }
    }

    /**
     * Each realm's one visible Record Page whose Collection is this stem, with that Collection's key. Two Record Pages
     * for one Collection name no single URL, so neither leaves a Redirect.
     *
     * @return array<string, array{0: BeamUxEntry, 1: string}>
     */
    private function recordPages(string $stem): array
    {
        $found = [];
        foreach (BeamUxEntry::query()->whereNotNull('record_page')->where('type', UxType::Page->value)->get() as $page) {
            $collection = (string) ($page->getAttribute('record_page')['collection'] ?? '');
            $source = $this->profiles->sources($page)[$collection] ?? null;
            $ancestry = $this->paths->ancestry($page);
            if (($source?->address['collection'] ?? null) !== $stem || $ancestry === null || $this->gate->hidden($ancestry)) {
                continue;
            }
            foreach ((array) ($page->realms ?? [$page->realm]) as $realm) {
                $found[$realm][] = [$page, $collection];
            }
        }

        return array_map(fn (array $pages) => $pages[0], array_filter($found, fn (array $pages) => count($pages) === 1));
    }
}
