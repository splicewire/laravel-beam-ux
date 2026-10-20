<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\BeamUxEntryFormInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * `POST /beam-ux-entries/{id}/op/save-form` — ADR-0228 d10's **Form** write. One input, `{ form }`, for a
 * Form-kind entry (a theme). The fields are checked against the entry's OWN schema before anything lands
 * ({@see EntryBodyEnvelope::schemaFor()} — the same JSON Schema the inspector's SchemaForm rendered), then
 * stored and published the same baseline → write → publish the other writes use, so the paths can't drift.
 *
 * ## Why a form has its own operation
 *
 * ADR-0228 d1 makes the body's KIND the entry's declaration, and d10 gives each kind one input: blocks a
 * typed JsonDoc, source a `{ source }` string, a form THIS schema-checked fields object. A theme's body is
 * a `{ canvas, site }` token tree, not a JsonNode list and not free text; it is written through the theme
 * codec (`CssBodyCodec` decodes it to custom properties on the disk mirror). Validating against the entry's
 * schema here is what keeps `theme.site.darkAccent` a real token and refuses a field the schema never
 * declared, before a blank or malformed theme reaches a reader.
 */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'save-form',
    kind: OperationKind::Write,
    ability: 'ux.author',
    // Subject-free entitlement plane, as the sibling writes carry.
    abilityModel: false,
    // ADR-0228 d10: every new operation declares its affordance. The theme form's save is the inspector's
    // implicit save, not a discrete frame ACTION.
    affordance: false,
    input: BeamUxEntryFormInputData::class,
    output: BeamUxEntryBodyData::class,
)]
class EntryFormSaveOp
{
    /**
     * @return array{key: string, compileError: string|null}
     */
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        $input = BeamUxEntryFormInputData::validateAndCreate($request->all());

        $publication = app(EntryPublication::class);

        // ADR-0228 d1/d10: a form write serves a Form-kind entry. Blocks takes save-body, source takes
        // save-source. Refuse anything else as a clean 422 naming the entry's declared kind.
        self::assertFormKind($model);

        // d10: the form is checked against the entry's OWN schema before it is stored.
        self::assertMatchesSchema($model, $input->form);

        // ADR-0225 d2: a save is its own publish; the publish needs the version store. Refuse first.
        $publication->requireVersionStore();
        $publication->baseline($model, mirror: true);

        $written = $publication->write($model, $input->form);

        return [
            'key' => $written['key'],
            'compileError' => $publication->publishWritten($model->refresh()),
        ];
    }

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

    private static function assertFormKind(BeamUxEntry $entry): void
    {
        if ($entry->bodyKind() === BodyKind::Form) {
            return;
        }

        throw ValidationException::withMessages([
            'form' => sprintf(
                'This entry declares a %s body, not a form. Edit it through the %s input, or change the '.
                "entry's kind first.",
                $entry->bodyKind()->value,
                $entry->bodyKind()->value,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private static function assertMatchesSchema(BeamUxEntry $entry, array $form): void
    {
        $schema = app(EntryBodyEnvelope::class)->schemaFor($entry);

        // No declared schema is nothing to check against (the form is stored as-is, as before).
        if ($schema === null) {
            return;
        }

        // A form is a token TREE of objects + scalars (a theme's `{ canvas: {…}, site: {…} }`); it carries
        // no JSON arrays. PHP cannot tell an empty array from an empty object, so a cleared field `{}`
        // decodes to `[]` and would fail the schema's `type: object`. JSON_FORCE_OBJECT keeps every branch
        // an object for the check (scalars are untouched); the STORED body stays the original `$form`.
        $result = (new Validator)->validate(
            json_decode(json_encode($form, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR)),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR)),
        );

        if ($result->isValid()) {
            return;
        }

        $errors = (new ErrorFormatter)->formatKeyed($result->error());

        throw ValidationException::withMessages([
            'form' => 'This form does not match the entry\'s schema: '.json_encode($errors, JSON_THROW_ON_ERROR),
        ]);
    }
}
