<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Data\SitemapHealthRowData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `SitemapHealthRowData::project()` end to end (operator-surface-prototypes ticket, Direction C step
 * 2) — proves the projection reaches the SAME `EntryPublishGate`/`EntryEntitlementGate` ports
 * {@see \Splicewire\Beam\Ux\Sitemap\EntrySitemapSource::urls()} reads, and that `indexed` is exactly
 * their three-way AND. No workflow binding is registered in this suite, so every entry is "unmanaged"
 * (`LifecycleService::manages()` is false) — `published`/`entitled` are trivially true via the default
 * `WorkflowMarkingPublishGate`/`PublicEntitlementGate` bindings; `routable` is the axis this test
 * actually varies, mirroring `EntrySitemapSource::routable()`'s own two checks.
 */
class SitemapHealthRowDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_a_placed_page_is_routable_and_indexed_with_a_resolved_url(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'about',
            'type' => 'page',
            'namespace' => null,
            'realm' => 'site',
            'realms' => ['site'],
            'segment' => 'about',
        ]);

        $row = SitemapHealthRowData::project($entry);

        $this->assertTrue($row->routable);
        $this->assertTrue($row->published);
        $this->assertTrue($row->entitled);
        $this->assertTrue($row->indexed);
        $this->assertNotNull($row->url);
    }

    public function test_a_non_page_type_is_not_routable_and_not_indexed(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'button',
            'type' => 'component',
            'namespace' => null,
            'realm' => 'site',
            'realms' => ['site'],
        ]);

        $row = SitemapHealthRowData::project($entry);

        $this->assertFalse($row->routable);
        $this->assertFalse($row->indexed);
        $this->assertNull($row->url);
    }

    public function test_a_page_with_no_realm_membership_is_not_routable_and_not_indexed(): void
    {
        // `BeamUxEntry::booted()`'s `creating` hook always derives `realms` onto `[realm]` when empty
        // AND `realm !== null` (theme-entries-and-authoring ticket 03) — so `realms` can only end up
        // genuinely empty when `realm` itself is null, overriding the model's own `self::REALM_SITE`
        // PHP-level default. The real migration doesn't mark the column nullable (a fresh entry always
        // gets SOME realm in production), but the model's own invariant is what `routable()` actually
        // reads — this fixture exercises that boolean directly, independent of whether an unrealmed
        // row is reachable through the app's own entry-creation UI.
        $entry = BeamUxEntry::create([
            'slug' => 'orphan',
            'type' => 'page',
            'namespace' => null,
            'realm' => null,
            'realms' => null,
        ]);

        $row = SitemapHealthRowData::project($entry);

        $this->assertFalse($row->routable);
        $this->assertFalse($row->indexed);
        $this->assertNull($row->url);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            // Nullable here (unlike the real migration's `->default('site')`, never `.nullable()`) so
            // the "no realm membership" fixture below can override the model's PHP-level default.
            $table->string('realm')->nullable()->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}
