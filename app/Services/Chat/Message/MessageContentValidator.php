<?php

namespace App\Services\Chat\Message;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The shape of a message's content: an encrypted text and/or a list of
 * attachments. A content that does not fit throws a ValidationException, which
 * the framework answers with a 422 naming the field. It used to return null
 * instead, and the message handler then crashed on the missing content - the
 * client saw a 500 and the message stayed frozen in the input.
 */
class MessageContentValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(array $content): array
    {
        $rules = [
            'text' => 'nullable|array',
            'text.ciphertext' => 'required_with:text|string',
            'text.iv' => 'required_with:text|string',
            'text.tag' => 'required_with:text|string',

            'attachments' => 'nullable|array',
            'attachments.*.uuid' => 'required_with:attachments|string',
            'attachments.*.name' => 'required_with:attachments|string',
            'attachments.*.mime' => 'required_with:attachments|string',
        ];

        $validator = Validator::make($content, $rules);

        $validator->after(function ($validator) use ($content) {
            $textEmpty = empty($content['text']);
            $attachmentsEmpty = empty($content['attachments']);
            if ($textEmpty && $attachmentsEmpty) {
                $validator->errors()->add('content', 'Either text or attachments must be provided in content.');
            }
        });

        return $validator->validate();
    }
}
