export function passwordPolicyErrors(password, confirmation, min, maxBytes) {
    let passwordError = '';
    if ([...password].length < min) passwordError = `Gunakan minimal ${min} karakter.`;
    else if (new TextEncoder().encode(password).length > maxBytes) passwordError = `Password maksimal ${maxBytes} byte.`;
    else if (!/\p{L}/u.test(password) || /[\0\r\n]/.test(password)) passwordError = 'Gunakan huruf dan format password yang valid.';

    return {
        passwordError,
        confirmationError: confirmation === password ? '' : 'Konfirmasi password harus sama.',
    };
}

export function syncPasswordPolicy(form, enabled = true) {
    const { password, password_confirmation: confirmation } = form.elements;
    const errors = enabled ? passwordPolicyErrors(password.value, confirmation.value,
        Number(form.dataset.passwordMin), Number(form.dataset.passwordMaxBytes)) : {};
    password.setCustomValidity(errors.passwordError || '');
    confirmation.setCustomValidity(errors.confirmationError || '');
}

export function bindPasswordPolicy(form, enabled) {
    for (const field of [form.elements.password, form.elements.password_confirmation]) {
        field.addEventListener('input', () => syncPasswordPolicy(form, enabled?.()));
    }
    form.addEventListener('submit', event => {
        syncPasswordPolicy(form, enabled?.());
        if (!form.reportValidity()) event.preventDefault();
    });
}
