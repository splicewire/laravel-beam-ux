<?php

namespace Splicewire\Beam\Ux\Theme;

/**
 * What {@see ThemeResolver::resolve()} swallowed on its last call, when what it swallowed was NOT
 * absence (theme-entries-and-authoring ticket 07).
 *
 * `resolve()` keeps its never-throw contract — which theme resolves is a fact about the host, and a
 * host-dependent check must not throw (ecosystem `AGENTS.md`). But a `catch (Throwable)` that returns
 * package defaults is the estate's signature defect: an instrument reporting success by not running.
 * A bad connection string, a column missing after a stale-snapshot migration, a typo in a query — every
 * one rendered the site in package defaults with no log line, byte-identical to "no theme configured".
 * This record is the half that makes the two distinguishable: `report()` carries it to the log, and
 * {@see \Splicewire\Beam\Ux\Doctor\BeamUxThemeResolutionAudit} carries it to the doctor.
 */
class ThemeResolutionFailure
{
    /**
     * @param  string  $entry  the cascade tier that threw, as `<tier>:<slug>` — `central:default`,
     *                         `tenant:default`, `central:<realm>`, `tenant:<realm>`
     * @param  class-string<\Throwable>  $exception
     * @param  string|null  $connection  the connection name the tier read on; `null` is the default
     *                                   (tenant-side) connection
     */
    public function __construct(
        public string $entry,
        public string $exception,
        public string $message,
        public ?string $connection,
    ) {}
}
