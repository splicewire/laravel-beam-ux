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
    /** Set by {@see requireToolchain()}: the root the compiler resolves `node_modules` from. */
    private ?string $toolchainRoot = null;

    /**
     * The end-to-end assertion needs a real host toolchain, which a testbench does not have — so it
     * skips here and runs where the toolchain exists. A guard that only ever skips is worth nothing,
     * which is the same trap as a config test seeding the key it reads, so the contract is ALSO pinned
     * at the source below, where it always runs. The two halves fail for different reasons: this one if
     * the emitted module is wrong, that one if the script stops trying to emit the right thing.
     *
     * ## Borrowing a host's toolchain: `BEAM_UX_NODE_MODULES`
     *
     * The host owns `@mdx-js/mdx` and `esbuild`; beam-ux vendors neither, and that stays. To run the
     * end-to-end half here anyway, point `BEAM_UX_NODE_MODULES` at a host's `node_modules` directory
     * (absolute, or relative to this package's root). The compiler then runs with that directory's
     * PARENT as its root — `compile.mjs` resolves the toolchain through
     * `createRequire(<root>/package.json)`, i.e. from `<root>/node_modules`, exactly as it does in a host.
     *
     * `composer test:toolchain` sets it to the fleet layout's `laravel/starters/laravel-beam-starter`
     * (`@putenv`, relative, because Composer does not expand `$HOME` there) and runs the suite. The
     * variable lives in a composer script rather than `phpunit.xml` because `<env>` cannot be made
     * conditional on the path existing, and a committed machine path would turn a missing checkout into
     * a failure instead of a skip. With neither `base_path('node_modules/@mdx-js/mdx')` nor the variable
     * resolving to a toolchain, the test still skips.
     */
    private function requireToolchain(): void
    {
        $this->toolchainRoot = $this->resolveToolchainRoot();

        if ($this->toolchainRoot === null) {
            $this->markTestSkipped(
                'The host toolchain (@mdx-js/mdx) is not installed in this testbench; set BEAM_UX_NODE_MODULES '.
                'to a host\'s node_modules (or run `composer test:toolchain`) to run it.',
            );
        }
    }

    /** The directory whose `node_modules` holds `@mdx-js/mdx`: the testbench's own, else the borrowed one. */
    private function resolveToolchainRoot(): ?string
    {
        if (is_dir(base_path('node_modules/@mdx-js/mdx'))) {
            return base_path();
        }

        $borrowed = getenv('BEAM_UX_NODE_MODULES');

        if (! is_string($borrowed) || $borrowed === '') {
            return null;
        }

        if (! str_starts_with($borrowed, '/')) {
            $borrowed = dirname(__DIR__).'/'.$borrowed;
        }

        $nodeModules = realpath($borrowed);

        return $nodeModules !== false && is_dir($nodeModules.'/@mdx-js/mdx') ? dirname($nodeModules) : null;
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

    /**
     * D-T5 (docs-walkthrough §6.1, rule DOC-10), RED FIRST as a ratchet (DOCS-03): no MDX comment body reaches a
     * compiled artifact. Today `compile.mjs` passes comment-only expressions through, so the marker leaks; that known
     * violation is listed with the ticket that removes it (DOCS-04: strip comment-only expressions). An exact match
     * passes. When DOCS-04 lands, the leak is gone, this entry is STALE and the test fails until the entry is deleted,
     * so the list only shrinks.
     */
    public function test_no_mdx_comment_body_reaches_a_compiled_artifact_ratchet(): void
    {
        $this->requireToolchain();
        // DOCS-04 strips comment-only expressions, so nothing is known to leak any more.
        $known = [];

        $code = $this->compile("{/* SECRET-MARKER */}\n# Hi\n");
        $found = str_contains($code, 'SECRET-MARKER') ? ['D-T5 compile-fixture SECRET-MARKER' => 'the artifact carries the comment body'] : [];

        fwrite(STDERR, "\nD-T5 ratchet: ".count($found).' found, '.count($known)." listed\n".implode("\n", array_map(
            fn ($id) => (isset($found[$id]) ? '  known  ' : '  STALE  ').$id.'  →  '.$known[$id],
            array_keys($known),
        ))."\n");

        $this->assertSame([], array_keys(array_diff_key($found, $known)), 'A new comment leak: a regression, or the ratchet lacks an entry.');
        $this->assertSame([], array_keys(array_diff_key($known, $found)), 'Stale D-T5 entry: the compile strips the comment now, so delete the entry.');
    }

    /** DOC-10 (DOCS-04): a comment inside text is stripped too, and a real expression is untouched. */
    public function test_comment_only_expressions_are_stripped_and_real_ones_kept(): void
    {
        $this->requireToolchain();

        $code = $this->compile("Hello {/* INLINE-MARKER */} world {1 + 1}.\n\n{/*\n  FLOW-MARKER spanning lines\n*/}\n");

        $this->assertStringNotContainsString('INLINE-MARKER', $code);
        $this->assertStringNotContainsString('FLOW-MARKER', $code);
        $this->assertMatchesRegularExpression('/1\s*\+\s*1/', $code, 'A real expression must survive the strip.');

        // review-r1: code between two comments is code, so the expression stays.
        $mixed = $this->compile("{/* a */ KEPT_CODE /* b */}\n");
        $this->assertStringContainsString('KEPT_CODE', $mixed, 'Code between two comments must not be stripped.');
    }

    /**
     * DOC-15 (DOCS-04): a list whose first item starts on the line directly after a paragraph line is a WARN, computed
     * on the AST, never a refusal. The pair: the hard-wrapped shape of the two real hits warns; the same text fenced
     * (the `rag-faithfulness-measured.mdx:71` false positive) does not, and nor does a list after a blank line.
     */
    public function test_a_hard_wrapped_list_marker_is_a_warning_not_a_refusal(): void
    {
        $this->requireToolchain();

        $compiler = new NodeEntryBodyCompiler(workingDirectory: $this->toolchainRoot);
        $entry = new BeamUxEntry(['slug' => 'hard-wrap', 'type' => UxType::Page, 'format' => UxFormat::Mdx]);

        $code = $compiler->compile($entry, "Install the starter and run the installer, which also\n+ publishes the config\n+ runs the migrations\n");
        $this->assertNotSame('', $code, 'A warning never refuses the compile.');
        $this->assertSame(['hard-wrapped-list'], array_column($compiler->lastWarnings(), 'rule'));
        $this->assertSame(2, $compiler->lastWarnings()[0]['line']);

        $compiler->compile($entry, "```text\nA paragraph line that is fenced\n+ is not a list\n```\n");
        $this->assertSame([], $compiler->lastWarnings(), 'Fenced text is not a list.');

        $compiler->compile($entry, "A paragraph.\n\n+ a real list\n+ after a blank line\n");
        $this->assertSame([], $compiler->lastWarnings(), 'A list after a blank line is not hard-wrapped.');

        $compiler->compile($entry, "- resources/css\n  - app.css\n  - theme.css\n- resources/js\n");
        $this->assertSame([], $compiler->lastWarnings(), 'A nested list under its item is ordinary Markdown, not hard-wrapped.');
    }

    /**
     * Always runs. MDX compiles in the dialect the MDX Lens reads (ADR-0233 d1): MDX plus GFM tables, with the lens's own
     * two packages. The lens reads a table into table blocks and prints it back as a GFM table; compiled without them, every
     * table on a published page rendered as a paragraph of raw pipes (EP-42 H2 receipt, 2026-10-26).
     */
    public function test_the_compile_script_compiles_gfm_tables_as_the_lens_reads_them(): void
    {
        $script = file_get_contents(__DIR__.'/../resources/compile/compile.mjs');

        $this->assertStringContainsString("'micromark-extension-gfm-table'", $script);
        $this->assertStringContainsString("'mdast-util-gfm-table'", $script);
        $this->assertMatchesRegularExpression('/const tables = await gfmTables\(root, source, warnings\)/', $script);
        $this->assertMatchesRegularExpression('/remarkPlugins: \[\.\.\.\(tables \? \[tables\] : \[\]\)/', $script);
    }

    public function test_a_gfm_table_compiles_to_a_table(): void
    {
        $this->requireToolchain();

        $code = $this->compile("| Kind | Holds |\n| --- | --- |\n| page | blocks |\n");

        $this->assertStringContainsString('_components.table', $code);
        $this->assertStringContainsString('_components.td', $code);
        $this->assertStringNotContainsString('| --- |', $code, 'The table compiled as a paragraph of pipes.');
    }

    /**
     * A pnpm host carries the table packages only under `@splicewire/beam-mdx`, which declares them; the compiler finds
     * them there when the host root does not (the flagship, measured 2026-10-26).
     */
    public function test_the_table_packages_resolve_through_beam_mdx_when_the_host_root_lacks_them(): void
    {
        $this->requireToolchain();
        $beamMdx = realpath($this->toolchainRoot.'/node_modules/@splicewire/beam-mdx');
        if ($beamMdx === false) {
            $this->markTestSkipped('The borrowed toolchain has no @splicewire/beam-mdx to resolve through.');
        }
        $root = $this->bareRoot(['@mdx-js/mdx' => realpath($this->toolchainRoot.'/node_modules/@mdx-js/mdx'), '@splicewire/beam-mdx' => $beamMdx]);
        $this->assertDirectoryDoesNotExist($root.'/node_modules/micromark-extension-gfm-table', 'the host root lacks them');

        $code = $this->compileFrom($root, "| Kind | Holds |\n| --- | --- |\n| page | blocks |\n");

        $this->assertStringContainsString('_components.table', $code);
    }

    /**
     * Neither the host nor beam-mdx carries them, as on numero and schemastud (measured 2026-10-26: `@mdx-js/mdx` resolves
     * there, the table packages do not). A refusal would stop every MDX publish on those hosts, so the compile is today's:
     * a body without a table compiles exactly as before, and one with a table compiles as before with a warning naming them.
     */
    public function test_without_the_table_packages_a_body_compiles_as_before_and_a_table_is_warned_of(): void
    {
        $this->requireToolchain();
        $root = $this->bareRoot(['@mdx-js/mdx' => realpath($this->toolchainRoot.'/node_modules/@mdx-js/mdx')]);
        $compiler = new NodeEntryBodyCompiler(workingDirectory: $root);
        $entry = new BeamUxEntry(['slug' => 'artifact-contract', 'type' => UxType::Page, 'format' => UxFormat::Mdx]);

        $this->assertStringContainsString('Plain words.', $compiler->compile($entry, "# Hi\n\nPlain words.\n"));
        $this->assertSame([], $compiler->lastWarnings(), 'no table, nothing to warn of');

        $compiler->compile($entry, "Fenced:\n\n```text\n| a | b |\n| --- | --- |\n```\n");
        $this->assertSame([], $compiler->lastWarnings(), 'a delimiter row inside a code fence is code, not a table');

        $code = $compiler->compile($entry, "| Kind | Holds |\n| --- | --- |\n| page | blocks |\n");
        $this->assertStringNotContainsString('_components.table', $code);
        $this->assertSame(['gfm-table-unsupported'], array_column($compiler->lastWarnings(), 'rule'));
        $this->assertStringContainsString('micromark-extension-gfm-table', $compiler->lastWarnings()[0]['message']);
    }

    /** @param  array<string, string|false>  $packages  name → real directory, linked into a fresh root's node_modules */
    private function bareRoot(array $packages): string
    {
        $root = sys_get_temp_dir().'/beam-ux-compile-'.bin2hex(random_bytes(4));
        mkdir($root.'/node_modules/@splicewire', 0777, true);
        mkdir($root.'/node_modules/@mdx-js', 0777, true);
        file_put_contents($root.'/package.json', "{}\n");
        foreach ($packages as $name => $dir) {
            $this->assertIsString($dir, "{$name} in the borrowed toolchain");
            symlink($dir, $root.'/node_modules/'.$name);
        }

        return $root;
    }

    private function compileFrom(string $root, string $source): string
    {
        $entry = new BeamUxEntry(['slug' => 'artifact-contract', 'type' => UxType::Page, 'format' => UxFormat::Mdx]);

        return (new NodeEntryBodyCompiler(workingDirectory: $root))->compile($entry, $source);
    }

    private function compile(string $source): string
    {
        $entry = new BeamUxEntry([
            'slug' => 'artifact-contract',
            'type' => UxType::Page,
            'format' => UxFormat::Mdx,
        ]);

        return (new NodeEntryBodyCompiler(workingDirectory: $this->toolchainRoot ?? base_path()))->compile($entry, $source);
    }
}
