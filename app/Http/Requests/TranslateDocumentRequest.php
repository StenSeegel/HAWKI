<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class TranslateDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Log upload details before validation for debugging.
     */
    protected function prepareForValidation(): void
    {
        $file = $this->file('file');

        Log::debug('[DocTranslation][Validation] Incoming file details', [
            'has_file' => $this->hasFile('file'),
            'client_original_name' => $file?->getClientOriginalName(),
            'client_original_extension' => $file?->getClientOriginalExtension(),
            'client_mime_type' => $file?->getClientMimeType(),
            'guessed_extension' => $file?->guessExtension(),
            'guessed_mime_type' => $file?->getMimeType(),
            'file_size' => $file?->getSize(),
            'is_valid' => $file?->isValid(),
            'error_code' => $file?->getError(),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $allowedExtensions = ['pdf', 'doc', 'docx', 'pptx', 'ppt', 'xlsx', 'xls', 'jpg', 'jpeg', 'png', 'txt', 'htm', 'html', 'srt'];

        return [
            'file' => [
                'required',
                'file',
                'max:20480',
                function (string $attribute, mixed $value, \Closure $fail) use ($allowedExtensions) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($extension, $allowedExtensions)) {
                        $fail('Unsupported file type. Supported: PDF, DOC(X), PPTX, XLSX, JPG, PNG, TXT, HTML, SRT.');
                    }
                },
            ],
            'target_lang' => 'required|string|max:10',
            'source_lang' => 'nullable|string|max:10',
            'formality' => 'nullable|string|max:50',
            'glossary_id' => 'nullable',
            'glossary_id.*' => 'integer|exists:translate_glossaries,id',
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A document file is required.',
            'file.file' => 'The upload must be a valid file.',
            'file.max' => 'The document must not exceed 20MB.',
            'file.mimes' => 'Unsupported file type. Supported: PDF, DOC(X), PPTX, XLSX, JPG, PNG, TXT, HTML, SRT.',
            'target_lang.required' => 'A target language is required.',
        ];
    }
}
