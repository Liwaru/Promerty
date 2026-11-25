import {openDialog,closeDialog,dialogState} from './dialogs.js';
import {initialAreas,queryExamples,drawerSuggestions,rememberSearch,normalizeSearch,drawerSearchUrl} from './search-drawer-state';

export function initSearchDrawer(app){
    // Current Customer workflow uses the full Search page. Preserve the drawer
    // implementation for explicitly opted-in contexts without intercepting navigation.
    if(app.dataset.searchMode!=='drawer')return null;
    if(app.searchDrawer)return app.searchDrawer;
    const dialog=app.querySelector('#customer-search-drawer'),sidebar=app.querySelector('.f-sidebar');
    const input=dialog.querySelector('[name=q]'),form=dialog.querySelector('form'),list=dialog.querySelector('[data-search-options]'),status=dialog.querySelector('[data-search-status]');
    const results=dialog.querySelector('.c-search-results');
    const mobile=matchMedia('(max-width:760px)'),trigger=sidebar.querySelector('#sidebar-search button');
    const triggers=[trigger,...app.querySelectorAll('[data-open-search-drawer],.f-header [data-feed-action="search"]')];
    triggers.forEach(button=>{button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls',dialog.id);button.setAttribute('aria-expanded','false');});
    let recent=[],identity=null,timer,controller,version=0,index=-1;
    function alignRail(){
        if(!dialog.open)return;
        if(mobile.matches){for(const key of ['--search-panel-top','--search-header-size','--search-input-gap'])dialog.style.removeProperty(key);return;}
        const logo=sidebar.querySelector('.f-sidebar-brand img').getBoundingClientRect(),icon=trigger.querySelector('.p-icon').getBoundingClientRect();
        const logoCenter=logo.top+logo.height/2,iconCenter=icon.top+icon.height/2;
        const top=Math.max(0,Math.min(12,logoCenter-23)),header=2*(logoCenter-top-1);
        dialog.style.setProperty('--search-panel-top',top+'px');
        dialog.style.setProperty('--search-header-size',header+'px');
        dialog.style.setProperty('--search-input-gap',Math.max(0,iconCenter-top-1-header-22)+'px');
    }
    const railSize=new ResizeObserver(alignRail);railSize.observe(sidebar.querySelector('.f-sidebar-top'));railSize.observe(sidebar.querySelector('.f-sidebar-brand img'));railSize.observe(trigger);
    window.addEventListener('resize',alignRail);sidebar.addEventListener('scroll',alignRail,{passive:true});
    function setUser(user){identity=user?'user-'+Number(user.id):'guest';recent=[];try{const stored=JSON.parse(sessionStorage.getItem('promerty-search-history:'+identity)||'[]');if(Array.isArray(stored))recent=stored.filter(v=>typeof v==='string').map(normalizeSearch).filter(Boolean).slice(0,5);}catch{}if(dialog.open)render();}
    function save(){if(identity)try{sessionStorage.setItem('promerty-search-history:'+identity,JSON.stringify(recent));}catch{}}
    function run(value){const q=normalizeSearch(value);if(!q)return;recent=rememberSearch(recent,q);save();location.assign(drawerSearchUrl(q,location.pathname==='/search'?location.search:''));}
    function section(title,values,type='query'){
        const h=document.createElement('h3');h.textContent=title;list.append(h);
        for(const entry of values){const value=typeof entry==='string'?entry:entry.value;const button=document.createElement('button');button.type='button';button.className='c-search-option';button.dataset.query=value;button.dataset.suggestionType=entry.type||type;button.textContent=value;list.append(button);}
    }
    function render(items=[]){
        const focused=list.contains(document.activeElement)?document.activeElement.dataset.query:null;
        list.replaceChildren();index=-1;
        const q=normalizeSearch(input.value),suggestions=drawerSuggestions(q,{categories:app.searchCategories||[],items,recent});
        if(q){section('Saran pencarian',suggestions);if(!suggestions.length){const p=document.createElement('p');p.className='p-muted';p.textContent='Tekan Enter untuk mencari kebutuhan Anda.';list.append(p);}}
        else{
            section('Contoh pencarian',queryExamples.slice(0,3));section('Saran kawasan',initialAreas,'area');
            section('Pencarian Terakhir',recent.slice(0,3),'recent');if(!recent.length){const p=document.createElement('p');p.className='p-muted';p.textContent='Belum ada pencarian terakhir.';list.append(p);}
        }
        dialog.querySelector('[data-search-clear]').hidden=!!q||!recent.length;
        status.textContent=q?(suggestions.length?`${suggestions.length} saran pencarian. Enter untuk melihat seluruh hasil.`:'Enter untuk mencari kebutuhan Anda.'):'Tiga contoh pencarian dan tiga saran kawasan tersedia.';
        if(focused)[...list.querySelectorAll('button')].find(button=>button.dataset.query===focused)?.focus({preventScroll:true});
    }
    function stop(){clearTimeout(timer);controller?.abort();version++;}
    async function suggestions(){
        const q=normalizeSearch(input.value);stop();render();results.scrollTop=0;if(q.length<2)return;
        const token=version;
        timer=setTimeout(async()=>{
            controller=new AbortController();
            try{const response=await fetch('/api/search?'+new URLSearchParams({kind:'property',q}),{signal:controller.signal,credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.ok)throw new Error();const result=await response.json();if(token===version&&dialog.open)render(result.data.slice(0,5));}
            catch(error){if(error.name!=='AbortError'&&token===version)status.textContent='Saran properti belum tersedia. Enter untuk membuka hasil pencarian.';}
        },300);
    }
    function close(){return closeDialog(dialog);}
    function open(opener=trigger){
        if(dialog.open&&dialogState(dialog)!=='closing')return;
        input.value=location.pathname==='/search'?new URLSearchParams(location.search).get('q')||'':'';render();
        dialog.setAttribute('aria-modal',String(mobile.matches));openDialog(dialog,{modal:mobile.matches,opener});results.scrollTop=0;input.focus({preventScroll:true});if(input.value)suggestions();
    }
    app.addEventListener('click',event=>{
        const target=event.target.closest('#sidebar-search,[data-open-search-drawer],.f-header [data-feed-action="search"]');
        if(!target)return;event.preventDefault();event.stopImmediatePropagation();
        const opener=target.matches('#sidebar-search')?trigger:target;
        dialog.open&&dialogState(dialog)!=='closing'?close():open(opener);
    },true);
    sidebar.querySelector('#sidebar-search').addEventListener('submit',event=>{event.preventDefault();event.stopImmediatePropagation();open(trigger);},true);
    form.addEventListener('submit',event=>{event.preventDefault();run(input.value);});
    input.addEventListener('input',suggestions);
    input.addEventListener('keydown',event=>{
        if(!['ArrowDown','ArrowUp'].includes(event.key))return;
        const buttons=[...list.querySelectorAll('button')];if(!buttons.length)return;event.preventDefault();buttons[event.key==='ArrowDown'?0:buttons.length-1].focus();
    });
    list.addEventListener('keydown',event=>{
        if(!['ArrowDown','ArrowUp','Home','End'].includes(event.key))return;
        const buttons=[...list.querySelectorAll('button')];index=buttons.indexOf(document.activeElement);if(index<0)return;
        event.preventDefault();index=event.key==='Home'?0:event.key==='End'?buttons.length-1:(index+(event.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length;buttons[index].focus();
    });
    list.addEventListener('click',event=>{const button=event.target.closest('[data-query]');if(button)run(button.dataset.query);});
    dialog.querySelector('[data-search-clear]').addEventListener('click',()=>{recent=[];save();render();input.focus();});
    dialog.addEventListener('promerty:dialog-open',()=>{app.classList.add('has-search');alignRail();triggers.forEach(button=>button.setAttribute('aria-expanded','true'));sidebar.querySelector('input').inert=!mobile.matches;app.dispatchEvent(new CustomEvent('promerty:more',{detail:{modal:mobile.matches}}));});
    dialog.addEventListener('promerty:dialog-closing',()=>{stop();triggers.forEach(button=>button.setAttribute('aria-expanded','false'));});
    dialog.addEventListener('close',()=>{if(!dialog.open){app.classList.remove('has-search');sidebar.querySelector('input').inert=app.classList.contains('has-more')&&!mobile.matches;}});
    document.addEventListener('focusin',event=>{if(dialog.open&&!mobile.matches&&!dialog.contains(event.target)&&!sidebar.contains(event.target))closeDialog(dialog,{restore:false});});
    window.addEventListener('popstate',()=>closeDialog(dialog,{restore:false,immediate:true}));
    mobile.addEventListener('change',()=>{if(dialog.open){closeDialog(dialog,{restore:false,immediate:true});open(trigger);}});
    app.searchDrawer={open,close,setUser};return app.searchDrawer;
}
