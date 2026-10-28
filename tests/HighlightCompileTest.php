<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class HighlightCompileTest extends TestCase
{
    public function test_fenced_code_compiles_to_dual_theme_token_spans(): void
    {
        $compiler = $this->compiler(withShiki: true);
        $code = $compiler->compile($this->entry(), "```typescript\nconst answer: number = 42\n```");
        $this->assertStringContainsString('--shiki-light', $code);
        $this->assertStringContainsString('--shiki-dark', $code);
        $this->assertStringContainsString('span', $code);
        $this->assertSame([], $compiler->lastWarnings());
    }

    public function test_a_host_without_shiki_gets_bare_code_and_an_explicit_warning(): void
    {
        $compiler = $this->compiler();
        $code = $compiler->compile($this->entry(), "```typescript\nconst answer = 42\n```");
        $this->assertStringContainsString('const answer = 42', $code);
        $this->assertStringNotContainsString('--shiki-light', $code);
        $this->assertSame(['highlight-unavailable'], array_column($compiler->lastWarnings(), 'rule'));
    }

    public function test_shiki_can_resolve_through_beam_mdx_in_a_nested_install(): void
    {
        $compiler = $this->compiler(withShiki: true, nested: true);
        $code = $compiler->compile($this->entry(), "```js\nconst answer = 42\n```");
        $this->assertStringContainsString('--shiki-light', $code);
        $this->assertStringContainsString('--shiki-dark', $code);
        $this->assertSame([], $compiler->lastWarnings());
    }

    public function test_unknown_languages_stay_plain_text_without_refusing_the_page(): void
    {
        $compiler = $this->compiler(withShiki: true);
        $code = $compiler->compile($this->entry(), "```beam-unregistered-language\nplain code remains\n```");
        $this->assertStringContainsString('plain code remains', $code);
        $this->assertStringContainsString('language-beam-unregistered-language', $code);
        $this->assertSame([], $compiler->lastWarnings());
    }

    public function test_native_config_controls_themes_and_the_language_allowlist(): void
    {
        config(['beam.ux.compile.highlight' => [
            'themes' => ['light' => 'github-light', 'dark' => 'nord'], 'langs' => ['typescript'],
        ]]);
        $compiler = $this->compiler(withShiki: true);
        $code = $compiler->compile($this->entry(), "```typescript\nconst answer: number = 42\n```");
        $this->assertStringContainsString('nord', $code);
        $this->assertStringNotContainsString('github-dark', $code);
        $this->assertStringContainsString('--shiki-dark', $code);
        $this->assertSame([], $compiler->lastWarnings());
    }

    public function test_inline_code_does_not_require_a_highlighter(): void
    {
        $compiler = $this->compiler();
        $code = $compiler->compile($this->entry(), 'Keep `inline code` plain.');
        $this->assertStringNotContainsString('--shiki-light', $code);
        $this->assertSame([], $compiler->lastWarnings());
    }

    public function test_an_unavailable_theme_warns_and_keeps_the_page_publishable(): void
    {
        config(['beam.ux.compile.highlight.themes' => ['light' => 'beam-missing-theme', 'dark' => 'github-dark']]);
        $compiler = $this->compiler(withShiki: true);
        $code = $compiler->compile($this->entry(), "```js\nconst answer = 42\n```");
        $this->assertStringContainsString('const answer = 42', $code);
        $this->assertStringNotContainsString('--shiki-light', $code);
        $this->assertSame(['highlight-unavailable'], array_column($compiler->lastWarnings(), 'rule'));
    }

    public function test_an_empty_language_allowlist_keeps_code_plain_without_needing_shiki(): void
    {
        config(['beam.ux.compile.highlight.langs' => []]);
        $compiler = $this->compiler();
        $code = $compiler->compile($this->entry(), "```typescript\nconst answer = 42\n```");
        $this->assertStringContainsString('const answer = 42', $code);
        $this->assertStringNotContainsString('--shiki-light', $code);
        $this->assertSame([], $compiler->lastWarnings());
    }

    public function test_an_explicit_plain_text_fence_needs_no_highlighter(): void
    {
        $compiler = $this->compiler();
        $code = $compiler->compile($this->entry(), "```text\nplain code\n```");
        $this->assertStringContainsString('plain code', $code);
        $this->assertSame([], $compiler->lastWarnings());
    }

    /** @return array<string, array{string}> */
    public static function incompatibleHighlighters(): array
    {
        return [
            'missing metadata' => ['export const codeToHast = () => {};'],
            'non-array metadata' => ['export const bundledLanguagesInfo = {}; export const codeToHast = () => {};'],
            'missing renderer' => ['export const bundledLanguagesInfo = [];'],
            'renderer throws a non-error' => ['export const bundledLanguagesInfo = []; export const codeToHast = () => { throw null; };'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompatibleHighlighters')]
    public function test_importable_incompatible_highlighters_warn_without_refusing_the_page(string $module): void
    {
        $compiler = $this->compiler(incompatibleModule: $module);
        $code = $compiler->compile($this->entry(), "```js\nconst answer = 42\n```");
        $this->assertStringContainsString('const answer = 42', $code);
        $this->assertStringNotContainsString('--shiki-light', $code);
        $this->assertSame(['highlight-unavailable'], array_column($compiler->lastWarnings(), 'rule'));
    }

    public function test_an_incompatible_host_module_falls_through_to_real_beam_mdx_shiki(): void
    {
        $compiler = $this->compiler(withShiki: true, nested: true, incompatibleModule: 'export const bundledLanguagesInfo = {};');
        $code = $compiler->compile($this->entry(), "```js\nconst answer = 42\n```");
        $this->assertStringContainsString('--shiki-light', $code);
        $this->assertStringContainsString('--shiki-dark', $code);
        $this->assertSame([], $compiler->lastWarnings());
    }

    private function entry(): BeamUxEntry
    {
        return new BeamUxEntry(['slug' => 'highlight', 'type' => 'page', 'format' => 'mdx']);
    }

    private function compiler(bool $withShiki = false, bool $nested = false, ?string $incompatibleModule = null): NodeEntryBodyCompiler
    {
        // A real isolated host resolution tree: core compiler packages are linked read-only, no install.
        $root = $this->ownedFileFixtureRoot().'/host';
        mkdir($root.'/node_modules/@mdx-js', 0700, true);
        file_put_contents($root.'/package.json', '{"name":"highlight-fixture","private":true}');
        $core = __DIR__.'/Fixtures/entry-body-host/node_modules';
        foreach (['@mdx-js/mdx', 'esbuild'] as $name) {
            $source = realpath($core.'/'.$name);
            if ($source === false) {
                $this->markTestSkipped('The native entry-body fixture toolchain is not installed.');
            }
            symlink($source, $root.'/node_modules/'.$name);
        }
        if ($withShiki) {
            $modules = getenv('BEAM_UX_NODE_MODULES') ?: $core;
            $source = realpath($modules.'/shiki');
            if ($source === false) {
                $this->markTestSkipped('Set BEAM_UX_NODE_MODULES to an installed Shiki toolchain for the integration case.');
            }
            $destination = $root.'/node_modules/shiki';
            if ($nested) {
                $package = $root.'/node_modules/@splicewire/beam-mdx';
                mkdir($package.'/node_modules', 0700, true);
                // Resolution-layout fixture only: tokenization still uses the real installed Shiki above.
                file_put_contents($package.'/package.json', '{"name":"@splicewire/beam-mdx","dependencies":{"shiki":"^4.3.1"}}');
                $destination = $package.'/node_modules/shiki';
            }
            symlink($source, $destination);
        }

        if ($incompatibleModule !== null) {
            $package = $root.'/node_modules/shiki';
            mkdir($package, 0700, true);
            file_put_contents($package.'/package.json', '{"name":"shiki","type":"module","exports":"./index.mjs"}');
            // Negative compatibility fixture only; real tokenization controls above remain unchanged.
            file_put_contents($package.'/index.mjs', $incompatibleModule);
        }

        return new NodeEntryBodyCompiler(binary: getenv('BEAM_UX_NODE_BINARY') ?: 'node', workingDirectory: $root);
    }
}
