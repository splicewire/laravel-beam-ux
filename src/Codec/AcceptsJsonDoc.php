<?php

namespace Splicewire\Beam\Ux\Codec;

/**
 * Capability marker on a {@see BodyCodec}: **this format's body may be a JsonDoc** — the canvas
 * editor's `JsonNode[]` list (`[{kind:'block',…}, …]`, `@splicewire/beam-ux/blockdoc`), not only the
 * keyed source payload the codec's own `encode()` produces.
 *
 * A capability sub-interface rather than a method on the port, for the reason the particle doctrine
 * gives for `BacksModel`: a codec that cannot carry a JsonDoc has **no legal answer** to "print this
 * tree back to your source language" and must not invent one. {@see MdxBodyCodec} inventing one is
 * exactly the measured incident this marker exists to prevent — `decode()` looked for `content` and
 * `frontmatter` keys a JsonDoc list does not have, returned `''`, and the public page went blank
 * (G2-BEAM-AUTHOR-ENTRY, 2026-09-11).
 *
 * Today {@see TsxBodyCodec} is the only implementer, because it is the only codec with a
 * {@see JsonDocPrinter} — the server-side port of the canvas's own "Source" printer. A host's bespoke
 * codec earns the canvas by implementing this, not by being named in a list here: beam-ux never learns
 * a consumer's format name.
 *
 * Two readers, and they must agree:
 *  - {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} REFUSES a JsonDoc write to an entry whose
 *    codec declines this capability (422 on `body`, through the declared input's own envelope);
 *  - {@see \Splicewire\Beam\Ux\Particle\EntryBodyEnvelope} projects `format` + the decoded `source` so
 *    a client can tell, without guessing, whether the canvas is the right editor for this entry.
 */
interface AcceptsJsonDoc {}
