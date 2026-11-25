import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../../resources/js/theme-init.js', import.meta.url), 'utf8');
function boot({ saved = null, dark = false, blocked = false } = {}) {
    const events = {}, documentEvents = {}, storage = new Map([['promerty.theme', saved]]);
    const select = {}, root = { dataset: {}, style: {} };
    const media = { matches: dark, addEventListener: (_, listener) => { media.change = listener; } };
    const window = { matchMedia: () => media, addEventListener: (name, callback) => { events[name] = callback; }, dispatchEvent() {} };
    const document = { documentElement: root, querySelectorAll: () => [select], addEventListener: (name, callback) => { documentEvents[name] = callback; } };
    vm.runInNewContext(source, { window, document,
        localStorage: { getItem: key => { if (blocked) throw new Error('Blocked'); return storage.get(key); }, setItem: (key, value) => { if (blocked) throw new Error('Blocked'); storage.set(key, value); } },
        CustomEvent: class { constructor(type, options) { Object.assign(this, { type, ...options }); } },
    });
    return { root, window, media, events, documentEvents, storage, select };
}
test('saved preference applies synchronously before CSS and overrides OS', () => {
    const app = boot({ saved: 'light', dark: true });
    assert.equal(app.root.dataset.theme, 'light');
    assert.equal(app.root.style.colorScheme, 'light');
    app.media.change();
    assert.equal(app.root.dataset.theme, 'light');
});
test('system preference follows OS changes', () => {
    const app = boot({ saved: 'system', dark: true });
    assert.equal(app.root.dataset.theme, 'dark');
    app.media.matches = false; app.media.change();
    assert.equal(app.root.dataset.theme, 'light');
});
test('unavailable localStorage does not break switching', () => {
    const app = boot({ blocked: true });
    app.window.PromertyTheme.set('dark');
    assert.equal(app.root.dataset.theme, 'dark');
});
test('invalid values are ignored, and valid choices persist', () => {
    const app = boot({ saved: 'unexpected' });
    assert.equal(app.root.dataset.themePreference, 'dark');
    app.window.PromertyTheme.set('dark');
    app.window.PromertyTheme.set('<invalid>');
    assert.equal(app.storage.get('promerty.theme'), 'dark');
    assert.equal(app.select.value, 'dark');
});
test('cross-tab changes and storage clearing synchronize the theme', () => {
    const app = boot();
    app.events.storage({ key: 'promerty.theme', newValue: 'dark' });
    assert.equal(app.root.dataset.theme, 'dark');
    app.events.storage({ key: null, newValue: null });
    assert.equal(app.root.dataset.themePreference, 'dark');
});
test('select change updates preference', () => {
    const app = boot();
    app.documentEvents.change({ target: { matches: () => true, value: 'dark' } });
    assert.equal(app.root.dataset.theme, 'dark');
});
