/**
 * One-time-code inputs.
 *
 * Drives the boxes rendered by components/otp-input.blade.php: one box per digit,
 * with the joined value mirrored into the hidden input that carries the id. Code
 * around it reads and writes that one field and does not need to know about the boxes.
 */
function initializeOtpInputs(root) {
    const scope = root || document;

    scope.querySelectorAll('.otp-input-group').forEach(group => {
        if (group.dataset.otpReady === 'true') {
            return;
        }

        const hidden = document.getElementById(group.dataset.otpGroup);
        const boxes = Array.from(group.querySelectorAll('.otp-digit'));

        if (!hidden || boxes.length === 0) {
            return;
        }

        const sync = () => {
            hidden.value = boxes.map(box => box.value).join('');
        };

        const focusBox = (index) => {
            const box = boxes[Math.max(0, Math.min(boxes.length - 1, index))];
            if (box) {
                box.focus();
                box.select();
            }
        };

        /**
         * Spread a string of digits over the boxes, starting at one of them. Used for
         * pasting and for the browser filling in a code it read from a message.
         */
        const distribute = (digits, startIndex) => {
            const characters = digits.replace(/\D/g, '').split('');

            if (characters.length === 0) {
                return;
            }

            characters.forEach((character, offset) => {
                const target = boxes[startIndex + offset];
                if (target) {
                    target.value = character;
                }
            });

            sync();
            focusBox(startIndex + characters.length);
        };

        boxes.forEach((box, index) => {
            box.addEventListener('input', () => {
                // A box holds one digit. Anything else (a paste the browser routed here,
                // or a keyboard that sends the whole code) is spread over the boxes.
                const typed = box.value.replace(/\D/g, '');

                if (typed.length > 1) {
                    box.value = '';
                    distribute(typed, index);

                    return;
                }

                box.value = typed;
                sync();

                if (typed.length === 1) {
                    focusBox(index + 1);
                }
            });

            box.addEventListener('keydown', (event) => {
                if (event.key === 'Backspace' && box.value === '' && index > 0) {
                    // Deleting in an empty box steps back, which is what makes a row of
                    // boxes feel like one field.
                    event.preventDefault();
                    boxes[index - 1].value = '';
                    sync();
                    focusBox(index - 1);

                    return;
                }

                if (event.key === 'ArrowLeft' && index > 0) {
                    event.preventDefault();
                    focusBox(index - 1);

                    return;
                }

                if (event.key === 'ArrowRight' && index < boxes.length - 1) {
                    event.preventDefault();
                    focusBox(index + 1);
                }
            });

            box.addEventListener('paste', (event) => {
                event.preventDefault();

                const pasted = (event.clipboardData || window.clipboardData).getData('text');
                distribute(pasted, index);
            });

            box.addEventListener('focus', () => box.select());
        });

        group.dataset.otpReady = 'true';
    });
}

/**
 * Clear every box of a code input and its hidden field.
 */
function clearOtpInput(id) {
    const hidden = document.getElementById(id);
    const group = document.querySelector('[data-otp-group="' + id + '"]');

    if (hidden) {
        hidden.value = '';
    }

    if (group) {
        group.querySelectorAll('.otp-digit').forEach(box => {
            box.value = '';
        });
    }
}

/**
 * Put the cursor in the first box.
 */
function focusOtpInput(id) {
    const first = document.querySelector('[data-otp-group="' + id + '"] .otp-digit');

    if (first) {
        first.focus();
    }
}

document.addEventListener('DOMContentLoaded', () => initializeOtpInputs());
