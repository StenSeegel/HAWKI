{{-- Shared code step: used by the guest request panel on /login and by the
     verify-email pre-slide on /register. $prefix keeps the element ids apart. --}}
<p class="slide-subtitle" id="{{ $prefix }}-hint">
    {{ $translation['verify_email_text'] ?? 'We sent a 6-digit code to' }}
    <strong id="{{ $prefix }}-address">{{ $maskedEmail ?? '' }}</strong>
</p>

<label for="{{ $prefix }}-code">{{ $translation['verify_email_code'] ?? 'Confirmation code' }}</label>
<input type="text"
       id="{{ $prefix }}-code"
       name="otp"
       inputmode="numeric"
       autocomplete="one-time-code"
       pattern="[0-9]*"
       maxlength="6">

<div id="{{ $prefix }}-message"
     class="error-message"
     data-otp-invalid="{{ $translation['otp_invalid'] ?? 'The code is not correct. Attempts left: :count' }}"
     data-otp-invalid-last="{{ $translation['otp_invalid_last'] ?? 'The code is not correct and is no longer valid. Please request a new one.' }}"
     data-otp-expired="{{ $translation['otp_expired'] ?? 'The code has expired. Please request a new one.' }}"
     data-otp-missing="{{ $translation['otp_missing'] ?? 'There is no valid code any more. Please request a new one.' }}"
     data-token-invalid="{{ $translation['token_invalid'] ?? 'This confirmation step is no longer valid. Please start again.' }}"
     data-email-taken="{{ $translation['email_taken'] ?? 'This email address is already registered.' }}"
     data-domain-not-allowed="{{ $translation['domain_not_allowed'] ?? 'Registration is not possible with this email domain.' }}"
     data-verified="{{ $translation['verify_email_verified'] ?? 'Your email address has been confirmed. You can now log in.' }}"
     data-resent="{{ $translation['verify_email_resent'] ?? 'We sent a new code to your email address.' }}"
     data-code-required="{{ $translation['verify_email_code_required'] ?? 'Please enter the 6-digit code.' }}"
     data-network-error="{{ $translation['network_error'] ?? 'A network error occurred. Please check your connection and try again.' }}">
</div>

<div class="nav-buttons">
    <button class="btn-lg-fill" type="button" id="{{ $prefix }}-submit">
        {{ $translation['verify_email_submit'] ?? 'Confirm' }}
    </button>
</div>

<div class="guest-access-request">
    <button class="btn-link" type="button" id="{{ $prefix }}-resend">
        {{ $translation['verify_email_resend'] ?? 'Send the code again' }}
    </button>
</div>

<div class="guest-access-request">
    <button class="btn-link" type="button" id="{{ $prefix }}-change-toggle">
        {{ $translation['verify_email_change'] ?? 'Change address' }}
    </button>
</div>

<div id="{{ $prefix }}-change" style="display: none;">
    <label for="{{ $prefix }}-email">{{ $translation['verify_email_new_address'] ?? 'New email address' }}</label>
    <input type="email" id="{{ $prefix }}-email" autocomplete="email">
    <button class="btn-lg-fill top-gap-1" type="button" id="{{ $prefix }}-change-submit">
        {{ $translation['verify_email_change_submit'] ?? 'Save and send a new code' }}
    </button>
</div>
