<?php

namespace App\Services\Auth\Http;

use App\Services\Auth\EmailDomainRoleResolver;
use App\Http\Controllers\LanguageController;
use App\Services\Auth\Value\Local\GuestUserRequestData;
use Illuminate\Container\Attributes\Config;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Orchid\Platform\Models\Role;

class GuestUserRequest extends FormRequest
{
    public function rules(): array
    {
        $availableRoles = Role::pluck('slug')->toArray();
        $rolesList = implode(',', $availableRoles);

        // With domain filtering active the user does not pick a role at all,
        // it comes from the rule matching the e-mail address.
        $domainFilteringActive = app(EmailDomainRoleResolver::class)->isActive();

        return [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:255',
                'regex:/^[a-zA-Z0-9_-]+$/',
                'unique:users,username',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).*$/',
            ],
            'password_confirmation' => 'required|string|same:password',
            // Only a verified address blocks a new registration. An unverified duplicate is
            // answered with an offer to resend the code, so a typo cannot lock out the real owner.
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->whereNotNull('email_verified_at'),
            ],
            'employeetype' => $domainFilteringActive
                ? ['nullable', 'string']
                : ['required', 'string', "in:{$rolesList}"],
        ];
    }

    public function messages(): array
    {
        // These land under the fields of a form that is otherwise in the visitor's
        // language, so they are looked up the same way the form labels are, with the
        // English wording as the fallback.
        $translation = app(LanguageController::class)->getTranslation();

        $text = static fn (string $key, string $fallback): string => $translation[$key] ?? $fallback;

        return [
            'username.required' => $text('guest_req_username_required', 'Username is required'),
            'username.min' => $text('guest_req_username_min', 'Username must be at least 3 characters long'),
            'username.regex' => $text('guest_req_username_regex', 'Username can only contain letters, numbers, underscores, and hyphens'),
            'username.unique' => $text('guest_req_username_unique', 'This username is already taken'),
            'password.required' => $text('guest_req_password_required', 'Password is required'),
            'password.min' => $text('guest_req_password_min', 'Password must be at least 8 characters long'),
            'password.regex' => $text('guest_req_password_regex', 'Password must contain at least one uppercase letter, one lowercase letter, and one number'),
            'password_confirmation.required' => $text('guest_req_password_confirmation_required', 'Password confirmation is required'),
            'password_confirmation.same' => $text('guest_req_password_confirmation_same', 'Passwords do not match'),
            'email.required' => $text('guest_req_email_required', 'Email is required'),
            'email.email' => $text('guest_req_email_invalid', 'Please enter a valid email address'),
            'email.unique' => $text('guest_req_email_unique', 'This email address is already registered'),
            'employeetype.required' => $text('guest_req_employeetype_required', 'User group is required'),
            'employeetype.in' => $text('guest_req_employeetype_invalid', 'Please select a valid user group'),
        ];
    }

    public function authorize(
        #[Config('auth.local_authentication')]
        bool $localAuthenticationEnabled,
        #[Config('auth.local_selfservice')]
        bool $localSelfServiceEnabled
    ): bool
    {
        return $localAuthenticationEnabled && $localSelfServiceEnabled;
    }

    /**
     * @param string|null $employeeTypeOverride The role slug resolved from a domain rule, if any
     */
    public function getData(?string $employeeTypeOverride = null): GuestUserRequestData
    {
        return new GuestUserRequestData(
            username: $this->validated('username'),
            password: $this->validated('password'),
            passwordConfirmation: $this->validated('password_confirmation'),
            email: $this->validated('email'),
            employeeType: $employeeTypeOverride ?? (string) $this->validated('employeetype'),
        );
    }
}
