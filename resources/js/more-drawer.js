import {openDialog,closeDialog,dialogState} from './dialogs.js';
import {initSearchDrawer} from './search-drawer';
// The shared dialog controller owns dismissal, focus and cancellable motion.
export function initMoreDrawer(app, {onAccount} = {}) {
    const searchDrawer=initSearchDrawer(app);
    const dialog=app.querySelector('#customer-more');
    const sidebar=app.querySelector('.f-sidebar');
    const triggers=[...app.querySelectorAll('[data-feed-action="settings"]')];
    const mobile=window.matchMedia('(max-width:760px)');
    let user=null, railTimer;
    const themeInputs=[...dialog.querySelectorAll('[name="customer-theme"]')];
    function syncTheme(){themeInputs.forEach(input=>input.checked=input.value===(window.PromertyTheme?.preference||'system'));}
    function setUser(value){user=value;searchDrawer?.setUser(user);dialog.querySelector('[data-more-account-label]').textContent=user?'Keluar':'Masuk / Daftar';}
    function close({restore=true}={}) {
        return closeDialog(dialog,{restore});
    }
    function open(button=triggers.find(b=>b.getBoundingClientRect().width)) {
        if(dialog.open&&dialogState(dialog)!=='closing')return;
        syncTheme();dialog.querySelector('[data-more-error]').textContent='';
        dialog.setAttribute('aria-modal',String(mobile.matches));
        openDialog(dialog,{modal:mobile.matches,opener:button});
        dialog.querySelector('[data-more-close]').focus({preventScroll:true});
        app.dispatchEvent(new CustomEvent('promerty:more',{detail:{modal:mobile.matches}}));
    }
    // Capture prevents existing settings handlers from opening the former workspace.
    app.addEventListener('click',event=>{
        const button=event.target.closest('[data-feed-action]');
        if(button?.dataset.feedAction==='settings'){
            event.preventDefault();event.stopImmediatePropagation();
            ['opening','open'].includes(dialogState(dialog))?close():open(button);return;
        }
        if(button&&dialog.open)close({restore:false});
    },true);
    const clearRail=()=>{app.classList.remove('has-more');sidebar.querySelector('input').inert=app.classList.contains('has-search')&&!mobile.matches;};
    dialog.addEventListener('promerty:dialog-open',()=>{
        clearTimeout(railTimer);app.classList.add('has-more');sidebar.querySelector('input').inert=!mobile.matches;
        triggers.forEach(button=>button.setAttribute('aria-expanded','true'));
    });
    dialog.addEventListener('promerty:dialog-closing',()=>{
        triggers.forEach(button=>button.setAttribute('aria-expanded','false'));
        railTimer=setTimeout(clearRail,window.matchMedia('(prefers-reduced-motion: reduce)').matches?0:50);
    });
    dialog.addEventListener('close',()=>{if(!dialog.open){clearTimeout(railTimer);clearRail();}});
    // Desktop is nonmodal: rail and feed remain available; close before focus is obscured behind it.
    document.addEventListener('focusin',event=>{
        if(dialog.open&&!mobile.matches&&!dialog.contains(event.target)&&!sidebar.contains(event.target))close({restore:false});
    });
    mobile.addEventListener('change',()=>{if(dialog.open){closeDialog(dialog,{restore:false,immediate:true});open(triggers.find(b=>b.getBoundingClientRect().width));}});
    window.addEventListener('popstate',()=>close({restore:false}));
    window.addEventListener('promerty:theme',syncTheme);
    themeInputs.forEach(input=>input.addEventListener('change',()=>{if(input.checked)window.PromertyTheme?.set(input.value);}));
    dialog.querySelector('[data-more-account]').addEventListener('click',async event=>{
        const button=event.currentTarget;button.disabled=true;
        try{await onAccount?.(user);close({restore:false});}
        catch(error){dialog.querySelector('[data-more-error]').textContent=error.message||'Akun belum dapat diakses. Coba lagi.';}
        finally{button.disabled=false;}
    });
    syncTheme();return {open,close,setUser};
}
