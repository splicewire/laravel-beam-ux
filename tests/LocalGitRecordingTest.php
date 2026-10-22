<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Recording\LocalGit;
use Splicewire\Beam\Ux\Recording\RecordingFailure;
use Symfony\Component\Process\Process;

class LocalGitRecordingTest extends TestCase
{
    private string $repo;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $root = getenv('BEAM_RECORDING_TEST_ROOT');
        if (! is_string($root) || ! is_dir($root)) {
            $this->markTestSkipped('BEAM_RECORDING_TEST_ROOT must name a registered disposable root.');
        }
        $id = bin2hex(random_bytes(8));
        $this->repo = $root.'/git-'.$id;
        $this->work = $root.'/preparation-'.$id;
        mkdir($this->repo, 0700);
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.name', 'Recorder test']);
        $this->git(['config', 'user.email', 'recorder@example.invalid']);
        file_put_contents($this->repo.'/member.css', "old\n");
        file_put_contents($this->repo.'/unrelated', "base\n");
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'baseline']);
    }

    private function git(array $args, ?string $cwd = null): string
    {
        $p = new Process(['git', ...$args], $cwd ?? $this->repo);
        $p->mustRun();

        return trim($p->getOutput());
    }

    private function manifest(string $path = 'member.css', string $bytes = "new\n"): array
    {
        return [['path' => $path, 'bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes), 'mode' => '100644']];
    }

    public function test_native_hook_private_head_exact_tree_and_external_advance_cas_refusal(): void
    {
        $git = new LocalGit($this->repo);
        $base = $git->head();
        file_put_contents($this->repo.'/unrelated', "developer staged\n");
        $this->git(['add', '--', 'unrelated']);
        $index = hash_file('sha256', $this->repo.'/.git/index');
        file_put_contents($this->repo.'/member.css', "new\n");
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nprintf ran > hook-ran\n");
        chmod($this->repo.'/.git/hooks/pre-commit', 0755);
        $tree = $git->tree($this->work, $base, $this->manifest());
        $sha = $git->commit($this->work, $base, "Release\n\nBeam-UX-Recording: test-recording");
        $git->verify($sha, $base, $tree, $this->manifest(), 'test-recording');
        $this->assertSame('ran', file_get_contents($this->repo.'/hook-ran'));
        $this->assertSame($base, $git->head());
        $this->assertSame($index, hash_file('sha256', $this->repo.'/.git/index'));
        $this->git(['commit', '-m', 'external developer advance']);
        $external = $git->head();
        try {
            $git->install($sha, $base);
            $this->fail('A moved branch was overwritten.');
        } catch (RecordingFailure) {
            $this->assertSame($external, $git->head());
            $this->assertSame('developer staged', $this->git(['show', 'HEAD:unrelated']));
        }
    }

    public function test_hook_staged_extra_path_is_not_installed(): void
    {
        $git = new LocalGit($this->repo);
        $base = $git->head();
        file_put_contents($this->repo.'/unrelated', 'hook changed');
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\ngit add -- unrelated\n");
        chmod($this->repo.'/.git/hooks/pre-commit', 0755);
        $tree = $git->tree($this->work, $base, $this->manifest());
        $sha = $git->commit($this->work, $base, "Release\n\nBeam-UX-Recording: altered-tree");
        try {
            $git->verify($sha, $base, $tree, $this->manifest(), 'altered-tree');
            $this->fail('A hook changed the exact prepared tree.');
        } catch (RecordingFailure $error) {
            $this->assertSame('recovery-required', $error->reason);
            $this->assertSame($base, $git->head());
        }
    }

    public function test_paths_new_file_linked_gitfile_and_unsupported_states(): void
    {
        $git = new LocalGit($this->repo);
        $this->assertSame($this->repo.'/new/member.css', $git->path('new/member.css'));
        foreach (['../escape', '/absolute', '.git/config', "bad\0name"] as $path) {
            try {
                $git->path($path);
                $this->fail('Unsafe path accepted');
            } catch (RecordingFailure $error) {
                $this->assertSame('path', $error->reason);
            }
        }
        symlink(dirname($this->repo), $this->repo.'/escape');
        try {
            $git->path('escape/file');
            $this->fail('Symlink ancestor accepted');
        } catch (RecordingFailure $error) {
            $this->assertSame('path', $error->reason);
        }
        $linked = $this->repo.'-linked';
        $this->git(['worktree', 'add', '-b', 'linked-test', $linked]);
        $this->assertFileExists($linked.'/.git');
        $other = new LocalGit($linked);
        $this->assertSame($git->common, $other->common);
        $this->assertNotSame($git->directory, $other->directory);
        $originalHead = $git->head();
        $linkedIndex = hash_file('sha256', $other->directory.'/index');
        file_put_contents($linked.'/new.css', "linked\n");
        $manifest = $this->manifest('new.css', "linked\n");
        $tree = $other->tree($this->work.'-linked', $originalHead, $manifest);
        $sha = $other->commit($this->work.'-linked', $originalHead, "Linked worktree\n\nBeam-UX-Recording: linked");
        $other->verify($sha, $originalHead, $tree, $manifest, 'linked');
        $other->install($sha, $originalHead);
        $this->assertSame($sha, $other->head());
        $this->assertSame($originalHead, $git->head());
        $this->assertSame($linkedIndex, hash_file('sha256', $other->directory.'/index'));
        file_put_contents($this->repo.'/.git/MERGE_HEAD', $git->head());
        try {
            $git->assertSupported();
            $this->fail('An unfinished merge was accepted');
        } catch (RecordingFailure $error) {
            $this->assertSame('repository-state', $error->reason);
        }
    }

    public function test_signing_failure_does_not_advance_branch_or_change_real_index(): void
    {
        $git = new LocalGit($this->repo);
        $base = $git->head();
        $index = hash_file('sha256', $this->repo.'/.git/index');
        $this->git(['config', 'commit.gpgsign', 'true']);
        $this->git(['config', 'gpg.program', '/usr/bin/false']);
        $git->tree($this->work, $base, $this->manifest());
        try {
            $git->commit($this->work, $base, "Signed release\n\nBeam-UX-Recording: signing");
            $this->fail('Signing failure was bypassed');
        } catch (RecordingFailure) {
            $this->assertSame($base, $git->head());
            $this->assertSame($index, hash_file('sha256', $this->repo.'/.git/index'));
        }
    }

    public function test_missing_git_repository_and_unsupported_operation_and_config_controls(): void
    {
        $base = $this->git(['rev-parse', 'HEAD']);
        $index = hash_file('sha256', $this->repo.'/.git/index');
        foreach (['CHERRY_PICK_HEAD', 'REVERT_HEAD', 'BISECT_LOG', 'rebase-merge', 'rebase-apply', 'index.lock', 'HEAD.lock', 'config.worktree'] as $marker) {
            file_put_contents($this->repo.'/.git/'.$marker, $base);
            try {
                new LocalGit($this->repo);
                $this->fail('Unsupported Git operation was accepted: '.$marker);
            } catch (RecordingFailure) {
                $this->assertSame($base, $this->git(['rev-parse', 'HEAD']));
            } finally {
                unlink($this->repo.'/.git/'.$marker);
            }
        }
        foreach (['core.splitIndex', 'core.sparseCheckout', 'extensions.worktreeConfig'] as $key) {
            $this->git(['config', $key, 'true']);
            try {
                new LocalGit($this->repo);
                $this->fail('Unsupported config was accepted: '.$key);
            } catch (RecordingFailure) {
                $this->assertSame($index, hash_file('sha256', $this->repo.'/.git/index'));
            } finally {
                $this->git(['config', '--unset', $key]);
            }
        }
        config(['beam.ux.recording.git' => $this->repo.'/missing-git']);
        try {
            new LocalGit($this->repo);
            $this->fail('Missing Git was accepted');
        } catch (RecordingFailure $error) {
            $this->assertSame('git-failed', $error->reason);
        } finally {
            config(['beam.ux.recording.git' => 'git']);
        }
        mkdir($this->repo.'/not-repository');
        try {
            new LocalGit(dirname($this->repo));
            $this->fail('A directory without a repository was accepted');
        } catch (RecordingFailure $error) {
            $this->assertSame('git-failed', $error->reason);
        }
        $this->git(['checkout', '--detach']);
        try {
            new LocalGit($this->repo);
            $this->fail('Detached HEAD was accepted');
        } catch (RecordingFailure) {
            $this->assertSame($base, $this->git(['rev-parse', 'HEAD']));
        }
    }
}
