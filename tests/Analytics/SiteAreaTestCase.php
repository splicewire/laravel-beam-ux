<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Ux\Analytics\SiteArea;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * The Site area's harness (analytics-area ticket 10): beam-ux with laravel-beam-analytics and frame, the three beam-ux pages
 * the area reads mounted in the realm under test, and readers whose entitlements and entry-editor standing are set per id.
 */
abstract class SiteAreaTestCase extends TestCase
{
    protected SiteAreaEntitlements $entitlements;

    /** The beam-ux pages the area reads, mounted in the realm under test. */
    protected function destinations(): array
    {
        return [SiteArea::SITEMAP_HEALTH, SiteArea::SITE_HEALTH, SiteArea::REDIRECTS];
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Rushing\DataFilters\ServiceProvider::class,
            ...parent::getPackageProviders($app),
            FrameServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            BeamAnalyticsServiceProvider::class,
        ];
    }

    protected function realm(): string
    {
        return 'operator';
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('frame.middleware', []);
        $app['config']->set('app.entitlements', ['os.operate' => [], 'analytics.view' => []]);
        $app->instance(EntitlementResolver::class, $this->entitlements = new SiteAreaEntitlements);
        $app['config']->set('beam.ux.frame_nav.default_realm', $this->realm());
        $app['config']->set('frame.realms', [$this->realm() => $this->destinations(), ...($this->realm() === 'operator' ? ['tenant' => []] : ['operator' => []])]);
    }

    /** The realm's console shell at its own route base (`/operator`, or `/` for the tenant), as a host mounts its SPA. */
    protected function defineRoutes($router): void
    {
        $base = $this->realm() === 'operator' ? 'operator/' : '';
        $router->get($base.'{path?}', fn () => $this->realm().' shell')->where('path', $base === '' ? '^(?!frame/).*' : '.*')
            ->defaults(SeatGate::OPEN_TO_MEMBERS, true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createEntriesTable();
        (include dirname(__DIR__, 2).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();

        Gate::define('beam-ux-entry.update', fn (?User $user = null): bool => in_array($user?->id, $this->entitlements->editors, true));
        Gate::define('ux.author', fn (?User $user = null): bool => in_array($user?->id, $this->entitlements->editors, true));
    }

    /** A reader holding `$keys`, and an entry editor (so a Redirects reader and a diagnostics reader) when `$editor`. */
    protected function reader(int $id, array $keys, bool $editor = false): User
    {
        $this->entitlements->keys[$id] = $keys;
        if ($editor) {
            $this->entitlements->editors[] = $id;
        }

        return (new User)->forceFill(['id' => $id]);
    }

    /** @return array<string, array{label: string, value: mixed, href: ?string}> */
    protected function figures(): array
    {
        $out = [];
        foreach ($this->getJson('frame/resources/analytics-site')->assertOk()->json('data') as $row) {
            $out[$row['id']] = ['label' => $row['label'], 'value' => $row['value'], 'href' => $row['href'] ?? null];
        }

        return $out;
    }

    /** How many rows a destination's own list reports to the same viewer, for a `filter[…]` query. */
    protected function listed(string $resource, array $filter = []): int
    {
        $query = http_build_query(['filter' => $filter, 'per_page' => 100]);

        return count($this->getJson("frame/resources/{$resource}?{$query}")->assertOk()->json('data'));
    }

    protected function seedSite(): void
    {
        // Indexed: a routable site page, published (unmanaged) and public.
        BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'realm' => 'site', 'realms' => ['site'], 'segment' => 'about']);
        BeamUxEntry::create(['slug' => 'pricing', 'type' => 'page', 'realm' => 'site', 'realms' => ['site'], 'segment' => 'pricing']);
        // Published but never in the sitemap: a page outside the public site.
        BeamUxEntry::create(['slug' => 'register', 'type' => 'page', 'realm' => 'auth', 'realms' => ['auth'], 'segment' => 'register']);
        // Not published (the publish gate below holds it back), so neither published nor indexed.
        BeamUxEntry::create(['slug' => 'draft', 'type' => 'page', 'realm' => 'site', 'realms' => ['site'], 'segment' => 'draft']);
        // Not a page: never counted by the page figures.
        BeamUxEntry::create(['slug' => 'card', 'type' => 'component', 'realm' => 'site']);

        $this->app->bind(EntryPublishGate::class, fn () => new class implements EntryPublishGate
        {
            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->slug !== 'draft';
            }
        });

        // Without events: a redirect's write records activity, which this harness has no table for and the area never reads.
        BeamUxRedirect::withoutEvents(fn () => BeamUxRedirect::create(['realm' => 'site', 'path' => '/old-about', 'match' => 'exact', 'target_kind' => 'path', 'target_path' => '/about']));
    }

    protected function createEntriesTable(): void
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
            $table->string('realm')->nullable()->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->integer('nav_order')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}

/** Entitlements per principal id, so each test reader holds exactly its own keys. */
class SiteAreaEntitlements implements EntitlementResolver
{
    /** @var array<int, list<string>> */
    public array $keys = [];

    /** @var list<int> */
    public array $editors = [];

    public function entitlementsFor(mixed $principal): array
    {
        return $this->keys[$principal?->id ?? 0] ?? [];
    }
}
