<?php

namespace Splicewire\Beam\Ux\Ia;

use Rushing\DataNav\NavRegistry;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Splicewire\Beam\Ux\Frame\DeclaredSectionNavigation;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;

/**
 * M5: the decorator over WHATEVER {@see FrameNavContributor} a host binds (ux-walkthrough SPEC, UX-06). beam-ux wraps
 * the port with `extend()`, and a container extender survives a later `bind()`, so a host that binds its own
 * contributor (Tower ticket 04's shape) is still checked, and renderers never see a failing tree.
 *
 * Whose tree it is decides the answer, by the split {@see FrameNavContribution} already draws: when the package's own
 * contributor builds the package's {@see DeclaredSectionNavigation}, the tree is package-contributed and a breaking
 * node is pruned; otherwise the host wrote it and {@see IaInvariants::enforce()} throws (outside production) or reports
 * and prunes (in production).
 */
class IaCheckedNavContributor implements FrameNavContributor
{
    public function __construct(
        protected FrameNavContributor $inner,
        protected IaInvariants $invariants,
        protected NavRegistry $navigations,
    ) {}

    public function inner(): FrameNavContributor
    {
        return $this->inner;
    }

    public function contributeNav(?string $realm): ?array
    {
        $block = $this->inner->contributeNav($realm);
        $realm ??= config('beam.ux.frame_nav.default_realm');

        if ($block === null || ! is_string($realm) || $realm === '' || ! is_array($block['nav'] ?? null)) {
            return $block;
        }

        if ($this->contributedByThePackage($realm)) {
            $block['nav'] = $this->invariants->prune($realm, $block['nav']);
        } else {
            $block['nav'] = $this->invariants->enforce($realm, $block['nav']);
        }

        return $block;
    }

    /**
     * The package's own contributor building the package's declared navigation. Either half alone is not enough: beam-ux
     * registers its declared navigation for a realm whatever contributor the host binds, and a host's contributor
     * builds whatever it likes.
     */
    protected function contributedByThePackage(string $realm): bool
    {
        return $this->inner instanceof FrameNavContribution
            && $this->navigations->tryResolve($realm) instanceof DeclaredSectionNavigation;
    }
}
