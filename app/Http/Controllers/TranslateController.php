<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AI\AiService;
use App\Services\Announcements\AnnouncementService;
use App\Services\FileConverter\FileConverterFactory;
use App\Services\Storage\AvatarStorageService;
use App\Services\System\SettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TranslateController extends Controller
{
    public function __construct(
        private LanguageController $languageController,
        private AiService $aiService
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

        return view('translate.index', [
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
}
