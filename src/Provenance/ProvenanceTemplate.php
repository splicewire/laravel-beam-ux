<?php

namespace Splicewire\Beam\Ux\Provenance;

/**
 * One body a package seeds, or once seeded, at a row coordinate (DOCS-06b): the template as the package ships it, with
 * its `{{ token }}` spans unfilled, and the origin a row matching it is stamped with. A prior template is shipped only
 * when rows seeded from it are measured in the field; its origin is the coordinate's CURRENT owner (a stub that moved
 * from beam-ux to beam-docs is beam-docs' now).
 */
final class ProvenanceTemplate
{
    public function __construct(
        public readonly string $origin,
        public readonly ?string $namespace,
        public readonly string $slug,
        public readonly string $template,
        public readonly string $label,
    ) {}
}
