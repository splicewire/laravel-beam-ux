<?php

namespace Splicewire\Beam\Ux\Files;

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;

/** The canonical Theme token subset; this is not a parser for arbitrary host CSS. */
class CanonicalTheme
{
    /** @param array<string, mixed> $published
     * @return array<string, mixed>
     */
    public function decode(string $bytes, array $published): array
    {
        if (! mb_check_encoding($bytes, 'UTF-8') || strlen($bytes) > LocalThemeFile::LIMIT) {
            throw new FileConflict('invalid', 'Theme source must be bounded UTF-8 text.', 422);
        }
        $codec = new CssBodyCodec;
        try {
            $body = $codec->encode($bytes);
            if ($codec->decode($body) !== $bytes) {
                throw new FileConflict('invalid', 'Use the exact generated Theme token format; malformed, duplicate or unrepresented source cannot be imported.', 422);
            }
            if ($body === []) {
                throw new FileConflict('valid-empty-outside-import', 'This is a valid empty Theme representation. Clearing tokens is outside this key-preserving import.', 422);
            }
            $before = $this->leaves($published);
            $after = $this->leaves($body);
            $keys = array_keys($before);
            $newKeys = array_keys($after);
            sort($keys);
            sort($newKeys);
            if ($keys !== $newKeys) {
                throw new FileConflict('invalid', 'Import must preserve every published token and namespace.', 422);
            }
            $schema = ['type' => 'object', 'properties' => [
                'canvas' => ThemeSchemas::canvas(), 'shell' => ThemeSchemas::shell(), 'site' => ThemeSchemas::site(),
            ]];
            foreach ($after as $key => $value) {
                if ($value === $before[$key]) {
                    continue;
                }
                [$namespace, $property] = array_pad(explode('.', $key, 2), 2, '');
                $declared = $schema['properties'][$namespace]['properties'][$property] ?? [];
                if (($declared['type'] ?? null) !== 'string' || ($declared['format'] ?? null) !== 'color') {
                    throw new FileConflict('unsupported', 'Only existing Theme color leaves can change; other leaves must stay unchanged.', 422);
                }
                if (! is_string($value) || ! preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D', $value)) {
                    throw new FileConflict('invalid', 'Changed Theme colors must use a supported hexadecimal color.', 422);
                }
            }
            // Retained extension namespaces are compared above and never stripped. The three owning
            // declared Theme namespaces validate in full, including the shell hidden by the editor.
            if (! app(AcceptanceGate::class)->accepts($body, $schema)) {
                throw new FileConflict('invalid', 'The complete Theme body does not satisfy its declared schema.', 422);
            }

            return $body;
        } catch (FileConflict $failure) {
            throw $failure;
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            throw new FileConflict('invalid', 'The Theme source could not be decoded and validated.', 422);
        }
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function leaves(array $body, string $prefix = ''): array
    {
        $out = [];
        foreach ($body as $key => $value) {
            $segment = str_replace(['~', '.'], ['~0', '~1'], (string) $key);
            $path = $prefix === '' ? $segment : $prefix.'.'.$segment;
            if (is_array($value) && $value !== []) {
                $out += $this->leaves($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
