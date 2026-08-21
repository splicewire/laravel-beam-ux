<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The **shape** of a compiled artifact (ADR-0209 §7, amended at beam-docs-satellite ticket 07).
 *
 * This exists because the previous contract was never checked as a *module*, only as compiler output —
 * and it was unshippable. `outputFormat: 'program'` emitted `import {jsx} from "react/jsx-runtime"`, a
 * bare specifier that a bundler resolves and a browser flatly refuses:
 *
 *     TypeError: Failed to resolve module specifier "react/jsx-runtime".
 *
 * So the artifact the ADR calls "the ES module the page shell imports" could not be imported by the page
 * shell, on any host, and every test passed. The lesson is the same one this map keeps relearning: an
 * output is not verified until something that CONSUMES it the way production does has read it.
 *
 * Three properties, asserted on the real compiler output rather than described in prose:
 *
 *  1. **No bare imports.** The failure mode, pinned directly.
 *  2. **A callable default export taking the runtime.** What makes injection — and therefore exactly one
 *     React instance in the host — possible at all.
 *  3. **No frontmatter in the rendered body.** `MdxBody::decode()` re-emits the `---` block for storage
 *     round-tripping; plain MDX has no frontmatter concept and renders it as text, which is what printed
 *     "title: Documentation nav_order: 0" above the heading on `/beam/docs`.
 */
class ArtifactModuleContractTest extends TestCase
{
    /**
     * The end-to-end assertion needs a real host toolchain, which a testbench does not have — so it
     * skips here and runs where the toolchain exists. A guard that only ever skips is worth nothing,
     * which is the same trap as a config test seeding the key it reads, so the contract is ALSO pinned
     * at the source below, where it always runs. The two halves fail for different reasons: this one if
     * the emitted module is wrong, that one if the script stops trying to emit the right thing.
     */
    private function requireToolchain(): void
    {
        if (! is_dir(base_path('node_modules/@mdx-js/mdx'))) {
            $this->markTestSkipped('The host toolchain (@mdx-js/mdx) is not installed in this testbench.');
        }
    }

    /**
     * Always runs. `compile.mjs` must ask for runtime-injected output and wrap it as a module — the two
     * decisions that together mean "no bare specifier reaches the browser".
     */
    public function test_the_compile_script_emits_a_runtime_injected_module(): void
    {
        $script = file_get_contents(__DIR__.'/../resources/compile/compile.mjs');

        $this->assertStringContainsString("outputFormat: 'function-body'", $script);
        $this->assertStringContainsString('export default function (runtime)', $script);

        // Deliberately no "must NOT contain `outputFormat: 'program'`" assertion: the script's own
        // comments explain the shape that broke, and a naive substring check cannot tell prose from
        // code — it failed on the docblock describing the very bug it was guarding. The positive
        // assertions above are unambiguous, and the end-to-end test covers the emitted output.
    }

    public function test_a_compiled_mdx_artifact_is_a_module_with_no_bare_imports(): void
    {
        $this->requireToolchain();

        $code = $this->compile("---\ntitle: Seeded\nnav_order: 0\n---\n\n# Heading\n\nBody text.\n");

        // 1 — the exact regression: nothing the browser would have to resolve by name.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*import\s/m',
            $code,
            'A compiled artifact must not carry a bare import — a browser cannot resolve one, and the '.
            'artifact exists to be imported by a browser.',
        );

        // 2 — the injection point the host calls with its own React.
        $this->assertStringContainsString('export default function (runtime)', $code);

        // 3 — frontmatter belongs to the entry's columns, never to the rendered body.
        $this->assertStringNotContainsString('nav_order', $code);
        $this->assertStringNotContainsString('title: Seeded', $code);

        // And the content really did compile, so the assertions above are not passing on an empty file.
        $this->assertStringContainsString('Body text.', $code);
    }

    private function compile(string $source): string
    {
        $entry = new BeamUxEntry([
            'slug' => 'artifact-contract',
            'type' => UxType::Page,
            'format' => UxFormat::Mdx,
        ]);

        return (new NodeEntryBodyCompiler(workingDirectory: base_path()))->compile($entry, $source);
    }
}
