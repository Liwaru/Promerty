import { searchKeys,searchParams,publicSearchCard,searchCacheKey,defaultSearchKind } from './search-state';
import {advancedKeys,activeFilterKeys,initAdvancedFilters} from './search-filters';

const app=document.getElementById('promerty-search');
if(app&&location.hash==='#search-input'){
    history.replaceState(history.state,'',location.pathname+location.search);
    if(document.activeElement?.id==='search-input')document.activeElement.blur();
    app.scrollTop=0;window.scrollTo(0,0);
}
if(app)initSearch();
function initSearch(){
    const $=s=>app.querySelector(s),form=$('#search-form'),grid=$('#search-grid'),scroller=app;
    const advanced=initAdvancedFilters($('#toggle-advanced-filters'),$('#advanced-search-filters'));
    const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money=value=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(value||0)).replace(/^Rp\s+/,'Rp');
    let params=new URLSearchParams(location.search),cards=[],nextPage=null,controller,version=0,loading=false,failedPage=null,interpreted=null,restoring=false;
    const url=()=>'/search'+(params.size?'?'+params:'');
    const persist=()=>{if(restoring)return;try{const kept=cards.slice(0,120);sessionStorage.setItem(searchCacheKey(url()),JSON.stringify({at:Date.now(),cards:kept,next:cards.length>120?11:nextPage,scroll:scroller.scrollTop,filtersOpen:$('#search-filters').open,advancedOpen:advanced.isOpen()}));}catch{}};
    const poster=(src,label,isVideo)=>`<div class="s-poster">${src?`<img src="${escape(src)}" alt="" loading="lazy" width="360" height="560">`:isVideo?'':'<span>Pratinjau belum tersedia</span>'}<span class="s-media-label">${escape(label)}</span>${isVideo?'<span class="sr-only">Buka video</span><span class="s-preview-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 5 11 7-11 7Z"/></svg></span>':''}</div>`;
    function interpretation(){
        const parts=[],f=interpreted?.filters||{},node=$('#search-interpretation');
        for(const [key,value] of Object.entries(f)){
            if(key==='transaction')parts.push(value==='rent'?'Disewakan':'Dijual');
            else if(key==='category')parts.push(app.searchCategories?.find(c=>Number(c.id)===Number(value))?.name||'Kategori properti');
            else if(key==='price_period')parts.push(value==='month'?'Per bulan':value==='year'?'Per tahun':'Total');
            else if(key==='min_price')parts.push((f.min_price===f.max_price?'Harga ':'Harga mulai ')+money(value));
            else if(key==='max_price'&&f.min_price!==f.max_price)parts.push('Harga sampai '+money(value));
            else if(key==='max_price')continue;
            else parts.push(value+' '+({floors:'lantai',bedrooms:'kamar tidur',bathrooms:'kamar mandi',balconies:'balkon',land_area:'m² tanah',building_area:'m² bangunan'}[key]||key));
        }
        if(interpreted?.location)parts.push(interpreted.location);
        else if(parts.length&&interpreted?.terms?.length)parts.push('Kata kunci: '+interpreted.terms.join(' '));
        const ambiguous=interpreted?.ambiguous_transaction;
        node.hidden=!parts.length&&!ambiguous;
        node.textContent=ambiguous?'Pilih satu transaksi dalam kata kunci, jual atau sewa.':parts.length?'Pencarian: '+parts.join(' · '):'';
    }
    function propertyCard(c,index,from){
        const isVideo=c.media_type==='video';
        const label=isVideo?({tiktok:'TikTok',instagram:'Instagram Reels'})[c.provider]||'Video':'Foto';
        const facts=[[c.bedrooms,'KT'],[c.bathrooms,'KM'],[c.balconies,'balkon']]
            .filter(([count])=>count!==null&&count!==undefined).map(([count,unit])=>escape(count)+' '+unit);
        const tags=[c.transaction==='rent'?'Disewakan':'Dijual',c.category,c.type==='primary'?'Primary':'Secondary'];
        return `<a class="s-card" href="/posts/${Number(c.id)}?from=${from}&result_page=${Math.floor(index/12)+1}" data-result-post="${Number(c.id)}">
            ${poster(c.poster,label,isVideo)}<h3>${escape(c.title)}</h3>
            <p class="s-card-price">${escape(money(c.price))}${({month:' / bulan',year:' / tahun'})[c.price_period]||''}</p>
            <p class="s-card-transaction">${escape(tags.filter(Boolean).join(' · '))}</p>
            ${c.project?`<p class="p-muted">${escape(c.project)}</p>`:''}<p class="p-muted">${escape(c.location||'Lokasi publik belum tersedia')}</p>
            ${facts.length?`<p class="p-muted">${facts.join(' · ')}</p>`:''}<p class="p-muted">${escape(c.agent)}</p></a>`;
    }
    function render(){
        interpretation();
        const kind=params.get('kind')||defaultSearchKind,from=encodeURIComponent(url());
        grid.innerHTML=cards.map((c,index)=>kind==='agent'?`<a class="s-card s-agent" href="/agents/${Number(c.id)}?from=${from}">${c.photo?`<img class="p-avatar" src="${escape(c.photo)}" alt="" loading="lazy" width="64" height="64">`:''}<h3>${escape(c.name)}</h3><p class="p-muted">${escape([c.agency,c.branch].filter(Boolean).join(' · '))}</p><p>${escape(c.bio||'Kenali Agent dan lihat properti publiknya.')}</p><p class="s-card-price">Lihat profil &amp; properti ↗</p></a>`:propertyCard(c,index,from)).join('');
        grid.querySelectorAll('img').forEach(img=>img.addEventListener('error',()=>{
            if(img.closest('.s-poster')?.querySelector('.s-preview-play')){img.remove();return;}
            const note=document.createElement('span');note.textContent='Pratinjau belum tersedia';img.replaceWith(note);
        },{once:true}));
        $('#search-more').hidden=!nextPage;$('#search-more').disabled=false;
        $('#search-summary').textContent=cards.length?`${cards.length} hasil dimuat${nextPage?' · masih ada hasil':''}`:'';
    }
    function syncForm(){
        for(const key of searchKeys)if(form.elements[key])form.elements[key].value=params.get(key)||'';
        const kind=params.get('kind')||defaultSearchKind;form.elements.kind.value=kind;
        $('#sidebar-search').elements.q.value=params.get('q')||'';
        $('#search-suggestions').hidden=!!params.get('q')||kind==='agent';
        const sorts=kind==='agent'?[['relevance','Paling relevan'],['name','Nama Agent']]:[['relevance','Paling relevan'],['latest','Terbaru'],['price_asc','Harga terendah'],['price_desc','Harga tertinggi']];
        form.elements.sort.replaceChildren(...sorts.map(([value,label])=>new Option(label,value)));form.elements.sort.value=params.get('sort')||'relevance';
        for(const link of app.querySelectorAll('[data-kind]')){if(link.dataset.kind===kind)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');const p=new URLSearchParams(params);p.set('kind',link.dataset.kind);p.delete('sort');link.href='/search?'+p;}
        $('#search-heading').textContent={video:'Video properti',property:'Properti pilihan',agent:'Agent dengan properti publik'}[kind]||'Hasil pencarian';
        const count=activeFilterKeys(params).length,advancedCount=activeFilterKeys(params,advancedKeys).length;
        $('#search-filter-count').textContent=count?`(${count})`:'';$('#search-filter-count').setAttribute('aria-label',`${count} filter aktif`);
        $('#advanced-filter-count').textContent=advancedCount?`(${advancedCount} aktif)`:'';
    }
    function emptyState(){
        $('#search-state').innerHTML=cards.length?'':'<h3>Belum ada hasil yang cocok.</h3><p>Coba kata kunci lain atau sesuaikan filter.</p>';
    }
    async function load(page=1,{focusResults=false,restorePage=false}={}){
        if(!restorePage)restoring=false;
        controller?.abort();controller=new AbortController();const token=++version;loading=true;failedPage=null;
        $('#search-results').setAttribute('aria-busy','true');$('#search-more').disabled=true;
        form.querySelectorAll('button[type=submit]').forEach(button=>button.disabled=true);
        if(page===1){cards=[];nextPage=null;interpreted=null;interpretation();$('#search-more').hidden=true;grid.innerHTML=Array.from({length:6},()=>'<div class="s-skeleton" aria-hidden="true"></div>').join('');}
        $('#search-state').textContent='Memuat hasil pencarian…';
        try{
            const p=new URLSearchParams(params);p.set('page',page);
            const response=await fetch('/api/search?'+p,{credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
            const result=await response.json();if(!response.ok)throw new Error(response.status===422?Object.values(result.errors||{}).flat().join(' '):response.status===429?'Terlalu banyak pencarian. Tunggu sebentar lalu coba lagi.':'Pencarian belum dapat dimuat. Coba lagi.');
            if(token!==version)return;
            interpreted=result.interpreted||null;
            const incoming=result.data.map(item=>publicSearchCard(item,result.kind)),seen=new Set(cards.map(c=>c.id));cards.push(...incoming.filter(c=>!seen.has(c.id)));nextPage=result.next_page;
            render();emptyState();
            persist();
            if(focusResults){
                await advanced.setOpen(false);if(token!==version)return;
                const heading=$('#search-heading');heading.tabIndex=-1;heading.focus({preventScroll:true});
                heading.scrollIntoView({block:'start',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
            }
            return true;
        }catch(error){if(error.name==='AbortError'||token!==version)return;failedPage=page;if(page===1)grid.replaceChildren();$('#search-state').innerHTML=`<h3>Pencarian belum tersedia.</h3><p>${escape(error.message)}</p><button class="p-button p-button--primary" data-retry-search>Coba lagi</button>`;}
        finally{if(token===version){loading=false;$('#search-results').setAttribute('aria-busy','false');$('#search-more').disabled=false;form.querySelectorAll('button[type=submit]').forEach(button=>button.disabled=false);}}
    }
    function navigate(next,{focusResults=false}={}){const normalized=searchParams(next);if(loading&&normalized.toString()===params.toString())return;persist();params=normalized;history.pushState(null,'',url());syncForm();if(!focusResults)scroller.scrollTop=0;load(1,{focusResults});}
    const filterError=$('#search-filter-error');
    form.addEventListener('submit',event=>{
        event.preventDefault();const min=form.elements.min_price,max=form.elements.max_price;
        const error=min.value!==''&&max.value!==''&&Number(min.value)>Number(max.value)?'Harga maksimum harus sama atau lebih besar dari minimum.':'';
        max.setCustomValidity(error);filterError.textContent=error;filterError.hidden=!error;
        if(error){$('#search-filters').open=true;advanced.setOpen(true,{animate:false});max.setAttribute('aria-invalid','true');}
        if(form.reportValidity())navigate(new FormData(form),{focusResults:event.submitter?.id==='apply-search-filters'});
    });
    form.addEventListener('invalid',event=>{$('#search-filters').open=true;if($('#advanced-search-filters').contains(event.target))advanced.setOpen(true,{animate:false});filterError.textContent=event.target.validationMessage;filterError.hidden=false;},true);
    for(const input of [form.elements.min_price,form.elements.max_price])input.addEventListener('input',()=>{form.elements.max_price.setCustomValidity('');form.elements.max_price.removeAttribute('aria-invalid');filterError.hidden=true;});
    form.addEventListener('reset',event=>{event.preventDefault();form.elements.max_price.setCustomValidity('');form.elements.max_price.removeAttribute('aria-invalid');filterError.hidden=true;navigate({kind:params.get('kind')||defaultSearchKind});});
    form.elements.sort.addEventListener('change',()=>form.requestSubmit());
    app.addEventListener('click',event=>{const link=event.target.closest('[data-kind],[data-category]');if(link){event.preventDefault();navigate(new URL(link.href).searchParams);return;}if(event.target.closest('[data-retry-search]'))load(failedPage||1);if(event.target.closest('[data-result-post]'))persist();});
    $('#search-more').addEventListener('click',()=>{if(!loading&&nextPage)load(nextPage)});
    scroller.addEventListener('scroll',()=>{clearTimeout(scroller.saveTimer);scroller.saveTimer=setTimeout(persist,150)},{passive:true});
    $('#search-filters').addEventListener('toggle',persist);
    $('#toggle-advanced-filters').addEventListener('click',()=>queueMicrotask(persist));
    window.addEventListener('pagehide',persist);
    window.addEventListener('popstate',()=>{controller?.abort();params=new URLSearchParams(location.search);syncForm();restore()||load();});
    function restore(){
        let data;
        try{data=JSON.parse(sessionStorage.getItem(searchCacheKey(url())));}catch{return false;}
        if(!data||Date.now()-data.at>600000||!Array.isArray(data.cards))return false;
        restoring=true;
        if(typeof data.filtersOpen==='boolean')$('#search-filters').open=data.filtersOpen;
        if(typeof data.advancedOpen==='boolean')advanced.setOpen(data.advancedOpen,{animate:false});
        // Restore controls and scroll, but recheck publication before showing cached results.
        void (async()=>{
            const pages=Math.min(10,Math.max(1,Math.ceil(data.cards.length/12)));
            for(let page=1;page<=pages;page++){
                const pending=load(page,{restorePage:true}),token=version;
                const ok=await pending;
                if(!restoring||token!==version)return;
                if(!ok||!nextPage)break;
            }
            const token=version;
            await categoryReady;
            requestAnimationFrame(()=>{if(token!==version)return;scroller.scrollTop=data.scroll||0;restoring=false;persist();});
        })();
        return true;
    }
    async function categories(){try{const response=await fetch('/api/feed/config',{headers:{Accept:'application/json'}});if(!response.ok)return;const data=await response.json();app.searchCategories=data.categories;for(const c of data.categories){$('#search-category').append(new Option(c.name,c.id));const a=document.createElement('a'),p=new URLSearchParams({kind:'property',category:c.id});a.href='/search?'+p;a.dataset.category=c.id;a.textContent=c.name;$('#search-suggestions').append(a);}form.elements.category.value=params.get('category')||'';interpretation();}catch{/* Search remains usable without category suggestions. */}}
    params=searchParams(params);syncForm();const categoryReady=categories();
    let returning=false;try{returning=sessionStorage.getItem('promerty-search-return')===url();sessionStorage.removeItem('promerty-search-return');}catch{}
    const back=performance.getEntriesByType('navigation')[0]?.type==='back_forward';
    if(!(returning||back)||!restore())load();
    window.addEventListener('pageshow',event=>{if(event.persisted&&!restore())load();});
}
