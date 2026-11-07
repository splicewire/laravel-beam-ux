<?php

namespace Splicewire\Beam\Ux\Tests\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Ux\Console\StampShellCommand;
use Splicewire\Beam\Ux\Doctor\ShellStampParityAudit;
use Splicewire\Beam\Ux\Shell\ShellStamps;
use Splicewire\Beam\Ux\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * `splicewire:beam:ux:stamp-shell` and `beam-ux.shell-stamp-parity` (make-surface ticket 02). The ticket's bars, here
 * at package level: a stamp writes the shell; a re-stamp reports every file `identical`; the parity audit is green
 * after a stamp and red after a one-line edit to a stamped copy; surfaces are independent; routes are printed, never
 * edited. The fresh `laravel new` host journey is the slice's host proof, not this file.
 *
 * Each test relocates the base path to a throwaway host (the generator tests' pattern), so nothing reaches the package
 * tree or a real host.
 */
class StampShellCommandTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = sys_get_temp_dir().'/beam-shell-stamp-'.Str::random(10);
        File::ensureDirectoryExists($this->host.'/app');
        File::put($this->host.'/composer.json', json_encode(['require' => [
            'inertiajs/inertia-laravel' => '*',
            'splicewire/laravel-beam-ux' => '*',
            'splicewire/laravel-beam-accounts' => '*',
        ]], JSON_PRETTY_PRINT));

        $this->app->setBasePath($this->host);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    public function test_a_stamp_writes_the_base_and_the_nav_records_them_and_prints_routes_and_seeding(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account,operator'])
            ->expectsOutputToContain("Route::beamRealmConsole('operator', ['auth', 'verified']);")
            ->expectsOutputToContain("\$this->command->call('splicewire:beam:seed'")
            ->assertSuccessful();

        $stamps = $this->app->make(ShellStamps::class);

        foreach ($stamps->filesFor(['site', 'account', 'operator']) as $target => $stub) {
            $this->assertFileEquals($stub, $this->host.'/'.$target, "{$target} is not its stub");
        }

        $this->assertSame($stamps->navFor(['site', 'account', 'operator']), File::get($this->host.'/'.StampShellCommand::NAV));
        $this->assertStringContainsString('segment: /account', File::get($this->host.'/'.StampShellCommand::NAV));

        $this->assertSame(['site', 'account', 'operator'], $this->recorded('resources/js/app.tsx'));
        $this->assertSame(['site', 'account', 'operator'], $this->recorded(StampShellCommand::NAV));

        $lint = new Process([PHP_BINARY, '-l', $this->host.'/app/Http/Middleware/HandleInertiaRequests.php'], env: ['XDEBUG_MODE' => 'off']);
        $lint->run();
        $this->assertSame(0, $lint->getExitCode(), $lint->getOutput());
        $this->assertStringContainsString('use SharesBeamShell;', File::get($this->host.'/app/Http/Middleware/HandleInertiaRequests.php'));
        $this->assertStringNotContainsString('@beamAnalytics', File::get($this->host.'/resources/views/app.blade.php'));
    }

    /**
     * The fresh `laravel new` host proof 500'd every signed-in page with IA invariant I6 ("route [account] declares no
     * resolvable seat gate") while the printed account line lacked its seat-gate default.
     */
    public function test_the_printed_account_route_declares_its_seat_gate(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'account'])
            ->expectsOutputToContain('->defaults(\Splicewire\Beam\Authorization\SeatGate::OPEN_TO_MEMBERS, true);')
            ->assertSuccessful();
    }

    /**
     * The stamped `app.css` deliberately declares no family `@source` lines: they are derived at build time by
     * `familySources()` from `@schemastud/seam/vite`. A fresh `laravel new` host has no such plugin, so its
     * family dist classes were never scanned (doctor `family @source coverage`: 17 of 17). The stamp prints the
     * wiring, before Tailwind, and edits no Vite file (make-surface 02 owner-authorized follow-up).
     */
    public function test_the_guidance_prints_the_family_sources_vite_wiring_before_tailwind_and_edits_no_vite_file(): void
    {
        File::put($this->host.'/vite.config.ts', "export default {};\n");

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site']));
        $output = \Illuminate\Support\Facades\Artisan::output();

        $import = strpos($output, "import { familySources } from '@schemastud/seam/vite';");
        $plugin = strpos($output, 'familySources(),');
        $tailwind = strpos($output, 'BEFORE tailwindcss()');

        $this->assertNotFalse($import, $output);
        $this->assertNotFalse($plugin, $output);
        $this->assertNotFalse($tailwind, 'the registration must say it goes before tailwindcss()');
        $this->assertLessThan($plugin, $import);
        $this->assertSame("export default {};\n", File::get($this->host.'/vite.config.ts'));
    }

    public function test_the_family_sources_wiring_is_printed_for_every_surface_selection(): void
    {
        foreach (['account', 'operator', 'site,account,operator'] as $surfaces) {
            $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => $surfaces, '--dry-run' => true])
                ->expectsOutputToContain("import { familySources } from '@schemastud/seam/vite';")
                ->assertSuccessful();
        }
    }

    /** The fresh-host proof's seed failed (`roles.id` NOT NULL) until these host steps were done; the stamp names them. */
    public function test_the_seeding_guidance_names_the_permission_pairing_and_user_traits(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'operator'])
            ->expectsOutputToContain('Splicewire\Beam\Accounts\Models\Role / Permission')
            ->assertSuccessful();
    }

    public function test_routes_are_printed_and_never_edited(): void
    {
        File::ensureDirectoryExists($this->host.'/routes');
        File::put($this->host.'/routes/web.php', "<?php\n");

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account,operator,os'])->assertSuccessful();

        $this->assertSame("<?php\n", File::get($this->host.'/routes/web.php'));
    }

    public function test_a_re_stamp_reports_every_file_identical_and_writes_nothing(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account,operator'])->assertSuccessful();
        $before = $this->tree();

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account,operator'])
            ->expectsOutputToContain('identical')
            ->doesntExpectOutputToContain('stamped')
            ->doesntExpectOutputToContain('recorded')
            ->assertSuccessful();

        $this->assertSame($before, $this->tree());
    }

    public function test_a_tailored_file_is_kept_without_force_and_overwritten_with_it(): void
    {
        File::ensureDirectoryExists($this->host.'/resources/css');
        File::put($this->host.'/resources/css/app.css', "/* the host's own theme */\n");

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])
            ->expectsOutputToContain('tailored — --force to overwrite')
            ->assertSuccessful();

        $this->assertSame("/* the host's own theme */\n", File::get($this->host.'/resources/css/app.css'));
        $this->assertNull($this->recorded('resources/css/app.css'));

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site', '--force' => true])
            ->expectsOutputToContain('overwritten')
            ->assertSuccessful();

        $this->assertFileEquals(dirname(__DIR__, 2).'/stubs/shell/app.css.stub', $this->host.'/resources/css/app.css');
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        File::ensureDirectoryExists($this->host.'/resources/css');
        File::put($this->host.'/resources/css/app.css', "/* tailored */\n");
        $before = $this->tree();

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account', '--dry-run' => true, '--force' => true])
            ->expectsOutputToContain('would stamp')
            ->expectsOutputToContain('would overwrite')
            ->assertSuccessful();

        $this->assertSame($before, $this->tree());
    }

    public function test_surfaces_are_independent(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])
            ->expectsOutputToContain("Route::beamUxSite('site/entry');")
            ->doesntExpectOutputToContain('beamRealmConsole')
            ->doesntExpectOutputToContain('splicewire:beam:seed')
            ->assertSuccessful();

        $this->assertStringNotContainsString('realm: account', File::get($this->host.'/'.StampShellCommand::NAV));
    }

    public function test_an_unknown_surface_is_refused_before_anything_is_written(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,admin'])->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->host.'/resources');
    }

    public function test_a_missing_package_is_warned(): void
    {
        File::put($this->host.'/composer.json', '{"require": {}}');

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'account'])
            ->expectsOutputToContain('does not require splicewire/laravel-beam-accounts')
            ->assertSuccessful();
    }

    public function test_a_tier_contributes_its_own_route_line(): void
    {
        $this->app->make(ShellStamps::class)->route('site', 'Route::towerSpa();');

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])
            ->expectsOutputToContain('Route::towerSpa();')
            ->assertSuccessful();
    }

    // ── the parity audit ────────────────────────────────────────────────────────────────────────────

    public function test_the_parity_audit_is_green_after_a_stamp_and_red_after_a_one_line_edit(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account,operator'])->assertSuccessful();

        $this->assertStatuses([DoctorStatus::Pass], $this->audit());

        File::append($this->host.'/resources/js/app.tsx', "// one host line\n");

        $findings = $this->audit();
        $this->assertStatuses([DoctorStatus::Warn], $findings);
        $this->assertStringContainsString('resources/js/app.tsx has drifted from its stub', $findings[0]->detail);

        // Re-stamping with --force restores parity.
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account,operator', '--force' => true])->assertSuccessful();
        $this->assertStatuses([DoctorStatus::Pass], $this->audit());
    }

    // ── a changed surface selection (make-surface 02 review, finding 2) ────────────────────────────
    //
    // The nav is composed from the selection, so the same target holds different bytes for `site` and for
    // `site,account`. The record must say which stamp each file actually holds, or a correct stamp reads as drift.

    public function test_a_changed_selection_with_force_restamps_the_nav_and_parity_stays_green(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])->assertSuccessful();

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'account', '--force' => true])
            ->expectsOutputToContain('overwritten')
            ->assertSuccessful();

        $this->assertStatuses([DoctorStatus::Pass], $this->audit());
        $stamps = $this->app->make(ShellStamps::class);
        $this->assertSame($stamps->navFor(['account']), File::get($this->host.'/'.StampShellCommand::NAV));
        $this->assertSame(['account'], $this->recorded(StampShellCommand::NAV));
    }

    public function test_a_changed_selection_without_force_keeps_the_earlier_nav_stamp_and_parity_stays_green(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])->assertSuccessful();

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account'])
            ->expectsOutputToContain('the site stamp — --force to re-stamp it for site, account')
            ->assertSuccessful();

        $stamps = $this->app->make(ShellStamps::class);
        $this->assertStatuses([DoctorStatus::Pass], $this->audit());
        $this->assertSame($stamps->navFor(['site']), File::get($this->host.'/'.StampShellCommand::NAV));
        $this->assertSame(['site'], $this->recorded(StampShellCommand::NAV));
        // The base files are identical under both selections, so they now record the new one.
        $this->assertSame(['site', 'account'], $this->recorded('resources/js/app.tsx'));

        // And --force then moves the nav to the new selection, still green.
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account', '--force' => true])->assertSuccessful();
        $this->assertStatuses([DoctorStatus::Pass], $this->audit());
        $this->assertSame($stamps->navFor(['site', 'account']), File::get($this->host.'/'.StampShellCommand::NAV));
        $this->assertSame(['site', 'account'], $this->recorded(StampShellCommand::NAV));
    }

    public function test_a_tailored_nav_still_warns_after_a_changed_selection(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])->assertSuccessful();
        File::append($this->host.'/'.StampShellCommand::NAV, "\nextra:\n  segment: /extra\n");

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account'])
            ->expectsOutputToContain('tailored — --force to overwrite')
            ->assertSuccessful();

        $findings = $this->audit();
        $this->assertStatuses([DoctorStatus::Warn], $findings);
        $this->assertStringContainsString(StampShellCommand::NAV.' has drifted from its stub', $findings[0]->detail);
    }

    public function test_a_tailored_css_still_warns_after_a_changed_selection(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])->assertSuccessful();
        File::append($this->host.'/resources/css/app.css', "/* host brand */\n");

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'account'])
            ->expectsOutputToContain('tailored — --force to overwrite')
            ->assertSuccessful();

        $findings = $this->audit();
        $this->assertStatuses([DoctorStatus::Warn], $findings);
        $this->assertStringContainsString('resources/css/app.css has drifted from its stub', $findings[0]->detail);
    }

    public function test_an_unchanged_repeat_after_a_changed_selection_writes_nothing(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])->assertSuccessful();
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account'])->assertSuccessful();
        $before = $this->tree();

        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account'])
            ->doesntExpectOutputToContain('recorded')
            ->assertSuccessful();

        $this->assertSame($before, $this->tree());
        $this->assertStatuses([DoctorStatus::Pass], $this->audit());
    }

    /** A record written before per-file provenance (`{surfaces, files: [targets]}`) reads as one selection. */
    public function test_an_earlier_record_format_reads_as_one_selection(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site,account'])->assertSuccessful();
        File::put($this->host.'/'.StampShellCommand::RECORD, json_encode([
            'surfaces' => ['site', 'account'],
            'files' => ['resources/beam-ux/nav.yml', 'resources/css/app.css'],
        ]));

        $this->assertStatuses([DoctorStatus::Pass], $this->audit());
        $this->assertSame(['site', 'account'], $this->recorded(StampShellCommand::NAV));
    }

    public function test_the_parity_audit_reports_a_stamped_file_that_was_removed(): void
    {
        $this->artisan('splicewire:beam:ux:stamp-shell', ['--surfaces' => 'site'])->assertSuccessful();
        File::delete($this->host.'/resources/views/app.blade.php');

        $this->assertStringContainsString('resources/views/app.blade.php was stamped and is now missing', $this->audit()[0]->detail);
    }

    public function test_the_parity_audit_flags_retired_machinery_and_a_re_inlined_share(): void
    {
        File::ensureDirectoryExists($this->host.'/app/Sitemap');
        File::put($this->host.'/app/Sitemap/NavItem.php', "<?php\n");
        File::ensureDirectoryExists($this->host.'/app/Http/Middleware');
        File::put($this->host.'/app/Http/Middleware/HandleInertiaRequests.php', "<?php\nclass HandleInertiaRequests { public function share(\$request): array { return []; } }\n");

        $details = array_map(fn (Finding $f) => $f->detail, $this->audit());

        $this->assertCount(2, $details);
        $this->assertStringContainsString('app/Sitemap/NavItem.php is generic machinery back in the host', $details[0]);
        $this->assertStringContainsString('re-inlines the shared props', $details[1]);
    }

    public function test_the_parity_audit_passes_a_host_with_no_stamp(): void
    {
        $findings = $this->audit();

        $this->assertStatuses([DoctorStatus::Pass], $findings);
        $this->assertStringContainsString('no shell stamp recorded', $findings[0]->detail);
    }

    // ── wiring ──────────────────────────────────────────────────────────────────────────────────────

    public function test_the_stamp_is_the_beam_ux_install_steps_completion_command(): void
    {
        if (! $this->app->bound(BeamInstallManifest::class)) {
            $this->markTestSkipped('beam-core install manifest not bound in this testbench.');
        }

        $steps = array_filter(
            $this->app->make(BeamInstallManifest::class)->steps(),
            fn ($step) => $step->package === 'splicewire/laravel-beam-ux',
        );

        $this->assertCount(1, $steps);
        $this->assertSame(['splicewire:beam:ux:stamp-shell'], array_values($steps)[0]->commands);
    }

    /** @return list<string>|null the surface selection the record holds for this target */
    private function recorded(string $target): ?array
    {
        return ShellStampParityAudit::readRecord($this->host.'/'.StampShellCommand::RECORD)[$target] ?? null;
    }

    /** @return list<Finding> */
    private function audit(): array
    {
        return (new ShellStampParityAudit($this->app->make(ShellStamps::class), $this->host))->run();
    }

    /** @param list<DoctorStatus> $expected @param list<Finding> $findings */
    private function assertStatuses(array $expected, array $findings): void
    {
        $this->assertSame($expected, array_map(fn (Finding $f) => $f->status, $findings), implode("\n", array_map(fn (Finding $f) => $f->detail, $findings)));
    }

    /** @return array<string, string> every file under the host, path => sha1 */
    private function tree(): array
    {
        $files = [];

        foreach (File::allFiles($this->host) as $file) {
            $files[$file->getRelativePathname()] = sha1_file($file->getPathname());
        }

        ksort($files);

        return $files;
    }
}
