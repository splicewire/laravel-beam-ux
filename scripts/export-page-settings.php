<?php

require getenv('BEAM_UX_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
$class = new ReflectionClass(Splicewire\Beam\Ux\Data\EntryPageSettingsData::class);
echo "// Generated from EntryPageSettingsData by scripts/export-page-settings.php; do not edit.\nexport interface EntryPageSettings {\n";
foreach ($class->getConstructor()->getParameters() as $parameter) {
    $type = $parameter->getType();
    $ts = match ($type->getName()) {
        'string' => 'string', 'int' => 'number', default => throw new RuntimeException('Unsupported page field type')
    };
    echo '  '.$parameter->getName().': '.$ts.($type->allowsNull() ? ' | null' : '').";\n";
}
echo "}\n";
