<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryBodySaveOp;
use Splicewire\Beam\Ux\Particle\EntryDraftSaveOp;
use Splicewire\Beam\Ux\Particle\EntryPublishOp;
use Splicewire\Beam\Ux\Particle\EntryVersionRestoreOp;
use Splicewire\Beam\Ux\Particle\EntryVersionsShowOp;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * **Draft, publish, and restore as two pins into one version history** (G2-BEAM-DRAFT-PUBLISH).
 *
 * The obligation this file exists for is the one a reader can see: a draft must be recorded and must
 * change NOTHING a guest is served, and a publish must move what a guest is served through the real
 * compile path. Both of those are statements about the ARTIFACT ADDRESS, not about a column, so every
 * case below reads the artifact the public renderer would address
 * ({@see EntryArtifactStore::path()} / {@see EntryArtifactStore::read()}) rather than only asserting
 * that a pin moved. A pin that moved while the reader kept getting the old module would pass a
 * column-only assertion exactly as well as a correct implementation.
 */
class EntryPublicationTest extends TestCase
{
    private function themeFileFixture(): array
    {
        $root = $this->ownedFileFixtureRoot();
        $directory = $root.'/theme-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        config(['filesystems.disks.theme-file' => ['driver' => 'local', 'root' => $directory], 'beam.ux.storage.mirror_disk' => 'theme-file']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);
        [$releases, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn () => true);
        $entry = BeamUxEntry::create(['slug' => 'file-theme', 'type' => 'theme', 'format' => 'css', 'realm' => 'site', 'namespace' => 'theme']);
        $body = ['canvas' => ['accent' => '#112233', 'fontBody' => 'system-ui'], 'shell' => ['surface' => '#ffffff', 'radius' => '14px', 'shadow' => '0 40px 90px -40px rgba(0,0,0,.7)'], 'site' => ['accent' => '#334455']];
        $publication = app(EntryPublication::class);
        $publication->write($entry, $body);
        $publication->publishAuthoredBody($entry);
        $state = $publication->state($entry->fresh());
        $release = $releases->requestOne($entry->fresh(), $state->headVersion, $author);
        $member = $release->members()->firstOrFail();
        $target = app(\Splicewire\Beam\Ux\Files\LocalThemeFile::class)->target($entry->fresh());

        return [$entry->fresh(), $member, $author, $body, $target, $publication];
    }

    public static function recordingPreflightRefusals(): array
    {
        return [['case-alias'], ['nested-repository'], ['ls-files-failure'], ['git-unavailable']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recordingPreflightRefusals')]
    public function test_git_preflight_refusal_still_completes_every_published_mirror(string $mode): void
    {
        [$repo, $git] = $this->recordingRepository();
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn () => true);
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $paths = [];
        foreach ([$one, $two] as $entry) {
            $paths[] = app(\Splicewire\Beam\Ux\Placement\PlacementResolver::class)->resolve($entry)->pathFor($entry);
        }
        if ($mode === 'case-alias') {
            $alias = dirname($paths[0]).'/'.strtoupper(basename($paths[0]));
            rename($repo.'/'.$paths[0], $repo.'/'.$alias);
            $git(['add', '--', $alias]);
            $git(['commit', '-m', 'Existing case alias']);
        } elseif ($mode === 'nested-repository') {
            $nested = new \Symfony\Component\Process\Process(['git', 'init', '-b', 'main'], dirname($repo.'/'.$paths[0]));
            $nested->mustRun();
        } elseif ($mode === 'ls-files-failure') {
            $wrapper = dirname($repo).'/ls-files-refusal-'.bin2hex(random_bytes(6));
            file_put_contents($wrapper, "#!/bin/sh\nfor arg in \"\$@\"; do [ \"\$arg\" != ls-files ] || exit 1; done\nexec git \"\$@\"\n");
            chmod($wrapper, 0700);
            config(['beam.ux.recording.git' => $wrapper]);
        } else {
            config(['beam.ux.recording.git' => PHP_BINARY.'-unavailable-git']);
        }
        $base = $git(['rev-parse', 'HEAD']);
        $index = hash_file('sha256', $repo.'/.git/index');
        $pub = app(EntryPublication::class);
        $a = $pub->recordDraft($one, self::BODY_TWO);
        $b = $pub->recordDraft($two, self::BODY_TWO);
        $release = $service->create($one, 'All mirrors despite Git refusal', $author);
        $service->putMember($release, $two->id, $b->headVersion, $author);
        $service->putMember($release, $one->id, $a->headVersion, $author);
        $service->transition($release, 'publish', $author);
        $this->assertSame('published', $release->fresh()->status);
        $this->assertSame($a->headVersion, $one->fresh()->published_version);
        $this->assertSame($b->headVersion, $two->fresh()->published_version);
        foreach ([$one, $two] as $position => $entry) {
            $this->assertSame($entry->codec()->decode(self::BODY_TWO), file_get_contents($repo.'/'.$paths[$position]), $mode);
        }
        $receipt = app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class)->receipt($release);
        $this->assertContains($receipt->state, ['failed', 'unavailable']);
        $this->assertNull($receipt->sha);
        $this->assertNull($receipt->candidate);
        $this->assertSame($base, $git(['rev-parse', 'HEAD']));
        $this->assertSame($index, hash_file('sha256', $repo.'/.git/index'));
    }

