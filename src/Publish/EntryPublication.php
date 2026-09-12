<?php

namespace Splicewire\Beam\Ux\Publish;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Ux\Codec\AcceptsJsonDoc;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Data\EntryVersionData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PolicyWriteGate;

/**
 * **Draft and published, as two pins into one version history.**
 *
 * Every recorded save of an entry's body mints an immutable {@see Version} of its has-a
 * {@see BeamParticle} through `rushing/laravel-versioning`'s one {@see VersionStore}. Two pointers then
 * say which of those versions each audience is on:
 *
 *  - `beam_particles.head_version` — the WORKING HEAD, the body an author edits. The versioning
 *    package already owns and advances it; nothing here writes it directly.
 *  - `beam_ux_entries.published_version` — the PUBLISHED version, the body a guest reads.
 *    {@see \Splicewire\Beam\Ux\Compile\EntryArtifactStore::version()} keys the compiled artifact's
 *    address on it, so moving this pin IS publishing and leaving it still IS drafting.
 *
 * A draft is pending when the two disagree. That is derived on read ({@see state()}), never stored, so
 * there is no flag a failed write can leave lying about a body that is actually live.
 *
 * ## Why not a second store, a second body column, or the workflow marking
 *
 * A second body column (`draft_payload`) would fork the particle's whole migrate-on-read discipline for
 * the sake of one pointer, and would have to be reconciled with the version history that already holds
 * exactly the same bodies. `workflow_marking` (S6) answers a different question — whether the entry is
 * visible AT ALL — and an entry with a pending draft must stay visible, serving its previous body; a
 * host that binds the publish lifecycle keeps that marking governing the node's visibility and this pin
 * governing which recorded body the visible node serves. The two compose and neither subsumes the other.
 *
 * ## The BASELINE, and why the first draft is not the first version
 *
 * An entry that predates this path has a body and no versions. Recording a draft over it would leave the
 * previously-published body unrecorded — restorable from nothing, and the pin would have nothing to
 * point at. So the first version recorded for an entry is the body that was ALREADY live
 * ({@see baseline()}), minted before the draft is written and pinned as published, and the artifact is
 * recompiled at the address that pin now names. The recompile is not optional: the pin changes the
 * artifact's address, and the file is at the old one.
 *
 * ## What a publish actually does, in order
 *
 *  1. mint a version of the working copy (or reuse HEAD when HEAD already IS the working copy — a
 *     publish straight after a draft pins that draft rather than duplicating it);
 *  2. move the publication pin to it;
 *  3. mirror the body to its placed disk file — the mirror follows the PUBLISHED body, so a draft never
 *     changes the git-trackable file;
 *  4. compile the artifact, at the address step 2 just named.
 *
 * A failed compile does not fail the publish, for the reason {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp}
 * states at length: the write has landed, the author gets the compiler's own message, and absence is
 * reported by the doctor rather than degraded into a client-side compile.
 */
class EntryPublication
{
    public function __construct(
        private VersionStore $versions,
        private StorageDriverResolver $drivers,
        private PlacedDiskMirror $mirror,
        private PlacementResolver $placements,
    ) {}

    /**
     * The entry's current publication state — both pins, the derived draft flag, and the history.
     *
     * An entry with no particle has no history and no pins: nothing has ever been written, so there is
     * nothing to have drafted or published.
     */
    public function state(BeamUxEntry $entry, ?string $compileError = null): EntryPublicationData
    {
        $particle = $this->recordsVersions() ? $this->particle($entry) : null;

        if ($particle === null) {
            return new EntryPublicationData(
                id: (string) $entry->getKey(),
                draftPending: false,
                publishedVersion: null,
                publishedReadable: null,
                headVersion: null,
                headReadable: null,
                versions: [],
                compileError: $compileError,
            );
        }

        $head = $this->versions->head($particle);
        $published = $this->publishedVersionId($entry);

        $rows = $this->versions->versions($particle);

        $publishedRow = $rows->first(static fn (Version $v): bool => (string) $v->id === (string) $published);

        return new EntryPublicationData(
            id: (string) $entry->getKey(),
            draftPending: $head !== null && (string) $head->id !== (string) $published,
            publishedVersion: $published,
            publishedReadable: $publishedRow?->readableVersion(),
            headVersion: $head === null ? null : (string) $head->id,
            headReadable: $head?->readableVersion(),
            versions: $rows->map(fn (Version $version): EntryVersionData => EntryVersionData::fromVersion(
                $version,
                isHead: $head !== null && (string) $version->id === (string) $head->id,
                isPublished: $published !== null && (string) $version->id === (string) $published,
            ))->values()->all(),
            compileError: $compileError,
        );
    }

