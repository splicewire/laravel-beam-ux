<?php

namespace Splicewire\Beam\Ux\Provenance;

use Closure;

/**
 * The templates the provenance backfill may match an unstamped row against (DOCS-06b). A package registers a closure
 * that returns its templates when asked, so coordinates are read from config at that moment, not at boot.
 */
final class ProvenanceTemplates
{
    /** @var list<Closure(): list<ProvenanceTemplate>> */
    private array $providers = [];

    /** @param  Closure(): list<ProvenanceTemplate>  $provider */
    public function register(Closure $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return list<ProvenanceTemplate> every registered template at this coordinate */
    public function at(?string $namespace, string $slug): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            foreach ($provider() as $template) {
                if ($template->namespace === $namespace && $template->slug === $slug) {
                    $out[] = $template;
                }
            }
        }

        return $out;
    }
}
