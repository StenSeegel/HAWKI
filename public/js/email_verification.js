/**
 * Shared e-mail verification code step.
 *
 * The same markup (partials/login/email-verification-step.blade.php) is used by the guest
 * request panel on the login page and by the verify-email pre-slide of the registration page.
 * The only difference is the endpoints and whether a step token or the session identifies
 * the account, which is what the options below describe.
 */
function createEmailVerificationStep(options) {
    const prefix = options.prefix;
    const endpoints = options.endpoints;
    const usesToken = options.usesToken !== false;

    const element = (suffix) => document.getElementById(prefix + '-' + suffix);

    const messageBox = element('message');
    const codeInput = element('code');
    const addressLabel = element('address');
    const changeBlock = element('change');
    const emailInput = element('email');

    let token = null;

    const text = (name, fallback) => {
        const value = messageBox ? messageBox.getAttribute('data-' + name) : null;

        return value || fallback || '';
    };

    const showMessage = (message, isError) => {
        if (!messageBox) {
            return;
        }

        messageBox.textContent = message;
        messageBox.style.display = message ? 'block' : 'none';
        messageBox.style.color = isError ? '#dc3545' : '#28a745';
    };

    const csrfToken = () => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            return meta.getAttribute('content');
        }

        const input = document.querySelector('input[name="_token"]');

        return input ? input.value : '';
    };

    const post = (url, payload) => {
        const body = Object.assign({}, payload);
        if (usesToken) {
            body.token = token;
        }

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(body),
        }).then(response => response.json().then(data => ({status: response.status, data})));
    };

    const reportFailure = (data) => {
        const reason = data && data.reason;

        if (reason === 'otp_invalid') {
            const attemptsLeft = data.attempts_left || 0;
            showMessage(
                attemptsLeft > 0
                    ? text('otp-invalid').replace(':count', attemptsLeft)
                    : text('otp-invalid-last'),
                true
            );

            return;
        }

        if (reason === 'otp_expired' || reason === 'otp_missing' || reason === 'token_invalid'
            || reason === 'email_taken' || reason === 'domain_not_allowed') {
            showMessage(text(reason.replace(/_/g, '-')), true);

            return;
        }

        showMessage((data && data.message) || text('network-error'), true);
    };

    const verify = () => {
        const code = (codeInput ? codeInput.value : '').trim();

        if (!/^\d{6}$/.test(code)) {
            showMessage(text('code-required'), true);

            return;
        }

        post(endpoints.verify, {otp: code})
            .then(({data}) => {
                if (data && data.success) {
                    showMessage(text('verified'), false);

                    if (typeof options.onVerified === 'function') {
                        options.onVerified(data);
                    }

                    return;
                }

                reportFailure(data);
            })
            .catch(() => showMessage(text('network-error'), true));
    };

    const resend = () => {
        post(endpoints.resend, {})
            .then(({data}) => {
                if (data && data.success) {
                    if (usesToken && data.token) {
                        token = data.token;
                    }
                    if (codeInput) {
                        codeInput.value = '';
                    }
                    showMessage(text('resent'), false);

                    return;
                }

                reportFailure(data);
            })
            .catch(() => showMessage(text('network-error'), true));
    };

    const changeAddress = () => {
        const email = (emailInput ? emailInput.value : '').trim();

        post(endpoints.change, {email})
            .then(({data}) => {
                if (data && data.success) {
                    if (usesToken && data.token) {
                        token = data.token;
                    }
                    if (addressLabel && data.email_masked) {
                        addressLabel.textContent = data.email_masked;
                    }
                    if (changeBlock) {
                        changeBlock.style.display = 'none';
                    }
                    if (codeInput) {
                        codeInput.value = '';
                    }
                    showMessage(text('resent'), false);

                    return;
                }

                reportFailure(data);
            })
            .catch(() => showMessage(text('network-error'), true));
    };

    const bind = (suffix, handler) => {
        const button = element(suffix);
        if (button) {
            button.addEventListener('click', handler);
        }
    };

    bind('submit', verify);
    bind('resend', resend);
    bind('change-submit', changeAddress);
    bind('change-toggle', () => {
        if (!changeBlock) {
            return;
        }

        changeBlock.style.display = changeBlock.style.display === 'none' ? 'block' : 'none';
    });

    if (codeInput) {
        codeInput.addEventListener('keypress', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                verify();
            }
        });
    }

    return {
        /**
         * Show the step for an account, optionally with the token the submit response returned.
         */
        start(newToken, maskedEmail) {
            token = newToken || null;

            if (addressLabel && maskedEmail) {
                addressLabel.textContent = maskedEmail;
            }
            if (codeInput) {
                codeInput.value = '';
                codeInput.focus();
            }
            if (changeBlock) {
                changeBlock.style.display = 'none';
            }

            showMessage('', false);
        },

        reset() {
            token = null;

            if (codeInput) {
                codeInput.value = '';
            }
            if (emailInput) {
                emailInput.value = '';
            }
            if (changeBlock) {
                changeBlock.style.display = 'none';
            }

            showMessage('', false);
        },

        resend,
    };
}
