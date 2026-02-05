<?php
declare(strict_types=1);

namespace App\Services\Translation\Providers;

use App\Services\Translation\Contracts\TranslationProviderInterface;
use App\Services\Translation\Exceptions\InvalidLanguageException;
use App\Services\Translation\Exceptions\QuotaExceededException;
use App\Services\Translation\Exceptions\TranslationFailedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeeplTranslationProvider implements TranslationProviderInterface
{
    private ?string $apiKey;
    private string $baseUrl;
    
    /**
     * Supported DeepL languages
     * @var array<string, string>
     */
    private const SUPPORTED_LANGUAGES = [
        'AR' => 'Arabic',
        'BG' => 'Bulgarian',
        'CS' => 'Czech',
        'DA' => 'Danish',
        'DE' => 'German',
        'EL' => 'Greek',
        'EN' => 'English',
        'EN-GB' => 'English (British)',
        'EN-US' => 'English (American)',
        'ES' => 'Spanish',
        'ET' => 'Estonian',
        'FI' => 'Finnish',
        'FR' => 'French',
        'HU' => 'Hungarian',
        'ID' => 'Indonesian',
        'IT' => 'Italian',
        'JA' => 'Japanese',
        'KO' => 'Korean',
        'LT' => 'Lithuanian',
        'LV' => 'Latvian',
        'NB' => 'Norwegian (Bokmål)',
        'NL' => 'Dutch',
        'PL' => 'Polish',
        'PT' => 'Portuguese',
        'PT-BR' => 'Portuguese (Brazilian)',
        'PT-PT' => 'Portuguese (European)',
        'RO' => 'Romanian',
        'RU' => 'Russian',
        'SK' => 'Slovak',
        'SL' => 'Slovenian',
        'SV' => 'Swedish',
        'TR' => 'Turkish',
        'UK' => 'Ukrainian',
        'ZH' => 'Chinese (Simplified)',
    ];
    
    public function __construct()
    {
        $this->apiKey = config('services.deepl.api_key');
        $this->baseUrl = config('services.deepl.base_url', 'https://api-free.deepl.com/v2');
    }
    
    /**
     * Validate that API key is configured
     * 
     * @throws TranslationFailedException
     */
    private function validateApiKey(): void
    {
        if (empty($this->apiKey)) {
            Log::warning('DeepL API key is not configured');
            throw new TranslationFailedException('DeepL API key ist nicht konfiguriert. Bitte kontaktieren Sie den Administrator.');
        }
    }
    
    /**
     * Translate text using DeepL API
     *
     * @param string $text Text to translate (max 50,000 characters)
     * @param string|null $sourceLang Source language code (null for auto-detect)
     * @param string $targetLang Target language code
     * @return array{text: string, detected_source_language: string|null}
     * @throws TranslationFailedException
     * @throws InvalidLanguageException
     * @throws QuotaExceededException
     */
    public function translate(string $text, ?string $sourceLang, string $targetLang): array
    {
        // Validate API key is configured
        $this->validateApiKey();
        
        // Validate text length
        if (strlen($text) > 50000) {
            throw new TranslationFailedException('Text exceeds maximum length of 50,000 characters');
        }
        
        // Validate target language
        $targetLang = strtoupper($targetLang);
        if (!$this->isLanguageSupported($targetLang)) {
            throw new InvalidLanguageException("Target language '{$targetLang}' is not supported");
        }
        
        // Validate source language if provided
        if ($sourceLang !== null) {
            $sourceLang = strtoupper($sourceLang);
            if (!$this->isLanguageSupported($sourceLang)) {
                throw new InvalidLanguageException("Source language '{$sourceLang}' is not supported");
            }
        }
        
        // Build request payload
        $payload = [
            'text' => [$text],
            'target_lang' => $targetLang,
        ];
        
        if ($sourceLang !== null) {
            $payload['source_lang'] = $sourceLang;
        }
        
        try {
            $response = Http::withHeaders([
                'Authorization' => "DeepL-Auth-Key {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/translate", $payload);
            
            // Handle API errors
            if ($response->failed()) {
                $this->handleApiError($response->status(), $response->body());
            }
            
            $data = $response->json();
            
            if (!isset($data['translations'][0])) {
                throw new TranslationFailedException('Invalid response format from DeepL API');
            }
            
            $translation = $data['translations'][0];
            
            return [
                'text' => $translation['text'],
                'detected_source_language' => $translation['detected_source_language'] ?? null,
            ];
            
        } catch (ConnectionException $e) {
            Log::error('DeepL API connection failed', [
                'error' => $e->getMessage(),
            ]);
            throw new TranslationFailedException('Failed to connect to translation service', 0, $e);
        }
    }
    
    /**
     * Improve text using DeepL Write API
     *
     * @param string $text Text to improve (max 50,000 characters)
     * @param string|null $targetLang Target language code for the improved text
     * @return array{text: string}
     * @throws TranslationFailedException
     */
    public function write(string $text, ?string $targetLang = null): array
    {
        // Validate API key is configured
        $this->validateApiKey();
        
        // Validate text length
        if (strlen($text) > 50000) {
            throw new TranslationFailedException('Text exceeds maximum length of 50,000 characters');
        }
        
        // Validate target language if provided
        if ($targetLang !== null) {
            $targetLang = strtoupper($targetLang);
            if (!$this->isLanguageSupported($targetLang)) {
                throw new InvalidLanguageException("Target language '{$targetLang}' is not supported");
            }
        }
        
        try {
            $response = Http::withHeaders([
                'Authorization' => "DeepL-Auth-Key {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/write/rephrase", [
                'text' => [$text],
                'target_lang' => $targetLang,
            ]);
            
            // Handle API errors
            if ($response->failed()) {
                $this->handleApiError($response->status(), $response->body());
            }
            
            $data = $response->json();
            
            if (!isset($data['improvements'][0]['text'])) {
                throw new TranslationFailedException('Invalid response format from DeepL Write API');
            }
            
            return [
                'text' => $data['improvements'][0]['text'],
            ];
            
        } catch (ConnectionException $e) {
            Log::error('DeepL Write API connection failed', [
                'error' => $e->getMessage(),
            ]);
            throw new TranslationFailedException('Failed to connect to text improvement service', 0, $e);
        }
    }
    
    /**
     * Get list of supported languages
     *
     * @return array<string, string>
     */
    public function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }
    
    /**
     * Check if the provider is available and properly configured
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }
    
    /**
     * Get the provider name
     * 
     * @return string
     */
    public function getName(): string
    {
        return 'deepl';
    }
    
    /**
     * Check if a language code is supported
     *
     * @param string $langCode
     * @return bool
     */
    private function isLanguageSupported(string $langCode): bool
    {
        $langCode = strtoupper($langCode);
        
        // Check exact match
        if (isset(self::SUPPORTED_LANGUAGES[$langCode])) {
            return true;
        }
        
        // Check base language (e.g., 'EN' for 'EN-US')
        $baseLang = explode('-', $langCode)[0];
        return isset(self::SUPPORTED_LANGUAGES[$baseLang]);
    }
    
    /**
     * Handle DeepL API errors
     *
     * @param int $statusCode
     * @param string $body
     * @throws QuotaExceededException
     * @throws InvalidLanguageException
     * @throws TranslationFailedException
     */
    private function handleApiError(int $statusCode, string $body): void
    {
        Log::error('DeepL API error', [
            'status_code' => $statusCode,
            'response_body' => $body,
        ]);
        
        match ($statusCode) {
            400 => throw new InvalidLanguageException('Bad request: Invalid parameters'),
            403 => throw new TranslationFailedException('Authentication failed: Invalid API key'),
            404 => throw new TranslationFailedException('API endpoint not found'),
            413 => throw new TranslationFailedException('Request entity too large'),
            429 => throw new TranslationFailedException('Too many requests'),
            456 => throw new QuotaExceededException('Translation quota exceeded'),
            503 => throw new TranslationFailedException('Service temporarily unavailable'),
            default => throw new TranslationFailedException("DeepL API error: HTTP {$statusCode}"),
        };
    }
}
