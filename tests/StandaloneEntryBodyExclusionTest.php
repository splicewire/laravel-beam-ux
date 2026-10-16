<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Collections\StandaloneRecordQuery;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class StandaloneEntryBodyExclusionTest extends TestCase
{
    public function test_even_a_marked_schema_does_not_make_an_entry_body_a_standalone_record(): void
    {
        Schema::create(Beam::table('particles'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_ref');
            $table->string('source_tier');
        });
        Schema::create((new BeamUxEntry)->getTable(), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable();
            $table->softDeletes();
        });
        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name');
            $table->integer('version');
            $table->string('fingerprint');
            $table->json('artifact');
            $table->timestamps();
        });
        $registry = new \Splicewire\Beam\Schema\DatabaseSchemaRegistry;
        $registry->register([
            '$id' => 'https://example.test/schemas/article/1', 'type' => 'object',
            'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'title'],
            'properties' => ['title' => ['type' => 'string']],
        ]);
        $this->assertSame('Articles', $registry->get('https://example.test/schemas/article/1')['x-beam-collection']['label']);
        $standalone = (string) Str::uuid7();
        $body = (string) Str::uuid7();
        $trashedBody = (string) Str::uuid7();
        $shadow = (string) Str::uuid7();
        foreach ([$standalone, $body, $trashedBody, $shadow] as $id) {
            DB::table(Beam::table('particles'))->insert([
                'id' => $id, 'schema_ref' => 'https://example.test/schemas/article/1',
                'source_tier' => $id === $shadow ? 'shadow-cached' : 'local',
            ]);
        }
        DB::table((new BeamUxEntry)->getTable())->insert([
            ['id' => (string) Str::uuid7(), 'particle_id' => $body, 'deleted_at' => null],
            ['id' => (string) Str::uuid7(), 'particle_id' => $trashedBody, 'deleted_at' => '2026-10-16 00:00:00'],
        ]);
        // The classification is ownership, not schema shape: both body rows share the same
        // Collection schema ref as the standalone row, including a soft-deleted owner's body.
        $this->assertSame([$standalone], app(StandaloneRecordQuery::class)->query()->pluck('id')->all());
    }
}
