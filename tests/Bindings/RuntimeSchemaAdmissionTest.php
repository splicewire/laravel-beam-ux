<?php

namespace Splicewire\Beam\Ux\Tests\Bindings;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\SchemaVersionRegistering;

/** Real register()/event/transaction control flow; a strict query spy cannot open a database. */
final class RuntimeSchemaAdmissionTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        Container::setInstance(new \Illuminate\Foundation\Application(dirname(__DIR__, 2)));
        $dataConfig = require dirname((new \ReflectionClass(\Spatie\LaravelData\Data::class))->getFileName(), 2).'/config/data.php';
        app()->instance('config', new \Illuminate\Config\Repository(['data' => $dataConfig]));
        app()->instance('events', new Dispatcher(app()));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Container::setInstance($this->previous);
    }

    private function registry(?object $existing, bool $writes): DatabaseSchemaRegistry
    {
        $query = Mockery::mock(Builder::class);
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('transaction')->once()->with(Mockery::type(\Closure::class), 3)->andReturnUsing(fn ($run) => $run());
        $query->shouldReceive('getConnection')->once()->andReturn($connection);
        $query->shouldReceive('where', 'orderBy', 'lockForUpdate')->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn($existing);
        if ($existing === null) {
            $query->shouldReceive('get')->once()->andReturn(new \Illuminate\Database\Eloquent\Collection([]));
        }
        if ($writes) {
            $query->shouldReceive('create')->once()->with(Mockery::on(fn ($row) => $row['artifact'] === $this->schema()))->andReturn(Mockery::mock(\Splicewire\Beam\Models\BeamSchema::class));
        } else {
            $query->shouldNotReceive('create');
        }

        return new class($query) extends DatabaseSchemaRegistry
        {
            public function __construct(private Builder $builder)
            {
                parent::__construct('beam_schemas', 'tenant');
            }

            protected function query(): Builder
            {
                return $this->builder;
            }
        };
    }

    private function schema(): array
    {
        return ['$id' => 'https://tenant.test/schemas/articles/2', 'type' => 'object', 'properties' => ['title' => ['type' => 'string']]];
    }

    public function test_normal_registration_cannot_persist_when_a_pre_freeze_listener_refuses(): void
    {
        app('events')->listen(SchemaVersionRegistering::class, function ($event) {
            self::assertSame('tenant', $event->connection);
            self::assertSame($this->schema(), $event->schema);
            throw new \RuntimeException('controlled pre-freeze refusal');
        });
        $this->expectExceptionMessage('controlled pre-freeze refusal');
        $this->registry(null, false)->register($this->schema());
    }

    public function test_idempotent_existing_artifact_does_not_emit_review_or_write(): void
    {
        app('events')->listen(SchemaVersionRegistering::class, fn () => self::fail('Existing artifact must not re-enter preflight.'));
        $this->registry((object) ['fingerprint' => SchemaFingerprint::of($this->schema())], false)->register($this->schema());
        $this->addToAssertionCount(1);
    }

    public function test_frozen_conflict_stays_a_conflict_before_any_impact_confirmation(): void
    {
        app('events')->listen(SchemaVersionRegistering::class, fn () => self::fail('Conflicting artifact must not enter preflight.'));
        $this->expectException(SchemaRegistryConflict::class);
        $this->registry((object) ['fingerprint' => str_repeat('a', 64)], false)->register($this->schema());
    }

    public function test_normal_registration_without_impacts_keeps_the_existing_write_path(): void
    {
        $seen = false;
        app('events')->listen(SchemaVersionRegistering::class, function ($event) use (&$seen) {
            $seen = true;
            self::assertNull($event->confirmation);
        });
        $this->registry(null, true)->register($this->schema());
        self::assertTrue($seen);
    }

    // The serialized-receipt tests for tower's FreezeSchemaVersionJob were here (c438895). beam-ux may never require tower
    // (composer.json), so they could not run in this package; they are tower's (config-sections 25 hand-off).

    public function test_wire_schema_declares_confirmation_map_and_typed_impact_pages(): void
    {
        $generator = new \Schemastud\DataSchemas\Generators\JsonSchemaGenerator(['base_uri' => false]);
        $confirmation = $generator->generate(new \ReflectionClass(\Splicewire\Beam\Schema\SchemaRegistrationConfirmationData::class));
        self::assertSame('object', $confirmation['properties']['publishedVersions']['type']);
        self::assertSame('string', $confirmation['properties']['publishedVersions']['additionalProperties']['type']);
        $impact = $generator->generate(new \ReflectionClass(\Splicewire\Beam\Ux\Data\SchemaVersionImpactData::class));
        self::assertSame('array', $impact['properties']['pages']['type']);
        self::assertArrayHasKey('$ref', $impact['properties']['pages']['items']);
        $ref = substr($impact['properties']['pages']['items']['$ref'], strlen('#/$defs/'));
        self::assertSame('string', $impact['$defs'][$ref]['properties']['fields']['items']['type']);
        self::assertSame('object', $impact['$defs'][$ref]['properties']['dependencies']['type']);
    }
}
