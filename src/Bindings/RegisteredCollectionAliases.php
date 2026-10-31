<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Support\Str;
use LogicException;

/** Pure naming shared by the author manifest and trusted pack export; neither reads nor grants access. */
final class RegisteredCollectionAliases
{
    /**
     * @param  list<array{stem: string, label: string}>  $addresses
     * @return array<string, string> alias => stem
     */
    public static function fromAddresses(array $addresses): array
    {
        $byName = [];
        foreach ($addresses as $address) {
            $byName[self::baseName($address['stem'])][] = $address['stem'];
        }
        $aliases = [];
        foreach ($addresses as $address) {
            $stem = $address['stem'];
            $name = self::baseName($stem);
            if (count($byName[$name]) > 1) {
                $name .= '-'.substr(sha1($stem), 0, 8);
            }
            if (isset($aliases[$name])) {
                throw new LogicException('Tenant Collection stems must produce unique Manifest source names.');
            }
            $aliases[$name] = $stem;
        }
        ksort($aliases);

        return $aliases;
    }

    private static function baseName(string $stem): string
    {
        $path = parse_url($stem, PHP_URL_PATH);
        $segments = explode('/', trim(is_string($path) ? $path : $stem, '/'));
        $name = Str::slug(end($segments) ?: '') ?: 'collection';

        return preg_match('/^[a-z][a-z0-9-]*$/D', $name) ? $name : 'collection-'.$name;
    }
}
