<?php

namespace Splicewire\Beam\Ux\Disk;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser;
use Splicewire\Beam\Mdx\MdxBody;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\Right;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Containment\PageFields;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Files\MdxPageFile;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Inference\InferDraftSchema;
use Splicewire\Beam\Ux\JsonDoc\SourceToBlocks;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Provenance\Reasserter;
use Splicewire\Beam\Ux\Publish\EntryPublication;
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
     * The vendor name a PACKAGE source declares, for the current run. When set, registered rows are
     * stamped `package:<vendor>` (DM2) instead of `disk:<relative>`. Null ⇒ a host disk source, which
     * keeps stamping `disk:` exactly as today (the package-default rule, rigs/launch/mission SPEC §Rules:
     * the change is behind an opt-in field, so every existing undeclared source is unchanged).
     */
    protected ?string $package = null;

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
     * @param  list<string>  $only  Exact root-relative files; empty preserves the unfiltered batch.
     * @param  ?string  $expectedPlan  Optional selected dry-run hash; mismatch refuses before writes.
     * @return array{created: array<int, BeamUxEntry>, skipped: array<int, string>, reasserted: array<int, string>, filled: array<string, list<string>>, ignored: array<int, string>, failed: array<string, string>, unresolved: array<int, string>, recognized: int}
     */
    public function scan(string $root, ?BeamUxEntry $under = null, ?UxType $default = null, array $ignore = [], ?string $package = null, array $only = [], ?string $expectedPlan = null): array
    {
        if ($only !== []) {
            return $this->scanSelected($root, $under, $default, $ignore, $package, $only, $expectedPlan);
        }
        if ($expectedPlan !== null) {
            throw new InvalidArgumentException('An expected plan requires an explicit --only selection.');
        }

        $root = rtrim($root, '/');

        $this->failures = [];
        $this->seen = [];
        $this->under = $under;
        $this->package = $package;

        $created = [];
        $skipped = [];
        $reasserted = [];
        $filled = [];
        $ignored = [];

        $unresolved = [];
        $recognizedCount = 0;

        if (! is_dir($root)) {
            $failed = $this->failures;

            return compact('created', 'skipped', 'reasserted', 'filled', 'ignored', 'failed', 'unresolved') + ['recognized' => $recognizedCount];
        }

        $recognized = [];

        foreach ($this->files($root) as $absolute) {
            $relative = ltrim(substr($absolute, strlen($root)), '/');

            if (self::ignores($ignore, $relative) || ! $this->disk->recognizes($relative)) {
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

            return compact('created', 'skipped', 'reasserted', 'filled', 'ignored', 'failed', 'unresolved') + ['recognized' => $recognizedCount];
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
                // DOCS-06 (ADR-0215 §2): re-assert instead of blindly skipping. A pristine package:/disk:
                // row whose source changed is rewritten from this file; a disk origin outranks a package
                // stub (DOC-5), so a host file supersedes a pristine package row at the same coordinate.
                // An edited row is left for docs.diverged; cms is untouched. No-op pre-migration.
                $src = (string) file_get_contents($absolute);
                $title = $this->containmentFor($src, $relative, $envelope)['title'] ?? null;
                if (app(Reasserter::class)->reassert($existing, $title, $src, $this->originFor($relative), $this->loweredForReassert($existing, $src))) {
                    $reasserted[] = $relative;
                } else {
                    $skipped[] = $relative;
                }
                // A page registered before its file's settings were read gains them here, but only into columns
                // that are still empty: a value already there (an author's, or an earlier scan's) is never replaced.
                if (($empty = $this->emptySettings($existing, $src, $relative, $envelope)) !== []) {
                    $existing->forceFill($empty)->save();
                    $filled[$relative] = array_keys($empty);
                }
                // Born published (ADR-0225 decision 2; launch row qitem-20261016215906-483ede25): a row registered before
                // entries were pinned is repaired here. baseline() pins only a body with NO history, so an unpublished
                // draft is never published, and it snapshots + pins atomically, so a failure surfaces and a rerun retries.
                app(EntryPublication::class)->baseline($existing->refresh(), mirror: false);

                continue;
            }

            $created[] = $this->register($envelope, (string) file_get_contents($absolute), $relative);
        }

        $failed = $this->failures;

        return compact('created', 'skipped', 'reasserted', 'filled', 'ignored', 'failed', 'unresolved') + ['recognized' => $recognizedCount];
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
    /**
     * The origin a registered row is stamped with: a declared package source stamps `package:<vendor>`
     * (DM2, for the splicewire/tower docs bundle), a plain host disk source stamps `disk:<relative>` as
     * before. Both the create stamp and the re-assert's incoming origin read this, so DOC-5 precedence
     * and `docs.diverged` see the same origin the seed will.
     */
    protected function originFor(string $relative): string
    {
        return $this->package !== null
            ? Provenance::package($this->package)
            : Provenance::disk($relative);
    }

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
        // Born published, like a seeded page: no pin-less entry is created, and the create, the body and the pin commit
        // together, so a failure while pinning leaves no row behind and propagates (a rerun registers it again).
        $publication = app(EntryPublication::class);
        $publication->requireVersionStore();

        $entry = DB::transaction(function () use ($envelope, $source, $relative, $publication): BeamUxEntry {
            // DOCS-06: resolve containment first so its title feeds the asserted hash, then stamp
            // provenance LAST — `origin = disk:<relative>` (a host file) and the title+body hash the
            // import asserts, so a later host edit is detectable by `docs.diverged`. No-ops pre-migration.
            $containment = $this->containmentFor($source, $relative, $envelope);
            $entry = BeamUxEntry::create(array_merge([
                'slug' => $envelope['slug'],
                'type' => $envelope['type'],
                'namespace' => $envelope['namespace'],
                'format' => $envelope['format'],
            ], BeamUxEntry::publishedMarkingAttributes(), $containment, Provenance::stamp(
                $this->originFor($relative),
                $containment['title'] ?? null,
                $source,
                app(CodecRegistry::class)->for($envelope['format']),
            )));

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
            // A tsx file the Lens cannot lower is recorded like a compile failure and falls back to raw
            // source so the batch still registers it (ADR-0228 d9).
            // ADR-0233 d3/d4 — an MDX page that holds blocks takes its SEO fields and its Page Fields declaration from the
            // file's frontmatter too. They are not mass-assignable (page settings force-fill them at publish), so they
            // land here, after the create.
            if (($columns = $this->settingColumnsFor($source, $relative, $envelope)) !== []) {
                $entry->forceFill($columns)->save();
            }

            try {
                $this->writeBody($entry, $source);
                $this->keepFrontmatter($entry, $source, $relative);
            } catch (ValidationException $e) {
                $this->failures[$relative] = $e->errors()['source'][0] ?? 'the TSX lens could not lower the source.';
                $this->writeBody($entry, $source, lower: false);
            }

            // Compiles too; its compile error is kept with this run's failures below rather than thrown. No mirror: the
            // body was read from the file the mirror would write, and lowering is not byte-preserving.
            if (($error = $publication->publishWritten($entry->refresh(), 'registered', mirror: false)) !== null) {
                $this->failures[$relative] = $error;
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
        // Pinned entries were compiled by publishWritten(); only a non-particle entry (no version history) compiles here.
        if ($this->compile !== null && $entry->published_version === null) {
            try {
                $this->compile->forEntry($entry, $source, force: true);
            } catch (CompilationFailed $e) {
                $this->failures[$relative] = $e->getMessage();
            }
        }

        return $entry;
    }

    /**
     * Import a body into an existing empty entry, such as a nav-seeded page. Never overwrite a body.
     * Unlike scan(), which skips every existing entry, this is an explicit operator opt-in.
     * Compilation failure propagates after retaining the imported body, as on the regular import path.
     */
    public function hydrateEmpty(BeamUxEntry $entry, string $source): bool
    {
        $format = $entry->format;
        // Born published, like register(): refused before anything is written, and the body, the inference and the pin
        // commit together, so a failure while pinning leaves the entry empty and a retry hydrates it.
        $publication = app(EntryPublication::class);
        $publication->requireVersionStore();
        $compileError = null;

        $hydrated = $entry->getConnection()->transaction(function () use ($entry, $source, $format, $publication, &$compileError): bool {
            $current = $entry->newQuery()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            if ($current->particle_id !== null) {
                return false;
            }
            $current->format = $format;
            $this->writeBody($current, $source);
            $this->inference->forEntry($current, $source, persist: true);
            $compileError = $publication->publishWritten($current->refresh(), 'hydrated', mirror: false);

            return true;
        });

        // A body hydrated just now was compiled by publishWritten(); its compile error propagates, as it always has.
        if ($compileError !== null) {
            throw new CompilationFailed($compileError);
        }

        // Otherwise compile the stored body, including a retained body from a previous failed compilation.
        // Never compile the caller's candidate source after the lock has been released.
        if (! $hydrated || $entry->refresh()->published_version === null) {
            $this->compile?->forEntry($entry->refresh());
        }

        return $hydrated;
    }

    /**
     * ADR-0233 d9, EP-42 H2: an MDX file re-asserted on a host that holds MDX as blocks is lowered as a new row's is
     * ({@see writeBody()}), not encoded as source, so a pristine package page converts on the next registration. A lens
     * that cannot read it leaves today's encoding: the page keeps its source, and the MDX pages audit reports it. It is
     * not recorded as a failure, since a docs source seeder throws on one and a package page must not break a seed.
     */
    private function loweredForReassert(BeamUxEntry $entry, string $source): ?array
    {
        if ($entry->bodyKind() !== BodyKind::Blocks || $entry->format !== UxFormat::Mdx) {
            return null;
        }

        try {
            return app(SourceToBlocks::class)->convert(self::lowerable($entry, $source), [], app(ManifestProfileResolver::class)->resolve($entry), $entry->format);
        } catch (ValidationException) {
            return null;
        }
    }

    /** Both new registration and explicit empty-entry hydration use the same codec and storage seam. */
    private function writeBody(BeamUxEntry $entry, string $source, bool $lower = true): void
    {
        // ADR-0228 d9: a Blocks-kind (tsx) file is LOWERED to a JsonDoc through the Node TSX Lens — the
        // "future structural-import mechanism" this method used to defer to, which is why a disk `page`
        // once arrived as raw source. A fresh registration has no Working Head, so every node is minted a
        // fresh id. Every other kind encodes its text through the codec unchanged. `$lower = false` is the
        // batch's fallback when the Lens cannot read a file (see the caller).
        $body = ($lower && $entry->bodyKind() === BodyKind::Blocks)
            ? app(SourceToBlocks::class)->convert(self::lowerable($entry, $source), [], app(ManifestProfileResolver::class)->resolve($entry), $entry->format)
            : $entry->codec()->encode($source, $entry->body_style);
        $item = $this->drivers->resolveOnEntryConnection($entry)->write('', $body, $entry->namespace);
        if ($entry->particle_id === null && $item->key !== '') {
            $entry->particle_id = $item->key;
            $entry->save();
        }
    }

    /**
     * ADR-0233 d3/d4 — a blocks MDX page holds the frontmatter keys its parent's Page Fields declare, and keeps the
     * keys nothing declares, in its registered version's page settings, so the mirror prints them back. Registration's
     * own keys (`realm`, the rights, `workflow_marking`) are claimed here and land on columns as before. A key set the
     * page settings refuse (a page with no title, a Page Field its class refuses, a value the flat grammar cannot
     * carry) is recorded with this run's failures rather than kept half-way.
     */
    private function keepFrontmatter(BeamUxEntry $entry, string $source, string $relative): void
    {
        if (! MdxPageFile::applies($entry)) {
            return;
        }
        $unknown = array_diff_key($this->frontmatter($source), array_flip(EntryPageSettingsData::reservedFrontmatterKeys()));
        if ($unknown === []) {
            return;
        }
        $class = PageFields::forChild($entry->refresh());
        $settings = EntryPageSettingsData::fromEntry($entry);
        [$settings->fields, $settings->unclaimed] = PageFields::fromFrontmatter($class, $unknown);
        try {
            EntryPageSettingsData::validateAndCreate($settings->toArray());
            $settings->fields = PageFields::check($class, $settings->fields);
            MdxPageFile::assertPrintable($settings);
        } catch (ValidationException $e) {
            $this->failures[$relative] = 'frontmatter keys '.implode(', ', array_keys($unknown)).' were not kept: '
                .(collect($e->errors())->flatten()->first() ?? 'the page settings are not valid.');

            return;
        }
        $particle = $entry->particle;
        $meta = $particle->meta ?? [];
        $meta['pageSettings'] = $settings->toArray();
        $particle->meta = $meta;
        $particle->save();
    }

    /**
     * ADR-0233 d3 — what the lens reads from a disk file: an MDX file's body without its frontmatter, which
     * registration reads into the entry's own fields; any other file whole.
     */
    private static function lowerable(BeamUxEntry $entry, string $source): string
    {
        return $entry->format === UxFormat::Mdx ? MdxBody::encode($source)[MdxBody::CONTENT_KEY] : $source;
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
     * The SEO columns and the Page Fields declaration (`child_fields`, d4) an MDX page's frontmatter names, on a host
     * whose MDX pages hold blocks (ADR-0233 d3), where the frontmatter is the page's settings. Each is checked against
     * the page-settings rule for it and column-guarded like every other aspect field; a value the rule refuses is left
     * out and recorded with this run's failures.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     * @return array<string, string>
     */
    /**
     * The page settings a rescan fills on an existing page: those its file declares whose column is still empty. A
     * column that holds a value is never in it.
     *
     * @param  array{slug: string, type: ?string, namespace: ?string, format: string}  $envelope
     * @return array<string, string> column => value
     */
    private function emptySettings(BeamUxEntry $existing, string $source, string $relative, array $envelope): array
    {
        return array_filter(
            $this->settingColumnsFor($source, $relative, $envelope),
            fn (mixed $value, string $column): bool => $existing->getAttribute($column) === null || $existing->getAttribute($column) === '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    protected function settingColumnsFor(string $source, string $relative, array $envelope): array
    {
        if ($envelope['type'] !== UxType::Page->value || $envelope['format'] !== UxFormat::Mdx->value || ! config('beam.ux.mdx.holds_blocks')) {
            return [];
        }
        $fm = $this->frontmatter($source);
        // This estate authors a page's summary as `excerpt`; a declared `description` wins over it.
        if ((! isset($fm['description']) || $fm['description'] === '') && isset($fm['excerpt'])) {
            $fm['description'] = $fm['excerpt'];
        }
        $rules = EntryPageSettingsData::rules();
        $out = [];
        foreach (['description' => 'seo_description', 'social_image' => 'seo_social_image', 'page_type' => 'seo_page_type', 'published_date' => 'seo_published_date', 'child_fields' => 'child_fields'] as $field => $column) {
            if (! isset($fm[$field]) || $fm[$field] === '' || ! Schema::hasColumn('beam_ux_entries', $column)) {
                continue;
            }
            $setting = Str::camel($field);
            if (Validator::make([$setting => $fm[$field]], [$setting => $rules[$setting]])->fails()) {
                $this->failures[$relative] = "frontmatter `{$field}` is not a valid page setting; it was left out.";

                continue;
            }
            $out[$column] = $fm[$field];
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
     * The scalar frontmatter of a body source, in the CANONICAL (snake_case) spelling.
     *
     * Reads through the shared {@see FrontmatterParser} (frontmatter-declaration-seam ticket 04). The
     * local reader this replaces was one of five copies of the same two regexes; the note that used to
     * live here — "kept local so the disk seam takes no hard dependency on the mdx package" — had gone
     * stale, because this package has required `splicewire/laravel-beam-mdx` outright for some time.
     *
     * ⚠️ **Canonical, not authored, and that is the bug fix.** Every lookup below is snake
     * (`nav_order`, `nav_group`, …) while the flagship's content authors `navOrder:` and `navGroup:`.
     * The key pattern always admitted camelCase, so those fields parsed cleanly and were then never
     * looked up — dropped with no error, no warning, and a successful import. Canonicalizing here is
     * what makes an authored spelling reach its column.
     *
     * Contrast the sibling readers deliberately: `Mdx::fields()` and `MdxBody::split()` keep the
     * AUTHORED keys, because one is a public array contract tower reads camelCase and the other is a
     * persisted body that must round-trip byte-for-byte. The choice is per-reader, on what its output
     * IS — not a preference.
     *
     * @return array<string, string>
     */
    protected function frontmatter(string $source): array
    {
        return app(FrontmatterParser::class)->parse($source)->fields;
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
     * What {@see scan()} would do under `$root`, without writing: each recognized file (relative path) is either
     * `matched` (a row already holds its envelope's `namespace` + `slug`) or `unregistered`; `ignored` is not a body
     * format; `unresolved` has no inferrable `type` and no `$default` (scan() would refuse the whole tree for it).
     * The same files, envelopes and idempotency key as scan(), so `register-from-disk --dry-run` and the
     * `docs.unregistered` audit cannot disagree with the importer (docs-walkthrough DOCS-01, DOC-3).
     *
     * @param  list<string>  $only  Exact root-relative files; selected plans also expose entries and sha256.
     * @return array{matched: list<string>, unregistered: list<string>, ignored: list<string>, unresolved: list<string>}
     */
    public function plan(string $root, ?UxType $default = null, array $ignore = [], array $only = [], ?BeamUxEntry $under = null): array
    {
        if ($only !== []) {
            return $this->selectedPlan($root, $default, $ignore, $only, $under);
        }

        $plan = ['matched' => [], 'unregistered' => [], 'ignored' => [], 'unresolved' => []];
        $root = rtrim($root, '/');
        if (! is_dir($root)) {
            return $plan;
        }

        foreach ($this->files($root) as $absolute) {
            $relative = ltrim(substr($absolute, strlen($root)), '/');
            $envelope = ! self::ignores($ignore, $relative) && $this->disk->recognizes($relative) ? $this->disk->envelopeForPath($relative) : null;
            if ($envelope === null) {
                $plan['ignored'][] = $relative;

                continue;
            }
            if ($envelope['type'] === null && $default === null) {
                $plan['unresolved'][] = $relative;
            }
            $plan[$this->existing($envelope) === null ? 'unregistered' : 'matched'][] = $relative;
        }

        return array_map(function (array $paths): array {
            sort($paths);

            return $paths;
        }, $plan);
    }

    /**
     * The page settings an unfiltered {@see scan()} of `$root` would fill, without writing: each matched page whose
     * file declares settings whose columns are still empty, and those columns. The same files, envelopes (the operator's
     * `$default` type included) and rule as scan(), so `register-from-disk --dry-run` reports what a run will do.
     *
     * @return array<string, list<string>> relative path => columns
     */
    public function plannedFills(string $root, ?UxType $default = null, array $ignore = []): array
    {
        $fills = [];
        $root = rtrim($root, '/');
        if (! is_dir($root)) {
            return $fills;
        }

        foreach ($this->files($root) as $absolute) {
            $relative = ltrim(substr($absolute, strlen($root)), '/');
            $envelope = ! self::ignores($ignore, $relative) && $this->disk->recognizes($relative) ? $this->disk->envelopeForPath($relative) : null;
            if ($envelope === null || ($existing = $this->existing($envelope)) === null) {
                continue;
            }
            $envelope['type'] ??= $default?->value;
            if (($empty = $this->emptySettings($existing, (string) file_get_contents($absolute), $relative, $envelope)) !== []) {
                $fills[$relative] = array_keys($empty);
            }
        }
        ksort($fills);

        return $fills;
    }

    /** Selected mode never reasserts, baselines or reparents an existing entry. */
    private function scanSelected(string $root, ?BeamUxEntry $under, ?UxType $default, array $ignore, ?string $package, array $only, ?string $expectedPlan): array
    {
        $plan = $this->selectedPlan($root, $default, $ignore, $only, $under);
        if ($expectedPlan !== null && ! hash_equals($plan['sha256'], $expectedPlan)) {
            throw new InvalidArgumentException('Selected registration plan changed; dry-run again. Nothing was imported.');
        }
        if ($plan['unresolved'] !== []) {
            throw new InvalidArgumentException('Selected files have no type: '.implode(', ', $plan['unresolved']));
        }
        // Capture the complete selected source snapshot before the first write. A later disk edit is
        // not silently substituted for the bytes whose hash was planned; external editors are not locked.
        $sources = [];
        foreach ($plan['entries'] as $item) {
            $source = file_get_contents($plan['root'].'/'.$item['path']);
            if ($source === false || ! hash_equals($item['source_sha256'], hash('sha256', $source))) {
                throw new InvalidArgumentException('Selected source changed; dry-run again. Nothing was imported.');
            }
            $sources[$item['path']] = $source;
        }
        $this->failures = [];
        $this->seen = [];
        $this->under = $under;
        $this->package = $package;
        $created = [];
        $skipped = [];
        foreach ($plan['entries'] as $item) {
            $existing = $this->existing($item['envelope']);
            if ($existing !== null) {
                $this->seen[$this->key($existing->namespace, $existing->slug)] = $existing;
                $skipped[] = $item['path'];

                continue;
            }
            $created[] = $this->register($item['envelope'], $sources[$item['path']], $item['path']);
        }

        return ['created' => $created, 'skipped' => $skipped, 'reasserted' => [], 'ignored' => [],
            'failed' => $this->failures, 'unresolved' => [], 'recognized' => count($plan['entries']), 'plan_sha256' => $plan['sha256']];
    }

    /** Same native selection and containment calculation for dry-run and selected application. */
    private function selectedPlan(string $root, ?UxType $default, array $ignore, array $only, ?BeamUxEntry $under): array
    {
        $root = realpath($root);
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('The selection requires an existing scan root.');
        }
        $all = [];
        foreach ($this->files($root) as $absolute) {
            $all[ltrim(substr($absolute, strlen($root)), '/')] = $absolute;
        }
        $selected = [];
        foreach ($only as $relative) {
            if (! is_string($relative) || $relative === '' || preg_match('/[\\\\*?\[\]{}:]/', $relative)
                || str_starts_with($relative, '/') || array_intersect(explode('/', $relative), ['', '.', '..']) !== []) {
                throw new InvalidArgumentException('Each --only must be an exact root-relative file path.');
            }
            if (isset($selected[$relative])) {
                throw new InvalidArgumentException("Duplicate selection: {$relative}");
            }
            $aliases = array_filter(array_keys($all), fn (string $path): bool => strcasecmp($path, $relative) === 0);
            if (count($aliases) !== 1 || ! isset($all[$relative])) {
                throw new InvalidArgumentException("Unknown or ambiguous selection: {$relative}");
            }
            $walk = $root;
            foreach (explode('/', $relative) as $piece) {
                $walk .= '/'.$piece;
                if (is_link($walk)) {
                    throw new InvalidArgumentException("Symlink selection is unsupported: {$relative}");
                }
            }
            $absolute = realpath($all[$relative]);
            $envelope = $this->disk->envelopeForPath($relative);
            if ($absolute === false || ! str_starts_with($absolute, $root.'/') || ! is_file($absolute)
                || self::ignores($ignore, $relative) || $envelope === null) {
                throw new InvalidArgumentException("Not an eligible body file: {$relative}");
            }
            // A second format at the same coordinate is ambiguous even when only one is requested.
            foreach (array_keys($all) as $other) {
                if ($other !== $relative && $this->disk->envelopeForPath($other) !== null) {
                    $alias = $this->disk->envelopeForPath($other);
                    if ($this->key($alias['namespace'], $alias['slug']) === $this->key($envelope['namespace'], $envelope['slug'])) {
                        throw new InvalidArgumentException("Ambiguous body envelope: {$relative} and {$other}");
                    }
                }
            }
            $envelope['type'] ??= $default?->value;
            $selected[$relative] = $envelope;
        }
        uksort($selected, fn (string $a, string $b): int => ($this->depth($selected[$a]) <=> $this->depth($selected[$b])) ?: strcmp($a, $b));
        $this->seen = [];
        $this->under = $under;
        $plan = ['root' => $root, 'matched' => [], 'unregistered' => [], 'ignored' => [], 'unresolved' => [], 'entries' => []];
        $addresses = [];
        foreach ($selected as $relative => $envelope) {
            $matches = BeamUxEntry::query()->where('namespace', $envelope['namespace'])->where('slug', $envelope['slug'])->get();
            if ($matches->count() > 1) {
                throw new InvalidArgumentException("Ambiguous existing envelope: {$relative}");
            }
            $existing = $matches->first();
            $source = file_get_contents($root.'/'.$relative);
            if ($source === false) {
                throw new InvalidArgumentException("Cannot read selected body: {$relative}");
            }
            $placement = $this->containmentFor($source, $relative, $envelope);
            $fm = $this->frontmatter($source);
            $parent = $this->parentFor($envelope, $fm['realm'] ?? $this->realmByConvention($relative));
            $node = $existing ?? new BeamUxEntry(array_merge($envelope, $placement));
            if ($existing === null) {
                $node->setRelation('parent', $parent);
            }
            $chain = [];
            $cursor = $node;
            $visited = [];
            while ($cursor !== null) {
                $identity = $cursor->getKey() ?? spl_object_id($cursor);
                if (isset($visited[$identity])) {
                    throw new InvalidArgumentException("Cyclic selected parent chain: {$relative}");
                }
                $visited[$identity] = true;
                array_unshift($chain, $cursor);
                $cursor = $cursor->parent;
            }
            $address = app(UrlResolver::class)->resolveChain($chain);
            if ($existing === null && $envelope['type'] === UxType::Page->value && ($placement['segment'] ?? '') !== '') {
                $realm = $placement['realm'] ?? BeamUxEntry::REALM_SITE;
                $addressKey = $realm.'|'.$address;
                if (isset($addresses[$addressKey])) {
                    throw new InvalidArgumentException("Selected public address collision: {$address}");
                }
                foreach (BeamUxEntry::query()->where('type', UxType::Page->value)->get() as $candidate) {
                    if ($candidate->realm !== $realm && ! in_array($realm, $candidate->realms ?? [], true)) {
                        continue;
                    }
                    $ancestry = app(EntryPathResolver::class)->ancestry($candidate);
                    if ($ancestry !== null && app(UrlResolver::class)->resolveChain($ancestry) === $address) {
                        throw new InvalidArgumentException("Selected public address already occupied: {$address}");
                    }
                }
                $addresses[$addressKey] = true;
            }
            $plan[$existing === null ? 'unregistered' : 'matched'][] = $relative;
            if ($envelope['type'] === null) {
                $plan['unresolved'][] = $relative;
            }
            $plan['entries'][] = ['path' => $relative, 'source_sha256' => hash('sha256', $source), 'envelope' => $envelope,
                'action' => $existing === null ? 'register' : 'skip-existing', 'existing_id' => $existing?->getKey(),
                'inferred_parent' => $parent === null ? null : ['id' => $parent->getKey(), 'namespace' => $parent->namespace, 'slug' => $parent->slug],
                'parent' => ($actual = $node->parent) === null ? null : ['id' => $actual->getKey(), 'namespace' => $actual->namespace, 'slug' => $actual->slug],
                'placement' => $existing === null ? $placement : array_intersect_key($existing->getAttributes(), array_flip(['parent_id', 'realm', 'segment', 'nav_order', 'title'])),
                'composed_path' => $address,
                'ancestry' => array_map(fn (BeamUxEntry $ancestor): array => ['id' => $ancestor->getKey(), 'namespace' => $ancestor->namespace, 'slug' => $ancestor->slug, 'parent_id' => $ancestor->parent_id, 'realm' => $ancestor->realm, 'segment' => $ancestor->segment], $chain)];
            $this->seen[$this->key($envelope['namespace'], $envelope['slug'])] = $node;
        }
        $plan['sha256'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    /**
     * Whether `$relative` (a path under the scan root) matches one of a source's `ignore` globs (docs-walkthrough DOCS-05).
     * `**` crosses directories (a leading `**` plus slash also matches zero of them, as in .gitignore), `*` and `?` stay
     * within one segment; an ignored file is reported `ignored`, never imported.
     *
     * @param  list<string>  $globs
     */
    public static function ignores(array $globs, string $relative): bool
    {
        foreach ($globs as $glob) {
            $pattern = preg_replace_callback('/\*\*\/|\*\*|\*|\?|[^*?]+/', fn (array $m): string => match ($m[0]) {
                '**/' => '(?:.*/)?',
                '**' => '.*',
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($m[0], '#'),
            }, ltrim((string) $glob, '/'));

            if (preg_match('#^'.$pattern.'$#', $relative) === 1) {
                return true;
            }
        }

        return false;
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
