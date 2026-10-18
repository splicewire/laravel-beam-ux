<?php

namespace Splicewire\Beam\Ux\Style;

use InvalidArgumentException;

/** ADR-0003: one mobile-first vocabulary for Block Style and responsive theme tokens. */
final class StyleRegistry
{
    public static function fromTheme(array $theme): array
    {
        if (isset($theme['responsive']) && ! is_array($theme['responsive'])) {
            throw new InvalidArgumentException('responsive must be a configuration object');
        }
        $breakpoints = ['sm' => '40rem', 'md' => '48rem', 'lg' => '64rem', 'xl' => '80rem', '2xl' => '96rem'];
        $overrides = $theme['responsive']['breakpoints'] ?? [];
        if (! is_array($overrides)) {
            throw new InvalidArgumentException('responsive.breakpoints must be a name-to-width map');
        }
        foreach ($overrides as $name => $width) {
            if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*$/D', $name)
                || ! is_string($width) || ! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:px|rem|em)$/D', $width)) {
                throw new InvalidArgumentException('Breakpoints require a name and a literal non-negative CSS length');
            }
            $breakpoints[$name] = $width;
        }

        return ['breakpoints' => $breakpoints, 'states' => ['hover' => ':hover', 'focus-visible' => ':focus-visible', 'active' => ':active']];
    }
}
