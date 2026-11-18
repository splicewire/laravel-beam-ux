<?php

namespace Splicewire\Beam\Ux\Release\Kinds;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Release\ReleaseError;
use Splicewire\Beam\Ux\Release\Sinks\PublishSinkRegistry;
use Splicewire\Beam\Ux\Release\Sinks\ReleaseProjection;
use Splicewire\Beam\Workflows\Control\TransitionContext;

/**
 * A published config-section override as a Release member (config-sections ticket 08). `member_ref` is the section
 * ROOT (e.g. `beam.ux`; a nested key such as `beam.ux.releases` is a pointer inside it, not a root); the subject is its
 * {@see ConfigOverrideScope}. Publishing the Release moves the
 * override's published pin in the SAME transaction as its entry members' pins, and — on a host that opts into the
 * mirror, in the central realm — the recording writes `config/overrides/<root>.json` into the SAME commit (ADR-0243
 * decision 4, root `config/overrides/`; the recording still refuses while tenancy is active, so a tenant Release
 * moves pins but writes no file and no commit).
 *
 * This kind lives in beam-ux, which depends DOWN on laravel-beam, and consumes laravel-beam's existing
 * {@see ConfigOverrideStore} API (`scope`, `publish`, `publishedLayer`); laravel-beam never depends on beam-ux.
 *
 * @api
 */
class ConfigOverrideMemberKind implements ReleaseMemberKind
{
    public function kind(): string
    {
        return BeamUxReleaseMember::KIND_CONFIG;
    }

    /** Lock the section scopes this Release holds, in a stable order, against a concurrent publish. */
    public function lock(BeamUxRelease $release): void
    {
        // allMembers(), NOT members(): members() is scoped whereNotNull('entry_id') and EXCLUDES config members (no entry_id),
        // so a members() query here would lock NOTHING and two Releases on the same observed pin could both pass the stale
        // check and overwrite it in turn. Lock the config scope rows FOR UPDATE on the Release's connection (Standards HIGH).
        $roots = $release->allMembers()->where('member_kind', $this->kind())->orderBy('member_ref')->pluck('member_ref')->all();
        if ($roots !== []) {
            ConfigOverrideScope::on($release->getConnectionName())->whereIn('section', $roots)->orderBy('section')->lockForUpdate()->get();
        }
    }

    /**
     * The section scope for a root, which must be RESIDENT on the Release's connection. Config is central data
     * (`ConfigOverrideScope` lives on `central`, which tenancy aliases per request); a scope resolved on another
     * connection — a central override offered to a tenant Release, or the reverse — is outside the Release's realm
     * (ticket AC: a central override can't join a tenant Release, and the reverse also holds).
     */
    public function subject(BeamUxRelease $release, string $ref): ConfigOverrideScope
    {
        // A ref that names no registered section (a typo, or a nested key such as `beam.ux.releases` under the `beam.ux`
        // root) is not a realm question: say so, rather than reporting it outside the Release's realm.
        if (app(ConfigSectionRegistry::class)->get($ref) === null) {
            $this->refuse(ReleaseError::MemberUnknownSection, ['ref' => $ref]);
        }
        // config-sections 08 + 07: the override's TIER follows the Release's REALM — a central Release carries the
        // OPERATOR tier (on `central`), a tenant Release the TENANT tier (on the tenant's own connection). The override
        // publishes through the Release's OWN connection (atomic with its entry pins), sound only when that connection
        // reaches the SAME DATABASE as the realm tier's. The exclusion keys on (realm, tier, database): a scope of another
        // realm/tier is refused even when it shares a database (a pooled/schema tenant), via residency — recorded here and
        // re-checked at publish. So a central override cannot join a tenant Release, nor the reverse.
        $tier = $this->tierOf($release);
        $releaseConn = $release->getConnection();
        if (! $this->sameDatabase($releaseConn, DB::connection(app(ConfigOverrideStore::class)->forTier($tier)->connectionName()))) {
            $this->refuse(ReleaseError::MembersOutsideRealm);
        }
        // POOLED/identical-target exclusion by REALM + tier (not database): a TENANT override must be ISOLATED from the
        // operator tier (`central`). When the tenant's store is the SAME database AND schema as central — a tenant pooled
        // by id in the central schema, or a `central` alias pointing at the tenant's schema — the operator and tenant
        // scopes collide on one row, so a tenant Release cannot carry a config override without touching operator data.
        // sameDatabase passes there, so refuse by realm/tier. (A tenant with its own schema/database is isolated → admitted;
        // a central Release on its own `central` is the normal flagship and is unaffected.)
        if ($tier === ConfigTier::Tenant && $this->sameDatabase($releaseConn, DB::connection('central'))) {
            $this->refuse(ReleaseError::MembersOutsideRealm);
        }
        $store = $this->store($release);
        $scope = $store->scope($ref);
        if ($scope === null) {
            // The registered section has no override on this realm's tier yet, so there is nothing to publish. (A tenant tier
            // with no safe storage never gets here: store()->on() runs guardTenantTarget(), which refuses on `tier` first.)
            $this->refuse(ReleaseError::MemberNoDraft, ['ref' => $ref]);
        }

        return $scope;
    }

