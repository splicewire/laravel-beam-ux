<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One config member of a Release, as its reviewer reads it (config-sections ticket 11): the section, its owning package,
 * the version move, whether any key waits for workers to reload, and the key-level diff.
 *
 * It is a separate list on {@see ReleaseChangesData}, never part of the page-reach map: a config section reaches no page.
 */
#[TypeScript, Title('Release config change')]
final class ReleaseConfigChangeData extends BeamData
{
    public function __construct(
        /** The section ROOT, the member's `member_ref`. */
        public string $root,
        public string $title,
        /** The package (composer name) or `host` that registered the section. */
        public string $package,
        /** The version published now (the one the member replaces), or null when the section had nothing published. */
        public ?int $fromVersion,
        /** The version publishing pins, or null when a rollback removes the override. */
        public ?int $toVersion,
        /** Whether a changed key applies only after long-lived workers reload. */
        public bool $reloadsWorkers,
        #[DataCollectionOf(ReleaseConfigKeyChangeData::class)]
        public array $keys,
        /**
         * The file an operator (central) Release writes into its one commit, `config/overrides/<root>.json`; null for a
         * tenant Release, which records database history only and never a file.
         */
        public ?string $mirrorPath = null,
        /** The mirror file's content after publishing (secrets carry no value), as a preview; null where no file is written. */
        public ?string $mirror = null,
    ) {}
}
