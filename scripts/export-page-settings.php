<?php

require getenv('BEAM_UX_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
function emitSettingsType(ReflectionClass $class): void
{
    echo 'export interface '.$class->getShortName()." {\n";
    foreach ($class->getConstructor()->getParameters() as $parameter) {
        $type = $parameter->getType();
        if ($type->getName() !== 'string') {
            throw new RuntimeException('Unsupported nested setting');
        }
        echo '  '.$parameter->getName().': string'.($type->allowsNull() ? ' | null' : '').";\n";
    }
    echo "}\n";
}
$class = new ReflectionClass(Splicewire\Beam\Ux\Data\EntryPageSettingsData::class);
echo "// Generated from EntryPageSettingsData by scripts/export-page-settings.php; do not edit.\n";
foreach ($class->getConstructor()->getParameters() as $parameter) {
    $name = $parameter->getType()->getName();
    if (is_subclass_of($name, Splicewire\Beam\Data\BeamData::class)) {
        emitSettingsType(new ReflectionClass($name));
    }
}
echo "export interface EntryPageSettings {\n";
foreach ($class->getConstructor()->getParameters() as $parameter) {
    $type = $parameter->getType();
    $ts = match ($parameter->getName()) {
        'fields' => 'Record<string, string | number | boolean>',
        default => match ($type->getName()) {
            'string' => 'string', 'int' => 'number', 'array' => 'Record<string, string>', default => is_subclass_of($type->getName(), Splicewire\Beam\Data\BeamData::class) ? (new ReflectionClass($type->getName()))->getShortName() : throw new RuntimeException('Unsupported page field type')
        },
    };
    echo '  '.$parameter->getName().($parameter->getAttributes(Spatie\TypeScriptTransformer\Attributes\Optional::class) ? '?' : '').': '.$ts.($type->allowsNull() ? ' | null' : '').";\n";
}
echo "}\n";
