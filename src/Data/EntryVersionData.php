<?php

namespace Splicewire\Beam\Ux\Data;

use Rushing\Versioning\Models\Version;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * One recorded version of an entry's body, as the editor dock lists it — identity, provenance, and the
 * two pointer answers a restore affordance needs (`isHead`, `isPublished`). The frozen `snapshot`
 * itself is deliberately absent: a history panel needs to know WHICH versions exist and which one the
 * reader is on, not to carry every past body into the payload.
 *
 * ## Why this is not `splicewire/laravel-beam-versioning`'s `VersionData`
 *
 * That package ships the record-agnostic projection of exactly this model and argues, correctly, that
 * a DTO owns its wire contract. Reusing it would mean beam-ux requiring it, and beam-ux is installed at
 * hosts that do not install it — measured 2026-09-12 across the four roots that carry
 * `vendor/splicewire/laravel-beam-ux`: three have `laravel-beam-versioning` and
 * `~/Workspaces/laravel/starters/laravel-satellite-starter` does not. A `#[ParticleOp]`'s `output:` slot
 * is read by reflection at discovery, so naming a class that is absent at one of this package's own
 * hosts fatals that host's boot to save seven fields.
 *
 * What must not be duplicated is the STORE, and it is not: every field below is read off
 * `rushing/laravel-versioning`'s one {@see Version} model through its one {@see \Rushing\Versioning\Contracts\VersionStore},
 * which beam-core already hard-requires. The HTTP tier over that store stays optional, which is what it
 * is for.
 *
 * `isPublished` is the field the other projection cannot have: publication is beam-ux's own pin
 * ({@see EntryPublication}), not a property of a version.
 */
#[TypeScript]
#[Description('One recorded version of an entry body: a frozen snapshot with a monotonic number, a readable handle, and whether it is the working HEAD or the published body.')]
class EntryVersionData extends BeamData
{
    public function __construct(
        #[Example('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')]
        public string $id,
        #[Description('The per-entry monotonic version number (1, 2, 3, …).')]
        #[Example(3)]
        public int $version,
        #[Description('The human-readable handle for this version (default `v{n}`).')]
        #[Example('v3')]
        public string $readable,
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

    /**
     * Project one {@see Version}, with both pointer answers resolved by the caller (which reads HEAD and
     * the publication pin once for the whole list rather than per row).
     */
    public static function fromVersion(Version $version, bool $isHead, bool $isPublished): self
    {
        return new self(
            id: (string) $version->id,
            version: (int) $version->version,
            readable: $version->readableVersion(),
            label: $version->label,
            createdBy: $version->created_by === null ? null : (string) $version->created_by,
            createdAt: $version->created_at?->toIso8601String(),
            isHead: $isHead,
            isPublished: $isPublished,
        );
    }
}
