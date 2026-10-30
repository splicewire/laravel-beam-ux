<?php

namespace Splicewire\Beam\Ux\Media\Data;

use Illuminate\Http\UploadedFile;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

#[Title('Image upload')]
final class EntryImageUploadData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        // A host generator cannot transform UploadedFile; on the client the upload is a browser File.
        #[LiteralTypeScriptType('File')]
        public UploadedFile $file,
    ) {}

    public static function rules(): array
    {
        return ['file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/avif', 'max:8192']];
    }
}
