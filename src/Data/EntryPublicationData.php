<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * The entry's PUBLICATION STATE — the one projection all four draft/publish operations return, so the
 * dock re-seeds its whole affordance (the draft badge, the versions list, which row is restorable) from
 * whatever the server actually did, in the same round trip. The same discipline
 * {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} states for its own re-read: echoing back what the
 * client sent would prove nothing.
 *
 * The two pointers are the whole model ({@see EntryPublication}). `headVersion` is the newest recorded
 * version — the body an author is editing. `publishedVersion` is the one compiled to the public
 * artifact — the body a guest reads. `draftPending` is the two disagreeing, computed here rather than
 * stored, so no writer can leave it lying.
 *
 * `compileError` is transient compilation feedback on the returned state. Draft recording compiles
 * at the new version address and an expected CompilationFailed retains that draft while the published
 * pin stays unchanged. This is not durable diagnostic history: a later plain state read defaults null
 * and does not establish that compilation has recovered. Release publication compiles its selected
 * fixed versions before moving their pins.
 */
#[TypeScript]
#[Description("An entry's publication state: the working HEAD, the published version, whether a draft is pending, and the recorded version history.")]
class EntryPublicationData extends BeamData
{
    /**
     * @param  list<EntryVersionData>  $versions  newest-first, as the store returns them
     */
    public function __construct(
        #[Description("The entry's uuid — the address every one of these operations is mounted at.")]
        public string $id,
        #[Description('Whether an unpublished draft exists: the working HEAD is not the published version.')]
        public bool $draftPending,
        #[Description('The id of the version a guest reader is currently served, or null when the entry has never been published through this path.')]
        public ?string $publishedVersion,
        #[Description('The readable handle of the published version (e.g. `v2`), or null.')]
        public ?string $publishedReadable,
        #[Description('The id of the working HEAD — the newest recorded version of the body an author edits.')]
        public ?string $headVersion,
        #[Description('The readable handle of the working HEAD (e.g. `v3`), or null.')]
        public ?string $headReadable,
        #[DataCollectionOf(EntryVersionData::class)]
        #[Description("The entry's recorded version history, newest-first.")]
        public array $versions = [],
        #[Description('Transient compilation feedback on this response, or null; a later null is not stored diagnostic history or evidence of recovery.')]
        public ?string $compileError = null,
        /** @var list<string> */
        #[Description('Profile legality the restored version breaks (an unknown block, illegal containment, a schema fail), by node id and path. A restore records these and does not refuse (ADR-0228 d9); also reports pages stranded by a saved component interface change.')]
        public array $diagnostics = [],
        #[Description('The Release created by a publish request; its workflow may still require review.')]
        public ?ReleaseData $release = null,
    ) {}
}
