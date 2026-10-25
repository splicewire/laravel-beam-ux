<?php

namespace Splicewire\Beam\Ux\Redirect;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Bindings\RecordPages;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Data\RedirectInputData;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Hand-made Redirects (ADR-0236 d5) and what Publish does to them (d7). A save resolves the author's target into the
 * row (a page named by its URL is held by identity) and refuses a path a page or Record answers, a path the renderer
 * never receives, and a Redirect that would reach itself. Managing one takes `ux.author`, plus the host's mapped publish
 * ability on a target page, as a direct Release checks (EP-30 ruling 2).
 */
final class RedirectService
{
    public function prepare(BeamUxRedirect $redirect, RedirectInputData $input, mixed $actor): void
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize('ux.author');
        $realm = $redirect->realm ?: BeamUxEntry::REALM_SITE;
        $connection = $redirect->getConnectionName();

        $path = RedirectPaths::normalize($input->path);
        if ($path === null) {
            $this->refuse('path', 'A Redirect starts from a path on this site, like /old-page.');
        }
        if (RedirectPaths::reserved($path)) {
            $this->refuse('path', "The site never receives {$path}, so a Redirect there could never answer.");
        }
        if ($this->answers($path, $realm)) {
            $this->refuse('path', "A page answers {$path}, and a page always wins.");
        }
        $taken = BeamUxRedirect::on($connection)->where('realm', $realm)->where('path', $path)->where('match', $input->match)
            ->when($redirect->exists, fn ($q) => $q->whereKeyNot($redirect->getKey()))->exists();
        if ($taken) {
            $this->refuse('path', "A Redirect already starts from {$path}.");
        }

        $redirect->forceFill(['realm' => $realm, 'target_entry_id' => null, 'target_collection' => null,
            'target_record_id' => null, 'target_path' => null, 'target_url' => null]);
        match ($input->targetKind) {
            'page' => $this->targetPage($redirect, $input->target, $realm, $actor),
            'path' => $redirect->forceFill(['target_path' => RedirectPaths::normalize($input->target)
                ?? $this->refuse('target', 'Name a path on this site, like /new-page.')]),
            'address' => $redirect->forceFill(['target_url' => $this->address($input->target)]),
            'record' => $this->targetRecord($redirect, $input->target),
        };

        $candidate = (clone $redirect)->forceFill(['path' => $path, 'match' => $input->match, 'target_kind' => $input->targetKind]);
        if ($this->reachesItself($candidate, $realm, $connection)) {
            $this->refuse('target', 'This Redirect would lead back to itself.');
        }

