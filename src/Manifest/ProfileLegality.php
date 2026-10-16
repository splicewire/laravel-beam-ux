<?php

namespace Splicewire\Beam\Ux\Manifest;

use Illuminate\Validation\ValidationException;

/**
 * ADR-0228 d8 / ux-0227 d1 (EP-07a, part c) — the PHP save legality check. It walks a canvas (JsonDoc)
 * body and refuses each node the realm's resolved Manifest Profile does not admit, naming the node by
 * **id + path** in a single 422 on `body`.
 *
 * Enforced:
 *   - **unknown name** — a block whose JSX tag (`name`) is not an entry of the profile. A registered
 *     component is simply a profile entry, so an *unregistered* component falls here too (ux-0227 d1:
 *     "a tenant author places registered components"). A fragment (`name === null`) is a transparent
 *     wrapper and carries no name of its own.
 *   - **containment** — a node must sit under a parent that admits its category. The root's admitted
 *     set is the profile's `doc.admitsChildCategories` (`null` = unconstrained, `[]` = leaf); a block's
 *     children are checked against its own `admitsChildCategories`. A text node is admitted only where
 *     its parent's `admitsText` is true. A fragment is transparent: its children are checked against
 *     the fragment's own parent.
 *   - **code, when `x-admits-code: false`** (ux-0227 d1) — an **opaque node** (verbatim code in its
 *     `source`) and an **expression prop** (`PropKind::Expression`). A `dynamic` flag is not code unless
 *     it carries executable source, which this shape does not; registered components are allowed.
 *
 * The per-node `attrsSchema` (opis) lands in the next increment; EP-06 d8 narrows the walk to only the
 * nodes a save adds or changes (by id).
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
        $entries = array_column($profile['nodes'] ?? [], null, 'name');
        $docAdmits = $profile['doc']['admitsChildCategories'] ?? null;

        $violations = [];
        // The document root admits its declared categories and never bare text.
        $this->walk($body, '', $entries, $admitsCode, $docAdmits, false, $violations);

        if ($violations !== []) {
            throw ValidationException::withMessages(['body' => $violations]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $entries        name => v1 node entry
     * @param  list<string>|null                  $parentAdmits   categories the parent admits (null = unconstrained)
     * @param  list<string>                       $violations
     */
    private function walk(array $nodes, string $prefix, array $entries, bool $admitsCode, ?array $parentAdmits, bool $parentAdmitsText, array &$violations): void
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

            if ($kind === 'text') {
                if (! $parentAdmitsText) {
                    $violations[] = $this->message($path, null, 'has text where the parent admits none');
                }

                continue;
            }

            if ($kind !== 'block') {
                continue;
            }

            $id = $node['id'] ?? null;
            $name = $node['name'] ?? null;

            // A fragment is a transparent wrapper: its children are checked against ITS parent.
            if ($name === null) {
                $this->walk($node['children'] ?? [], $path, $entries, $admitsCode, $parentAdmits, $parentAdmitsText, $violations);

                continue;
            }

            if (! $admitsCode) {
                foreach ($node['props'] ?? [] as $prop) {
                    if (($prop['kind'] ?? null) === 'expression') {
                        $propName = $prop['name'] ?? '?';
                        $violations[] = $this->message($path, $id, "carries the expression prop '$propName' (code); this profile does not admit code (ux-0227 d1)");
                    }
                }
            }

            $entry = $entries[$name] ?? null;

            if ($entry === null) {
                // Unknown name: an unregistered block. Don't cascade containment onto its children.
                $violations[] = $this->message($path, $id, "uses the unknown block '$name', which is not in this profile");
                $this->walk($node['children'] ?? [], $path, $entries, $admitsCode, null, true, $violations);

                continue;
            }

            // Containment: the parent must admit this node's category (null parent = unconstrained).
            $category = $entry['category'] ?? null;
            if ($parentAdmits !== null && ($category === null || ! in_array($category, $parentAdmits, true))) {
                $admitted = $parentAdmits === [] ? 'nothing' : implode(', ', $parentAdmits);
                $violations[] = $this->message($path, $id, "block '$name' (category '$category') is not admitted here (the parent admits: $admitted)");
            }

            $this->walk($node['children'] ?? [], $path, $entries, $admitsCode, $entry['admitsChildCategories'] ?? null, (bool) ($entry['admitsText'] ?? false), $violations);
        }
    }

    private function message(string $path, ?string $id, string $what): string
    {
        $where = $id !== null ? "node #$id" : 'node';

        return "$where at [$path] $what";
    }
}
