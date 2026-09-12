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
 * `compileError` mirrors {@see BeamUxEntryBodyData}'s field verbatim, and for the same reason: a publish
 * whose body does not compile still LANDS (the version is recorded and the pin moves), and the author
 * gets the compiler's own message rather than a refused write. A draft never compiles at all, so the
 * field is null on that path by construction, not by omission.
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
        #[Description('Why the published body could not be compiled to its artifact, or null when it did (and null on every draft path, which never compiles).')]
        public ?string $compileError = null,
    ) {}
}
