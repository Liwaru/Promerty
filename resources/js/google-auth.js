/** Only return to known local pages. Never carry tokens, callback queries or external URLs. */
export function googleReturnPath(path) {
    return typeof path === 'string' && /^(?:\/|\/search|\/posts\/[1-9][0-9]*|\/agents\/[1-9][0-9]*)$/.test(path) ? path : '/';
}

export function bindGoogleLogin(button, pathname, navigate, lifecycle = globalThis.window) {
    if (!button || button.disabled) return;
    lifecycle?.addEventListener('pageshow', () => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    });
    button.addEventListener('click', () => {
        if (button.disabled || button.dataset.googleUrl !== '/auth/google/redirect') return;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        const target = typeof pathname === 'function' ? pathname() : pathname;
        navigate('/auth/google/redirect?return_to=' + encodeURIComponent(googleReturnPath(target)));
    });
}
