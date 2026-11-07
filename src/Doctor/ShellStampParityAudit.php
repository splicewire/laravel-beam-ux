<?php

namespace Splicewire\Beam\Ux\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Console\StampShellCommand;
use Splicewire\Beam\Ux\Shell\ShellStamps;

/**
 * `beam-ux.shell-stamp-parity` — reports, and never writes, two kinds of shell drift (make-surface ticket 02):
 *
 * 1. **A stamped file that drifted from its stub.** `splicewire:beam:ux:stamp-shell` records every file that was its
 *    stub byte-for-byte after a run, with the surface selection that stub belongs to
 *    (`resources/beam-ux/shell-stamp.json`). A recorded file that no longer matches ITS OWN selection's stub is a
 *    finding: an edit to the host copy, or a package update that moved the stub. Either re-stamp it, or accept that
 *    it is now host-owned (and drop it from the record). A host that changes its selection without `--force` keeps
 *    its earlier stamp, which still matches its recorded selection, so that is not drift. A file the host tailored
 *    before its first stamp was never recorded, so it is never reported here.
 * 2. **Generic machinery found in the host.** The copies ticket 01 moved into packages must not come back (the audiostud
 *    `doctor-editor-ootb.mjs` pattern: "the host copy stays retired"). The forbidden list is 01's record of what left
 *    the starters; a host `HandleInertiaRequests` that re-inlines the shared props instead of `use SharesBeamShell` is
 *    the same defect inside a file the host legitimately owns.
 *
 * Both are WARN, never FAIL: a tailored host is legitimate, and the stamp's own rule is that the host owns its copy.
 * The point is that the drift is visible. A host with no record and no machinery passes with a one-line note.
 */
class ShellStampParityAudit implements DoctorAudit
{
    public const CHECK = 'beam-ux.shell-stamp-parity';

    /**
     * Host files ticket 01 retired into packages, and where each lives now (01 PROOF "Gone from the beam starter").
     *
     * @var array<string, string>
     */
    public const RETIRED_MACHINERY = [
        'app/Data/Pages/FrameConsolePageData.php' => 'beam-ux RealmConsolePageData, shipped with Route::beamRealmConsole()',
        'app/Sitemap/NavItem.php' => 'Splicewire\Beam\Ux\Nav\NavItem',
        'app/Doctor/OrphanedNavItemAudit.php' => 'Splicewire\Beam\Ux\Doctor\OrphanedNavItemAudit',
    ];

    public function __construct(
        private ShellStamps $stamps,
        private ?string $basePath = null,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $base = $this->basePath ?? base_path();
        $record = self::readRecord($base.'/'.StampShellCommand::RECORD);
        $findings = [];
        $contents = [];

        foreach ($record as $target => $surfaces) {
            $path = $base.'/'.$target;

            if (! is_file($path)) {
                $findings[] = Finding::warn(self::CHECK, "{$target} was stamped and is now missing.");

                continue;
            }

            $selection = implode(',', $surfaces);
            $contents[$selection] ??= $this->stamps->contentsFor($surfaces);
            $stub = $contents[$selection][$target] ?? null;

            if ($stub === null) {
                $findings[] = Finding::warn(self::CHECK, "{$target} is recorded as stamped but no surface stamps it any more.");
            } elseif (rtrim((string) file_get_contents($path)) !== rtrim($stub)) {
                $findings[] = Finding::warn(self::CHECK, "{$target} has drifted from its stub — re-stamp it (--force), or drop it from ".StampShellCommand::RECORD.' to own it.');
            }
        }

        foreach (self::RETIRED_MACHINERY as $relative => $packaged) {
            if (is_file($base.'/'.$relative)) {
                $findings[] = Finding::warn(self::CHECK, "{$relative} is generic machinery back in the host; it is packaged as {$packaged}.");
            }
        }

        $middleware = $base.'/app/Http/Middleware/HandleInertiaRequests.php';

        if (is_file($middleware)) {
            $source = (string) file_get_contents($middleware);

            if (preg_match('/function\s+share\s*\(/', $source) === 1 && ! str_contains($source, 'SharesBeamShell')) {
                $findings[] = Finding::warn(self::CHECK, 'app/Http/Middleware/HandleInertiaRequests.php re-inlines the shared props; use Splicewire\Beam\Ux\Http\Concerns\SharesBeamShell and override only what the host tailors.');
            }
        }

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass(self::CHECK, $record === []
            ? 'no shell stamp recorded, and no retired machinery in the host.'
            : sprintf('%d stamped file(s) match their stubs; no retired machinery in the host.', count($record)))];
    }

    /**
     * The stamp record: each recorded target and the surface selection whose stub it was. It is empty when absent or
     * unreadable. A record written before per-file selections (`{surfaces, files: [targets]}`) reads as every file
     * under its one `surfaces` list.
     *
     * @return array<string, list<string>>
     */
    public static function readRecord(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $files = is_array($data) && is_array($data['files'] ?? null) ? $data['files'] : [];

        if (array_is_list($files)) {
            $surfaces = self::surfaceList($data['surfaces'] ?? []) ?: ShellStamps::SURFACES;
            $files = array_fill_keys(array_filter($files, 'is_string'), $surfaces);
        }

        $record = [];

        foreach ($files as $target => $surfaces) {
            if (is_string($target) && is_array($surfaces)) {
                $record[$target] = self::surfaceList($surfaces);
            }
        }

        return $record;
    }

    /** @return list<string> */
    private static function surfaceList(mixed $surfaces): array
    {
        return array_values(array_filter((array) $surfaces, 'is_string'));
    }
}
