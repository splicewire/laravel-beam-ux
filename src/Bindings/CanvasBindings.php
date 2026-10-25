<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Ux\Data\CanvasRecordChoiceData;
use Splicewire\Beam\Ux\Data\EntryCanvasBindingsOutputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** The ordinary guarded reader as the author, never a public URL query override or a write. */
final class CanvasBindings
{
    public const CHOICE_LIMIT = 25;

    /** Bounded, read-only guest access walk; malformed containment never implies public access. */
    private function pagePublic(BeamUxEntry $entry): ?bool
    {
        if ($entry->type !== \Splicewire\Beam\Ux\Type\UxType::Page) {
            return null;
        }
        $chain = [];
        $seen = [];
        $current = $entry;
        for ($depth = 0; $depth < 64; $depth++) {
            $id = (string) $current->getKey();
            if ($id === '' || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $chain[] = $current;
            if ($current->parent_id === null) {
                return app(\Splicewire\Beam\Ux\Access\EntryAccessResolver::class)->canRender(null, array_reverse($chain));
            }
            $parent = $current->parent()->first();
            if (! $parent instanceof BeamUxEntry) {
                return null;
            }
            $current = $parent;
        }

        return null;
    }

    public function authorize(BeamUxEntry $entry, mixed $actor): void
    {
        abort_unless($actor instanceof Authenticatable
            && app(AbilityResolver::class)->allows($actor, 'ux.author')
            && Gate::forUser($actor)->allows('update', $entry), 403);
    }

    public function resolve(BeamUxEntry $entry, Request $request, array $body, ?string $recordValue): EntryCanvasBindingsOutputData
    {
        $this->authorize($entry, $request->user());
        \Splicewire\Beam\Ux\Data\EntryCanvasBindingsInputData::assertBounds(['body' => $body]);
        \Splicewire\Beam\Ux\Data\CanvasBodyInputData::validateAndCreate(['body' => $body]);
        $page = app(RecordPages::class)->validationEntry($entry);
        $profile = app(\Splicewire\Beam\Ux\Manifest\ManifestProfileResolver::class)->resolve($page);
        app(BindingBodyGuard::class)->assert($page, $body, $profile);
        $resolver = app(BindingResolver::class);
        $bindings = $resolver->resolveDocument($page, $body, $request, (string) $page->realm);
        $declaration = $page->getAttribute('record_page');
        if (! is_array($declaration)) {
            if ($recordValue !== null) {
                throw ValidationException::withMessages(['recordValue' => 'Save a Record Page declaration before choosing a Record.']);
            }

            return new EntryCanvasBindingsOutputData($bindings, null, [], false, false, null, $this->pagePublic($entry));
        }
        $records = app(RecordPages::class);
        $rows = $records->choiceWindow($page, $request, (string) $page->realm, self::CHOICE_LIMIT + 1);
        $hasMore = count($rows) > self::CHOICE_LIMIT;
        $choices = [];
        foreach (array_slice($rows, 0, self::CHOICE_LIMIT) as $row) {
            $value = RecordPages::slug(Arr::get($row, 'fields.'.$declaration['field']));
            if ($value === null) {
                continue;
            }
            $title = isset($declaration['titleField']) ? Arr::get($row, 'fields.'.$declaration['titleField']) : null;
            $choices[] = new CanvasRecordChoiceData($value, is_scalar($title) ? (string) $title : $value);
        }
        $selected = $recordValue ?? ($choices[0]->value ?? null);
        if ($selected !== null && RecordPages::slug($selected) === null) {
            throw ValidationException::withMessages(['recordValue' => 'Choose a valid Record value.']);
        }
        $row = $selected === null ? null : $records->resolve($page, $selected, $request, (string) $page->realm);
        $extra = [];
        foreach (['field', 'titleField', 'descriptionField', 'socialImageField'] as $key) {
            if (is_string($declaration[$key] ?? null)) {
                $extra[] = 'fields.'.$declaration[$key];
            }
        }
        $record = $row === null ? null : $resolver->projectRoot($body, $row, $extra);

        return new EntryCanvasBindingsOutputData($bindings, $record, $choices, $hasMore, true, $record === null ? null : $selected, $this->pagePublic($entry));
    }
}
