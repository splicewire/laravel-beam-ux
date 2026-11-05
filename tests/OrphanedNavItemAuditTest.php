<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Ux\Doctor\OrphanedNavItemAudit;
use Splicewire\Beam\Ux\Nav\Contracts\NavItemSource;
use Splicewire\Beam\Ux\Nav\NavItem;
use Splicewire\Beam\Ux\Nav\NoNavItemSource;

/**
 * make-surface 01: the starters' `OrphanedNavItemAudit`, packaged over the {@see NavItemSource} seam. The cases are
 * the starters' own (`SitemapRecordFrameTest`'s three doctor tests) plus the seam's: the shipped default, a host's
 * source superseding it, and absent storage.
 */
class OrphanedNavItemAuditTest extends TestCase
{
    /** @param list<NavItem> $items */
    private function source(array $items): void
    {
        $this->app->bind(NavItemSource::class, fn () => new class($items) implements NavItemSource
        {
            public function __construct(private array $items) {}

            public function navItems(): iterable
            {
                return $this->items;
            }
        });
    }

    private function finding(): \Rushing\Doctor\Finding
    {
        [$finding] = $this->app->make(OrphanedNavItemAudit::class)->run();

        return $finding;
    }

    public function test_the_shipped_default_has_nothing_to_orphan(): void
    {
        $this->assertInstanceOf(NoNavItemSource::class, $this->app->make(NavItemSource::class));

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertSame('orphaned nav item', $finding->check);
        $this->assertStringContainsString('nothing to orphan', $finding->detail);
    }

    public function test_it_passes_when_record_links_resolve_to_real_routes(): void
    {
        Route::get('dashboard', fn () => 'ok');
        $this->source([new NavItem(label: 'Dash', href: '/dashboard', order: 1)]);

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    public function test_it_warns_advisory_on_a_broken_or_malformed_record_link(): void
    {
        Route::get('/', fn () => 'home');
        $this->source([
            new NavItem(label: 'Gone', href: '/no-such-route', order: 1),
            // parse_url() answers FALSE here; collapsing it to '/' would resolve to the home route and hide it.
            new NavItem(label: 'Malformed', href: 'http://:', order: 2),
        ]);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('2 record nav link(s)', $finding->detail);
        $this->assertStringContainsString('/no-such-route', $finding->detail);
        $this->assertStringContainsString('http://:', $finding->detail);
    }

    public function test_it_skips_external_links(): void
    {
        $this->source([new NavItem(label: 'Off-host', href: 'https://elsewhere.example', order: 1, external: true)]);

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    public function test_absent_storage_reads_as_no_record_leaf(): void
    {
        $this->app->bind(NavItemSource::class, fn () => new class implements NavItemSource
        {
            public function navItems(): iterable
            {
                throw new RuntimeException('no such table: sitemap_records');
            }
        });

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('no record-leaf nav to validate', $finding->detail);
    }
}
