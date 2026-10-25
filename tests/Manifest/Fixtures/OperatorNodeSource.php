<?php

namespace Splicewire\Beam\Ux\Tests\Manifest\Fixtures;

/** The operator realm's host components (EP-07 realm-bind tests). */
class OperatorNodeSource extends FixtureNodeSource
{
    public function __construct()
    {
        parent::__construct([BannerBlock::class]);
    }
}
