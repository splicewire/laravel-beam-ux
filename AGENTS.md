> You are in **splicewire/laravel-beam-ux** — the beam-tier authoring/orchestration UX layer over beam-core.

A Laravel package (`Splicewire\Beam\Ux\*`) providing the beam-ux authoring layer: the raw-MDX
reader for disk-authored content, classification facets (silo/tag) over `BeamUxEntry`, and
sitemap/workflow wiring for beam hosts. It composes several sibling beam-family packages —
taxonomy, sitemap, workflows, MDX — while owning the orchestration seam itself. Beam tier, MIT,
like every other `laravel-beam-*` package (ADR-0092 / ADR-0159).

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
