<?php

namespace Splicewire\Beam\Ux\Style;

use Splicewire\Beam\Ux\Codec\JsonDocShape;

/** On the next write, legacy string attributes become a base layer; expression props are opaque. */
final class NormalizeBlockStyles
{
    public static function apply(array $body, array $registry): array
    {
        if (! JsonDocShape::is($body)) {
            return $body;
        }
        foreach ($body as &$node) {
            if (($node['kind'] ?? null) !== 'block') {
                continue;
            }
            $props = [];
            $base = [];
            foreach ($node['props'] ?? [] as $prop) {
                if (($prop['name'] ?? null) !== 'style' || ($prop['kind'] ?? null) !== 'string') {
                    $props[] = $prop;

                    continue;
                }
                foreach (explode(';', (string) ($prop['value'] ?? '')) as $declaration) {
                    $at = strpos($declaration, ':');
                    if ($at !== false) {
                        $base[trim(substr($declaration, 0, $at))] = trim(substr($declaration, $at + 1));
                    }
                }
            }
            $node['props'] = $props;
            if ($base !== []) {
                $node['style'] = ['layers' => [['declarations' => $base], ...($node['style']['layers'] ?? [])]];
            }
            if (isset($node['style'])) {
                if (($node['name'] ?? null) === null) {
                    throw new \InvalidArgumentException('A fragment cannot carry a Block Style');
                }
                BlockStyleCompiler::compile($node['style'], $registry);
            }
            $node['children'] = self::apply($node['children'] ?? [], $registry);
            foreach ($node['slots'] ?? [] as $name => $content) {
                $node['slots'][$name] = self::apply($content, $registry);
            }
        }

        return $body;
    }
}
