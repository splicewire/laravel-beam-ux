<?php

namespace Splicewire\Beam\Ux\Particle\Backing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Ux\Data\DashboardCardRowData;
use Splicewire\Beam\Ux\Data\DashboardWelcomeActionData;
use Splicewire\Beam\Ux\Data\DashboardWelcomeData;
use Throwable;

/**
 * The row a realm dashboard answers when it has nothing else for the viewer — a first-run welcome for
 * someone on no team, a softer "nothing here yet" for someone who is (ux-demo-convergence replay 9: a
 * freshly registered, teamless user landed on `/dashboard` and saw only frame's "No records.").
 *
 * The dashboard is still the honest list it was: {@see DashboardBacking} asks for this row only when its
 * cards and tiles are BOTH empty, and a welcome row never sits beside either.
 *
 * ## Which facts decide it
 *
 *  - **On a team?** beam-accounts' current team ({@see BeamAccounts::currentTeam()}, the resolver every
 *    team-scoped accounts route reads) is a {@see TeamContract} that seats the viewer. Anything else —
 *    no team, a team the viewer does not sit on, a resolver that throws — reads `first-run` only when the
 *    answer is a clear "no team"; a failure to answer reads `empty`, which makes no claim about teams.
 *  - **Which next steps?** Only destinations the host ROUTES, asked of the router by name:
 *    {@see self::CREATE_TEAM_ROUTE} (beam-accounts mounts none today, so a beam starter shows no such
 *    link), {@see self::SETTINGS_ROUTE} (beam-accounts' own settings page). The invitation hint is
 *    shown only where {@see self::ACCEPT_INVITATION_ROUTE} exists — a host that cannot redeem an
 *    invitation link must not tell the reader to open one.
 */
class DashboardWelcome
{
    /** A host that lets a user start a team names that page this. */
    public const CREATE_TEAM_ROUTE = 'teams.create';

    /** A host that redeems an emailed invitation link over the web names that route this. */
    public const ACCEPT_INVITATION_ROUTE = 'invitations.accept';

    /** beam-accounts' profile/settings page (`routes/account.php`). */
    public const SETTINGS_ROUTE = 'profile.edit';

    public function __construct(
        private readonly Router $router,
        private readonly UrlGenerator $url,
    ) {}

    public function row(Authenticatable $actor): DashboardCardRowData
    {
        $welcome = $this->welcome($actor);

        return new DashboardCardRowData(
            id: DashboardCardRowData::CONTEXT_WELCOME,
            context: DashboardCardRowData::CONTEXT_WELCOME,
            label: $welcome->heading,
            icon: null,
            href: '',
            welcome: $welcome,
        );
    }

    public function welcome(Authenticatable $actor): DashboardWelcomeData
    {
        $settings = $this->action('settings', __('Account settings'), self::SETTINGS_ROUTE);

        if ($this->onTeam($actor) === false) {
            $name = $this->nameOf($actor);

            return new DashboardWelcomeData(
                state: DashboardWelcomeData::STATE_FIRST_RUN,
                heading: $name === null ? __('Welcome') : __('Welcome, :name', ['name' => $name]),
                body: __("You aren't on a team yet, so there's nothing to show here."),
                actions: array_values(array_filter([
                    $this->action('create-team', __('Create a team'), self::CREATE_TEAM_ROUTE),
                    $settings,
                ])),
                hint: $this->router->has(self::ACCEPT_INVITATION_ROUTE)
                    ? __('Have an invitation? Open the link from your email.')
                    : null,
            );
        }

        return new DashboardWelcomeData(
            state: DashboardWelcomeData::STATE_EMPTY,
            heading: __('Nothing here yet'),
            body: __('Summaries of your work will appear here as soon as there is something to show.'),
            actions: array_values(array_filter([$settings])),
        );
    }

    /**
     * True when the current team seats the viewer, false when there is clearly no team for them, null
     * when the question could not be answered (a host resolver that throws, or answers with something
     * that is not a team).
     */
    private function onTeam(Authenticatable $actor): ?bool
    {
        try {
            $team = BeamAccounts::currentTeam();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($team === null) {
            return false;
        }

        if (! $team instanceof TeamContract) {
            return null;
        }

        try {
            return $team->memberRole($actor) !== null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function action(string $key, string $label, string $route): ?DashboardWelcomeActionData
    {
        if (! $this->router->has($route)) {
            return null;
        }

        try {
            return new DashboardWelcomeActionData(key: $key, label: $label, href: $this->url->route($route, [], false));
        } catch (Throwable) {
            return null; // a named route that needs parameters is not a next step we can link
        }
    }

    private function nameOf(Authenticatable $actor): ?string
    {
        $name = method_exists($actor, 'getAttribute') ? $actor->getAttribute('name') : ($actor->name ?? null);

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