    public static function configuredMirrorRoots(): array
    {
        return [['configured-link'], ['platform-temp-alias']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configuredMirrorRoots')]
    public function test_configured_symlink_mirror_root_publishes_without_git_recording(string $mode): void
    {
        $root = $this->ownedFileFixtureRoot();
        mkdir($root.'/real');
        symlink($root.'/real', $root.'/configured');
        $configured = $mode === 'configured-link' ? $root.'/configured' : rtrim(sys_get_temp_dir(), '/').'/'.basename($root).'/real';
        config(['filesystems.disks.alias-mirror' => ['driver' => 'local', 'root' => $configured], 'beam.ux.storage.mirror_disk' => 'alias-mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn () => true);
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $state = $this->publishDraftAs($entry, $author);
        $path = app(\Splicewire\Beam\Ux\Placement\PlacementResolver::class)->resolve($entry)->pathFor($entry);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
        $this->assertSame('published', $state->release->status);
        $this->assertSame($entry->codec()->decode(self::BODY_TWO), file_get_contents($root.'/real/'.$path));
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $receipt = app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class)->receipt($release);
        $this->assertNotSame('recorded', $receipt->state);
        $this->assertNull($receipt->sha);
    }

    public function test_restore_rejects_known_symlink_before_creating_directories_outside_root(): void
    {
        $root = $this->ownedFileFixtureRoot();
        mkdir($root.'/mirror');
        mkdir($root.'/outside');
        symlink($root.'/outside', $root.'/mirror/escape');
        $target = ['absolute' => $root.'/mirror/escape/new/member.css', 'path' => 'escape/new/member.css', 'fingerprint' => 'captured-before-directory-change'];
        $this->assertFileConflict('placement-moved', fn () => app(\Splicewire\Beam\Ux\Files\LocalThemeFile::class)->create($target, 'body'));
        $this->assertDirectoryDoesNotExist($root.'/outside/new');
    }

    public function test_configured_root_does_not_admit_a_child_symlink_escape(): void
    {
        $root = $this->ownedFileFixtureRoot();
        mkdir($root.'/mirror');
        mkdir($root.'/outside');
        config(['filesystems.disks.contained-mirror' => ['driver' => 'local', 'root' => $root.'/mirror'], 'beam.ux.storage.mirror_disk' => 'contained-mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn () => true);
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $path = app(\Splicewire\Beam\Ux\Placement\PlacementResolver::class)->resolve($entry)->pathFor($entry);
        unlink($root.'/mirror/'.$path);
        rmdir(dirname($root.'/mirror/'.$path));
        symlink($root.'/outside', dirname($root.'/mirror/'.$path));
        $state = $this->publishDraftAs($entry, $author);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
        $this->assertFileDoesNotExist($root.'/outside/'.basename($path));
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $receipt = app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class)->receipt($release);
        $this->assertNotSame('recorded', $receipt->state);
        $this->assertSame('path', $receipt->error);
        $this->assertNull($receipt->candidate);
    }

    public function test_default_file_fixture_is_owned_canonical_and_git_is_unavailable(): void
    {
        $root = $this->ownedFileFixtureRoot();
        $this->assertSame(realpath($root), $root);
        $this->assertSame(realpath(sys_get_temp_dir()), dirname($root));
        $this->assertFileExists($root.'/.beam-ux-file-owner');
        $this->assertNotSame('git', config('beam.ux.recording.git'));
        for ($parent = $root; $parent !== dirname($parent); $parent = dirname($parent)) {
            $this->assertFileDoesNotExist($parent.'/.git');
            $this->assertDirectoryDoesNotExist($parent.'/.git');
        }
    }

    public function test_completion_reserves_and_mirrors_entries_in_global_id_order(): void
    {
        $root = $this->ownedFileFixtureRoot();
        config(['filesystems.disks.ordered-mirror' => ['driver' => 'local', 'root' => $root], 'beam.ux.storage.mirror_disk' => 'ordered-mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn () => true);
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $pub = app(EntryPublication::class);
        $a = $pub->recordDraft($one, self::BODY_TWO);
        $b = $pub->recordDraft($two, self::BODY_TWO);
        $release = $service->create($one, 'Ordered completion', $author);
        $service->putMember($release, $two->id, $b->headVersion, $author);
        $service->putMember($release, $one->id, $a->headVersion, $author);
        $service->transition($release, 'publish', $author);
        $prepared = [];
        foreach ([$one->fresh(), $two->fresh()] as $entry) {
            $member = $release->members()->where('entry_id', $entry->id)->firstOrFail();
            $prepared[] = [$entry, (object) ['id' => $member->version_id], self::BODY_TWO];
        }
        usort($prepared, fn (array $left, array $right): int => strcmp((string) $right[0]->id, (string) $left[0]->id));
        $mirrors = new class(Storage::disk('ordered-mirror')) extends \Splicewire\Beam\Ux\Storage\PlacedDiskMirror
        {
            public array $entryIds = [];

            public function mirror(BeamUxEntry $entry, string $path, array $body): bool
            {
                $this->entryIds[] = (string) $entry->id;

                return parent::mirror($entry, $path, $body);
            }
        };
        $this->app->instance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class, $mirrors);
        $reservations = [];
        $one->getConnection()->listen(function ($query) use (&$reservations) {
            if (str_starts_with($query->sql, 'update "beam_ux_entries" set "id" =')) {
                $reservations[] = $query->bindings[0];
            }
        });
        // Supply reverse order directly to the completion boundary: member insertion order cannot
        // change the entry lock order used by publication and completion.
        (new \ReflectionMethod(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class, 'complete'))
            ->invoke(app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class), $release->fresh(), $prepared, $author);
        $expected = [(string) $one->id, (string) $two->id];
        sort($expected);
        $this->assertSame([$expected[0]], $reservations);
        $this->assertSame($expected, $mirrors->entryIds);
    }

    private function fileInput($inspection): \Splicewire\Beam\Ux\Data\FileReconcileInputData
    {
        return new \Splicewire\Beam\Ux\Data\FileReconcileInputData($inspection->workingHeadVersion, $inspection->publishedVersion,
            $inspection->fileExists, $inspection->fileSha256, $inspection->fingerprint, true);
    }

    public function test_file_import_preserves_all_namespaces_pin_mirror_and_records_only_a_draft(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $body['canvas']['accent'] = '#102030';
        $body['shell']['surface'] = '#405060';
        $body['site']['accent'] = '#abcdef';
        $bytes = $entry->codec()->decode($body);
        file_put_contents($target['absolute'], $bytes);
        $before = $publication->state($entry);
        $inspection = $service->inspect($member, $author);
        $this->assertSame('differs', $inspection->state);
        $result = $service->take($member, $author, $this->fileInput($inspection));
        $after = $publication->state($entry->fresh());
        $this->assertSame('drafted', $result->status);
        $this->assertSame($before->publishedVersion, $after->publishedVersion);
        $this->assertNotSame($before->headVersion, $after->headVersion);
        $this->assertCount(count($before->versions) + 1, $after->versions);
        $this->assertSame($bytes, file_get_contents($target['absolute']));
        $actual = app(\Rushing\Versioning\Contracts\VersionStore::class)->version($entry->fresh()->versionable(), $after->headVersion)->snapshot['payload'];
        $this->assertSame($body, $actual);
    }

    public function test_file_import_rejects_pin_moved_with_same_head_and_keeps_every_effect(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $body['site']['accent'] = '#abcdef';
        $draft = $publication->draftFromBase($entry, $body, $publication->state($entry)->headVersion);
        $local = $body;
        $local['site']['accent'] = '#123abc';
        $bytes = $entry->codec()->decode($local);
        file_put_contents($target['absolute'], $bytes);
        $inspection = $service->inspect($member, $author);
        // Move only the pin; the inspected working head is unchanged.
        $publication->publishVersion($entry->fresh(), $draft->headVersion);
        file_put_contents($target['absolute'], $bytes);
        $before = $publication->state($entry->fresh());
        try {
            $service->take($member, $author, $this->fileInput($inspection));
            $this->fail('A moved pin was ignored');
        } catch (\Splicewire\Beam\Ux\Files\FileConflict $failure) {
            $this->assertSame('pin-moved', $failure->reason);
            $this->assertEquals($before, $publication->state($entry->fresh()));
            $this->assertSame($bytes, file_get_contents($target['absolute']));
        }
    }

    private function mdxFileFixture(): array
    {
        $directory = $this->ownedFileFixtureRoot().'/mdx';
        mkdir($directory, 0700);
        config(['filesystems.disks.mdx-file' => ['driver' => 'local', 'root' => $directory], 'beam.ux.storage.mirror_disk' => 'mdx-file']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);
        [$releases, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn () => true);
        $entry = BeamUxEntry::create(['slug' => 'file-mdx', 'type' => 'page', 'format' => 'mdx', 'realm' => 'site', 'namespace' => 'help']);
        $body = ['frontmatter' => ['title' => 'Help', 'navOrder' => '2'], 'content' => "\n# Published MDX\n"];
        $publication = app(EntryPublication::class);
        $publication->write($entry, $body);
        $publication->publishAuthoredBody($entry);
        $release = $releases->requestOne($entry->fresh(), $publication->state($entry->fresh())->headVersion, $author);
        $member = $release->members()->sole();
        $target = app(\Splicewire\Beam\Ux\Files\LocalThemeFile::class)->target($entry->fresh());

        return [$entry->fresh(), $member, $author, $body, $target, $publication];
    }

    public function test_missing_mdx_restore_uses_current_pin_not_historical_member_or_pending_head(): void
    {
        [$entry, $historicalMember, $author, $body, $target, $publication] = $this->mdxFileFixture();
        $oldPin = $historicalMember->version_id;
        $body['content'] = "\n# Current published MDX\n";
        $current = $publication->draftFromBase($entry, $body, $publication->state($entry)->headVersion);
        $publication->publishVersion($entry->fresh(), $current->headVersion);
        $pending = $body;
        $pending['content'] = "\n# Pending, never Restore this\n";
        $publication->draftFromBase($entry->fresh(), $pending, $current->headVersion);
        unlink($target['absolute']);
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $inspection = $service->inspect($historicalMember, $author);
        $this->assertTrue($inspection->canRestore);
        $this->assertSame('mdx', $inspection->format);
        $this->assertSame($current->headVersion, $inspection->publishedVersion);
        $before = $publication->state($entry->fresh());
        $result = $service->restore($historicalMember, $author, $this->fileInput($inspection));
        $this->assertSame('restored', $result->status);
        $this->assertSame("---\ntitle: Help\nnavOrder: 2\n---\n\n# Current published MDX\n", file_get_contents($target['absolute']));
        $this->assertEquals($before, $publication->state($entry->fresh()));
        $this->assertSame($oldPin, $historicalMember->fresh()->version_id);
    }

    public function test_missing_mdx_restore_preserves_an_appearing_file_and_short_write(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->mdxFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        unlink($target['absolute']);
        $input = $this->fileInput($service->inspect($member, $author));
        $before = $publication->state($entry->fresh());
        file_put_contents($target['absolute'], 'My external edit');
        $this->assertFileConflict('file-moved', fn () => $service->restore($member, $author, $input));
        $this->assertSame('My external edit', file_get_contents($target['absolute']));
        unlink($target['absolute']);
        $short = new class extends \Splicewire\Beam\Ux\Files\LocalThemeFile
        {
            private int $writes = 0;

            protected function write($stream, string $bytes): int|false
            {
                return $this->writes++ === 0 ? fwrite($stream, substr($bytes, 0, 5)) : 0;
            }
        };
        $failing = new \Splicewire\Beam\Ux\Files\ThemeReconciliation($short, app(\Splicewire\Beam\Ux\Files\CanonicalTheme::class), app(\Splicewire\Beam\Ux\Files\CanonicalMdx::class));
        try {
            $failing->restore($member, $author, $this->fileInput($failing->inspect($member, $author)));
            $this->fail('An incomplete Restore must not report success');
        } catch (\Splicewire\Beam\Ux\Files\FileConflict $failure) {
            $this->assertSame(503, $failure->status);
            $this->assertSame('incomplete', $failure->reason);
        }
        $this->assertSame("---\nt", file_get_contents($target['absolute']));
        $this->assertEquals($before, $publication->state($entry->fresh()));
    }

    public function test_missing_file_restore_is_exclusive_and_preserves_draft_history(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $published = file_get_contents($target['absolute']);
        $body['site']['accent'] = '#abcdef';
        $publication->draftFromBase($entry, $body, $publication->state($entry)->headVersion);
        unlink($target['absolute']);
        $inspection = $service->inspect($member, $author);
        $this->assertSame('missing', $inspection->state);
        $before = $publication->state($entry->fresh());
        $result = $service->restore($member, $author, $this->fileInput($inspection));
        $this->assertSame('restored', $result->status);
        $this->assertEquals($before, $publication->state($entry->fresh()));
        $this->assertSame($published, file_get_contents($target['absolute']));
        unlink($target['absolute']);
        $inspection = $service->inspect($member, $author);
        file_put_contents($target['absolute'], 'later manual bytes');
        try {
            $service->restore($member, $author, $this->fileInput($inspection));
            $this->fail('An appearing file was overwritten');
        } catch (\Splicewire\Beam\Ux\Files\FileConflict $failure) {
            $this->assertSame('file-moved', $failure->reason);
            $this->assertSame('later manual bytes', file_get_contents($target['absolute']));
            $this->assertEquals($before, $publication->state($entry->fresh()));
        }
    }

    public function test_file_invalid_sources_never_create_versions_or_change_pin_file_or_receipt(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $canonical = $entry->codec()->decode($body);
        $dropped = $body;
        unset($dropped['shell']);
        $font = $body;
        $font['canvas']['fontBody'] = '#abcdef';
        $badColor = $body;
        $badColor['site']['accent'] = 'not-color';
        $sources = ['garbage', '', ':root { nope }', $canonical.'/* ignored */',
            str_replace('--theme-site-accent:', '--theme-site-accent: #000; --theme-site-accent:', $canonical),
            $entry->codec()->decode($dropped), $entry->codec()->decode($font), $entry->codec()->decode($badColor),
            $entry->codec()->decode([]), str_repeat('x', 4194305), "\xff\xfe"];
        $before = $publication->state($entry);
        foreach ($sources as $source) {
            file_put_contents($target['absolute'], $source);
            $inspection = $service->inspect($member, $author);
            $this->assertFalse($inspection->canTake);
            // Direct clients cannot bypass the inspected refusal with otherwise current preconditions.
            $input = new \Splicewire\Beam\Ux\Data\FileReconcileInputData($before->headVersion, $before->publishedVersion,
                true, hash('sha256', $source), $target['fingerprint'], true);
            try {
                $service->take($member, $author, $input);
                $this->fail('Invalid import accepted: '.substr(json_encode($source), 0, 240));
            } catch (\Splicewire\Beam\Ux\Files\FileConflict $failure) {
                $this->assertSame(422, $failure->status);
            }
            $this->assertEquals($before, $publication->state($entry->fresh()));
            $this->assertSame($source, file_get_contents($target['absolute']));
        }
    }

    public function test_file_stale_head_hash_placement_and_validation_race_refuse_without_write(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $body['site']['accent'] = '#abcdef';
        file_put_contents($target['absolute'], $entry->codec()->decode($body));
        $inspection = $service->inspect($member, $author);
        $publication->draftFromBase($entry, $body, $inspection->workingHeadVersion);
        $this->assertFileConflict('head-moved', fn () => $service->take($member, $author, $this->fileInput($inspection)));
        $inspection = $service->inspect($member, $author);
        $before = $publication->state($entry->fresh());
        file_put_contents($target['absolute'], 'new bytes');
        $this->assertFileConflict('file-moved', fn () => $service->take($member, $author, $this->fileInput($inspection)));
        file_put_contents($target['absolute'], $entry->codec()->decode($body));
        $input = $this->fileInput($service->inspect($member, $author));
        $input->fingerprint = str_repeat('0', 64);
        $this->assertFileConflict('placement-moved', fn () => $service->take($member, $author, $input));
        $inspection = $service->inspect($member, $author);
        $validator = new class($target['absolute']) extends \Splicewire\Beam\Ux\Files\CanonicalTheme
        {
            public function __construct(private string $path) {}

            public function decode(string $bytes, array $published): array
            {
                $body = parent::decode($bytes, $published);
                file_put_contents($this->path, 'external change during validation');

                return $body;
            }
        };
        $racing = new \Splicewire\Beam\Ux\Files\ThemeReconciliation(app(\Splicewire\Beam\Ux\Files\LocalThemeFile::class), $validator);
        $this->assertFileConflict('file-moved', fn () => $racing->take($member, $author, $this->fileInput($inspection)));
        $this->assertSame('external change during validation', file_get_contents($target['absolute']));
        $this->assertEquals($before, $publication->state($entry->fresh()));
    }

    private function assertFileConflict(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected file conflict '.$reason);
        } catch (\Splicewire\Beam\Ux\Files\FileConflict $failure) {
            $this->assertSame($reason, $failure->reason);
        }
    }

    public function test_file_restore_refuses_actual_exclusive_create_race_and_preserves_short_write(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $before = $publication->state($entry);
        unlink($target['absolute']);
        $base = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $input = $this->fileInput($base->inspect($member, $author));
        $files = new class extends \Splicewire\Beam\Ux\Files\LocalThemeFile
        {
            public function create(array $target, string $bytes): void
            {
                file_put_contents($target['absolute'], 'external file at exclusive create');
                parent::create($target, $bytes);
            }
        };
        $racing = new \Splicewire\Beam\Ux\Files\ThemeReconciliation($files, app(\Splicewire\Beam\Ux\Files\CanonicalTheme::class));
        $this->assertFileConflict('file-moved', fn () => $racing->restore($member, $author, $input));
        $this->assertSame('external file at exclusive create', file_get_contents($target['absolute']));
        unlink($target['absolute']);
        $files = new class extends \Splicewire\Beam\Ux\Files\LocalThemeFile
        {
            private int $writes = 0;

            protected function write($stream, string $bytes): int|false
            {
                return $this->writes++ === 0 ? fwrite($stream, substr($bytes, 0, 7)) : false;
            }
        };
        $broken = new \Splicewire\Beam\Ux\Files\ThemeReconciliation($files, app(\Splicewire\Beam\Ux\Files\CanonicalTheme::class));
        $input = $this->fileInput($base->inspect($member, $author));
        $this->assertFileConflict('incomplete', fn () => $broken->restore($member, $author, $input));
        $partial = file_get_contents($target['absolute']);
        $this->assertSame(7, strlen($partial));
        $this->assertFileConflict('file-moved', fn () => $base->restore($member, $author, $input));
        $this->assertFileConflict('file-exists', fn () => $base->restore($member, $author, $this->fileInput($base->inspect($member, $author))));
        $this->assertSame($partial, file_get_contents($target['absolute']));
        $this->assertEquals($before, $publication->state($entry->fresh()));
    }

    public function test_file_restore_identical_is_explicit_noop_and_symlink_is_not_read(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $before = $publication->state($entry);
        $this->assertSame('unchanged', $service->restore($member, $author, $this->fileInput($service->inspect($member, $author)))->status);
        unlink($target['absolute']);
        $outside = dirname($target['absolute']).'/private.txt';
        file_put_contents($outside, 'private');
        symlink($outside, $target['absolute']);
        $inspection = $service->inspect($member, $author);
        $this->assertSame('unsupported', $inspection->state);
        $this->assertNull($inspection->localSource);
        $this->assertNull($inspection->fileSha256);
        $this->assertSame('private', file_get_contents($outside));
        $this->assertEquals($before, $publication->state($entry->fresh()));
    }

    public function test_file_direct_publisher_without_edit_cannot_inspect_or_import_or_restore(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $input = $this->fileInput($service->inspect($member, $author));
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);
        $before = $publication->state($entry);
        foreach ([fn () => $service->inspect($member, $author), fn () => $service->take($member, $author, $input), fn () => $service->restore($member, $author, $input)] as $operation) {
            try {
                $operation();
                $this->fail('Direct publication granted file edit');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertEquals($before, $publication->state($entry->fresh()));
    }

    public function test_file_path_case_alias_submodule_and_nonregular_refuse_without_source_exposure(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $parent = dirname($target['absolute']);
        $name = basename($target['absolute']);
        $alias = $parent.'/'.strtoupper($name);
        rename($target['absolute'], $alias);
        $this->assertSame('unsupported', $service->inspect($member, $author)->state);
        rename($alias, $target['absolute']);
        unlink($target['absolute']);
        mkdir($target['absolute']);
        $this->assertNull($service->inspect($member, $author)->localSource);
        rmdir($target['absolute']);
        $root = config('filesystems.disks.theme-file.root');
        mkdir($root.'/.git');
        file_put_contents($root.'/.gitmodules', "[submodule \"mirror\"]\npath = ".explode('/', $target['path'])[0]."\n");
        $this->assertSame('unsupported', $service->inspect($member, $author)->state);
    }

    public function test_file_active_unknown_tenancy_and_revoked_author_refuse_before_local_io(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $input = $this->fileInput($service->inspect($member, $author));
        $before = $publication->state($entry);
        foreach (['active', 'unknown', 'throwing'] as $mode) {
            $context = \Mockery::mock(TenancyContext::class);
            if ($mode === 'throwing') {
                $context->shouldReceive('isActive')->andThrow(new \RuntimeException('private tenancy problem'));
            } else {
                $context->shouldReceive('isActive')->andReturn($mode === 'active');
                $context->shouldReceive('isMultiTenant')->andReturn(true);
                $context->shouldReceive('centralConnection')->andReturn(null);
            }
            $this->app->instance(TenancyContext::class, $context);
            foreach ([fn () => $service->inspect($member, $author), fn () => $service->take($member, $author, $input), fn () => $service->restore($member, $author, $input)] as $operation) {
                try {
                    $operation();
                    $this->fail('Unverified tenancy read a file');
                } catch (\Illuminate\Auth\Access\AuthorizationException $failure) {
                    $this->assertStringNotContainsString('private tenancy problem', $failure->getMessage());
                }
            }
        }
        \Illuminate\Support\Facades\Gate::define('ux.author', fn () => false);
        try {
            $service->inspect($member, $author);
            $this->fail('Revoked author inspected a file');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertEquals($before, $publication->state($entry->fresh()));
    }

    public function test_public_file_overlap_conflict_only_translates_sqlite_busy_after_rollback(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $state = $publication->state($entry);
        $count = \Splicewire\Beam\Ux\Models\BeamUxRelease::count();
        $code = 5;
        $entry->getConnection()->listen(function ($query) use (&$code) {
            if (str_starts_with($query->sql, 'insert into "beam_ux_releases"')) {
                $previous = new \PDOException('controlled database fault');
                $previous->errorInfo = ['HY000', $code, 'controlled database fault'];
                throw new \Illuminate\Database\QueryException($query->connectionName, $query->sql, $query->bindings, $previous);
            }
        });
        foreach ([5, 6, 19] as $code) {
            try {
                app(\Splicewire\Beam\Ux\Release\ReleaseService::class)->requestOne($entry->fresh(), $state->headVersion, $author);
                $this->fail('Expected controlled database fault');
            } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $failure) {
                $this->assertContains($code, [5, 6]);
                $this->assertStringContainsString('Reload and explicitly retry', $failure->getMessage());
            } catch (\Illuminate\Database\QueryException) {
                $this->assertSame(19, $code, 'Only unrelated database faults retain their original failure');
            }
            $this->assertSame(0, $entry->getConnection()->transactionLevel());
            $this->assertSame($count, \Splicewire\Beam\Ux\Models\BeamUxRelease::count());
            $this->assertEquals($state, $publication->state($entry->fresh()));
        }
    }

    public function test_canonical_file_retains_extension_and_responsive_shape_and_refuses_ambiguous_keys(): void
    {
        $validator = app(\Splicewire\Beam\Ux\Files\CanonicalTheme::class);
        $codec = new \Splicewire\Beam\Ux\Codec\CssBodyCodec;
        $body = ['site' => ['accent' => '#123456'], 'extension' => ['retained' => 'value'],
            'responsive' => ['breakpoints' => ['wide' => '60rem'], 'tokens' => ['wide' => ['site' => ['accent' => '#111111']]]]];
        $local = $body;
        $local['site']['accent'] = '#abcdef';
        $this->assertSame($local, $validator->decode($codec->decode($local), $body));
        $changed = $local;
        $changed['responsive']['tokens']['wide']['site']['accent'] = '#222222';
        $this->assertFileConflict('unsupported', fn () => $validator->decode($codec->decode($changed), $body));
        $ambiguous = ['site' => ['accent' => '#123456'], 'extension' => ['literal.key' => 'value']];
        $this->assertFileConflict('invalid', fn () => $validator->decode($codec->decode($ambiguous), $ambiguous));
    }

    public function test_file_restore_readback_mismatch_preserves_created_bytes_and_refuses_blind_retry(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $base = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        unlink($target['absolute']);
        $input = $this->fileInput($base->inspect($member, $author));
        $files = new class extends \Splicewire\Beam\Ux\Files\LocalThemeFile
        {
            protected function write($stream, string $bytes): int|false
            {
                return fwrite($stream, str_repeat('x', strlen($bytes)));
            }
        };
        $service = new \Splicewire\Beam\Ux\Files\ThemeReconciliation($files, app(\Splicewire\Beam\Ux\Files\CanonicalTheme::class));
        $before = $publication->state($entry);
        $this->assertFileConflict('incomplete', fn () => $service->restore($member, $author, $input));
        $actual = file_get_contents($target['absolute']);
        $this->assertNotSame('', $actual);
        $this->assertFileConflict('file-moved', fn () => $base->restore($member, $author, $input));
        $this->assertSame($actual, file_get_contents($target['absolute']));
        $this->assertEquals($before, $publication->state($entry->fresh()));
    }

    public function test_file_import_refuses_declared_noncolor_shell_changes_without_effects(): void
    {
        [$entry, $member, $author, $body, $target, $publication] = $this->themeFileFixture();
        $service = app(\Splicewire\Beam\Ux\Files\ThemeReconciliation::class);
        $before = $publication->state($entry);
        foreach (['radius', 'shadow'] as $key) {
            $changed = $body;
            $changed['shell'][$key] = '#abcdef';
            $bytes = $entry->codec()->decode($changed);
            file_put_contents($target['absolute'], $bytes);
            $inspection = $service->inspect($member, $author);
            $this->assertSame('unsupported', $inspection->state, $key.' is a generic string, not a declared color');
            $this->assertFalse($inspection->canTake);
            $this->assertFileConflict('unsupported', fn () => $service->take($member, $author, $this->fileInput($inspection)));
            $this->assertEquals($before, $publication->state($entry->fresh()));
            $this->assertSame($bytes, file_get_contents($target['absolute']));
        }
    }

    /** Disposable repositories only: caller supplies a registered scratch root. */
    private function recordingRepository(): array
    {
        $root = getenv('BEAM_RECORDING_TEST_ROOT');
        if (! is_string($root) || ! is_dir($root)) {
            $this->markTestSkipped('Set BEAM_RECORDING_TEST_ROOT to an owned registered scratch root.');
        }
        $repo = $root.'/repo-'.bin2hex(random_bytes(6));
        mkdir($repo, 0700);
        $git = function (array $args) use ($repo): string {
            $process = new \Symfony\Component\Process\Process(['git', ...$args], $repo);
            $process->mustRun();

            return trim($process->getOutput());
        };
        $git(['init', '-b', 'main']);
        $this->assertSame(realpath($repo), realpath($git(['rev-parse', '--show-toplevel'])));
        $git(['config', 'user.name', 'Release test']);
        $git(['config', 'user.email', 'release@example.invalid']);
        file_put_contents($repo.'/unrelated', "base\n");
        $git(['add', '--', 'unrelated']);
        $git(['commit', '-m', 'baseline']);
        config(['filesystems.disks.recording-test' => ['driver' => 'local', 'root' => $repo, 'throw' => true],
            'beam.ux.storage.mirror_disk' => 'recording-test', 'beam.ux.recording.git' => 'git']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        return [$repo, $git];
    }

    public function test_release_records_exact_member_and_preserves_real_index_then_no_change_republication(): void
    {
        [$repo, $git] = $this->recordingRepository();
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user) => $user->getAuthIdentifier() === 'author');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        file_put_contents($repo.'/unrelated', "staged by developer\n");
        $git(['add', '--', 'unrelated']);
        file_put_contents($repo.'/untracked', 'untouched');
        $index = hash_file('sha256', $repo.'/.git/index');
        $base = $git(['rev-parse', 'HEAD']);
        $state = $this->publishDraftAs($entry, $author);
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $receipt = app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class)->receipt($release);
        $this->assertSame('recorded', $receipt?->state, $receipt?->error ?? '');
        $this->assertSame($git(['rev-parse', 'HEAD']), $receipt->sha);
        $this->assertSame($base, $git(['rev-parse', 'HEAD^']));
        $this->assertSame($index, hash_file('sha256', $repo.'/.git/index'));
        $this->assertSame('untouched', file_get_contents($repo.'/untracked'));
        $path = app(\Splicewire\Beam\Ux\Placement\PlacementResolver::class)->resolve($entry)->pathFor($entry);
        $this->assertSame($path, $git(['diff-tree', '--no-commit-id', '--name-only', '-r', 'HEAD']));
        $blob = new \Symfony\Component\Process\Process(['git', 'show', 'HEAD:'.$path], $repo);
        $blob->mustRun();
        $this->assertSame(file_get_contents($repo.'/'.$path), $blob->getOutput());
        $again = $service->requestOne($entry->fresh(), $state->headVersion, $author);
        $second = app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class)->receipt($again);
        $this->assertSame('recorded', $second?->state, $second?->error ?? '');
        $this->assertTrue($second->no_change);
        $this->assertSame($receipt->sha, $second->sha);
        $this->assertSame('1', $git(['rev-list', '--count', $base.'..HEAD']));
    }

    public function test_failed_hook_keeps_publication_success_and_files_retry_uses_same_receipt(): void
    {
        [$repo, $git] = $this->recordingRepository();
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user) => $user->getAuthIdentifier() === 'author');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        file_put_contents($repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 1\n");
        chmod($repo.'/.git/hooks/pre-commit', 0755);
        $base = $git(['rev-parse', 'HEAD']);
        $state = $this->publishDraftAs($entry, $author);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
        $this->assertSame('published', $state->release->status);
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $recorder = app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class);
        $failed = $recorder->receipt($release);
        $this->assertSame('failed', $failed->state);
        $this->assertSame('git-failed:hook-rejected:1', $failed->error);
        $this->assertSame($base, $git(['rev-parse', 'HEAD']));
        file_put_contents($repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 0\n");
        $recorder->retry($release, $author);
        $recovered = $recorder->receipt($release);
        $this->assertSame($failed->id, $recovered->id);
        $this->assertSame('recorded', $recovered->state, $recovered->error ?? '');
        $recorder->retry($release, $author);
        $this->assertSame($recovered->sha, $recorder->receipt($release)->sha);
        $this->assertSame('1', $git(['rev-list', '--count', $base.'..HEAD']));
    }

