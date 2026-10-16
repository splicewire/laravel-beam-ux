<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The baseline runs INSIDE `migrate` (launch ticket 00, orch-lead 2026-10-16 16:57Z): every entry that
 * already has a body gets its Publication Pin from the same migrate that makes the pin required, so
 * there is no window in which the pin is required but missing. Idempotent; a fresh database no-ops.
 */
class PublicationPinBaselineMigrationTest extends TestCase
{
    private const BODY = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'Live']],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);
        $this->app->singleton(EntryBodyCompiler::class, fn () => new BaselineFakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    private function migration(): object
    {
        return require __DIR__.'/../database/migrations/shared/baseline_beam_ux_entry_publication_pins.php.stub';
    }

    /** An entry written the way every pre-pin entry was: a body, no pin, no history. */
    private function pinlessEntry(): BeamUxEntry
    {
        $entry = BeamUxEntry::create([
            'slug' => 'page-'.substr(bin2hex(random_bytes(4)), 0, 8),
            'type' => UxType::Page->value,
            'format' => 'tsx',
            'realm' => 'site',
        ]);
        app(EntryPublication::class)->write($entry, self::BODY);

        return $entry->fresh();
    }

    private function versionRows(): int
    {
        return (int) \DB::table(Beam::table('versions'))->count();
    }

    public function test_every_pinless_entry_with_a_body_is_pinned_to_a_baseline_of_its_live_body(): void
    {
        $this->createTables();
        $a = $this->pinlessEntry();
        $b = $this->pinlessEntry();
        $empty = BeamUxEntry::create(['slug' => 'never-written', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);

        $this->migration()->up();

        foreach ([$a, $b] as $entry) {
            $pin = $entry->fresh()->getAttribute('published_version');
            $this->assertNotNull($pin, 'a written entry was left pin-less by the baseline migration');
            $state = app(EntryPublication::class)->state($entry->fresh());
            $this->assertSame((string) $pin, $state->publishedVersion);
            $this->assertFalse($state->draftPending, 'the baseline left a draft pending: a reader would see a change');
        }
        // An entry with no body has nothing to publish, and the baseline manufactures no history.
        $this->assertNull($empty->fresh()->getAttribute('published_version'));
        $this->assertSame(2, $this->versionRows());
    }

    public function test_a_rerun_changes_nothing(): void
    {
        $this->createTables();
        $entry = $this->pinlessEntry();
        $this->migration()->up();
        $pin = (string) $entry->fresh()->getAttribute('published_version');

        $this->migration()->up();

        $this->assertSame($pin, (string) $entry->fresh()->getAttribute('published_version'));
        $this->assertSame(1, $this->versionRows());
    }

    public function test_a_fresh_database_with_no_entries_no_ops_even_without_the_version_table(): void
    {
        $this->createTables(versions: false);

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable(Beam::table('versions')));
    }

    public function test_pinless_entries_without_the_version_table_refuse_and_name_the_fix(): void
    {
        $this->createTables(versions: false);
        BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => (string) \Illuminate\Support\Str::uuid()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/vendor:publish --tag=beam-migrations --tag=beam-ux-migrations/');
        $this->migration()->up();
    }

    private function createTables(bool $versions = true): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->uuid('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        if (! $versions) {
            return;
        }

        Schema::create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });
    }
}

class BaselineFakeCompiler implements EntryBodyCompiler
{
    public function handles(BeamUxEntry $entry): bool
    {
        return in_array($entry->format?->value, ['mdx', 'tsx'], true);
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        return '/* compiled */ export default () => '.json_encode($source);
    }
}
