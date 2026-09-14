<?php


return [

    'default' => env('FILE_CONVERTER', 'hawki_converter'),
    'fallback' => 'hawki_converter',

    'converters' => [
        'hawki_converter' => [
            'api_url' => env('HAWKI_FILE_CONVERTER_API_URL'),
            'api_key' => env('HAWKI_FILE_CONVERTER_API_KEY'),
            // Seconds to wait for one synchronous /extract call (OCR is slow on small hosts)
            'timeout' => (int) env('HAWKI_FILE_CONVERTER_TIMEOUT', 300),
        ],
        'gwdg_docling' =>[
            'api_url' => env('GWDG_FILE_CONVERTER_API_URL', 'https://chat-ai.academiccloud.de/v1/documents/convert'),
            'api_key' => env('GWDG_API_KEY')
        ]
    ],


    /*
     * What a chat upload may be.
     *
     * The converter is the source of truth for the extension list:
     * SupportedFormats asks it at runtime (GET / on the HAWKI converter) and
     * caches the answer, so a format the converter learns needs no HAWKI
     * change. The table below is the MIME lookup for those extensions - the
     * converter reports extensions only - and the fallback list while it is
     * unreachable.
     */
    'formats' => [

        // Office / documents
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'dot' => 'application/msword',
        'docm' => 'application/vnd.ms-word.document.macroEnabled.12',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'dotm' => 'application/vnd.ms-word.template.macroEnabled.12',
        'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'rtf' => 'application/rtf',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'pages' => 'application/x-iwork-pages-sffpages',
        'key' => 'application/x-iwork-keynote-sffkey',
        'numbers' => 'application/x-iwork-numbers-sffnumbers',
        'wp' => 'application/vnd.wordperfect',
        'wp5' => 'application/vnd.wordperfect',
        'wp6' => 'application/vnd.wordperfect',
        'wpd' => 'application/vnd.wordperfect',
        'hwp' => 'application/x-hwp',
        'hwpx' => 'application/haansofthwpx',
        'epub' => 'application/epub+zip',
        'fb2' => 'application/x-fictionbook+xml',

        // Slides / sheets
        'ppt' => 'application/vnd.ms-powerpoint',
        'pot' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'pptm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
        'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
        'potm' => 'application/vnd.ms-powerpoint.template.macroEnabled.12',
        'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
        'xls' => 'application/vnd.ms-excel',
        'xlt' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
        'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
        'xla' => 'application/vnd.ms-excel.template.macroEnabled.12',
        'xlam' => 'application/vnd.ms-excel.addin.macroEnabled.12',
        'dbf' => 'application/x-dbf',

        // Mail
        'eml' => 'message/rfc822',
        'msg' => 'application/vnd.ms-outlook',
        'pst' => 'application/vnd.ms-outlook-pst',

        // Markup / text
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'commonmark' => 'text/x-commonmark',
        'mdx' => 'text/mdx',
        'rmd' => 'text/x-r-markdown',
        'qmd' => 'text/x-quarto',
        'rst' => 'text/x-rst',
        'adoc' => 'text/asciidoc',
        'asciidoc' => 'text/asciidoc',
        'org' => 'text/x-org',
        'djot' => 'text/x-djot',
        'tex' => 'application/x-latex',
        'latex' => 'application/x-latex',
        'typ' => 'application/x-typst',
        'typst' => 'application/x-typst',
        'html' => 'text/html',
        'htm' => 'text/html',
        'xml' => 'application/xml',
        'opml' => 'application/xml+opml',
        'docbook' => 'application/docbook+xml',
        'docbook4' => 'application/docbook+xml',
        'docbook5' => 'application/docbook+xml',
        'dbk' => 'application/docbook+xml',
        'jats' => 'application/x-jats+xml',
        'nxml' => 'application/x-jats+xml',
        'bib' => 'application/x-bibtex',
        'ris' => 'application/x-research-info-systems',
        'enw' => 'application/x-endnote+xml',
        'nbib' => 'application/x-pubmed',
        'json' => 'application/json',
        'jsonl' => 'application/x-ndjson',
        'ndjson' => 'application/x-ndjson',
        'yaml' => 'application/x-yaml',
        'yml' => 'application/x-yaml',
        'toml' => 'application/toml',
        'csv' => 'text/csv',
        'tsv' => 'text/tab-separated-values',
        'vtt' => 'text/vtt',
        'ipynb' => 'application/x-ipynb+json',

        // Images (the converter OCRs them, HAWKI sends them to vision models)
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'svg' => 'image/svg+xml',
        'avif' => 'image/avif',
        'avcs' => 'image/avcs',
        'heic' => 'image/heic',
        'heics' => 'image/heic',
        'heif' => 'image/heif',
        'jp2' => 'image/jp2',
        'j2k' => 'image/jp2',
        'j2c' => 'image/jp2',
        'jpx' => 'image/jpx',
        'jpm' => 'image/jpm',
        'mj2' => 'image/mj2',
        'jb2' => 'image/x-jbig2',
        'jbig2' => 'image/x-jbig2',
        'pbm' => 'image/x-portable-bitmap',
        'pgm' => 'image/x-portable-graymap',
        'ppm' => 'image/x-portable-pixmap',
        'pnm' => 'image/x-portable-anymap',

        // Audio / video - listed by the converter, rejected by its HTTP API
        // today. Excluded by default, see `excluded_extensions`.
        'mp3' => 'audio/mpeg',
        'mpga' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'wav' => 'audio/wav',
        'webm' => 'audio/webm',
        'mp4' => 'video/mp4',
        'mpeg' => 'video/mp4',

        // Archives - never accepted, see `never_accept`.
        'zip' => 'application/zip',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        'tgz' => 'application/gzip',
        '7z' => 'application/x-7z-compressed',

        // Known to HAWKI, not to the converter: a diagram from the built-in editor.
        'drawio' => 'application/vnd.jgraph.mxfile',
    ],

    /*
     * Never uploadable, whatever the converter offers and whatever an admin
     * sets: an archive is an opaque container (zip bombs, nesting, path
     * traversal inside the converter, no per-member type check) and the chat
     * has no use for one. `pst` is an Outlook mail archive and counts as one.
     */
    'never_accept' => ['zip', 'tar', 'gz', 'tgz', '7z', 'pst'],

    /*
     * Turned off by admin choice, on top of `never_accept`. Audio and video are
     * the default: the converter advertises them but its API answers 400, and
     * HAWKI transcribes media in its own pipeline (transcription module). Empty
     * this once the converter can transcribe.
     */
    'excluded_extensions' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FILE_CONVERTER_EXCLUDED_EXTENSIONS', 'mp3,mpga,m4a,wav,webm,mp4,mpeg'))
    ))),

    // How long the extension list fetched from the converter is cached, in seconds.
    'formats_cache_ttl' => (int) env('FILE_CONVERTER_FORMATS_CACHE_TTL', 3600),

    /*
     * Figures the converter extracts from a document (assets/*.webp with the
     * HAWKI converter >= 3.x, images_*\/*.png|jpeg with 1.x) are forwarded to
     * vision-capable models together with the document text. Every image is
     * downscaled to `max_dimension` on its longest side, images smaller than
     * `min_dimension` on either side (logos, bullets, rules) are dropped and
     * at most `max_per_document` images are sent, in document order, and only
     * with the newest `recent_turns` user messages. The same filter and a lossy
     * webp re-encode are applied once at upload time, so the stored output is a
     * fraction of what the converter returns.
     */
    'document_images' => [
        'enabled' => (bool) env('FILE_CONVERTER_DOCUMENT_IMAGES', true),
        'max_per_document' => (int) env('FILE_CONVERTER_DOCUMENT_IMAGES_MAX', 10),
        'max_dimension' => (int) env('FILE_CONVERTER_DOCUMENT_IMAGES_MAX_DIMENSION', 1024),
        'min_dimension' => (int) env('FILE_CONVERTER_DOCUMENT_IMAGES_MIN_DIMENSION', 100),
        // Figures travel only with the newest N user messages (0 = every turn).
        'recent_turns' => (int) env('FILE_CONVERTER_DOCUMENT_IMAGES_RECENT_TURNS', 1),
    ],
];
