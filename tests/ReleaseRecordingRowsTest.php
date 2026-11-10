<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseRecording;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Recording\RecordingFailure;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Symfony\Component\Process\Process;

/**
 * config-sections 04 step 3 (ADR-0243): every recording manifest row says its kind, its ref and the root its path is
 * relative to. A receipt frozen before rows carried them reads as `entry` rows under the mirror disk, so it still
 * retries to the same commit. Disposable repositories only, under BEAM_RECORDING_TEST_ROOT, as the other recording tests.
 */
class ReleaseRecordingRowsTest extends AuthoredComponentNativeTestCase
{
    private User $author;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $root = getenv('BEAM_RECORDING_TEST_ROOT');
        if (! is_string($root) || ! is_dir($root)) {
            $this->markTestSkipped('Set BEAM_RECORDING_TEST_ROOT to an owned registered scratch root.');
        }
        $this->repo = $root.'/rows-'.bin2hex(random_bytes(6));
        mkdir($this->repo, 0700);
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.name', 'Release test']);
        $this->git(['config', 'user.email', 'release@example.invalid']);
        file_put_contents($this->repo.'/unrelated', "base\n");
        $this->git(['add', '--', 'unrelated']);
        $this->git(['commit', '-m', 'baseline']);
        config(['filesystems.disks.recording-test' => ['driver' => 'local', 'root' => $this->repo, 'throw' => true],
            'beam.ux.storage.mirror_disk' => 'recording-test', 'beam.ux.recording.git' => 'git']);
        Storage::forgetDisk('recording-test');
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table', 'create_beam_ux_release_recordings_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        Gate::before(fn () => true);
        \Illuminate\Support\Facades\Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Author']);
        $this->author = User::query()->findOrFail(7);
    }

    public function test_every_row_carries_its_kind_ref_and_root(): void
    {
        [$release, $page] = $this->publishWithHook(0);

        $receipt = app(ReleaseRecording::class)->receipt($release);
        $this->assertSame('recorded', $receipt->state, (string) $receipt->error);
        $this->assertCount(1, $receipt->manifest);
        $row = $receipt->manifest[0];
        $this->assertSame('entry', $row['kind']);
        $this->assertSame((string) $page->id, $row['ref']);
        $this->assertSame(Storage::disk('recording-test')->path(''), $row['root']);
        $this->assertSame($row['root'].$row['path'], $row['absolute'], 'the path is relative to the row\'s root');
    }

    public function test_a_receipt_frozen_before_rows_had_kinds_still_retries_to_one_commit(): void
    {
        $base = $this->git(['rev-parse', 'HEAD']);
        [$release] = $this->publishWithHook(1);
        $recorder = app(ReleaseRecording::class);
        $failed = $recorder->receipt($release);
        $this->assertSame('failed', $failed->state, 'the hook refused the first commit');
        $this->assertSame($base, $this->git(['rev-parse', 'HEAD']));
        $this->freezeAsPreChange($failed);
        $this->assertArrayNotHasKey('kind', $recorder->receipt($release)->manifest[0], 'the receipt now reads as written before 04');

        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 0\n");
        $recorder->retry($release, $this->author);

        $recovered = $recorder->receipt($release);
        $this->assertSame($failed->id, $recovered->id, 'the same frozen receipt');
        $this->assertSame('recorded', $recovered->state, (string) $recovered->error);
        $this->assertSame($this->git(['rev-parse', 'HEAD']), $recovered->sha);
        $this->assertSame('1', $this->git(['rev-list', '--count', $base.'..HEAD']));
        $this->assertArrayNotHasKey('kind', $recovered->manifest[0], 'retry reads the frozen rows; it never rewrites them');
    }

    public function test_a_row_of_another_kind_or_another_root_is_never_read_as_an_entry(): void
    {
        [$release] = $this->publishWithHook(1);
        $recorder = app(ReleaseRecording::class);
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 0\n");
        $receipt = $recorder->receipt($release);
        $frozen = $receipt->manifest;

        foreach (['kind' => ['config', 'unavailable'], 'root' => ['/elsewhere/', 'path']] as $field => [$value, $reason]) {
            $rows = $frozen;
            $rows[0][$field] = $value;
            $this->plant($receipt, $rows);
            try {
                $recorder->retry($release, $this->author);
                $this->fail("A row with {$field} `{$value}` was recorded as an entry.");
            } catch (RecordingFailure $refused) {
                $this->assertSame($reason, $refused->reason);
            }
            $this->assertSame('failed', $recorder->receipt($release)->state);
        }
    }

    /** @return array{BeamUxRelease, BeamUxEntry} a page published by a direct Release, its pre-commit hook exiting `$exit` */
    private function publishWithHook(int $exit): array
    {
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit {$exit}\n");
        chmod($this->repo.'/.git/hooks/pre-commit', 0755);
        $page = BeamUxEntry::create(['slug' => 'about-'.Str::random(4), 'title' => 'About', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'about']);
        $version = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]])->headVersion;
        $release = app(ReleaseService::class)->requestOne($page->fresh(), $version, $this->author);
        $this->assertSame('published', $release->status);

        return [$release, $page];
    }

    /** Strip what 04 added to each row, as a receipt frozen by the previous release of this package holds them. */
    private function freezeAsPreChange(BeamUxReleaseRecording $receipt): void
    {
        $this->plant($receipt, array_map(fn (array $row) => array_diff_key($row, array_flip(['kind', 'ref', 'root'])), $receipt->manifest));
    }

    /**
     * Write a receipt's frozen rows at the table, as another writer would have: the model never rewrites a frozen
     * manifest, which is the guarantee these rows rest on.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function plant(BeamUxReleaseRecording $receipt, array $rows): void
    {
        DB::connection($receipt->getConnectionName())->table('beam_ux_release_recordings')->where('id', $receipt->id)
            ->update(['manifest' => json_encode($rows)]);
    }

    private function git(array $args): string
    {
        $process = new Process(['git', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
