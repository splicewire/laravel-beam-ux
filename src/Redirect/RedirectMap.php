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
