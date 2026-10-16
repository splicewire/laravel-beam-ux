<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

/**
 * ADR-0225 decision 2: the Publication Pin is required wherever the entry editor is mounted, and a missing
 * version table or `published_version` column fails at INSTALL, not on each request.
 *
 * "Mounted" is read from the route table, not guessed: a host that mounts any of the entry's write or
 * history ops (`Particle::ops('beam-ux-entries', 'beam-ux-entry', …)`) has a route carrying
 * {@see ParticleOperationController::RESOURCE} `beam-ux-entry`. A host that mounts none of them (a site
 * that only renders entries) needs no pin, and this audit says so instead of failing it.
 *
 * Deliberately NOT a boot refusal: refusing at boot would also refuse `php artisan migrate`, the command
 * that fixes it. The ops themselves refuse at the call ({@see EntryPublication::requireVersionStore()}),
 * and this audit is what names the host before an author finds out.
 */
class BeamUxVersionStoreAudit implements DoctorAudit
{
    /** The entry ops that read or write the entry's one history. */
    public const OPS = ['save-body', 'save-draft', 'publish', 'restore', 'versions'];

    private const CHECK = 'entry publication pin (ADR-0225)';

    private const FIX = '`php artisan vendor:publish --tag=beam-migrations --tag=beam-ux-migrations && php artisan migrate`';

    public function __construct(
        private Router $router,
        private EntryPublication $publication,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $mounted = $this->mountedOps();

        if ($mounted === []) {
            return [Finding::pass(self::CHECK, 'no beam-ux-entry history op is mounted on this host, so no Publication Pin is required here.')];
        }

        $names = implode(', ', $mounted);

        if (! Schema::hasTable('beam_ux_entries')) {
            return [Finding::fail(self::CHECK, "beam-ux-entry ops [{$names}] are mounted but beam_ux_entries is absent. Run ".self::FIX.'.')];
        }

        if (! $this->publication->recordsVersions()) {
            try {
                $this->publication->requireVersionStore();
            } catch (\RuntimeException $e) {
                return [Finding::fail(self::CHECK, "beam-ux-entry ops [{$names}] are mounted. {$e->getMessage()}")];
            }
        }

        $pinless = BeamUxEntry::query()->whereNull('published_version')->whereNotNull('particle_id')->count();

        if ($pinless > 0) {
            return [Finding::warn(self::CHECK, "{$pinless} entr".($pinless === 1 ? 'y has' : 'ies have').' a body but no Publication Pin. '
                .'Publish the `beam-ux-migrations` tag and migrate: `baseline_beam_ux_entry_publication_pins` pins each one to its live body.')];
        }

        return [Finding::pass(self::CHECK, "beam-ux-entry ops [{$names}] are mounted and every written entry is pinned.")];
    }

    /** @return list<string> */
    private function mountedOps(): array
    {
        $found = [];

        foreach ($this->router->getRoutes() as $route) {
            $defaults = $route->defaults;

            if (($defaults[ParticleOperationController::RESOURCE] ?? null) === 'beam-ux-entry'
                && in_array($name = $defaults[ParticleOperationController::NAME] ?? null, self::OPS, true)) {
                $found[$name] = true;
            }
        }

        $names = array_keys($found);
        sort($names);

        return $names;
    }
}
