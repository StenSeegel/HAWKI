<?php

namespace App\Rules;

use App\Services\FileConverter\SupportedFormats;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A chat upload has to be a file kind HAWKI can do something with. The browser
 * says so before the upload starts; this is the same check where it counts,
 * for a request that skipped the browser.
 */
class UploadableAttachment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be a file.');

            return;
        }

        $formats = app(SupportedFormats::class);
        $name = $value->getClientOriginalName();

        if ($formats->accepts($name)) {
            return;
        }

        $extension = SupportedFormats::extensionOf($name);
        $extensions = $formats->extensions();
        sort($extensions);

        $fail(sprintf(
            '%s is not a file type HAWKI accepts. Accepted: %s.',
            $extension === '' ? 'A file without an extension' : '.'.$extension,
            implode(', ', array_map(static fn(string $ext): string => '.'.$ext, $extensions))
        ));
    }
}
