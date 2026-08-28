<?php

namespace Splicewire\Beam\Ux\Disk;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\Right;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Inference\InferDraftSchema;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The **`register-from-disk` batch** (charter S8, `beamux-build/issues/05`) — the beam-tier
 * operator-run operation that bootstraps a BeamUx tree from an existing on-disk one. It scans a target
 * directory for **every registered body format's** file (format-aware, ADR-0164 — NO LONGER tsx-only:
 * an `.mdx` file registers exactly as a `.tsx` one), skips files already in the DB, materializes a
 * {@see BeamUxEntry} for each new one, infers its `type` + `namespace` from the path (the reverse of
 * S2's {@see DefaultPlacement}), writes the body THROUGH the resolved
 * free-beam-core {@see StorageDriver}, and — for a `component` — runs S9's
 * {@see InferDraftSchema} at import so the fresh entry arrives editable.
 *
 * **Explicit operator batch, never a watcher (the load-bearing rule).** This runs ONLY when an operator
 * invokes it — there is no ambient filesystem watcher (rejected: it races versioning + the build). Every
 * inbound disk→DB flow is one of these deliberate batches.
 *
 * **Idempotent.** A file whose derived envelope (`namespace` + `slug`) already resolves to a record is
 * SKIPPED — re-running the batch registers only what is new, never duplicating or clobbering.
 *
 * **Composition seam (ADR-0092).** The batch orchestration over the storage port is beam-ux's; the
 * particle + `ParticleWriter` records the body rides are beam-core's, untouched.
 */
class RegisterEntriesFromDisk
{
    public function __construct(
        protected RegisterFromDisk $disk,
        protected StorageDriverResolver $drivers,
        protected InferDraftSchema $inference,
        protected ?EntryAccessGate $gate = null,
        protected ?CompileEntryBody $compile = null,
    ) {}

    /**
     * Compile failures from the current {@see scan()}, keyed by disk-relative path. Per-run state rather
     * than a return value threaded through {@see register()}, which several hosts already override.
     *
     * @var array<string, string>
     */
    protected array $failures = [];

    /**
     * Every entry this run has seen — created OR skipped-as-already-present — keyed `{namespace}|{slug}`.
     * The parent lookup ({@see parentFor}) reads it first, so a subtree imports in one pass without
     * re-querying for a row it just wrote.
     *
     * @var array<string, BeamUxEntry>
     */
    protected array $seen = [];

    /**
     * The entry a scan-root file hangs from, for the current run. Null ⇒ the file's own realm root.
     */
    protected ?BeamUxEntry $under = null;

    /**
     * Scan `$root` and register every recognized-format file not yet in the DB. Returns the outcome:
     * the entries created, the disk-relative paths skipped as already-present, and the paths ignored as
     * an unrecognized (non-body) format.
     *
     * `failed` carries the compile diagnostics for files that registered but whose bodies would not
     * compile (ADR-0209 §7) — they are IN the database and absent from disk-served output, which is the
     * loud-but-not-fatal shape a batch needs.
     *
     * `$under` is the entry a scan-root file is contained by — how a host imports a subtree *beneath*
     * something that already exists (a seeded docs root) rather than at the top of the realm. Omitted,
     * a scan-root file hangs from its own realm's root, which is the same thing one level up.
     *
     * `$default` is the **operator's `type`** for this scan — the resolution {@see RegisterFromDisk::envelopeForPath()}
     * has always deferred ("a path whose last dir is not a type keeps `type = null`; the operator/host
     * decides"), which until now nothing supplied. A file whose path DOES name a {@see UxType} keeps the
     * path's answer, so a mixed tree imports correctly under one default. See {@see unresolved()} for
     * what happens when neither source answers.
     *
     * @return array{created: array<int, BeamUxEntry>, skipped: array<int, string>, ignored: array<int, string>, failed: array<string, string>, unresolved: array<int, string>, recognized: int}
     */
    public function scan(string $root, ?BeamUxEntry $under = null, ?UxType $default = null): array
    {
        $root = rtrim($root, '/');

        $this->failures = [];
        $this->seen = [];
        $this->under = $under;

        $created = [];
        $skipped = [];
        $ignored = [];

        $unresolved = [];
        $recognizedCount = 0;

        if (! is_dir($root)) {
            $failed = $this->failures;

            return compact('created', 'skipped', 'ignored', 'failed', 'unresolved') + ['recognized' => $recognizedCount];
        }

        $recognized = [];

        foreach ($this->files($root) as $absolute) {
            $relative = ltrim(substr($absolute, strlen($root)), '/');

            if (! $this->disk->recognizes($relative)) {
                $ignored[] = $relative;

                continue;
            }

            $envelope = $this->disk->envelopeForPath($relative);
            if ($envelope === null) {
                $ignored[] = $relative;

                continue;
            }

            $recognized[] = [$absolute, $relative, $envelope];
        }

        // SHALLOWEST FIRST, and this ordering is what lets `parent_id` be stamped at CREATE time rather
        // than patched in a second pass. A file's parent is the file named for its containing directory
        // ({@see parentFor}), which is always one namespace segment shallower — so sorting by namespace
        // depth guarantees a parent is registered before the children that name it. The directory
        // iterator's own order is filesystem-dependent and offers no such guarantee.
        usort($recognized, fn (array $a, array $b) => $this->depth($a[2]) <=> $this->depth($b[2]));

        $recognizedCount = count($recognized);

        // THE `type` GATE, and it refuses the WHOLE SCAN rather than the file (owner ruling,
        // beam-docs-satellite ticket 42). `envelopeForPath()` sources `type` from the directory segment
        // before the filename and leaves it null when that segment is not a UxType — which is the shape of
        // every hand-authored tree in the estate, because a docs directory is named for its TRACK
        // (`build`, `using`, `legal`, `essays`) and only the mirror's own `DefaultPlacement` output is laid
        // out by type. Measured 2026-08-28 across ~/Herd/*: 88 of 105 on-disk bodies have no UxType parent
        // directory. Before this, all 88 reached `create()` with `type = null` and died on the NOT NULL
        // constraint, one raw QueryException naming a column instead of a path.
        //
        // ⚠️ This is deliberately NOT the per-file `failed` treatment ADR-0209 §7 gives a compile failure,
        // and the difference was argued before it was chosen. A compile failure is a fact about ONE file;
        // an un-inferrable `type` is almost always a fact about the INVOCATION — the operator pointed a
        // whole tree at a command that had no way to type it, and importing the 25 files that happened to
        // sit under a `page/` directory would leave a half-imported tree whose remainder the idempotent
        // re-run then reports as "skipped (already present)". A refusal that leaves nothing behind is the
        // one the operator can act on, for the same reason `register()` is atomic.
        //
        // The gate sits HERE, after the full enumeration and before the first write, so "nothing was
        // imported" is true by construction rather than by unwinding.
        foreach ($recognized as [, $relative, $envelope]) {
            if ($envelope['type'] === null && $default === null) {
                $unresolved[] = $relative;
            }
        }

        if ($unresolved !== []) {
            $failed = $this->failures;

            return compact('created', 'skipped', 'ignored', 'failed', 'unresolved') + ['recognized' => $recognizedCount];
        }

        foreach ($recognized as $index => [$absolute, $relative, $envelope]) {
            // The path's own answer outranks the operator's, so a tree mixing `page/hero.mdx` with a
            // bare `guides/intro.mdx` imports both correctly under a single `--type`.
            if ($envelope['type'] === null) {
                $recognized[$index][2]['type'] = $default->value;
            }
        }

        foreach ($recognized as [$absolute, $relative, $envelope]) {
            $existing = $this->existing($envelope);

            if ($existing !== null) {
                // Remembered even though nothing is written: a re-run over a partially-imported tree must
                // still resolve children onto the parent that is already there.
                $this->seen[$this->key($envelope['namespace'], $envelope['slug'])] = $existing;
                $skipped[] = $relative;

                continue;
            }

            $created[] = $this->register($envelope, (string) file_get_contents($absolute), $relative);
        }

        $failed = $this->failures;

        return compact('created', 'skipped', 'ignored', 'failed', 'unresolved') + ['recognized' => $recognizedCount];
    }

    /**
     * Materialize one entry from a derived envelope + its raw source: create the record, write the body
     * through the resolved StorageDriver, and run S9 draft-schema inference (a no-op for
     * page/layout/template — only a `component` gets a draft).
     *
     * The record is created with its **containment** ({@see containmentFor}: `realm`/`segment`/`nav_order`/
     * `title`) resolved from the file's own frontmatter (+ a config `realm` convention) — so a page lands
     * in the right realm and derives into nav with NO hand-authored `nav.yml` (ADR-0165; the derive leg
     * the nav-source priority chain already promised). `realm` is set at CREATE time so the model's
     * `creating` hook defaults its `realms` fallback stack onto it.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     */
    protected function register(array $envelope, string $source, string $relative = ''): BeamUxEntry
    {
        // ATOMIC, for the reason `SeedsEntries::seedPage()` states and this method had to relearn: the row
        // and its body are two writes, and the skip-if-present check at the top of {@see scan()} makes
        // anything the first of them leaves behind PERMANENT. Observed on `splicewire/www`
        // (beam-docs-satellite ticket 08): an import refused by the write gate left two bodyless rows, and
        // the re-run — with the gate fixed — reported "2 skipped (already present)" and imported the other
        // thirteen. A failure that makes the retry a silent no-op is worse than one that leaves nothing.
        //
        // BORN PUBLISHED, for the same reason a seeded page is. `WorkflowMarkingPublishGate` treats a
        // workflow-managed entry as public only at the published marking, and a fresh row's marking is
        // NULL — so on any host binding a `page` workflow, every imported page resolved, compiled, and
        // then 404'd, with the subtree beneath the topmost one pruned from nav entirely. An operator
        // placing files on disk and explicitly running this batch is asking for content that is live;
        // arriving invisible is the import not happening. Overridable from frontmatter, so importing
        // genuine drafts stays possible.
        $entry = DB::transaction(function () use ($envelope, $source, $relative): BeamUxEntry {
            $entry = BeamUxEntry::create(array_merge([
                'slug' => $envelope['slug'],
                'type' => $envelope['type'],
                'namespace' => $envelope['namespace'],
                'format' => $envelope['format'],
            ], BeamUxEntry::publishedMarkingAttributes(), $this->containmentFor($source, $relative, $envelope)));

            // The body rides the beam-core StorageDriver (ParticleWriter under the default Stacked
            // driver) — the batch selects the driver, beam-core does the versioned write. Every
            // disk-registered file keeps its raw source as the body (the former Puck-bridge structural
            // parse for `page` `.tsx` files is retired, ADR-0016 — body format is
            // `@splicewire/beam-ux/blockdoc`'s `JsonNode[]` tree, not Puck; no PHP-callable blockdoc parser
            // exists yet to replace it, so a disk-registered `page` arrives as raw source pending a future
            // structural-import mechanism, same degrade this method already applied when the Puck bridge
            // was merely unavailable).
            //
            // The body is encoded THROUGH THE ENTRY'S CODEC (ADR-0164), not hardcoded to `['source' => …]`.
            // Found live while wiring the renderer: the hardcoded shape happens to be what `TsxBodyCodec`
            // round-trips, but `MdxBodyCodec` stores `{frontmatter, content}` — so every disk-registered
            // `.mdx` entry decoded to an EMPTY STRING, and `PlacedDiskMirror` (which decodes through the
            // codec) wrote a blank `.mdx` back out with no error. Exactly the failure the model's own
            // `format` default docblock records finding for themes, in a second place.
            $body = $entry->codec()->encode($source, $entry->body_style);

            $driver = $this->drivers->resolve($entry);
            $item = $driver->write('', $body, $entry->namespace);

            if ($entry->particle_id === null && $item->key !== '') {
                $entry->particle_id = $item->key;
                $entry->save();
            }

            return $entry;
        });

        $this->seen[$this->key($envelope['namespace'], $envelope['slug'])] = $entry;

        // S9 at import: a fresh `component` arrives editable; page/layout/template are left untouched.
        $this->inference->forEntry($entry, $source, persist: true);

        $entry = $entry->refresh();

        // Compile-on-save (ADR-0209 §7), second producer: an operator batch, on a console where Node is
        // trivially available. Without this an import would register a hundred pages that all 404 until
        // someone ran the backfill — the "invisible failure months later" shape §7 exists to refuse.
        // We already hold the source, so nothing is read back out of the store it was just written to.
        //
        // A failure is COLLECTED, not thrown: the batch's contract is to import everything importable
        // and report what it could not, and one page with a syntax error must not abort the other four
        // hundred. Loudness is preserved by {@see scan()} returning the failures and the command
        // reporting them — plus the page 404ing and the doctor naming it, exactly as at save time.
        if ($this->compile !== null) {
            try {
                $this->compile->forEntry($entry, $source, force: true);
            } catch (CompilationFailed $e) {
                $this->failures[$relative] = $e->getMessage();
            }
        }

        return $entry;
    }

    /**
     * The **containment** fields to stamp on a freshly-registered entry, resolved from the file itself so
     * nav DERIVES with no hand-authored `nav.yml`. Only resolved keys are returned (an unresolved field
     * falls to the model default — `realm` → `site`, `segment`/`nav_order`/`title` → null ⇒ not in nav).
     *
     * Realm priority (highest first): the file's own `realm:` frontmatter → the config `realm`
     * convention ({@see realmByConvention}) → omitted (the model's `site` default). `segment`/`nav_order`/
     * `title` come from frontmatter only — a URL is never guessed, so a page auto-surfaces into nav only
     * once it declares its `segment` (co-located in the page file, not a separate registry). The explicit
     * `beam.ux.nav` override and an authored `nav.yml` still outrank all of this at the NavSource seam.
     *
     * `parent_id` is the exception, and it comes from the DISK — see {@see parentFor} for why the one
     * containment field a frontmatter block cannot honestly carry is the one the directory tree already
     * states.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     * @return array<string, mixed>
     */
    protected function containmentFor(string $source, string $relative, array $envelope = ['slug' => '', 'type' => null, 'namespace' => null, 'format' => '']): array
    {
        $fm = $this->frontmatter($source);
        $out = [];

        $parent = $this->parentFor($envelope, $fm['realm'] ?? $this->realmByConvention($relative));

        if ($parent !== null) {
            $out['parent_id'] = $parent->getKey();
        }

        $realm = $fm['realm'] ?? $this->realmByConvention($relative);
        if ($realm !== null && $realm !== '') {
            $out['realm'] = $realm;
        }

        if (isset($fm['segment']) && $fm['segment'] !== '') {
            $out['segment'] = $fm['segment'];
        }

        if (isset($fm['nav_order']) && $fm['nav_order'] !== '' && is_numeric($fm['nav_order'])) {
            $out['nav_order'] = (int) $fm['nav_order'];
        }

        if (isset($fm['title']) && $fm['title'] !== '') {
            $out['title'] = $fm['title'];
        }

        // Chrome + nav grouping (ADR-0213), read from frontmatter like every other containment field
        // and column-guarded like every other aspect field, so a host that has not migrated 0213 imports
        // the file WITHOUT them rather than dying on a missing column. All three are plain strings; the
        // inheritance that makes `layout`/`template` useful is resolved at render, not stamped here, so
        // a guide file declaring nothing is the normal case and inherits its section's shell.
        foreach (['layout', 'template', 'nav_group'] as $field) {
            if (! isset($fm[$field]) || $fm[$field] === '') {
                continue;
            }

            if (! Schema::hasColumn('beam_ux_entries', $field)) {
                continue;
            }

            $out[$field] = $fm[$field];
        }

        // The escape hatch on born-published: a file declaring its own marking imports at that marking.
        // Guarded on the column so a host that has not migrated workflows imports without it.
        if (isset($fm['workflow_marking']) && $fm['workflow_marking'] !== '' && Schema::hasColumn('beam_ux_entries', 'workflow_marking')) {
            $out['workflow_marking'] = $fm['workflow_marking'];
        }

        // ADR-0212's two rights mirror row↔disk like every other field — particle-primary, import never
        // overwrites (a file whose envelope already resolves is SKIPPED before we get here), no special
        // case and no divergence-resolution rule, because there is no second authority.
        foreach (Right::cases() as $right) {
            // Guarded on the column, like every other aspect field: a consumer that has not migrated
            // ADR-0212 yet imports the file without its rights rather than dying on a missing column.
            if (! Schema::hasColumn('beam_ux_entries', $right->column())) {
                continue;
            }

            $tokens = $this->tokenList($fm[$right->value] ?? null);

            if ($tokens !== null) {
                $this->assertKnownTokens($tokens, $right, $relative);
                $out[$right->column()] = $tokens;
            }
        }

        return $out;
    }

    /**
     * Parse one frontmatter value into an any-of token list. The local {@see frontmatter()} reader is
     * deliberately flat `key: value` (it takes no dependency on a YAML parser), so both the inline-list
     * and bare-list spellings a hand author reaches for are accepted:
     *
     *   access: root, docs.view      →  ['root', 'docs.view']
     *   access: [root, docs.view]    →  ['root', 'docs.view']
     *   access:                      →  []  (declared-but-empty ⇒ DENIES, secure-by-omission)
     *
     * An absent key returns `null` — no declaration, so the entry inherits its ancestors' constraint.
     * That `null` vs `[]` distinction is the load-bearing one; it survives all the way to the column.
     *
     * @return list<string>|null
     */
    protected function tokenList(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            $raw = substr($raw, 1, -1);
        }

        return array_values(array_filter(
            array_map(fn (string $token) => trim($token, " \t\"'"), explode(',', $raw)),
            fn (string $token) => $token !== '',
        ));
    }

    /**
     * Hard-error on a token the bound gate does not `know()` (ADR-0212 §5), naming the entry and the
     * token. An unknown token already fails closed at runtime, so this never prevents a leak — it
     * prevents a **silent lockout**: a typo'd `athu` would otherwise import cleanly and 404 the page
     * for everyone, including its author, with nothing to point at. A host binding an exotic gate
     * returns `true` from `knows()` and skips validation entirely.
     *
     * @param  list<string>  $tokens
     */
    protected function assertKnownTokens(array $tokens, Right $right, string $relative): void
    {
        if ($this->gate === null) {
            return;
        }

        foreach ($tokens as $token) {
            if (! $this->gate->knows($token)) {
                throw new InvalidArgumentException(
                    "[{$relative}] declares `{$right->value}: {$token}`, a token the bound ".
                    $this->gate::class.' does not recognise. It would fail closed and lock the entry out. '.
                    'Fix the token, or add it to `beam.ux.access.extra_tokens`.'
                );
            }
        }
    }

    /**
     * The scalar frontmatter of a body source — the leading `---` … `---` block parsed as flat
     * `key: value` pairs (the same shape `MdxBody` stores into the body). A `.tsx` page or any file with
     * no frontmatter block yields `[]`. Kept local (a tiny reader) so the disk seam takes no hard
     * dependency on the mdx package.
     *
     * @return array<string, string>
     */
    protected function frontmatter(string $source): array
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?/s', $source, $match)) {
            return [];
        }

        $fields = [];
        foreach (preg_split('/\r?\n/', $match[1]) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $kv)) {
                $fields[$kv[1]] = trim($kv[2], " \t\"'");
            }
        }

        return $fields;
    }

    /**
     * The realm a file's DISK PATH maps to by convention, when its frontmatter declares none — the
     * config `beam.ux.realm_conventions` ordered map of `glob => realm` (fnmatch against the disk-relative
     * path, so a host scopes by segment, e.g. `*​/page/library-*` → `account`). First match wins; no
     * match ⇒ null (the model's `site` default applies). Default `[]` ⇒ pure frontmatter, no convention.
     */
    protected function realmByConvention(string $relative): ?string
    {
        $conventions = config('beam.ux.realm_conventions', []);
        if (! is_array($conventions)) {
            return null;
        }

        foreach ($conventions as $pattern => $realm) {
            if (is_string($pattern) && fnmatch($pattern, $relative)) {
                return (string) $realm;
            }
        }

        return null;
    }

    /**
     * The entry a file is **contained by**, derived from its own directory chain: the file named for the
     * directory it sits in. In envelope terms — the coordinate both directions of the mirror agree on —
     * a file at namespace `a.b.c` is parented by the entry at `(namespace: a.b, slug: c)`; one at
     * namespace `a` by `(namespace: null, slug: a)`; and one at the scan root by {@see $under} (default:
     * its own realm's root).
     *
     * **Why the disk and not frontmatter.** Every other containment field is frontmatter-only on the
     * stated ground that a URL is never guessed. `parent_id` cannot follow that rule, because it is a
     * *foreign key* — a frontmatter block can only name a parent by some other coordinate, which means
     * inventing a second address space for a thing the directory tree already says unambiguously. Before
     * this, an imported tree landed FLAT: `namespace` was derived from the dir chain and `parent_id` was
     * left null, so every file arrived an orphan and the containment tree — which decides both the URL
     * (ADR-0209 §3) and the nav (ADR-0165) — had to be rebuilt by hand afterwards. The directory chain
     * was right there, and carried none of its meaning across.
     *
     * Expressed on `namespace` rather than the raw path deliberately: {@see DefaultPlacement} writes an
     * entry back out as `{namespace}/{type}/{slug}.{ext}`, so the type segment sits between a file and
     * its children on disk. Reading the parent off the namespace makes import and mirror agree by
     * construction — a tree exported by the mirror re-imports with the same parents.
     *
     * A named parent that does not resolve (a file nested under a directory with no file of its own)
     * falls back to `$under` rather than erroring: a gap in the chain is a flatter tree, not a failed
     * import, and the batch's contract is to import everything importable.
     *
     * **The realm root is looked up, never created.** ADR-0209 §9 gave root provisioning to install, on
     * the ground that a row nothing declared should not appear as a side effect of some other operation —
     * the argument was made about a GET, but it holds for a batch too. A host that has not installed
     * imports flat, exactly as it did before this method existed, and its missing root is already a
     * doctor finding rather than something an import quietly papers over.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     */
    protected function parentFor(array $envelope, ?string $realm): ?BeamUxEntry
    {
        $namespace = $envelope['namespace'];

        if (is_string($namespace) && $namespace !== '') {
            $segments = explode('.', $namespace);
            $slug = array_pop($segments);
            $parentNamespace = $segments === [] ? null : implode('.', $segments);

            $parent = $this->seen[$this->key($parentNamespace, $slug)] ?? BeamUxEntry::query()
                ->where('namespace', $parentNamespace)
                ->where('slug', $slug)
                ->first();

            if ($parent !== null) {
                return $parent;
            }
        }

        return $this->under ?? BeamUxEntry::query()
            ->where('namespace', 'realms')
            ->where('slug', $realm ?: BeamUxEntry::REALM_SITE)
            ->first();
    }

    /**
     * The run index's key for one `(namespace, slug)` coordinate. A null and an empty namespace are the
     * same address — `envelopeForPath` returns null, the column may hold either — so they normalize here
     * rather than at every call site.
     */
    protected function key(?string $namespace, string $slug): string
    {
        return ($namespace ?? '').'|'.$slug;
    }

    /**
     * How deep in the containment tree an envelope sits — its namespace segment count. The sort key that
     * guarantees parents register before their children.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     */
    protected function depth(array $envelope): int
    {
        $namespace = $envelope['namespace'];

        return is_string($namespace) && $namespace !== ''
            ? substr_count($namespace, '.') + 1
            : 0;
    }

    /**
     * The record a derived envelope already resolves to (the idempotency key: `namespace` + `slug`), or
     * null when nothing is registered there yet.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     */
    protected function existing(array $envelope): ?BeamUxEntry
    {
        return BeamUxEntry::query()
            ->where('slug', $envelope['slug'])
            ->where('namespace', $envelope['namespace'])
            ->first();
    }

    /**
     * Every file under `$root`, recursively (depth-first). Directories and dotfiles are skipped by the
     * iterator; recognition of a body format happens in {@see scan()}.
     *
     * @return iterable<int, string> absolute file paths
     */
    protected function files(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }
}
