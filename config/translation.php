<?php

return [
    
    /**
     * Default translation provider
     * 
     * This provider will be used by default for all translation requests.
     * Available providers: 'deepl', 'google' (future)
     */
    'default' => env('TRANSLATION_PROVIDER', 'deepl'),
    
    /**
     * Fallback translation provider
     * 
     * If the default provider is not available or fails, this provider
     * will be used as a fallback.
     */
    'fallback' => 'deepl',
    
];
