#!/usr/bin/env node
/**
 * beam-ux's compile-on-save worker (ADR-0209 §7).
 *
 * Reads `{ format, slug, source }` as JSON on stdin, writes `{ code }` as JSON on stdout, and exits
 * non-zero with a human diagnostic on stderr if anything goes wrong. It is intentionally a plain script
 * with no CLI surface: PHP owns the orchestration (which entry, which version, where the artifact
 * lands), this owns the one thing PHP cannot do.
 *
 * Dependencies resolve from the HOST's node_modules, never from anything beam-ux vendors — the host owns
 * its toolchain. A missing dependency is reported by name so the fix is `npm i <name>` rather than a
 * hunt. There is deliberately NO fallback path that emits raw source for the browser to compile: that is
 * exactly the silent regression ADR-0209 §7 rules out.
 */

import { createRequire } from 'node:module'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'

const read = (stream) =>
  new Promise((resolve, reject) => {
    let buffer = ''
    stream.setEncoding('utf8')
    stream.on('data', (chunk) => (buffer += chunk))
    stream.on('end', () => resolve(buffer))
    stream.on('error', reject)
  })

const fail = (message) => {
  process.stderr.write(`${message}\n`)
  process.exit(1)
}

/**
 * Resolve a dependency from the HOST's node_modules, explicitly.
 *
 * A bare `import('@mdx-js/mdx')` here does NOT do that, which is the trap this function exists to
 * close. Node resolves bare specifiers by walking up from the importing module's **real** path, and
 * this script lives inside the beam-ux package. That happens to reach the host when the package sits
 * at `<host>/vendor/splicewire/laravel-beam-ux/...` — the host root is an ancestor — and reaches
 * nothing at all when it does not: a Composer *path* repository (every co-dev overlay in this estate)
 * symlinks the package to somewhere like `~/Workspaces/php/packages/...`, whose ancestors have never
 * heard of the host. The old error even reported `from ${process.cwd()}`, which was misleading twice
 * over: cwd is not what resolution used, and the package the message told you to install was already
 * installed exactly where it belonged.
 *
 * `createRequire` against the host's own `package.json` makes resolution start where the docblock
 * always claimed it did. PHP passes the root because PHP is the only side that knows it.
 */
const load = async (specifier, root) => {
  try {
    const require = createRequire(join(root, 'package.json'))

    return await import(pathToFileURL(require.resolve(specifier)).href)
  } catch (error) {
    fail(
      `beam-ux compile: cannot resolve [${specifier}] from the host at ${root} (${error.message}). ` +
        `Install it in the host (npm i -D ${specifier}) — beam-ux compiles with the host's toolchain.`,
    )
  }
}

/**
 * Wrap runtime-injected compiler output as a real ES module (ADR-0209 §7, amended at ticket 07).
 *
 * The artifact used to be `outputFormat: 'program'`, which emits `import {jsx} from "react/jsx-runtime"`
 * — a BARE SPECIFIER. A bundler resolves that; a browser refuses it outright:
 *
 *     TypeError: Failed to resolve module specifier "react/jsx-runtime".
 *
 * So the artifact the ADR calls "the ES module the page shell imports" was, for every host, not
 * importable by the thing that had to import it. Nothing caught it because no test ever loaded an
 * artifact in a browser — it was verified as a compile output, not as a module.
 *
 * `function-body` output has NO imports at all and reads its runtime from `arguments[0]`. Wrapping it in
 * a plain `function` (not an arrow — `arguments` is the whole point) makes the artifact a genuine ES
 * module that imports nothing and takes its jsx runtime from the caller. That is what buys all four
 * properties at once: a static `import()` with no import map, no `new Function` and so no CSP
 * `unsafe-eval`, exactly ONE React instance because the host injects its own, and the same code path
 * server-side for SSR. The artifact's version-keyed address and free ETag are unaffected — only the
 * shape inside changes.
 */
const asModule = (functionBody) => `export default function (runtime) {\n${functionBody}\n}\n`

