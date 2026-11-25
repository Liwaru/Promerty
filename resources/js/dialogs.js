import {DialogMotion,isOutsideRect,dismissalPolicy} from './dialog-motion.js';

const records=new Map(),baselines=new WeakMap();
let installed=false,serial=0,outsideStart=null,confirmation=null;
const reduced=()=>window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const modal=dialog=>dialog.matches(':modal');
function fingerprint(form){
    return JSON.stringify([...form.elements].filter(e=>e.name&&!e.disabled&&!e.readOnly&&!['submit','button','hidden','reset'].includes(e.type)).map(e=>[e.name,e.type==='file'?[...e.files].map(f=>[f.name,f.size,f.lastModified]):['checkbox','radio'].includes(e.type)?e.checked:e.value]));
}
export function markDialogClean(dialog){dialog.querySelectorAll('form').forEach(form=>baselines.set(form,fingerprint(form)));}
function adoptForms(dialog){dialog.querySelectorAll('form').forEach(form=>{if(!baselines.has(form))baselines.set(form,fingerprint(form));});}
function record(dialog){
    if(records.has(dialog))return records.get(dialog);
    const state={motion:new DialogMotion(),opener:null,order:0,pending:null};records.set(dialog,state);
    dialog.classList.add('p-managed-dialog');
    dialog.addEventListener('cancel',event=>{event.preventDefault();dismissDialog(dialog,{reason:'escape'});});
    dialog.addEventListener('close',()=>{dialog.inert=false;lock();});
    adoptForms(dialog);return state;
}
function top(){return [...records.keys()].filter(d=>d.open).sort((a,b)=>Number(modal(a))-Number(modal(b))||record(a).order-record(b).order).at(-1);}
function lock(){document.documentElement.classList.toggle('p-modal-active',[...records.keys()].some(d=>d.open&&modal(d)));}
function railAllowed(dialog,target){return ['customer-more','customer-search-drawer'].includes(dialog.id)&&!!target.closest?.('.f-sidebar,.f-mobile-nav');}
function outside(dialog,event){return !dialog.contains(event.target)||(event.target===dialog&&isOutsideRect(dialog.getBoundingClientRect(),event.clientX,event.clientY));}
function feedback(dialog,message){
    let status=dialog.querySelector('[data-dialog-feedback]');
    if(!status){status=document.createElement('p');status.dataset.dialogFeedback='';status.className='p-dialog-feedback';status.setAttribute('role','alert');dialog.append(status);}
    status.textContent=message;
}
function animate(dialog,opening,immediate=false){
    if(immediate||reduced()||!dialog.animate)return null;
    const drawer=['customer-more','customer-search-drawer'].includes(dialog.id),desktop=drawer&&!window.matchMedia('(max-width:760px)').matches;
    const hidden={opacity:0,transform:desktop?'translateX(-18px)':drawer?'translateY(14px)':'translateY(6px) scale(.985)'};
    const visible={opacity:1,transform:'none'};
    return dialog.animate(opening?[hidden,visible]:[visible,hidden],{duration:opening?200:180,delay:opening&&desktop?30:0,easing:'cubic-bezier(.2,.7,.2,1)',fill:'both'});
}
export function dialogState(dialog){return record(dialog).motion.state;}
export function openDialog(dialog,{modal:asModal=true,opener=document.activeElement,preserveDraft=false}={}){
    initializeDialogs();const state=record(dialog);
    if(['customer-more','customer-search-drawer'].includes(dialog.id)){
        for(const other of document.querySelectorAll('#customer-more[open],#customer-search-drawer[open]'))if(other!==dialog)closeDialog(other,{restore:false,immediate:true});
    }
    if(dialog.open&&state.motion.state!=='closing')return;
    if(!dialog.open){
        state.opener=opener===document.body?(document.querySelector('#feed-account,.p-skip')||opener):opener;
        const post=state.opener?.closest?.('.f-post'),action=state.opener?.dataset?.action;
        state.openerKey=post&&action?`.f-post[data-id="${post.dataset.id}"] [data-action="${action}"]`:null;
        if(!preserveDraft)markDialogClean(dialog);state.order=++serial;
    }
    return state.motion.run(true,{before:()=>{
        dialog.dataset.dialogState='opening';dialog.inert=false;dialog.querySelector('[data-dialog-feedback]')?.remove();
        if(!dialog.open){if(asModal)dialog.showModal();else dialog.show();}
        lock();dialog.dispatchEvent(new CustomEvent('promerty:dialog-open'));
    },animate:opening=>animate(dialog,opening),after:()=>{dialog.dataset.dialogState='open';}});
}
export function closeDialog(dialog,{restore=true,immediate=false}={}){
    if(!dialog?.open)return Promise.resolve(false);
    const state=record(dialog);
    if(state.motion.state==='closing')return state.closing;
    state.closing=state.motion.run(false,{before:()=>{
        dialog.dataset.dialogState='closing';dialog.inert=true;dialog.dispatchEvent(new CustomEvent('promerty:dialog-closing'));
    },animate:opening=>animate(dialog,opening,immediate),after:()=>{
        dialog.close();dialog.dataset.dialogState='closed';dialog.inert=false;lock();
        const opener=state.opener?.isConnected?state.opener:state.openerKey?document.querySelector(state.openerKey):null;
        if(restore&&opener?.getBoundingClientRect().width)opener.focus({preventScroll:true});
    }});
    return state.closing;
}
function confirmDiscard(parent){
    if(!confirmation){
        confirmation=document.createElement('dialog');confirmation.id='dialog-discard';confirmation.className='p-modal p-discard-dialog';
        confirmation.setAttribute('aria-labelledby','discard-title');confirmation.dataset.dialogCritical='true';
        confirmation.innerHTML='<h2 id="discard-title">Abaikan perubahan?</h2><p>Isian Anda belum disimpan. Tetap mengisi atau abaikan isian dan tutup dialog?</p><div class="p-row"><button type="button" class="p-button p-button--secondary" data-keep>Lanjutkan mengisi</button><button type="button" class="p-button p-button--primary" data-discard>Abaikan dan tutup</button></div>';
        (parent.closest('.f-app')||document.body).append(confirmation);record(confirmation);
    }
    return new Promise(resolve=>{
        const settle=async discard=>{confirmation.querySelector('[data-keep]').onclick=null;confirmation.querySelector('[data-discard]').onclick=null;await closeDialog(confirmation,{restore:!discard});resolve(discard);};
        confirmation.querySelector('[data-keep]').onclick=()=>settle(false);
        confirmation.querySelector('[data-discard]').onclick=()=>settle(true);
        confirmation.keep=()=>settle(false);
        openDialog(confirmation);confirmation.querySelector('[data-keep]').focus({preventScroll:true});
    });
}
export async function allowDialogChange(dialog){
    adoptForms(dialog);
    const policy=dismissalPolicy({
        busy:dialog.getAttribute('aria-busy')==='true'||!!dialog.querySelector('form[aria-busy="true"],[data-dialog-pending="true"],form button[type="submit"]:disabled,form button:not([type]):disabled'),
        critical:dialog.dataset.dialogCritical==='true',
        dirty:dialog.dataset.dialogDisposable!=='true'&&[...dialog.querySelectorAll('form')].some(f=>baselines.get(f)!==fingerprint(f))
    });
    if(policy==='busy'||policy==='critical'){
        feedback(dialog,policy==='busy'?'Proses masih berjalan. Tunggu hingga selesai sebelum menutup dialog.':'Pilih salah satu tindakan untuk melanjutkan.');return false;
    }
    if(policy==='confirm'){
        if(!await confirmDiscard(dialog))return false;
        dialog.querySelectorAll('form').forEach(f=>f.reset());markDialogClean(dialog);
    }
    return true;
}
export function dismissDialog(dialog,{reason='dismiss',restore=true}={}){
    if(!dialog?.open)return Promise.resolve(false);
    // A disposable search draft needs no asynchronous discard decision. Start its
    // cancellable close synchronously so an immediate reopen wins the transition.
    if(dialog.dataset.dialogDisposable==='true')return closeDialog(dialog,{restore});
    if(dialog===confirmation&&reason==='escape'){confirmation.keep?.();return Promise.resolve(false);}
    const state=record(dialog);if(state.pending)return state.pending;
    state.pending=(async()=>{
        if(!await allowDialogChange(dialog))return false;
        return closeDialog(dialog,{restore});
    })().finally(()=>state.pending=null);
    return state.pending;
}
export function initializeDialogs(){
    if(installed)return;installed=true;
    document.querySelectorAll('dialog').forEach(record);
    new MutationObserver(changes=>{for(const change of changes){const dialog=change.target.closest?.('dialog');if(dialog)adoptForms(dialog);}}).observe(document.body,{childList:true,subtree:true});
    document.addEventListener('reset',event=>{if(event.target.closest('dialog'))queueMicrotask(()=>baselines.set(event.target,fingerprint(event.target)));},true);
    document.addEventListener('pointerdown',event=>{
        const dialog=top();outsideStart=null;
        if(dialog&&outside(dialog,event)&&!railAllowed(dialog,event.target)){
            outsideStart={dialog,x:event.clientX,y:event.clientY};event.preventDefault();event.stopImmediatePropagation();
        }
    },true);
    document.addEventListener('pointercancel',()=>{outsideStart=null;},true);
    document.addEventListener('pointerup',()=>{setTimeout(()=>outsideStart=null,0);},true);
    document.addEventListener('click',event=>{
        const dialog=top();
        if(outsideStart){
            const start=outsideStart;outsideStart=null;event.preventDefault();event.stopImmediatePropagation();
            if(dialog===start.dialog&&outside(dialog,event)&&Math.hypot(event.clientX-start.x,event.clientY-start.y)<8)dismissDialog(dialog,{reason:'outside'});
            return;
        }
        const closer=event.target.closest('[data-close],[data-dialog-close],[data-more-close]');
        if(closer?.closest('dialog')){event.preventDefault();event.stopImmediatePropagation();dismissDialog(closer.closest('dialog'));}
    },true);
    document.addEventListener('keydown',event=>{
        const dialog=top();if(!dialog)return;
        if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();dismissDialog(dialog,{reason:'escape'});}
        if(event.key==='Tab'&&modal(dialog)){
            const controls=[...dialog.querySelectorAll('button:not(:disabled),input:not(:disabled):not([type=hidden]),textarea:not(:disabled),select:not(:disabled),a[href],[tabindex="0"]')].filter(e=>e.getBoundingClientRect().width&&getComputedStyle(e).visibility!=='hidden'&&(e.type!=='radio'||e.checked));
            const first=controls[0],last=controls.at(-1);
            if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
            else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
        }
    },true);
    for(const type of ['wheel','touchmove'])document.addEventListener(type,event=>{const dialog=top();if(dialog&&modal(dialog)&&outside(dialog,event))event.preventDefault();},{capture:true,passive:false});
}
