<?php

namespace Splicewire\Beam\Ux\Docs;

/**
 * The shipped default behind {@see DocsLinkResolver}: no docs capability installed, no link — for
 * every key, including the index root `''`. Null is an answer here, never an error (the seam's
 * whole contract), so a caller renders plain text where a link would have gone and moves on.
 */
class NullDocsLinkResolver implements DocsLinkResolver
{
    public function urlFor(string $key): ?string
    {
        return null;
    }
}
