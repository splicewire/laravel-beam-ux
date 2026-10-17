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
use Splicewire\Beam\Ux\Manifest\EntryResidency;
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
 * For a `tsx` page the stored body is blocks, so the server lowers source to a JsonDoc through the Node
 * TSX Lens (ADR-0228 d9) — a later slice; a `tsx` page is Blocks-kind and is NOT served here.
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

        // ADR-0228 d1/d10: this input serves a Source-kind entry. A Blocks entry takes save-body's typed
        // JsonDoc; a Form entry takes the theme form. Refuse anything else as a clean 422 on `source`,
        // before the write, naming the entry's actual declared kind.
        self::assertSourceKind($model);

        // ADR-0227 d1 / ADR-0233 d6 (EP-06 d8): a tenant-owned entry holds blocks, not code, and a Hosted
        // site has no File Language — so a source (MDX/TSX) write must never reach a tenant-resident entry.
        // Enforce it at the save rather than trust that nothing ever sends one.
        self::assertNotTenantResident($model);

        // The codec turns the author's text into the stored body (mdx: `{frontmatter,content}`; css: the
        // theme token object a theme would use a form for — but a Source css entry still round-trips text).
        $body = $model->codec()->encode($input->source);

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

    private static function assertSourceKind(BeamUxEntry $entry): void
    {
        if ($entry->bodyKind() === BodyKind::Source) {
            return;
        }

        throw ValidationException::withMessages([
            'source' => sprintf(
                'This entry declares a %s body, not source. Edit it through the %s input, or change the '.
                "entry's kind first.",
                $entry->bodyKind()->value,
                $entry->bodyKind()->value,
            ),
        ]);
    }
}
