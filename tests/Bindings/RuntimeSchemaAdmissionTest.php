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

    public function test_serialized_confirmation_is_explicit_and_an_unsupported_registry_never_writes(): void
    {
        $receipt = new \Splicewire\Beam\Schema\SchemaRegistrationConfirmationData(SchemaFingerprint::of($this->schema(), []), ['page-a' => 'pin-1']);
        $job = unserialize(serialize(new \Splicewire\Tower\Jobs\FreezeSchemaVersionJob($this->schema(), $receipt)));
        self::assertSame($receipt->publishedVersions, $job->registrationConfirmation->publishedVersions);
        $registry = Mockery::mock(\Schemastud\DataSchemas\Contracts\SchemaRegistry::class);
        $registry->shouldNotReceive('register');
        $this->expectExceptionMessage('does not support explicit impact confirmation');
        $job->handle($registry);
    }

    public function test_confirmed_native_registration_delivers_the_serialized_receipt_to_preflight(): void
    {
        $receipt = new \Splicewire\Beam\Schema\SchemaRegistrationConfirmationData(SchemaFingerprint::of($this->schema(), []), ['page-a' => 'pin-1']);
        $job = unserialize(serialize(new \Splicewire\Tower\Jobs\FreezeSchemaVersionJob($this->schema(), $receipt)));
        app('events')->listen(SchemaVersionRegistering::class, function ($event) use ($receipt) {
            self::assertSame($receipt->publishedVersions, $event->confirmation->publishedVersions);
            throw new \RuntimeException('Receipt does not bypass preflight');
        });
        $this->expectExceptionMessage('Receipt does not bypass preflight');
        $job->handle($this->registry(null, false));
    }

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