    /**
     * Write a body to the entry's working copy and RECORD it as a version — without publishing it.
     *
     * Nothing downstream of the particle moves: no disk mirror, no compile, no pin. The public artifact
     * stays exactly where {@see baseline()} left it, which is what makes the draft invisible to a reader
     * rather than merely unannounced.
     *
     * @param  array<string, mixed>  $body
     */
    public function recordDraft(BeamUxEntry $entry, array $body, ?string $label = null): EntryPublicationData
    {
        $this->assertStorable($entry, $body);
        $this->requireVersionStore();
        $this->baseline($entry);
        $this->write($entry, $body);
        $this->versions->snapshot($this->requireParticle($entry), $label ?? 'draft');

        return $this->state($entry->refresh());
    }

    /**
     * Publish the entry's working copy: pin it, mirror it, compile it.
     *
     * Returns the state including any compile diagnostic — the publish itself has already landed by then
     * and stays landed.
     */
    public function publish(BeamUxEntry $entry, ?string $label = null): EntryPublicationData
    {
        $this->baseline($entry);

        $particle = $this->recordsVersions() ? $this->particle($entry) : null;

        if ($particle === null) {
            if ($entry->particle_id !== null) {
                // No version store here: publish is then what it has always been — mirror the body and
                // compile it at the unpinned address. See {@see recordsVersions()}.
                return $this->state($entry, $this->mirrorAndCompile($entry));
            }

            // Nothing has ever been written to this entry, so there is no body to publish. Reporting the
            // empty state is the honest answer; minting an empty version would manufacture history.
            return $this->state($entry);
        }

        $version = $this->versionForWorkingCopy($particle, $label ?? 'published');

        return $this->state($entry->refresh(), $this->pinAndCompile($entry, $version));
    }

    /**
     * Roll the entry FORWARD to a recorded version and publish the result.
     *
     * Forward, never back: the store applies the frozen snapshot onto the working copy and moves HEAD to
     * it, and a fresh version of the result is then recorded, so history is appended to rather than
     * rewound — `rushing/laravel-versioning`'s own documented restore shape, and
     * `splicewire/laravel-beam-versioning`'s controller makes the same pair of moves. What is added here
     * is the fourth step that a record-agnostic restore cannot know about: the restored body is
     * published, so the disk mirror and the compiled artifact follow it. A restore that moved the
     * particle alone would leave the page serving a body nobody can produce again.
     *
     * @throws ValidationException when no such version belongs to this entry
     */
    public function restore(BeamUxEntry $entry, string $ref, ?string $label = null): EntryPublicationData
    {
        $this->requireVersionStore();

        $particle = $this->particle($entry);

        $version = $particle === null ? null : $this->versions->version($particle, $ref);

        if ($particle === null || $version === null) {
            throw ValidationException::withMessages([
                'ref' => "No version [{$ref}] belongs to this entry; there is nothing to restore.",
            ]);
        }

        $this->versions->restore($particle, (string) $version->id);

        $fresh = $this->requireParticle($entry->refresh());
        $minted = $this->versions->snapshot($fresh, $label ?? "restore of {$version->readableVersion()}");

        return $this->state($entry->refresh(), $this->pinAndCompile($entry, $minted));
    }

    /**
     * Publish a body the caller has ALREADY written — the immediate-publish path
     * {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} takes, where a save is its own publish.
     *
     * Separate from {@see publish()} only in that it skips the baseline: the caller has already written
     * over the working copy, so the body a baseline would freeze is gone. The save op takes the baseline
     * itself, before its write, for exactly that reason.
     */
    public function publishWritten(BeamUxEntry $entry, ?string $label = null): ?string
    {
        if ($entry->particle_id === null) {
            return null;
        }

        $particle = $this->recordsVersions() ? $this->particle($entry) : null;

        if ($particle === null) {
            return $this->mirrorAndCompile($entry);
        }

        return $this->pinAndCompile($entry, $this->versionForWorkingCopy($particle, $label ?? 'published'));
    }

    /**
     * Record the CURRENT published body as the entry's first version, before anything overwrites it.
     *
     * A no-op for an entry that already has history, or one that has never been written at all. For the
     * rest — every entry authored before this path existed — it is what makes the previously-live body
     * restorable and gives the publication pin something true to point at. The artifact is recompiled
     * because pinning moves its address; the body being compiled is the one already on the page, so a
     * reader sees nothing change.
     */
    public function baseline(BeamUxEntry $entry): void
    {
        $particle = $this->recordsVersions() ? $this->particle($entry) : null;

        if ($particle === null || $this->publishedVersionId($entry) !== null) {
            return;
        }

        if ($this->versions->versions($particle)->isNotEmpty()) {
            return;
        }

        $this->pinAndCompile($entry, $this->versions->snapshot($particle, 'baseline'));
    }

