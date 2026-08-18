<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\Frame\Attributes\Column;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Models\GitRepo;

/**
 * The Frame resource declaration for `GitRepo` (mirror-status-ui ticket 02) — the SAME zero-glue
 * `ParticleResource` tier {@see BeamUxEntryData} uses (nothing to do with `BeamParticle`/versioning
 * despite the name; it's Frame's own REST-resource registration attribute), so it shows up in Admin's
 * resource-blind `/frame/manifest` list for free. `readOnly: true` — nothing writes to a `GitRepo`
 * through Frame; it's a cache {@see \Splicewire\Beam\Ux\Storage\GitRepoRegistrar} owns exclusively.
 */
#[ParticleResource(
    key: 'git-repo',
    model: GitRepo::class,
    label: 'Git Repos',
    group: 'Ops',
    icon: 'git-branch',
    section: 'ops',
    readOnly: true,
)]
class GitRepoData extends Data
{
    public function __construct(
        public string $id,
        #[Column(label: 'Root', sort: 0)]
        public string $root_path,
        #[Column(label: 'Branch', sort: 1)]
        public ?string $branch,
        #[Column(label: 'HEAD', sort: 2)]
        public ?string $head_sha,
        /** @var list<string> */
        public array $dirty_paths,
        /** @var list<string> */
        public array $untracked_paths,
        #[Column(label: 'Checked', sort: 3)]
        public ?string $checked_at,
    ) {}
}
