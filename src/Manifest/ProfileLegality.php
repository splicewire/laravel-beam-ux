<?php

namespace Splicewire\Beam\Ux\Manifest;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * ADR-0228 d8 / ux-0227 d1 (EP-07a c + EP-06 d8) — the PHP save legality check. It walks a canvas
 * (JsonDoc) body and refuses each node the realm's resolved Manifest Profile does not admit, naming the
 * node by **id + path** in a single 422 on `body`.
 *
 * Two bands, treated differently by a save's narrowing (`$checkIds`, EP-06 d8):
 *   - **Legality** — unknown name, containment, and the per-node `attrsSchema`. A save narrows these to
 *     only the nodes it adds or changes (`$checkIds`), so a pre-existing node it did not touch is not
 *     re-refused. A node with no id cannot be matched and is always checked.
 *   - **Code** (ux-0227 d1) — an **opaque node** (verbatim code) and an **expression prop**. This is
 *     NEVER narrowed: ADR-0227 d1 refuses code on every tenant save, even an unchanged node already in
 *     the entry. `$admitsCode` (the profile's `x-admits-code`) governs whether it runs at all.
 *
 * `$checkIds === null` checks everything (a central save, or any caller without a Working Head to diff).
 */
final class ProfileLegality
{
    /**
     * @param  array<int, array<string, mixed>>  $body  the JsonDoc node array (wire shape)
     * @param  array<string, mixed>  $profile  a resolved v1 manifest: { nodes, doc, x-admits-code, ... }
     * @param  array<string, true>|null  $checkIds  ids whose legality to check; null = all
     *
     * @throws ValidationException
     */
    public function assert(array $body, array $profile, ?array $checkIds = null, ?string $component = null): void
    {
        ['code' => $code, 'legality' => $legality] = $this->violations($body, $profile, $checkIds, $component);
        $violations = [...$code, ...$legality];

        if ($violations !== []) {
            throw ValidationException::withMessages(['body' => $violations]);
        }
    }

    /**
     * The two bands apart, for a caller that treats them differently: a restore refuses code but records
     * legality as diagnostics (ADR-0228 d9 as amended).
     *
     * @param  array<int, array<string, mixed>>  $body
     * @param  array<string, mixed>  $profile
     * @param  array<string, true>|null  $checkIds
     * @return array{code: list<string>, legality: list<string>}
     */
    public function violations(array $body, array $profile, ?array $checkIds = null, ?string $component = null): array
    {
        $admitsCode = (bool) ($profile['x-admits-code'] ?? true);
        $entries = array_column($profile['nodes'] ?? [], null, 'name');
        $componentEntry = $component === null ? null : ($entries[$component] ?? null);
        $docAdmits = $profile['doc']['admitsChildCategories'] ?? null;

        $code = [];
        $violations = [];
        $this->walk($body, '', $entries, $admitsCode, $checkIds, $docAdmits, false, $violations, $code, $componentEntry);

        return ['code' => $code, 'legality' => $violations];
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $entries  name => v1 node entry
     * @param  array<string, true>|null  $checkIds
     * @param  list<string>|null  $parentAdmits  categories the parent admits (null = unconstrained)
     * @param  list<string>  $violations  the legality band
     * @param  list<string>  $code  the code band (ux-0227 d1)
     */
    private function walk(array $nodes, string $prefix, array $entries, bool $admitsCode, ?array $checkIds, ?array $parentAdmits, bool $parentAdmitsText, array &$violations, array &$code, ?array $componentEntry): void
    {
        foreach ($nodes as $i => $node) {
            $path = $prefix === '' ? (string) $i : "$prefix.$i";
            $kind = $node['kind'] ?? null;

            if ($kind === 'opaque') {
                // Code refusal — whole body, never narrowed (ux-0227 d1).
                if (! $admitsCode) {
                    $code[] = $this->message($path, null, 'carries an opaque node (verbatim code); this profile does not admit code (ux-0227 d1)');
                }

                continue;
            }

            if ($kind === 'text') {
                if (isset($node['prop']) && ! $this->hasProp($componentEntry, $node['prop'])) {
                    $code[] = $this->message($path, null, 'uses an undeclared component prop reference');
                }
                if (isset($node['prop']) && $this->hasProp($componentEntry, $node['prop']) && ! SchemaCompatibility::fits($componentEntry['attrsSchema']['properties'][$node['prop']], ['type' => 'string'])) {
                    $violations[] = $this->message($path, null, 'text requires a text component prop');
                }
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
            // An empty-state subtree is authored content at the Repeat's own position. It must not
            // become a second route around whole-body code or profile legality admission.
            $this->walk($node['repeat']['empty'] ?? [], $path.'.empty', $entries, $admitsCode, null, $parentAdmits, $parentAdmitsText, $violations, $code, $componentEntry);

            foreach ($node['props'] ?? [] as $prop) {
                if (($prop['kind'] ?? null) === 'prop' && ! $this->hasProp($componentEntry, $prop['value'] ?? null)) {
                    $code[] = $this->message($path, $id, 'uses an undeclared component prop reference');
                }
            }
            // Slots are grammar, not registry islands. Their fallback is still ordinary content.
            if ($name === 'Slot') {
                $slotName = 'children';
                foreach ($node['props'] ?? [] as $prop) {
                    if (($prop['name'] ?? null) === 'name' && ($prop['kind'] ?? null) === 'string') {
                        $slotName = $prop['value'];
                    } else {
                        $code[] = $this->message($path, $id, 'Slot accepts only a literal name');
                    }
                }
                $declarations = array_column($componentEntry['slots'] ?? [], null, 'name');
                $slot = $slotName === 'children' && $componentEntry !== null ? $componentEntry : ($declarations[$slotName] ?? null);
                if ($slot === null || ($slot['admitsChildCategories'] ?? null) === []) {
                    $code[] = $this->message($path, $id, 'uses an undeclared component slot');
                }
                $this->walk($node['children'] ?? [], $path, $entries, $admitsCode, $checkIds, $slot['admitsChildCategories'] ?? $parentAdmits, $parentAdmitsText, $violations, $code, $componentEntry);
                // Do not let a malformed Slot carry an unvisited subtree.
            }
            $nodeEntry = $entries[$name] ?? null;
            $slotEntries = array_column($nodeEntry['slots'] ?? [], null, 'name');
            foreach ($node['slots'] ?? [] as $slotName => $slotChildren) {
                if (! isset($slotEntries[$slotName])) {
                    $violations[] = $this->message($path, $id, 'uses undeclared named slot '.$slotName);
                }
                $this->walk($slotChildren, $path.'.slots.'.$slotName, $entries, $admitsCode, $checkIds, $slotEntries[$slotName]['admitsChildCategories'] ?? null, false, $violations, $code, $componentEntry);
            }
            if ($name === 'Slot') {
                continue;
            }

            // A fragment is a transparent wrapper: its children are checked against ITS parent.
            if ($name === null) {
                $this->walk($node['children'] ?? [], $path, $entries, $admitsCode, $checkIds, $parentAdmits, $parentAdmitsText, $violations, $code, $componentEntry);

                continue;
            }

            // Code refusal — whole body, never narrowed (ux-0227 d1).
            if (! $admitsCode) {
                foreach ($node['props'] ?? [] as $prop) {
                    if (($prop['kind'] ?? null) === 'expression') {
                        $propName = $prop['name'] ?? '?';
                        $code[] = $this->message($path, $id, "carries the expression prop '$propName' (code); this profile does not admit code (ux-0227 d1)");
                    }
                }
            }

            // Legality band — narrowed to the nodes this save adds or changes (a node with no id, which
            // cannot be matched to the Working Head, is always checked).
            $checkLegality = $checkIds === null || $id === null || isset($checkIds[$id]);

            $entry = $entries[$name] ?? null;

            if ($entry === null) {
                if ($checkLegality) {
                    $violations[] = $this->message($path, $id, "uses the unknown block '$name', which is not in this profile");
                }
                $this->walk($node['children'] ?? [], $path, $entries, $admitsCode, $checkIds, null, true, $violations, $code, $componentEntry);

                continue;
            }

            if ($checkLegality) {
                $category = $entry['category'] ?? null;
                if ($parentAdmits !== null && ($category === null || ! in_array($category, $parentAdmits, true))) {
                    $admitted = $parentAdmits === [] ? 'nothing' : implode(', ', $parentAdmits);
                    $violations[] = $this->message($path, $id, "block '$name' (category '$category') is not admitted here (the parent admits: $admitted)");
                }

                $this->assertAttrsMatchSchema($node, $entry, $path, $id, $violations, $componentEntry);
            }

            $this->walk($node['children'] ?? [], $path, $entries, $admitsCode, $checkIds, $entry['admitsChildCategories'] ?? null, (bool) ($entry['admitsText'] ?? false), $violations, $code, $componentEntry);
        }
    }

    /**
     * EP-07a c-3 — validate a block's props against its node's `attrsSchema` (opis). Literal props are
     * type-checked; an EXPRESSION prop carries dynamic code, so it satisfies a required field by its
     * presence but its type is relaxed to "any" here (whether code is allowed at all is the
     * x-admits-code check's job, not the schema's). A schema violation is one 422 on `body` by id + path.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $violations
     */
    private function assertAttrsMatchSchema(array $node, array $entry, string $path, ?string $id, array &$violations, ?array $componentEntry): void
    {
        $schema = $entry['attrsSchema'] ?? null;
        if (! is_array($schema)) {
            return;
        }

        $attrs = [];
        $dynamic = [];
        foreach ($node['props'] ?? [] as $prop) {
            $name = $prop['name'] ?? null;
            if ($name === null) {
                continue;
            }
            if (($prop['kind'] ?? null) === 'prop' && $this->hasProp($componentEntry, $prop['value'] ?? null)) {
                $source = $componentEntry['attrsSchema']['properties'][$prop['value']];
                $target = $schema['properties'][$name] ?? null;
                if (! is_array($source) || ! is_array($target) || ! SchemaCompatibility::fits($source, $target)) {
                    $violations[] = $this->message($path, $id, 'component prop does not fit attribute '.$name);
                }
            }
            $attrs[$name] = $prop['value'] ?? null;
            if (in_array($prop['kind'] ?? null, ['expression', 'field', 'prop'], true)) {
                $dynamic[] = $name;
            }
        }

        // Keep dynamic props present for required checks. A field carries a path, not its runtime
        // value: BindingBodyGuard checks the offered field schema against this attribute before
        // save/publish reaches legality. Expressions remain governed by the whole-body code band.
        foreach ($dynamic as $name) {
            if (isset($schema['properties'][$name])) {
                $schema['properties'][$name] = new \stdClass;
            }
        }

        $result = (new Validator)->validate(
            json_decode(json_encode($attrs, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR)),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR)),
        );

        if (! $result->isValid()) {
            $errors = (new ErrorFormatter)->formatKeyed($result->error());
            $violations[] = $this->message($path, $id, 'has props that do not match the block schema: '.json_encode($errors, JSON_THROW_ON_ERROR));
        }
    }

    private function hasProp(?array $entry, mixed $name): bool
    {
        return is_string($name) && preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', $name) === 1
            && ! in_array($name, ['__proto__', 'constructor', 'prototype'], true)
            && array_key_exists($name, $entry['attrsSchema']['properties'] ?? []);
    }

    private function message(string $path, ?string $id, string $what): string
    {
        $where = $id !== null ? "node #$id" : 'node';

        return "$where at [$path] $what";
    }
}
