<?php

namespace Splicewire\Beam\Ux\Components;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Data\EntryComponentCapabilitiesOutputData;
use Splicewire\Beam\Ux\Data\EntryComponentCreateInputData;
use Splicewire\Beam\Ux\Lifecycle\EntryDuplicator;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/** Canvas adapters delegate native entry creation, duplication and publication; no new permission plane. */
final class ComponentAuthoring
{
    public const REACH_LIMIT = 500;

    private function author(mixed $actor): Authenticatable
    {
        abort_unless($actor instanceof Authenticatable && app(AbilityResolver::class)->allows($actor, 'ux.author'), 403);

        return $actor;
    }

    public function capabilities(BeamUxEntry $entry, mixed $actor): EntryComponentCapabilitiesOutputData
    {
        $actor = $this->author($actor);
        $components = app(AuthoredComponents::class);
        abort_unless($components->authored($entry) && $components->canRead($entry, $actor), 403);
        $canEdit = Gate::forUser($actor)->allows('update', $entry);
        if (! $canEdit) {
            return new EntryComponentCapabilitiesOutputData((string) $entry->getKey(), false, null, self::REACH_LIMIT, false);
        }
        // usedBy walks retained Working Heads and Publication Pins. Bound its candidate set first.
        $overLimit = $entry->newQuery()->where('realm', $entry->realm)->limit(self::REACH_LIMIT + 1)->pluck('id')->count() > self::REACH_LIMIT;
        $count = $overLimit ? null : count($components->usedBy($entry));

        return new EntryComponentCapabilitiesOutputData((string) $entry->getKey(), true, $count, self::REACH_LIMIT, $overLimit);
    }

    public function authorizeCreate(BeamUxEntry $context, mixed $actor): Authenticatable
    {
        $actor = $this->author($actor);
        Gate::forUser($actor)->authorize('update', $context);
        Gate::forUser($actor)->authorize('create', BeamUxEntry::class);

        return $actor;
    }

    public function create(BeamUxEntry $context, Request $request, mixed $actor, EntryComponentCreateInputData $input): BeamUxEntry
    {
        $actor = $this->authorizeCreate($context, $actor);

        return $context->getConnection()->transaction(function () use ($context, $request, $actor, $input) {
            $root = BeamUxEntry::rootFor((string) $context->realm);
            $resource = app(ParticleResourceRegistry::class)->get('beam-ux-entry');
            $created = app(ParticleFrameResourceHandler::class)->forRequest($request, (string) $context->realm)->store(
                $resource->toResourceDefinition((string) $context->realm),
                ['type' => 'component', 'componentKind' => 'authored', 'title' => $input->title,
                    'slug' => \Illuminate\Support\Str::kebab($input->title), 'realm' => (string) $context->realm, 'parentId' => (string) $root->getKey()],
            );
            $entry = $context->newQuery()->findOrFail($created['id']);
            $publication = app(EntryPublication::class);
            $state = $publication->state($entry);
            $publication->draftFromBase($entry, $input->toArray()['body'], $state->headVersion, 'Made from canvas', $actor instanceof \Illuminate\Database\Eloquent\Model ? $actor : null);

            return $entry->refresh();
        });
    }

    public function duplicate(BeamUxEntry $entry, mixed $actor): BeamUxEntry
    {
        $actor = $this->author($actor);
        Gate::forUser($actor)->authorize('update', $entry);
        Gate::forUser($actor)->authorize('create', BeamUxEntry::class);
        abort_unless(app(AuthoredComponents::class)->authored($entry) && app(AuthoredComponents::class)->canRead($entry, $actor), 403);

        return app(EntryDuplicator::class)->duplicate($entry);
    }
}
