<?php

namespace Splicewire\Beam\Ux\Release;

use Splicewire\Beam\Ux\Containment\Reach;
use Splicewire\Beam\Ux\Data\ReleaseChangesData;
use Splicewire\Beam\Ux\Data\ReleaseMemberReachData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The pages a Release changes (ADR-0232 d10): its own pages first, at the paths its Preview answers, then, under each
 * component, layout, template or theme member, what that member reaches. A Take-down has no page to open.
 */
final class ReleaseChanges
{
    public function __construct(private Reach $reach) {}

    public function of(BeamUxRelease $release): ReleaseChangesData
    {
        $preview = new PreviewContext(self::grant($release));
        $pages = $reach = [];
        $members = $release->members()->whereNotNull('version_id')->orderBy('entry_id')->get();
        $entries = BeamUxEntry::on($release->getConnectionName())->whereKey($members->pluck('entry_id')->all())->get()->keyBy('id');
        foreach ($members as $member) {
            $entry = $entries->get($member->entry_id);
            if ($entry === null) {
                continue;
            }
            if ($entry->type === UxType::Page) {
                $pages = [...$pages, ...$this->reach->of($entry, $preview)->pages];

                continue;
            }
            $kind = $entry->type instanceof UxType ? $entry->type->value : (string) $entry->type;
            if (! in_array($kind, ['component', 'layout', 'template', 'theme'], true)) {
                continue;
            }
            $of = $this->reach->of($entry, $preview);
            $name = (string) ($entry->getAttribute('component_name') ?? $entry->getAttribute('published_component_name') ?? $entry->slug ?? $entry->title);
            $reach[] = new ReleaseMemberReachData((string) $entry->getKey(), $name, $kind, $of->everyPage, $of->count, $of->pages);
        }

        return new ReleaseChangesData($pages, $reach);
    }

    /** The Release-scoped grant its Preview reads through, built in memory and never saved. */
    private static function grant(BeamUxRelease $release): BeamUxPreviewGrant
    {
        $grant = new BeamUxPreviewGrant;
        $grant->setConnection($release->getConnectionName());
        $grant->forceFill(['release_id' => $release->getKey(), 'created_by' => 'release']);
        $grant->setRelation('release', $release);

        return $grant;
    }
}
