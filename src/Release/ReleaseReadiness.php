<?php

namespace Splicewire\Beam\Ux\Release;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Data\ReleaseReadinessData;
use Splicewire\Beam\Ux\Data\ReleaseReadinessItemData as Item;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Publish\EntryUrlChanges;

/**
 * Everything that would stop a Release publishing, each with its fix, and the notes that don't (ADR-0232 d11). Publish
 * refuses on the first reason; this runs the same checks, through the same methods, and keeps going, so the Preview bar
 * and the Release panel never promise a Publish that refuses. It reads only: no locks, no transitions.
 *
 * A stale member is reported alone: Publish refuses it before its other checks, and rebasing replaces the version
 * those checks would read.
 */
final class ReleaseReadiness
{
    public function __construct(
        private ReleaseService $releases,
        private EntryPublication $publication,
        private EntryUrlChanges $urls,
        private AuthoredComponents $components,
    ) {}

    public function report(BeamUxRelease $release): ReleaseReadinessData
    {
        if (! in_array($release->status, ReleaseLifecycle::OPEN, true)) {
            return new ReleaseReadinessData(true, [], []);
        }
        $members = $release->members()->orderBy('entry_id')->get();
        $blockers = $notes = [];
        if ($members->isEmpty()) {
            $blockers[] = new Item('empty', null, 'An empty Release cannot advance.');
        }
        if ($this->releases->awaitsReview($release)) {
            $blockers[] = new Item('review', null, 'This Release requires approval before publication.',
                match ($release->status) {
                    'draft' => ['submit'], 'in_review' => ['approve'], default => []
                });
        }
        $removed = $members->whereNull('version_id')->pluck('entry_id')->map(fn ($id) => (string) $id)->all();
        $refs = $members->whereNotNull('version_id')->mapWithKeys(fn ($member) => [(string) $member->entry_id => (string) $member->version_id])->all();
        $changes = [];
        foreach ($members as $member) {
            $id = (string) $member->entry_id;
            try {
                $entry = $this->releases->entry($release, $id);
            } catch (ModelNotFoundException) {
                $blockers[] = new Item('missing', $id, 'A Release entry no longer exists.', ['drop']);

                continue;
            } catch (ValidationException $refused) {
                $blockers[] = new Item('missing', $id, self::message($refused), ['drop']);

                continue;
            }
            if ($this->releases->memberMoved($entry, $member)) {
                $blockers[] = new Item('stale', $id, 'A publication pin or marking moved. Rebase or drop that member.', ['rebase', 'drop']);

                continue;
            }
            try {
                $this->releases->assertMemberPublishable($release, $entry, $member, $removed);
            } catch (CompilationFailed $failed) {
                $blockers[] = new Item('compile', $id, $failed->getMessage(), ['open', 'drop']);

                continue;
            } catch (ValidationException $refused) {
                $blockers[] = new Item($member->version_id === null ? 'takedown' : 'version', $id, self::message($refused), ['drop']);

                continue;
            }
            if ($member->version_id === null) {
                continue;
            }
            // assertMemberPublishable() just read this version, so it exists.
            $version = app(VersionStore::class)->version($entry->particle, (string) $member->version_id);
            $body = $version->snapshot['payload'];
            try {
                $change = $this->publication->assertVersionPublishable($entry, $version, $refs);
                if ($change !== null) {
                    $changes[$id] = $change;
                }
            } catch (ValidationException $refused) {
                // Publish names the first never-published component it meets; each one is a reason, with its own fix.
                $missing = [];
                foreach ($this->usedComponents($entry, $body, $refs) as $component) {
                    if ($component->published_version === null) {
                        $name = (string) ($component->getAttribute('component_name') ?? $component->slug);
                        $missing[] = new Item('component', $id, "Publish the component {$name} first, or include it in the same Release.",
                            ['add', 'open'], componentId: (string) $component->getKey());
                    }
                }
                $message = self::message($refused);
                if (! in_array($message, array_map(fn (Item $item) => $item->message, $missing), true)) {
                    $blockers[] = new Item('content', $id, $message, ['open', 'drop']);
                }
                $blockers = [...$blockers, ...$missing];
            }
            foreach ($this->usedComponents($entry, $body, $refs) as $component) {
                if ($component->published_version !== null && $this->publication->state($component)->draftPending) {
                    $name = (string) ($component->getAttribute('published_component_name') ?? $component->slug);
                    $notes[] = new Item('component-draft', $id, "{$name} has a draft that isn't in this Release.", ['add'],
                        componentId: (string) $component->getKey());
                }
            }
        }
        $prototype = (new BeamUxEntry)->setConnection($release->getConnectionName());
        foreach ($this->urls->problems($prototype, $changes, lock: false) as $problem) {
            $blockers[] = new Item('url', $problem['entryId'], $problem['message'],
                array_key_exists($problem['entryId'], $refs) ? ['open', 'drop'] : ['open']);
        }
        $notes = [...$notes, ...$this->inOtherReleases($release, $members->pluck('entry_id')->map(fn ($id) => (string) $id)->all())];

        return new ReleaseReadinessData($blockers === [], $blockers, $notes);
    }