        $who = (string) $actor->getAuthIdentifier();
        $redirect->forceFill($redirect->exists ? ['edited_by' => $who] : ['origin' => 'author', 'created_by' => $who, 'edited_by' => $who]);
    }

    /** Whether the actor may manage this Redirect: `ux.author`, and the mapped publish ability on its page target. */
    public function mayManage(?Authenticatable $actor, BeamUxRedirect $redirect): bool
    {
        if ($actor === null || ! Gate::forUser($actor)->allows('ux.author')) {
            return false;
        }
        $page = $redirect->target_kind === 'page' && $redirect->target_entry_id !== null
            ? BeamUxEntry::on($redirect->getConnectionName())->find($redirect->target_entry_id) : null;

        return $page === null || $this->publishAbility() === null || Gate::forUser($actor)->allows($this->publishAbility(), $page);
    }

    /**
     * d7: a page always wins, so Publish removes a Redirect at exactly the URL it puts a page on, recording each one it
     * removed. A path-and-below Redirect there keeps answering the URLs under it.
     *
     * @return list<string> the paths it removed
     */
    public function pruneAnswered(BeamUxEntry $page): array
    {
        if ($page->type !== UxType::Page || RecordPages::parameter($page->segment) !== null
            || ! Schema::connection($page->getConnectionName())->hasTable('beam_ux_redirects')) {
            return [];
        }
        $url = $page->url();
        $realms = array_values(array_filter((array) ($page->realms ?? [$page->realm]), fn (string $realm) => $this->answers($url, $realm)));
        $removed = [];
        foreach (BeamUxRedirect::on($page->getConnectionName())->whereIn('realm', $realms)->where('match', 'exact')->where('path', $url)->get() as $redirect) {
            $redirect->audit('pruned', ['by' => 'publish', 'page' => (string) $page->getKey()]);
            BeamUxRedirect::on($page->getConnectionName())->whereKey($redirect->getKey())->delete();
            $removed[] = $url;
        }

        return $removed;
    }

    /** Whether a page, or a Record through its Record Page, answers the path for a guest: then a Redirect there never would. */
    public function answers(string $path, string $realm): bool
    {
        $chain = app(EntryPathResolver::class)->resolve($path, $realm);
        if ($chain === null || app(PublicEntryGate::class)->hidden($chain)) {
            return false;
        }
        $page = $chain[array_key_last($chain)];
        if (RecordPages::parameter($page->segment) === null) {
            return true;
        }

        return app(RecordPages::class)->resolve($page, (string) $page->getAttribute('_record_page_value'), Request::create($path), $realm) !== null;
    }

    private function targetPage(BeamUxRedirect $redirect, string $target, string $realm, Authenticatable $actor): void
    {
        // Either way the page is one this Redirect's site reaches: its URL resolves to it in the Redirect's realm.
        // An id from another realm, or one whose URL another page answers here, names no page of this site.
        $path = RedirectPaths::normalize($target);
        if (preg_match('/\A[0-9a-f-]{36}\z/i', $target) === 1) {
            $chosen = BeamUxEntry::on($redirect->getConnectionName())->whereKey($target)->where('type', UxType::Page->value)->first();
            $path = $chosen === null ? null : RedirectPaths::normalize($chosen->url());
        }
        $chain = $path === null ? null : app(EntryPathResolver::class)->resolve($path, $realm);
        $page = $chain === null ? null : $chain[array_key_last($chain)];
        if (isset($chosen) && $page !== null && (string) $page->getKey() !== (string) $chosen->getKey()) {
            $page = null;
        }
        if ($page === null) {
            $this->refuse('target', 'No page has that URL. Name the page by its current URL.');
        }
        if (($ability = $this->publishAbility()) !== null) {
            Gate::forUser($actor)->authorize($ability, $page);
        }
        $redirect->forceFill(['target_entry_id' => (string) $page->getKey()]);
    }

    /** EP-30 ruling 8: a Record by the Collection key its Record Page resolves by, and its id: `collection:id`. */
    private function targetRecord(BeamUxRedirect $redirect, string $target): void
    {
        [$collection, $id] = array_pad(explode(':', $target, 2), 2, '');
        if ($collection === '' || $id === '') {
            $this->refuse('target', 'Name a Record as collection:id.');
        }
        $redirect->forceFill(['target_collection' => $collection, 'target_record_id' => $id]);
    }

    private function address(string $target): string
    {
        $scheme = parse_url($target, PHP_URL_SCHEME);
        if (filter_var($target, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            $this->refuse('target', 'Name an address on another site, starting https://.');
        }

        return $target;
    }

    /**
     * Whether following the Redirect from its own path comes back to it, through the site's other Redirects and the
     * paths no page answers, within the renderer's bound. A loop elsewhere is the doctor's to name.
     */
    private function reachesItself(BeamUxRedirect $candidate, string $realm, ?string $connection): bool
    {
        $map = RedirectMap::for($realm, $connection)->with($candidate);
        $start = $candidate->path;
        $redirect = $candidate;
        $path = $start;
        $seen = [$start => true];
        for ($step = 0; $step < (int) config('beam.ux.redirects.max_steps', 5); $step++) {
            $page = $redirect->target_kind === 'page' && $redirect->target_entry_id !== null
                ? BeamUxEntry::on($connection)->find($redirect->target_entry_id) : null;
            $destination = RedirectMap::destination($redirect, $path, $page);
            if ($destination === null || $destination['kind'] !== 'path') {
                return false;
            }
            $next = (string) $destination['to'];
            if ($next === $start) {
                return true;
            }
            if (isset($seen[$next]) || $this->answers($next, $realm) || ($redirect = $map->match($next)) === null) {
                return false;
            }
            $seen[$next] = true;
            $path = $next;
        }

        return false;
    }

    private function publishAbility(): ?string
    {
        $ability = config('beam.ux.releases.publish_ability');

        return is_string($ability) && $ability !== '' ? $ability : null;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
