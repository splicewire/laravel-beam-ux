# EP-06 d9 — real Node TSX Lens run on tots

A REAL Node run (not the fake lens): `resources/lens/tsx-to-blocks.mjs` invoked on tots (node v22.23.3)
against a **built** `@splicewire/beam-ux/blockdoc`, then `NodeTsxLens` → `SourceToBlocks` →
`JsonDocIdRematcher`, proving the three id behaviours ADR-0228 d3/d9 require.

**Built blockdoc used (commit-bound).** A fresh SCRATCH clone of `splicewire/beam` at **origin/main
`ea4abe997f1d6d3a069be9d177d734f89332beda`**, built there — not the owner's live tree. I used beam main
rather than the EP-05/06 candidate because the candidate's diff is json.ts **type** imports only (not the
`parse`/`toJson` logic), main builds without the unlanded generated-types dependency, and the resulting
`dist/blockdoc.js` is **byte-identical** to the live build (7993 bytes). Built artifact:
`beam-ux/dist/blockdoc.js` **sha256 `5dd78f478627bee4adf13f577085fea6cd6122cee8179e630188842b95e1df1a`**.

Reproducible build on tots (node v22.23.3, npm): `git clone splicewire/beam` at the SHA above; then the
monorepo install needs one workaround — a fresh `npm ci` aborts because `@splicewire/beam-ux-prototype`'s
`prepare` runs `tsup` before devDeps are linked and `--ignore-scripts` is NOT honored for workspace-local
prepares, so strip the `prepare` scripts from the workspace `package.json`s, `npm ci --ignore-scripts`,
then `tsup` in `beam-ux`. The ESM build (`dist/*.js`, incl. `blockdoc.js`) succeeds; the DTS step fails on
unbuilt sibling types (`@schemastud/frame`), which the runtime lens does not need.

(Resolution note: `@splicewire/beam-ux` is an import-only ESM package — CJS `require.resolve` cannot
resolve it and node v22's `import.meta.resolve` ignores a parent-URL arg unflagged, so the lens resolves
it from the host's `node_modules` via the package's own `exports` map.)

**Lens output** for `export default () => <section id="hero"><h2>Welcome</h2><p>Hello world</p></section>`:
```json
{"doc":[{"kind":"block","name":"section","isComponent":false,"props":[{"name":"id","kind":"string","value":"hero"}],"children":[{"kind":"block","name":"h2",...,"children":[{"kind":"text","value":"Welcome"}]},{"kind":"block","name":"p",...,"children":[{"kind":"text","value":"Hello world"}]}]}]}
```

**End-to-end result** (`scratchpad/d9-real-run.php`, all PASS):
```
doc1 (first lower) block ids: 01a14746-6e37-7209-..., ...-74a92b5, ...-777181c
  [PASS] every first-lower block got a UUIDv7 id
  [PASS] UNCHANGED save keeps the h2 id
  [PASS] UNCHANGED save keeps the p id
  [PASS] EDITED h2 text keeps its id (similarity fallback)
  [PASS] the untouched p keeps its id across the edit
  [PASS] a NEW paragraph gets a fresh id not in the Working Head
```

Scenarios: (1) first lower of the source with an empty Working Head → three fresh UUIDv7 ids; (2) the
same source lowered again against doc1 → every id unchanged; (3) the `<h2>` text edited
("Welcome" → "Welcome back") plus a new `<p>` added, lowered against doc1 → the edited `<h2>` keeps its
id through the similarity fallback, the untouched `<p>` keeps its id, and the new `<p>` is minted a fresh
UUIDv7. The ids print identically across doc1/doc2 and the edited `<h2>`/untouched `<p>` carry doc1's ids
in doc3.
