<?php

namespace Splicewire\Beam\Ux\Bindings;

/** A declaration names data, never a JavaScript expression or prototype property. */
final class FieldPath
{
    public static function assert(mixed $path): void
    {
        if (! is_string($path) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*(?:\.[A-Za-z_][A-Za-z0-9_-]*)*\z/', $path)
            || array_intersect(explode('.', $path), ['__proto__', 'prototype', 'constructor'])) {
            throw new \InvalidArgumentException('A binding field path must name declared data properties.');
        }
    }
}
