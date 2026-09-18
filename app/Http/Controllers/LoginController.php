<?php

namespace App\Http\Controllers;

use App\Services\Auth\Contract\AuthServiceInterface;
use App\Services\Auth\Contract\AuthServiceWithCredentialsInterface;
use App\Services\Auth\EmailDomainRoleResolver;
use App\Services\System\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Orchid\Platform\Models\Role;

class LoginController extends Controller
{
    protected $languageController;

    // Inject LanguageController instance
    public function __construct(LanguageController $languageController)
    {
        $this->languageController = $languageController;
    }

    /// Redirect to Login Page
    public function index(AuthServiceInterface $authService, Request $request)
    {
        Session::put('registration_access', false);

        if (Auth::check()) {
            return redirect('/handshake');
        }

        // Call getTranslation method from LanguageController
        $translation = $this->languageController->getTranslation();
        $settingsPanel = (new SettingsService())->render();

        $showLoginForm = $authService instanceof AuthServiceWithCredentialsInterface;
        $localUsersActive = config('auth.local_authentication', false);
        $localSelfserviceActive = config('auth.local_selfservice', false);
        $availableRoles = $localSelfserviceActive ? Role::where('selfassign', true)->get() : [];

        // While domain filtering is active the role comes from the address, so the user
        // group dropdown is not rendered at all.
        $domainFilteringActive = $localSelfserviceActive && app(EmailDomainRoleResolver::class)->isActive();

        // Read authentication forms
        $authForms = View::make('partials.login.authForms', compact(
            'translation',
            'showLoginForm',
            'localUsersActive',
            'localSelfserviceActive',
            'availableRoles',
            'domainFilteringActive'
        ))->render();


        $activeOverlay = false;
        if (Session::get('last-route') && Session::get('last-route') != 'login') {
            $activeOverlay = true;
        }
        Session::put('last-route', 'login');

        // Pass translation, authenticationMethod, and authForms to the view
        return view('layouts.login', compact(
            'translation',
            'authForms',
            'settingsPanel',
            'activeOverlay'
        ));
    }
}
