<?php

namespace Splicewire\Beam\Ux\Support;

use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Ux\Particle\EntryMakeEditableOp;
use Splicewire\Beam\Ux\Particle\ResolvePageEntryRefOp;

/** Called by the host inside its auth/verified group; every operation also declares ux.author. */
final class EditorRoutes
{
    public static function mount(
        string $uri = 'beam-ux-entries',
        string $releasesUri = 'beam-ux-releases',
        array $mountOptions = [],
    ): void {
        $realmPrefix = is_string($mountOptions['realm'] ?? null) ? $mountOptions['realm'].'.' : '';
        $includeReleaseFiles = $mountOptions['includeReleaseFiles']
            ?? ! is_string($mountOptions['realm'] ?? null);
        $entryOptions = array_replace($mountOptions, ['names' => $realmPrefix.'beam-ux-entry']);

        // NoSubject must precede any {id} route; no hidden package route registration.
        Particle::ops($uri, 'beam-ux-entry', [ResolvePageEntryRefOp::class, EntryMakeEditableOp::class], $entryOptions);
        foreach (['body', 'save-body', 'save-draft', 'publish', 'versions', 'restore', 'clear-body', 'print-source', 'read-source', 'canvas-bindings', 'component-capabilities', 'create-component', 'duplicate-component', 'create-release', 'preview'] as $op) {
            Particle::ops($uri, 'beam-ux-entry', $op, $entryOptions);
        }

        $releaseMount = Particle::mount($releasesUri, 'beam-ux-release')
            ->names($realmPrefix.'beam-ux-release')
            ->only(['index', 'show']);
        $releaseFilesMount = null;
        if ($includeReleaseFiles !== false) {
            $releaseFilesMount = Particle::mount('beam-ux-release-files', 'beam-ux-release-files')
                ->names($realmPrefix.'beam-ux-release-files')
                ->only(['index', 'show']);
        }

        if (is_string($mountOptions['realm'] ?? null)) {
            $releaseMount->inRealm($mountOptions['realm']);
            $releaseFilesMount?->inRealm($mountOptions['realm']);
        }

        $releaseOps = ['command', 'readiness', 'changes'];
        if (($mountOptions['realm'] ?? null) === 'tenant') {
            $releaseOps[] = 'preview-open';
        }
        $releaseMount->ops($releaseOps, $mountOptions);
        $releaseFilesMount?->ops(['commit-now', 'inspect-file', 'take-file-draft', 'take-all-drafts', 'restore-missing-file', 'remirror-file'], $mountOptions);

        // ADR-0236 d14: the Redirects seat's resource; the service checks the rights publishing takes.
        $redirectMount = Particle::mount('beam-ux-redirects', 'beam-ux-redirect');
        if (is_string($mountOptions['realm'] ?? null)) {
            $redirectMount->names($realmPrefix.'beam-ux-redirect')->inRealm($mountOptions['realm']);
        }

        if (is_string($mountOptions['realm'] ?? null)
            && is_array($mountOptions['abilityOverrides'] ?? null)) {
            // Particle::mount() stamps the realm onto CRUD routes, while the override map
            // is normally carried only by ->ops(). Apply the same server-owned map to all
            // resource routes this helper mounted in that realm (entry ops already carry it).
            $releaseMount->register();
            $releaseFilesMount?->register();

            $resourceKeys = ['beam-ux-entry', 'beam-ux-release', 'beam-ux-redirect'];
            if ($includeReleaseFiles !== false) {
                $resourceKeys[] = 'beam-ux-release-files';
            }

            foreach (app('router')->getRoutes()->getRoutes() as $route) {
                if (in_array($route->defaults[\Splicewire\Beam\Http\Particle\ParticleController::RESOURCE] ?? null, $resourceKeys, true)
                    && ($route->defaults[\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM] ?? null) === $mountOptions['realm']) {
                    $route->defaults(
                        \Splicewire\Beam\Http\Particle\ParticleOperationController::ABILITY_OVERRIDES,
                        $mountOptions['abilityOverrides'],
                    );
                }
            }
        }
    }
}
