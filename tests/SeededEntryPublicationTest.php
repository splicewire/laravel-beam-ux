<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Seed\SeedsEntries;

/**
 * A page a seeder writes is BORN PUBLISHED: it has a Publication Pin from the moment it exists (ADR-0225 decision 2).
 *
 * Every fresh install runs the seeders AFTER `migrate`, so the baseline migration has nothing to pin and the seeded
 * pages stayed pin-less forever: on a fresh laravel-beam-starter, `docs` and `docs-api` (beam-docs' DocsSeeder, through
 * this trait) and `docs-mcp` (beam-mcp) among 11 (launch ticket 00, 2026-10-16; row qitem-20261016214321-d6aa335c).
 * The legacy no-history fallback served them, and EP-08's "the editor refuses to mount where the pin is missing" would
 * then refuse a fresh starter's own pages. {@see SeedsEntries::seedPage()} now writes and publishes through
 * {@see EntryPublication}, the way the packaged ThemeSeeder does.
 */
class SeededEntryPublicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        // The default WriteGate delegates to the Laravel gate; a host grants the console seed its writes.
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);
        $this->app->singleton(EntryBodyCompiler::class, fn () => new SeedingFakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    public function test_a_seeded_page_is_born_published_with_a_pin_and_a_compiled_artifact(): void
    {
        $entry = (new PageSeedingSeeder)->seed('guide', "# Guide\n\nSeeded body.");

        $this->assertNotNull($entry->published_version, 'a seeded page was left without a Publication Pin');
        $state = app(EntryPublication::class)->state($entry->fresh());
        $this->assertFalse($state->draftPending);
        $this->assertSame($state->headVersion, $state->publishedVersion);
        $this->assertCount(1, $state->versions);
        $this->assertStringContainsString('Seeded body.', (string) app(EntryArtifactStore::class)->read($entry->fresh()));
    }

    public function test_reseeding_is_idempotent_the_pin_and_the_history_do_not_move(): void
    {
        $first = (new PageSeedingSeeder)->seed('guide', "# Guide\n\nSeeded body.");
        $pin = (string) $first->published_version;

        $again = (new PageSeedingSeeder)->seed('guide', "# Guide\n\nSeeded body.");

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame($pin, (string) $again->fresh()->published_version);
        $this->assertSame(1, BeamUxEntry::query()->where('slug', 'guide')->count());
        $this->assertSame(1, (int) \DB::table(Beam::table('versions'))->where('versionable_id', (string) $again->fresh()->particle_id)->count());
    }

    public function test_seeding_a_page_without_the_version_store_refuses_before_writing_anything(): void
    {
        Schema::drop(Beam::table('versions'));

        try {
            (new PageSeedingSeeder)->seed('guide', "# Guide\n\nSeeded body.");
            $this->fail('a page was seeded with no version store to pin it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('vendor:publish --tag=beam-migrations --tag=beam-ux-migrations', $e->getMessage());
        }

        $this->assertSame(0, BeamUxEntry::query()->where('slug', 'guide')->count(), 'a refused seed left a row behind');
    }

    public function test_a_failure_while_pinning_surfaces_leaves_no_row_and_a_rerun_seeds_the_page(): void
    {
        // review-r1, 2026-10-16: the create and body commit, then the pin fails, and nothing may be left stranded.
        FailingOncePublication::$fail = true;
        $this->app->bind(EntryPublication::class, FailingOncePublication::class);

        try {
            (new PageSeedingSeeder)->seed('guide', "# Guide\n\nSeeded body.");
            $this->fail('a failure while pinning was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure while pinning', $e->getMessage());
        }

        $this->assertSame(0, BeamUxEntry::query()->where('slug', 'guide')->count(), 'a failed publication left a pin-less row');

        // The retry is not a no-op: it seeds the page, born published.
        $entry = (new PageSeedingSeeder)->seed('guide', "# Guide\n\nSeeded body.");
        $this->assertNotNull($entry->fresh()->published_version);
    }

    public function test_a_rerun_repairs_a_pinless_seeded_row_that_has_no_history(): void
    {
        // A row a seed left pin-less (seeded before pages were born published): a body, no pin, no history.
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        app(EntryPublication::class)->write($entry, $entry->codec()->encode("# Guide\n\nSeeded body."));
        $this->assertNull($entry->fresh()->published_version);

        (new PageSeedingSeeder)->seed('guide', "# Guide\n\nSeeded body.");

        $this->assertNotNull($entry->fresh()->published_version, 'a re-seed left a pin-less row pin-less');
        $this->assertStringContainsString('Seeded body.', (string) app(EntryArtifactStore::class)->read($entry->fresh()));
    }

    public function test_a_rerun_never_publishes_a_pinless_row_that_has_history_it_is_an_unpublished_draft(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        app(EntryPublication::class)->write($entry, $entry->codec()->encode("# Guide\n\nA draft."));
        app(\Rushing\Versioning\Contracts\VersionStore::class)->snapshot(\Splicewire\Beam\Models\BeamParticle::find($entry->fresh()->particle_id), 'draft');

        (new PageSeedingSeeder)->seed('guide', "# Guide\n\nSeeded body.");

        $this->assertNull($entry->fresh()->published_version, 'a re-seed published an unpublished draft');
    }

    public function test_shipped_structural_auth_body_is_pinned_preserved_and_idempotent(): void
    {
        $body = [['kind' => 'block', 'name' => 'AuthLogin', 'isComponent' => true, 'props' => [], 'children' => [], 'dynamic' => false]];
        $seeder = app(\Splicewire\Beam\Ux\Provenance\Reasserter::class);
        $entry = $seeder->seedBody(['namespace' => 'starter', 'slug' => 'login'], ['type' => 'page', 'format' => 'tsx', 'title' => 'Login'], $body, 'package:starter/auth');
        $pin = $entry->published_version;
        $this->assertNotNull($pin);
        $this->assertSame($body, \Splicewire\Beam\Models\BeamParticle::findOrFail($entry->particle_id)->payload);
        $again = $seeder->seedBody(['namespace' => 'starter', 'slug' => 'login'], ['type' => 'page', 'format' => 'tsx', 'title' => 'Login'], $body, 'package:starter/auth');
        $this->assertSame($pin, $again->published_version);
        $this->assertCount(1, app(EntryPublication::class)->state($again)->versions);
    }

    public function test_structured_reseed_repairs_a_pinless_existing_body_without_changing_it(): void
    {
        $entry = BeamUxEntry::create(['namespace' => 'starter', 'slug' => 'login', 'type' => 'page', 'format' => 'tsx']);
        app(EntryPublication::class)->write($entry, $entry->codec()->encode('<p>Existing body</p>'));
        $particle = $entry->fresh()->particle_id;
        $again = app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->seedBody(
            ['namespace' => 'starter', 'slug' => 'login'], ['type' => 'page', 'title' => 'Login'], [], 'package:starter/auth',
        );
        $this->assertSame($particle, $again->particle_id);
        $this->assertNotNull($again->published_version);
        $this->assertStringContainsString('Existing body', (string) app(EntryArtifactStore::class)->read($again));
    }

    public function test_structured_reseed_updates_pristine_content_but_preserves_author_edits(): void
    {
        $seed = app(\Splicewire\Beam\Ux\Provenance\Reasserter::class);
        $coordinate = ['namespace' => 'starter', 'slug' => 'login'];
        $attributes = ['type' => 'page', 'format' => 'tsx', 'title' => 'Login'];
        $first = $seed->seedBody($coordinate, $attributes, ['source' => '<p>First</p>'], 'package:starter/auth');
        $second = $seed->seedBody($coordinate, $attributes, ['source' => '<p>Second</p>'], 'package:starter/auth');
        $this->assertSame($first->particle_id, $second->particle_id);
        $this->assertNotSame($first->published_version, $second->published_version);
        app(EntryPublication::class)->write($second, ['source' => '<p>Author</p>']);
        $again = $seed->seedBody($coordinate, $attributes, ['source' => '<p>Third</p>'], 'package:starter/auth');
        $this->assertSame(['source' => '<p>Author</p>'], \Splicewire\Beam\Models\BeamParticle::findOrFail($again->particle_id)->payload);
        $this->assertSame($second->published_version, $again->published_version);
    }

    public function test_structured_seed_rolls_back_when_pinning_fails(): void
    {
        FailingOncePublication::$fail = true;
        $this->app->bind(EntryPublication::class, FailingOncePublication::class);
        try {
            app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->seedBody(
                ['namespace' => 'starter', 'slug' => 'login'], ['type' => 'page', 'format' => 'tsx'], [], 'package:starter/auth',
            );
            $this->fail('Expected publication failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure while pinning', $e->getMessage());
        }
        $this->assertSame(0, BeamUxEntry::query()->where('slug', 'login')->count());
        $this->assertSame(0, \Splicewire\Beam\Models\BeamParticle::query()->count());
    }

    private function createTables(): void
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
            $table->string('origin')->nullable();
            $table->string('asserted_hash')->nullable();
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

/** The smallest seeder that seeds a page through the trait, as beam-docs' DocsSeeder does. */
class PageSeedingSeeder extends Seeder
{
    use SeedsEntries;

    public function seed(string $slug, string $source): BeamUxEntry
    {
        return $this->seedPage($slug, $source, ['title' => ucfirst($slug)]);
    }
}

/** A compiler with no toolchain: it echoes the source back as a module. */
class SeedingFakeCompiler implements EntryBodyCompiler
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

/** Fails the first publishWritten() it is asked for, then behaves normally. */
class FailingOncePublication extends EntryPublication
{
    public static bool $fail = true;

    public function publishWritten(BeamUxEntry $entry, ?string $label = null, bool $mirror = true): ?string
    {
        if (self::$fail) {
            self::$fail = false;

            throw new \RuntimeException('simulated failure while pinning');
        }

        return parent::publishWritten($entry, $label, $mirror);
    }
}
