<?php

require getenv('BEAM_UX_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';

echo json_encode(Splicewire\Beam\Ux\Style\StyleRegistry::fromTheme([]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
