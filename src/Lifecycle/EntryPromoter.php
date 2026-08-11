<?php

namespace Splicewire\Beam\Ux\Lifecycle;

use Illuminate\Auth\Access\AuthorizationException;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * Promote a tenant entry to central (ticket 06) — the one direction lacking an existing reduction
 * ("revert to inherited" is already delete; "pin an explicit tenant copy" is already ticket 05's
 * create-prefilled flow). `BeamUxEntry`'s body is pure DB-resident JSON via `BeamParticle`, no file/
 * package references, so this cross-connection copy is clean — mirrors {@see
 * \Splicewire\Beam\Ux\Theme\ThemeResolver}'s `Model::on('central')` read pattern, but as a write: the
 * PARTICLE also needs its own explicit `->on()`/`setConnection()`, not just the entry (confirmed via
 * `ParticleWriter::write()`, which persists whatever connection the passed target model carries).
 *
 * Gated directly against `rushing/laravel-permission-cascade`'s `EntitlementResolver` kernel contract
 * — the mechanism `laravel-beam-accounts`' `DefaultEntitlementResolver` implements (ACC-01) — rather
 * than a host's `author-ux-{realm}` Gate ability, since at least one host's (`laravel-beam-starter`)
 * gate still hardcodes `is_staff` directly and has not yet been rewired to consume ACC-01 (a
 * discovered gap, documented in the GOAL's Notes — out of this ticket's `laravel-beam-ux`-only scope
 * to fix). Going straight to the resolver is correct either way: it is the actual source of truth.
 *
 * `$payload` is optional and defaults to the live row's particle payload — but is a REAL parameter
 * (not hardcoded to the live row) precisely so a future caller can source it from a past
 * `RevisionRecorder` revision instead, per the ticket's explicit "don't paint into a live-row-only
 * corner" instruction. No revision-browsing UI is built here.
 */
class EntryPromoter
{
    public const CENTRAL_CONNECTION = 'central';

    public function __construct(
        private ParticleWriter $writer,
        private EntitlementResolver $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function promote(BeamUxEntry $tenantEntry, mixed $actor, ?array $payload = null): BeamUxEntry
    {
        if (! $this->authorized($actor, $tenantEntry->realm)) {
            throw new AuthorizationException(
                "Not authorized to promote into the [{$tenantEntry->realm}] realm's central root."
            );
        }

        $payload ??= $tenantEntry->particle?->payload ?? [];

        $particle = $this->writer->write(
            (new BeamParticle)->setConnection(self::CENTRAL_CONNECTION),
            $payload,
        );

        return BeamUxEntry::on(self::CENTRAL_CONNECTION)->updateOrCreate(
            ['namespace' => $tenantEntry->namespace, 'slug' => $tenantEntry->slug],
            [
                'title' => $tenantEntry->title,
                'schema_ref' => $tenantEntry->schema_ref,
                'schema_is_draft' => $tenantEntry->schema_is_draft,
                'facade_ref' => $tenantEntry->facade_ref,
                'type' => $tenantEntry->type,
                'format' => $tenantEntry->format,
                'body_style' => $tenantEntry->body_style,
                'placement_ref' => $tenantEntry->placement_ref,
                'driver_ref' => $tenantEntry->driver_ref,
                // residency_mode-only operation (charter §Q7) — never a WriteTarget concern (a
                // different axis; see ticket 06's source design note).
                'residency_mode' => BeamUxEntry::RESIDENCY_CONTEXT_FOLLOWING,
                'particle_id' => $particle->id,
                'realm' => $tenantEntry->realm,
                'realms' => $tenantEntry->realms,
                'segment' => $tenantEntry->segment,
                // parent_id is intentionally NOT copied: the tenant row's parent_id is a
                // tenant-schema uuid that may not name any row on central. Promote lands the entry
                // at the realm root's top level; re-parenting on central is a separate authoring
                // act.
            ],
        );
    }

    /**
     * Checks the grant on the AMBIENT connection, not explicitly `central` — consistent with ACC-01's
     * own stated design ("Team/BeamUxEntry/AccessGrant all follow the ambient DB connection by
     * default") and `BeamUxEntry`'s own residency docblock ("only the central path is exercised for
     * now"). Real multi-tenant activation (a tenant-scoped grant reaching this central-write gate)
     * would need this to become connection-aware; not before then.
     */
    private function authorized(mixed $actor, string $realm): bool
    {
        return in_array("author-ux-{$realm}", $this->entitlements->entitlementsFor($actor), true);
    }
}