/**
 * Drop a leading `---` frontmatter block before compiling.
 *
 * The particle body stores frontmatter and content separately, and `MdxBody::decode()` deliberately
 * re-emits the `---` block so an entry round-trips losslessly back to disk. That is right for STORAGE
 * and wrong for the RENDERER: plain MDX has no frontmatter concept, so it parses `---` as a thematic
 * break and the keys as paragraph text — which is exactly what `/beam/docs` rendered at ticket 07,
 * "title: Documentation nav_order: 0" printed above the heading.
 *
 * Stripped here rather than fixed with `remark-frontmatter` because the artifact has no use for the
 * values: they were already lifted onto the entry's own columns (title, segment, nav_order) when it was
 * seeded or imported. Doing it here also covers disk-registered `.mdx` files, which carry frontmatter
 * for the same reason, and adds no host dependency that could be missing on a bare install.
 */
const stripFrontmatter = (source) => source.replace(/^---\r?\n[\s\S]*?\r?\n---\r?\n?/, '')

const compileMdx = async (source, slug, root) => {
  const { compile } = await load('@mdx-js/mdx', root)

  const compiled = await compile(stripFrontmatter(source), {
    jsx: false,
    jsxRuntime: 'automatic',
    jsxImportSource: 'react',
    outputFormat: 'function-body',
    development: false,
    filepath: `${slug}.mdx`,
  })

  return asModule(String(compiled))
}

/**
 * tsx reaches the same contract by a different road. esbuild's `automatic` jsx emits the same bare
 * `react/jsx-runtime` import the mdx path just stopped emitting, so this uses the CLASSIC transform
 * against factory names the wrapper binds off the injected runtime, and `format: 'cjs'` so the body's
 * own `export default` becomes an assignment that is legal inside a function body.
 *
 * The result is the SAME callable contract as mdx — `module.default(runtime)` returns an object whose
 * `default` is the component — so the page shell has one code path for every format.
 */
const compileTsx = async (source, slug, root) => {
  const esbuild = await load('esbuild', root)

  const result = await esbuild.transform(source, {
    loader: 'tsx',
    format: 'cjs',
    jsx: 'transform',
    jsxFactory: '__beamJsx',
    jsxFragment: '__beamFragment',
    sourcefile: `${slug}.tsx`,
  })

  // A tsx body that imports something becomes a `require()` here, which nothing defines. That was
  // equally unresolvable before (a bare specifier no browser could resolve), so this is not a new
  // limit — but it now fails with a sentence naming the cause rather than a ReferenceError at read time.
  if (/\brequire\s*\(/.test(result.code)) {
    fail(
      `beam-ux compile: ${slug} imports another module. An entry body compiles to a standalone artifact ` +
        `with no module graph of its own — pass what it needs through the components map instead ` +
        `(ADR-0209 §7).`,
    )
  }

  return asModule(
    [
      'const __beamJsx = runtime.createElement, __beamFragment = runtime.Fragment;',
      'const exports = {}, module = { exports };',
      result.code,
      'return module.exports;',
    ].join('\n'),
  )
}

const main = async () => {
  let input
  try {
    input = JSON.parse(await read(process.stdin))
  } catch (error) {
    fail(`beam-ux compile: unreadable input (${error.message})`)
  }

  const { format, source, slug = 'entry', root = process.cwd() } = input

  if (typeof source !== 'string') {
    fail('beam-ux compile: input carried no `source` string.')
  }

  let code
  try {
    if (format === 'mdx') {
      code = await compileMdx(source, slug, root)
    } else if (format === 'tsx') {
      code = await compileTsx(source, slug, root)
    } else {
      fail(`beam-ux compile: unsupported format [${format}].`)
    }
  } catch (error) {
    fail(`beam-ux compile: ${slug} failed to compile — ${error.message}`)
  }

  process.stdout.write(JSON.stringify({ code }))
}

main().catch((error) => fail(`beam-ux compile: ${error.stack ?? error.message}`))