    /**
     * Refuse a **canvas document written onto a format that cannot carry one** — 422 on `body`, before
     * anything lands. The same refusal on the same field for every write path, because they are the
     * same write; it lived on {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} when that op was the
     * only one.
     *
     * This is the one shape check a declared input cannot make. The write DTOs validate the payload
     * against themselves (`present`, `array`), and by that standard a `JsonNode[]` list is a perfectly
     * good body; what makes it wrong is the ENTRY it is aimed at, which those DTOs deliberately do not
     * carry (ADR-0214 §2 — the entry is addressed by `{id}` on the route). So the check lives at the
     * first point that holds both halves, and reuses the input's own 422 envelope rather than inventing
     * a second refusal shape for a client to learn.
     *
     * Measured, 2026-09-11 (G2-BEAM-AUTHOR-ENTRY): the dock offered the canvas on the mdx `/docs` entry;
     * Save wrote its JsonDoc over the `{frontmatter,content}` particle payload; `MdxBodyCodec::decode()`
     * found neither key, so the disk mirror wrote 0 bytes and the compile produced an empty artifact,
     * and `/docs` went blank for every visitor. Nothing downstream can recover from that — the mirror
     * and the compiler are both faithfully projecting a source-of-record that has already lost the
     * source. A DRAFT path that skipped the guard would be strictly worse than no guard: the damage
     * would land at draft time and first become visible to whoever pressed Publish.
     *
     * The question asked is a codec CAPABILITY ({@see AcceptsJsonDoc}), never a format name: a host's
     * own `UxFormatCase` earns the canvas by implementing the marker, and beam-ux never learns its name.
     *
     * @param  array<string, mixed>  $body
     */
    public function assertStorable(BeamUxEntry $entry, array $body): void
    {
        if (! JsonDocShape::is($body) || $entry->codec() instanceof AcceptsJsonDoc) {
            return;
        }

        $format = $entry->getAttribute('format');
        $format = is_object($format) && property_exists($format, 'value') ? (string) $format->value : (string) $format;

        throw ValidationException::withMessages([
            'body' => sprintf(
                'This entry is authored as %s source, which cannot carry a canvas document. Saving one '.
                'here would replace the source with an empty file. Edit it as %s source, or change the '.
                "entry's format first.",
                $format,
                $format,
            ),
        ]);
    }

    /**
     * Write a body to the entry's particle, binding a first-write `particle_id`.
     *
     * A request-scoped {@see ParticleStorageDriver} over a permissive {@see PolicyWriteGate}, identical
     * to the one the save op has always built — this is an authenticated editor write behind the host's
     * auth middleware and the operation's declared `ability`, not an anonymous submission.
     *
     * @param  array<string, mixed>  $body
     * @return array{key: string, body: array<string, mixed>}
     */
    public function write(BeamUxEntry $entry, array $body): array
    {
        $written = $this->authoringDriver()->write(
            (string) ($entry->particle_id ?? ''),
            $body,
            $entry->namespace,
        );

        if ($entry->particle_id === null && $written->key !== '') {
            $entry->particle_id = $written->key;
            $entry->save();
        }

        return ['key' => $written->key, 'body' => $written->body];
    }

    /**
     * Can this host record versions and hold a publication pin at all?
     *
     * Both halves are OPTIONAL schema this package has always guarded rather than assumed — the same
     * `Schema::hasColumn` tolerance {@see BeamUxEntry::publishedMarkingAttributes()} gives the workflow
     * marking. The version table is beam-CORE's (`beam_versions`, published under `beam-migrations`) and
     * the pin is this package's (`beam-ux-migrations`), and a host can legitimately be mid-way through
     * either.
     *
     * **Where this returns false, the behaviour is exactly the pre-pin behaviour**: a save mirrors and
     * compiles at the unpinned address, and it is live when it returns. That is a degrade to a contract
     * that was correct for years, not a silent degrade of THIS one — the capability that cannot work
     * ({@see recordDraft()}, {@see restore()}) refuses out loud with the remedy rather than quietly
     * publishing something an author asked to keep back. Which of the two a host gets is therefore
     * never ambiguous: the draft door says so, and the save door never claimed to do this.
     */
    public function recordsVersions(): bool
    {
        return Schema::hasTable((new Version)->getTable())
            && Schema::hasColumn('beam_ux_entries', 'published_version');
    }

