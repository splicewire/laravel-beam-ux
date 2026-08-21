<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0209 — the public entry renderer. These are the cases the ADR argued for, not incidental coverage:
 *
 *  - a **root-absolute** segment resolves, which a pure top-down walk cannot reach — the whole reason
 *    resolution is two-phase, and the reason re-rooting `/docs` to `/beam/docs` is one row's edit;
 *  - the **uniform 404**: unresolvable, unpublished and access-denied are indistinguishable;
 *  - the renderer **never writes** — a GET against a realm with no seeded root creates nothing;
 *  - the gate is handed the chain the reverse walk already produced, so **`traverse` on an ancestor**
 *    denies a descendant even when the descendant itself is wide open;
 *  - a **guarded** body streams `no-store` while a public one is cacheable by version;
 *  - an entry in a realm this mount does not serve is **not reachable via the artifact route** either.
 */
class PublicEntryRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);

        $this->app->extend(
            StorageDriverResolver::class,
            fn () => (new StorageDriverResolver)
                ->register(StorageDriverResolver::DEFAULT, new RecordingDriver),
        );

        // A compiler that needs no Node: the renderer's contract is about resolution and gating, and a
        // real toolchain in a unit test would make every one of these cases depend on npm.
        $this->app->singleton(EntryBodyCompiler::class, fn () => new FakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);

        $this->app->bind(EntryRenderer::class, fn () => new RecordingRenderer);

        // Mounted bare: middleware is the HOST's (ADR-0209 §4 — a private site mounts this inside its
        // own auth group), so a test of the renderer has no business booting a session stack.
        Route::beamUxSite('site/entry');
    }

    public function test_it_serves_a_page_reached_by_walking_down_from_the_realm_root(): void
    {
        $root = BeamUxEntry::rootFor();
        $docs = $this->page('docs', ['segment' => 'docs', 'parent_id' => $root->getKey()]);
        $this->page('docs-api', ['segment' => 'api', 'parent_id' => $docs->getKey(), 'title' => 'API Reference']);

        $response = $this->get('/docs/api');

        $response->assertOk();
        $this->assertSame('site/entry', $response->json('page'));
        $this->assertSame('docs-api', $response->json('props.entry.slug'));
        $this->assertSame('/docs/api', $response->json('props.entry.url'));
    }

    public function test_a_root_absolute_segment_resolves_even_though_a_top_down_walk_could_never_reach_it(): void
    {
        // The docs subtree sits under the realm root but declares an absolute segment, so its URL
        // ignores every ancestor — the shape a pure top-down walk cannot resolve, and exactly what
        // re-rooting a subtree to `/beam/docs` by editing ONE row produces.
        $root = BeamUxEntry::rootFor();
        $shell = $this->page('shell', ['segment' => 'internal', 'parent_id' => $root->getKey()]);
        $docs = $this->page('docs', ['segment' => '/beam/docs', 'parent_id' => $shell->getKey()]);
        $this->page('docs-api', ['segment' => 'api', 'parent_id' => $docs->getKey()]);

        $this->get('/beam/docs')->assertOk();

        $response = $this->get('/beam/docs/api');
        $response->assertOk();
        $this->assertSame('docs-api', $response->json('props.entry.slug'));

        // And the ancestor path it does NOT resolve at.
        $this->get('/internal/docs/api')->assertNotFound();
    }

    public function test_unresolvable_unpublished_and_denied_are_one_indistinguishable_404(): void
    {
        // The DEFAULT publish gate reports an UNMANAGED entry as published (the optional-workflow
        // fallback), so proving the unpublished leg needs a gate that actually withholds. The port is
        // re-bindable by design; this is the same seam a host with its own publish rule uses.
        $this->app->bind(EntryPublishGate::class, fn () => new MarkingOnlyPublishGate);
        $this->app->forgetInstance(PublicEntryGate::class);

        $root = BeamUxEntry::rootFor();

        $this->page('draft', [
            'segment' => 'draft',
            'parent_id' => $root->getKey(),
            'workflow_marking' => 'review',
        ]);

        $this->page('secret', [
            'segment' => 'secret',
            'parent_id' => $root->getKey(),
            'access' => ['auth'],
        ]);

        // Indistinguishable is the assertion, not "empty": the three must produce the SAME response, or
        // an anonymous reader learns which private paths exist by diffing them.
        $responses = [];

        foreach (['/nothing-here', '/draft', '/secret'] as $path) {
            $response = $this->get($path);
            $response->assertNotFound();
            $responses[$path] = $response->getContent();
        }

        $this->assertCount(1, array_unique($responses), 'the three 404s must be byte-identical');
    }

    public function test_the_renderer_never_writes_the_realm_root(): void
    {
        $this->assertSame(0, BeamUxEntry::query()->count());

        $this->get('/anything')->assertNotFound();

        // `rootFor()` is a firstOrCreate; a GET that silently INSERTs breaks on read replicas and races
        // under concurrent first-hits (ADR-0209 §9). Absence means "nothing to serve".
        $this->assertSame(0, BeamUxEntry::query()->count());
    }

    public function test_traverse_on_an_ancestor_denies_a_descendant_that_is_itself_open(): void
    {
        $root = BeamUxEntry::rootFor();
        $private = $this->page('private', [
            'segment' => 'private',
            'parent_id' => $root->getKey(),
            'traverse' => ['auth'],
        ]);
        $this->page('leaf', ['segment' => 'leaf', 'parent_id' => $private->getKey()]);

        $this->get('/private/leaf')->assertNotFound();

        $this->actingAs($this->reader());
        $this->get('/private/leaf')->assertOk();
    }

    /**
     * The prop URL must PIN the version, because that is the only thing making §7's immutable-cache
     * argument true. It used to hand the shell a version-less address plus the version as a sibling
     * prop, which reads correct and is not: the browser caches by URL, and the URL never moved.
     */
    public function test_the_artifact_url_handed_to_the_shell_carries_the_version(): void
    {
        $root = BeamUxEntry::rootFor();
        $entry = $this->page('open', ['segment' => 'open', 'parent_id' => $root->getKey()]);

        $artifacts = $this->app->make(EntryArtifactStore::class);
        $artifacts->put($entry, 'export default () => null');
        $version = $artifacts->version($entry);

        $response = $this->withHeader('X-Inertia', 'true')->get('/open');
        $response->assertOk();

        $url = data_get($response->json(), 'props.artifact.url');

        $this->assertStringContainsString((string) $version, (string) $url);
    }

    public function test_a_guarded_artifact_is_no_store_and_a_public_one_is_cacheable(): void
    {
        $root = BeamUxEntry::rootFor();

        $public = $this->page('open', ['segment' => 'open', 'parent_id' => $root->getKey()]);
        $guarded = $this->page('closed', [
            'segment' => 'closed',
            'parent_id' => $root->getKey(),
            'access' => ['auth'],
        ]);

        $artifacts = $this->app->make(EntryArtifactStore::class);
        $artifacts->put($public, 'export default () => null');
        $artifacts->put($guarded, 'export default () => null');

        // Immutable is earned by the VERSION-PINNED address and nothing else. The version-less URL used
        // to be served `immutable, max-age=1y` on the strength of an argument about a URL that moves —
        // and it never moved, so a returning reader kept a stale module for a year without revalidating
        // (beam-docs-satellite ticket 08).
        $pinned = $this->get(route('beam.ux.site.artifact', [
            'entry' => $public->getKey(),
            'version' => $artifacts->version($public),
        ]));
        $pinned->assertOk();
        $this->assertStringContainsString('immutable', (string) $pinned->headers->get('Cache-Control'));
        $this->assertSame('"'.$artifacts->version($public).'"', $pinned->headers->get('ETag'));

        $open = $this->get(route('beam.ux.site.artifact', ['entry' => $public->getKey()]));
        $open->assertOk();
        $this->assertStringNotContainsString('immutable', (string) $open->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $open->headers->get('Cache-Control'));

        // A STALE pin is a moving target too — the version in the URL no longer names what is served, so
        // it must not be cached hard under someone else's key.
        $stale = $this->get(route('beam.ux.site.artifact', [
            'entry' => $public->getKey(),
            'version' => 'deadbeefdeadbeef',
        ]));
        $stale->assertOk();
        $this->assertStringNotContainsString('immutable', (string) $stale->headers->get('Cache-Control'));

        // Anonymous: the guarded artifact is the same 404 the page is — the artifact route must not be
        // the oracle the page route refuses to be.
        $this->get(route('beam.ux.site.artifact', ['entry' => $guarded->getKey()]))->assertNotFound();

        $this->actingAs($this->reader());
        $closed = $this->get(route('beam.ux.site.artifact', ['entry' => $guarded->getKey()]));
        $closed->assertOk();
        $this->assertStringContainsString('no-store', (string) $closed->headers->get('Cache-Control'));
    }

    public function test_an_entry_outside_the_mounted_realm_is_unreachable_by_id(): void
    {
        BeamUxEntry::rootFor();
        $operator = $this->page('ops', ['segment' => 'ops', 'realm' => 'operator', 'realms' => ['operator']]);

        $this->app->make(EntryArtifactStore::class)->put($operator, 'export default () => null');

        $this->get(route('beam.ux.site.artifact', ['entry' => $operator->getKey()]))->assertNotFound();
    }

    public function test_a_page_with_no_artifact_404s_rather_than_compiling_on_read(): void
    {
        $root = BeamUxEntry::rootFor();
        $page = $this->page('uncompiled', ['segment' => 'uncompiled', 'parent_id' => $root->getKey()]);

        // The page shell still renders — it is the BODY that is missing, and the shell is what tells a
        // reader so. What must never happen is the artifact route compiling on demand (§7).
        $this->get('/uncompiled')->assertOk();
        $this->get(route('beam.ux.site.artifact', ['entry' => $page->getKey()]))->assertNotFound();
    }

    public function test_the_two_phase_resolver_returns_the_containment_chain_not_the_url_pieces(): void
    {
        $root = BeamUxEntry::rootFor();
        $shell = $this->page('shell', ['segment' => 'internal', 'parent_id' => $root->getKey()]);
        $docs = $this->page('docs', ['segment' => '/beam/docs', 'parent_id' => $shell->getKey()]);

        $chain = $this->app->make(EntryPathResolver::class)->resolve('/beam/docs');

        // Three nodes, not two: the URL says `/beam/docs` but `shell` is a real ancestor, and it is the
        // ancestry the access conjunction is defined over (ADR-0212 §3).
        $this->assertSame(
            [$root->getKey(), $shell->getKey(), $docs->getKey()],
            array_map(fn (BeamUxEntry $e) => $e->getKey(), $chain),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function page(string $slug, array $attributes = []): BeamUxEntry
    {
        return BeamUxEntry::create(array_merge([
            'slug' => $slug,
            'type' => UxType::Page,
            'format' => UxFormat::Mdx,
        ], $attributes));
    }

    private function reader(): Authenticatable
    {
        return new class extends User
        {
            protected $table = 'users';

            public function getAuthIdentifier(): int
            {
                return 1;
            }
        };
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
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable()->index();
            $table->integer('nav_order')->nullable();
            $table->json('traverse')->nullable();
            $table->json('access')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
            $table->unique(['parent_id', 'segment']);
        });
    }
}