    public function authorize(BeamUxRelease $release, object $subject, Authenticatable $actor, ReleaseAccess $access): void
    {
        // One REALM-AWARE ability governs saving a draft and publishing it (laravel-beam ticket 07): `beam-config`'s
        // declared policy resolves to the operator's staff key in the central realm and to the host's tenant_ability
        // inside a tenant, so the same check opens the operator tier for a central Release and the tenant tier for a
        // tenant Release — the staff key never opens a tenant's tier, nor a tenant ability the operator's.
        Gate::forUser($actor)->authorize(ConfigSectionAccess::OPERATE, $subject);
    }

    /** The section's current head draft (config-sections 08), which a generic putMemberByKind pins on this connection. */
    public function headVersion(BeamUxRelease $release, string $ref): ?string
    {
        return $this->subject($release, $ref)->head_version;
    }

    public function admit(BeamUxRelease $release, object $subject, BeamUxReleaseMember $member): void
    {
        /** @var ConfigOverrideScope $subject */
        if ($this->isRollback($release)) {
            // A rollback reverts the pin to a PREVIOUS version, or to null (the override is removed) — it needs no draft,
            // only that a non-null target version still exists (config-sections 08).
            if ($member->version_id !== null && $this->store($release)->versionNumber($subject, $member->version_id) === null) {
                $this->refuse(ReleaseError::EntryNoSavedVersion);
            }

            return;
        }
        $this->assertHasDraft($subject);
    }

    /** @return array<string, mixed> */
    public function columns(object $subject): array
    {
        /** @var ConfigOverrideScope $subject */
        // Record the RESOLVED residency the member was placed under (ticket AC; lead ruling 15:16Z), not the `central`
        // alias string, so a later context switch cannot carry the member across a realm boundary. publish()/rollback
        // re-check it. The subject was resolved on the Release's connection in subject(), so its resolved connection
        // identity is the Release's residency.
        return ['observed_pin' => $subject->published_version, 'member_residency' => $this->residencyOf($subject)];
    }

    /** A stale member: the section's published pin moved after it joined (ADR-0226 d6). */
    public function moved(object $subject, BeamUxReleaseMember $member): bool
    {
        /** @var ConfigOverrideScope $subject */
        return $subject->published_version !== $member->observed_pin;
    }

    public function assertPublishable(BeamUxRelease $release, object $subject, BeamUxReleaseMember $member): void
    {
        /** @var ConfigOverrideScope $subject */
        if ($this->moved($subject, $member)) {
            $this->refuse(ReleaseError::PinMoved);
        }
        if (! $this->isRollback($release)) {
            $this->assertHasDraft($subject);
        }
    }

