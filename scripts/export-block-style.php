<?php

require getenv('BEAM_UX_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';

// The declared Data constructors own the wire fields; the browser mirror is generated, not a second model.
echo "// Generated from laravel-beam-ux Style Data. Do not edit.\n";
foreach ([Splicewire\Beam\Ux\Style\StyleLayerData::class, Splicewire\Beam\Ux\Style\BlockStyleData::class] as $class) {
    $reflection = new ReflectionClass($class);
    echo 'export interface '.$reflection->getShortName()." {\n";
    foreach ($reflection->getConstructor()->getParameters() as $parameter) {
        $type = $parameter->getType();
        $property = $reflection->getProperty($parameter->getName());
        $collection = $property->getAttributes(Spatie\LaravelData\Attributes\DataCollectionOf::class);
        $ts = $type->getName() === 'array'
            ? ($collection ? (new ReflectionClass($collection[0]->getArguments()[0]))->getShortName().'[]' : 'Record<string, string>')
            : $type->getName();
        echo '    '.$parameter->getName().($parameter->isDefaultValueAvailable() ? '?' : '').': '.$ts.($type->allowsNull() ? ' | null' : '').";\n";
    }
    echo "}\n";
}
