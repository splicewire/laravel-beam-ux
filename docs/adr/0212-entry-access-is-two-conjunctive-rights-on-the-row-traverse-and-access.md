# ADR-0212 — Entry access is two conjunctive rights on the row: `traverse` and `access`

Status: **accepted** — beam-docs-satellite ticket 15, 2026-08-19.
Date: 2026-08-19
Builds on: **ADR-0165** (content organization is containment in a realm-rooted sitemap tree),
**ADR-0166 §4** (the published-marking gate), **ADR-0209** (the public entry renderer — this ADR
supplies the gate semantics 0209 §5 deferred), **ADR-0092** (the vendor seam — no host vocabulary in a
foundation package).
Amends: **`splicewire/splicewire-app` ADR-0122** (guarded guides) — see that repo's ADR-0210 for the
disposition of 0122's four decisions.
Coins: **traverse right**, **access right**, **inert declaration**.

## Context

ADR-0209 specified *where* the public renderer's gates fire — post-resolution, pre-body-read, uniform
404 — and explicitly deferred *what they mean* to this decision. Two gate stacks answered overlapping
questions and could not both survive entries becoming the docs substrate:

- **Entry-side** (`src/Sitemap/`): `EntryPublishGate` / `EntryEntitlementGate`, with
  `WorkflowMarkingPublishGate`, `PublicEntitlementGate`, and `SiloVisibilityEntitlementGate` as
  bindings.
- **Route-side** (app ADR-0122): `AccessGate` over an any-of token list, a gate-filtered
  server-authoritative index, and a uniform-404 delivery route.

The decisive structural fact is in the signatures. `EntryPublishGate::isPublished(BeamUxEntry): bool`
and `EntryEntitlementGate::isPublic(BeamUxEntry): bool` take **no actor**; they answer *"is this row
crawlable by anyone?"* `AccessGate::allows(?Authenticatable, string|array): bool` takes one; it answers
*"may this reader see it?"* These are not two implementations of one question, so neither stack
subsumes the other and no amount of re-binding makes one do the other's job.

Three further findings shaped the decision:

1. **`NavProjector` applies no gates at all.** It filters on realm, `type = page`, and non-null
   `segment` — not even `EntryPublishGate`. Converting docs to entries without fixing this leaks every
   draft and gated title into the sidebar, defeating ADR-0122's server-authoritative index outright.
2. **The particle is unavailable as a home for permissions.** An entry *has-a* `BeamParticle` carrying
   the versioned body; ADR-0209 §5 requires gates to fire **before the body is read**, so a permission
   in the body would have to be read to decide whether the body may be read.
3. **`Mdx::isVisible()` conflates two axes** — `! isGated() && (previewAllowed() || ! isDraft())`.
   Gating and drafting are different concerns wearing one boolean.

## Decision

### 1. Three ports, two consumers

`EntryAccessGate` joins the two existing ports as an **actor-aware** third:

```php
interface EntryAccessGate
{
    public function allows(?Authenticatable $actor, BeamUxEntry $entry, Right $right): bool;

    /** Whether this binding recognises a token; a binding that cannot enumerate returns true. */
    public function knows(string $token): bool;
}
```

The two consumers ask different questions and never share an answer:

- **`EntrySitemapSource`** (no actor — a crawler, a console build) keeps asking `EntryPublishGate` +
  `EntryEntitlementGate`: *anonymous crawlability*.
- **The public renderer and `NavProjector`** ask `EntryPublishGate` + `EntryAccessGate`: *this
  reader's authorization*.

`EntryEntitlementGate` therefore leaves the render path entirely. This is the point of the split: an
`auth`-gated entry is correctly **absent from the sitemap** and correctly **renderable to a logged-in
reader**, which a single caller-independent boolean cannot express. Widening `EntryEntitlementGate`
with a nullable actor was rejected — it forces `EntrySitemapSource` into a null-actor call and merges
crawlability with authorization in one value.

### 2. Two rights, named `traverse` and `access`

Permissions are **two** nullable token-list columns on `beam_ux_entries`, mirroring Unix's `x`/`r`
split rather than collapsing to one right:

- **`access`** — may this reader read this entry's **body**. Retains ADR-0122's meaning exactly, so
  every `access:` key already in authored frontmatter migrates with zero edits.
- **`traverse`** — may this reader reach **through** this entry to its descendants, and see it in a
  projected nav.

Both are any-of lists of opaque strings, evaluated by the bound gate. Two rights rather than one buys
the **unlisted page**: `traverse` denied with `access` open yields an entry reachable by direct link
and absent from nav — a real docs need (hand a customer one guide) that one right cannot express.

**Why `traverse` and not `list`.** Three reasons, recorded because the question recurs:

1. It names the mechanism, not the symptom, and the mechanism is larger. Denying this right on a
   section does not merely hide it from a sidebar — it blocks direct URLs to everything beneath it. An
   author setting `list: false` to tidy the nav, then finding every child 404s, has been mis-sold by
   the name.
2. `list` is grammatically wrong at most positions in the chain. Listing is done to a container's
   children; traversal is done to a node. This right is evaluated at every ancestor position and needs
   a name that reads correctly mid-chain.
3. "Hide from nav" already exists, structurally: `NavProjector` excludes null-`segment` entries as
   *unplaced*. A permission named `list` would be a second, permission-flavoured way to do what
   placement already does, and the two would disagree the first time both were used.

### 3. Rights compose conjunctively up the ancestor chain

To render the entry at `/a/b/c`, the reader must clear **`traverse` on every ancestor** (`a`, `b`) and
**`access` on `c`**. An ancestor's `access` is irrelevant to a descendant; the entry's own `traverse`
governs its children, not itself.

