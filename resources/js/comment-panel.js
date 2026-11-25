import {openDialog,closeDialog,dismissDialog} from './dialogs.js';
export function panelBounds(viewport) {
    const sidebar = viewport >= 1200 ? 224 : 76;
    return { min:320, max:Math.max(320, Math.min(600, viewport - sidebar - 360)) };
}
export function clampPanelWidth(value, viewport) {
    const {min,max}=panelBounds(viewport);
    return Math.round(Math.min(max,Math.max(min,Number.isFinite(Number(value)) ? Number(value) : 400)));
}
export function initCommentPanel(app, { onModeChange = () => {}, dialogUI={open:openDialog,close:closeDialog,dismiss:dismissDialog} } = {}) {
    const panel=app.querySelector('#feed-comments'),handle=panel.querySelector('[data-comment-resize]');
    const media=window.matchMedia('(min-width: 960px)');
    let preferred=400, opener=null, dragging=false, ignoredCloses=0;
    try { const stored=localStorage.getItem('promerty.comments.width'); if(stored!==null)preferred=Number(stored); } catch {}
    const applyWidth=()=>{
        const width=clampPanelWidth(preferred,window.innerWidth),bounds=panelBounds(window.innerWidth);
        app.style.setProperty('--comment-width',`${width}px`);
        handle.setAttribute('aria-valuemin',bounds.min);handle.setAttribute('aria-valuemax',bounds.max);handle.setAttribute('aria-valuenow',width);
        return width;
    };
    const persist=()=>{try{localStorage.setItem('promerty.comments.width',String(preferred));}catch{}};
    const open=(source=document.activeElement)=>{
        if(!panel.open)opener=source;
        applyWidth();
        panel.setAttribute('role',media.matches?'region':'dialog');
        panel.setAttribute('aria-modal',String(!media.matches));
        app.classList.toggle('has-comments',media.matches);
        if(!panel.open)dialogUI.open(panel,{modal:!media.matches,opener,preserveDraft:true});
        panel.querySelector('[data-close]').focus({preventScroll:true});onModeChange();
    };
    const close=()=>{if(panel.open)dialogUI.close(panel);};
    panel.addEventListener('close',()=>{if(ignoredCloses){ignoredCloses--;return;}app.classList.remove('has-comments','is-resizing-comments');onModeChange();});
    const finish=()=>{if(!dragging)return;dragging=false;app.classList.remove('is-resizing-comments');persist();};
    handle.addEventListener('pointerdown',event=>{
        if(!media.matches||event.button!==0)return;
        event.preventDefault();dragging=true;handle.setPointerCapture(event.pointerId);app.classList.add('is-resizing-comments');
    });
    handle.addEventListener('pointermove',event=>{if(dragging){preferred=clampPanelWidth(window.innerWidth-event.clientX,window.innerWidth);applyWidth();}});
    for(const event of ['pointerup','pointercancel','lostpointercapture'])handle.addEventListener(event,finish);
    handle.addEventListener('keydown',event=>{
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
        event.preventDefault();const bounds=panelBounds(window.innerWidth);
        preferred=event.key==='Home'?bounds.min:event.key==='End'?bounds.max:applyWidth()+(event.key==='ArrowLeft'?24:-24);
        preferred=clampPanelWidth(preferred,window.innerWidth);applyWidth();persist();
    });
    panel.addEventListener('keydown',event=>{if(event.key==='Escape'&&media.matches){event.preventDefault();dialogUI.dismiss(panel);}});
    media.addEventListener('change',()=>{if(panel.open){ignoredCloses++;dialogUI.close(panel,{immediate:true,restore:false});open();}else applyWidth();});
    window.addEventListener('resize',applyWidth);
    applyWidth();
    return {open,close,isBlocking:()=>panel.open&&!media.matches};
}
