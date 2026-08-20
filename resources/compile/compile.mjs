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

const load = async (specifier) => {
  try {
    return await import(specifier)
  } catch {
    fail(
      `beam-ux compile: cannot resolve [${specifier}] from ${process.cwd()}. ` +
        `Install it in the host (npm i -D ${specifier}) — beam-ux compiles with the host's toolchain.`,
    )
  }
}

const compileMdx = async (source, slug) => {
  const { compile } = await load('@mdx-js/mdx')

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

const compileTsx = async (source, slug) => {
  const esbuild = await load('esbuild')

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

  const { format, source, slug = 'entry' } = input

  if (typeof source !== 'string') {
    fail('beam-ux compile: input carried no `source` string.')
  }

  let code
  try {
    if (format === 'mdx') {
      code = await compileMdx(source, slug)
    } else if (format === 'tsx') {
      code = await compileTsx(source, slug)
    } else {
      fail(`beam-ux compile: unsupported format [${format}].`)
    }
  } catch (error) {
    fail(`beam-ux compile: ${slug} failed to compile — ${error.message}`)
  }

  process.stdout.write(JSON.stringify({ code }))
}

main().catch((error) => fail(`beam-ux compile: ${error.stack ?? error.message}`))
