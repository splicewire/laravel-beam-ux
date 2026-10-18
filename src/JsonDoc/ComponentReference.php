<?php

namespace Splicewire\Beam\Ux\JsonDoc;

/** Identifier syntax only. ProfileLegality separately requires a declared enclosing interface. */
final class ComponentReference
{
    public static function assert(mixed $name): void
    {
        if (! is_string($name) || preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', $name) !== 1
            || in_array($name, ['__proto__', 'prototype', 'constructor', 'this', 'super', 'null', 'true', 'false'], true)) {
            throw new \InvalidArgumentException('A component reference must be a declared identifier.');
        }
    }
}
