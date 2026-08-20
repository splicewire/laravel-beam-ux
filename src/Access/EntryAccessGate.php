<?php

namespace Splicewire\Beam\Ux\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Sitemap\EntrySitemapSource;

/**
 * The **actor-aware** third entry gate (ADR-0212 §1) — the port that supplies the gate semantics
 * ADR-0209 §5 deferred. It joins {@see EntryPublishGate} and {@see EntryEntitlementGate} rather than
 * replacing either, and the decisive reason is in the signatures:
 *
 *  - `EntryPublishGate::isPublished(BeamUxEntry): bool` and `EntryEntitlementGate::isPublic(BeamUxEntry): bool`
 *    take **no actor** — they answer *"is this row crawlable by anyone?"*
 *  - this port takes one — it answers *"may this reader see it?"*
 *
 * Those are not two implementations of one question, so neither stack subsumes the other and no amount
 * of re-binding makes one do the other's job. Hence **three ports, two consumers**:
 *
 *  - {@see EntrySitemapSource} (no actor — a crawler, a console build) keeps asking the publish +
 *    entitlement pair: *anonymous crawlability*.
 *  - the public renderer and {@see NavProjector} ask the publish gate + this one: *this reader's
 *    authorization*. {@see EntryEntitlementGate} leaves the render path entirely.
 *
 * The payoff is the case a single caller-independent boolean cannot express: an `auth`-gated entry is
 * correctly **absent from the sitemap** and correctly **renderable to a logged-in reader**.
 *
 * **Tokens are opaque to beam-ux** (ADR-0212 §5, ADR-0092): the columns store plain strings and this
 * package owns no RBAC vocabulary, exactly as ADR-0122 kept "gated" opaque in beam-mdx. beam-ux
 * therefore cannot validate a token — that is the binding's {@see self::knows()} capability, consumed
 * by {@see RegisterEntriesFromDisk} at import and by the standing doctor check. At runtime an unknown
 * token already fails closed for free: under any-of, a token nothing satisfies never matches, so a typo
 * denies rather than admits — a lockout, which is loud and self-correcting, never a leak.
 */
interface EntryAccessGate
{
    /**
     * Whether `$actor` holds `$right` on `$entry`. This is the evaluation of ONE row's token list, not
     * the ancestor walk — conjunction up the chain is {@see EntryAccessResolver}'s job, so a host
     * re-binding this port never has to reimplement inheritance.
     */
    public function allows(?Authenticatable $actor, BeamUxEntry $entry, Right $right): bool;

    /**
     * Whether this binding recognises a token at all. A binding that cannot enumerate its vocabulary
     * returns `true` unconditionally and simply skips validation — so `knows()` never becomes a
     * requirement that blocks an exotic custom gate.
     */
    public function knows(string $token): bool;
}
