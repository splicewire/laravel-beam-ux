<?php

namespace Splicewire\Beam\Ux\Inference;

use Splicewire\Beam\Ux\Type\UxType;

/**
 * The **deterministic prop→draft-schema inference engine** (paid `splicewire/*`, charter S9,
 * `beamux-build/issues/06`). Given a `.tsx` component's source it parses the props declaration into a
 * JSON-Schema object body so a freshly-registered `component`/`node` is **editable immediately** — the
 * author does not have to hand-write a schema before the record has an editing surface.
 *
 * **The mapping (deterministic, byte-stable):**
 *  - prop name → object `properties` key (declaration order preserved).
 *  - TS scalar type → JSON `type` (`string`/`number`/`boolean`; arrays → `array`, everything else → `object`).
 *  - a string/number literal-union (`'a' | 'b'`) → `enum` (with the JSON `type` of the members).
 *  - `?` optional marker → the property is dropped from `required` (never fabricated as required).
 *  - a destructure default (`{ size = 'md' }`) → `default` (and the prop counts as optional).
 *  - a JSDoc block on the prop → `title` (first `@title`/first sentence) + `description` (the block).
 *
 * **DRAFT, never final (the load-bearing rule).** Inference produces the schema BODY only; whether it is
 * a draft is a separate flag the caller (see {@see InferDraftSchema}) SETS on the record. Widgets,
 * validation keywords, and `$ref`s are **never** fabricated here — they stay explicit authoring acts the
 * author declares when graduating the draft. The inferred schema is the honest floor, not a finished spec.
 *
 * **Parser pick is deferred (issue-06):** whether the eventual production parser is
 * `react-docgen-typescript` or the TS compiler API is an implementation detail left open. This is a
 * deterministic PHP-side AST-lite parser (regex over the props interface/type-alias + destructure
 * defaults + JSDoc) — sufficient for the mapping and, crucially, STABLE: the same source yields the same
 * schema byte-for-byte across runs (properties in declaration order, no hashing/timestamps). That
 * stability is the commitment; the parser vendor can change under it without moving the contract.
 *
 * **Exclusion is asserted, not incidental.** `page`/`layout`/`template` schemas come from the
 * composition model (slots/regions), not props — {@see infersFor()} returns false for them and
 * {@see inferForType()} throws rather than silently emitting a props-shaped schema for a `page`.
 */
class TsxPropInference
{
    /**
     * The JSON-Schema `$schema` dialect the draft targets — the same 2020-12 dialect the schemastud
     * `JsonSchemaGenerator` emits, so a graduated draft is the same shape as an authored schema.
     */
    public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    /** Only these structural types get prop inference; the rest come from the composition model. */
    private const INFERABLE = [UxType::Component];

    /**
     * Does this structural type get prop→schema inference? Only `component` (which subsumes the old
     * "node" — a `component` in `body_style: inline`). `page`/`layout`/`template` are EXCLUDED: their
     * schemas are the composition model's (slots/regions), not their props.
     */
    public function infersFor(UxType|string $type): bool
    {
        $type = $type instanceof UxType ? $type : UxType::from((string) $type);

        return in_array($type, self::INFERABLE, true);
    }

    /**
     * Infer the draft schema BODY for a component's `.tsx` source, asserting the type is inferable.
     * Throws for `page`/`layout`/`template` — inference must never fabricate a props-shaped schema for a
     * type whose schema is the composition model's.
     *
     * @return array<string, mixed> the JSON-Schema object (no draft flag — the caller sets that)
     */
    public function inferForType(UxType|string $type, string $source): array
    {
        $type = $type instanceof UxType ? $type : UxType::from((string) $type);

        if (! $this->infersFor($type)) {
            throw new \InvalidArgumentException(
                "Prop inference is excluded for `{$type->value}`: its schema comes from the composition ".
                'model (slots/regions), not props. Only `component` is inferred.'
            );
        }

        return $this->infer($source);
    }

