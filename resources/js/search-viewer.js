import {safeSearchReturn,searchCacheKey,publicSearchCard} from './search-state';
const feed=document.getElementById('promerty-feed');
const from=safeSearchReturn(new URLSearchParams(location.search).get('from'),location.origin);
if(feed&&from&&(feed.dataset.post||feed.dataset.agent))initSearchViewer();
function initSearchViewer(){
    let state;try{state=JSON.parse(sessionStorage.getItem(searchCacheKey(from)));}catch{}
    const cached=Array.isArray(state?.cards);
    let ids=(cached?state.cards:[]).map(c=>Number(c.id)),nextPage=state?.next,loading=false,firstPage=1;
    const current=Number(feed.dataset.post),bar=document.createElement('nav');bar.className='s-viewer-context';bar.setAttribute('aria-label','Konteks hasil pencarian');
    const back=document.createElement('a');back.href=from;back.className='p-button p-button--ghost';back.textContent='← Hasil pencarian';back.onclick=()=>{try{sessionStorage.setItem('promerty-search-return',from);}catch{}};bar.append(back);
    const controls=document.createElement('div');controls.className='p-row';
    const prev=document.createElement('a'),next=document.createElement('button');prev.className=next.className='p-button p-button--secondary';prev.textContent='↑ Sebelumnya';next.textContent='Berikutnya ↓';controls.append(prev,next);if(current)bar.append(controls);
    feed.querySelector('.f-main').prepend(bar);
    const target=id=>`/posts/${id}?from=${encodeURIComponent(from)}&result_page=${firstPage+Math.floor(Math.max(0,ids.indexOf(id))/12)}`;
    function update(){const index=ids.indexOf(current);prev.hidden=index<=0&&firstPage<=1;if(index>0)prev.href=target(ids[index-1]);else prev.href='#';next.disabled=loading||(!ids[index+1]&&!nextPage);}
    async function loadPage(page){const p=new URL(from,location.origin).searchParams;p.set('page',page);const r=await fetch('/api/search?'+p,{headers:{Accept:'application/json'}});if(!r.ok)throw Error();return r.json();}
    async function advance(){if(loading)return;let index=ids.indexOf(current);
        if(ids[index+1]){location.assign(target(ids[index+1]));return;}
        if(!nextPage)return;loading=true;update();
        try{const data=await loadPage(nextPage);nextPage=data.next_page;ids.push(...data.data.map(p=>Number(p.id)).filter(id=>!ids.includes(id)));if(cached&&state.cards.length<120){const known=new Set(state.cards.map(c=>c.id));state.cards.push(...data.data.filter(p=>!known.has(p.id)).map(p=>publicSearchCard(p,data.kind)));state.cards=state.cards.slice(0,120);state.next=nextPage;try{sessionStorage.setItem(searchCacheKey(from),JSON.stringify(state));}catch{}}index=ids.indexOf(current);if(ids[index+1])location.assign(target(ids[index+1]));else next.textContent='Tidak ada hasil berikutnya';}
        catch{next.textContent='Coba hasil berikutnya lagi';}finally{loading=false;update();}
    }
    next.onclick=advance;update();
    prev.addEventListener('click',async event=>{if(ids.indexOf(current)>0)return;event.preventDefault();if(firstPage<=1||loading)return;loading=true;try{const data=await loadPage(firstPage-1);ids.unshift(...data.data.map(p=>Number(p.id)));firstPage--;const index=ids.indexOf(current);if(index>0)location.assign(target(ids[index-1]));}catch{prev.textContent='Coba sebelumnya lagi';}finally{loading=false;update();}});
    feed.querySelector('#feed-scroll').addEventListener('keydown',event=>{if(event.target.matches('input,textarea,button,select,video')||feed.querySelector('dialog[open]'))return;if(['ArrowDown','PageDown'].includes(event.key)){event.preventDefault();event.stopImmediatePropagation();advance();}else if(['ArrowUp','PageUp'].includes(event.key)&&!prev.hidden){event.preventDefault();event.stopImmediatePropagation();prev.click();}},{capture:true});
    if(current&&!ids.includes(current)){
        // A page hint restores shared links with one bounded request, not a scan of all result pages.
        firstPage=Math.min(100,Math.max(1,Number(new URLSearchParams(location.search).get('result_page'))||1));
        (async()=>{loading=true;update();try{const data=await loadPage(firstPage);ids=data.data.map(p=>Number(p.id));nextPage=data.next_page;}catch{next.textContent='Coba hasil berikutnya lagi';}finally{loading=false;update();}})();
    }
}
