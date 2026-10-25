<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/**
 * A hand-made Redirect as an author gives it (ADR-0236 d5). `target` names the target in the author's terms: a page by
 * its current URL (held by identity once saved), a path on this site, an address on another site, or a Record as
 * `collection:id`. The Redirect service resolves it into the row's target columns before the write.
 */
#[TypeScript, Title('Redirect')]
final class RedirectInputData extends BeamData implements MapsToModelAttributes
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Title('From'), Description('The old path, like /old-page.')]
        public string $path,
        #[Title('Kind of target'), In(BeamUxRedirect::KINDS), Description('A page, a Record, a path on this site, or an address on another site.')]
        public string $targetKind,
        #[Title('To'), Description('A page by its current URL; a path like /new-page; an address like https://example.com; a Record as collection:id.')]
        public string $target,
        #[Title('Match'), In(BeamUxRedirect::MATCHES), Description('`exact`: this path only. `prefix`: this path and everything under it, the rest carried to the target.')]
        public string $match = 'exact',
        #[Title('Permanent'), Description('301 when permanent (the default), 302 when temporary.')]
        public bool $permanent = true,
    ) {}

    /** The columns this input owns; the target columns are the service's (from `target`). */
    public function toModelAttributes(): array
    {
        return ['path' => $this->path, 'match' => $this->match, 'target_kind' => $this->targetKind, 'permanent' => $this->permanent];
    }
}
