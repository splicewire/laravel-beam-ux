<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Theme\ThemeResolver;

/**
 * The doctor half of theme-entries-and-authoring ticket 07: {@see ThemeResolver::resolve()} never
 * throws, so the only way a host learns its theme cascade is broken — rather than merely unconfigured —
 * is if something asks. This asks.
 *
 * **Computed on read, never stamped at register.** The audit runs the resolver in the doctor's own
 * process and reads back {@see ThemeResolver::lastFailure()}. A web request's swallowed failure reaches
 * the log through `report()`; this is the same reading taken deliberately, at a moment someone is
 * looking. It is not a ledger of past requests — a doctor process cannot see those — and it does not
 * pretend to be.
 *
 * **Warn, never Fail.** Which theme resolves, and whether the central tier is reachable, are facts
 * about the host, and a host-dependent check must not fail a build (ecosystem `AGENTS.md`, "a check
 * whose answer depends on the host must not throw"). Registered advisory for the same reason.
 *
 * **Inconclusive when the default connection carries no `beam_ux_entries`.** Then the resolver measured
 * nothing on this host — it returned package defaults because there was nothing to read, which is the
 * absence case the resolver itself keeps silent. Saying so is the point: a Pass over an unmigrated host
 * would be the exact success-by-not-running this ticket exists to end.
 */
class BeamUxThemeResolutionAudit implements DoctorAudit
{
    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $check = 'theme resolution (ThemeResolver cascade)';

        $resolver = app(ThemeResolver::class);
        $resolver->resolve();
        $failure = $resolver->lastFailure();

        if ($failure !== null) {
            $connection = $failure->connection ?? 'default';

            return [Finding::warn(
                $check,
                "ThemeResolver::resolve() swallowed a non-absence failure reading `{$failure->entry}` on the ".
                "`{$connection}` connection — {$failure->exception}: {$failure->message}. The site renders in ".
                'package defaults behind a 200, which reads as "no theme configured" until someone asks. '.
                'The tier\'s `beam_ux_entries` EXISTS, so this is not a fresh install: compare the published '.
                'migration against beam-ux\'s stub, and check the connection resolves where you think it does.',
            )];
        }

        if (! Schema::hasTable('beam_ux_entries')) {
            return [Finding::inconclusive(
                $check,
                'beam_ux_entries is absent on the default connection — the resolver returned package '.
                'defaults without reading a row. Publish + migrate beam-ux before this can measure anything.',
            )];
        }

        return [Finding::pass($check, 'resolve() read every configured tier without swallowing a failure.')];
    }
}
