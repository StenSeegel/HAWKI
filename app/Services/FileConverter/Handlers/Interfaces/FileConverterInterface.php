<?php

namespace App\Services\FileConverter\Handlers\Interfaces;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Finder\SplFileInfo;

interface FileConverterInterface
{

    /**
     * @param  string|null  $filename  the real name of the file - the HAWKI
     *         converter validates by extension, so a string payload has to say
     *         what it is. Ignored when $file carries a name of its own.
     */
    public function convert(UploadedFile|SplFileInfo|string $file, ?string $filename = null): array;

    /**
     * The file extensions this converter reads, dotted (".pptx") or bare.
     * An empty list means "could not say", and the caller falls back.
     *
     * @return string[]
     */
    public function supportedFormats(): array;

}
