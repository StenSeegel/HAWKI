<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AI\AiService;
use App\Services\Announcements\AnnouncementService;
use App\Services\FileConverter\FileConverterFactory;
use App\Services\Storage\AvatarStorageService;
use App\Services\System\SettingsService;
use App\Services\Translation\DeeplTranslationService;
use App\Services\Translation\TextImprovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TranslateController extends Controller
{
    public function __construct(
        private LanguageController $languageController,
        private AiService $aiService,
        private DeeplTranslationService $deeplService,
        private TextImprovementService $textImprovementService
    ) {
    }

    /**
     * Show the translate interface
     */
    public function index(
        AvatarStorageService $avatarStorage,
        AnnouncementService $announcementService
    ): View
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login');
        }

        $translation = $this->languageController->getTranslationWithLocalized();
        $settingsPanel = (new SettingsService())->render();
        $activeModule = 'translate';
        $activeOverlay = false;

        $avatarUrl = !empty($user->avatar_id)
            ? $avatarStorage->getUrl($user->avatar_id, 'profile_avatars')
            : null;
        $hawkiAvatarUrl = $avatarStorage->getUrl(User::find(1)->avatar_id, 'profile_avatars');

        $userData = [
            'avatar_url' => $avatarUrl,
            'hawki_avatar_url' => $hawkiAvatarUrl,
            'convs' => [],
            'convs_total' => 0,
            'convs_has_more' => false,
            'rooms' => [],
            'hawki_username' => User::find(1)->username,
        ];

        try {
            $models = $this->aiService->getAvailableModels()->toArray();
        } catch (\Exception $e) {
            $models = ['models' => []];
        }

        $webSearchAvailable = false;
        foreach ($models['models'] as $model) {
            if (!empty($model['tools']['web_search'])) {
                $webSearchAvailable = true;
                break;
            }
        }

        $announcements = $announcementService->getUserAnnouncements();
        $converterActive = FileConverterFactory::converterActive();

        return view('translate.translation', [
            'translation' => $translation,
            'activeModule' => $activeModule,
            'activeOverlay' => $activeOverlay,
            'settingsPanel' => $settingsPanel,
            'userData' => $userData,
            'user' => $user,
            'models' => $models,
            'webSearchAvailable' => $webSearchAvailable,
            'announcements' => $announcements,
            'converterActive' => $converterActive,
        ]);
    }

    /**
     * Translate text using DeepL
     */
    public function translate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'text' => 'required|string|max:50000',
                'source_lang' => 'nullable|string|max:10',
                'target_lang' => 'required|string|max:10',
            ]);

            $result = $this->deeplService->translate(
                $validated['text'],
                $validated['source_lang'] ?? null,
                $validated['target_lang']
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\App\Services\Translation\Exceptions\TranslationFailedException $e) {
            Log::error('Translation failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        } catch (\App\Services\Translation\Exceptions\InvalidLanguageException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\App\Services\Translation\Exceptions\QuotaExceededException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Übersetzungskontingent überschritten. Bitte später erneut versuchen.',
            ], 429);
        } catch (\Exception $e) {
            Log::error('Unexpected translation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten.',
            ], 500);
        }
    }

    /**
     * Improve text using AI
     */
    public function write(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'text' => 'required|string|max:50000',
                'target_lang' => 'nullable|string|max:10',
                'model' => 'nullable|string|max:100',
                'style' => 'nullable|string|max:50',
            ]);

            $result = $this->textImprovementService->improveText(
                $validated['text'],
                $validated['target_lang'] ?? null,
                $validated['model'] ?? null,
                $validated['style'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\App\Services\Translation\Exceptions\TranslationFailedException $e) {
            Log::error('Text improvement failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected text improvement error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Ein unerwarteter Fehler ist aufgetreten.',
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
            Log::error('Failed to get AI models', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'models' => [],
                ],
            ]);
        }
    }
}
