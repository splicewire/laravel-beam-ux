<?php

namespace Splicewire\Beam\Ux\Style;

use InvalidArgumentException;

/** Literal CSS only: no selectors, at-rules or HTML can be smuggled through a declaration. */
final class BlockStyleCompiler
{
    public static function compile(array $style, array $registry): array
    {
        if (array_diff(array_keys($style), ['layers']) || ! is_array($style['layers'] ?? null) || ! array_is_list($style['layers'])) {
            throw new InvalidArgumentException('Block Style requires a layers list');
        }
        $rules = [];
        foreach ($style['layers'] as $layer) {
            if (! is_array($layer) || array_diff(array_keys($layer), ['breakpoint', 'state', 'declarations'])) {
                throw new InvalidArgumentException('Invalid Block Style layer');
            }
            $breakpoint = $layer['breakpoint'] ?? null;
            $state = $layer['state'] ?? null;
            if (($breakpoint !== null && (! is_string($breakpoint) || ! isset($registry['breakpoints'][$breakpoint])))
                || ($state !== null && (! is_string($state) || ! isset($registry['states'][$state])))) {
                throw new InvalidArgumentException('Unknown Block Style breakpoint or state');
            }
            $declarations = $layer['declarations'] ?? null;
            if (! is_array($declarations)) {
                throw new InvalidArgumentException('Block Style declarations must be a property map');
            }
            ksort($declarations);
            $css = [];
            foreach ($declarations as $property => $value) {
                if (! is_string($property) || ! preg_match('/^(?:--[a-zA-Z0-9-]+|[a-z][a-z0-9-]*)$/D', $property)
                    || ! is_string($value) || preg_match('/[{}<>;\\\\]|\/\*|@|expression\s*\(/i', $value)) {
                    throw new InvalidArgumentException('Invalid Block Style declaration');
                }
                $css[] = $property.':'.$value;
            }
            $rules[] = ['width' => $breakpoint === null ? null : $registry['breakpoints'][$breakpoint], 'selector' => $state === null ? '' : $registry['states'][$state], 'css' => implode(';', $css)];
        }
        $className = 'bs-'.hash('fnv1a64', json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $css = '';
        foreach ($rules as $rule) {
            $text = '.'.$className.$rule['selector'].'{'.$rule['css'].'}';
            $css .= $rule['width'] === null ? $text : '@media (min-width: '.$rule['width'].'){'.$text.'}';
        }

        return ['className' => $className, 'css' => $css];
    }
}