    /**
     * The Authored Components a member's body uses by name that the Release does not hold.
     *
     * @param  array<string, string>  $refs
     * @return \Illuminate\Support\Collection<int, BeamUxEntry>
     */
    private function usedComponents(BeamUxEntry $entry, array $body, array $refs): \Illuminate\Support\Collection
    {
        $names = $this->components->names($body);
        if ($names === []) {
            return collect();
        }

        return $entry->newQuery()->where('realm', $entry->realm)->where('type', 'component')
            ->where(fn ($query) => $query->whereIn('component_name', $names)->orWhereIn('published_component_name', $names))
            ->whereKeyNot(array_keys($refs))->orderBy('id')->get()
            ->filter(fn (BeamUxEntry $component) => $this->components->authored($component))->values();
    }

    /**
     * A member also in another open Release is a note, with that Release's state (ADR-0232 d11).
     *
     * @param  list<string>  $entryIds
     * @return list<Item>
     */
    private function inOtherReleases(BeamUxRelease $release, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $memberships = BeamUxReleaseMember::on($release->getConnectionName())->whereIn('entry_id', $entryIds)
            ->where('release_id', '!=', $release->getKey())->orderBy('entry_id')->get();
        $open = BeamUxRelease::on($release->getConnectionName())->whereKey($memberships->pluck('release_id')->unique()->all())
            ->whereIn('status', ReleaseLifecycle::OPEN)->get()->keyBy('id');
        $notes = [];
        foreach ($memberships as $membership) {
            if (($other = $open->get($membership->release_id)) !== null) {
                [$dueAt, $timezone] = $this->schedule($other);
                $notes[] = new Item('other-release', (string) $membership->entry_id, "Also in the Release \"{$other->title}\" ({$other->status}).",
                    [], releaseId: (string) $other->getKey(), releaseTitle: (string) $other->title, releaseStatus: (string) $other->status,
                    releaseDueAt: $dueAt, releaseTimezone: $timezone);
            }
        }

        return $notes;
    }

    /**
     * When a scheduled Release will publish: its calendar action, found by the origin the Release holds. Null without
     * a schedule, or on a host without calendars.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function schedule(BeamUxRelease $release): array
    {
        $token = $release->schedule_token;
        if ($release->status !== 'scheduled' || $token === null || ! class_exists(\Splicewire\Beam\Calendars\Models\CalendarAction::class)) {
            return [null, null];
        }
        $action = \Splicewire\Beam\Calendars\Models\CalendarAction::on($release->getConnectionName())->where('origin', $token)->first();

        return $action === null ? [null, null] : [$action->due_at?->toIso8601String(), (string) $action->timezone];
    }

    private static function message(ValidationException $refused): string
    {
        return (string) (collect($refused->errors())->flatten()->first() ?? $refused->getMessage());
    }
}
