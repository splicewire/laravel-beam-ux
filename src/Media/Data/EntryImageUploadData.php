<?php

namespace Splicewire\Beam\Ux\Media\Data;

use Illuminate\Http\UploadedFile;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

final class EntryImageUploadData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(public UploadedFile $file) {}

    public static function rules(): array
    {
        return ['file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/avif', 'max:8192']];
    }
}
