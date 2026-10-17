# EP-06 d9 — real Node TSX Lens run on tots

A REAL Node run (not the fake lens): `resources/lens/tsx-to-blocks.mjs` invoked on tots (node v22.23.3)
against a **built** `@splicewire/beam-ux/blockdoc`, then `NodeTsxLens` → `SourceToBlocks` →
`JsonDocIdRematcher`, proving the three id behaviours ADR-0228 d3/d9 require.

**Built blockdoc used.** `@splicewire/beam-ux` at `~/Workspaces/js/packages/beam/beam-ux` is already
built (`dist/blockdoc.js`) and its `package.json` `exports` defines `./blockdoc` → `dist/blockdoc.js`.
The lens's `parse`/`toJson` are ref-stable (the EP-05/06 candidate differs only in json.ts **type**
imports, not the parse/toJson logic), so this is an equivalent real run without a fresh candidate npm
build. (Resolution note: `@splicewire/beam-ux` is an import-only ESM package — CJS `require.resolve`
cannot resolve it and node v22's `import.meta.resolve` ignores a parent-URL arg unflagged, so the lens
resolves it from the host's `node_modules` via the package's own `exports` map.)

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
