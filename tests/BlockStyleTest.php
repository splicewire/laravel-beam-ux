<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Style\BlockStyleCompiler;
use Splicewire\Beam\Ux\Style\StyleRegistry;

class BlockStyleTest extends TestCase
{
    public function test_legacy_style_normalization_keeps_class_and_expression_props(): void
    {
        $node = ['kind' => 'block', 'name' => 'p', 'props' => [
            ['name' => 'style', 'kind' => 'string', 'value' => 'color:red'],
            ['name' => 'className', 'kind' => 'string', 'value' => 'p-2'],
            ['name' => 'data-x', 'kind' => 'expression', 'value' => '({foo:call()})'],
        ], 'children' => [], 'isComponent' => false, 'dynamic' => false];
        $doc = \Splicewire\Beam\Ux\Style\NormalizeBlockStyles::apply([$node], StyleRegistry::fromTheme([]));
        $this->assertSame(['color' => 'red'], $doc[0]['style']['layers'][0]['declarations']);
        $this->assertSame(array_slice($node['props'], 1), $doc[0]['props']);
        $this->assertSame($doc, \Splicewire\Beam\Ux\Style\NormalizeBlockStyles::apply($doc, StyleRegistry::fromTheme([])));
    }

    public function test_real_artifact_renders_one_sheet_and_shared_classes_using_current_registry(): void
    {
        $root = getenv('BEAM_UX_JS_ROOT');
        if (! $root) {
            $this->markTestSkipped('Set BEAM_UX_JS_ROOT to the paired Beam JS candidate for the cross-runtime proof');
        }
        $node = ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'children' => [],
            'props' => [['name' => 'className', 'kind' => 'string', 'value' => 'text-sm']],
            'style' => ['layers' => [['breakpoint' => 'md', 'state' => 'hover', 'declarations' => ['color' => 'red']]]]];
        $process = new \Symfony\Component\Process\Process(['node', __DIR__.'/Fixtures/render-block-style.mjs'], null, null,
            json_encode(['root' => $root, 'source' => \Splicewire\Beam\Ux\Codec\JsonDocPrinter::printModule([$node, $node]), 'compiler' => __DIR__.'/../resources/compile/compile.mjs']), 30);
        $process->mustRun();
        $html = $process->getOutput();
        $this->assertSame(1, substr_count($html, '<style'));
        $this->assertSame(1, substr_count($html, 'color:red'));
        $this->assertStringContainsString('@media (min-width: 52rem)', $html);
        $compiled = BlockStyleCompiler::compile($node['style'], StyleRegistry::fromTheme(['responsive' => ['breakpoints' => ['md' => '52rem']]]));
        $this->assertStringContainsString($compiled['className'], $html, 'PHP and JS must address identical style content identically');
        $this->assertSame(2, preg_match_all('/class="text-sm bs-[a-f0-9]+"/', $html));
        $this->assertStringNotContainsString('bdStyle', $html);
    }

    public function test_theme_tokens_roundtrip_at_named_breakpoints(): void
    {
        $body = ['site' => ['background' => 'white'], 'responsive' => [
            'breakpoints' => ['md' => '52rem'],
            'tokens' => ['md' => ['site' => ['background' => 'black']]],
        ]];
        $codec = new \Splicewire\Beam\Ux\Codec\CssBodyCodec;
        $css = $codec->decode($body);
        $this->assertStringContainsString('@media (min-width: 52rem)', $css);
        $this->assertStringContainsString('--theme-site-background: black', $css);
        $this->assertSame($body, $codec->encode($css));
    }

    public function test_style_data_fixture_cannot_drift_silently(): void
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, __DIR__.'/../scripts/export-block-style.php']);
        $process->mustRun();
        $this->assertSame(file_get_contents(__DIR__.'/Fixtures/block-style.ts'), $process->getOutput());
        if ($root = getenv('BEAM_UX_JS_ROOT')) {
            $this->assertSame($process->getOutput(), file_get_contents($root.'/beam-ux/src/generated/block-style.ts'));
        }
    }

    public function test_registry_fixture_cannot_drift_silently(): void
    {
        $this->assertSame(file_get_contents(__DIR__.'/Fixtures/style-registry.json'), json_encode(StyleRegistry::fromTheme([]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        if ($root = getenv('BEAM_UX_JS_ROOT')) {
            $this->assertSame(file_get_contents(__DIR__.'/Fixtures/style-registry.json'), file_get_contents($root.'/beam-ux/src/generated/style-registry.json'));
        }
    }

    public function test_source_keeps_bdstyle_but_module_compiles_it_once_at_render_time(): void
    {
        $block = ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false,
            'props' => [['name' => 'className', 'kind' => 'string', 'value' => 'text-sm']], 'children' => [],
            'style' => ['layers' => [['declarations' => ['color' => 'red']]]]];
        $source = \Splicewire\Beam\Ux\Codec\JsonDocPrinter::print([$block]);
        $this->assertStringContainsString(' bdStyle={', $source);
        $module = \Splicewire\Beam\Ux\Codec\JsonDocPrinter::printModule([$block, $block]);
        $this->assertSame(1, substr_count($module, '<style'));
        $this->assertStringContainsString('__beamBlockStyles(', $module);
        $this->assertStringNotContainsString(' bdStyle=', $module);
        $this->assertStringContainsString('text-sm', $module);
    }

    public function test_theme_breakpoint_override_is_the_same_registry_used_by_css(): void
    {
        $registry = StyleRegistry::fromTheme(['responsive' => ['breakpoints' => ['md' => '52rem']]]);
        $this->assertSame('52rem', $registry['breakpoints']['md']);
        $style = ['layers' => [
            ['declarations' => ['color' => 'var(--theme-site-text)']],
            ['breakpoint' => 'md', 'state' => 'hover', 'declarations' => ['padding' => '2rem']],
        ]];
        $compiled = BlockStyleCompiler::compile($style, $registry);
        $this->assertStringStartsWith('bs-', $compiled['className']);
        $this->assertStringContainsString('@media (min-width: 52rem)', $compiled['css']);
        $this->assertStringContainsString(':hover{padding:2rem}', $compiled['css']);
        $this->assertSame($compiled, BlockStyleCompiler::compile($style, $registry));
    }

    public function test_registry_refuses_an_executable_width(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StyleRegistry::fromTheme(['responsive' => ['breakpoints' => ['md' => '1px){}<script>']]]);
    }

    public function test_compiler_refuses_unknown_conditions_and_css_breakout(): void
    {
        foreach ([
            ['breakpoint' => 'invented', 'declarations' => ['color' => 'red']],
            ['state' => 'visited', 'declarations' => ['color' => 'red']],
            ['declarations' => ['color' => 'red}</style><script>alert(1)</script>']],
        ] as $layer) {
            try {
                BlockStyleCompiler::compile(['layers' => [$layer]], StyleRegistry::fromTheme([]));
                $this->fail('Unsafe style accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
