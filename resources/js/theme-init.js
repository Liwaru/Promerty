/* Runs synchronously in <head> before CSS, without network or storage assumptions. */
(() => {
    const key = 'promerty.theme';
    const valid = value => ['light', 'dark', 'system'].includes(value);
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    let preference = 'dark';
    try { const stored = localStorage.getItem(key); if (valid(stored)) preference = stored; } catch {}
    const apply = () => {
        const theme = preference === 'system' ? (media.matches ? 'dark' : 'light') : preference;
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.themePreference = preference;
        document.documentElement.style.colorScheme = theme;
        document.querySelectorAll('[data-theme-select]').forEach(select => { select.value = preference; });
        window.dispatchEvent(new CustomEvent('promerty:theme', { detail: { preference, theme } }));
    };
    window.PromertyTheme = {
        get preference() { return preference; },
        set(value) {
            if (!valid(value)) return;
            preference = value;
            try { localStorage.setItem(key, value); } catch {}
            apply();
        },
    };
    media.addEventListener('change', () => { if (preference === 'system') apply(); });
    window.addEventListener('storage', event => {
        if (event.key !== key && event.key !== null) return;
        preference = valid(event.newValue) ? event.newValue : 'dark';
        apply();
    });
    document.addEventListener('DOMContentLoaded', apply);
    document.addEventListener('change', event => {
        if (event.target.matches('[data-theme-select]')) window.PromertyTheme.set(event.target.value);
    });
    apply();
})();
