<?php

namespace Splicewire\Beam\Ux\Components;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Contracts\VersionStore;
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
        $scope = app(ComponentScope::class);
        $key = 'working|'.($entry->getConnectionName() ?? 'default').'|'.$entry->getConnection()->getDatabaseName().'|'.$entry->particle_id;
        if (array_key_exists($key, $scope->documents)) {
            return $scope->documents[$key];
        }
        $item = app(\Splicewire\Beam\Ux\Storage\StorageDriverResolver::class)
            ->resolveOnEntryConnection($entry)->read((string) $entry->particle_id);
        if ($item === null) {
            $this->refuse('A component dependency has no readable Working Head.');
        }

        return $scope->documents[$key] = $item->body;
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

        $key = 'entries|'.($connection ?? 'default').'|'.$context->getConnection()->getDatabaseName().'|'.$context->realm;

        return app(ComponentScope::class)->lookups[$key] ??= $context->newQuery()->where('realm', $context->realm)->where('type', UxType::Component->value)->get()->all();
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
    public function extendProfile(BeamUxEntry $context, array $profile, bool $inspectAll = false): array
    {
        $registered = array_column($profile['nodes'], null, 'name');
        $pending = $owners = $duplicates = $reserved = [];
        $scope = app(ComponentScope::class);
        foreach ($this->entries($context) as $entry) {
            if (! $this->authored($entry) || (! $inspectAll && ! $this->canRead($entry, request()->user()))) {
                continue;
            }
            try {
                $body = $this->working($entry);
                if ($body === []) {
                    continue;
                }
                $name = $this->definition($body)->name;
                if (isset($registered[$name])) {
                    continue;
                }
                if (isset($owners[$name])) {
                    $holder = $entry->getAttribute('component_name') === $name;
                    if ($reserved[$name] ?? false) {
                        $scope->issues[(string) $entry->getKey()] = 'Duplicate component name';

                        continue;
                    }
                    if ($holder) {
                        unset($duplicates[$name]);
                    } else {
                        $duplicates[$name] = true;
                    }
                    $scope->issues[$owners[$name]] = 'Duplicate component name';
                    if (! $holder) {
                        $scope->issues[(string) $entry->getKey()] = 'Duplicate component name';
                    }
                }
                $reserved[$name] = $entry->getAttribute('component_name') === $name;
                $owners[$name] = (string) $entry->getKey();
                $pending[$name] = $body;
            } catch (\Throwable) {
                // Stored corruption is a doctor finding, never a realm-wide editor outage.
                $scope->issues[(string) $entry->getKey()] = 'Unreadable component declaration';
            }
        }
        foreach (array_keys($duplicates) as $name) {
            unset($pending[$name]);
        }
        $visiting = $failed = [];
        $add = function (string $name) use (&$add, &$profile, &$registered, &$pending, &$visiting, &$failed, $scope, $owners): bool {
            if (isset($registered[$name])) {
                return true;
            }
            if (! isset($pending[$name]) || isset($visiting[$name]) || isset($failed[$name])) {
                return false;
            }
            $visiting[$name] = true;
            try {
                foreach ($this->names($pending[$name]) as $dependency) {
                    if (! $add($dependency)) {
                        throw new \LogicException('Unavailable or cyclic dependency');
                    }
                }
                $node = $this->node($pending[$name], $profile);
                $profile['nodes'][] = $node;
                $registered[$name] = $node;

                return true;
            } catch (\Throwable) {
                $failed[$name] = true;
                $scope->issues[$owners[$name]] = 'Invalid or cyclic component declaration';

                return false;
            } finally {
                unset($visiting[$name]);
            }
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
            try {
                $doc = (string) $component->getKey() === (string) $entry->getKey() ? $body : $this->working($component);
                if ($doc === []) {
                    continue;
                }
                $name = $this->definition($doc)->name;
            } catch (\Throwable) {
                continue;
            }
            if (isset($registered[$name])) {
                continue;
            }
            if (array_key_exists($name, $byName)) {
                $prior = $byName[$name];
                if ($prior?->getAttribute('component_name') === $name) {
                    continue;
                }
                if ($component->getAttribute('component_name') !== $name) {
                    $byName[$name] = null;

                    continue;
                }
            }
            $byName[$name] = $component;
        }
        if ($this->authored($entry)) {
            $name = $this->definition($body)->name;
            $previous = $this->working($entry);
            if ($previous === []) {
                $previous = $this->version($entry, $entry->getAttribute('published_version'));
            }
            $oldName = $previous === [] ? null : $this->definition($previous)->name;
            $ownsOldName = $entry->getAttribute('component_name') !== null || $entry->getAttribute('published_version') !== null;
            if ($ownsOldName && $oldName !== null && $oldName !== $name && $this->usedBy($entry, $oldName) !== []) {
                $this->refuse('A component in use cannot be renamed.');
            }
            if (array_key_exists($name, $byName) && ($byName[$name] === null || (string) $byName[$name]->getKey() !== (string) $entry->getKey())) {
                $this->refuse('Component names must be unique in their realm.');
            }
            if ($publishing) {
                // A pending rename cannot release its still-visible Pin name. The existing publication
                // transaction locks the complete set; fixed Release refs can move both claims together.
                foreach ($this->entries($entry) as $other) {
                    $id = (string) $other->getKey();
                    if ($id === (string) $entry->getKey() || ! $this->authored($other)) {
                        continue;
                    }
                    if (array_key_exists($id, $refs)) {
                        $selected = $refs[$id] === null ? [] : $this->version($other, (string) $refs[$id]);
                        $otherName = $selected === [] ? null : $this->definition($selected)->name;
                    } else {
                        $otherName = $other->getAttribute('published_version') === null ? null : $other->getAttribute('published_component_name');
                    }
                    if (is_string($otherName) && strcasecmp($otherName, $name) === 0) {
                        $this->refuse('Published component names must be unique in their realm. Publish the rename first or in the same Release.');
                    }
                }
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
                    if ($this->definition($next)->name !== $name) {
                        $this->refuse('Publish the renamed component first, or include its matching version in the same Release.');
                    }
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
        $names = $name === null ? [] : [$name];
        if ($name === null) {
            $names = array_filter([$component->getAttribute('component_name'), $component->getAttribute('published_component_name')], 'is_string');
            try {
                $retained = $this->retainedBodies($component);
            } catch (\Throwable) {
                app(ComponentScope::class)->issues[(string) $component->getKey()] = 'Unreadable component declaration';
                $retained = [];
            }
            foreach ($retained as $body) {
                if (JsonDocShape::is($body)) {
                    $names[] = $this->definition($body)->name;
                }
            }
        }
        // An unreserved legacy copy cannot own usages belonging to the actual reservation holder.
        if ($component->getAttribute('component_name') === null && $component->getAttribute('published_version') === null) {
            $names = array_values(array_filter($names, fn ($candidate) => ! $component->newQuery()
                ->where('realm', $component->realm)->whereKeyNot($component->getKey())
                ->where('component_name', $candidate)->exists()));
        }
        $used = [];
        foreach ($component->newQuery()->where('realm', $component->realm)->whereKeyNot($component->getKey())->get() as $entry) {
            try {
                $bodies = $this->retainedBodies($entry);
            } catch (\Throwable) {
                app(ComponentScope::class)->issues[(string) $entry->getKey()] = 'Unreadable component dependency';

                continue;
            }
            foreach ($bodies as $body) {
                if (array_intersect($names, $this->names($body))) {
                    $used[] = (string) $entry->getKey();
                    break;
                }
            }
        }

        return $used;
    }

    /** Retry the whole owning transaction once; a rolled-back body must not leave memoized reads. */
    public function reservationTransaction(\Illuminate\Database\Connection $connection, \Closure $callback): mixed
    {
        if ($connection->transactionLevel() > 0) {
            // The outer owner retries the complete save, not a half-finished nested writer.
            return $callback();
        }
        try {
            return $connection->transaction(function () use ($callback) {
                app(ComponentScope::class)->forget();

                return $callback();
            }, 2);
        } catch (\Throwable $failure) {
            if (in_array((string) $failure->getCode(), ['40001', '1213', '1205'], true)
                || str_contains(strtolower($failure->getMessage()), 'deadlock')
                || str_contains(strtolower($failure->getMessage()), 'serialization failure')
                || str_contains(strtolower($failure->getMessage()), 'lock wait timeout')) {
                $this->refuse('The component reservation conflicted. Retry the save against the current head.');
            }
            throw $failure;
        }
    }

    /** Name reservation shares the caller's body transaction; the DB owns concurrent uniqueness. */
    public function reserveName(BeamUxEntry $entry, array $body): void
    {
        if (! $this->authored($entry) || ! JsonDocShape::is($body)) {
            return;
        }
        if (! $entry->getConnection()->getSchemaBuilder()->hasColumn($entry->getTable(), 'component_name')) {
            return;
        }
        $name = $this->definition($body)->name;
        $taken = $entry->newQuery()->where('realm', $entry->realm)->whereKeyNot($entry->getKey())
            ->where(fn ($query) => $query->whereRaw('LOWER(component_name) = ?', [strtolower($name)])
                ->orWhereRaw('LOWER(published_component_name) = ?', [strtolower($name)]))->exists();
        if ($taken) {
            $this->refuse('Component names must be unique in their realm; this name is taken.');
        }
        try {
            $entry->forceFill(['component_name' => $name])->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->refuse('Component names must be unique in their realm; this name is taken.');
        }
    }

    /** Restoring an old Pin must not reclaim a name published by a surviving row. */
    public function assertRestoredPublishedName(BeamUxEntry $entry, array $body): void
    {
        if ($body === [] || ! $entry->getConnection()->getSchemaBuilder()->hasColumn($entry->getTable(), 'published_component_name')) {
            return;
        }
        $name = $this->definition($body)->name;
        if ($entry->newQuery()->where('realm', $entry->realm)->whereKeyNot($entry->getKey())
            ->whereNotNull('published_version')->whereRaw('LOWER(published_component_name) = ?', [strtolower($name)])->exists()) {
            $this->refuse('Cannot restore: published component name '.$name.' conflicts with another published component. Rename the conflicting component first.');
        }
    }

    public function setPublishedName(BeamUxEntry $entry, array $body): void
    {
        if ($this->authored($entry) && $entry->getConnection()->getSchemaBuilder()->hasColumn($entry->getTable(), 'published_component_name')) {
            $entry->setAttribute('published_component_name', $body === [] ? null : $this->definition($body)->name);
        }
    }

    public function retainedBodies(BeamUxEntry $entry): array
    {
        $bodies = [$this->working($entry), $this->version($entry, $entry->getAttribute('published_version'))];
        $connection = $entry->getConnection();
        if ($connection->getSchemaBuilder()->hasTable('beam_ux_release_members') && $connection->getSchemaBuilder()->hasTable('beam_ux_releases')) {
            $refs = $connection->table('beam_ux_release_members as member')
                ->join('beam_ux_releases as release', 'release.id', '=', 'member.release_id')
                ->where('member.entry_id', $entry->getKey())->where('release.status', '!=', 'published')
                ->whereNotNull('member.version_id')->pluck('member.version_id');
            foreach ($refs as $ref) {
                $bodies[] = $this->version($entry, (string) $ref);
            }
        }

        return $bodies;
    }

    /** Interface edits diagnose readable affected pages; they never rewrite or refuse those pages. */
    public function strandedInstances(BeamUxEntry $component, array $profile): array
    {
        if (! $this->authored($component) || ($working = $this->working($component)) === []) {
            return [];
        }
        $name = $this->definition($working)->name;
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
        if (! JsonDocShape::is($body)) {
            return [];
        }
        // Read-only dependency discovery is not admission/hydration. Legacy bound render trees may
        // omit printer-only fields; the save boundary still requires the declared JsonNode shape.
        $names = [];
        $walk = function (array $nodes) use (&$walk, &$names): void {
            foreach ($nodes as $node) {
                if (($node['kind'] ?? null) !== 'block') {
                    continue;
                }
                $name = $node['name'] ?? null;
                if (($node['isComponent'] ?? false) && is_string($name) && $name !== '' && $name !== 'Slot') {
                    $names[$name] = true;
                }
                $walk(NodeChildren::of($node));
                $walk($node['repeat']['empty'] ?? []);
            }
        };
        $walk($body);

        return array_keys($names);
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
