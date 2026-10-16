<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Ux\Data\EntryMakeEditableInputData;
use Splicewire\Beam\Ux\Data\EntryMakeEditableOutputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Support\PageEntryRef;
use Splicewire\Beam\Ux\Type\UxType;

/** Generated with make:particle-op; creates only, never replaces an authored row. */
#[ParticleOp(resource: 'beam-ux-entry', name: 'make-editable', kind: OperationKind::Write,
    subject: NoSubject::class, ability: 'ux.author', abilityModel: false, affordance: false,
    input: EntryMakeEditableInputData::class, output: EntryMakeEditableOutputData::class)]
final class EntryMakeEditableOp
{
    public static function handle(mixed $subject, Request $request, mixed $actor): EntryMakeEditableOutputData
    {
        $input = EntryMakeEditableInputData::validateAndCreate($request->all());
        $publication = app(EntryPublication::class);
        $publication->requireVersionStore();
        $compileError = DB::transaction(function () use ($input, $publication) {
            $namespace = config('beam.ux.namespace', 'starter');
            abort_if(BeamUxEntry::withTrashed()->where('namespace', $namespace)->where('slug', $input->slug)->exists(), 409, 'This page already has an entry.');
            // The unique namespace/slug key also refuses concurrent creation. No existing row is written.
            $entry = BeamUxEntry::create(['namespace' => $namespace, 'slug' => $input->slug,
                'type' => UxType::Page, 'format' => 'tsx', 'title' => $input->slug]);
            $publication->assertStorable($entry, $input->body);
            $publication->write($entry, $input->body);

            return $publication->publishWritten($entry->refresh());
        });

        return EntryMakeEditableOutputData::from(['id' => PageEntryRef::for($input->slug)['id'], 'compileError' => $compileError]);
    }
}
