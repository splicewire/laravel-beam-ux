<?php

namespace Splicewire\Beam\Ux\Codegen;

use Rushing\Codegen\Laravel\Contracts\ModelSource;
use Rushing\Codegen\Model\CodegenModel;
use Splicewire\Beam\Ux\Codec\CodecRegistry;

/**
 * A `ModelSource` (`rushing/laravel-codegen`) reflecting {@see CodecRegistry}'s CURRENTLY registered
 * formats into a real, generatable `UxFormat` enum — the follow-up
 * `.scratch/rushing/laravel-codegen/registry-backed-enums/01-registry-reflecting-model-source.md`
 * named but deliberately left undone: that ticket proved the PATTERN generically (a fake registry, in
 * `rushing/laravel-codegen`'s own test suite); this is the real wiring.
 *
 * Genuinely reflects live state, not a fixed snapshot: `CodecRegistry::formats()` returns whatever a
 * host's own providers have `register()`ed by the time this resolves (the tsx/mdx/css seed set PLUS
 * anything a host added), so `codegen:generate` run after a host registers its own `BodyCodec` picks
 * that up with no change here.
 *
 * NOT auto-bound by {@see \Splicewire\Beam\Ux\BeamUxServiceProvider} — `ModelSource` is a single-
 * binding-per-app seam (`rushing/laravel-codegen`'s own contract docblock: "a host binds ONE
 * implementation"), and a host may already bind its own `ModelSource` composing several concerns
 * (routes, other schemas). Binding this unconditionally from beam-ux's provider would silently
 * clobber — or be clobbered by — whatever else a host's `ModelSource` needs to contribute. A host
 * that wants ONLY this contributes it directly:
 *
 *   $this->app->instance(ModelSource::class, app(CodecRegistryModelSource::class));
 *
 * A host composing several sources calls `CodecRegistryModelSource::enumInto()` as one ingredient of
 * its own `model()` instead.
 */
class CodecRegistryModelSource implements ModelSource
{
    public function __construct(private CodecRegistry $codecs) {}

    public function model(): CodegenModel
    {
        return $this->enumInto(new CodegenModel);
    }

    /** Declares the `UxFormat` enum on an existing model — the composable half, for a host `ModelSource`
     * that contributes more than just this. */
    public function enumInto(CodegenModel $model): CodegenModel
    {
        return $model->enum(
            'UxFormat',
            $this->codecs->formats(),
            doc: 'Generated from CodecRegistry — every BodyCodec format registered at boot. '.
                'Hand-edits are overwritten on the next codegen:generate.',
        );
    }
}
