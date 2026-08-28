<?php

namespace App\Http\Controllers;

use App\Models\TranslateSetting;
use App\Models\User;
use App\Services\AI\AiService;
use App\Services\Announcements\AnnouncementService;
use App\Services\FileConverter\FileConverterFactory;
use App\Services\Storage\AvatarStorageService;
use App\Services\System\SettingsService;
use App\Services\Translation\TranslationFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class TranslateController extends Controller
{
    public function __construct(
        private LanguageController $languageController,
        private AiService $aiService
    ) {}

    /**
     * Show the translate interface
     */
    public function index(
        AvatarStorageService $avatarStorage,
        AnnouncementService $announcementService
    ): View|\Illuminate\Http\RedirectResponse {
        $user = Auth::user();
        if (! $user) {
            return redirect('/login');
        }

        $translation = $this->languageController->getTranslationWithLocalized();
        $settingsPanel = (new SettingsService)->render();
        $activeModule = 'text';
        $activeOverlay = false;

        // Extract short locale code (e.g., 'de' from 'de_DE') for language defaults
        $sessionLang = Session::get('language');
        $userLocale = 'en'; // fallback
        if (is_array($sessionLang) && isset($sessionLang['id'])) {
            $userLocale = strtolower(substr($sessionLang['id'], 0, 2));
        }

        $avatarUrl = ! empty($user->avatar_id)
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

        $settings = TranslateSetting::all()->keyBy('key');
        $allowedModels = json_decode($settings->get('allowed_models')?->value ?? '[]', true);
        
        // Check if deepL is active
        $deepLKey = $settings->get('deepl_api_key')?->value;
        $isDeepLActive = !empty($deepLKey);

        $userRoles = $user->getRoles()->pluck('slug')->toArray();

        // Check if user role is allowed for DeepL
        $userHasAllowedRole = $this->hasAllowedRole($settings->get('deepl_allowed_roles')?->value, $userRoles);

        // Check if user role is allowed for the create mode
        $createModeAllowed = $this->hasAllowedRole($settings->get('create_mode_allowed_roles')?->value, $userRoles);

        $isDeepLActiveForUser = $isDeepLActive && $userHasAllowedRole;

        try {
            $availableModels = $this->aiService->getAvailableModels()->toArray();
            $filteredModels = [];
            foreach ($availableModels['models'] as $model) {
                if (!($model['visible'] ?? true)) {
                    continue;
                }

                $id = $model['id'] ?? null;
                $systemId = $model['system_id'] ?? null;
                
                $isAllowed = empty($allowedModels);
                if (!$isAllowed) {
                    if ($id && in_array($id, $allowedModels)) {
                        $isAllowed = true;
                    } elseif ($systemId && in_array($systemId, $allowedModels)) {
                        $isAllowed = true;
                    }
                }

                if ($isAllowed) {
                    $filteredModels[] = $model;
                }
            }

            // Add DeepL API Pro if active for user
            if ($isDeepLActiveForUser) {
                $hasDeepL = collect($filteredModels)->contains(fn($m) => ($m['id'] ?? '') === 'deepl');
                if (!$hasDeepL) {
                    $filteredModels[] = [
                        'id' => 'deepl',
                        'label' => 'DeepL API Pro',
                        'status' => 'online',
                        'provider_name' => 'DeepL',
                        'provider_display_order' => -1,
                        'display_order' => 0,
                        'visible' => true,
                        'tools' => [
                            'vision' => false,
                            'file_upload' => true,
                            'web_search' => false,
                            'reasoning' => false
                        ]
                    ];
                }
            }
            $models = ['models' => $filteredModels];
        } catch (\Exception $e) {
            $models = ['models' => []];
        }

        $webSearchAvailable = false;
        $reasoningAvailable = false;
        foreach ($models['models'] as $model) {
            if (! empty($model['tools']['web_search'])) {
                $webSearchAvailable = true;
            }
            if (! empty($model['tools']['reasoning'])) {
                $reasoningAvailable = true;
            }

            if ($webSearchAvailable && $reasoningAvailable) {
                break;
            }
        }

        $announcements = $announcementService->getUserAnnouncements();
        $converterActive = FileConverterFactory::converterActive();

        $betaSettings = TranslateSetting::whereIn('key', ['show_beta_message', 'beta_message_text'])
            ->get()
            ->keyBy('key');
        $showBetaMessage = (bool) ($betaSettings->get('show_beta_message')?->typed_value ?? false);
        $betaMessageText = $betaSettings->get('beta_message_text')?->value ?? '';
        
        $enableLiveMode = (bool) ($settings->get('enable_live_mode')?->typed_value ?? true);

        return view('translate.translation', [
            'translation' => $translation,
            'activeModule' => $activeModule,
            'activeOverlay' => $activeOverlay,
            'settingsPanel' => $settingsPanel,
            'userData' => $userData,
            'user' => $user,
            'models' => $models,
            'webSearchAvailable' => $webSearchAvailable,
            'reasoningAvailable' => $reasoningAvailable,
            'announcements' => $announcements,
            'converterActive' => $converterActive,
            'userLocale' => $userLocale,
            'showBetaMessage' => $showBetaMessage,
            'betaMessageText' => $betaMessageText,
            'enableLiveMode' => $enableLiveMode,
            'deeplApiKeyPresent' => TranslationFactory::isActive('deepl') && $userHasAllowedRole,
            'createModeAllowed' => $createModeAllowed,
            'defaults' => [
                'translate_model' => $settings->get('translate_model')?->value,
                'rephrase_model' => $settings->get('rephrase_model')?->value,
            ],
        ]);
    }

    /**
     * Check whether one of the user's roles is on a setting's allowlist.
     * An empty or unset allowlist grants access to everyone.
     *
     * @param  array<int, string>  $userRoles
     */
    private function hasAllowedRole(?string $allowedRolesJson, array $userRoles): bool
    {
        $allowedRoles = json_decode($allowedRolesJson ?? '[]', true) ?? [];

        if (empty($allowedRoles)) {
            return true;
        }

        return ! empty(array_intersect($allowedRoles, $userRoles));
    }
}
