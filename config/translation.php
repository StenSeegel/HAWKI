<?php

return [

    /**
     * Translation Driver
     * 
     * Supported: 'deepl', 'ai'
     */
    'driver' => env('TRANSLATION_DRIVER', 'deepl'),

    /**
     * AI Model for Translation
     * 
     * Used when driver is set to 'ai'.
     * Example: 'gpt-4o', 'anthropic.claude-3-sonnet', etc.
     */
    'ai_model' => env('TRANSLATION_AI_MODEL', 'gpt-4o'),

    /**
     * Default translation provider (Legacy/DeepL specific)
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
    
    /**
     * Filter Glossary Entries
     * 
     * If true, only glossary entries where the source term exists in the text
     * will be included in the prompt.
     */
    'filter_glossary' => env('TRANSLATION_FILTER_GLOSSARY', true),

];
