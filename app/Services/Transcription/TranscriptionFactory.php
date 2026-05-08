<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use App\Services\Transcription\Contracts\TranscriptionProviderInterface;
use App\Services\Transcription\Providers\CustomSpeachesProvider;
use App\Services\Transcription\Providers\OpenAiTranscriptionProvider;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Factory for creating transcription provider instances.
 */
class TranscriptionFactory
{
    /**
     * Creates the appropriate transcription provider based on global settings.
     *
     * @throws Exception
     */
    public static function create(): TranscriptionProviderInterface
    {
        $settingsService = app(TranscriptionSettingsService::class);
        $providerId = $settingsService->get('provider', 'custom_speaches');

        if ($providerId === 'custom_speaches') {
            Log::info('TranscriptionFactory: Erstelle CustomSpeachesProvider');

            return new CustomSpeachesProvider($settingsService);
        }

        Log::info('TranscriptionFactory: Erstelle OpenAiTranscriptionProvider');

        return new OpenAiTranscriptionProvider($settingsService);
    }
}
