<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider ID Mapping
    |--------------------------------------------------------------------------
    |
    | Maps HAWKI's internal api_providers.id to the providerId used in
    | model_info.json from https://models.hawki.info/models.json
    |
    | Format: [hawki_provider_id => json_provider_id]
    |
    | University providers (GWDG, ki@JLU, local Ollama) will typically
    | NOT have matches in the JSON and will get 'base_model' matches.
    |
    */

    'provider_mapping' => [
        // Public/Commercial Providers (likely to have exact matches)
        1 => 'openai',           // OpenAI (USA)
        3 => 'google',           // Google (USA) → Maps to various Google providers
        4 => 'anthropic',        // Anthropic (USA)

        // University/Local Providers (won't match in JSON)
        2 => null,               // GWDG (EU) - University provider, no JSON match
        5 => null,               // Ollama - Local deployment, no JSON match (ollama-cloud is different)
        6 => null,               // Open WebUI - Local deployment, no JSON match
        7 => null,               // ki@JLU - University provider, no JSON match
    ],

    /*
    |--------------------------------------------------------------------------
    | University/Manual Providers
    |--------------------------------------------------------------------------
    |
    | Provider IDs that require manual configuration because they are not
    | included in the public model_info.json dataset.
    |
    | These providers will get 'base_model' or 'none' match types and
    | will need provider-specific data (context_length, prices) set manually.
    |
    */

    'requires_manual_setup' => [
        2,  // GWDG (EU)
        5,  // Ollama (local deployment)
        6,  // Open WebUI
        7,  // ki@JLU
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Aliases
    |--------------------------------------------------------------------------
    |
    | Some JSON providers may have multiple IDs or variations.
    | This allows flexible matching when provider names vary.
    |
    | Format: [hawki_provider_id => [possible_json_provider_ids]]
    |
    */

    'provider_aliases' => [
        3 => ['google', 'google-ai', 'gemini'],  // Google may appear as different IDs
        4 => ['anthropic'],                       // Anthropic
        1 => ['openai'],                          // OpenAI
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Strategy
    |--------------------------------------------------------------------------
    |
    | When a university provider (like GWDG) has a model that exists in JSON
    | but the provider itself doesn't match, we can use fallback strategies:
    |
    | - 'first': Use the first provider in the JSON providers array
    | - 'popular': Try to use well-known providers (OpenAI, Anthropic, etc.)
    | - 'none': Don't use any provider data, only base model metadata
    |
    */

    'fallback_strategy' => 'popular',

    /*
    |--------------------------------------------------------------------------
    | Popular Providers (for fallback)
    |--------------------------------------------------------------------------
    |
    | Preferred provider IDs from JSON to use as fallback when our provider
    | isn't found but we want to get reasonable context_length/price data.
    |
    */

    'popular_providers' => [
        'openai',
        'anthropic',
        'google',
        'azure',
        'github-models',
    ],

];