    /**
     * Parse a component's `.tsx` source into a JSON-Schema object. Deterministic and pure: no clock, no
     * randomness, no hashing — the same source always yields the same array in the same key order.
     *
     * @return array<string, mixed>
     */
    public function infer(string $source): array
    {
        $props = $this->extractPropMembers($source);
        $defaults = $this->extractDestructureDefaults($source);

        $properties = [];
        $required = [];

        foreach ($props as $member) {
            $name = $member['name'];
            $schema = [];

            // JSDoc → title + description (title first so it reads before the description in the body).
            if ($member['title'] !== null) {
                $schema['title'] = $member['title'];
            }
            if ($member['description'] !== null) {
                $schema['description'] = $member['description'];
            }

            // TS type → JSON type / literal-union → enum.
            $schema = $schema + $this->mapType($member['tsType']);

            // A destructure default → `default` (and the prop is optional even without a `?`).
            $hasDefault = array_key_exists($name, $defaults);
            if ($hasDefault) {
                $schema['default'] = $defaults[$name];
            }

            $properties[$name] = $schema;

            // `?` OR a destructure default makes it optional; otherwise it is required. Inference never
            // invents required-ness beyond what the props declaration states.
            if (! $member['optional'] && ! $hasDefault) {
                $required[] = $name;
            }
        }

        $schema = [
            '$schema' => self::DIALECT,
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Extract the ordered prop members from the props interface/type-alias declaration. Recognizes a
     * `interface FooProps { … }` or `type FooProps = { … }` block (the conventional React props shape)
     * and each member's name, `?` optionality, TS type text, and any preceding JSDoc block.
     *
     * @return array<int, array{name: string, optional: bool, tsType: string, title: ?string, description: ?string}>
     */
    private function extractPropMembers(string $source): array
    {
        $body = $this->extractPropsBlock($source);
        if ($body === null) {
            return [];
        }

        $members = [];
        $offset = 0;
        $length = strlen($body);

        // Walk member-by-member: an optional leading JSDoc, then `name?: type;` up to the terminator.
        // Terminators are `;`, `,`, or newline — whichever closes the member first, at brace depth 0
        // (so an inline object/union spanning `{…}` or `<…>` is not split mid-type).
        while ($offset < $length) {
            // Capture a JSDoc block immediately preceding this member (skipping only whitespace).
            $jsdoc = null;
            if (preg_match('#\G\s*(/\*\*.*?\*/)#s', $body, $m, 0, $offset)) {
                $jsdoc = $m[1];
                $offset += strlen($m[0]);
            }

            // Skip whitespace to the member name.
            if (preg_match('#\G\s+#s', $body, $m, 0, $offset)) {
                $offset += strlen($m[0]);
            }

            if ($offset >= $length) {
                break;
            }

            // `name` `?`? `:` — the member head. Anything not matching (a stray `}` etc.) ends parsing.
            if (! preg_match('#\G([A-Za-z_$][\w$]*)\s*(\??)\s*:#s', $body, $head, 0, $offset)) {
                // Advance past the offending character to stay total (never loop forever).
                $offset++;

                continue;
            }

            $name = $head[1];
            $optional = $head[2] === '?';
            $offset += strlen($head[0]);

            // The type text runs to the member terminator at brace/angle depth 0.
            $type = $this->readTypeText($body, $offset);

            [$title, $description] = $this->parseJsDoc($jsdoc);

            $members[] = [
                'name' => $name,
                'optional' => $optional,
                'tsType' => trim($type),
                'title' => $title,
                'description' => $description,
            ];
        }

        return $members;
    }

    /**
     * The inner text of the props declaration block, or null when the source declares no props shape.
     * Matches `interface XxxProps {` or `type XxxProps = {`, then returns the balanced `{…}` body.
     */
    private function extractPropsBlock(string $source): ?string
    {
        if (! preg_match('#(?:interface\s+[\w$]*Props[\w$<>,\s]*|type\s+[\w$]*Props[\w$<>,\s]*=\s*)\{#s', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // The `{` is the last char of the match; read its balanced partner.
        $open = $m[0][1] + strlen($m[0][0]) - 1;

        return $this->readBalanced($source, $open, '{', '}');
    }

    /** The inner text between a balanced open/close pair starting at $openPos (which holds $open). */
    private function readBalanced(string $s, int $openPos, string $open, string $close): ?string
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = $openPos; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === $open) {
                $depth++;
            } elseif ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $openPos + 1, $i - $openPos - 1);
                }
            }
        }

        return null;
    }

    /** Read a member's type text from $offset (advanced past it) to its depth-0 terminator. */
    private function readTypeText(string $body, int &$offset): string
    {
        $depth = 0;
        $start = $offset;
        $length = strlen($body);

        for (; $offset < $length; $offset++) {
            $c = $body[$offset];
            if ($c === '{' || $c === '<' || $c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === '}' || $c === '>' || $c === ')' || $c === ']') {
                if ($depth === 0) {
                    break; // a closing brace of the props block itself
                }
                $depth--;
            } elseif ($depth === 0 && ($c === ';' || $c === ',' || $c === "\n")) {
                break;
            }
        }

        $type = substr($body, $start, $offset - $start);
        // Consume the terminator so the next member starts clean.
        if ($offset < $length && ($body[$offset] === ';' || $body[$offset] === ',' || $body[$offset] === "\n")) {
            $offset++;
        }

        return $type;
    }

    /**
     * Map a TS type expression to its JSON-Schema fragment. A pure-literal union becomes an `enum`; a
     * scalar maps to its JSON `type`; an array type maps to `array`; anything unrecognized degrades to
     * `object` (never crashes — degrade, don't fabricate).
     *
     * @return array<string, mixed>
     */
    private function mapType(string $ts): array
    {
        $ts = trim($ts);

        // Literal-union → enum. Every member must be a string/number/boolean literal.
        if (str_contains($ts, '|')) {
            $enum = $this->parseLiteralUnion($ts);
            if ($enum !== null) {
                return ['type' => $enum['type'], 'enum' => $enum['values']];
            }
        }

        // Array types: `T[]` or `Array<T>`.
        if (preg_match('#\[\s*\]$#', $ts) || preg_match('#^Array\s*<#', $ts)) {
            return ['type' => 'array'];
        }

        return match ($this->baseScalar($ts)) {
            'string' => ['type' => 'string'],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            default => ['type' => 'object'],
        };
    }

    /** The base scalar keyword of a (possibly `| undefined`/`| null`-stripped) TS type, else null-ish. */
    private function baseScalar(string $ts): string
    {
        // Strip a trailing `| undefined` / `| null` so `string | undefined` reads as `string`.
        $parts = array_map('trim', explode('|', $ts));
        $parts = array_filter($parts, fn ($p) => $p !== 'undefined' && $p !== 'null' && $p !== '');
        if (count($parts) === 1) {
            return reset($parts);
        }

        return $ts;
    }

    /**
     * Parse a pure literal union into an enum spec, or null when any member is not a literal (so a
     * union of complex types is NOT mis-read as an enum). Determines the JSON `type` from the members.
     *
     * @return array{type: string, values: array<int, mixed>}|null
     */
    private function parseLiteralUnion(string $ts): ?array
    {
        $rawMembers = array_map('trim', explode('|', $ts));
        $values = [];
        $types = [];

        foreach ($rawMembers as $member) {
            if ($member === '' || $member === 'undefined' || $member === 'null') {
                continue; // a `| undefined` tail does not disqualify the enum; it just marks optional
            }

            // String literal: '…' or "…".
            if (preg_match('#^([\'"])(.*)\1$#s', $member, $m)) {
                $values[] = $m[2];
                $types[] = 'string';

                continue;
            }

            // Numeric literal.
            if (preg_match('#^-?\d+(\.\d+)?$#', $member)) {
                $values[] = $member[1] === '.' || str_contains($member, '.') ? (float) $member : (int) $member;
                $types[] = str_contains($member, '.') ? 'number' : 'integer';

                continue;
            }

            // Boolean literal.
            if ($member === 'true' || $member === 'false') {
                $values[] = $member === 'true';
                $types[] = 'boolean';

                continue;
            }

            // A non-literal member — this is not an enum.
            return null;
        }

        if ($values === []) {
            return null;
        }

        // Collapse the member types to a single JSON type (string wins if mixed; integer promotes to number).
        $unique = array_values(array_unique($types));
        $type = count($unique) === 1
            ? ($unique[0] === 'integer' ? 'integer' : $unique[0])
            : (in_array('string', $unique, true) ? 'string' : 'number');

        return ['type' => $type, 'values' => $values];
    }

    /**
     * Extract destructure defaults from the component signature — `({ size = 'md', count = 3 }) =>` or a
     * `function C({ size = 'md' })` form. Only literal defaults are captured (a computed default is not a
     * JSON-Schema `default`). Keyed by prop name.
     *
     * @return array<string, mixed>
     */
    private function extractDestructureDefaults(string $source): array
    {
        // Find a destructuring param block `{ … }` that contains at least one `name = literal`.
        // Restrict to the FIRST such block (the props param) to stay deterministic.
        if (! preg_match('#\(\s*\{(.*?)\}\s*(?::|\)|=>)#s', $source, $m)) {
            return [];
        }

        $inner = $m[1];
        $defaults = [];

        // name = 'literal' | "literal" | number | true | false
        if (preg_match_all('#([A-Za-z_$][\w$]*)\s*=\s*(\'[^\']*\'|"[^"]*"|-?\d+(?:\.\d+)?|true|false)#', $inner, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $pair) {
                $defaults[$pair[1]] = $this->literalValue($pair[2]);
            }
        }

        return $defaults;
    }

    /** Coerce a matched literal token to its PHP value (string without quotes, number, bool). */
    private function literalValue(string $token): mixed
    {
        if (preg_match('#^([\'"])(.*)\1$#s', $token, $m)) {
            return $m[2];
        }
        if ($token === 'true') {
            return true;
        }
        if ($token === 'false') {
            return false;
        }
        if (str_contains($token, '.')) {
            return (float) $token;
        }

        return (int) $token;
    }

    /**
     * Parse a JSDoc block into [title, description]. The title is an explicit `@title x` tag if present,
     * otherwise the first sentence of the free text; the description is the full cleaned free text.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseJsDoc(?string $jsdoc): array
    {
        if ($jsdoc === null) {
            return [null, null];
        }

        // Strip the /** */ frame and per-line ` * ` gutters.
        $inner = preg_replace('#^/\*\*|\*/$#', '', $jsdoc) ?? '';
        $lines = preg_split('#\r?\n#', $inner) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = preg_replace('#^\s*\*\s?#', '', $line) ?? $line;
            $clean[] = rtrim($line);
        }
        $text = trim(implode("\n", $clean));

        $title = null;
        if (preg_match('#@title\s+(.+)#', $text, $m)) {
            $title = trim($m[1]);
        }

        // The description is the free text minus tag lines.
        $descLines = array_filter(
            preg_split('#\r?\n#', $text) ?: [],
            fn ($l) => ! preg_match('#^\s*@\w+#', $l),
        );
        $description = trim(implode(' ', array_map('trim', $descLines)));
        $description = preg_replace('#\s+#', ' ', $description) ?: null;
        $description = $description === '' ? null : $description;

        // Absent an explicit @title, the title is the first sentence of the description.
        if ($title === null && $description !== null) {
            if (preg_match('#^(.*?[.!?])(\s|$)#', $description, $m)) {
                $title = trim($m[1]);
            } else {
                $title = $description;
            }
        }

        return [$title, $description];
    }
}
