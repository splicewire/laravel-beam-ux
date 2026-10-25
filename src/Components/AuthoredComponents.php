<?php

namespace Splicewire\Beam\Ux\Components;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Codec\JsonDocPrinter;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\JsonDoc\NodeChildren;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/** Same-realm, same-connection component declarations and their versioned dependency graph. */
class AuthoredComponents
{
    public function authored(BeamUxEntry $entry): bool
    {
        return $entry->type === UxType::Component && $entry->getAttribute('component_kind') === ComponentKind::Authored;
    }

    /** The only normalization: freeze the default name with the first body, never with a later title. */
    public function prepare(BeamUxEntry $entry, array $body): array
    {
        if (! $this->authored($entry) || ! JsonDocShape::is($body)) {
            return $body;
        }
        $body[0]['component'] ??= ['name' => null, 'props' => []];
        if (! isset($body[0]['component']['name'])) {
            $previous = $this->working($entry);
            $body[0]['component']['name'] = $previous[0]['component']['name'] ?? Str::studly((string) ($entry->title ?: $entry->slug));
        }

        return $body;
    }

    /** @return array<int|string, mixed> */
    public function working(BeamUxEntry $entry): array
    {
        if ($entry->particle_id === null) {
            return [];
        }
        $item = app(\Splicewire\Beam\Ux\Storage\StorageDriverResolver::class)
            ->resolveOnEntryConnection($entry)->read((string) $entry->particle_id);
        if ($item === null) {
            $this->refuse('A component dependency has no readable Working Head.');
        }

        return $item->body;
    }

    public function version(BeamUxEntry $entry, ?string $ref): array
    {
        if ($ref === null || $entry->particle_id === null) {
            return [];
        }
        $body = app(VersionStore::class)->version($entry->particle, $ref)?->snapshot['payload'] ?? null;
        if (! is_array($body)) {
            $this->refuse('A component version has no readable body snapshot.');
        }

        return $body;
    }

    /** @return list<BeamUxEntry> No realm fallback or central-to-tenant reference is inferred. */
    public function entries(BeamUxEntry $context): array
    {
        $connection = $context->getConnectionName();
        if ($connection !== null && ! array_key_exists($connection, config('database.connections', []))) {
            // A declared tenant context without an initialized connection offers no entries.
            // Profile/code-policy reads must not open a central fallback or initialize tenancy.
            return [];
        }
        if (! $context->getConnection()->getSchemaBuilder()->hasTable($context->getTable())) {
            return [];
        }

        return $context->newQuery()->where('realm', $context->realm)->where('type', UxType::Component->value)->get()->all();
    }

    public function definition(array $body): ComponentDefinitionData
    {
        if (count($body) !== 1 || ($body[0]['kind'] ?? null) !== 'block' || ! is_string($body[0]['name'] ?? null)
            || ! is_array($body[0]['component'] ?? null)) {
            $this->refuse('An Authored Component requires one root block and its declared interface.');
        }
        try {
            $definition = ComponentDefinitionData::from($body[0]['component']);
        } catch (\Throwable $failure) {
            $this->refuse('The component interface is invalid: '.$failure->getMessage());
        }
        if ($definition->name === null) {
            $this->refuse('The component name must be saved with its interface.');
        }

        return $definition;
    }

    /** Slot declarations are derived from the body; an instance cannot author a second slot vocabulary. */
    public function node(array $body, array $profile): array
    {
        $definition = $this->definition($body);
        $nodes = array_column($profile['nodes'], null, 'name');
        $root = $nodes[$body[0]['name']] ?? null;
        if ($root === null) {
            $this->refuse('The component root must be a node in its realm profile.');
        }
        $slots = [];
        $this->slots($body, $nodes, $profile['doc']['admitsChildCategories'] ?? null, $slots);
        $named = [];
        foreach ($slots as $name => $categories) {
            if ($name !== 'children') {
                if (in_array($name, array_map(fn ($prop) => $prop->name, $definition->props), true)) {
                    $this->refuse('A component prop and Slot cannot share a name.');
                }
                $named[] = ['name' => $name, 'admitsChildCategories' => $categories];
            }
        }

        return ['name' => $definition->name, 'category' => $root['category'] ?? 'block',
            'admitsChildCategories' => array_key_exists('children', $slots) ? $slots['children'] : [],
            'admitsText' => array_key_exists('children', $slots) && ($root['admitsText'] ?? false), 'slots' => $named,
            'attrsSchema' => $definition->schema(), 'x-authored-component' => true, 'x-forwards-root-style' => true];
    }

