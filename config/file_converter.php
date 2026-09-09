<?php


return [

    'default' => env('FILE_CONVERTER', 'hawki_converter'),
    'fallback' => 'hawki_converter',

    'converters' => [
        'hawki_converter' => [
            'api_url' => env('HAWKI_FILE_CONVERTER_API_URL'),
            'api_key' => env('HAWKI_FILE_CONVERTER_API_KEY'),
        ],
        'gwdg_docling' =>[
            'api_url' => env('GWDG_FILE_CONVERTER_API_URL', 'https://chat-ai.academiccloud.de/v1/documents/convert'),
            'api_key' => env('GWDG_API_KEY')
        ]
    ],

    /*
     * Figures the converter extracts from a document (assets/*.webp with the
     * HAWKI converter >= 3.x, images_*\/*.png|jpeg with 1.x) are forwarded to
     * vision-capable models together with the document text. Every image is
     * downscaled to `max_dimension` on its longest side, images smaller than
     * `min_dimension` on either side (logos, bullets, rules) are dropped and
     * at most `max_per_document` images are sent, in document order.
     */
    'document_images' => [
        'enabled' => (bool) env('FILE_CONVERTER_DOCUMENT_IMAGES', true),
        'max_per_document' => (int) env('FILE_CONVERTER_DOCUMENT_IMAGES_MAX', 10),
        'max_dimension' => (int) env('FILE_CONVERTER_DOCUMENT_IMAGES_MAX_DIMENSION', 1024),
        'min_dimension' => (int) env('FILE_CONVERTER_DOCUMENT_IMAGES_MIN_DIMENSION', 100),
    ],
];
