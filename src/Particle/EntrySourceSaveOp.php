<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\BeamUxEntrySourceInputData;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\JsonDoc\SourceToBlocks;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * `POST /beam-ux-entries/{id}/op/save-source` — ADR-0228 d10's **Source** write. One input, `{ source }`,
 * for a Source-kind entry (a component, an mdx page). The entry's {@see \Splicewire\Beam\Ux\Codec\BodyCodec}
 * encodes the author's text into the stored body; everything after is {@see EntryPublication}'s, the same
 * baseline → write → publish the blocks write ({@see EntryBodySaveOp}) uses, so the two paths cannot drift.
 *
 * ## Why this is its own operation, not `save-body` with a loose shape
 *
 * ADR-0228 d1 makes the body's KIND the entry's declaration, and d10 gives each kind ONE input: blocks a
 * typed JsonDoc, a theme a schema-checked form, and source this `{ source }` string. The retired
 * controller took any array and guessed; a Source-kind entry now has an input that says it takes text,
 * and a blocks or form entry is refused here with a 422 that names its actual kind rather than silently
 * writing a blank file (the mdx-blanking defect `save-body` already guards the other direction of).
 *
 * For a `tsx` page the stored body is blocks, so a source write LOWERS the source to a JsonDoc through the
 * Node TSX Lens (ADR-0228 d9, EP-06) with ids rematched against the Working Head, then stores it like a
 * blocks write — the EP-05 refusal of a Blocks-kind entry here has become that lowering. A Form-kind
 * entry (a theme) has no source editor and is still refused.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'save-source',
    kind: OperationKind::Write,
    ability: 'ux.author',
    // Subject-free entitlement plane, as the sibling writes carry ({@see EntryBodySaveOp}).
    abilityModel: false,
    // ADR-0228 d10: every new operation declares its affordance. This is the source editor's save — the
    // same implicit save `save-body` is, not a discrete frame ACTION — so it draws no action button.
    affordance: false,
    input: BeamUxEntrySourceInputData::class,
    output: BeamUxEntryBodyData::class,
)]
class EntrySourceSaveOp
{
    /**
     * @return array{key: string, compileError: string|null}
     */
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        $input = BeamUxEntrySourceInputData::validateAndCreate($request->all());

        $publication = app(EntryPublication::class);

        // ADR-0227 d1 / ADR-0233 d6 (EP-06 d8): a tenant-owned entry holds blocks, not code, and a Hosted
        // site has no File Language — so a source (MDX/TSX) write must never reach a tenant-resident entry.
        // Enforce it at the save rather than trust that nothing ever sends one.
        self::assertNotTenantResident($model);

        // ADR-0228 d10/d9: build the stored body from the source by the entry's KIND. A Source-kind entry
        // (mdx/css) encodes its text through the codec; a Blocks-kind (tsx) entry is lowered to a JsonDoc
        // through the Node TSX Lens with ids rematched; a Form-kind entry has no source editor.
        $body = self::bodyFromSource($model, $input->source);

        // ADR-0225 d2: a save is its own publish, and a publish needs the version store — refuse before
        // the write so a host missing the store keeps its working copy.
        $publication->requireVersionStore();

        // Baseline the currently-live body before the write destroys it (no-op once there is history).
        $publication->baseline($model);

        $written = $publication->write($model, $body);

        return [
            'key' => $written['key'],
            'compileError' => $publication->publishWritten($model->refresh()),
        ];
    }

    /**
     * Re-read the durable state through the resolved driver and project the envelope — the same round-trip
     * proof {@see EntryBodySaveOp::respond()} gives; echoing the submitted source back would prove nothing.
     */
    public static function respond(mixed $payload, Model $model): mixed
    {
        /** @var BeamUxEntry $model */
        /** @var array{key: string, compileError: string|null} $payload */
        $reloaded = app(StorageDriverResolver::class)->resolve($model)->read($payload['key']);

        return app(EntryBodyEnvelope::class)->of(
            $model,
            $reloaded?->body ?? [],
            $payload['compileError'],
        );
    }

    private static function assertNotTenantResident(BeamUxEntry $entry): void
    {
        if (app(EntryResidency::class)->isCentralResident($entry)) {
            return;
        }

        throw ValidationException::withMessages([
            'source' => 'A hosted-site (tenant-owned) entry holds blocks, not source: a source body is code '
                .'and is refused here (ADR-0227 decision 1, ADR-0233 decision 6).',
        ]);
    }

    private static function bodyFromSource(BeamUxEntry $entry, string $source): mixed
    {
        return match ($entry->bodyKind()) {
            // mdx: `{frontmatter,content}`; css: the theme token object (a Source css entry round-trips text).
            BodyKind::Source => $entry->codec()->encode($source),
            // tsx: lowered to a JsonDoc through the Node TSX Lens (d9).
            BodyKind::Blocks => self::lowerToBlocks($entry, $source),
            // a theme (Form) has no source editor.
            default => throw ValidationException::withMessages([
                'source' => sprintf(
                    'This entry declares a %s body, which has no source editor. Edit it through its own input.',
                    $entry->bodyKind()->value,
                ),
            ]),
        };
    }

    /**
     * ADR-0228 d9 — lower a Blocks-kind (tsx) entry's source to a JsonDoc through the Node TSX Lens, ids
     * rematched against the Working Head, then hold it to the realm's Manifest Profile exactly as
     * save-body does (via the shared {@see CanvasBodyGuard}).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lowerToBlocks(BeamUxEntry $entry, string $source): array
    {
        // The lowered body is held to the realm's profile at the publication choke (EntryPublication::write);
        // here we only lower + reattach ids against the Working Head.
        $guard = app(CanvasBodyGuard::class);
        $body = app(SourceToBlocks::class)->convert($source, $guard->workingHead($entry), app(ManifestProfileResolver::class)->resolve($entry));

        return $body;
    }
}
