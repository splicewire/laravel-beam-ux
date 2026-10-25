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

/** Entry addresses use the same publication/access gate as the native artifact stream. No writes or compilation. */
class ComponentDelivery
{
    public function __construct(private AuthoredComponents $components, private EntryArtifactStore $artifacts) {}

    /** @return list<ComponentArtifactData> */
    public function published(BeamUxEntry $entry, array $body, Request $request, string $realm): array
    {
        $preview = $request->attributes->get('beam.ux.preview');
        $preview = $preview instanceof PreviewContext ? $preview : null;
        $catalog = [];
        $entries = array_filter($this->components->entries($entry), fn ($candidate) => $this->components->authored($candidate));
        if ($entries === []) {
            return [];
        }
        foreach ($entries as $candidate) {
            if (! $this->components->authored($candidate)
                || app(PublicEntryGate::class)->chainForEntry($candidate, $realm, $preview === null ? $request->user() : null, $preview) === null) {
                continue;
            }
            $ref = $preview?->versionFor((string) $candidate->getKey()) ?? $candidate->getAttribute('published_version');
            if ($ref === null) {
                continue;
            }
            $doc = $this->components->version($candidate, $ref);
            if ($doc !== []) {
                $catalog[$this->components->definition($doc)->name] = [$candidate, $ref, $doc];
            }
        }
        $route = (string) ($request->route()?->defaults['beamUxArtifactRoute'] ?? '');
        $out = [];
        $visit = function (array $doc) use (&$visit, &$out, $catalog, $route, $request, $realm): void {
            foreach ($this->components->names($doc) as $name) {
                if (isset($out[$name])) {
                    continue;
                }
                // A denied, taken-down or missing entry is an empty instance. A registered Code Component wins client-side.
                $out[$name] = new ComponentArtifactData($name, null, null);
                if (! isset($catalog[$name])) {
                    continue;
                }
                [$component, $ref, $body] = $catalog[$name];
                $version = $this->artifacts->versionKey($ref);
                if ($route === '' || ! app('router')->has($route)) {
                    continue;
                }
                $url = route($route, ['entry' => $component->getKey(), 'version' => $version]);
                $bindings = app(BindingResolver::class)->resolveDocument($component, $body, $request, $realm);
                $out[$name] = new ComponentArtifactData($name, $url, $version, array_map(fn ($data) => $data->toArray(), $bindings));
                $visit($body);
            }
        };
        $visit($body);

        return array_values($out);
    }

    /** Authorized author envelope. A component's record scope stays separate from its caller's. */
    public function working(BeamUxEntry $entry, array $body, Request $request): array
    {
        $catalog = [];
        foreach ($this->components->entries($entry) as $component) {
            if (! $this->components->authored($component) || ! $this->components->canRead($component, $request->user())) {
                continue;
            }
            $doc = $this->components->working($component);
            if ($doc !== []) {
                $catalog[$this->components->definition($doc)->name] = [$component, $doc];
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
