<?php

namespace Splicewire\Beam\Ux\Redirect;

use Illuminate\Support\Collection;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;

/**
 * Which Redirect a path meets (ADR-0236 d5, d9): the one at exactly that path first, else the path-and-below one with
 * the longest path above it. Read once per question; the renderer and the save checks share it.
 */
final class RedirectMap
{
    /** @param Collection<int, BeamUxRedirect> $redirects */
    private function __construct(private Collection $redirects) {}

    public static function for(string $realm, ?string $connection = null): self
    {
        return new self(BeamUxRedirect::on($connection)->where('realm', $realm)->get());
    }

    /** The same map with one Redirect as it would be saved (replacing its stored row), for the save checks. */
    public function with(BeamUxRedirect $candidate): self
    {
        return new self($this->redirects->reject(fn (BeamUxRedirect $r) => $candidate->exists && $r->getKey() === $candidate->getKey())
            ->push($candidate)->values());
    }

    /**
     * The same map as a Preview's Release would leave it (ADR-0236 d12): each planned Redirect, as a path to where it
     * leads after the Release, replacing a stored one at the same path and match. One with nowhere to go is left out.
     *
     * @param  list<array{realm: string, path: string, match: string, to: ?string}>  $planned
     */
    public function withPlanned(array $planned, string $realm): self
    {
        $map = $this->redirects;
        foreach ($planned as $row) {
            if ($row['realm'] !== $realm || $row['to'] === null) {
                continue;
            }
            $map = $map->reject(fn (BeamUxRedirect $r) => $r->path === $row['path'] && $r->match === $row['match'])
                ->push((new BeamUxRedirect)->forceFill(['realm' => $realm, 'path' => $row['path'], 'match' => $row['match'],
                    'target_kind' => 'path', 'target_path' => $row['to'], 'permanent' => false, 'origin' => 'release']));
        }

        return new self($map->values());
    }

    public function match(string $path): ?BeamUxRedirect
    {
        $exact = $this->redirects->first(fn (BeamUxRedirect $r) => $r->match === 'exact' && $r->path === $path);
        if ($exact !== null) {
            return $exact;
        }

        return $this->redirects
            ->filter(fn (BeamUxRedirect $r) => $r->match === 'prefix' && ($r->path === $path || $r->path === '/' || str_starts_with($path, $r->path.'/')))
            ->sortByDesc(fn (BeamUxRedirect $r) => strlen($r->path))
            ->first();
    }

    /** @return Collection<int, BeamUxRedirect> */
    public function all(): Collection
    {
        return $this->redirects;
    }

    /**
     * Where a Redirect sends the path it met, as a path on this site or an address on another, before any reader's
     * checks: a page's current URL, a path, an address; the rest of the path carried along for a path-and-below one.
     * Null when the page target no longer exists. A Record target resolves through its Record Page elsewhere.
     *
     * @return array{kind: 'path'|'address'|'record', to: ?string}|null
     */
    public static function destination(BeamUxRedirect $redirect, string $path, ?BeamUxEntry $page = null): ?array
    {
        $rest = $redirect->match === 'prefix' ? RedirectPaths::rest($path, $redirect->path) : '';

        return match ($redirect->target_kind) {
            'page' => $page === null ? null : ['kind' => 'path', 'to' => RedirectPaths::join($page->url(), $rest)],
            'path' => ['kind' => 'path', 'to' => RedirectPaths::join((string) $redirect->target_path, $rest)],
            'address' => ['kind' => 'address', 'to' => RedirectPaths::join((string) $redirect->target_url, $rest)],
            'record' => ['kind' => 'record', 'to' => null],
            default => null,
        };
    }
}
