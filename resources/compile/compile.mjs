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

const compileMdx = async (source, slug, root) => {
  const { compile } = await load('@mdx-js/mdx', root)

  // `outputFormat: 'program'` emits a standalone ES module exporting the MDX component as default, and
  // the automatic runtime keeps `react/jsx-runtime` the only import the artifact needs — so the page
  // shell supplies components via the `components` prop rather than the module importing them itself.
  const compiled = await compile(source, {
    jsx: false,
    jsxRuntime: 'automatic',
    jsxImportSource: 'react',
    outputFormat: 'program',
    development: false,
    filepath: `${slug}.mdx`,
  })

  return String(compiled)
}

const compileTsx = async (source, slug, root) => {
  const esbuild = await load('esbuild', root)

  const result = await esbuild.transform(source, {
    loader: 'tsx',
    format: 'esm',
    jsx: 'automatic',
    jsxImportSource: 'react',
    sourcefile: `${slug}.tsx`,
  })

  return result.code
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