    private function slots(array $body, array $nodes, ?array $parentAdmits, array &$slots): void
    {
        foreach ($body as $node) {
            if (($node['kind'] ?? null) !== 'block') {
                continue;
            }
            if (($node['name'] ?? null) === 'Slot') {
                $name = 'children';
                foreach ($node['props'] ?? [] as $prop) {
                    if (($prop['name'] ?? null) !== 'name' || ($prop['kind'] ?? null) !== 'string') {
                        $this->refuse('A Slot accepts only its literal name.');
                    }
                    $name = $prop['value'];
                }
                \Splicewire\Beam\Ux\JsonDoc\ComponentReference::assert($name);
                if ($name !== 'children' && in_array($name, ComponentPropData::RUNTIME_PROPS, true)) {
                    $this->refuse('A Slot cannot replace a component runtime channel.');
                }
                if (array_key_exists($name, $slots)) {
                    $this->refuse('A component must declare each Slot only once.');
                }
                $slots[$name] = $parentAdmits;
            }
            $this->slots(NodeChildren::of($node), $nodes, $nodes[$node['name'] ?? '']['admitsChildCategories'] ?? $parentAdmits, $slots);
            $this->slots($node['repeat']['empty'] ?? [], $nodes, $parentAdmits, $slots);
        }
    }

    /** Append Working Head declarations, preserving every registered name's priority. */
    public function extendProfile(BeamUxEntry $context, array $profile): array
    {
        $registered = array_column($profile['nodes'], null, 'name');
        $pending = [];
        foreach ($this->entries($context) as $entry) {
            if (! $this->authored($entry) || ! $this->canRead($entry, request()->user()) || ($body = $this->working($entry)) === []) {
                continue;
            }
            $name = $this->definition($body)->name;
            if (! isset($registered[$name])) {
                if (isset($pending[$name])) {
                    $this->refuse('Component names must be unique in their realm.');
                }
                $pending[$name] = $body;
            }
        }
        $visiting = [];
        $add = function (string $name) use (&$add, &$profile, &$registered, &$pending, &$visiting): void {
            if (isset($registered[$name]) || ! isset($pending[$name])) {
                return;
            }
            if (isset($visiting[$name])) {
                $this->refuse('Components cannot contain a cycle.');
            }
            $visiting[$name] = true;
            foreach ($this->names($pending[$name]) as $dependency) {
                $add($dependency);
            }
            $node = $this->node($pending[$name], $profile);
            $profile['nodes'][] = $node;
            $registered[$name] = $node;
            unset($visiting[$name]);
        };
        foreach (array_keys($pending) as $name) {
            $add($name);
        }

        return $profile;
    }

    /** The proposed body's own interface participates before that body has been written. */
    public function forBody(BeamUxEntry $entry, array $body, array $profile): array
    {
        $this->assertDefinitionPlacement($body, $this->authored($entry));
        $component = null;
        if ($this->authored($entry)) {
            $node = $this->node($body, $profile);
            $component = $node['name'];
            foreach ($profile['nodes'] as $existing) {
                if ($existing['name'] === $component && ! ($existing['x-authored-component'] ?? false)) {
                    $this->refuse('A registered component already uses this name.');
                }
            }
            $profile['nodes'] = array_values(array_filter($profile['nodes'], fn ($existing) => $existing['name'] !== $component));
            $profile['nodes'][] = $node;
        } elseif (in_array($entry->type, [UxType::Layout, UxType::Template], true) && JsonDocShape::is($body)) {
            $slots = [];
            $this->slots($body, array_column($profile['nodes'], null, 'name'), null, $slots);
            if (array_keys($slots) !== ['children']) {
                $this->refuse('A blocks layout or template requires exactly one unnamed Slot.');
            }
            $component = 'BeamChrome';
            $profile['nodes'][] = ['name' => $component, 'attrsSchema' => ['type' => 'object', 'additionalProperties' => false], 'admitsChildCategories' => null];
        }

        return [$profile, $component];
    }

