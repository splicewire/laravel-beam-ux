<?php

namespace Splicewire\Beam\Ux\Data;

use Rushing\Versioning\Models\Version;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Particle\EntryVersionShowOp;

/**
 * One recorded version of an entry's body WITH its frozen `snapshot` — what {@see EntryVersionShowOp} returns, so the
 * document editor can compare a version with the body in hand.
 *
 * It carries {@see EntryVersionData}'s identity and pointer fields plus the tree. It is a separate class rather than
 * a widened {@see EntryVersionData} because the list must stay small (a history panel carries every version, the
 * frozen tree for at most one). It is not `splicewire/laravel-beam-versioning`'s `VersionSnapshotData` for the reason
 * {@see EntryVersionData} gives: beam-ux does not require that package, and an `output:` slot is read by reflection at
 * every host's boot.
 *
 * `snapshot` is the version exactly as the store froze it (for an entry: `payload`, plus `meta` where recorded). It
 * is untyped here, as it is in the record-agnostic projection; the editor reads `snapshot.payload` as the body.
 * Release recordings are left out: they belong to the list row, not to one version's contents.
 */
#[TypeScript]
#[Title('Entry version snapshot')]
#[Description('One recorded version of an entry body together with its frozen snapshot.')]
class EntryVersionSnapshotData extends BeamData
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        #[Example('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')]
        public string $id,
        #[Description('The per-entry monotonic version number (1, 2, 3, …).')]
        #[Example(3)]
        public int $version,
        #[Description('The human-readable handle for this version (default `v{n}`).')]
        #[Example('v3')]
        public string $readable,
        #[Description('The frozen snapshot this version holds, exactly as recorded; the entry body is `snapshot.payload`.')]
        public array $snapshot,
        #[Description('An optional human annotation — the only mutable field on a version.')]
        #[Example('draft')]
        public ?string $label = null,
        #[Description('The id of the user who recorded this version, if known.')]
        public ?string $createdBy = null,
        public ?string $createdAt = null,
        #[Description('Whether this version is the working HEAD — the body an author currently edits.')]
        public bool $isHead = false,
        #[Description('Whether this version is the PUBLISHED body — the one a guest reader is served.')]
        public bool $isPublished = false,
    ) {}

    /** Project one {@see Version} with its snapshot; both pointer answers are resolved by the caller. */
    public static function fromVersion(Version $version, bool $isHead, bool $isPublished): self
    {
        $snapshot = $version->snapshot;

        return new self(
            id: (string) $version->id,
            version: (int) $version->version,
            readable: $version->readableVersion(),
            snapshot: is_array($snapshot) ? $snapshot : [],
            label: $version->label,
            createdBy: $version->created_by === null ? null : (string) $version->created_by,
            createdAt: $version->created_at?->toIso8601String(),
            isHead: $isHead,
            isPublished: $isPublished,
        );
    }

    /**
     * `snapshot` is declared an object (`array<string, mixed>` → a record), so an EMPTY one goes on the wire as `{}`, not
     * as PHP's `[]`, which JSON-encodes to an array and would contradict the declared type.
     *
     * @return array<string, mixed>
     */
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        $transformed = parent::transform($transformationContext);
        if (($transformed['snapshot'] ?? null) === []) {
            $transformed['snapshot'] = new \stdClass;
        }

        return $transformed;
    }
}
