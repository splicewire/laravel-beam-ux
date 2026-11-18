<?php

namespace Splicewire\Beam\Ux\Release;

/**
 * Marks the span in which a Release's own Publish applies its members (launch ticket 77): the one place an entry's
 * workflow marking may enter or leave `published` while `beam.ux.releases.review_required` is on.
 *
 * {@see \Splicewire\Beam\Ux\Models\BeamUxEntry::participateInWorkflowTransaction()} refuses such a move outside this
 * span, so no actuator caller (the calendar, Circuit and Reaction actions, tower's workflow endpoint, a host that
 * registers an entry subject resolver) can re-show or take down a page past review. The span admits ONLY the Release's
 * own member entries: host code that runs inside a Release publish (a publish sink, an observer) still cannot move an
 * unrelated entry. Frames are released in `finally`, so a nested or failed publish can never leave one open for a
 * long-lived worker's next job.
 *
 * @internal opened only by {@see ReleaseService::participate()}, after the Release's review and member checks.
 */
final class ReleasePublicationScope
{
    /** @var list<array<string, true>> one frame per open Release publish: its member entry ids */
    private static array $frames = [];

    /**
     * @template T
     *
     * @param  list<string>  $entryIds  the Release's member entries, the only ones this publish may move
     * @param  callable(): T  $publish
     * @return T
     */
    public static function run(array $entryIds, callable $publish): mixed
    {
        self::$frames[] = array_fill_keys(array_map('strval', $entryIds), true);
        try {
            return $publish();
        } finally {
            array_pop(self::$frames);
        }
    }

    /** Whether an open Release publish has this entry as a member. */
    public static function admits(string $entryId): bool
    {
        foreach (self::$frames as $frame) {
            if (isset($frame[$entryId])) {
                return true;
            }
        }

        return false;
    }

    public static function active(): bool
    {
        return self::$frames !== [];
    }
}
