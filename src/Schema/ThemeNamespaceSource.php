<?php

namespace Splicewire\Beam\Ux\Schema;

/** An optional capability contributes one namespaced theme schema without a generic UX dependency. */
interface ThemeNamespaceSource
{
    /** @return array<string, mixed> JSON Schema with a theme.<namespace> $id. */
    public function schema(): array;
}
