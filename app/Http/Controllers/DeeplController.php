<?php

namespace App\Http\Controllers;

use App\Services\Translation\Exceptions\InvalidLanguageException;
use App\Services\Translation\Exceptions\QuotaExceededException;
use App\Services\Translation\Exceptions\TranslationFailedException;
use App\Services\Translation\TextImprovementService;
use App\Services\Translation\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeeplController extends Controller
{
    public function __construct(
        private TranslationService $translationService,
        private TextImprovementService $textImprovementService
    ) {}

    /**
     * Translate text using DeepL API
     */
    public function translate(Request $request): JsonResponse
    {
        // Validate incoming request
        $validated = $request->validate([
            'text' => 'required|string|max:50000',
            'source_lang' => 'nullable|string|max:10',
            'target_lang' => 'required|string|max:10',
            'glossary_id' => 'nullable|integer|exists:translate_glossaries,id',
        ]);

        try {
            // Call translation service
            $result = $this->translationService->translate(
                text: $validated['text'],
                sourceLang: $validated['source_lang'] ?? null,
                targetLang: $validated['target_lang'],
                glossaryId: $validated['glossary_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'text' => $result['text'],
                    'detected_source_language' => $result['detected_source_language'],
                ],
            ]);

        } catch (InvalidLanguageException $e) {
            Log::warning('DeepL translation failed: Invalid language', [
                'error' => $e->getMessage(),
                'source_lang' => $validated['source_lang'] ?? null,
                'target_lang' => $validated['target_lang'],
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ungültige Sprache ausgewählt. Bitte wählen Sie eine unterstützte Sprache.',
                'message' => $e->getMessage(),
            ], 400);

        } catch (QuotaExceededException $e) {
            Log::error('DeepL translation failed: Quota exceeded', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Übersetzungslimit erreicht. Bitte versuchen Sie es später erneut.',
                'message' => $e->getMessage(),
            ], 429);

        } catch (TranslationFailedException $e) {
            Log::error('DeepL translation failed', [
                'error' => $e->getMessage(),
                'source_lang' => $validated['source_lang'] ?? null,
                'target_lang' => $validated['target_lang'],
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Übersetzung fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'message' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            Log::error('Unexpected error during translation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten.',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get available AI models for text improvement
     */
    public function getModels(): JsonResponse
    {
        try {
            $models = $this->textImprovementService->getAvailableModels();

            return response()->json([
                'success' => true,
                'data' => [
                    'models' => $models,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch available models', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Modelle konnten nicht geladen werden.',
            ], 500);
        }
    }

    /**
     * Improve text using AI
     */
    public function write(Request $request): JsonResponse
    {
        // Validate incoming request
        $validated = $request->validate([
            'text' => 'required|string|max:50000',
            'target_lang' => 'nullable|string|max:10',
            'model' => 'nullable|string|max:100',
            'style' => 'nullable|string|max:50',
        ]);

        try {
            // Check if DeepL Write is selected
            $modelId = $validated['model'] ?? null;

            if ($modelId === 'deepl-write') {
                // Use DeepL Write API
                $result = $this->translationService->write(
                    text: $validated['text'],
                    targetLang: $validated['target_lang'] ?? null
                );
            } else {
                // Use AI models (GWDG, Ollama, OpenAI, etc.)
                $result = $this->textImprovementService->improveText(
                    text: $validated['text'],
                    targetLang: $validated['target_lang'] ?? null,
                    modelId: $modelId,
                    style: $validated['style'] ?? null
                );
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'text' => $result['text'],
                ],
            ]);

        } catch (InvalidLanguageException $e) {
            Log::warning('Text improvement failed: Invalid language', [
                'error' => $e->getMessage(),
                'target_lang' => $validated['target_lang'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ungültige Sprache ausgewählt. Bitte wählen Sie eine unterstützte Sprache.',
                'message' => $e->getMessage(),
            ], 400);

        } catch (QuotaExceededException $e) {
            Log::error('Text improvement failed: Quota exceeded', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Verbesserungslimit erreicht. Bitte versuchen Sie es später erneut.',
                'message' => $e->getMessage(),
            ], 429);

        } catch (TranslationFailedException $e) {
            Log::error('Text improvement failed', [
                'error' => $e->getMessage(),
                'target_lang' => $validated['target_lang'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Textverbesserung fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'message' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            Log::error('Unexpected error during text improvement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten.',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
