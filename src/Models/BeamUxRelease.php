<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Splicewire\Beam\Ux\Release\ReleaseOrigin;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Workflows\Control\Contracts\ParticipatesInWorkflowTransaction;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

class BeamUxRelease extends Model implements ParticipatesInWorkflowTransaction, WorkflowManaged
{
    use HasUuids;
    use WorkflowManagedTrait;

    protected $table = 'beam_ux_releases';

    protected $guarded = ['*'];

    /** The Preview Links' secret signs every link (ADR-0232 d8); it never leaves the server. */
    protected $hidden = ['preview_link_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['origin' => ReleaseOrigin::class, 'preview_links_enabled' => 'boolean'];
    }

    public function workflowType(): string
    {
        return 'beam-ux-release';
    }

    public function members(): HasMany
    {
        return $this->hasMany(BeamUxReleaseMember::class, 'release_id');
    }

    public function participateInWorkflowTransaction(string $transition, TransitionContext $context): void
    {
        app(ReleaseService::class)->participate($this, $transition, $context);
    }
}
