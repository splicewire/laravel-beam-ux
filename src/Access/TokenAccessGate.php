<?php

namespace Splicewire\Beam\Ux\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The default {@see EntryAccessGate} binding — the **any-of token-list evaluator** descended from
 * `Splicewire\Tower\Navigation\Gates\AccessGate` (ADR-0212 §6). It carried no tower vocabulary to begin
 * with (Laravel auth contracts, a hardcoded `'Root'` role string, and ADR-0118's morph-alias `can()`
 * convention), and the two host-specific values become config on the way down:
 * `beam.ux.access.root_role` and `beam.ux.access.extra_tokens`.
 *
 * Leaving it in tower and authoring a second default here was rejected as two implementations of one
 * gate — the exact failure mode that makes the app's refactor onto the packaged surface mandatory.
 *
 * A token is one of:
 *   - `root`  — the actor holds the configured root role (`hasRole('Root')` by default; `Gate::before`
 *               additionally bypasses every `can()` for Root, so its elevated access follows it onto
 *               central and every tenant subdomain);
 *   - `auth`  — any authenticated actor (denies the anonymous visitor);
 *   - `<alias>.<verb>` — a morph-alias permission the actor `can()` (ADR-0118), evaluated live against
 *               the active tenant scope.
 *
 * **Degrades headless.** beam-ux must stay installable with no RBAC package present, so `root` probes
 * `hasRole` with `method_exists` and a permission token requires the actor to be {@see Authorizable} —
 * with neither present the gate simply denies those tokens rather than fataling. `auth` keeps working
 * on Laravel's own contracts alone.
 *
 * **Null versus empty is the whole distinction.** A NULL column is *no declaration*, so it imposes no
 * constraint and the entry transparently inherits its ancestors' (handled in
 * {@see EntryAccessResolver}). A declared-but-EMPTY list `[]` denies — secure-by-omission, inherited
 * from the tower gate: an empty any-of list satisfies nothing.
 */
class TokenAccessGate implements EntryAccessGate
{
    /** The reserved sentinel meaning "the root role only". */
    public const ROOT = 'root';

    /** The reserved sentinel meaning "any authenticated actor". */
    public const AUTH = 'auth';

    /** The role a `root`-gated token resolves against when config names none. */
    public const DEFAULT_ROOT_ROLE = 'Root';

    /**
     * @param  string  $rootRole  the role name the `root` sentinel resolves against
     * @param  list<string>  $extraTokens  host tokens {@see knows()} should recognise beyond the
     *                                     sentinels and the `alias.verb` shape
     */
    public function __construct(
        private string $rootRole = self::DEFAULT_ROOT_ROLE,
        private array $extraTokens = [],
    ) {}

    public function allows(?Authenticatable $actor, BeamUxEntry $entry, Right $right): bool
    {
        $tokens = $entry->tokensFor($right);

        // No declaration ⇒ no constraint. Inheritance falls out of conjunction up the chain
        // (ADR-0212 §3), so a row with nothing declared contributes nothing and passes here.
        if ($tokens === null) {
            return true;
        }

        foreach ($tokens as $token) {
            if ($this->allowsToken($actor, $token)) {
                return true;
            }
        }

        // Reached on an explicitly-empty list too — secure-by-omission.
        return false;
    }

    /**
     * Recognises the two sentinels, any configured host token, and the `alias.verb` permission SHAPE
     * (ADR-0118) — which is as far as enumeration goes without depending on an RBAC package. That is
     * deliberately enough to catch the failure this validates against: a typo'd bare word (`athu`,
     * `roto`) that would silently lock a page out. A host with a real permission registry binds a gate
     * whose `knows()` consults it, or returns `true` to opt out of validation entirely.
     */
    public function knows(string $token): bool
    {
        if ($token === self::AUTH || $token === self::ROOT) {
            return true;
        }

        if (in_array($token, $this->extraTokens, true)) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+$/', $token);
    }

    /** Whether the actor satisfies one token. */
    private function allowsToken(?Authenticatable $actor, string $token): bool
    {
        if ($token === self::AUTH) {
            return $actor !== null;
        }

        if ($token === self::ROOT) {
            return $actor !== null
                && method_exists($actor, 'hasRole')
                && $actor->hasRole($this->rootRole);
        }

        // A morph-alias permission token — a live, tenant-scoped `can()` check. Root bypasses via
        // `Gate::before`, so its access follows everywhere.
        return $actor instanceof Authorizable && $actor->can($token);
    }
}
