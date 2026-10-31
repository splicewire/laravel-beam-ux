<?php

namespace Splicewire\Beam\Ux\Tests\Bindings;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Collections\StandaloneRecordQuery;
use Splicewire\Beam\Ux\Bindings\RepeatQueryData;
use Splicewire\Beam\Ux\Bindings\RepeatSelectedRecords;

final class RepeatSelectionTest extends TestCase
{
    public function test_omission_empty_and_bounded_uuid_validation_are_distinct(): void
    {
        self::assertNull((new RepeatQueryData)->ids);
        self::assertSame([], (new RepeatQueryData(ids: []))->ids);
        $id = '019a3456-7890-7000-8000-000000000001';
        self::assertSame([$id], (new RepeatQueryData(ids: [$id]))->ids);
        foreach ([['invalid'], [17], ['key' => $id], array_fill(0, 101, $id)] as $invalid) {
            try {
                new RepeatQueryData(ids: $invalid);
                self::fail('Malformed or unbounded selection accepted.');
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString('100 record UUIDs', $refusal->getMessage());
            }
        }
    }

    public function test_saved_selection_intersects_the_collection_and_retains_its_existing_order(): void
    {
        $database = new Manager;
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $database->schema()->create('selection_records', static function ($table): void {
            $table->string('id')->primary();
            $table->string('collection');
            $table->integer('rank');
        });
        $database->table('selection_records')->insert([
            ['id' => 'a', 'collection' => 'selected', 'rank' => 2],
            ['id' => 'b', 'collection' => 'selected', 'rank' => 1],
            ['id' => 'foreign', 'collection' => 'other', 'rank' => 0],
        ]);
        $model = new class extends Model
        {
            protected $table = 'selection_records';

            protected $keyType = 'string';
        };
        $base = $this->createStub(StandaloneRecordQuery::class);
        $base->method('query')->willReturnCallback(static fn () => $model->newQuery()->where('collection', 'selected')->orderBy('rank'));
        try {
            self::assertSame(['b', 'a'], (new RepeatSelectedRecords($base, ['a', 'foreign', 'b']))->query()->pluck('id')->all());
            self::assertSame([], (new RepeatSelectedRecords($base, []))->query()->pluck('id')->all());
            self::assertSame(['b', 'a'], $base->query()->pluck('id')->all(), 'The saved subset does not mutate the base query.');
        } finally {
            $database->getDatabaseManager()->disconnect();
        }
    }
}
