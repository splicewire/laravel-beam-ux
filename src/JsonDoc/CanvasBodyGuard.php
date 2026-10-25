<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\Manifest\BodyNodeDiff;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Manifest\ProfileLegality;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * ADR-0228 d8 / ux-0227 d1 — the shared save-time guard for a canvas (JsonDoc) body, used by every path
 * that stores one: {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} (the typed JsonDoc write) and
 * {@see \Splicewire\Beam\Ux\Particle\EntrySourceSaveOp} (a tsx source write lowered through the d9 Lens),
 * so a lowered body is held to exactly the same profile as a directly-authored one.
 *
 * It resolves the realm's Manifest Profile, narrows the LEGALITY checks (unknown-name, containment,
 * attrsSchema) to only the nodes this save adds or changes versus the Working Head ({@see BodyNodeDiff}),
 * and leaves the ux-0227 d1 code refusal (opaque node + expression prop) whole-body.
 */
final class CanvasBodyGuard
{
    public function __construct(
        private ManifestProfileResolver $resolver,
        private ProfileLegality $legality,
        private StorageDriverResolver $drivers,
        private BodyNodeDiff $diff = new BodyNodeDiff,
    ) {}

    /**
     * The entry's current stored body (the Working Head), read before the write. Empty for a first save
     * or a non-JsonDoc body.
     *
     * @return array<int|string, mixed>
     */
    public function workingHead(BeamUxEntry $entry): array
    {
        $key = $entry->particle_id;
        if (! is_string($key) || $key === '') {
            return [];
        }

        // On the entry's OWN connection: an entry on a non-default connection (a tenant's, central under a
        // tenant default) read through the unbound driver showed the guard an empty or foreign body.
        $body = $this->drivers->resolveOnEntryConnection($entry)->read($key)?->body ?? [];

        return is_array($body) ? $body : [];
    }

    /**
     * Refuse a canvas body the realm's resolved Manifest Profile does not admit, as a 422 on `body`
     * naming each offending node by id + path. A non-JsonDoc body is source, so where the profile refuses
     * code it is refused whole ({@see assertNotSourceWhereCodeIsRefused()}); elsewhere it passes untouched.
     *
     * @param  array<int|string, mixed>  $body
     */
    public function assertAdmitted(BeamUxEntry $entry, array $body): void
    {
        if (! JsonDocShape::is($body)) {
            $this->assertNotSourceWhereCodeIsRefused($entry, $body);

            return;
        }

        $this->assertWellFormed($body);

        $profile = $this->resolver->resolve($entry);
        app(\Splicewire\Beam\Ux\Bindings\BindingBodyGuard::class)->assert($entry, $body, $profile);
        $checkIds = $this->diff->changedIds($this->workingHead($entry), $body);

        $this->legality->assert($body, $profile, $checkIds);
        // EP-07d: a changed form posts only to a public intake schema this host's door accepts.
        app(\Splicewire\Beam\Ux\Intake\IntakeForms::class)->assertBody($body, $checkIds);
    }

    /**
     * The restore half (ADR-0228 d9 as amended by ADR-0227 d1): a restore never refuses ILLEGALITY, an
     * unknown block, illegal containment or a schema fail, because it is not authoring; those come back as
     * diagnostics and the version is restored. It still refuses CODE where the profile refuses it (a
     * non-canvas body, an opaque node, an expression prop) and a node the canvas cannot read, leaving the
     * entry as it was, as d9's own "a file with code outside its JSX isn't a page" leaves it.
     *
     * @param  array<int|string, mixed>  $body
     * @return list<string> the legality diagnostics, by node id + path
     */
    public function admitForRestore(BeamUxEntry $entry, array $body): array
    {
        if (! JsonDocShape::is($body)) {
            $this->assertNotSourceWhereCodeIsRefused($entry, $body);

            return [];
        }

        $this->assertWellFormed($body);

        ['code' => $code, 'legality' => $legality] = $this->legality->violations($body, $this->resolver->resolve($entry));

        if ($code !== []) {
            throw ValidationException::withMessages(['body' => $code]);
        }

        return $legality;
    }

    /**
     * The publish half: publishing serves the body, so it refuses both bands. Code is refused whole, and
     * legality in every node that differs from the version a guest is served now (`$published`), so a
     * restored illegal draft cannot go live while a legacy page that is already live still republishes.
     * An unknown block is not cosmetic on a tenant page: `script` and `iframe` are unknown to the HTML profile.
     *
     * @param  array<int|string, mixed>  $working  the working copy about to be pinned
     * @param  array<int|string, mixed>  $published  the body of the currently published version ([] if none)
     */
    public function assertPublishable(BeamUxEntry $entry, array $working, array $published): void
    {
        if (! JsonDocShape::is($working)) {
            $this->assertNotSourceWhereCodeIsRefused($entry, $working);

            return;
        }

        $this->assertWellFormed($working);

        $profile = $this->resolver->resolve($entry);
        app(\Splicewire\Beam\Ux\Bindings\BindingBodyGuard::class)->assert($entry, $working, $profile);
        $this->legality->assert($working, $profile, $this->diff->changedIds($published, $working));
    }

    /**
     * ADR-0228 d2 — well-formedness first: every node must hydrate through the JsonNode union, or the
     * document is one the canvas editor cannot read. A bad node (unknown kind, malformed field, a literal
     * prop carrying code) becomes the same clean 422 as any other bad input, never a 500 from the hydrator.
     *
     * @param  list<array<string, mixed>>  $body
     */
    private function assertWellFormed(array $body): void
    {
        try {
            foreach ($body as $node) {
                JsonNode::from($node);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'body' => 'This document has a node the canvas editor cannot read ('.$e->getMessage().').',
            ]);
        }
    }

    /**
     * ADR-0227 d1 — a body that is not a canvas document is source: `{source}` (a disk-registered tsx, which
     * `TsxBodyCodec::decode()` compiles verbatim), an mdx `{frontmatter,content}`, a component's TSX. Where
     * the profile refuses code, only an empty body (a clear) and a Form-kind entry's token object (a theme,
     * ADR-0228 d1: data, not source) remain. The guard used to return early here, so `{source: '<tsx>'}`
     * reached a tenant page through save-body and restore (EP-07a probe P2, launch 2026-10-17).
     *
     * @param  array<int|string, mixed>  $body
     */
    private function assertNotSourceWhereCodeIsRefused(BeamUxEntry $entry, array $body): void
    {
        if ($body === [] || $entry->bodyKind() === BodyKind::Form) {
            return;
        }

        if (($this->resolver->resolve($entry)['x-admits-code'] ?? false) === true) {
            return;
        }

        throw ValidationException::withMessages([
            'body' => 'This entry holds blocks, not code (ADR-0227 decision 1): a body that is not a canvas document is source, and source is refused here.',
        ]);
    }
}