    public function test_real_result_database_failure_after_git_install_recovers_same_commit(): void
    {
        [$repo, $git] = $this->recordingRepository();
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user) => $user->getAuthIdentifier() === 'author');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $base = $git(['rev-parse', 'HEAD']);
        $hidden = false;
        \Splicewire\Beam\Ux\Models\BeamUxReleaseRecording::updating(function ($row) use (&$hidden) {
            if (! $hidden && $row->state === 'recorded' && $row->candidate !== null) {
                $row->getConnection()->statement('ALTER TABLE beam_ux_release_recordings RENAME TO recording_store_unavailable');
                $hidden = true;
            }
        });
        $state = $this->publishDraftAs($entry, $author);
        $this->assertTrue($hidden);
        $sha = $git(['rev-parse', 'HEAD']);
        $this->assertNotSame($base, $sha);
        $entry->getConnection()->statement('ALTER TABLE recording_store_unavailable RENAME TO beam_ux_release_recordings');
        \Splicewire\Beam\Ux\Models\BeamUxReleaseRecording::flushEventListeners();
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $recorder = new \Splicewire\Beam\Ux\Recording\ReleaseRecording;
        $pending = $recorder->receipt($release);
        $this->assertSame($sha, $pending->candidate);
        $this->assertNull($pending->sha);
        $recorder->retry($release, $author);
        $this->assertSame($sha, $recorder->receipt($release)->sha);
        $this->assertSame('recorded', $recorder->receipt($release)->state);
        $this->assertSame('1', $git(['rev-list', '--count', $base.'..HEAD']));
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public static function mirrorFailurePositions(): array
    {
        return [[1, false], [2, false], [1, true], [2, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mirrorFailurePositions')]
    public function test_multi_member_false_mirror_cannot_commit_and_does_not_stop_later_callbacks(int $position, bool $throws): void
    {
        [$repo, $git] = $this->recordingRepository();
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user) => $user->getAuthIdentifier() === 'author');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $pub = app(EntryPublication::class);
        $a = $pub->recordDraft($one, self::BODY_TWO);
        $b = $pub->recordDraft($two, self::BODY_TWO);
        $release = $service->create($one, 'Both members', $author);
        $service->putMember($release, $one->id, $a->headVersion, $author);
        $service->putMember($release, $two->id, $b->headVersion, $author);
        $base = $git(['rev-parse', 'HEAD']);
        $this->app->instance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class,
            new class(Storage::disk('recording-test'), $position, $throws) extends \Splicewire\Beam\Ux\Storage\PlacedDiskMirror
            {
                public int $calls = 0;

                public function __construct($disk, private int $position, private bool $throws)
                {
                    parent::__construct($disk);
                }

                public function mirror(BeamUxEntry $entry, string $path, array $body): bool
                {
                    $this->calls++;

                    if ($this->calls === $this->position && $this->throws) {
                        throw new \RuntimeException('owned mirror failure');
                    }

                    return $this->calls === $this->position ? false : parent::mirror($entry, $path, $body);
                }
            });
        $later = false;
        $one->getConnection()->transaction(function () use ($service, $release, $author, $one, &$later) {
            $service->transition($release, 'publish', $author);
            $one->getConnection()->afterCommit(function () use (&$later) {
                $later = true;
            });
        });
        $this->assertTrue($later);
        $this->assertSame('published', $release->fresh()->status);
        $this->assertSame($a->headVersion, $one->fresh()->published_version);
        $this->assertSame($b->headVersion, $two->fresh()->published_version);
        $this->assertSame($base, $git(['rev-parse', 'HEAD']));
        $this->assertSame($throws ? 'completion' : 'mirror', app(\Splicewire\Beam\Ux\Recording\ReleaseRecording::class)->receipt($release)->error);
    }

    public function test_recording_refusals_check_live_authority_and_tenancy_before_any_git_process(): void
    {
        [$repo] = $this->recordingRepository();
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author, $other] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user) => $user->getAuthIdentifier() === 'author');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $state = $this->publishDraftAs($entry, $author);
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $recorder = new \Splicewire\Beam\Ux\Recording\ReleaseRecording;
        $marker = dirname($repo).'/git-refusal-'.bin2hex(random_bytes(4));
        $wrapper = $marker.'.sh';
        file_put_contents($wrapper, "#!/bin/sh\nprintf called >> ".escapeshellarg($marker)."\nexit 1\n");
        chmod($wrapper, 0755);
        config(['beam.ux.recording.git' => $wrapper]);
        try {
            $recorder->retry($release, $other);
            $this->fail('An unauthorized actor reached recording.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertFileDoesNotExist($marker);
        }
        config(['beam.ux.releases.publish_ability' => 'host.publish']);
        \Illuminate\Support\Facades\Gate::define('host.publish', fn () => false);
        try {
            $recorder->retry($release, $author);
            $this->fail('A revoked host publication decision was ignored.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertFileDoesNotExist($marker);
        }
        config(['beam.ux.releases.publish_ability' => null]);
        foreach ([false, true] as $throwing) {
            $this->app->instance(TenancyContext::class, new class($throwing) implements TenancyContext
            {
                public function __construct(private bool $throwing) {}

                public function isMultiTenant(): bool
                {
                    return true;
                }

                public function isActive(): bool
                {
                    if ($this->throwing) {
                        throw new \RuntimeException('unknown topology');
                    }

                    return true;
                }

                public function currentTenant(): ?object
                {
                    return null;
                }

                public function currentTenantId(): int|string|null
                {
                    return 'tenant';
                }

                public function centralConnection(): ?string
                {
                    return 'central';
                }
            });
            $probe = (new BeamUxEntry)->setConnection('central');
            if (! $throwing) {
                $this->assertTrue(app(\Splicewire\Beam\Ux\Manifest\EntryResidency::class)->isCentralResident($probe),
                    'A central-pinned model alone is insufficient while tenancy is active.');
            }
            try {
                $recorder->retry($release, $author);
                $this->fail('Active or unknown tenancy reached Git.');
            } catch (\Splicewire\Beam\Ux\Recording\RecordingFailure $failure) {
                $this->assertSame('tenancy', $failure->reason);
                $this->assertFileDoesNotExist($marker);
            }
        }
        // Exercise model connection residency on a shared disposable SQLite PDO, without
        // pretending this is a physical multi-database/PostgreSQL tenancy proof.
        $pdo = $entry->getConnection()->getPdo();
        \Illuminate\Support\Facades\DB::extend('recording-shared', fn ($config, $name) => new \Illuminate\Database\SQLiteConnection($pdo, ':memory:', '', ['name' => $name, 'driver' => 'sqlite']));
        config(['database.connections.tenant_probe' => ['driver' => 'recording-shared'],
            'database.connections.central_db' => ['driver' => 'recording-shared']]);
        foreach ([null, 'central_db'] as $central) {
            $this->app->instance(TenancyContext::class, new class($central) implements TenancyContext
            {
                public function __construct(private ?string $central) {}

                public function isMultiTenant(): bool
                {
                    return true;
                }

                public function isActive(): bool
                {
                    return false;
                }

                public function currentTenant(): ?object
                {
                    return null;
                }

                public function currentTenantId(): int|string|null
                {
                    return null;
                }

                public function centralConnection(): ?string
                {
                    return $this->central;
                }
            });
            $tenantRelease = \Splicewire\Beam\Ux\Models\BeamUxRelease::on('tenant_probe')->findOrFail($release->id);
            try {
                $recorder->retry($tenantRelease, $author);
                $this->fail('An inactive explicit tenant or dangling split reached Git.');
            } catch (\Splicewire\Beam\Ux\Recording\RecordingFailure $failure) {
                $this->assertSame('tenancy', $failure->reason);
                $this->assertFileDoesNotExist($marker);
            }
        }
        $centralRelease = \Splicewire\Beam\Ux\Models\BeamUxRelease::on('central_db')->findOrFail($release->id);
        $recorder->authorize($centralRelease, $author);
        $this->assertFileDoesNotExist($marker, 'The positive central-residency check itself does not invoke Git.');
    }

    public function test_files_scope_requires_every_member_and_explicit_release_input_rejects_other_membership(): void
    {
        $this->recordingRepository();
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author, $other] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user) => $user->getAuthIdentifier() === 'author');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $state = $this->publishDraftAs($entry, $author);
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $member = $release->members()->first();
        request()->setUserResolver(fn () => $author);
        $this->assertSame(1, \Splicewire\Beam\Ux\Data\ReleaseFilesData::scope(\Splicewire\Beam\Ux\Models\BeamUxReleaseMember::query())->count());
        request()->setUserResolver(fn () => $other);
        $this->assertSame(0, \Splicewire\Beam\Ux\Data\ReleaseFilesData::scope(\Splicewire\Beam\Ux\Models\BeamUxReleaseMember::query())->count());
        request()->setUserResolver(fn () => null);
        $this->assertSame(0, \Splicewire\Beam\Ux\Data\ReleaseFilesData::scope(\Splicewire\Beam\Ux\Models\BeamUxReleaseMember::query())->count());
        try {
            \Splicewire\Beam\Ux\Particle\ReleaseFilesRecordOp::handle($member, Request::create('/', 'POST',
                ['releaseId' => '00000000-0000-4000-8000-000000000001']), $author);
            $this->fail('An unrelated Release ID was accepted.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('releaseId', $failure->errors());
        }
    }

    public function test_outer_rollback_discards_recording_and_a_superseded_completion_cannot_remirror(): void
    {
        [$repo, $git] = $this->recordingRepository();
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user) => $user->getAuthIdentifier() === 'author');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_release_recordings_table.php.stub')->up();
        $pub = app(EntryPublication::class);
        $draft = $pub->recordDraft($entry, self::BODY_TWO);
        $base = $git(['rev-parse', 'HEAD']);
        try {
            $entry->getConnection()->transaction(function () use ($service, $entry, $draft, $author) {
                $service->requestOne($entry, $draft->headVersion, $author);
                throw new \RuntimeException('abort outer');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('abort outer', $error->getMessage());
        }
        $this->assertSame($base, $git(['rev-parse', 'HEAD']));
        $this->assertSame(0, \Splicewire\Beam\Ux\Models\BeamUxReleaseRecording::count());
        $this->assertNotSame($draft->headVersion, $entry->fresh()->published_version);
        $first = $second = null;
        $entry->getConnection()->transaction(function () use ($service, $entry, $draft, $author, $pub, &$first, &$second) {
            $first = $service->requestOne($entry->fresh(), $draft->headVersion, $author);
            $next = $pub->recordDraft($entry->fresh(), self::BODY_ONE);
            $second = $service->requestOne($entry->fresh(), $next->headVersion, $author);
        });
        $recorder = new \Splicewire\Beam\Ux\Recording\ReleaseRecording;
        $this->assertSame('superseded', $recorder->receipt($first)->error);
        $this->assertSame('recorded', $recorder->receipt($second)->state);
        $this->assertSame('1', $git(['rev-list', '--count', $base.'..HEAD']));
    }

    public function test_seeded_unauthored_first_save_pins_visible_baseline_then_reloads_and_publishes_draft(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'seeded-home', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $pub = app(EntryPublication::class);
        $envelope = app(\Splicewire\Beam\Ux\Particle\EntryBodyEnvelope::class)->read($entry);
        $this->assertSame('div', $envelope->body[0]['name']);
        $this->assertNull($pub->state($entry)->headVersion);
        $this->assertNull($entry->fresh()->particle_id, 'reading must not initialize history');
        $request = Request::create('/save-draft', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => null, 'initialBody' => self::BODY_ONE]);
        $state = EntryDraftSaveOp::handle($entry, $request, null);
        $this->assertNotNull($state->publishedVersion);
        $this->assertNotSame($state->headVersion, $state->publishedVersion);
        $this->assertCount(2, $state->versions);
        $this->assertStringContainsString('div', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertStringNotContainsString('One', (string) $this->artifacts()->read($entry->fresh()), 'forged initialBody must never be pinned');
        $reloaded = $pub->state($entry->fresh());
        $this->assertSame($state->headVersion, $reloaded->headVersion);
        $pub->publishVersion($entry->fresh(), $reloaded->headVersion);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        try {
            EntryDraftSaveOp::handle($entry->fresh(), $request, null);
            $this->fail('a racing null-base write overwrote history');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertCount(2, $pub->state($entry->fresh())->versions);
        }
    }

    public function test_history_bearing_missing_pin_is_not_reinitialized_by_editor_save(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $head = $pub->state($entry)->headVersion;
        $entry->forceFill(['published_version' => null])->save();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $pub->draftFromBase($entry, self::BODY_TWO, $head);
    }

    public function test_first_save_preserves_the_server_stored_body_not_a_forged_baseline(): void
    {
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $request = Request::create('/save-draft', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => null, 'initialBody' => self::BODY_TWO]);
        $state = EntryDraftSaveOp::handle($entry, $request, null);
        $this->assertCount(2, $state->versions);
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertStringNotContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_foreign_pin_is_not_accepted_as_a_history_baseline(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $other = $this->publishedPage(self::BODY_TWO);
        $entry->forceFill(['published_version' => $other->published_version])->save();
        $pub = app(EntryPublication::class);
        $before = $pub->state($entry);
        try {
            $pub->draftFromBase($entry, self::BODY_TWO, $before->headVersion);
            $this->fail('foreign pin admitted');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertSame($before->headVersion, $pub->state($entry->fresh())->headVersion);
        }
    }

    public function test_failed_first_save_rolls_back_the_baseline_and_particle(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'atomic-new', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $pub = app(EntryPublication::class);
        PublicationFakeCompiler::$fails = true;
        try {
            $pub->draftFromBase($entry, self::BODY_TWO, null);
            $this->fail('compile refusal expected');
        } catch (CompilationFailed) {
            $this->assertNull($entry->fresh()->particle_id);
            $this->assertNull($entry->fresh()->published_version);
            $this->assertSame([], $pub->state($entry->fresh())->versions);
        } finally {
            PublicationFakeCompiler::$fails = false;
        }
    }

    public function test_refused_edit_after_initialization_leaves_no_pin_or_mirror(): void
    {
        Storage::fake('mirror');
        config(['beam.ux.storage.mirror_disk' => 'mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->bindSplitTenancy();
        $entry = BeamUxEntry::create(['slug' => 'refused-first', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site']);
        $pub = app(EntryPublication::class);
        try {
            $pub->draftFromBase($entry, [[
                'kind' => 'block', 'name' => 'UnknownIsland', 'isComponent' => true,
                'props' => [], 'children' => [], 'dynamic' => false,
            ]], null);
            $this->fail('the edited unknown component must be refused');
        } catch (ValidationException) {
            $this->assertNull($entry->fresh()->particle_id);
            $this->assertNull($entry->fresh()->published_version);
            $this->assertSame([], $pub->state($entry->fresh())->versions);
            $this->assertSame([], Storage::disk('mirror')->allFiles());
        }
    }

    /** A JsonDoc body with one heading — the canvas shape a tsx entry stores. */
    private const BODY_ONE = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'One']],
    ]];

    private const BODY_TWO = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'Two']],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        // Fake disks may sit inside a checkout; dedicated recordingRepository explicitly opts in.
        config(['beam.ux.recording.git' => PHP_BINARY.'-publication-test-no-git']);
        config(['beam.taxonomy.models.tag' => Fixtures\Tag::class]);
        $this->createTables();

        // HasTags detaches on soft delete; the deleted-parent dataset needs the host taxonomy fixture.
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug');
            $table->string('type')->nullable();
            $table->timestamps();
        });
        Schema::create('taggables', function (Blueprint $table) {
            $table->uuid('tag_id');
            $table->uuid('taggable_id');
            $table->string('taggable_type');
        });

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);

        // A compiler with no toolchain: these cases are about WHICH body reaches the artifact and at
        // WHICH address, and a real Node run would make every one of them depend on npm.
        $this->app->singleton(EntryBodyCompiler::class, fn () => new PublicationFakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    public function test_release_batch_publishes_fixed_versions_and_validates_url_swaps_together(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $one->forceFill(['segment' => 'one'])->save();
        $two->forceFill(['segment' => 'two'])->save();
        $pub = app(EntryPublication::class);
        $a = $pub->recordDraft($one, self::BODY_TWO, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'A', segment: 'two'));
        $b = $pub->recordDraft($two, self::BODY_ONE, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'B', segment: 'one'));
        $newer = $pub->recordDraft($one->fresh(), self::BODY_ONE);
        $pub->publishVersions($one, [$one->id => $a->headVersion, $two->id => $b->headVersion]);
        $this->assertSame($a->headVersion, $one->fresh()->published_version);
        $this->assertSame($b->headVersion, $two->fresh()->published_version);
        $this->assertSame($newer->headVersion, $pub->state($one->fresh())->headVersion);
        $this->assertSame('two', $one->fresh()->segment);
        $this->assertSame('one', $two->fresh()->segment);
    }

    public function test_release_requires_an_independent_review_and_publishes_its_fixed_member(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $draft = $pub->recordDraft($entry, self::BODY_TWO);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $release = $service->create($entry, 'Launch', $author);
        $service->putMember($release, $entry->id, $draft->headVersion, $author);
        try {
            $service->transition($release, 'publish', $author);
            $this->fail('review was bypassed');
        } catch (ValidationException) {
            $this->assertNotSame($draft->headVersion, $entry->fresh()->published_version);
        }
        $service->transition($release, 'submit', $author);
        try {
            $service->transition($release, 'approve', $author);
            $this->fail('self-approval was admitted');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame('in_review', $release->fresh()->status);
        }
        $service->transition($release, 'approve', $reviewer);
        $later = $pub->recordDraft($entry->fresh(), self::BODY_ONE);
        $service->transition($release, 'publish', $reviewer);
        $this->assertSame('published', $release->fresh()->status);
        $this->assertSame($draft->headVersion, $entry->fresh()->published_version);
        $this->assertSame($later->headVersion, $pub->state($entry->fresh())->headVersion);
    }

    public function test_release_member_changes_reset_approval_and_take_down_requires_a_published_target(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $release = $service->create($entry, 'Take down', $author);
        try {
            $service->putMember($release, $entry->id, null, $author, '/missing');
            $this->fail('unmanaged take-down was admitted');
        } catch (ValidationException) {
            $this->assertCount(0, $release->fresh()->members);
        }
        app(\Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry::class)->bind('page', \Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle::DEFINITION);
        $entry->forceFill(['workflow_marking' => 'published'])->save();
        try {
            $service->putMember($release, $entry->id, null, $author, '/missing');
            $this->fail('unknown redirect target was admitted');
        } catch (ValidationException) {
            $this->assertCount(0, $release->fresh()->members);
        }
        $target = $this->publishedPage(self::BODY_TWO);
        $target->forceFill(['segment' => '/target', 'workflow_marking' => 'published'])->save();
        $service->putMember($release, $entry->id, null, $author, $target->id);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $service->dropMember($release, $entry->id, $author);
        $this->assertSame('draft', $release->fresh()->status);
        $this->assertNull($release->fresh()->reviewed_by);
    }

    public function test_release_retains_its_artifact_and_rollback_creates_a_new_reviewable_release(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $oldPin = $entry->published_version;
        $pub = app(EntryPublication::class);
        $draft = $pub->recordDraft($entry, self::BODY_TWO);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $release = $service->create($entry, 'Release', $author);
        $service->putMember($release, $entry->id, $draft->headVersion, $author);
        $pub->recordDraft($entry->fresh(), self::BODY_ONE);
        $this->assertTrue($this->artifacts()->disk()->exists($this->artifacts()->path($entry, $this->artifacts()->versionKey($draft->headVersion))));
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $service->transition($release, 'publish', $reviewer);
        $rollback = $service->rollback($release, $author);
        $this->assertNotSame($release->id, $rollback->id);
        $this->assertSame('draft', $rollback->status);
        $this->assertSame($oldPin, $rollback->members->first()->version_id);
        $this->assertSame($draft->headVersion, $entry->fresh()->published_version);
    }

    public function test_editor_publish_and_workflow_take_down_create_reviewed_releases_of_one(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $pin = $entry->fresh()->published_version;
        $request = Request::create('/', 'POST', ['ref' => $pin]);
        $state = EntryPublishOp::handle($entry, $request, $author);
        $this->assertNotNull($state->release);
        $this->assertSame('in_review', $state->release->status);
        $this->assertSame($pin, $entry->fresh()->published_version);
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $service->transition($release, 'approve', $reviewer);
        $service->transition($release, 'publish', $reviewer);
        $this->assertSame('published', $release->fresh()->status);
        app(\Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry::class)->bind('page', \Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle::DEFINITION);
        $entry->forceFill(['workflow_marking' => 'published'])->save();
        $result = \Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp::handle($entry,
            Request::create('/', 'POST', ['transition' => 'unpublish']), $author);
        $response = \Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp::respond($result, $entry);
        $this->assertSame('published', $entry->fresh()->workflow_marking);
        $this->assertSame('in_review', $response->release->status);
        $takeDown = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($response->release->id);
        $service->transition($takeDown, 'approve', $reviewer);
        $service->transition($takeDown, 'publish', $reviewer);
        $this->assertNotSame('published', $entry->fresh()->workflow_marking);
    }

    public function test_release_without_calendars_refuses_schedule_but_can_publish_now(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $release = $service->create($entry, 'Immediate', $author);
        $service->putMember($release, $entry->id, $entry->published_version, $author);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        try {
            $service->schedule($release, '2030-01-01T00:00:00Z', 'UTC', $reviewer);
            $this->fail('schedule without calendars succeeded');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('calendars ActionService', $exception->getMessage());
            $this->assertSame('approved', $release->fresh()->status);
        }
        $service->transition($release, 'publish', $reviewer);
        $this->assertSame('published', $release->fresh()->status);
    }

    public function test_release_resource_and_commands_mount_through_the_existing_particle_surface(): void
    {
        $discovery = app(\Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery::class);
        foreach ([\Splicewire\Beam\Ux\Data\ReleaseData::class, \Splicewire\Beam\Ux\Particle\ReleaseCommandOp::class,
            \Splicewire\Beam\Ux\Particle\EntryReleaseCreateOp::class] as $class) {
            $discovery->registerClass($class);
        }
        \Splicewire\Beam\Facades\Particle::mount('beam-ux-releases', 'beam-ux-release')->only(['index', 'show'])->ops(['command']);
        \Splicewire\Beam\Facades\Particle::ops('beam-ux-entries', 'beam-ux-entry', 'create-release');
        $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();
        $this->assertContains('beam-ux-releases', $routes);
        $this->assertContains('beam-ux-releases/{id}/command', $routes);
        $this->assertContains('beam-ux-entries/{id}/create-release', $routes);
    }

    public function test_release_schedule_uses_the_existing_calendar_runner_and_rejects_a_revised_intent(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $draft = app(EntryPublication::class)->recordDraft($entry, self::BODY_TWO);
        [$service, $author, $reviewer] = $this->releaseFixture();
        $this->calendarFixture();
        $release = $service->create($entry, 'Scheduled', $author);
        $service->putMember($release, $entry->id, $draft->headVersion, $author);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $service->schedule($release, '2030-01-01T00:00:00Z', 'UTC', $reviewer);
        $action = \Splicewire\Beam\Calendars\Models\CalendarAction::firstOrFail();
        $this->assertSame('scheduled', $release->fresh()->status);
        $service->rebase($release, $entry->id, $author);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $attempt = app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($action->id, 'tenant:test', \Carbon\CarbonImmutable::parse('2030-01-02T00:00:00Z'));
        $this->assertSame('blocked', $attempt->status);
        $this->assertSame($entry->published_version, $entry->fresh()->published_version);
        $service->schedule($release, '2030-01-02T00:00:00Z', 'UTC', $reviewer);
        $next = \Splicewire\Beam\Calendars\Models\CalendarAction::whereKeyNot($action->id)->firstOrFail();
        $attempt = app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($next->id, 'tenant:test', \Carbon\CarbonImmutable::parse('2030-01-03T00:00:00Z'));
        $this->assertSame('applied', $attempt->status, json_encode($attempt->result));
        $this->assertSame($draft->headVersion, $entry->fresh()->published_version);
        $this->assertNull(app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($next->id, 'tenant:test', \Carbon\CarbonImmutable::parse('2030-01-03T00:00:00Z')));
    }

    public function test_scheduled_release_rechecks_revoked_member_authority(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $draft = app(EntryPublication::class)->recordDraft($entry, self::BODY_TWO);
        $deny = false;
        [$service, $author, $reviewer] = $this->releaseFixture(function ($actor, $ability) use (&$deny) {
            return ! ($deny && $ability === 'publish');
        });
        $this->calendarFixture();
        $release = $service->create($entry, 'Later', $author);
        $service->putMember($release, $entry->id, $draft->headVersion, $author);
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $service->schedule($release, '2030-01-01T00:00:00Z', 'UTC', $reviewer);
        $deny = true;
        $action = \Splicewire\Beam\Calendars\Models\CalendarAction::firstOrFail();
        $attempt = app(\Splicewire\Beam\Calendars\Actions\ActionScheduler::class)->run($action->id, 'tenant:test', \Carbon\CarbonImmutable::parse('2030-01-02T00:00:00Z'));
        $this->assertSame('blocked', $attempt->status);
        $this->assertSame('scheduled', $release->fresh()->status);
        $this->assertSame($entry->published_version, $entry->fresh()->published_version);
    }

    private function calendarFixture(): void
    {
        $root = dirname((new ReflectionClass(\Splicewire\Beam\Calendars\Actions\ActionService::class))->getFileName(), 3);
        foreach (['create_calendar_actions_table', 'create_calendar_action_attempts_table'] as $name) {
            (require $root.'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        config(['beam.calendars.action_handlers' => ['kind.workflow-transition' => \Splicewire\Beam\Workflows\Calendar\WorkflowActionHandler::class]]);
        $this->app->singleton(\Splicewire\Beam\Calendars\Actions\ActionService::class);
        $this->app->instance(\Splicewire\Beam\Calendars\Contracts\ActionContextProvider::class,
            new class implements \Splicewire\Beam\Calendars\Contracts\ActionContextProvider
            {
                public function current(): \Splicewire\Beam\Calendars\Actions\ActionContext
                {
                    return new \Splicewire\Beam\Calendars\Actions\ActionContext('user:reviewer', 'user:reviewer', 'tenant:test');
                }
            });
        // The host authenticates the tenant context; Release participation still rechecks every member gate.
        $this->app->instance(\Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority::class,
            new class implements \Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority
            {
                public function authorize(\Illuminate\Database\Eloquent\Model $subject, string $transition, \Splicewire\Beam\Workflows\Actions\WorkflowActionContext $context): void {}
            });
    }

    public function test_release_rechecks_publish_authority_on_every_member_and_refuses_atomically(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $pub = app(EntryPublication::class);
        $refs = [$one->id => $pub->recordDraft($one, self::BODY_TWO)->headVersion,
            $two->id => $pub->recordDraft($two, self::BODY_ONE)->headVersion];
        $deny = false;
        [$service, $author, $reviewer] = $this->releaseFixture(function ($actor, $ability, $arguments) use (&$deny, $two) {
            return ! ($deny && $ability === 'publish' && ($arguments[0]->id ?? null) === $two->id);
        });
        $release = $service->create($one, 'Both', $author);
        foreach ($refs as $id => $ref) {
            $service->putMember($release, $id, $ref, $author);
        }
        $service->transition($release, 'submit', $author);
        $service->transition($release, 'approve', $reviewer);
        $deny = true;
        try {
            $service->transition($release, 'publish', $reviewer);
            $this->fail('revoked member authority was ignored');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame('approved', $release->fresh()->status);
            $this->assertSame($one->published_version, $one->fresh()->published_version);
            $this->assertSame($two->published_version, $two->fresh()->published_version);
        }
    }

    private function releaseFixture(?\Closure $authorize = null, bool $reviewedAndMapped = true, bool $standardGuardProvider = false): array
    {
        // Most cases here are about REVIEWED Releases and the abilities a host maps: opt in to review, and map
        // `update` and `publish` so they are checked strictly through the Gate. The owner-ruled defaults (review off,
        // abilities unmapped) pass `$reviewedAndMapped = false`, which also installs no blanket Gate::before, so
        // each of those cases declares its own gates.
        if ($reviewedAndMapped) {
            config([
                'beam.ux.releases.review_required' => true,
                'beam.ux.releases.edit_ability' => 'update',
                'beam.ux.releases.publish_ability' => 'publish',
            ]);
        }
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table'] as $stub) {
            (require __DIR__."/../database/migrations/shared/{$stub}.php.stub")->up();
        }
        $author = new \Illuminate\Foundation\Auth\User;
        $author->setKeyType('string');
        $author->forceFill(['id' => 'author']);
        $reviewer = new \Illuminate\Foundation\Auth\User;
        $reviewer->setKeyType('string');
        $reviewer->forceFill(['id' => 'reviewer']);
        if ($reviewedAndMapped) {
            \Illuminate\Support\Facades\Gate::before($authorize ?? fn () => true);
        }
        $provider = \Mockery::mock(\Illuminate\Contracts\Auth\UserProvider::class);
        $provider->shouldReceive('retrieveById')->andReturnUsing(fn ($id) => $id === 'author' ? $author : ($id === 'reviewer' ? $reviewer : null));
        $guard = \Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
        $guard->shouldReceive('user')->andReturn(null);
        $guard->shouldReceive('id')->andReturn(null);
        if ($standardGuardProvider) {
            config([
                'auth.defaults.guard' => 'web',
                'auth.defaults.provider' => null,
                'auth.guards.web.provider' => 'users',
            ]);
            \Illuminate\Support\Facades\Auth::shouldReceive('createUserProvider')->with('users')->andReturn($provider);
        } else {
            \Illuminate\Support\Facades\Auth::shouldReceive('createUserProvider')->andReturn($provider);
        }
        \Illuminate\Support\Facades\Auth::shouldReceive('guard')->andReturn($guard);

        return [app(\Splicewire\Beam\Ux\Release\ReleaseService::class), $author, $reviewer];
    }

    // ---- The owner ruling (2026-10-18, launch ticket 00 `5dd27af98`): review off by default, and an upgrade never
    // makes Publish answer 403 to an author. Pre-Releases, the editor's Publish was gated by `ux.author` alone, so the
    // fallback is parity with that, not a widening.

    /** A Publish request for the entry's newest draft, through the editor's operation. */
    private function publishDraftAs(BeamUxEntry $entry, \Illuminate\Contracts\Auth\Authenticatable $actor): EntryPublicationData
    {
        $draft = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        return EntryPublishOp::handle($entry->fresh(), Request::create('/', 'POST', ['ref' => $draft->headVersion]), $actor);
    }

    public function test_by_default_a_sole_author_publish_publishes_at_once(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);

        $state = $this->publishDraftAs($entry, $author);

        $this->assertSame('published', $state->release->status);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_a_release_resolves_its_author_from_the_default_guards_provider(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false, standardGuardProvider: true);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);

        $state = $this->publishDraftAs($entry, $author);

        $this->assertSame('published', $state->release->status);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_a_host_that_opts_into_review_holds_a_publish_for_a_second_author(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        config(['beam.ux.releases.review_required' => true]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        $pin = $entry->fresh()->published_version;

        $state = $this->publishDraftAs($entry, $author);

        $this->assertSame('in_review', $state->release->status);
        $this->assertSame($pin, $entry->fresh()->published_version);
    }

    public function test_a_host_that_defines_no_entry_ability_never_refuses_an_author(): void
    {
        // audiostud's shape: a global `update` for its own models only, and no `publish`.
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null, $subject = null) => $subject instanceof BeamParticle);

        $this->assertSame('published', $this->publishDraftAs($entry, $author)->release->status);
    }

    public function test_a_non_author_is_still_refused(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => false);
        $pin = $entry->fresh()->published_version;

        try {
            $this->publishDraftAs($entry, $author);
            $this->fail('A non-author published.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame($pin, $entry->fresh()->published_version);
        }
    }

    public function test_a_declared_entry_policy_that_denies_publish_still_refuses(): void
    {
        // The fallback never overrides a policy the host declared for BeamUxEntry.
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, DenyPublishEntryPolicy::class);
        $pin = $entry->fresh()->published_version;

        try {
            $this->publishDraftAs($entry, $author);
            $this->fail('A declared policy that denies publish was overridden.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame($pin, $entry->fresh()->published_version);
        }
    }

    public function test_a_declared_entry_policy_without_publish_falls_back_to_its_update(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, DenyUpdateEntryPolicy::class);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->publishDraftAs($entry, $author);
    }

    public function test_direct_publish_resolves_one_ability_so_a_policy_that_allows_publish_governs(): void
    {
        // orch-peer HIGH on b23e933: the editor's Publish (a Release of one) must decide on ONE ability, the publish
        // ladder (publish, else update, else ux.author). A host policy with publish=true and update=false publishes;
        // checking edit first would refuse before the specific publish decision could govern.
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);

        $state = $this->publishDraftAs($entry, $author);

        $this->assertSame('published', $state->release->status);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_a_multi_entry_release_still_checks_edit_on_its_member_operations(): void
    {
        // Only the direct Publish takes the single-ability path; building a Release by hand keeps the edit check.
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->create($entry, 'By hand', $author);
    }

    public function test_a_reviewed_direct_release_keeps_the_single_publish_ability_through_approve_and_publish(): void
    {
        // orch-peer / orch-lead 13:42Z: with review opted in, the later approve and publish of a direct Release of one
        // must decide on the same single publish ability; the provenance lives on the Release row, not in process state.
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author, $reviewer] = $this->releaseFixture(reviewedAndMapped: false);
        config(['beam.ux.releases.review_required' => true]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);

        $state = $this->publishDraftAs($entry, $author);
        $this->assertSame('in_review', $state->release->status);
        $release = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $this->assertSame(\Splicewire\Beam\Ux\Release\ReleaseOrigin::Direct, $release->origin);

        // A fresh service, as a later request would resolve it.
        $later = app(\Splicewire\Beam\Ux\Release\ReleaseService::class);
        $later->transition($release, 'approve', $reviewer);
        $later->transition($release, 'publish', $reviewer);

        $this->assertSame('published', $release->fresh()->status);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_direct_and_built_releases_keep_their_own_gates_side_by_side(): void
    {
        // Isolation: the same actor and policy, a direct Release in review next to Releases built by hand. Nothing a
        // direct request does leaks into a built Release, and a member added by hand to a direct Release is an edit.
        $entry = $this->publishedPage(self::BODY_ONE);
        $other = $this->publishedPage(self::BODY_TWO);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        config(['beam.ux.releases.review_required' => true]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, AllowPublishDenyUpdateEntryPolicy::class);

        $direct = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($this->publishDraftAs($entry, $author)->release->id);
        $this->assertSame(\Splicewire\Beam\Ux\Release\ReleaseOrigin::Direct, $direct->origin);

        try {
            $service->create($entry, 'By hand', $author);
            $this->fail('A built Release borrowed the direct gate.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
        }

        try {
            $service->putMember($direct, $other->id, app(EntryPublication::class)->state($other->fresh())->headVersion, $author);
            $this->fail('A member added by hand to a direct Release skipped the edit check.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
        }

        $this->assertSame(1, $direct->members()->count());
        $this->assertSame(1, \Splicewire\Beam\Ux\Models\BeamUxRelease::count());
    }

    public function test_an_emptied_direct_release_refilled_by_hand_is_an_edit(): void
    {
        // orch-peer HIGH on 980e865: a direct Release in review loses its member through a legitimate edit; refilling it
        // by hand is an edit too, so a publisher without `update` is refused. Only requestOne's own first placement
        // takes the publish ladder.
        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture(reviewedAndMapped: false);
        config(['beam.ux.releases.review_required' => true]);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::policy(BeamUxEntry::class, ToggleUpdateEntryPolicy::class);
        ToggleUpdateEntryPolicy::$update = true;

        $state = $this->publishDraftAs($entry, $author);
        $direct = \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail($state->release->id);
        $service->dropMember($direct, $entry->id, $author);
        $this->assertSame(0, $direct->members()->count());

        ToggleUpdateEntryPolicy::$update = false;
        try {
            $service->putMember($direct, $entry->id, $state->headVersion, $author);
            $this->fail('A hand-added member of an emptied direct Release skipped the edit check.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame(0, $direct->members()->count());
        } finally {
            ToggleUpdateEntryPolicy::$update = true;
        }
    }

    public function test_the_origin_alter_reaches_a_releases_table_made_by_the_earlier_create_and_is_idempotent(): void
    {
        // orch-peer HIGH on 980e865: `origin` arrives as its own ALTER stub, because editing the create reaches nothing
        // already migrated. A table from the create alone gains the column, an existing Release reads as built, and a
        // second run changes nothing. (A fresh install runs the same two stubs.)
        Schema::dropIfExists('beam_ux_release_members');
        Schema::dropIfExists('beam_ux_releases');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
        $this->assertFalse(Schema::hasColumn('beam_ux_releases', 'origin'));
        \Illuminate\Support\Facades\DB::table('beam_ux_releases')->insert([
            'id' => '00000000-0000-0000-0000-000000000001', 'title' => 'Earlier', 'realm' => 'site',
            'created_by' => 'user:1', 'edited_by' => 'user:1', 'status' => 'draft',
        ]);

        $alter = __DIR__.'/../database/migrations/shared/add_origin_to_beam_ux_releases_table.php.stub';
        (require $alter)->up();
        (require $alter)->up();

        $this->assertTrue(Schema::hasColumn('beam_ux_releases', 'origin'));
        $this->assertSame(
            \Splicewire\Beam\Ux\Release\ReleaseOrigin::Built,
            \Splicewire\Beam\Ux\Models\BeamUxRelease::findOrFail('00000000-0000-0000-0000-000000000001')->origin,
        );
    }

    public function test_a_caller_cannot_claim_the_direct_origin(): void
    {
        // orch-peer HIGH on 980e865: public create() keeps its three-argument, built-only shape; only requestOne opens a
        // Direct Release, and the stored value is constrained by the ReleaseOrigin cast.
        $create = new ReflectionClass(\Splicewire\Beam\Ux\Release\ReleaseService::class);
        $this->assertCount(3, $create->getMethod('create')->getParameters());
        $this->assertFalse($create->getMethod('open')->isPublic());
        $this->assertFalse($create->getMethod('place')->isPublic());

        $entry = $this->publishedPage(self::BODY_ONE);
        [$service, $author] = $this->releaseFixture();
        $built = $service->create($entry, 'By hand', $author);
        $this->assertSame(\Splicewire\Beam\Ux\Release\ReleaseOrigin::Built, $built->fresh()->origin);

        \Illuminate\Support\Facades\DB::table('beam_ux_releases')->where('id', $built->id)->update(['origin' => 'direct-ish']);
        $this->expectException(\ValueError::class);
        $built->fresh()->origin;
    }

    public function test_save_draft_keeps_its_pre_releases_gate_and_never_consults_an_entry_policy(): void
    {
        // orch-lead 13:14Z: every operation keeps exactly its pre-Releases authorization. At 86d4b98 save-draft was
        // `ux.author`, subject-free (`abilityModel: false`, pinned by
        // test_every_operation_declares_its_shape_slots_and_the_one_entitlement_plane), and its write gate consulted
        // no policy. So an author whom an entry `update` would deny still saves a draft, before and after this change.
        $entry = $this->publishedPage(self::BODY_ONE);
        [, $author] = $this->releaseFixture(reviewedAndMapped: false);
        \Illuminate\Support\Facades\Gate::define('ux.author', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);
        $head = app(EntryPublication::class)->state($entry->fresh())->headVersion;

        $state = EntryDraftSaveOp::handle($entry->fresh(), Request::create('/', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => $head]), $author);

        $this->assertNotSame($head, $state->headVersion);
    }

    public function test_release_batch_compile_failure_keeps_every_pin_and_public_artifact(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $pub = app(EntryPublication::class);
        $before = [$one->published_version, $two->published_version];
        $oldArtifacts = [$this->artifacts()->read($one), $this->artifacts()->read($two)];
        $a = $pub->recordDraft($one, self::BODY_TWO);
        $b = $pub->recordDraft($two, self::BODY_ONE);
        $this->artifacts()->forgetVersion($two, $b->headVersion);
        PublicationFakeCompiler::$fails = true;
        try {
            $pub->publishVersions($one, [$one->id => $a->headVersion, $two->id => $b->headVersion]);
            $this->fail('failed compilation published a Release');
        } catch (CompilationFailed) {
            $this->assertSame($before, [$one->fresh()->published_version, $two->fresh()->published_version]);
            $this->assertSame($oldArtifacts, [$this->artifacts()->read($one->fresh()), $this->artifacts()->read($two->fresh())]);
        } finally {
            PublicationFakeCompiler::$fails = false;
        }
    }

    public function test_release_batch_refuses_a_stale_pin_before_any_member_moves(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $pub = app(EntryPublication::class);
        $pins = [$one->id => $one->published_version, $two->id => $two->published_version];
        $a = $pub->recordDraft($one, self::BODY_TWO);
        $b = $pub->recordDraft($two, self::BODY_ONE);
        $pub->publishVersion($two, $b->headVersion);
        try {
            $pub->publishVersions($one, [$one->id => $a->headVersion, $two->id => $b->headVersion], expectedPins: $pins);
            $this->fail('stale Release was published');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('release', $exception->errors());
            $this->assertSame($pins[$one->id], $one->fresh()->published_version);
            $this->assertSame($b->headVersion, $two->fresh()->published_version);
        }
    }

    public function test_page_settings_are_drafted_with_body_and_only_published_from_the_named_version(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $entry->forceFill(['title' => 'Published title'])->save();
        $publication = app(EntryPublication::class);
        $base = $publication->state($entry)->headVersion;
        $draft = EntryDraftSaveOp::handle($entry, Request::create('/', 'POST', [
            'body' => self::BODY_TWO, 'baseVersion' => $base,
            'pageSettings' => ['title' => 'Draft title', 'parentId' => null, 'segment' => 'draft-page'],
        ]), null);
        $this->assertSame('Published title', $entry->fresh()->title);
        $particle = $entry->fresh()->particle;
        $version = app(\Rushing\Versioning\Contracts\VersionStore::class)->version($particle, $draft->headVersion);
        $this->assertSame('Draft title', $version->snapshot['meta']['pageSettings']['title'] ?? null);
        $publication->publishVersion($entry->fresh(), $draft->headVersion);
        $this->assertSame('Draft title', $entry->fresh()->title);
        $this->assertSame('draft-page', $entry->fresh()->segment);
    }

    public function test_restoring_page_settings_keeps_live_fields_until_publish_and_first_date_is_stable(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $first = $pub->draftFromBase($entry, self::BODY_TWO, $pub->state($entry)->headVersion, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'First', segment: 'first'));
        $pub->publishVersion($entry->fresh(), $first->headVersion);
        $firstDate = $entry->fresh()->first_published_at;
        $this->assertNotNull($firstDate);
        $second = $pub->draftFromBase($entry->fresh(), self::BODY_ONE, $first->headVersion, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'Second', segment: 'second'));
        $pub->publishVersion($entry->fresh(), $second->headVersion);
        $restored = $pub->restore($entry->fresh(), $first->headVersion);
        $this->assertSame('Second', $entry->fresh()->title);
        $pub->publishVersion($entry->fresh(), $restored->headVersion);
        $this->assertSame('First', $entry->fresh()->title);
        $this->assertSame($firstDate, $entry->fresh()->first_published_at);
    }

    public function test_entry_form_cannot_change_a_published_pages_settings_outside_history(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $request = Request::create('/entries/'.$entry->id, 'PATCH');
        $route = new \Illuminate\Routing\Route(['PATCH'], 'entries/{id}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        $input = \Splicewire\Beam\Ux\Data\BeamUxEntryInputData::fromModel($entry);
        $input->title = 'Direct live overwrite';
        $this->expectException(ValidationException::class);
        $input->toModelAttributes();
    }

    public function test_named_settings_publish_under_a_bodyless_canonical_root_does_not_publish_the_root(): void
    {
        $root = BeamUxEntry::rootFor('site')->fresh();
        $before = $root->getAttributes();
        $publication = app(EntryPublication::class);
        $parent = $this->publishedPage(self::BODY_ONE);
        $parent->forceFill(['parent_id' => $root->id, 'segment' => 'docs'])->save();
        $entry = $this->publishedPage(self::BODY_ONE);
        $entry->forceFill(['parent_id' => $parent->id, 'segment' => 'guide', 'title' => 'Old', 'nav_order' => 40])->save();
        $pin = $entry->published_version;
        $draft = $publication->draftFromBase($entry, self::BODY_ONE, $pin, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(
            title: 'Reviewed title', parentId: $parent->id, segment: 'guide', navOrder: 40,
        ));
        $this->assertSame('Old', $entry->fresh()->title);

        $versionsBeforePublish = \DB::table(Beam::table('versions'))->count();
        $published = $publication->publishVersion($entry->fresh(), $draft->headVersion);

        $this->assertSame($versionsBeforePublish, \DB::table(Beam::table('versions'))->count());
        $this->assertSame($draft->headVersion, $published->publishedVersion);
        $this->assertSame('Reviewed title', $entry->fresh()->title);
        $this->assertSame(40, $entry->fresh()->nav_order);
        $this->assertSame(self::BODY_ONE, $entry->fresh()->particle->payload);
        $this->assertSame('/docs/guide', $entry->fresh()->url());
        $this->assertSame($before, $root->fresh()->getAttributes());
        $this->assertNull($root->fresh()->particle_id);
        $this->assertNull($root->fresh()->published_version);
        $this->assertCount(2, $published->versions);
    }

    public function test_url_move_under_canonical_root_still_checks_collisions_and_cycles(): void
    {
        $root = BeamUxEntry::rootFor('site')->fresh();
        $before = $root->getAttributes();
        $entry = $this->publishedPage(self::BODY_ONE);
        $entry->forceFill(['parent_id' => $root->id, 'segment' => 'old'])->save();
        $other = $this->publishedPage(self::BODY_ONE);
        $other->forceFill(['parent_id' => $root->id, 'segment' => '/taken'])->save();
        $check = app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class);
        $check->assertAllowed($entry, [$entry->id => ['parentId' => $root->id, 'segment' => 'new']]);
        foreach ([
            [$entry->id => ['parentId' => $root->id, 'segment' => 'taken']],
            [$root->id => ['parentId' => $entry->id, 'segment' => null]],
        ] as $changes) {
            try {
                $check->assertAllowed($entry, $changes);
                $this->fail('An invalid final graph was admitted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('pageSettings.parentId', $exception->errors());
            }
        }
        $this->assertSame($before, $root->fresh()->getAttributes());
        $this->assertSame('old', $entry->fresh()->segment);
    }

    public static function nonCanonicalRootParents(): array
    {
        return array_map(fn ($case) => [$case], ['ordinary', 'impostor', 'wrong-realm', 'wrong-type', 'hidden', 'unmarked', 'addressable', 'body', 'deleted', 'missing', 'broken-root']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonCanonicalRootParents')]
    public function test_no_pin_parent_admission_is_limited_to_the_canonical_structural_root(string $case): void
    {
        $root = BeamUxEntry::rootFor('site');
        $entry = $this->publishedPage(self::BODY_ONE);
        $parentId = $root->id;
        match ($case) {
            'ordinary' => $root->forceFill(['namespace' => 'content'])->save(),
            'impostor' => $root->forceFill(['slug' => 'not-the-realm'])->save(),
            'wrong-realm' => $root->forceFill(['realm' => 'operator', 'realms' => ['operator'], 'slug' => 'operator'])->save(),
            'wrong-type' => $root->forceFill(['type' => 'component'])->save(),
            'hidden' => $root->forceFill(['workflow_marking' => 'draft'])->save(),
            'unmarked' => $root->forceFill(['workflow_marking' => null])->save(),
            'addressable' => $root->forceFill(['segment' => 'root-content'])->save(),
            'body' => $root->forceFill(['particle_id' => $entry->particle_id])->save(),
            'deleted' => $root->delete(),
            'missing' => $parentId = (string) \Illuminate\Support\Str::uuid(),
            'broken-root' => $root->forceFill(['parent_id' => (string) \Illuminate\Support\Str::uuid()])->save(),
        };
        $before = $entry->fresh()->getAttributes();
        try {
            app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class)->assertAllowed($entry, [
                $entry->id => ['parentId' => $parentId, 'segment' => 'guide'],
            ]);
            $this->fail('An invalid no-pin parent was admitted: '.$case);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pageSettings.parentId', $exception->errors());
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    public function test_canonical_root_exception_respects_the_existing_publish_gate(): void
    {
        $root = BeamUxEntry::rootFor('site');
        $entry = $this->publishedPage(self::BODY_ONE);
        $this->app->instance(\Splicewire\Beam\Ux\Sitemap\EntryPublishGate::class, new class implements \Splicewire\Beam\Ux\Sitemap\EntryPublishGate
        {
            public function isPublished(BeamUxEntry $entry): bool
            {
                return false;
            }
        });
        $this->expectException(ValidationException::class);
        app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class)->assertAllowed($entry, [
            $entry->id => ['parentId' => $root->id, 'segment' => 'guide'],
        ]);
    }

    public function test_canonical_root_is_found_only_on_the_entry_connection(): void
    {
        config(['database.connections.root_elsewhere' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->createTables('root_elsewhere');
        $foreign = BeamUxEntry::on('root_elsewhere')->create([
            'namespace' => 'realms', 'slug' => 'site', 'realm' => 'site', 'realms' => ['site'],
            'type' => 'page', 'workflow_marking' => BeamUxEntry::MARKING_PUBLISHED,
        ]);
        $entry = $this->publishedPage(self::BODY_ONE);
        $this->expectException(ValidationException::class);
        app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class)->assertAllowed($entry, [
            $entry->id => ['parentId' => $foreign->id, 'segment' => 'guide'],
        ]);
    }

    public function test_batch_url_check_accepts_a_swap_but_refuses_a_collision(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        $one->forceFill(['segment' => 'one'])->save();
        $two->forceFill(['segment' => 'two'])->save();
        $check = app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class);
        $check->assertAllowed($one, [
            $one->id => ['parentId' => null, 'segment' => 'two'],
            $two->id => ['parentId' => null, 'segment' => 'one'],
        ]);
        $this->assertSame('one', $one->fresh()->segment);
        $this->expectException(ValidationException::class);
        $check->assertAllowed($one, [$one->id => ['parentId' => null, 'segment' => 'two']]);
    }

    public function test_batch_url_check_refuses_an_unpublished_parent(): void
    {
        $child = $this->publishedPage(self::BODY_ONE);
        $parent = $this->pageWithUnversionedBody(self::BODY_TWO);
        $this->expectException(ValidationException::class);
        app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class)->assertAllowed($child, [
            $child->id => ['parentId' => $parent->id, 'segment' => 'child'],
        ]);
    }

    public function test_batch_includes_a_new_parent_and_uses_the_existing_absolute_url_grammar(): void
    {
        $parent = $this->pageWithUnversionedBody(self::BODY_ONE);
        $child = $this->publishedPage(self::BODY_TWO);
        $other = $this->publishedPage(self::BODY_ONE);
        $other->forceFill(['segment' => '/taken'])->save();
        $check = app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class);
        $check->assertAllowed($child, [
            $parent->id => ['parentId' => null, 'segment' => 'parent'],
            $child->id => ['parentId' => $parent->id, 'segment' => './child'],
        ]);
        $this->expectException(ValidationException::class);
        $check->assertAllowed($child, [
            $parent->id => ['parentId' => null, 'segment' => 'parent'],
            $child->id => ['parentId' => $parent->id, 'segment' => '/taken'],
        ]);
    }

    public function test_batch_refuses_a_cycle_without_mutating_rows(): void
    {
        $one = $this->publishedPage(self::BODY_ONE);
        $two = $this->publishedPage(self::BODY_TWO);
        try {
            app(\Splicewire\Beam\Ux\Publish\EntryUrlChanges::class)->assertAllowed($one, [
                $one->id => ['parentId' => $two->id, 'segment' => 'one'],
                $two->id => ['parentId' => $one->id, 'segment' => 'two'],
            ]);
            $this->fail('cycle was admitted');
        } catch (ValidationException) {
            $this->assertNull($one->fresh()->parent_id);
            $this->assertNull($two->fresh()->parent_id);
        }
    }

    public function test_body_only_save_preserves_the_working_page_settings(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $first = $pub->draftFromBase($entry, self::BODY_TWO, $pub->state($entry)->headVersion, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'Draft', segment: 'draft'));
        $next = $pub->recordDraft($entry->fresh(), self::BODY_ONE);
        $pub->publishVersion($entry->fresh(), $next->headVersion);
        $this->assertSame('Draft', $entry->fresh()->title);
        $this->assertSame('draft', $entry->fresh()->segment);
    }

    public function test_invalid_page_settings_refuse_before_recording_a_draft(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pub = app(EntryPublication::class);
        $before = $pub->state($entry)->headVersion;
        try {
            EntryDraftSaveOp::handle($entry, Request::create('/', 'POST', [
                'body' => self::BODY_TWO, 'baseVersion' => $before,
                'pageSettings' => ['title' => 'Page', 'pageType' => 'InventedType'],
            ]), null);
            $this->fail('invalid page type was accepted');
        } catch (ValidationException) {
            $this->assertSame($before, $pub->state($entry->fresh())->headVersion);
        }
    }

    public function test_page_settings_on_a_component_refuse_before_any_write(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $entry->type = UxType::Component;
        $entry->save();
        $publication = app(EntryPublication::class);
        $before = $publication->state($entry)->headVersion;
        try {
            $publication->draftFromBase($entry, self::BODY_TWO, $before, pageSettings: new \Splicewire\Beam\Ux\Data\EntryPageSettingsData(title: 'Not a page'));
            $this->fail('component accepted page settings');
        } catch (ValidationException) {
            $this->assertSame($before, $publication->state($entry->fresh())->headVersion);
        }
    }

    public function test_editor_draft_refuses_a_stale_base_before_any_write(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $base = $publication->state($entry)->headVersion;
        $first = EntryDraftSaveOp::handle($entry, Request::create('/', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => $base]), null);
        $this->assertNotSame($base, $first->headVersion);
        try {
            EntryDraftSaveOp::handle($entry->fresh(), Request::create('/', 'POST', ['body' => self::BODY_ONE, 'baseVersion' => $base]), null);
            $this->fail('stale editor save was accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            $this->assertStringContainsString('base', $e->getMessage());
        }
        $this->assertSame($first->headVersion, $publication->state($entry->fresh())->headVersion);
        $this->assertCount(2, $publication->state($entry->fresh())->versions);
        $this->assertSame(self::BODY_TWO, $entry->fresh()->particle->payload);
    }

    public function test_next_author_write_migrates_legacy_style_to_the_versioned_block_field(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $body = self::BODY_ONE;
        $body[0]['props'] = [['name' => 'style', 'kind' => 'string', 'value' => 'color:red']];
        $written = app(EntryPublication::class)->write($entry, $body);
        $this->assertSame(['color' => 'red'], $written['body'][0]['style']['layers'][0]['declarations']);
        $this->assertSame([], $written['body'][0]['props']);
    }

    public function test_named_publish_pins_the_seen_version_without_replacing_a_newer_working_head(): void
    {
        [, $author] = $this->releaseFixture();
        config(['beam.ux.releases.review_required' => false]);
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $seen = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $later = $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $published = EntryPublishOp::handle($entry->fresh(), Request::create('/', 'POST', ['ref' => $seen->headVersion]), $author);
        $this->assertSame($seen->headVersion, $published->publishedVersion);
        $this->assertSame($later->headVersion, $published->headVersion);
        $this->assertTrue($published->draftPending);
        $this->assertFalse($published->release->members[0]->stale, 'A Release is not stale after its own successful pin move.');
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame(self::BODY_ONE, $entry->fresh()->particle->payload);
    }

    public function test_ep10_save_compiles_its_version_and_preserves_the_public_artifact(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);
        $draft = clone $entry->fresh();
        $draft->published_version = $state->headVersion;
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($draft));
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_ep10_prunes_only_obsolete_artifacts_and_restore_recompiles_its_draft(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $first = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $firstPath = $this->artifacts()->path($entry, $this->artifacts()->versionKey($first->headVersion));
        $this->assertTrue($this->artifacts()->disk()->exists($firstPath));
        $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $this->assertFalse($this->artifacts()->disk()->exists($firstPath));
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));

        $restored = $publication->restore($entry->fresh(), $first->headVersion);
        $restoredPath = $this->artifacts()->path($entry, $this->artifacts()->versionKey($restored->headVersion));
        $this->assertStringContainsString('Two', $this->artifacts()->disk()->get($restoredPath));
        $this->assertSame($first->publishedVersion, $restored->publishedVersion);
        $this->assertTrue($restored->draftPending);
    }

    public function test_ep10_publish_of_missing_artifact_compiles_the_named_snapshot_not_the_head(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $first = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $latest = $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $state = $publication->publishVersion($entry->fresh(), $first->headVersion);
        $this->assertSame($first->headVersion, $state->publishedVersion);
        $this->assertSame($latest->headVersion, $state->headVersion);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame(self::BODY_ONE, BeamParticle::find($entry->particle_id)->payload);
    }

    public function test_ep10_backfill_uses_the_pin_snapshot_and_keeps_the_draft_artifact(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);
        app(CompileEntryBody::class)->forEntry($entry->fresh(), force: true);
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $draftPath = $this->artifacts()->path($entry, $this->artifacts()->versionKey($state->headVersion));
        $this->assertStringContainsString('Two', $this->artifacts()->disk()->get($draftPath));
    }

    public function test_ep10_programmatic_save_retains_body_but_does_not_publish_a_failed_compile(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pin = $entry->published_version;
        PublicationFakeCompiler::$fails = true;
        try {
            try {
                EntryBodySaveOp::handle($entry->fresh(), Request::create('/', 'POST', ['body' => self::BODY_TWO]), null);
                $this->fail('Programmatic save published an uncompilable version.');
            } catch (CompilationFailed $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertSame(self::BODY_TWO, BeamParticle::find($entry->particle_id)->payload);
            $this->assertSame($pin, $entry->fresh()->published_version);
            $this->assertTrue(app(EntryPublication::class)->state($entry->fresh())->draftPending);
        } finally {
            PublicationFakeCompiler::$fails = false;
        }
    }

    public function test_ep10_failed_draft_compile_keeps_the_draft_and_publish_refuses(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $pin = $entry->published_version;
        $publication = app(EntryPublication::class);
        PublicationFakeCompiler::$fails = true;
        try {
            $state = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
            $this->assertNotNull($state->compileError);
            $this->assertTrue($state->draftPending);
            $this->assertSame(self::BODY_TWO, BeamParticle::find($entry->particle_id)->payload);
            try {
                $publication->publishVersion($entry->fresh(), $state->headVersion);
                $this->fail('Publish accepted an uncompilable version');
            } catch (CompilationFailed $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertSame($pin, $entry->fresh()->published_version);
            $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        } finally {
            PublicationFakeCompiler::$fails = false;
        }
    }

    public function test_a_draft_is_recorded_as_a_version_and_the_reader_keeps_the_published_body(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publishedPath = $this->artifacts()->path($entry->fresh());

        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO, 'my draft');

        $this->assertTrue($state->draftPending, 'the two pins agree after a draft');
        $this->assertNotSame($state->headVersion, $state->publishedVersion);
        $this->assertCount(2, $state->versions);
        $this->assertSame('my draft', $state->versions[0]->label);
        $this->assertTrue($state->versions[0]->isHead);
        $this->assertFalse($state->versions[0]->isHead && $state->versions[0]->isPublished);

        // The reader's whole answer: same address, same module, and it is the PREVIOUS body.
        $fresh = $entry->fresh();
        $this->assertSame($publishedPath, $this->artifacts()->path($fresh), 'a draft moved the public artifact address');
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($fresh));
        $this->assertStringNotContainsString('Two', (string) $this->artifacts()->read($fresh));
    }

    public function test_publishing_moves_the_pin_and_compiles_the_drafted_body_at_its_new_address(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);

        $state = $publication->publish($entry->fresh());

        $this->assertFalse($state->draftPending);
        $this->assertSame($state->headVersion, $state->publishedVersion, 'publish pins the working HEAD');
        $this->assertNull($state->compileError);
        // A publish straight after a draft pins THAT draft rather than minting a twin of it.
        $this->assertCount(2, $state->versions);

        $fresh = $entry->fresh();
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($fresh));
    }

    public function test_the_first_draft_baselines_the_already_live_body_so_it_stays_restorable(): void
    {
        // The case every entry authored before this path is in: a body, an artifact, and no history.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $this->assertSame(0, $this->versionRows($entry));

        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        $this->assertCount(2, $state->versions, 'the live body was not recorded before the draft overwrote it');
        $baseline = $state->versions[1];
        $this->assertSame('baseline', $baseline->label);
        $this->assertTrue($baseline->isPublished, 'the baseline is what the reader is on');
        // And the reader is still reading it, at the address the pin now names.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_baseline_that_fails_after_its_snapshot_rolls_the_snapshot_back_and_a_retry_pins(): void
    {
        // review-r1 R2 (seeded pages, 2026-10-16): baseline() snapshotted (committing a version and HEAD), THEN saved the
        // pin. A failure in between left a history-bearing, pin-less row, which every later baseline() treats as an
        // unpublished draft and skips, forever. The failure here lands on the pin save, AFTER the snapshot.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });

        try {
            app(EntryPublication::class)->baseline($entry->fresh());
            $this->fail('a failed pin save was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertSame(0, $this->versionRows($entry), 'the snapshot outlived the failed pin: the row now reads as a draft');
        $this->assertNull($entry->fresh()->published_version);

        // The retry is not a no-op: the row still has no history, so baseline() pins it.
        app(EntryPublication::class)->baseline($entry->fresh());

        $this->assertNotNull($entry->fresh()->published_version, 'a retry could not repair the row');
        $this->assertSame(1, $this->versionRows($entry));
    }

    public function test_a_central_entry_under_a_tenant_default_rolls_its_snapshot_back_with_a_failed_pin_and_a_retry_pins(): void
    {
        // review-r1 R3 (2026-10-16): the supported central-entry shape, where EntryPromoter keeps the entry and its
        // particle on `central` while the default connection is a tenant's. baseline()'s transaction is on the entry's
        // connection; the version store wrote the version on the DEFAULT one, outside it, so a failed pin kept the
        // version and the row read as a draft forever. Needs rushing/laravel-versioning with record-connection versions.
        config(['database.connections.central' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->createTables('central');

        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        // Built the way EntryPromoter builds one: the particle written with its own central connection, then the entry.
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(
            (new BeamParticle)->setConnection('central'),
            self::BODY_ONE,
        );
        $entry = BeamUxEntry::on('central')->create(['slug' => 'central-home', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => $particle->id]);
        $this->assertSame('central', $entry->getConnectionName());
        $this->assertSame(1, \DB::connection('central')->table(Beam::table('particles'))->count(), 'the body left the entry connection');

        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });

        try {
            app(EntryPublication::class)->baseline($entry);
            $this->fail('a failed pin save was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertSame(0, \DB::connection('central')->table(Beam::table('versions'))->count(), 'the snapshot outlived the failed pin on central');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'the snapshot outlived the failed pin on the tenant default');
        $this->assertNull($entry->fresh()->published_version);

        app(EntryPublication::class)->baseline($entry->fresh());

        $this->assertNotNull($entry->fresh()->published_version, 'a retry could not repair the central row');
        $this->assertSame(1, \DB::connection('central')->table(Beam::table('versions'))->count());
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count());
    }

    public function test_restoring_rolls_forward_into_a_draft_and_the_reader_keeps_the_published_body(): void
    {
        // ADR-0225 decision 5: Restore applies the frozen snapshot to the working copy and mints a new version, and does
        // NOT publish. Rolling back the live page takes Restore and then Publish (review-r1, launch L3, 2026-10-16).
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $published = $publication->publish($entry->fresh());
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));

        $first = $published->versions[array_key_last($published->versions)];

        $state = $publication->restore($entry->fresh(), $first->readable);

        // FORWARD: the history grew, the restored version is still in it, and HEAD is the new row: a DRAFT.
        $this->assertCount(3, $state->versions);
        $this->assertSame("restore of {$first->readable}", $state->versions[0]->label);
        $this->assertTrue($state->versions[0]->isHead);
        $this->assertFalse($state->versions[0]->isPublished, 'a restore published the restored body');
        $this->assertTrue($state->draftPending);
        $this->assertSame($published->publishedVersion, $state->publishedVersion, 'a restore moved the publication pin');

        // The reader still gets the published body; the working copy is the restored one.
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertStringNotContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame(self::BODY_ONE, BeamParticle::find($entry->fresh()->particle_id)->payload);

        // Publish is what moves the reader.
        $publication->publish($entry->fresh());
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertStringNotContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_publishing_a_restored_empty_document_retires_the_artifact_rather_than_serving_an_empty_module(): void
    {
        // beam-ux d033815: a cleared document is no body, so the reader must read UNAUTHORED (no
        // artifact at any address) rather than be handed a module that exports nothing. Restoring an empty
        // version only drafts it (ADR-0225 decision 5); PUBLISHING that draft is what retires the artifact,
        // and it must land in the same place as publishing a cleared body directly.
        $entry = $this->publishedPage([]);
        $publication = app(EntryPublication::class);
        $cleared = $publication->publish($entry->fresh());
        $empty = $cleared->versions[array_key_last($cleared->versions)];

        $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $publication->publish($entry->fresh());
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));

        $publication->restore($entry->fresh(), $empty->readable);

        // ADR-0225 decision 5: the restore is a draft, so the reader keeps the published body until Publish.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));

        $publication->publish($entry->fresh());

        $this->assertNull($this->artifacts()->read($entry->fresh()), 'an empty restore, published, left a module behind');
    }

    public function test_an_immediate_save_still_publishes_and_now_records_the_history_it_never_had(): void
    {
        // The contract `g2-beam-author-entry` proves: a save through this op is live when it returns.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);

        $payload = EntryBodySaveOp::handle($entry, $this->saveRequest(self::BODY_TWO), actor: null);

        $this->assertNull($payload['compileError']);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));

        $state = app(EntryPublication::class)->state($entry->fresh());
        $this->assertFalse($state->draftPending, 'an immediate save left a draft pending');
        // Two: the body that was live before the save (baselined), and the one it wrote.
        $this->assertCount(2, $state->versions);
        $this->assertTrue($state->versions[0]->isPublished);
    }

    public function test_a_ref_that_names_no_version_of_this_entry_is_a_422_on_ref(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);

        try {
            app(EntryPublication::class)->restore($entry->fresh(), 'v99');
            $this->fail('an unknown ref restored something');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ref', $e->errors());
        }

        // And nothing moved: the reader is where they were.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_draft_refuses_a_canvas_document_on_a_format_that_cannot_carry_one(): void
    {
        // The mdx defect (G2-BEAM-AUTHOR-ENTRY, 2026-09-11) reached through the draft door would land
        // the damage at draft time and surface it at publish time, which is strictly worse.
        $entry = BeamUxEntry::create(['slug' => 'docs', 'type' => UxType::Page->value, 'format' => 'mdx']);

        try {
            app(EntryPublication::class)->recordDraft($entry, self::BODY_ONE);
            $this->fail('a canvas document was drafted onto an mdx entry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
            $this->assertStringContainsString('mdx', $e->errors()['body'][0]);
        }

        $this->assertNull($entry->fresh()->particle_id, 'the refused draft still bound a particle');
    }

    public function test_publish_reuses_the_saved_artifact_without_invoking_a_now_failing_compiler(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        PublicationFakeCompiler::$fails = true;

        try {
            $state = app(EntryPublication::class)->publish($entry->fresh());
        } finally {
            PublicationFakeCompiler::$fails = false;
        }

        $this->assertNull($state->compileError);
        $this->assertFalse($state->draftPending);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_the_artifact_address_follows_the_publication_pin_and_not_the_working_head(): void
    {
        // The mechanism the reader-visible cases above all rest on, asserted directly: the address is
        // the PUBLISHED version, so a moving HEAD cannot move it.
        $entry = $this->publishedPage(self::BODY_ONE);
        $pinned = $this->artifacts()->path($entry->fresh());

        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        $fresh = $entry->fresh();
        $this->assertNotNull($fresh->getAttribute('published_version'));
        $this->assertNotSame(
            (string) $fresh->getAttribute('published_version'),
            (string) $fresh->particle->getAttribute('head_version'),
            'HEAD did not move away from the pin, so this case proves nothing',
        );
        $this->assertSame($pinned, $this->artifacts()->path($fresh));
    }

    public function test_an_entry_with_no_pin_addresses_exactly_as_it_did_before_the_pin_existed(): void
    {
        // The degrade path: a host that has not migrated the column, or an entry never taken through
        // this door, must key on the particle write as it always did.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);

        $this->assertNull($entry->fresh()->getAttribute('published_version'));

        $expected = substr(hash('xxh128', '3:'.$entry->fresh()->particle->getAttribute('updated_at')->format('U.u')), 0, 16);
        $this->assertSame($expected, $this->artifacts()->version($entry->fresh()));
    }

    public function test_every_operation_declares_its_shape_slots_and_the_one_entitlement_plane(): void
    {
        $expected = [
            EntryDraftSaveOp::class => ['save-draft', OperationKind::Write],
            EntryPublishOp::class => ['publish', OperationKind::Write],
            EntryVersionsShowOp::class => ['versions', OperationKind::Read],
            EntryVersionRestoreOp::class => ['restore', OperationKind::Write],
        ];

        foreach ($expected as $class => [$name, $kind]) {
            $op = (new ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();

            $this->assertSame('beam-ux-entry', $op->resource, $class);
            $this->assertSame($name, $op->name, $class);
            $this->assertSame($kind, $op->kind, $class);
            // One plane for the whole family, subject-free (particle-operation-surface ticket 08): an
            // author who can publish but not list, or draft but not publish, has half an editor.
            $this->assertSame('ux.author', $op->ability, $class);
            $this->assertFalse($op->abilityModel, $class);
            $this->assertSame(EntryPublicationData::class, $op->output, $class);
        }
    }

    public function test_the_versions_read_projects_the_same_state_the_writes_return(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO, 'wip');

        $state = EntryVersionsShowOp::handle($entry->fresh(), new Request, actor: null);

        $this->assertInstanceOf(EntryPublicationData::class, $state);
        $this->assertTrue($state->draftPending);
        $this->assertSame('wip', $state->versions[0]->label);
        $this->assertSame((string) $entry->getKey(), $state->id);
    }

    public function test_the_publish_and_draft_operations_round_trip_through_their_declared_inputs(): void
    {
        [, $author] = $this->releaseFixture();
        config(['beam.ux.releases.review_required' => false]);
        $entry = $this->publishedPage(self::BODY_ONE);

        $drafted = EntryDraftSaveOp::handle(
            $entry->fresh(),
            Request::create('/x', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => app(EntryPublication::class)->state($entry->fresh())->headVersion, 'label' => 'from the op']),
            actor: null,
        );
        $this->assertInstanceOf(EntryPublicationData::class, $drafted);
        $this->assertTrue($drafted->draftPending);
        $this->assertSame('from the op', $drafted->versions[0]->label);

        $published = EntryPublishOp::handle($entry->fresh(), Request::create('/x', 'POST', ['ref' => $drafted->headVersion]), actor: $author);
        $this->assertFalse($published->draftPending);

        $restored = EntryVersionRestoreOp::handle(
            $entry->fresh(),
            Request::create('/x', 'POST', ['ref' => $published->versions[array_key_last($published->versions)]->readable]),
            actor: null,
        );
        // ADR-0225 decision 5: a draft; the reader keeps the published body (Two).
        $this->assertTrue($restored->draftPending);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_host_without_the_version_store_refuses_to_publish_and_names_the_fix(): void
    {
        // ADR-0225 decision 2: the pin is required wherever the editor is mounted. The pin-less branch
        // that used to mirror and compile at the unpinned address is gone; a save-body or publish on a
        // host missing the store refuses, naming both missing halves and the command that fixes it.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $artifactBefore = $this->artifacts()->read($entry->fresh());
        Schema::drop(Beam::table('versions'));

        try {
            EntryBodySaveOp::handle($entry->fresh(), $this->saveRequest(self::BODY_TWO), actor: null);
            $this->fail('save-body published pin-less on a host without the version store');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/beam-ux-migrations/', $e->getMessage());
            $this->assertMatchesRegularExpression('/beam_versions|versions\] table/', $e->getMessage());
        }
        // Refused BEFORE the write: the working copy is still the body that was live.
        $this->assertSame(self::BODY_ONE, $entry->fresh()->particle->getAttribute('payload'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/vendor:publish/');
        try {
            app(EntryPublication::class)->publish($entry->fresh());
        } finally {
            // Nothing moved: the reader keeps exactly the artifact it had.
            $this->assertSame($artifactBefore, $this->artifacts()->read($entry->fresh()));
            $this->assertNull($entry->fresh()->getAttribute('published_version'));
        }
    }

    private function artifacts(): EntryArtifactStore
    {
        return app(EntryArtifactStore::class);
    }

    /** An entry with a body and an artifact, already taken through the publish path once. */
    private function publishedPage(array $body): BeamUxEntry
    {
        $entry = $this->pageWithUnversionedBody($body);

        app(EntryPublication::class)->publish($entry->fresh());

        return $entry->fresh();
    }

    private function bindSplitTenancy(string $central = 'central_db'): void
    {
        // The entry stays on the default (testing) connection, which is not this central connection, so
        // it is tenant-resident while its DB ops still work.
        $this->app->instance(TenancyContext::class, new class($central) implements TenancyContext
        {
            public function __construct(private string $central) {}

            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function currentTenant(): ?object
            {
                return null;
            }

            public function currentTenantId(): int|string|null
            {
                return 1;
            }

            public function centralConnection(): ?string
            {
                return $this->central;
            }
        });
    }

    /**
     * review-r1, EP-07a boundary-fix re-review (2026-10-17): Publish guards the working copy, which it must read
     * on the ENTRY's connection. Read on the default one, a tenant entry on its own connection showed the guard
     * nothing (`[]`), passed, and the real body was pinned and compiled unchecked.
     */
    public function test_publish_guards_the_working_copy_on_the_entrys_own_connection(): void
    {
        config(['database.connections.tenant_acme' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->createTables('tenant_acme');
        $this->bindSplitTenancy();

        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        // Code written straight to the particle, as history from before the save check, on the tenant's connection.
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(
            (new BeamParticle)->setConnection('tenant_acme'),
            [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']],
        );
        $entry = BeamUxEntry::on('tenant_acme')->create(['slug' => 'tenant-home', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => $particle->id]);
        $this->assertSame(0, \DB::table(Beam::table('particles'))->count(), 'the default connection must hold no body, or the probe proves nothing');

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('publish pinned a tenant body it never checked');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('opaque', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version);
        $this->assertSame(0, \DB::connection('tenant_acme')->table(Beam::table('versions'))->count(), 'a refused publish recorded a version');
    }

    /**
     * review-r1 R2 (2026-10-17): publish() baselined first, and on an entry with no history baseline() pins,
     * mirrors and compiles the working copy itself, so code was published before the guard refused it. The
     * guard now runs before any write: a refused first publish leaves history, pin, artifact and mirror as they were.
     */
    public function test_a_refused_first_publish_writes_nothing(): void
    {
        Storage::fake('mirror');
        config(['beam.ux.storage.mirror_disk' => 'mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        // History-less, as a body written before the save check existed; then the entry is tenant-resident.
        $entry = BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(new BeamParticle, [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']]);
        $entry->forceFill(['particle_id' => $particle->id])->save();
        $this->bindSplitTenancy();
        $artifactsBefore = Storage::disk('artifacts')->allFiles();

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('a code-bearing first publish was not refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('opaque', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version, 'the pin moved');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'history was written');
        $this->assertSame($artifactsBefore, Storage::disk('artifacts')->allFiles(), 'an artifact was compiled');
        $this->assertSame([], Storage::disk('mirror')->allFiles(), 'the body was mirrored');
    }

    /** The legality half of the same order: an illegal history-less body is checked against nothing published, not itself. */
    public function test_a_first_publish_of_an_illegal_body_is_refused(): void
    {
        Storage::fake('mirror');
        config(['beam.ux.storage.mirror_disk' => 'mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        $entry = BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(new BeamParticle, [['kind' => 'block', 'id' => 'mq', 'name' => 'marquee', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]]);
        $entry->forceFill(['particle_id' => $particle->id])->save();
        $this->bindSplitTenancy();
        $artifactsBefore = Storage::disk('artifacts')->allFiles();

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('an illegal first publish passed because it was compared with its own baseline');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('marquee', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version, 'the pin moved');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'history was written');
        $this->assertSame($artifactsBefore, Storage::disk('artifacts')->allFiles(), 'an artifact was compiled');
        $this->assertSame([], Storage::disk('mirror')->allFiles(), 'the body was mirrored');
    }

    public function test_a_tenant_save_draft_with_an_opaque_node_is_refused_at_the_choke(): void
    {
        // HIGH-1: save-draft -> recordDraft -> write(), the choke. A tenant entry refuses an opaque node
        // (code) even though EntryDraftSaveOp itself calls no guard.
        $entry = $this->publishedPage(self::BODY_ONE);
        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']], 'draft');
    }

    public function test_a_tenant_save_draft_with_an_expression_prop_is_refused_at_the_choke(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->recordDraft($entry->fresh(), [[
            'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
            'props' => [['name' => 'x', 'kind' => 'expression', 'value' => 'user.isAdmin']], 'children' => [],
        ]], 'draft');
    }

    public function test_a_restore_of_a_code_bearing_version_on_a_tenant_entry_is_refused(): void
    {
        // A code-bearing version recorded while central, then the entry is tenant-owned: restore refuses
        // it BEFORE applying (ADR-0227 d1), so a tenant cannot republish old code via restore + publish.
        $entry = $this->publishedPage(self::BODY_ONE);
        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{code()}']], 'code draft');
        $codeRef = $state->versions[0]->readable;

        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->restore($entry->fresh(), $codeRef);
    }

    public function test_named_publish_republishes_a_legacy_page_against_the_published_body(): void
    {
        $entry = $this->legacyPublishedPage();
        $publication = app(EntryPublication::class);
        $pin = $publication->state($entry)->publishedVersion;
        $draft = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $this->bindSplitTenancy();

        $result = $publication->publishVersion($entry->fresh(), $pin);

        $this->assertSame($pin, $result->publishedVersion);
        $this->assertSame($draft->headVersion, $result->headVersion);
        $this->assertSame(self::BODY_TWO, $entry->fresh()->particle->payload);
    }

    public function test_restore_stores_illegal_legacy_nodes_but_named_publish_refuses_them(): void
    {
        $entry = $this->legacyPublishedPage();
        $publication = app(EntryPublication::class);
        $legacy = $publication->state($entry)->publishedVersion;
        $legacyBody = $entry->fresh()->particle->payload;
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $current = $publication->publish($entry->fresh());
        $this->bindSplitTenancy();

        $restored = $publication->restore($entry->fresh(), $legacy);

        $this->assertSame($legacyBody, $entry->fresh()->particle->payload);
        $this->assertSame($current->publishedVersion, $restored->publishedVersion);
        $this->assertNotEmpty($restored->diagnostics);
        $before = $entry->fresh()->getAttributes();
        try {
            $publication->publishVersion($entry->fresh(), $restored->headVersion);
            $this->fail('Restored illegal draft bypassed publication admission.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('marquee', implode('\n', $exception->errors()['body']));
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    public function test_programmatic_publish_cannot_pin_a_restored_illegal_working_head(): void
    {
        $entry = $this->legacyPublishedPage();
        $publication = app(EntryPublication::class);
        $legacy = $publication->state($entry)->publishedVersion;
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $publication->publish($entry->fresh());
        $this->bindSplitTenancy();
        $publication->restore($entry->fresh(), $legacy);
        $before = $entry->fresh()->getAttributes();

        try {
            $publication->publishAuthoredBody($entry->fresh());
            $this->fail('An illegal working head was compared to itself and published.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('marquee', implode('\n', $exception->errors()['body']));
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    private function legacyPublishedPage(): BeamUxEntry
    {
        $entry = BeamUxEntry::create(['slug' => 'legacy-published', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(new BeamParticle, [[
            'kind' => 'block', 'id' => 'legacy', 'name' => 'marquee', 'isComponent' => false,
            'dynamic' => false, 'props' => [], 'children' => [],
        ]]);
        $entry->forceFill(['particle_id' => $particle->id])->save();
        app(EntryPublication::class)->baseline($entry);

        return $entry->fresh();
    }

    public function test_publishing_a_historical_code_version_refuses_before_any_write(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $state = $publication->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{code()}']], 'old code');
        $before = $entry->fresh()->getAttributes();
        $this->bindSplitTenancy();

        try {
            $publication->publishVersion($entry->fresh(), $state->headVersion);
            $this->fail('A historical code version was published without admission.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame($state->publishedVersion, $publication->state($entry->fresh())->publishedVersion);
    }

    /** An entry with a written body and NO version history — every entry authored before this path. */
    private function pageWithUnversionedBody(array $body): BeamUxEntry
    {
        $entry = BeamUxEntry::create([
            'slug' => 'home-'.substr(bin2hex(random_bytes(4)), 0, 8),
            'type' => UxType::Page->value,
            'format' => 'tsx',
            'realm' => 'site',
        ]);

        app(EntryPublication::class)->write($entry, $body);

        // Compiled the way the console backfill would: at whatever address the unpinned entry has.
        app(CompileEntryBody::class)->forEntry($entry->refresh(), force: true);

        return $entry->fresh();
    }

    private function saveRequest(array $body): Request
    {
        return Request::create('/beam-ux-entries/x/save-body', 'POST', ['body' => $body]);
    }

    private function versionRows(BeamUxEntry $entry): int
    {
        return (int) \DB::table(Beam::table('versions'))
            ->where('versionable_id', (string) $entry->fresh()->particle_id)
            ->count();
    }

    private function createTables(?string $connection = null): void
    {
        Schema::connection($connection)->create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create('beam_ux_entries', function (Blueprint $table) {
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
            $table->integer('nav_order')->nullable();
            foreach (['nav_group', 'layout', 'template', 'seo_description', 'seo_social_image', 'seo_page_type', 'seo_published_date'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->uuid('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::connection($connection)->create(Beam::table('particles'), function (Blueprint $table) {
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

        Schema::connection($connection)->create(Beam::table('versions'), function (Blueprint $table) {
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

/** A compiler with no toolchain — it echoes the source back as a module. */
class PublicationFakeCompiler implements EntryBodyCompiler
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
}

/** A host's BeamUxEntry policy that allows editing and denies publishing. */
class DenyPublishEntryPolicy
{
    public function update($user, BeamUxEntry $entry): bool
    {
        return true;
    }

    public function publish($user, BeamUxEntry $entry): bool
    {
        return false;
    }
}

/** A host's BeamUxEntry policy that declares only `update`, and denies it. */
class DenyUpdateEntryPolicy
{
    public function update($user, BeamUxEntry $entry): bool
    {
        return false;
    }
}

/** A host's BeamUxEntry policy that allows publishing and denies editing. */
class AllowPublishDenyUpdateEntryPolicy
{
    public function update($user, BeamUxEntry $entry): bool
    {
        return false;
    }

    public function publish($user, BeamUxEntry $entry): bool
    {
        return true;
    }
}

/** A host's BeamUxEntry policy whose `update` a test can flip; `publish` is always allowed. */
class ToggleUpdateEntryPolicy
{
    public static bool $update = true;

    public function update($user, BeamUxEntry $entry): bool
    {
        return self::$update;
    }

    public function publish($user, BeamUxEntry $entry): bool
    {
        return true;
    }
}
