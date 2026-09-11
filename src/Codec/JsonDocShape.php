<?php

namespace Splicewire\Beam\Ux\Codec;

/**
 * Is a particle body **shaped like a JsonDoc** — the canvas editor's `JsonNode[]` list?
 *
 * The discriminator {@see TsxBodyCodec::decode()} already relies on, lifted to one named place so the
 * codec that prints one and the op that refuses one cannot drift. It reads the shape only; it never
 * asks the entry what format it claims (that is the caller's other half of the question).
 *
 * `[]` is deliberately **not** a JsonDoc. An empty PHP array is indistinguishable from an empty object,
 * and clearing a document to `{}` is a legitimate edit that {@see BeamUxEntryBodyInputData}'s `present`
 * rule exists to allow — treating it as a canvas write would make "empty this mdx page" unperformable.
 */
final class JsonDocShape
{
    /** A non-empty list whose every element is a node object (it carries a `kind`). */
    public static function is(mixed $body): bool
    {
        if (! is_array($body) || $body === [] || ! array_is_list($body)) {
            return false;
        }

        foreach ($body as $node) {
            if (! is_array($node) || ! array_key_exists('kind', $node)) {
                return false;
            }
        }

        return true;
    }
}
