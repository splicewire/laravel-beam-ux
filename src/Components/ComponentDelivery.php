<?php

namespace Splicewire\Beam\Ux\Components;

use Illuminate\Http\Request;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Bindings\BindingResolver;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Data\ComponentArtifactData;
use Splicewire\Beam\Ux\Data\ComponentCanvasData;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;

/** Load only referenced names. Memoized raw reads live in the request scope, never across actors/requests. */
class ComponentDelivery
{
    public function __construct(private AuthoredComponents $components, private EntryArtifactStore $artifacts, private ComponentScope $scope) {}

    private function candidates(BeamUxEntry $context, array $names, string $column): array
    {
        if ($names === []) {
            return [];
        }
        $connection = $context->getConnectionName();
        if ($connection !== null && ! array_key_exists($connection, config('database.connections', []))) {
            return [];
        }
        $key = ($connection ?? 'default').'|'.$context->getConnection()->getDatabaseName().'|'.$context->realm.'|'.$column;
        $this->scope->columns[$key] ??= $context->getConnection()->getSchemaBuilder()->hasColumn($context->getTable(), $column);
        if (! $this->scope->columns[$key]) {
            return []; // Pre-migration hosts have no Authored components, and require no legacy-table rewrite.
        }
        $missing = array_values(array_filter(array_unique($names), fn ($name) => ! array_key_exists($name, $this->scope->lookups[$key] ?? [])));
        if ($missing !== []) {
            $groups = $context->newQuery()->where('realm', $context->realm)->where('component_kind', ComponentKind::Authored->value)
                ->whereIn($column, $missing)->get()->groupBy($column);
            foreach ($missing as $name) {
                $matches = $groups->get($name);
                $this->scope->lookups[$key][$name] = $matches?->count() === 1 ? $matches->first() : null;
            }
        }

        return array_filter(array_intersect_key($this->scope->lookups[$key] ?? [], array_fill_keys($names, true)));
    }

    private function document(BeamUxEntry $entry, ?string $ref): array
    {
        $key = ($entry->getConnectionName() ?? 'default').'|'.$entry->getConnection()->getDatabaseName().'|'.$entry->getKey().'|'.($ref ?? 'working');

        return $this->scope->documents[$key] ??= $ref === null ? $this->components->working($entry) : $this->components->version($entry, $ref);
    }

    /** @return list<ComponentArtifactData> */
    public function published(BeamUxEntry $entry, array $body, Request $request, string $realm): array
    {
        $preview = $request->attributes->get('beam.ux.preview');
        $preview = $preview instanceof PreviewContext ? $preview : null;
        $route = (string) ($request->route()?->defaults['beamUxArtifactRoute'] ?? '');
        $out = [];
        $visit = function (array $doc) use (&$visit, &$out, $entry, $preview, $route, $request, $realm): void {
            $names = array_values(array_diff($this->components->names($doc), array_keys($out)));
            // Preview versions may carry a not-yet-pinned name: use only its explicitly fixed entry refs.
            $catalog = $this->candidates($entry, $names, 'published_component_name');
            if ($preview !== null) {
                foreach ($this->candidates($entry, $names, 'component_name') as $name => $candidate) {
                    if ($preview->versionFor((string) $candidate->getKey()) !== null) {
                        $catalog[$name] = $candidate;
                    }
                }
            }
            foreach ($catalog as $name => $component) {
                if (isset($out[$name])) {
                    continue;
                }
                $out[$name] = new ComponentArtifactData($name, null, null);
                $chain = app(PublicEntryGate::class)->chainForEntry($component, $realm, $preview === null ? $request->user() : null, $preview);
                if ($chain === null) {
                    continue;
                }
                $ref = $preview?->versionFor((string) $component->getKey()) ?? $component->getAttribute('published_version');
                if ($ref === null) {
                    continue;
                }
                try {
                    $componentBody = $this->document($component, $ref);
                    if ($this->components->definition($componentBody)->name !== $name) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue; // Corrupt stored data is diagnosed by the operator audit, never served as another name.
                }
                if ($route === '' || ! app('router')->has($route)) {
                    continue;
                }
                $version = $this->artifacts->versionKey($ref);
                $url = route($route, ['entry' => $component->getKey(), 'version' => $version]);
                $key = ($component->getConnectionName() ?? 'default').'|'.$component->getConnection()->getDatabaseName().'|'.$component->getKey().'|'.$ref.'|'.$realm.'|'.($request->user()?->getAuthIdentifier() ?? 'guest').'|'.($preview === null ? 'public' : 'preview');
                $bindings = $this->scope->deliveries[$key] ??= app(BindingResolver::class)->resolveDocument($component, $componentBody, $request, $realm);
                $this->scope->privateCache = $this->scope->privateCache || $preview !== null || $bindings !== [] || app(PublicEntryGate::class)->isRestricted($chain);
                $out[$name] = new ComponentArtifactData($name, $url, $version, array_map(fn ($data) => $data->toArray(), $bindings));
                $visit($componentBody);
            }
        };
        $visit($body);

        return array_values($out);
    }

    /** Authorized author envelope. A component's record scope stays separate from its caller's. */
    public function working(BeamUxEntry $entry, array $body, Request $request): array
    {
        $catalog = $duplicates = [];
        foreach ($this->components->entries($entry) as $component) {
            if (! $this->components->authored($component) || isset($this->scope->issues[(string) $component->getKey()]) || ! $this->components->canRead($component, $request->user())) {
                continue;
            }
            try {
                $doc = $this->components->working($component);
                if ($doc !== []) {
                    $name = $this->components->definition($doc)->name;
                    if (array_key_exists($name, $catalog) || isset($duplicates[$name])) {
                        $duplicates[$name] = true;
                        unset($catalog[$name]);
                    } else {
                        $catalog[$name] = [$component, $doc];
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }
        $out = [];
        $visit = function (array $doc) use (&$visit, &$out, $catalog, $request, $entry): void {
            foreach ($this->components->names($doc) as $name) {
                if (isset($out[$name]) || ! isset($catalog[$name])) {
                    continue;
                }
                [$component, $body] = $catalog[$name];
                $bindings = app(BindingResolver::class)->resolveDocument($component, $body, $request, (string) $entry->realm);
                $head = $component->particle_id === null ? null : app(VersionStore::class)->head($component->particle);
                $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class)->forUser($request->user());
                $visible = (new \Splicewire\Beam\Ux\Canvas\ViewGateFilter($gate))->filter($body);
                $out[$name] = new ComponentCanvasData(
                    (string) $component->getKey(), $this->components->definition($body),
                    array_map(fn ($node) => JsonNode::from($node), $visible),
                    array_map(fn ($data) => $data->toArray(), $bindings),
                    $head === null ? null : (string) $head->id, $component->getAttribute('published_version'),
                );
                $visit($visible);
            }
        };
        $visit($body);
        // The palette offers every readable Working Head in the profile, including not-yet-used entries.
        foreach (array_keys($catalog) as $name) {
            $visit([['kind' => 'block', 'name' => $name, 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => []]]);
        }

        return array_values($out);
    }
}
