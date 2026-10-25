<?php

namespace Splicewire\Beam\Ux\Redirect;

use Splicewire\Beam\Ux\Http\Controllers\PublicEntryController;

/** The paths a Redirect starts from and points at (ADR-0236 d5): normalised as a guest types them. */
final class RedirectPaths
{
    /** One leading slash, no trailing one, no query or fragment, no `..`; null when it isn't a path on this site. */
    public static function normalize(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\s?#\\\\]/', $path) === 1) {
            return null;
        }
        $path = '/'.trim(preg_replace('#/{2,}#', '/', $path), '/');
        if (in_array('..', explode('/', $path), true) || strlen($path) > 512) {
            return null;
        }

        return $path;
    }

    /**
     * Whether the renderer never receives the path: a reserved prefix (ADR-0209 §2) the site mount composes from beam's
     * own, `_beam` and the host's configuration, or the compiled-artifact route. A Redirect there could never answer.
     */
    public static function reserved(string $path): bool
    {
        $first = explode('/', ltrim($path, '/'))[0] ?? '';
        $prefixes = array_map(fn ($p) => trim((string) $p, '/'), [
            ...PublicEntryController::RESERVED_BY_BEAM, '_beam', ...(array) config('beam.ux.site.reserved_prefixes', []),
        ]);
        if ($first !== '' && in_array($first, $prefixes, true)) {
            return true;
        }
        $artifacts = '/'.trim((string) config('beam.ux.site.artifact_root', 'beam/ux/artifacts'), '/');

        return $path === $artifacts || str_starts_with($path, $artifacts.'/');
    }

    /** The rest of a path under a path-and-below Redirect, carried to its target. */
    public static function rest(string $path, string $prefix): string
    {
        return $prefix === '/' ? $path : substr($path, strlen($prefix));
    }

    public static function join(string $base, string $rest): string
    {
        return $rest === '' || $rest === '/' ? $base : rtrim($base, '/').$rest;
    }

    /** An address with the rest of a path carried into its path, ahead of any query or fragment it names. */
    public static function joinAddress(string $url, string $rest): string
    {
        preg_match('/\A([^?#]*)(.*)\z/s', $url, $parts);

        return self::join($parts[1], $rest).$parts[2];
    }

    /** The guest's query string carried onto a target, ahead of any fragment the target names. */
    public static function withQuery(string $to, ?string $query): string
    {
        if ($query === null || $query === '') {
            return $to;
        }
        [$head, $fragment] = array_pad(explode('#', $to, 2), 2, null);

        return $head.(str_contains($head, '?') ? '&' : '?').$query.($fragment === null ? '' : '#'.$fragment);
    }
}
