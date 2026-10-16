# EP-07a — `beam-ux → schemastud/laravel-blockdoc` repositories census

**Why this matters.** EP-07a adds a direct `schemastud/laravel-blockdoc` requirement to
`laravel-beam-ux` (the reuse path ADR-0228 names: `ManifestBuilder` / `#[NodeType]` / the v1 manifest
grammar). `laravel-blockdoc` is **not on Packagist** (verified: `repo.packagist.org/p2/schemastud/laravel-blockdoc.json`
→ HTTP 404, same as every other `schemastud/*` package). Composer **ignores a non-root package's own
`repositories` block**, so adding the git `repositories` entry to `beam-ux`'s own `composer.json` (the
11th such entry) only helps `beam-ux`'s own install — **every consumer's ROOT `composer.json` must carry
its own git `repositories` entry** for `laravel-blockdoc`, exactly as EP-04's analytics dep required.

Verified on tots 2026-10-16 (pruned of `.wt-*` worktrees; each row is the canonical checkout).

## Resolve already (carry a `laravel-blockdoc` git `repositories` entry — Tower-family)

| Consumer | requires `laravel-beam-ux` | `laravel-blockdoc` entry | action |
| --- | --- | --- | --- |
| `splicewire-app` (flagship, `~/Herd/splicewire-app`) | yes | HAS | none |
| `splicewire/tower` | yes | HAS | none |
| `splicewire/laravel-tower-market` | yes | HAS | none |
| `laravel-tower-starter` | yes | HAS | none |
| `laravel-satellite-starter` | yes | HAS | none |

## Need the entry added on their next `beam-ux` bump (no `laravel-blockdoc` today — beam-family)

| Consumer | requires `laravel-beam-ux` | `laravel-blockdoc` entry | action |
| --- | --- | --- | --- |
| `splicewire/laravel-beam-docs` | yes | NEEDS | add git `repositories` entry |
| `splicewire/laravel-beam-ux-prototype` | yes | NEEDS | add git `repositories` entry |
| `splicewire/laravel-beam-market-packages` | yes | NEEDS | add git `repositories` entry |
| `splicewire/laravel-beam-market` | yes | NEEDS | add git `repositories` entry |
| `splicewire/laravel-beam-sitemap` | yes | NEEDS | add git `repositories` entry |
| `laravel-beam-starter` | yes | NEEDS | add git `repositories` entry |
| `laravel-beam-market-starter` | yes | NEEDS | add git `repositories` entry |
| `laravel-beam-commerce-starter` | yes | NEEDS | add git `repositories` entry |

## EP-04 12-consumer roster reconciliation — the apps

| Consumer | requires `laravel-beam-ux` | `laravel-blockdoc` entry | action |
| --- | --- | --- | --- |
| `splicewire/www` (`~/Herd/splicewire`) | yes | NEEDS | its refreshed graph carries the `laravel-beam-ux` git repo but **not** `laravel-blockdoc`; add the entry |
| `numero` (`~/Herd/numero`, `laravel/react-starter-kit`) | yes | NEEDS | add git `repositories` entry |
| `audiostud` (`~/Herd/audiostud`) | yes | NEEDS | **not present on tots** (empty dir); checked on primary (orch-lead, 2026-10-16 23:09Z): no `laravel-blockdoc` entry, not required, not locked — add the git `repositories` entry |

**Tally: 5 resolve already; 11 need the entry** (the 8 beam-family packages/starters + `splicewire/www` + `numero` + `audiostud`).

**Note.** `~/Herd/schemastud` (name `laravel/laravel`) does **not** require `laravel-beam-ux` and is not a
consumer. The git `repositories` entry to add, in each consumer's root `composer.json`:

```json
{ "type": "git", "url": "https://github.com/schemastud/laravel-blockdoc.git" }
```
