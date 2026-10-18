<?php

namespace Splicewire\Beam\Ux\Manifest;

/** Shared conservative schema containment for field and component-prop references. */
final class SchemaCompatibility
{
    /** Conservative schema containment: never infer compatibility from one sample Record. */
    public static function fits(array $source, array $target): bool
    {
        if (isset($source['enum']) || array_key_exists('const', $source)) {
            $values = $source['enum'] ?? [$source['const']];
            $validator = new \Opis\JsonSchema\Validator;
            $schema = json_decode(json_encode((object) $target, JSON_THROW_ON_ERROR));
            foreach ($values as $value) {
                if (! $validator->validate(json_decode(json_encode($value, JSON_THROW_ON_ERROR)), $schema)->isValid()) {
                    return false;
                }
            }

            return true;
        }
        if (isset($target['type'])) {
            $types = (array) ($source['type'] ?? []);
            $allowed = (array) $target['type'];
            if ($types === []) {
                return false;
            }
            foreach ($types as $type) {
                if (! in_array($type, $allowed, true) && ! ($type === 'integer' && in_array('number', $allowed, true))) {
                    return false;
                }
            }
        }
        // An arbitrary source string is not a member of a target enum. Other constraints must
        // be carried by the source verbatim until a dedicated containment rule supports them.
        foreach ($target as $key => $value) {
            if (in_array($key, ['type', 'title', 'description', '$comment', 'default', 'examples', '$schema', '$id'], true)) {
                continue;
            }
            if (! array_key_exists($key, $source) || $source[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
