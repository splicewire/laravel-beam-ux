<?php

namespace Splicewire\Beam\Ux\Tests\Unit\Data;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Ux\Data\EntryFrontmatterData;

final class EntryFrontmatterNamingContractTest extends TestCase
{
    public function test_camel_case_properties_preserve_snake_case_wire_keys(): void
    {
        $container = new Application('/tmp/dto-c-beam-ux-test-app');
        Application::setInstance($container);
        $container->instance('config', new Repository([
            'data' => require dirname((new \ReflectionClass(\Spatie\LaravelData\Data::class))->getFileName(), 2).'/config/data.php',
        ]));

        self::assertStringStartsWith(
            '/Volumes/RigScratch/tmp/dto-c-beam-ux-20261101/src/',
            (new \ReflectionClass(EntryFrontmatterData::class))->getFileName(),
        );

        $data = EntryFrontmatterData::from([
            'nav_order' => 7,
            'nav_group' => 'Guides',
            'workflow_marking' => 'draft',
        ]);

        self::assertSame(7, $data->navOrder);
        self::assertSame('Guides', $data->navGroup);
        self::assertSame('draft', $data->workflowMarking);
        self::assertSame([
            'realm' => null,
            'segment' => null,
            'nav_order' => 7,
            'nav_group' => 'Guides',
            'title' => null,
            'layout' => null,
            'template' => null,
            'workflow_marking' => 'draft',
            'leftovers' => [],
        ], $data->toArray());
        self::assertFalse(property_exists($data, 'nav_order'));
        self::assertFalse(property_exists($data, 'nav_group'));
        self::assertFalse(property_exists($data, 'workflow_marking'));
    }
}
