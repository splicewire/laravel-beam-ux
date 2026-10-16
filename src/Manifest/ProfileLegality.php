<?php

namespace Splicewire\Beam\Ux\Manifest;

use Illuminate\Validation\ValidationException;

/**
 * ADR-0228 d8 / ux-0227 d1 (EP-07a, part c) — the PHP save legality check. It walks a canvas (JsonDoc)
 * body and refuses each node the realm's resolved Manifest Profile does not admit, naming the node by
 * **id + path** in a single 422 on `body`.
 *
 * This increment enforces:
 *   - **unknown name** — a block whose JSX tag (`name`) is not an entry of the profile. A registered
 *     component is simply a profile entry, so an *unregistered* component falls here too (ux-0227 d1:
 *     "a tenant author places registered components"). A fragment (`name === null`) is a transparent
 *     wrapper and carries no name of its own.
 *   - **code, when `x-admits-code: false`** (ux-0227 d1) — an **opaque node** (it carries verbatim code
 *     in its `source`) and an **expression prop** (`PropKind::Expression`). A `dynamic` flag is not code
 *     unless it carries executable source, which this shape does not; registered components are allowed.
 *
 * Containment (a child's category admitted by its parent) and the per-node `attrsSchema` land in the
 * next increment; EP-06 d8 narrows the walk to only the nodes a save adds or changes (by id).
 */
final class ProfileLegality
{
    /**
     * @param  array<int, array<string, mixed>>  $body     the JsonDoc node array (wire shape)
     * @param  array<string, mixed>              $profile  a resolved v1 manifest: { nodes, doc, x-admits-code, ... }
     *
     * @throws ValidationException
     */
    public function assert(array $body, array $profile): void
    {
        $admitsCode = (bool) ($profile['x-admits-code'] ?? true);
        $known = array_column($profile['nodes'] ?? [], 'name');

        $violations = [];
        $this->walk($body, '', $known, $admitsCode, $violations);

        if ($violations !== []) {
            throw ValidationException::withMessages(['body' => $violations]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  list<string>                      $known
     * @param  list<string>                      $violations
     */
    private function walk(array $nodes, string $prefix, array $known, bool $admitsCode, array &$violations): void
    {
        foreach ($nodes as $i => $node) {
            $path = $prefix === '' ? (string) $i : "$prefix.$i";
            $kind = $node['kind'] ?? null;

            if ($kind === 'opaque') {
                if (! $admitsCode) {
                    $violations[] = $this->message($path, null, 'carries an opaque node (verbatim code); this profile does not admit code (ux-0227 d1)');
                }

                continue;
            }

            if ($kind !== 'block') {
                continue; // a text node carries no name, props, or children to check
            }

            $id = $node['id'] ?? null;
            $name = $node['name'] ?? null;

            // Unknown name: a named block whose tag is not an entry of the profile (an unregistered
            // component lands here). A fragment (name === null) is a transparent wrapper.
            if ($name !== null && ! in_array($name, $known, true)) {
                $violations[] = $this->message($path, $id, "uses the unknown block '$name', which is not in this profile");
            }

            if (! $admitsCode) {
                foreach ($node['props'] ?? [] as $prop) {
                    if (($prop['kind'] ?? null) === 'expression') {
                        $propName = $prop['name'] ?? '?';
                        $violations[] = $this->message($path, $id, "carries the expression prop '$propName' (code); this profile does not admit code (ux-0227 d1)");
                    }
                }
            }

            $this->walk($node['children'] ?? [], $path, $known, $admitsCode, $violations);
        }
    }

    private function message(string $path, ?string $id, string $what): string
    {
        $where = $id !== null ? "node #$id" : 'node';

        return "$where at [$path] $what";
    }
}
