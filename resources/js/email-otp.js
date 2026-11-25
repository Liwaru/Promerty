const form = document.querySelector('[data-email-otp]');
const resend = document.querySelector('[data-email-otp-resend]');

if (form && resend) {
    const digits = [...form.querySelectorAll('input[inputmode="numeric"]')];
    const verifyButton = form.querySelector('button[type="submit"]');
    const resendButton = resend.querySelector('button[type="submit"]');
    const error = form.querySelector('[role="alert"]');
    const status = resend.querySelector('[data-otp-status]');
    let busy = false;
    let counter;

    function countdown(seconds) {
        clearInterval(counter);
        const deadline = Date.now() + seconds * 1000;
        const update = () => {
            const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            resendButton.disabled = left > 0;
            resendButton.textContent = left ? `Kirim ulang (${left} dtk)` : 'Kirim ulang';
            if (!left) clearInterval(counter);
        };
        counter = setInterval(update, 1000);
        update();
    }

    function spread(value, start) {
        const numbers = value.replace(/[^0-9]/g, '');
        const offset = numbers.length === 6 ? 0 : start;
        [...numbers.slice(0, digits.length - offset)].forEach((number, index) => {
            digits[offset + index].value = number;
        });
        digits[Math.min(offset + numbers.length, digits.length - 1)].focus();
        if (digits.every(input => input.value)) form.requestSubmit();
    }

    async function post(target, body) {
        const response = await fetch(target.action, {method: 'POST', body,
            headers: {'Accept': 'application/json'}});
        return {response, result: await response.json()};
    }

    digits.forEach((input, index) => {
        input.addEventListener('input', () => {
            if (!busy) {
                const value = input.value;
                input.value = '';
                spread(value, index);
            }
        });
        input.addEventListener('paste', event => {
            const value = event.clipboardData?.getData('text') ?? '';
            if (busy || !/[0-9]/.test(value)) return;
            event.preventDefault();
            spread(value, index);
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Backspace' && !input.value && index) digits[index - 1].focus();
        });
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        verifyButton.disabled = true;
        const label = verifyButton.textContent;
        verifyButton.textContent = 'Memeriksa kode…';
        form.setAttribute('aria-busy', 'true');
        error.textContent = '';
        digits.forEach(input => input.removeAttribute('aria-invalid'));
        try {
            const body = new FormData(form);
            body.delete('digits[]');
            body.set('email_otp', digits.map(input => input.value).join(''));
            const {response, result} = await post(form, body);
            if (response.ok && result.redirect_to) {
                location.assign(result.redirect_to);
                return;
            }
            error.textContent = result.errors?.email_otp?.[0] ?? 'Kode belum dapat diverifikasi. Silakan coba lagi.';
            digits.forEach(input => { input.value = ''; input.setAttribute('aria-invalid', 'true'); });
            digits[0].focus();
        } catch {
            error.textContent = 'Koneksi terganggu. Pilih Verifikasi untuk mencoba lagi.';
        } finally {
            busy = false;
            verifyButton.disabled = false;
            verifyButton.textContent = label;
            form.removeAttribute('aria-busy');
        }
    });

    resend.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || resendButton.disabled) return;
        busy = true;
        resendButton.disabled = true;
        try {
            const {response, result} = await post(resend, new FormData(resend));
            status.textContent = result.message ?? 'Kode belum dapat dikirim.';
            if (response.ok) {
                error.textContent = '';
                digits.forEach(input => { input.value = ''; input.removeAttribute('aria-invalid'); });
                digits[0].focus();
            }
            countdown(Number(result.retry) || 0);
        } catch {
            status.textContent = 'Koneksi terganggu. Silakan coba lagi.';
            resendButton.disabled = false;
        } finally {
            busy = false;
        }
    });
    countdown(Number(resend.dataset.retry) || 0);
}
