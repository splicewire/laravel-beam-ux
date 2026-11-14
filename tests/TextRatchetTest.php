<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Testing\AssertsTextRatchet;

/**
 * localization 12's PHP ratchet for laravel-beam-ux (committed by sweep 23): the user-facing literals in this package's
 * response-reaching calls, per file, against `lang-ratchet.json`. The count may only fall: key new text instead.
 * Re-write the baseline with `php artisan beam:lang:ratchet --write vendor/splicewire/laravel-beam-ux`.
 */
class TextRatchetTest extends TestCase
{
    use AssertsTextRatchet;

    public function test_beam_uxs_own_literals_never_rise(): void
    {
        $this->assertTextRatchet(dirname(__DIR__));
    }
}