    /**
     * Publish the section overrides: each scope's draft is promoted to its published pin ({@see ConfigOverrideStore::publish()},
     * on the Release's connection, inside the publish transaction), then ONE config projection is handed to the sinks so
     * the recording ADDS this kind's rows to the per-release receipt and the mirror files join the same commit
     * (ADR-0243 d3: a kind projects its own members in its own savepoint; d4: rows of every kind reach one commit).
     *
     * @param  Collection<int, BeamUxReleaseMember>  $members
     */
    public function publish(BeamUxRelease $release, Collection $members, Authenticatable $actor, TransitionContext $context): void
    {
        $store = $this->store($release);
        $published = [];
        foreach ($members as $member) {
            $root = $member->ref();
            $scope = $this->subject($release, $root);
            // Re-check the recorded residency against the PUBLISHING context (lead ruling 15:16Z): a member whose
            // recorded residency no longer matches where it now resolves has been carried across a context switch.
            if ($member->member_residency !== null && $member->member_residency !== $this->residencyOf($scope)) {
                $this->refuse(ReleaseError::MembersOutsideRealm);
            }
            $this->assertPublishable($release, $scope, $member);
            // Move the published pin to the member's version — the head draft for a forward publish, or the previous pin
            // (or null) for a rollback — without touching the draft or minting a version (config-sections 08).
            $store->publishVersion($root, $member->version_id);
            $published[] = $root;
        }
        if ($published !== []) {
            app(PublishSinkRegistry::class)->project($release, new ReleaseProjection($this->kind(), $published), $actor);
        }
    }

    /** The pin the member replaced; a null previous pin removes the override on rollback (ticket AC). */
    public function rollbackRef(BeamUxReleaseMember $member): ?string
    {
        return $member->observed_pin;
    }

    /** Whether this Release is a rollback (reverts its source's pins) rather than a forward publish. */
    private function isRollback(BeamUxRelease $release): bool
    {
        return $release->rollback_of !== null;
    }

    /** The tier the Release's realm writes: TENANT inside a tenant hop, OPERATOR in the central realm (07's tenant tier). */
    private function tierOf(BeamUxRelease $release): ConfigTier
    {
        return app(TenancyContext::class)->isActive() ? ConfigTier::Tenant : ConfigTier::Operator;
    }

    /** The realm name for residency — distinguishes a tenant from central even when they share a database (pooled). */
    private function realmOf(): string
    {
        return app(TenancyContext::class)->isActive() ? 'tenant' : 'central';
    }

    /** The override store for the Release's REALM tier, writing through the Release's OWN connection (config-sections 08). */
    private function store(BeamUxRelease $release): ConfigOverrideStore
    {
        return app(ConfigOverrideStore::class)->forTier($this->tierOf($release))->on($release->getConnectionName());
    }

    /** Whether two connections reach the same database: driver, host, port, database, schema/search_path. */
    private function sameDatabase(\Illuminate\Database\Connection $a, \Illuminate\Database\Connection $b): bool
    {
        $identity = fn (\Illuminate\Database\Connection $c): array => [
            $c->getDriverName(), $c->getConfig('host'), $c->getConfig('port'),
            $c->getDatabaseName(), $c->getConfig('search_path') ?? $c->getConfig('schema'),
        ];

        return $identity($a) === $identity($b);
    }

    /**
     * The resolved residency of a scope: (REALM, database). The realm (central vs tenant, from tenancy) distinguishes
     * the operator tier from a tenant tier EVEN when a pooled/schema tenant shares the database with central, so a member
     * carried across a realm boundary — a central override published under a tenant hop, or the reverse — is caught at
     * publish/rollback. The database (driver, host, port, name, schema) catches a move to a different physical store.
     */
    private function residencyOf(ConfigOverrideScope $scope): string
    {
        $c = $scope->getConnection();

        return implode('|', [$this->realmOf(), $c->getDriverName(), (string) $c->getConfig('host'), (string) $c->getConfig('port'),
            (string) $c->getDatabaseName(), (string) ($c->getConfig('search_path') ?? $c->getConfig('schema') ?? '')]);
    }

    /** A section is admissible to a Release only with a draft to publish (a head the publish will promote). */
    private function assertHasDraft(ConfigOverrideScope $scope): void
    {
        if ($scope->head_version === null || $scope->head_version === $scope->published_version) {
            $this->refuse(ReleaseError::MemberNoDraft, ['ref' => (string) $scope->section]);
        }
    }

    /** @param  array<string, scalar>  $params */
    private function refuse(ReleaseError $error, array $params = []): never
    {
        throw \Splicewire\Beam\Localization\CodedValidationException::for('release', $error, $params);
    }
}
