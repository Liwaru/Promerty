import {openDialog} from './dialogs.js';
document.addEventListener('click', event => {
    const opener = event.target.closest('[data-dialog-open]');
    if (opener) openDialog(document.getElementById(opener.dataset.dialogOpen));
    const chip = event.target.closest('[data-chip-group] .p-chip');
    if (chip) {
        chip.closest('[data-chip-group]').querySelectorAll('.p-chip').forEach(item => item.setAttribute('aria-pressed', String(item === chip)));
    }
    const tab = event.target.closest('[role=tab]');
    if (tab) {
        tab.closest('[role=tablist]').querySelectorAll('[role=tab]').forEach(item => {
            const selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
        });
    }
    const trigger = event.target.closest('[data-toast-show]');
    if (trigger) { const toast = document.getElementById(trigger.dataset.toastShow); toast.hidden = false; toast.querySelector('[data-toast-message]').textContent = 'Preferensi tampilan berhasil disimpan.'; }
    if (event.target.closest('[data-toast-close]')) event.target.closest('.p-toast').hidden = true;
    document.querySelectorAll('.p-dropdown[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.p-dropdown[open]').forEach(menu => { menu.open = false; menu.querySelector('summary').focus(); });
        document.querySelectorAll('.p-tooltip-wrap').forEach(wrap => { wrap.dataset.dismissed = ''; });
    }
    if (event.target.matches('[role=tab]') && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        event.preventDefault();
        const tabs = [...event.target.closest('[role=tablist]').querySelectorAll('[role=tab]')];
        let index = tabs.indexOf(event.target);
        index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[index].click(); tabs[index].focus();
    }
});
document.querySelectorAll('.p-tooltip-wrap').forEach(wrap => {
    for (const event of ['pointerleave', 'focusout']) wrap.addEventListener(event, () => { delete wrap.dataset.dismissed; });
});
