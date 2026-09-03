<?php

namespace Splicewire\Beam\Ux\Query;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Rushing\DataFilters\Query\ResourceQuery;

/**
 * The base query behind the `beam-ux-entry` data-filters resource — the one
 * {@see \Splicewire\Beam\Ux\Data\BeamUxEntryData}'s `#[ParticleResource]` promises by being
 * `filterable` and, until beam-docs-satellite ticket 50, never shipped.
 *
 * ## The promise, and the 500 behind it
 *
 * `ParticleResource` defaults `filterable` to **true** — `BeamUxEntryData` never spells it out, it
 * simply does not opt out — and `ParticleController::index()` sends a filterable resource through
 * `hydrator->query($key)`. At a host binding tower's `DataFilterRecordHydrator` (the flagship does)
 * that raises `BadMethodCallException` on a key with no data-filters registration. Measured over an
 * in-process authenticated request at `~/Herd/splicewire-app` on 2026-08-31: `GET
 * /api/v1/beam-ux-entries` was a **500** — *"No data-filters resource is registered under
 * [beam-ux-entry]"* — against a table holding 33 rows. Exactly the defect beam core repaired for its
 * own `hooks` key ({@see \Splicewire\Beam\Query\HookResourceQuery}) and this package was carrying for
 * its flagship resource the whole time.
 *
 * `filterable: false` is not the escape: that path is `defaultSortedQuery()`, which cannot see the
 * request, and it would demote the resource every authoring surface in the family lists.
 *
 * ## Why a class at all, rather than the stock `ResourceQuery`
 *
 * Two reasons, and the second is the load-bearing one.
 *
 * `ResourceQuery` is **abstract**, so a registration needs a concrete subclass whatever it does.
 *
 * And `BeamUxEntryData` declares **no** `#[Sortable(default: true)]`, so with the inherited bare
 * `Model::query()` an unsorted `GET .../beam-ux-entries` comes back in whatever order Postgres feels
 * like. On a *paginated* endpoint an unstable order is not cosmetic — rows repeat and vanish across
 * pages, which reads as missing data rather than as a missing ORDER BY. `namespace, slug` is the
 * order every other beam-ux listing already presents entries in (the sitemap, the mirror-status
 * "Files" tool, `splicewire:beam:ux:entries`); `id` is the tiebreak that makes it total, since
 * `(namespace, slug)` is unique only per realm.
 *
 * There is deliberately **no row filter here**. `BeamUxEntryData` declares no `scope()`, and inventing
 * one here would quietly turn ordering wiring into an authorization boundary nothing else in the surface
 * honours, which is the worse of the two failures because it would look like it worked.
 *
 * ⚠️ An earlier version of this docblock then said "no policy is registered for BeamUxEntry; the read
 * guard is the schema the connection resolves to, plus the middleware on each host's mount".
 * beam-docs-satellite 65 measured that sentence for what it is — prose NOMINATING a gate no code
 * enforced (the flagship mounted this index centrally, with neither) — and it is stale in both halves:
 * {@see \Splicewire\Beam\Ux\Models\BeamUxEntry} carries `#[UseCascadePolicy]` since api-surface-coherence
 * 147 (`0d3ca6b`), and that bound policy is what `ParticleController::index()`'s read gate reads. A host
 * mounting this resource with no tenancy and no policy answers 403, not every row.
 */
class BeamUxEntryResourceQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return ($this->definition->requireModel())::query()
            ->orderBy('namespace')
            ->orderBy('slug')
            ->orderBy('id');
    }
}
