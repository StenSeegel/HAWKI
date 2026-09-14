<?php

/*
 * The page renderer: a sidecar (_docker/page-render) that turns a slide deck
 * into one PNG per slide. The file converter only extracts a deck's text and
 * the image files embedded in it, so a model given a poster could name the
 * icon on it but not say where it sits. With the slides rendered it sees the
 * layout.
 */
return [
    'enabled' => (bool) env('PAGE_RENDER_ENABLED', true),

    // API root of the sidecar; empty disables rendering entirely.
    'api_url' => env('PAGE_RENDER_API_URL', 'http://page-render'),
    // RENDER_API_KEY on the sidecar. Empty on both sides means no check.
    'api_key' => env('PAGE_RENDER_API_KEY', ''),
    // Seconds to wait for one render; LibreOffice on a big deck is slow.
    'timeout' => (int) env('PAGE_RENDER_TIMEOUT', 120),

    /*
     * Which uploads are rendered, by extension. Slide formats by default. PDF
     * is left out: the converter already OCRs a PDF and extracts its figures,
     * so rendering its pages too would send every picture twice. Add "pdf"
     * here when the layout of a PDF matters more than that cost.
     */
    'formats' => array_values(array_filter(array_map(
        static fn(string $ext): string => strtolower(ltrim(trim($ext), '.')),
        explode(',', (string) env('PAGE_RENDER_FORMATS', 'pptx,ppt,pptm,ppsx,pps,potx,potm,pot,odp,otp,fodp,key'))
    ))),

    // Pages rendered and sent per document, in document order.
    'max_pages' => (int) env('PAGE_RENDER_MAX_PAGES', 20),
    // Render resolution. 110 dpi puts a 16:9 slide at ~1470 px wide before
    // DocumentImageService downscales it to document_images.max_dimension.
    'dpi' => (int) env('PAGE_RENDER_DPI', 110),

    /*
     * When a document is rendered, drop the figures the converter cut out of
     * it. A slide's icons are in the slide image already; sending them again
     * as separate pictures costs image tokens and gave the model two things
     * to confuse. Off, both travel.
     */
    'replace_figures' => (bool) env('PAGE_RENDER_REPLACE_FIGURES', true),
];