/** Captures what the renderer was asked to render, so the props contract is assertable without Inertia. */
class RecordingRenderer implements EntryRenderer
{
    public function render(string $page, array $props): Response
    {
        return new JsonResponse(['page' => $page, 'props' => $props]);
    }
}

/** Publishes only at the published marking — no workflow engine involved. */
class MarkingOnlyPublishGate implements EntryPublishGate
{
    public function isPublished(BeamUxEntry $entry): bool
    {
        return $entry->workflow_marking === null
            || $entry->workflow_marking === BeamUxEntry::MARKING_PUBLISHED;
    }
}

/** A compiler with no toolchain — it echoes the source back as a module. */
class FakeCompiler implements EntryBodyCompiler
{
    public static bool $fails = false;

    public function handles(BeamUxEntry $entry): bool
    {
        return in_array($entry->format?->value, ['mdx', 'tsx'], true);
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        if (self::$fails) {
            throw CompilationFailed::for($entry, 'the fake compiler was told to fail.');
        }

        return '/* compiled */ export default () => '.json_encode($source);
    }

    /**
     * An unmigrated host has NOTHING TO SERVE, and that is a 404 — never a 500 (ADR-0209 §5).
     *
     * The host mounts this renderer as a catch-all, so before the guard the FIRST request to any
     * unmatched URL on a host with beam-ux installed but not migrated raised
     * "no such table: beam_ux_entries" and turned the uniform-404 promise into a stack trace on every
     * unknown path. Found by `splicewire/www`'s own "an unknown path 404s" test the moment the
     * catch-all was mounted (beam-docs-satellite ticket 07).
     */
    public function test_an_unmigrated_host_resolves_nothing_rather_than_fataling(): void
    {
        Schema::dropIfExists('beam_ux_entries');

        $this->assertNull(
            app(EntryPathResolver::class)->resolve('/no-such-page'),
        );
    }
}