Inheritance is not a separate lookup — it **falls out of conjunction**. An entry with no explicit
tokens contributes no constraint, so it transparently inherits whatever its ancestors impose, which is
the "inherit from `parent_id` unless set" behaviour without a second mechanism to keep in agreement.
The renderer's reverse walk already holds every ancestor, so this costs nothing.

The consequence is that a subtree can only ever **narrow**. A child declaring wider access than its
inherited constraint is accepted and **inert** — precisely a `644` file inside a `700` directory, whose
permissive mode is unreachable rather than honoured. Inert declarations are silently useless, so a
doctor check reports them by name: *"this declaration grants nothing beyond its inherited
constraint."* Accepting-and-reporting was chosen over rejecting, because rejecting would make a
perfectly coherent Unix-shaped declaration an error.

### 4. Nav is read-filtered: `traverse` chain **and** own `access`

A node appears in a projected nav only if the reader clears the `traverse` chain to it **and** its own
`access`. This deliberately departs from a faithful Unix port, where `ls` on a readable directory names
every child regardless of the children's own modes.

The Unix-faithful behaviour — a node visible in the sidebar that 404s on click — would resurrect the
titles leak ADR-0122 named as a hard requirement, and would make ADR-0209's uniform 404 a 404 that only
sometimes means "not here." The upsell tease that behaviour would enable is a product feature ADR-0122
already deferred deliberately (404→403+CTA on the entitlement axis); it should arrive as itself, not as
a side effect of nav semantics.

`NavProjector` accordingly gates, having previously gated nothing:

- `workflow_marking` is pushed **into the query** (it is a column, so the publish filter is free);
- the access pass runs **in memory** after the single adjacency load, so no N+1 is introduced;
- **a denied node prunes its entire subtree** — lifting orphans to the grandparent is incoherent here,
  because a child's URL is composed from its parent's segment chain, so a lifted child has no
  reachable URL.

ADR-0122's caching split is preserved verbatim: the **user-independent** enumerated tree is cached
(keyed by a content fingerprint, shared across readers and tenants), and the **gate pass runs live on
every request**, so a revoked grant bites on the next request with no stale-authorization window.

### 5. Tokens are opaque to beam-ux; validity is the binding's capability

The columns store opaque strings — ADR-0092 keeps host RBAC vocabulary out of the package, exactly as
ADR-0122 kept "gated" opaque in beam-mdx. beam-ux therefore cannot validate a token, so validation is
the bound gate's `knows()` capability:

- **Runtime:** an unknown token already fails closed for free. Under any-of, a token nothing satisfies
  never matches, so a typo denies rather than admits — a lockout, which is loud and self-correcting,
  never a leak.
- **Import:** `RegisterEntriesFromDisk` **hard-errors** on an entry whose frontmatter names a token the
  bound gate does not know, reporting the entry and the token.
- **Standing:** the doctor check reports rows already carrying an unknown token.
- **Opt-out:** a host binding an exotic gate returns `true` from `knows()` and skips validation, so
  this never becomes a requirement that blocks a custom binding.

Both keys **mirror row↔disk like every other field** — particle-primary, import never overwrites, per
ADR-0209. There is no special case and no divergence-resolution rule, because there is no second
authority.

### 6. `AccessGate` descends from `splicewire/tower`; the glob index is deleted

`Splicewire\Tower\Navigation\Gates\AccessGate` becomes the **default `EntryAccessGate` binding** in
beam-ux. It carries no tower vocabulary — Laravel auth contracts, a hardcoded `'Root'` role string, and
the morph-alias `can()` convention (ADR-0118) — and both host-specific values become config on descent.
Leaving it in tower and authoring a second default in beam-ux was rejected as two implementations of
one gate, the exact failure mode that makes the app's refactor onto the packaged surface mandatory.

`DocsManifest`, `GuidesNavInvocable`, `GuideDescriptor`, and `DocsGuides` are the **filesystem-glob**
index. Gated `NavProjector` replaces them wholesale; they are deletions, not moves, retired alongside
the disk path.

### 7. The draft preview allowlist does not survive

`beam.mdx.preview_envs` made draft visibility a function of `APP_ENV`. In the entry world the draft
axis is `workflow_marking` + `EntryPublishGate`, and a strictly better answer is available: an author
who clears the entry's gate sees their own unpublished draft — **actor**-authorized, not
**environment**-authorized. Content visibility that depends on deploy configuration is the setting that
gets it wrong in production and leaks silently.

`previewAllowed()` survives only for its other job — the authoring write guard — until the disk path is
retired.

## Consequences

- **A subtree can only narrow.** You cannot hang a genuinely public page under a gated parent; you must
  re-parent it. This is the deliberate price of URL inheritance and conjunction agreeing by
  construction rather than by two rules kept in sync.
- **`EntryEntitlementGate` narrows to a sitemap-only concern.** `SiloVisibilityEntitlementGate` remains
  a valid binding for *crawlability*, and classification-driven gating stays available — but it no
  longer has any bearing on what a logged-in reader may read.
- **Two columns, two walks.** Marginally more machinery than one right, bought deliberately for the
  unlisted page rather than deferred, on the grounds that retrofitting a second right onto a shipped
  single-right column pair is a migration of live permission data.
- **The nav projection becomes actor-dependent**, so it can no longer be cached whole. The cache
  boundary moves to the enumerated tree, per §4.
- **Fail-closed is the posture throughout** — unknown token, missing binding, and denied ancestor all
  deny, and every denial renders as the same 404.
