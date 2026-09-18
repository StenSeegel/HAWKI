{{--
    Reusable one-time-code input.

    Renders one box per digit and keeps the joined value in a hidden input, so the
    surrounding code reads a single field exactly as it would read a plain input.
    Behaviour lives in public/js/otpInputs.js (initializeOtpInputs).

    Props:
    - $id: id of the hidden input that carries the value
    - $length: number of digits (defaults to 6)
    - $name: name attribute of the hidden input (defaults to 'otp')
--}}

@php($otpLength = (int) ($length ?? 6))

<div class="otp-input-group" data-otp-group="{{ $id }}">
    @for ($digit = 1; $digit <= $otpLength; $digit++)
        <input
            class="otp-digit"
            id="{{ $id }}-{{ $digit }}"
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="1"
            autocomplete="{{ $digit === 1 ? 'one-time-code' : 'off' }}"
            autocorrect="off"
            autocapitalize="off"
            spellcheck="false"
            aria-label="{{ __('Digit') }} {{ $digit }}"
        />
    @endfor
</div>

<input type="hidden" id="{{ $id }}" name="{{ $name ?? 'otp' }}" value="">