    /** Save graph: Working Heads. Publish graph: fixed Release refs, otherwise Publication Pins. */
    public function assertGraph(BeamUxEntry $entry, array $body, bool $publishing = false, array $refs = [], array $profile = []): void
    {
        $byName = [];
        $registered = array_column(array_filter($profile['nodes'] ?? [], fn ($node) => ! ($node['x-authored-component'] ?? false)), null, 'name');
        foreach ($this->entries($entry) as $component) {
            if (! $this->authored($component)) {
                continue;
            }
            $doc = (string) $component->getKey() === (string) $entry->getKey() ? $body : $this->working($component);
            if ($doc === []) {
                continue;
            }
            $name = $this->definition($doc)->name;
            if (isset($registered[$name])) {
                continue;
            }
            if (isset($byName[$name])) {
                $this->refuse('Component names must be unique in their realm.');
            }
            $byName[$name] = $component;
        }
        if ($this->authored($entry)) {
            $name = $this->definition($body)->name;
            $previous = $this->working($entry);
            $oldName = $previous === [] ? null : $this->definition($previous)->name;
            if ($oldName !== null && $oldName !== $name && $this->usedBy($entry, $oldName) !== []) {
                $this->refuse('A component in use cannot be renamed.');
            }
            if (isset($byName[$name]) && (string) $byName[$name]->getKey() !== (string) $entry->getKey()) {
                $this->refuse('Component names must be unique in their realm.');
            }
            $byName[$name] = $entry;
        }
        $visit = function (BeamUxEntry $owner, array $doc, array $path) use (&$visit, $byName, $entry, $body, $publishing, $refs, $registered): void {
            $id = (string) $owner->getKey();
            if (isset($path[$id]) || count($path) >= 64) {
                $this->refuse('Components cannot contain a cycle.');
            }
            $path[$id] = true;
            foreach ($this->names($doc) as $name) {
                if (isset($registered[$name])) {
                    continue;
                }
                $target = $byName[$name] ?? null;
                if ($target === null || ! $this->canRead($target, request()->user())) {
                    $this->refuse('A referenced component is unavailable in this realm.');
                }
                $targetId = (string) $target->getKey();
                if ($publishing) {
                    $ref = array_key_exists($targetId, $refs) ? $refs[$targetId] : $target->getAttribute('published_version');
                    if ($ref === null) {
                        $this->refuse('Publish the component '.$name.' first, or include it in the same Release.');
                    }
                    $next = $this->version($target, (string) $ref);
                } else {
                    $next = $targetId === (string) $entry->getKey() ? $body : $this->working($target);
                }
                $visit($target, $next, $path);
            }
        };
        $visit($entry, $body, []);
    }

    /** Both histories matter: a pending draft and a guest-visible pin can independently use the name. */
    public function usedBy(BeamUxEntry $component, ?string $name = null): array
    {
        $name ??= $this->definition($this->working($component))->name;
        $used = [];
        foreach ($component->newQuery()->where('realm', $component->realm)->whereKeyNot($component->getKey())->get() as $entry) {
            foreach ([$this->working($entry), $this->version($entry, $entry->getAttribute('published_version'))] as $body) {
                if (in_array($name, $this->names($body), true)) {
                    $used[] = (string) $entry->getKey();
                    break;
                }
            }
        }

        return $used;
    }

    /** Interface edits diagnose readable affected pages; they never rewrite or refuse those pages. */
    public function strandedInstances(BeamUxEntry $component, array $profile): array
    {
        if (! $this->authored($component)) {
            return [];
        }
        $name = $this->definition($this->working($component))->name;
        $diagnostics = [];
        $probeProfile = $profile;
        $probeProfile['doc']['admitsChildCategories'] = null;
        foreach ($component->newQuery()->where('realm', $component->realm)->where('type', UxType::Page->value)->get() as $page) {
            if (! $this->canRead($page, request()->user())) {
                continue;
            }
            $walk = function (array $nodes) use (&$walk, &$diagnostics, $name, $page, $probeProfile): void {
                foreach ($nodes as $node) {
                    if (($node['kind'] ?? null) !== 'block') {
                        continue;
                    }
                    if (($node['name'] ?? null) === $name) {
                        $probe = $node;
                        $probe['children'] = [];
                        $probe['slots'] = array_fill_keys(array_keys($node['slots'] ?? []), []);
                        unset($probe['repeat'], $probe['when']);
                        $result = app(\Splicewire\Beam\Ux\Manifest\ProfileLegality::class)->violations([$probe], $probeProfile);
                        foreach ($result['legality'] as $message) {
                            $diagnostics[] = 'Entry '.(string) $page->getKey().': '.$message;
                        }
                    }
                    $walk(NodeChildren::of($node));
                    $walk($node['repeat']['empty'] ?? []);
                }
            };
            $walk($this->working($page));
            $walk($this->version($page, $page->getAttribute('published_version')));
        }

        return array_values(array_unique($diagnostics));
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['body' => $message]);
    }

    public function names(array $body): array
    {
        return JsonDocShape::is($body) ? JsonDocPrinter::componentNames($body) : [];
    }

    public function canRead(BeamUxEntry $entry, ?\Illuminate\Contracts\Auth\Authenticatable $actor): bool
    {
        $chain = app(\Splicewire\Beam\Ux\Containment\EntryPathResolver::class)->ancestry($entry);

        return $chain !== null && app(\Splicewire\Beam\Ux\Access\EntryAccessResolver::class)->canRender($actor, $chain);
    }

    private function assertDefinitionPlacement(array $nodes, bool $rootAllowed): void
    {
        foreach ($nodes as $index => $node) {
            if (($node['kind'] ?? null) !== 'block') {
                continue;
            }
            if (isset($node['component']) && (! $rootAllowed || count($nodes) !== 1 || $index !== 0)) {
                $this->refuse('A component interface belongs only to the sole Authored Component root.');
            }
            $this->assertDefinitionPlacement(NodeChildren::of($node), false);
            $this->assertDefinitionPlacement($node['repeat']['empty'] ?? [], false);
        }
    }
}
