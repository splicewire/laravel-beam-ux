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
        // Explicit native-Git admission follows the required disposable root check.
        config(['beam.ux.recording.git' => 'git']);
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

    public static function commitFailureClasses(): array
    {
        return [
            'identity-unknown' => ['Author identity unknown', 'identity-unknown'],
            'signing' => ['error: gpg failed to sign the data', 'signing'],
            'hook-rejected' => ['fatal: pre-commit hook declined', 'hook-rejected'],
            'ref-lock' => ["fatal: cannot lock ref 'refs/heads/main'", 'ref-lock'],
            'not-a-repo' => ['fatal: not a git repository', 'not-a-repo'],
            'permission' => ['fatal: could not open index.lock: Permission denied', 'permission'],
            'other' => ['opaque diagnostic sentinel that must not escape', 'other'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commitFailureClasses')]
    public function test_commit_failure_exposes_only_a_sanitized_class_and_exit_status(string $stderr, string $class): void
    {
        $git = new LocalGit($this->repo);
        $base = $git->head();
        $tree = $git->tree($this->work, $base, $this->manifest());
        $wrapper = $this->repo.'/failing-git';
        $script = "#!/bin/sh\nif [ \"\$2\" = commit ]; then printf '%s\\n' ".escapeshellarg($stderr)." >&2; exit 128; fi\nexec /usr/bin/git \"\$@\"\n";
        file_put_contents($wrapper, $script);
        chmod($wrapper, 0755);
        config(['beam.ux.recording.git' => $wrapper]);

        $previousHome = getenv('HOME');
        $emptyHome = $this->repo.'/empty-home';
        if ($class === 'identity-unknown') {
            mkdir($emptyHome, 0700);
            putenv('HOME='.$emptyHome);
            $_SERVER['HOME'] = $emptyHome;
            $_ENV['HOME'] = $emptyHome;
            foreach (['EMAIL', 'GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL', 'GIT_CONFIG_GLOBAL'] as $key) {
                putenv($key);
                unset($_SERVER[$key], $_ENV[$key]);
            }
        }

        try {
            $git->commit($this->work, $base, 'diagnostic fixture');
            $this->fail('A failing Git commit was accepted.');
        } catch (RecordingFailure $failure) {
            $this->assertSame('git-failed', $failure->reason);
            $this->assertSame($class, $failure->diagnosticClass);
            $this->assertSame(128, $failure->exitStatus);
            $this->assertStringNotContainsString($stderr, $failure->getMessage());
        } finally {
            config(['beam.ux.recording.git' => 'git']);
            if ($class === 'identity-unknown') {
                if ($previousHome === false) {
                    putenv('HOME');
                    unset($_SERVER['HOME'], $_ENV['HOME']);
                } else {
                    putenv('HOME='.$previousHome);
                    $_SERVER['HOME'] = $previousHome;
                    $_ENV['HOME'] = $previousHome;
                }
            }
        }

        $this->assertNotSame('', $tree);
    }

    public function test_process_start_failure_returns_a_bounded_failure_without_leaking_the_working_directory(): void
    {
        $missingWorkingDirectory = $this->repo.'/does-not-exist';

        try {
            new LocalGit($missingWorkingDirectory);
            $this->fail('A missing working directory was accepted.');
        } catch (RecordingFailure $failure) {
            $this->assertSame('git-failed', $failure->reason);
            $this->assertSame('other', $failure->diagnosticClass);
            $this->assertNull($failure->exitStatus);
            $this->assertStringNotContainsString($missingWorkingDirectory, $failure->getMessage());
        }
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
        } catch (RecordingFailure $error) {
            $this->assertSame('signing', $error->diagnosticClass);
            $this->assertNotNull($error->exitStatus);
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

    public function test_case_aliases_against_parent_tree_index_and_ancestors_refuse_but_exact_paths_work(): void
    {
        mkdir($this->repo.'/Theme');
        mkdir($this->repo.'/0');
        file_put_contents($this->repo.'/0/1.css', "numeric ancestor\n");
        file_put_contents($this->repo.'/Theme/Existing.css', "parent\n");
        $this->git(['add', '--', 'Theme/Existing.css', '0/1.css']);
        $this->git(['commit', '-m', 'case-sensitive parent names']);
        // Remove the working directory so the negative control requires Git-tree inspection,
        // including when a filesystem would otherwise alias a different case spelling.
        unlink($this->repo.'/Theme/Existing.css');
        rmdir($this->repo.'/Theme');
        $git = new LocalGit($this->repo);
        $base = $git->head();
        $index = hash_file('sha256', $this->repo.'/.git/index');
        foreach (['Member.css', 'theme/new.css', 'Theme/existing.css'] as $path) {
            foreach (['path', 'tree'] as $door) {
                try {
                    if ($door === 'path') {
                        $git->path($path);
                    } else {
                        $git->tree($this->work.'-'.$door, $base, $this->manifest($path));
                    }
                    $this->fail('A case alias was accepted: '.$path.' through '.$door);
                } catch (RecordingFailure $failure) {
                    $this->assertSame('path', $failure->reason);
                    $this->assertSame($base, $git->head());
                    $this->assertSame($index, hash_file('sha256', $this->repo.'/.git/index'));
                }
            }
        }
        $manifest = [...$this->manifest('New/a.css'), ...$this->manifest('new/b.css')];
        try {
            $git->tree($this->work.'-manifest', $base, $manifest);
            $this->fail('Case aliases within new manifest ancestors were accepted');
        } catch (RecordingFailure $failure) {
            $this->assertSame('path', $failure->reason);
        }
        // A staged-only name and an untracked ancestor are separate alias sources.
        file_put_contents($this->repo.'/Staged.css', 'staged');
        $this->git(['add', '--', 'Staged.css']);
        mkdir($this->repo.'/Untracked');
        foreach (['staged.css', 'untracked/new.css'] as $path) {
            try {
                $git->path($path);
                $this->fail('Index/worktree case alias accepted');
            } catch (RecordingFailure $failure) {
                $this->assertSame('path', $failure->reason);
            }
        }
        $manifest = [...$this->manifest('member.css'), ...$this->manifest('Theme/Existing.css'), ...$this->manifest('Theme/New.css'), ...$this->manifest('0/1.css')];
        $tree = $git->tree($this->work.'-exact', $base, $manifest);
        $sha = $git->commit($this->work.'-exact', $base, "Exact spelling\n\nBeam-UX-Recording: exact-case");
        $git->verify($sha, $base, $tree, $manifest, 'exact-case');
        $this->assertSame($base, $git->head());
        $this->assertSame('new', $this->git(['show', $sha.':Theme/New.css']));
    }

    public function test_saved_case_colliding_candidate_is_refused_during_recovery_verification(): void
    {
        $git = new LocalGit($this->repo);
        $base = $git->head();
        $index = hash_file('sha256', $this->repo.'/.git/index');
        // Construct a stored bad candidate directly, bypassing the newly guarded preparation.
        $old = $this->git(['rev-parse', $base.':member.css']);
        $tree = new Process(['git', 'mktree'], $this->repo, null,
            '100644 blob '.$old."\tmember.css\n100644 blob ".$old."\tMember.css\n");
        $tree->mustRun();
        $treeId = trim($tree->getOutput());
        $sha = $this->git(['commit-tree', $treeId, '-p', $base, '-m', "Old bad candidate\n\nBeam-UX-Recording: bad-case"]);
        try {
            $git->verify($sha, $base, $treeId, $this->manifest('Member.css', "old\n"), 'bad-case');
            $this->fail('A stored case-colliding candidate was accepted for recovery');
        } catch (RecordingFailure $failure) {
            $this->assertSame('path', $failure->reason);
            $this->assertSame($base, $git->head());
            $this->assertSame($index, hash_file('sha256', $this->repo.'/.git/index'));
        }
    }
}