    /**
     * Refuse a draft/restore on a host that cannot record one, naming both halves and the remedy.
     *
     * A publish-gate claim this package cannot verify is exactly the shape that reads green while the
     * table is absent, so the refusal states what it checked rather than "versioning unavailable".
     */
    private function requireVersionStore(): void
    {
        if ($this->recordsVersions()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Entry draft/publish needs the version store and the publication pin, and this host has %s. '
            .'Publish and run the migrations: `vendor:publish --tag=beam-migrations --tag=beam-ux-migrations` '
            .'then `migrate`.',
            implode(' and ', array_filter([
                Schema::hasTable((new Version)->getTable()) ? null : "no [{$this->versionTable()}] table",
                Schema::hasColumn('beam_ux_entries', 'published_version') ? null : 'no [beam_ux_entries.published_version] column',
            ])),
        ));
    }

    private function versionTable(): string
    {
        return (new Version)->getTable();
    }

    /** The id the entry's publication pin holds, or null on a host whose column predates it. */
    public function publishedVersionId(BeamUxEntry $entry): ?string
    {
        $pin = $entry->getAttribute('published_version');

        return $pin === null || $pin === '' ? null : (string) $pin;
    }

    /**
     * The version to publish: HEAD when HEAD already froze exactly the working copy (a publish straight
     * after a draft pins that draft rather than minting a twin of it), otherwise a fresh snapshot.
     *
     * The comparison is against {@see BeamParticle::toVersionSnapshot()} — the record's own statement of
     * what a version freezes — so a writer that bypasses this service (a disk import, a console
     * backfill) is correctly seen as having moved the working copy, and its body is recorded before it
     * is published.
     */
    private function versionForWorkingCopy(BeamParticle $particle, string $label): Version
    {
        $head = $this->versions->head($particle);

        if ($head !== null && $head->snapshot == $particle->toVersionSnapshot()) {
            return $head;
        }

        return $this->versions->snapshot($particle, $label);
    }

    /**
     * Move the publication pin to `$version`, mirror the body to disk, and compile the artifact at the
     * address the pin now names. Returns the compile diagnostic, or null.
     *
     * Order is load-bearing: the pin is written FIRST, because the artifact store hashes it into the
     * artifact's path, so compiling before pinning would file the module at the outgoing address.
     */
    private function pinAndCompile(BeamUxEntry $entry, Version $version): ?string
    {
        $entry->setAttribute('published_version', (string) $version->id);
        $entry->save();

        return $this->mirrorAndCompile($entry);
    }

    /**
     * The two producers every publish ends in, whatever pinned it: project the published body to its
     * placed disk file, then compile the artifact at the entry's current address.
     *
     * Split out because this IS the whole of publishing on a host with no version store — the pin is
     * the only step that needs one.
     */
    private function mirrorAndCompile(BeamUxEntry $entry): ?string
    {
        $item = $entry->particle_id === null
            ? null
            : $this->drivers->resolve($entry)->read((string) $entry->particle_id);

        $this->mirror->mirror(
            $entry,
            $this->placements->resolve($entry)->pathFor($entry),
            $item?->body ?? [],
        );

        try {
            // Resolved lazily rather than injected, so this service still constructs on a host that has
            // bound no compiler at all — the same reason the save op has always resolved it this way.
            app(CompileEntryBody::class)->forEntry($entry->refresh(), force: true);

            return null;
        } catch (CompilationFailed $e) {
            return $e->getMessage();
        }
    }

    /** The entry's has-a particle, or null when nothing has ever been written to it. */
    private function particle(BeamUxEntry $entry): ?BeamParticle
    {
        if ($entry->particle_id === null) {
            return null;
        }

        $versionable = $entry->versionable();

        return $versionable instanceof BeamParticle ? $versionable : null;
    }

    /** The particle, after a write that must have minted one. */
    private function requireParticle(BeamUxEntry $entry): BeamParticle
    {
        $entry->unsetRelation('particle');

        return $this->particle($entry) ?? throw new RuntimeException(
            "Entry [{$entry->getKey()}] has no particle after a write; nothing can be versioned."
        );
    }

    private function authoringDriver(): ParticleStorageDriver
    {
        return new ParticleStorageDriver(new ParticleWriter(
            new PolicyWriteGate(app(Gate::class)),
            app(SchemaTargetResolver::class),
            app(AcceptanceGate::class),
            app(Dispatcher::class),
        ));
    }
}
