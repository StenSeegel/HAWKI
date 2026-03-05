<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GlossaryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'source_language' => ['required', 'string', 'max:10'],
            'target_language' => ['required', 'string', 'max:10'],
            'visibility' => ['required', 'in:private,team,org,public'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A CSV file is required.',
            'file.mimes' => 'The file must be a CSV file.',
            'file.max' => 'The file must not exceed 5MB.',
            'name.required' => 'A glossary name is required.',
            'source_language.required' => 'A source language is required.',
            'target_language.required' => 'A target language is required.',
        ];
    }
}
