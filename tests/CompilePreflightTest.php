<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;
use Symfony\Component\Process\ExecutableFinder;

/**
 * SS-02 (owner re-walk, 2026-10-19): schemastud's `migrate` ran 5 of 15 migrations, then a page-copy
 * migration died on its first publish with "cannot resolve [esbuild] from the host … npm i -D esbuild",
 * although esbuild was declared in package.json and only the install was missing.
 *
 * Pinned here, through the real Node compiler against a throwaway host root:
 *
 *  - `CompileEntryBody::preflight()` resolves the toolchain the way a compile does and compiles nothing,
 *    so a caller that writes before it compiles can stop before its first write;
 *  - the failure names the fix that fits the host: `npm ci` when package.json declares the dependency,
 *    `npm i -D` when it does not, and the binary when Node itself cannot run;
 *  - a batch with nothing compilable never starts Node, so a host with no tsx pages needs no toolchain;
 *  - the compile itself (the path schemastud hit mid-run) names the same remedy.
 */
class CompilePreflightTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);
        $this->app->forgetInstance(EntryArtifactStore::class);

        $this->root = sys_get_temp_dir().'/beam-ux-preflight-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_declared_but_uninstalled_esbuild_fails_the_preflight_naming_npm_ci(): void
    {
        $this->requireNode();
        $this->hostDeclares(['esbuild' => '^0.28.2']);
        $this->useNodeCompiler();

        $message = $this->preflightFailure($this->page(UxFormat::Tsx));

        $this->assertStringContainsString('beam-ux cannot compile [tsx] entries on this host', $message);
        $this->assertStringContainsString('esbuild is required to compile tsx entries', $message);
        $this->assertStringContainsString("{$this->root}/package.json declares it", $message);
        $this->assertStringContainsString("run `npm ci` in {$this->root}", $message);
        $this->assertStringNotContainsString('npm i -D', $message);

        // A preflight compiles nothing, so it leaves nothing behind for a caller to clean up.
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_an_undeclared_esbuild_names_the_install_command_instead(): void
    {
        $this->requireNode();
        $this->hostDeclares([]);
        $this->useNodeCompiler();

        $message = $this->preflightFailure($this->page(UxFormat::Tsx));

        $this->assertStringContainsString("{$this->root}/package.json does not declare it", $message);
        $this->assertStringContainsString("run `npm i -D esbuild` in {$this->root}", $message);
    }

    public function test_an_mdx_page_preflights_the_mdx_compiler(): void
    {
        $this->requireNode();
        $this->hostDeclares(['@mdx-js/mdx' => '^3.0.0']);
        $this->useNodeCompiler();

        $message = $this->preflightFailure($this->page(UxFormat::Mdx));

        $this->assertStringContainsString('@mdx-js/mdx is required to compile mdx entries', $message);
        $this->assertStringContainsString("run `npm ci` in {$this->root}", $message);
    }

    public function test_a_host_whose_esbuild_resolves_passes_the_preflight(): void
    {
        $this->requireNode();
        $this->hostDeclares(['esbuild' => '^0.28.2']);
        // The probe resolves and imports the module exactly as a compile's `load()` does; a stub at the
        // host's node_modules is a resolvable esbuild as far as that resolution is concerned.
        File::ensureDirectoryExists($this->root.'/node_modules/esbuild');
        File::put($this->root.'/node_modules/esbuild/package.json', json_encode(['name' => 'esbuild', 'version' => '0.28.2', 'main' => 'index.js']));
        File::put($this->root.'/node_modules/esbuild/index.js', "module.exports = { transform: async () => ({ code: '' }) }\n");
        $this->useNodeCompiler();

        $this->app->make(CompileEntryBody::class)->preflight($this->page(UxFormat::Tsx), UxFormat::Tsx);

        $this->addToAssertionCount(1); // reaching here is the assertion: no CompilationFailed
    }

    public function test_nothing_compilable_never_starts_node(): void
    {
        // A binary that cannot run: any attempt to start Node would fail loudly.
        $this->useNodeCompiler(binary: $this->root.'/no-such-node');
        $preflight = $this->app->make(CompileEntryBody::class);

        $preflight->preflight();
        $preflight->preflight(
            new BeamUxEntry(['slug' => 'card', 'type' => UxType::Component, 'format' => UxFormat::Tsx]),
            $this->page(UxFormat::Css),
            UxFormat::Css,
        );

        // …and the moment a compilable page is in the batch, the same host is refused, naming the binary.
        $message = $this->preflightFailure($this->page(UxFormat::Tsx));

        $this->assertStringContainsString("Node could not run as [{$this->root}/no-such-node]", $message);
        $this->assertStringContainsString('beam.ux.compile.binary', $message);
    }

    public function test_a_host_bound_compiler_that_cannot_preflight_is_trusted_to_fail_at_compile_time(): void
    {
        $this->app->singleton(EntryBodyCompiler::class, fn () => new class implements EntryBodyCompiler
        {
            public function compile(BeamUxEntry $entry, string $source): string
            {
                throw CompilationFailed::for($entry, 'this compiler never compiles');
            }

            public function handles(BeamUxEntry $entry): bool
            {
                return true;
            }
        });
        $this->app->forgetInstance(CompileEntryBody::class);

        $this->app->make(CompileEntryBody::class)->preflight($this->page(UxFormat::Tsx));

        $this->addToAssertionCount(1);
    }

    public function test_the_compile_names_the_same_remedy_when_no_preflight_ran(): void
    {
        $this->requireNode();
        $this->hostDeclares(['esbuild' => '^0.28.2']);
        $this->useNodeCompiler();

        try {
            $this->app->make(EntryBodyCompiler::class)->compile($this->page(UxFormat::Tsx), 'export default () => null');
            $this->fail('A tsx compile with no esbuild installed must throw.');
        } catch (CompilationFailed $e) {
            $this->assertStringContainsString('Compiling entry [frame-demo-published] (format: tsx) failed', $e->getMessage());
            $this->assertStringContainsString("run `npm ci` in {$this->root}", $e->getMessage());
        }
    }

    private function page(UxFormat $format): BeamUxEntry
    {
        return new BeamUxEntry(['slug' => 'frame-demo-published', 'type' => UxType::Page, 'format' => $format]);
    }

    /** @param  array<string, string>  $devDependencies */
    private function hostDeclares(array $devDependencies): void
    {
        File::put($this->root.'/package.json', json_encode(['private' => true, 'devDependencies' => (object) $devDependencies]));
    }

    private function useNodeCompiler(string $binary = 'node'): void
    {
        $this->app->singleton(EntryBodyCompiler::class, fn () => new NodeEntryBodyCompiler(
            binary: $binary,
            workingDirectory: $this->root,
        ));
        $this->app->forgetInstance(CompileEntryBody::class);
    }

    private function preflightFailure(UxFormat|BeamUxEntry ...$subjects): string
    {
        try {
            $this->app->make(CompileEntryBody::class)->preflight(...$subjects);
        } catch (CompilationFailed $e) {
            return $e->getMessage();
        }

        $this->fail('The preflight passed on a host that cannot compile.');
    }

    /**
     * These cases drive the real `compile.mjs` and so need a Node binary. Without one they would assert
     * the "Node could not run" refusal instead of the dependency message, so they skip rather than lie.
     */
    private function requireNode(): void
    {
        if ((new ExecutableFinder)->find('node') === null) {
            $this->markTestSkipped('No `node` on the PATH; the toolchain preflight is exercised where Node exists.');
        }
    }
}
