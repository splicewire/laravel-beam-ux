<?php

namespace Splicewire\Beam\Ux\Docs;

/**
 * The null-safe "docs URL for a registry key" seam (registry-docs ticket 01, amended ruling 1).
 *
 * UX surfaces ask this for the documentation URL of a popcorn registry root, so Frame/seam surfaces
 * can deep-link a registry's page with no lookup table of their own. The contract is absolute:
 * **`null` means "render no link"** — an implementation never throws and never guesses a URL. A key
 * is the registry root, dotted, VERBATIM; there is no slugifier at this seam or behind it
 * (`RegisterFromDisk` round-trips a dotted basename untouched, so none is ever needed).
 *
 * beam-ux ships only the {@see NullDocsLinkResolver} default (`bindIf`, the host-bindings container
 * lane), so a host that installs beam-ux without `splicewire/laravel-beam-docs` — an audiostud-class
 * host — loses the links, not the pages. beam-docs supersedes the binding with a resolver that reads
 * the committed spine catalog and the entries table; beam-docs keeps no separate docs registry to
 * read instead (beam-docs ADR-0210).
 *
 * @api
 */
interface DocsLinkResolver
{
    /**
     * The documentation URL for `$key` — a registry root, dotted, verbatim — or `null` when this host
     * can offer no link. The empty root `''` is structurally skipped at every tier and always `null`.
     */
    public function urlFor(string $key): ?string;
}
