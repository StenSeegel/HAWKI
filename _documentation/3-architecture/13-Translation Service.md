# Translation Service

This document describes HAWKI's translation service architecture using a plugin pattern to support multiple translation providers.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Key Components](#key-components)
3. [Data Flow](#data-flow)
4. [How to Add a New Provider](#how-to-add-new-provider)
5. [Configuration](#configuration)
6. [API Endpoints](#api-endpoints)
7. [Usage Examples](#usage-examples)

## Architecture Overview

The translation service uses a plugin architecture with factory pattern and dependency injection to support multiple providers (currently DeepL, extensible to Google Translate, LibreTranslate, etc.). The architecture follows the same pattern as HAWKI's FileConverter service.

**File Structure:**
```
app/Services/Translation/
├── Contracts/
│   └── TranslationProviderInterface.php
├── Providers/
│   └── DeeplTranslationProvider.php
├── TranslationFactory.php
└── TranslationService.php
```

## Key Components

### Service Layer
- **TranslationService**: Main entry point providing unified translation methods
- **TranslationFactory**: Creates provider instances based on configuration

### Provider Layer
- **TranslationProviderInterface**: Contract defining required methods for all providers
- **DeeplTranslationProvider**: DeepL API implementation

### Configuration
- **config/translation.php**: Provider selection and fallback settings
- **config/services.php**: API credentials for providers

## Data Flow

```
Frontend (translate.js)
    ↓
DeeplController
    ↓
TranslationService (injected)
    ↓
TranslationFactory → creates provider
    ↓
TranslationProvider (DeeplProvider)
    ↓
External API (DeepL, Google, etc.)
```

## TranslationProviderInterface

All providers must implement these methods:

```php
interface TranslationProviderInterface
{
    // Translate text (sourceLang null = auto-detect)
    public function translate(string $text, ?string $sourceLang, string $targetLang): array;
    
    // Get supported language codes
    public function getSupportedLanguages(): array;
    
    // Check if provider is configured and available
    public function isAvailable(): bool;
    
    // Return provider name ('deepl', 'google', etc.)
    public function getName(): string;
}
```

## How to Add a New Provider

### 1. Create Provider Class

Create `app/Services/Translation/Providers/GoogleTranslationProvider.php`:

```php
<?php

namespace App\Services\Translation\Providers;

use App\Services\Translation\Contracts\TranslationProviderInterface;

class GoogleTranslationProvider implements TranslationProviderInterface
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.google.translate_api_key', '');
    }

    public function translate(string $text, ?string $sourceLang, string $targetLang): array
    {
        // Implement Google Translate API call
    }

    public function getSupportedLanguages(): array
    {
        // Return supported languages
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getName(): string
    {
        return 'google';
    }
}
```

### 2. Register in Factory

Update `TranslationFactory::create()`:

```php
return match ($provider) {
    'deepl' => new DeeplTranslationProvider(),
    'google' => new GoogleTranslationProvider(),
    default => new DeeplTranslationProvider(),
};
```

### 3. Add Configuration

**config/services.php:**
```php
'google' => [
    'translate_api_key' => env('GOOGLE_TRANSLATE_API_KEY'),
],
```

**.env:**
```bash
TRANSLATION_PROVIDER=google
GOOGLE_TRANSLATE_API_KEY=your-key-here
```

## Configuration

### Environment Variables

```bash
# Provider selection
TRANSLATION_PROVIDER=deepl

# DeepL Configuration
DEEPL_API_KEY=your-deepl-api-key
DEEPL_BASE_URL=https://api-free.deepl.com/v2
```

### config/translation.php

```php
return [
    'default_provider' => env('TRANSLATION_PROVIDER', 'deepl'),
    'fallback_provider' => env('TRANSLATION_FALLBACK_PROVIDER', 'deepl'),
];
```

## API Endpoints

### Translate Text
```
POST /req/deepl/translate
Body: {"text": "Hello", "source_lang": "EN", "target_lang": "DE"}
Response: {"translations": [{"detected_source_language": "EN", "text": "Hallo"}]}
```

### Improve Text (DeepL Write)
```
POST /req/ai/write
Body: {"text": "Text to improve", "target_lang": "EN"}
```

### Get Supported Languages
```
GET /req/ai/models
Response: {"languages": [{"code": "DE", "name": "German"}, ...]}
```

## Usage Examples

### In Controller

```php
use App\Services\Translation\TranslationService;

public function __construct(private TranslationService $translationService) {}

public function translate()
{
    // Auto-detect source language
    $result = $this->translationService->translate('Hello', null, 'DE');
    
    // Explicit source language
    $result = $this->translationService->translate('Bonjour', 'FR', 'EN');
    
    // Check availability
    if (!$this->translationService->isAvailable()) {
        return response()->json(['error' => 'Service unavailable'], 503);
    }
}
```

### Using Specific Provider

```php
use App\Services\Translation\TranslationFactory;

$factory = app(TranslationFactory::class);
$provider = $factory->create('google');
$result = $provider->translate('Hello', null, 'DE');
```

### Frontend

```javascript
const response = await fetch('/req/deepl/translate', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken
    },
    body: JSON.stringify({
        text: 'Hello World',
        source_lang: 'EN',
        target_lang: 'DE'
    })
});
```

## Planned Features

- **Additional Providers**: Google Translate, LibreTranslate, Microsoft Translator
- **Caching**: Reduce API costs with translation caching
- **Batch Translation**: Multiple texts in one request
- **Automatic Fallback**: Switch to backup provider on failure
- **Usage Analytics**: Track provider usage and costs

## Developer Notes

- Always inject `TranslationService` in controllers, not specific providers
- Check `isAvailable()` before making translation requests
- Provider-specific features (like DeepL Write) are optional
- Follow the same pattern as `FileConverterFactory` for consistency
- Consider API rate limits and costs when implementing providers

## Related Documentation

- [Model Connection](6-Model%20Connection.md) - Similar plugin architecture
- [Environment Variables](10-dot%20Env.md) - Configuration reference
