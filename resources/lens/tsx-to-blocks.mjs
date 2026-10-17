#!/usr/bin/env node
/**
 * beam-ux's TSX Lens worker (ADR-0228 d9).
 *
 * Reads `{ source, root }` as JSON on stdin and writes `{ doc }` (an id-less JsonDoc node array) as JSON
 * on stdout, exiting non-zero with a human diagnostic on stderr on any failure. Like the compile worker
 * (`resources/compile/compile.mjs`), it is a plain script with no CLI surface: PHP owns the orchestration
 * (which entry, the Working Head, the id rematch); this owns the one thing PHP cannot do — run the
 * Babel/recast TSX lens. The returned doc carries NO ids; the PHP side reattaches the Working Head's
 * stable ids by content (JsonDocIdRematcher).
 *
 * `@splicewire/beam-ux/blockdoc` resolves from the HOST's node_modules, never from anything beam-ux
 * vendors — the host owns its toolchain — via `createRequire` against the host's own `package.json`, the
 * same trap-closing resolution the compile worker documents (a Composer path repo symlinks this package
 * away from the host, so a bare import would not find the host's copy).
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

const load = async (specifier, root) => {
  try {
    const require = createRequire(join(root, 'package.json'))
    return await import(pathToFileURL(require.resolve(specifier)).href)
  } catch (error) {
    fail(
      `beam-ux lens: cannot resolve [${specifier}] from the host at ${root} (${error.message}). ` +
        `Install it in the host (npm i ${specifier}) — the lens runs with the host's toolchain.`,
    )
  }
}

const main = async () => {
  let input
  try {
    input = JSON.parse(await read(process.stdin))
  } catch (error) {
    fail(`beam-ux lens: could not parse stdin as JSON (${error.message}).`)
  }

  const { source, root } = input
  if (typeof source !== 'string' || typeof root !== 'string') {
    fail('beam-ux lens: stdin must be {"source": string, "root": string}.')
  }

  const { parse, toJson } = await load('@splicewire/beam-ux/blockdoc', root)

  let doc
  try {
    doc = toJson(parse(source))
  } catch (error) {
    // A parse/lower failure is the author's source, not an internal fault: report it for a 422.
    fail(`beam-ux lens: could not lower the source to blocks (${error.message}).`)
  }

  process.stdout.write(JSON.stringify({ doc }))
}

main().catch((error) => fail(`beam-ux lens: ${error?.message ?? error}`))
