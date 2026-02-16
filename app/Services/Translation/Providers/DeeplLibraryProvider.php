<?php

declare(strict_types=1);

namespace App\Services\Translation\Providers;

use App\Services\Translation\Contracts\TranslationProviderInterface;
use App\Services\Translation\Exceptions\InvalidLanguageException;
use App\Services\Translation\Exceptions\QuotaExceededException;
use App\Services\Translation\Exceptions\TranslationFailedException;
use DeepL\DeepLException;
use DeepL\Translator;
use DeepL\GlossaryEntries;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class DeeplLibraryProvider implements TranslationProviderInterface
{
    private ?Translator $translator;
    private string $apiKey;
    
    /**
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

    public function __construct(?string $apiKey = null, ?Translator $translator = null)
    {
        if ($translator) {
             $this->translator = $translator;
             $this->apiKey = $apiKey ?? 'mock-key';
        } elseif ($apiKey) {
            $this->apiKey = $apiKey;
            try {
                $this->translator = new Translator($apiKey);
            } catch (DeepLException $e) {
                Log::error('Failed to initialize DeepL Translator', ['error' => $e->getMessage()]);
                $this->translator = null;
            }
        } else {
            $this->translator = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function translate(string $text, ?string $sourceLang, string $targetLang, ?int $glossaryId = null): array
    {
        if (!$this->isAvailable()) {
            throw new TranslationFailedException('DeepL provider is not available');
        }

        try {
            $options = [];
            
            // Handle Glossary
             if ($glossaryId && $sourceLang) {
                // Note: The official library handles glossaries via ID. 
                // However, our internal logic creates temporary glossaries on the fly in the old provider.
                // For now, we will reproduce the old logic or adapt.
                // Since the old provider created a NEW glossary every time, we should probably check if we can replicate that 
                // or if we should redesign glossary handling.
                // The interface passes `glossaryId` which is the LOCAL DB ID.
                
                // For this implementation, we will skip complex glossary creation to focus on basic translation, 
                // OR adapt the `createDeepLGlossary` logic if needed. 
                // Given the instruction "full DeepL API compatibility", skipping might be bad.
                // But the `Translator` class makes it easier.
                
                $tempGlossaryId = $this->createDeepLGlossary($glossaryId, $sourceLang, $targetLang);
                if ($tempGlossaryId) {
                    $options['glossary'] = $tempGlossaryId;
                }
            }

            // Fix for DeepL deprecation of 'en' as target language
            if (strtolower($targetLang) === 'en') {
                $targetLang = 'en-US';
            }

            $result = $this->translator->translateText(
                $text,
                $sourceLang,
                $targetLang,
                $options
            );

            // If we created a temp glossary, delete it
            if (isset($options['glossary'])) {
                try {
                     $this->translator->deleteGlossary($options['glossary']);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete temporary DeepL glossary', ['id' => $options['glossary'], 'error' => $e->getMessage()]);
                }
            }

            return [
                'text' => $result->text,
                'detected_source_language' => $result->detectedSourceLang,
            ];

        } catch (DeepLException $e) {
            $this->handleDeepLException($e);
            throw new TranslationFailedException($e->getMessage()); // Fallback
        }
    }

    /**
     * @inheritDoc
     */
    public function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return $this->translator !== null;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'deepl-library';
    }

     /**
     * Replicating the logic to create a temporary glossary using the library
     */
    private function createDeepLGlossary(int $localGlossaryId, string $sourceLang, string $targetLang): ?string
    {
        $entries = \App\Models\TranslateGlossaryEntry::where('glossary_id', $localGlossaryId)
            ->where('source_language', strtoupper($sourceLang))
            ->where('target_language', strtoupper($targetLang))
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $glossaryEntries = [];
        foreach ($entries as $entry) {
            $glossaryEntries[$entry->source_term] = $entry->target_term;
        }

        try {
            $glossary = $this->translator->createGlossary(
                'temp_glossary_'.uniqid(),
                strtolower($sourceLang),
                strtolower($targetLang),
                GlossaryEntries::fromEntries($glossaryEntries)
            );
            return $glossary->glossaryId;
        } catch (DeepLException $e) {
            Log::warning('DeepL Glossary Creation Failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function handleDeepLException(DeepLException $e): void
    {
        $msg = $e->getMessage();
        
        // Basic mapping based on message content as DeepL library might not throw specific exception types for everything
        if (str_contains($msg, '403')) {
             throw new TranslationFailedException('Authentication failed: Invalid API key', 0, $e);
        }
        if (str_contains($msg, '456')) {
            throw new QuotaExceededException('Translation quota exceeded', 0, $e);
        }
        if (str_contains($msg, '400')) {
             throw new InvalidLanguageException('Bad request: ' . $msg, 0, $e);
        }
        
        throw new TranslationFailedException('DeepL Error: ' . $msg, 0, $e);
    }

    /**
     * Improve text using DeepL Write API
     *
     * @param  string  $text  Text to improve (max 50,000 characters)
     * @param  string|null  $targetLang  Target language code for the improved text
     * @return array{text: string}
     *
     * @throws TranslationFailedException
     */
    public function write(string $text, ?string $targetLang = null): array
    {
        // 1. Validate API Key
        if (!$this->isAvailable()) {
            throw new TranslationFailedException('DeepL provider is not available');
        }

        // 2. Validate Text Length
        if (strlen($text) > 50000) {
            throw new TranslationFailedException('Text exceeds maximum length of 50,000 characters');
        }

        // 3. Determine Base URL
        // We can inspect the key: if it ends in :fx, it is free.
        $baseUrl = 'https://api.deepl.com/v2';
        if (str_ends_with($this->apiKey, ':fx')) {
            $baseUrl = 'https://api-free.deepl.com/v2';
        }

        // 4. Validate Target Lang
        if ($targetLang !== null) {
            $targetLang = strtoupper($targetLang);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "DeepL-Auth-Key {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/write/rephrase", [
                'text' => [$text],
                'target_lang' => $targetLang,
            ]);

            // Handle API errors
            if ($response->failed()) {
                $message = $response->json('message') ?? 'Unknown error';
                throw new TranslationFailedException("DeepL Write API error: {$message} ({$response->status()})");
            }

            $data = $response->json();

            if (! isset($data['improvements'][0]['text'])) {
                throw new TranslationFailedException('Invalid response format from DeepL Write API');
            }

            return [
                'text' => $data['improvements'][0]['text'],
            ];

        } catch (ConnectionException $e) {
            Log::error('DeepL Write API connection failed', ['error' => $e->getMessage()]);
            throw new TranslationFailedException('Failed to connect to translation service', 0, $e);
        }
    }
}
