import { initCommentPanel } from './comment-panel';
import { coordinatePlayback } from './feed-playback';
import { customerWorkspace } from './customer';
import { propertyDetail } from './property-detail';
import { emptyState } from './empty-state';
import { safeSearchReturn } from './search-state';
import {customerSections,setNavigationActive,customerSearchUrl} from './customer-navigation';
import {initMoreDrawer} from './more-drawer';
import {openDialog,closeDialog,markDialogClean,allowDialogChange} from './dialogs.js';
import {videoViewer,bindVideoControls,bindVideoBuffer,videoOrientation} from './video-viewer.js';
import {bindGoogleLogin} from './google-auth';
import {externalVideo} from './external-video.js';
import {bindPasswordPolicy, syncPasswordPolicy} from './password-policy';

const app = document.getElementById('promerty-feed');
if (app) initFeed();

function initFeed() {
    const $ = selector => app.querySelector(selector);
    bindGoogleLogin($('[data-google-login]'),()=>pendingPostId ? '/posts/'+pendingPostId : location.pathname,url=>location.assign(url));
    const scroller = $('#feed-scroll');
    const posts = new Map();
    const ratios = new Map();
    const paths = { heart:'M20 4c-3-2-6 0-8 2-2-2-5-4-8-2-4 3-1 8 8 15C21 12 24 7 20 4Z', comment:'M4 4h16v12H9l-5 4Z', save:'M6 3h12v18l-6-4-6 4Z', share:'m14 3 7 6-7 6V11C7 11 4 14 3 20V11c0-4 5-5 11-5Z', download:'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4', home:'m3 10 9-7 9 7v11H3ZM9 21v-8h6v8', play:'m8 4 12 8-12 8Z', volume:'m3 9 5 0 5-5v16l-5-5H3ZM17 8c3 3 3 5 0 8' };
    paths.pause='M8 4v16m8-16v16';paths.muted='m3 9 5 0 5-5v16l-5-5H3Zm14 0 5 6m0-6-5 6';
    const icon = name => `<svg class="p-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="${paths[name] || paths.home}"/></svg>`;
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = value => new Intl.NumberFormat('id-ID', { style:'currency', currency:'IDR', maximumFractionDigits:0 }).format(Number(value || 0)).replace(/^Rp\s+/,'Rp');
    let user = null, csrf = document.querySelector('meta[name=csrf-token]').content;
    let mode = 'for-you', filters = {}, next = null, loading = false, generation = 0, active = null;
    let pending = null, pendingPostId = null, authMode = 'login', authView = 'credentials', currentPost = null, commentPost = null, contactMode = 'message', reply = null, commentPage = 1, messagePage = 1;
    let muted = true, downloadSettings = null, observer, moreObserver, contactVersion=0;
    const moreDrawer=initMoreDrawer(app,{onAccount:()=>$('#feed-account').click()});
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    try { muted = sessionStorage.getItem('promerty.feed.muted') !== 'false'; } catch {}

    const commentPanel = initCommentPanel(app, {onModeChange:()=>playActive()});
    const blockingDialog = () => !!app.querySelector('dialog[open]:not(#feed-comments):not(#customer-more[aria-modal="false"]):not(#customer-search-drawer[aria-modal="false"])') || commentPanel.isBlocking();

    async function api(url, method = 'GET', body) {
        const multipart = body instanceof FormData;
        let response;
        try { response = await fetch(url, { method, credentials:'same-origin', headers:{ Accept:'application/json', ...(!multipart ? {'Content-Type':'application/json'} : {}), 'X-CSRF-TOKEN':csrf, 'X-Requested-With':'XMLHttpRequest' }, ...(body ? { body:multipart ? body : JSON.stringify(body) } : {}) }); }
        catch { throw new Error('Koneksi terputus. Periksa jaringan lalu coba lagi.'); }
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
            if(response.status===419){try{await syncSession();}catch{} }
            const error = new Error(response.status===419 ? 'Sesi diperbarui. Periksa isian lalu kirim kembali.' : response.status >= 500 ? 'Layanan belum tersedia. Silakan coba lagi sebentar.' : (Object.values(result.errors || {}).flat()[0] || result.message || 'Permintaan gagal. Silakan coba lagi.'));
            error.status = response.status; error.code = result.code; error.errors = result.errors; throw error;
        }
        if (result.csrf_token) csrf = result.csrf_token;
        return result;
    }
    let noticeTimer;
    function notice(message) { const box = $('#feed-notice'); box.querySelector('span').textContent = message; box.hidden = false; clearTimeout(noticeTimer); noticeTimer=setTimeout(()=>{box.hidden=true;},8000); }
    $('#feed-notice button').onclick = () => { $('#feed-notice').hidden = true; };
    function open(id,opener=document.activeElement) { if(opener?.disabled&&opener.matches('[data-action],[data-detail-action]')){opener.disabled=false;opener.setAttribute('aria-disabled','true');} if(id==='#feed-comments'){commentPanel.open(opener);return;} const dialog = $(id); openDialog(dialog,{opener}); if(id==='#feed-auth')requestAnimationFrame(()=>$('#auth-email').focus({preventScroll:true})); pauseAll(); }
    function close(id) { closeDialog($(id)); }
    function pauseAll(except) { app.querySelectorAll('video').forEach(v => { if (v !== except) v.pause(); }); }
    app.addEventListener('promerty:more',event=>{if(event.detail.modal)pauseAll();});
    app.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('close', () => { if (!blockingDialog()) playActive(); }));
    function gate(action, task, postId = null) {
        pending = task; pendingPostId = postId; setAuthMode('login'); setAuthView('credentials'); open('#feed-auth');
    }
    function requireAccount(action, task, postId) { if (!user) { gate(action,task,postId); return false; } return true; }
    async function syncSession() { const result = await api('/web-auth/me'); user = result.user; moreDrawer.setUser(user); $('#feed-account').textContent = user ? 'Keluar' : 'Masuk'; }
    async function recoverSession(action,task,postId){
        user=null;try{await syncSession();}catch{notice('Koneksi sesi belum tersedia. Coba masuk kembali.');}
        clearPrivateState();
        gate(action,task,postId);
    }
    const workspace = customerWorkspace({app,api,open,notice,escape,getUser:()=>user,gate,refresh:syncSession,recoverSession,openSettings:()=>{
        const url=new URL(location.href);if(url.searchParams.get('panel')==='settings'){url.searchParams.delete('panel');history.replaceState(null,'',url);}moreDrawer.open();
    },onSection:section=>{
        setNavigationActive(app,section);const url=new URL(location.href);if(url.searchParams.get('panel')!==section){url.searchParams.set('panel',section);history.pushState(null,'',url);}
    },onClose:()=>{
        if($('#customer-workspace').open)return;const url=new URL(location.href);url.searchParams.delete('panel');history.replaceState(null,'',url);setNavigationActive(app,mode);
    },interactionsChanged:async(kind,id)=>{
        if(kind==='follow')posts.forEach(p=>{if(p.agent.id===id){p.viewer.following=false;updatePost(p);}});
        if(kind==='comment'&&active){try{const p=(await api(`/api/posts/${active.dataset.id}`)).data;posts.set(p.id,p);updatePost(p);}catch{}}
    }});
    function clearPrivateState(){
        contactVersion++;pending=null;pendingPostId=null;reply=null;currentPost=null;
        workspace.clear();app.querySelectorAll('dialog[open]').forEach(dialog=>closeDialog(dialog,{immediate:true,restore:false}));
        $('#contact-history').replaceChildren();$('#download-links').replaceChildren();
        for(const id of ['#contact-form','#phone-form','#comment-form','#feed-auth-form'])$(id).reset();
        $('[data-auth-mode=login]').click();
    }
    const initialQuery = new URLSearchParams(location.search);
    const returnSearch=safeSearchReturn(initialQuery.get('from'),location.origin);
    for(const key of ['q','type','transaction','category','min_price','max_price','agent']) if(initialQuery.get(key)) filters[key]=initialQuery.get(key);
    if(initialQuery.get('view')==='saved'){mode='saved';filters.saved=1;}else if(initialQuery.get('view')==='following')mode='following';
    function rememberFilters() {
        const params = new URLSearchParams(filters); params.delete('saved'); if(mode!=='for-you')params.set('view',mode);
        const section=new URLSearchParams(location.search).get('panel');if(customerSections.includes(section))params.set('panel',section);
        history.replaceState(null,'',`${location.pathname}${params.size?'?'+params:''}`);
    }

    function state(title, message, retry = false) {
        const box = $('#feed-state'); box.hidden = false;
        box.innerHTML = emptyState({title,message,retry,signedIn:!!user,escape});
    }
    function updateNavigation() {
        if(!$('#customer-workspace').open)setNavigationActive(app,mode);
        const filterCount=Object.entries(filters).filter(([key,value])=>!['saved','agent'].includes(key)&&String(value).trim()!=='').length;
        $('#feed-filter-count').textContent=filterCount;$('#feed-filter-count').hidden=!filterCount;$('#feed-filter-count').setAttribute('aria-label',`${filterCount} filter aktif`);
        app.querySelectorAll('[data-feed-action="for-you"],[data-feed-action="following"],[data-feed-action="saved"]').forEach(button => {
            const selected = button.dataset.feedAction === mode;
            if (button.classList.contains('f-nav') || button.closest('.f-mobile-nav')) { if (selected) button.setAttribute('aria-current','page'); else button.removeAttribute('aria-current'); }
            else button.setAttribute('aria-pressed', String(selected));
        });
        $('#feed-context').textContent = filters.q ? `Pencarian: ${filters.q}` : filters.agent ? 'Properti Agent' : mode === 'saved' ? 'Properti yang Anda simpan' : mode === 'following' ? 'Pilihan dari koneksi Anda' : '';
    }
    async function load(reset = false) {
        if (loading && !reset) return;
        if (reset) {
            commentPanel.close();
            generation++; observer?.disconnect(); posts.clear(); ratios.clear(); active = null;
            app.querySelectorAll('.f-post').forEach(node => { node.querySelector('video')?.pause(); node.remove(); });
            next = null; scroller.scrollTop = 0; updateNavigation();
            rememberFilters();
            $('#feed-state').hidden = false; $('#feed-state').innerHTML = '<div class="p-skeleton p-skeleton--image" style="width:min(100%,380px)"></div><p>Memuat properti…</p>';
        }
        loading = true; const version = generation; $('#feed-more').hidden = true;
        const params = new URLSearchParams(filters); if (next) params.set('cursor',next);
        try {
            // Keep the cursor when a page contains private media that is unavailable on disk.
            for (let page=0;page<3;page++) {
                const result = await api(`/api/feed${mode === 'following' ? '/following' : ''}?${params}`);
                if (version !== generation) return;
                next = result.next_cursor;
                for (const post of result.data) append(post);
                if (result.data.length || !next) break;
                params.set('cursor',next);
            }
            if (!posts.size) state('Pilihan Anda belum ditemukan.', mode === 'following' ? 'Belum ada properti dari Agent yang Anda ikuti. Jelajahi Untuk Anda untuk menemukan koneksi baru.' : 'Belum ada properti terpublikasi yang cocok. Coba kawasan lain atau sesuaikan pilihan pencarian Anda.');
            else $('#feed-state').hidden = true;
            $('#feed-more').hidden = !next;
        } catch (error) {
            if (version !== generation) return;
            if (error.status === 401) { await recoverSession(mode,()=>load(true)); }
            if (!posts.size) state('Belum dapat memuat feed.', error.message, true);
            else { notice(error.message); $('#feed-more').hidden = false; }
        } finally { if (version === generation) loading = false; }
    }
    async function loadSelectedPost() {
        try {
            const result=await api(`/api/posts/${app.dataset.post}`);
            append(result.data);
            $('#feed-state').hidden=true;
            $('#feed-context').textContent='Properti yang dibagikan';
        } catch(error) {
            state('Properti tidak tersedia.',error.message,error.status!==404);
        }
    }
    function actionButton(action, name, text, pressed) {
        return `<button class="f-action" data-action="${action}" aria-label="${name}" title="${name}" ${pressed !== undefined ? `aria-pressed="${pressed}"` : ''}>${icon(action === 'like' ? 'heart' : action)}<span>${escape(text)}</span></button>`;
    }
    function append(post) {
        if (posts.has(post.id)) return;
        posts.set(post.id, post);
        const article = document.createElement('article'); article.className = 'f-post'; article.dataset.id = post.id; article.setAttribute('aria-label',post.listing.title);
        const media = post.media, isVideo = post.content_type === 'video', first = media[0];
        const avatar = post.agent.photo ? `<img class="p-avatar" src="${escape(post.agent.photo)}" alt="" loading="lazy">` : `<span class="p-avatar">${escape(post.agent.name.slice(0,2))}</span>`;
        const mediaHtml = !isVideo && media.length ? `<div class="f-carousel" tabindex="0" aria-label="Galeri foto, gunakan panah kiri dan kanan">${media.map((m,i)=>`<img src="${escape(m.url || '')}" alt="${escape(post.listing.title)} — foto ${i+1}" loading="lazy" draggable="false">`).join('')}</div>` : '';
        if (!isVideo) article.innerHTML = `<div class="f-player">${mediaHtml}<div class="f-media-top"><span class="f-counter">1 / ${media.length}</span></div><div class="f-media-bottom">${media.length>1 ? '<button class="f-media-control" data-action="previous" aria-label="Foto sebelumnya">←</button><button class="f-media-control" data-action="next" aria-label="Foto berikutnya">→</button>' : ''}</div><div class="f-media-status" role="status" ${first?.url ? 'hidden' : ''}>Media belum tersedia.</div></div>
        <div class="f-info"><div class="f-agent-line"><button data-action="agent" aria-label="Buka profil ${escape(post.agent.name)}">${avatar}</button><button class="f-agent-button" data-action="agent"><strong>${escape(post.agent.name)} ${post.agent.verified ? '✓' : ''}</strong><small>${escape(post.agent.agency || post.agent.branch || 'Agent Promerty')}${post.agent.verified ? ' · Terverifikasi' : ''}</small></button><button class="p-button p-button--secondary" data-action="follow" aria-pressed="${post.viewer.following}">${post.viewer.following ? 'Diikuti' : 'Ikuti'}</button></div><div class="p-row"><span class="p-badge">${post.listing.type === 'primary' ? 'Primary' : 'Secondary'}</span><span class="p-badge">${post.listing.transaction_type === 'rent' ? 'Disewakan' : 'Dijual'}</span><span class="p-muted text-xs">${escape(post.listing.category)}</span></div><h2>${escape(post.listing.title)}</h2><span class="p-price">${money(post.listing.price)}${post.listing.price_period === 'month' ? ' / bulan' : post.listing.price_period === 'year' ? ' / tahun' : ''}</span><p class="p-muted text-sm">${escape([post.listing.location?.label,post.listing.location?.city].filter(Boolean).join(', ') || 'Lokasi belum tersedia')}</p><p class="f-caption f-caption-preview">${escape(post.caption)}</p><div class="f-actions">${actionButton('like','Sukai properti',post.counts.likes,post.viewer.liked)}${actionButton('comment','Buka komentar',post.counts.comments)}${actionButton('save','Simpan properti',post.viewer.saved ? 'Tersimpan' : 'Simpan',post.viewer.saved)}${actionButton('share','Bagikan properti',post.counts.shares)}${actionButton('download','Download media','Unduh')}</div><div class="p-row"><button class="p-button p-button--primary" data-action="detail">Detail properti →</button><button class="p-button p-button--secondary" data-action="message">Hubungi Agent</button><button class="p-button p-button--ghost f-extra" data-action="survey">Jadwalkan survei</button></div></div>`;
        if(isVideo){article.classList.add('f-video-post');article.innerHTML=videoViewer(post,{escape,money,icon,avatar,actionButton,allowDownload:downloadSettings?.allow_video_download===true});}
        article.dataset.orientation=first?.orientation || 'unknown';
        if(!isVideo){article.append(article.querySelector('.f-actions'));article.querySelector('.f-player').append(article.querySelector('.f-info'));}
        scroller.insertBefore(article, $('#feed-sentinel'));
        bindMedia(article); observer.observe(article);
    }
    function bindMedia(article) {
        const video = article.querySelector('video'), status = article.querySelector('.f-media-status');
        if (video) {
            video.muted = muted;
            video.addEventListener('loadedmetadata',()=>{article.dataset.orientation=videoOrientation(video.videoWidth,video.videoHeight);});
            bindVideoControls(article,video,icon);
            bindVideoBuffer(article,video,status);
            video.addEventListener('click',()=>toggleVideo(video));
            video.addEventListener('keydown',event=>{ if ([' ','Enter'].includes(event.key)) { event.preventDefault(); toggleVideo(video); } });
            video.addEventListener('play',()=>pauseAll(video));
        }
        article.querySelectorAll('.f-carousel img').forEach(img=>img.addEventListener('error',()=>{ img.hidden=true; status.innerHTML='Foto tidak dapat dimuat. <button class="f-media-control" data-action="retry-photo">Coba lagi</button>'; status.hidden=false; }));
        const carousel = article.querySelector('.f-carousel');
        if (carousel) {
            carousel.addEventListener('scroll',()=>{ article.querySelector('.f-counter').textContent=`${Math.round(carousel.scrollLeft/carousel.clientWidth)+1} / ${carousel.children.length}`; },{passive:true});
            carousel.addEventListener('keydown',event=>{ if (['ArrowLeft','ArrowRight'].includes(event.key)) { event.preventDefault(); event.stopPropagation(); slide(carousel,event.key==='ArrowRight'?1:-1); } });
            let start;
            carousel.addEventListener('pointerdown',event=>{ if(event.pointerType==='mouse') { start={x:event.clientX,scroll:carousel.scrollLeft}; carousel.setPointerCapture(event.pointerId); } });
            carousel.addEventListener('pointermove',event=>{ if(start) carousel.scrollLeft=start.scroll+start.x-event.clientX; });
            carousel.addEventListener('pointerup',()=>{ if(start) { carousel.scrollTo({left:Math.round(carousel.scrollLeft/carousel.clientWidth)*carousel.clientWidth,behavior:reduced.matches?'instant':'smooth'}); start=null; } });
            carousel.addEventListener('pointercancel',()=>{ start=null; });
        }
    }
    function slide(carousel, direction) { carousel?.scrollBy({left:direction*carousel.clientWidth,behavior:reduced.matches?'instant':'smooth'}); }
    function toggleVideo(video) { video.dataset.userPaused=video.paused?'false':'true'; if(video.paused) { pauseAll(video); video.play().catch(()=>notice('Tekan Putar untuk memulai video.')); } else video.pause(); }
    function playActive() {
        coordinatePlayback({articles:app.querySelectorAll('.f-post'),active,muted,
            blocked:document.hidden || !!blockingDialog(),reducedMotion:reduced.matches,
            onBlocked:()=>{if(active&&!active.querySelector('video')?.error){const s=active.querySelector('.f-media-status');s.textContent='Tekan Putar untuk memulai video.';s.hidden=false;}}});
    }
    observer = new IntersectionObserver(entries=>{
        for(const entry of entries) ratios.set(entry.target,entry.intersectionRatio);
        const dominant=[...ratios].filter(([node])=>node.isConnected).sort((a,b)=>b[1]-a[1])[0];
        if(dominant?.[1] >= .55) { if(active!==dominant[0]) { active=dominant[0]; playActive();
            const visible=active;setTimeout(()=>{if(active===visible&&!document.hidden&&!blockingDialog())api(`/api/posts/${visible.dataset.id}/views`,'POST',{watch_duration_seconds:3}).catch(()=>{});},3200);
        } }
        else { active=null; pauseAll(); }
    },{root:scroller,threshold:[0,.25,.55,.75,1]});
    moreObserver = new IntersectionObserver(entries=>{ if(entries.some(e=>e.isIntersecting)&&next&&!loading) load(); },{root:scroller,rootMargin:'250px'});
    moreObserver.observe($('#feed-sentinel')); $('#feed-more').onclick=()=>load();
    document.addEventListener('visibilitychange',()=>document.hidden?pauseAll():playActive());
    scroller.addEventListener('keydown',event=>{
        if(event.target.matches('input,textarea,button,select,video')||!['ArrowDown','ArrowUp','PageDown','PageUp'].includes(event.key)) return;
        event.preventDefault(); scroller.scrollBy({top:scroller.clientHeight*(['ArrowDown','PageDown'].includes(event.key)?1:-1),behavior:reduced.matches?'instant':'smooth'});
    });
    function updatePost(post) {
        const node=app.querySelector(`.f-post[data-id="${post.id}"]`); if(!node) return;
        for(const [key,flag,label] of [['like','liked',post.counts.likes],['save','saved',post.viewer.saved?'Tersimpan':'Simpan'],['follow','following',post.viewer.following?'Diikuti':'Ikuti']]) {
            const b=node.querySelector(`[data-action=${key}]`); b.setAttribute('aria-pressed',String(post.viewer[flag]));
            if(key==='follow') b.textContent=label; else b.querySelector('span').textContent=label;
        }
        node.querySelector('[data-action=comment] span').textContent=post.counts.comments;
        node.querySelector('[data-action=share] span').textContent=post.counts.shares;
    }
    async function handle(action, id, button) {
        if(button?.getAttribute('aria-disabled')==='true')return;
        const post=posts.get(Number(id)); if(!post) return;
        const article=app.querySelector(`.f-post[data-id="${post.id}"]`);
        const trigger=button||article.querySelector(`[data-action="${action}"]`)||document.activeElement;
        if(['like','save','comment','follow','message','survey'].includes(action)) {
            await initialSession;
            const returnPost = ['like','save','comment','follow'].includes(action) ? post.id : null;
            if(!requireAccount(action,()=>handle(action,id),returnPost))return;
        }
        if(button) button.disabled=true;
        try {
            if(['like','save','follow'].includes(action)) {
                const flag={like:'liked',save:'saved',follow:'following'}[action];
                const result=await api(action==='follow'?`/api/agents/${post.agent.id}/follow`:`/api/posts/${id}/${action}`,post.viewer[flag]?'DELETE':'POST');
                post.viewer[flag]=result.active; if(action==='like') post.counts.likes=result.count;
                if(action==='follow') posts.forEach(p=>{if(p.agent.id===post.agent.id){p.viewer.following=result.active;updatePost(p);}});
                updatePost(post);
                if(action==='save'&&button?.dataset.detailAction==='save'){button.textContent=post.viewer.saved?'Hapus dari tersimpan':'Simpan properti';button.setAttribute('aria-pressed',String(post.viewer.saved));}
                if(action==='save'&&mode==='saved'&&!post.viewer.saved){close('#feed-detail');await load(true);}
            } else if(action==='detail') {
                currentPost=post;
                open('#feed-detail',trigger);
                $('#detail-body').innerHTML=propertyDetail(post,escape,money);
            } else if(action==='agent') await showAgent(post.agent.id,post,trigger);
            else if(action==='comment'||action==='read-comments') { if(action==='read-comments')close('#feed-detail'); commentPost=post;$('#comment-form').hidden=!user;$('[data-comment-login]').hidden=!!user;$('#comments-count').textContent=post.counts.comments;$('#comment-form [data-error]').textContent=''; reply=null;commentPage=1;$('#comments-list').replaceChildren();$('#comment-form').reset();$('#reply-label').textContent='';$('#cancel-reply').hidden=true;open('#feed-comments',trigger);await comments(); }
            else if(action==='message'||action==='survey') {
                currentPost=post;contactMode=action;
                if(user.role==='customer'&&!user.phone_verified){pending=()=>handle(action,id);open('#feed-phone',trigger);return;}
                contactVersion++;
                $('#contact-title').textContent=action==='survey'?'Jadwalkan survei':'Hubungi Agent';$('#survey-date-label').hidden=action!=='survey';$('#contact-form').elements.requested_at.required=action==='survey';$('#contact-form').reset();$('#contact-form [data-error]').textContent='';$('#contact-history').replaceChildren();$('#contact-more').hidden=true;messagePage=1;open('#feed-contact',trigger);
                $('#contact-form').elements.body.required=action!=='survey';$('#contact-form').elements.body.maxLength=action==='survey'?2000:4000;
                if(action==='message') await messages();
            } else if(action==='share') {
                let platform='copy';
                if(navigator.share) { try { await navigator.share({title:post.listing.title,url:post.url}); platform='web_share'; } catch(error) { if(error.name==='AbortError') return; await copyLink(post.url,trigger); } }
                else await copyLink(post.url,trigger);
                await api(`/api/posts/${id}/shares`,'POST',{platform});post.counts.shares++;updatePost(post);
            } else if(action==='download') {
                const key=post.content_type==='video'?'allow_video_download':'allow_photo_download';
                if(downloadSettings===null)downloadSettings=(await api('/api/feed/config')).downloads;
                if(!downloadSettings[key]) { notice('Download media ini dinonaktifkan.');return; }
                const files=post.media.filter(m=>post.content_type==='video' ? m.downloadable : !externalVideo(m));
                if(!files.length){notice('Video eksternal tersedia melalui penyedianya.');return;}
                const max=Math.max(1,Math.min(15,Number(downloadSettings.max_download_items_per_session)||15));
                currentPost=post;$('#download-links').replaceChildren();$('#download-form [data-error]').textContent='';$('#download-items').innerHTML=files.map((m,i)=>`<label class="p-row"><input type="checkbox" name="media_ids" value="${m.id}" ${i<max?'checked':''}> ${post.content_type==='video'?'Video asli':`Foto ${i+1}`}</label>`).join('');open('#feed-download',trigger);
            } else if(action==='play') toggleVideo(article.querySelector('video'));
            else if(action==='mute') {
                muted=!muted;try{sessionStorage.setItem('promerty.feed.muted',String(muted));}catch{}
                app.querySelectorAll('video').forEach(v=>{v.muted=muted;});
            } else if(action==='previous'||action==='next') slide(article.querySelector('.f-carousel'),action==='next'?1:-1);
            else if(action==='retry-media') {
                const v=article.querySelector('video'), status=article.querySelector('.f-media-status');
                const time=v.currentTime;
                status.hidden=true;
                v.dataset.userPaused='false';
                v.src=v.dataset.src;
                v.load();
                v.addEventListener('loadedmetadata',()=>{if(time>0&&time<v.duration)v.currentTime=time;},{once:true});
                v.play().catch(()=>{if(v.error){status.hidden=false;}});
            }
            else if(action==='retry-photo') {article.querySelector('.f-media-status').hidden=true;article.querySelectorAll('.f-carousel img').forEach(img=>{img.hidden=false;img.src=img.src;});}
        } catch(error) {
            const personal = ['like','save','comment','follow','message','survey'].includes(action);
            if(error.status===401||(personal&&error.status===419&&!user)){await recoverSession(action,()=>handle(action,id),post.id);}
            else if(error.code==='PHONE_VERIFICATION_REQUIRED'){user.phone_verified=false;pending=()=>handle(action,id);open('#feed-phone',trigger);}
            else notice(error.message);
        }
        finally {if(button){button.disabled=false;button.removeAttribute('aria-disabled');}}
    }
    async function showAgent(id,post=null,trigger=document.activeElement) {
        const {data:agent}=await api(`/api/agents/${id}`);
        $('#agent-body').innerHTML=`<span class="p-avatar">${escape(agent.name.slice(0,2))}</span><h3>${escape(agent.name)}</h3><span class="p-badge">${agent.verified?'Agent terverifikasi':'Agent'}</span><p class="p-muted">${escape([agent.agency,agent.branch].filter(Boolean).join(' · '))}</p><p class="f-caption">${escape(agent.bio||'Profil Agent Promerty.')}</p><a class="p-button p-button--primary" href="/agents/${id}">Lihat semua properti</a>`;open('#feed-agent',trigger);
        if(agent.photo) {const photo=document.createElement('img');photo.className='p-avatar';photo.src=agent.photo;photo.alt=`Foto ${agent.name}`;$('#agent-body .p-avatar').replaceWith(photo);}
        const follow=document.createElement('button');follow.className='p-button';follow.textContent=agent.following?'Berhenti mengikuti':'Ikuti Agent';follow.setAttribute('aria-pressed',String(agent.following));
        follow.onclick=async()=>{
            const task=async()=>{
                follow.disabled=true;
                try {
                    const {data:latest}=await api(`/api/agents/${id}`);
                    const result=await api(`/api/agents/${id}/follow`,latest.following?'DELETE':'POST');
                    follow.textContent=result.active?'Berhenti mengikuti':'Ikuti Agent';
                    follow.setAttribute('aria-pressed',String(result.active));
                    posts.forEach(p=>{if(p.agent.id===Number(id)){p.viewer.following=result.active;updatePost(p);}});
                } catch(error) {
                    if(error.status===401||(error.status===419&&!user))await recoverSession('follow',task,post?.id);
                    else notice(error.message);
                } finally {follow.disabled=false;}
            };
            await initialSession;
            if(requireAccount('follow',task,post?.id))await task();
        };$('#agent-body').append(follow);
        if(post){const message=document.createElement('button');message.className='p-button p-button--secondary';message.textContent='Pesan tentang properti ini';message.onclick=()=>{close('#feed-agent');handle('message',post.id);};$('#agent-body').append(message);}
        else {const hint=document.createElement('p');hint.className='c-footnote';hint.textContent='Pilih properti Agent untuk memulai pesan atau permintaan survei.';$('#agent-body').append(hint);}
    }
    async function copyLink(url,trigger=document.activeElement) {
        try{await navigator.clipboard.writeText(url);notice('Link properti disalin.');}
        catch{$('#share-link').value=url;open('#feed-link',trigger);$('#share-link').select();}
    }
    app.addEventListener('click',async event=>{
        const button=event.target.closest('button');if(!button)return;
        if(button.dataset.action){await handle(button.dataset.action,button.closest('.f-post').dataset.id,button);return;}
        if(button.dataset.detailAction){await handle(button.dataset.detailAction,currentPost.id,button);return;}
        const action=button.dataset.feedAction;
        if (['account','following','saved',...customerSections].includes(action)) await initialSession;
        if(action==='account'&&user&&$('#customer-workspace').open&&!await allowDialogChange($('#customer-workspace')))return;
        if((app.dataset.post||app.dataset.agent)&&['for-you','following','saved'].includes(action)){location.assign(action==='for-you'?'/':'/?view='+action);return;}
        if(action==='profile'){
            const workspaceDialog=$('#customer-workspace');
            if(workspaceDialog.open&&!await allowDialogChange(workspaceDialog))return;
            const showProfile=()=>location.assign(user?.role==='agent'?'/agents/'+user.id:'/account/profile');
            if(requireAccount(action,showProfile))showProfile();
            return;
        }
        if(['messages','activity','settings','surveys','notifications','connections'].includes(action)){
            workspace.show(action);return;
        }
        if(action==='search'){location.assign(customerSearchUrl());return;}
        if(action==='retry'){if(app.dataset.post)await loadSelectedPost();else load(true);}
        if(action==='account'){if(user){try{const hadWorkspace=user.role!=='customer';await api('/web-auth/logout','POST');user=null;moreDrawer.setUser(null);clearPrivateState();$('#feed-account').textContent='Masuk';mode='for-you';delete filters.saved;if(returnSearch||hadWorkspace){location.assign('/');return;}await load(true);}catch(e){notice(e.message);}}else gate('account',null);}
        if(action==='for-you'||action==='following'||action==='saved'){
            const task=()=>{const url=new URL(location.href);url.searchParams.delete('panel');if(action==='for-you')url.searchParams.delete('view');else url.searchParams.set('view',action);if(url.href!==location.href)history.pushState(null,'',url);workspace.close();mode=action;delete filters.agent;delete filters.saved;if(action==='saved')filters.saved=1;load(true);};
            if(['following','saved'].includes(action)&&!requireAccount(action,task))return;task();
        }
    });
    $('#feed-search-form').onsubmit=event=>{event.preventDefault();const f=event.target;const min=f.elements.min_price,max=f.elements.max_price;max.setCustomValidity(min.value!==''&&max.value!==''&&Number(min.value)>Number(max.value)?'Harga maksimum harus sama atau lebih besar dari minimum.':'');if(!f.reportValidity())return;filters=Object.fromEntries([...new FormData(f)].filter(([,value])=>value!==''));mode='for-you';$('#sidebar-search').elements.q.value=filters.q||'';close('#feed-search');load(true);};
    for(const el of [$('#feed-search-form').elements.min_price,$('#feed-search-form').elements.max_price])el.oninput=()=>$('#feed-search-form').elements.max_price.setCustomValidity('');
    $('#feed-search-form').onreset=()=>$('#feed-search-form').elements.max_price.setCustomValidity('');
    function setAuthMode(next){
        authMode=next;
        const registering=authMode==='register',form=$('#feed-auth-form');
        app.querySelectorAll('[data-register]').forEach(label=>{label.hidden=!registering;label.querySelector('input').required=registering;});
        form.elements.password_confirmation.required=registering;
        form.elements.password_confirmation.minLength=Number(form.dataset.passwordMin);
        form.elements.password.autocomplete=registering?'new-password':'current-password';
        form.elements.password.minLength=registering?Number(form.dataset.passwordMin):1;
        syncPasswordPolicy(form,registering);
        form.querySelector('button[type=submit]').textContent=registering?'Daftar sebagai Customer':'Masuk';
        $('#auth-title').textContent=registering?'Daftar Ke Promerty':'Masuk Ke Promerty';
        app.querySelectorAll('[data-auth-login-only]').forEach(node=>node.hidden=registering);
        $('[data-auth-prompt="login"]').hidden=registering;
        $('[data-auth-prompt="register"]').hidden=!registering;
    }
    function setAuthView(view){
        authView=view;
        $('[data-auth-view="credentials"]').hidden=view!=='credentials';
        $('[data-auth-view="forgot"]').hidden=view!=='forgot';
        $('#auth-title').textContent=view==='forgot'?'Lupa password?':authMode==='register'?'Daftar Ke Promerty':'Masuk Ke Promerty';
        if(view==='forgot')$('#auth-forgot-email').value=$('#auth-email').value.trim();
        requestAnimationFrame(()=>$(view==='forgot'?'#auth-forgot-email':'#auth-email').focus({preventScroll:true}));
    }
    app.querySelectorAll('[data-auth-mode]').forEach(button=>button.onclick=()=>{setAuthMode(button.dataset.authMode);setAuthView('credentials');});
    $('[data-forgot-password]').onclick=()=>setAuthView('forgot');
    $('[data-auth-back]').onclick=()=>setAuthView('credentials');
    $('[data-password-toggle]').onclick=event=>{
        const button=event.currentTarget,input=$('#auth-password'),visible=input.type==='password';
        input.type=visible?'text':'password';button.setAttribute('aria-label',visible?'Sembunyikan password':'Tampilkan password');button.setAttribute('aria-pressed',String(visible));
        input.focus({preventScroll:true});
    };
    $('#feed-auth').addEventListener('close',()=>{
        pending=null;pendingPostId=null;
        setAuthMode('login');authView='credentials';$('[data-auth-view="credentials"]').hidden=false;$('[data-auth-view="forgot"]').hidden=true;$('#feed-auth-form').reset();$('#feed-forgot-form').reset();
        app.querySelectorAll('#feed-auth [aria-invalid]').forEach(field=>field.removeAttribute('aria-invalid'));
        app.querySelectorAll('#feed-auth [data-error],#feed-auth [data-status]').forEach(node=>node.textContent='');
        const password=$('#auth-password');password.type='password';$('[data-password-toggle]').setAttribute('aria-label','Tampilkan password');$('[data-password-toggle]').setAttribute('aria-pressed','false');
    });
    async function submit(form, task) {
        if(form.getAttribute('aria-busy')==='true')return;
        form.setAttribute('aria-busy','true');
        const button=form.querySelector('button[type=submit]')||form.querySelector('button:last-child');button.disabled=true;const error=form.querySelector('[data-error]');if(error)error.textContent='';
        form.querySelectorAll('[aria-invalid]').forEach(field=>field.removeAttribute('aria-invalid'));
        try{await task(Object.fromEntries(new FormData(form)));markDialogClean(form.closest('dialog'));}catch(e){if(error)error.textContent=e.message;else notice(e.message);for(const name of Object.keys(e.errors||{}))form.elements[name]?.setAttribute('aria-invalid','true');if(e.status===419){await syncSession().catch(()=>{});if(error)error.textContent='Sesi diperbarui. Silakan kirim kembali.';}}finally{button.disabled=false;form.removeAttribute('aria-busy');}
    }
    bindPasswordPolicy($('#feed-auth-form'),()=>authMode==='register');
    $('#feed-auth-form').onsubmit=event=>{if(event.defaultPrevented)return;event.preventDefault();submit(event.target,async data=>{
        if(pendingPostId) data.return_to='/posts/'+pendingPostId;
        const result=await api(`/web-auth/${authMode}`,'POST',data);
        if(result.verification_url){event.target.reset();location.assign(result.verification_url);return;}
        user=result.user;moreDrawer.setUser(user);$('#feed-account').textContent='Keluar';event.target.reset();
        const task=pending,postId=pendingPostId ?? Number(active?.dataset.id);
        if(!pendingPostId && user.role!=='customer'&&result.redirect_to){pending=null;location.assign(result.redirect_to);return;}
        pending=null;pendingPostId=null;close('#feed-auth');
        if(postId && posts.has(postId)){
            const fresh=(await api('/api/posts/'+postId)).data;
            posts.set(postId,fresh);updatePost(fresh);
        }
        if(task)await task();
    });};
    $('#feed-forgot-form').onsubmit=event=>{event.preventDefault();submit(event.target,async data=>{
        const result=await api('/web-auth/password/forgot','POST',data);event.target.querySelector('[data-status]').textContent=result.message;
    });};
    async function comments(parentId=null,target=$('#comments-list'),page=commentPage){
        const post=commentPost;
        const result=await api(`/api/posts/${post.id}/comments?page=${page}${parentId?`&parent_id=${parentId}`:''}`);
        if(post!==commentPost||!target.isConnected)return false;
        for(const c of result.data){
            const node=document.createElement('div');node.className='f-comment';
            const avatar=c.user.photo?`<img class="p-avatar" src="${escape(c.user.photo)}" alt="" loading="lazy">`:`<span class="p-avatar">${escape(c.user.name.slice(0,2))}</span>`;
            const date=c.created_at?new Date(c.created_at).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}):'';
            node.innerHTML=`<div class="f-comment-author">${avatar}<strong>${escape(c.user.username||c.user.name)}</strong></div><p>${escape(c.content)}</p><time datetime="${escape(c.created_at||'')}">${escape(date)}</time>`;
            if(!parentId){
                const b=document.createElement('button');b.className='p-button p-button--ghost';b.textContent='Balas';
                b.onclick=()=>{const task=()=>{reply=c.id;$('#reply-label').textContent=`Membalas ${c.user.name}`;$('#cancel-reply').hidden=false;$('#comment-form textarea').focus();};if(requireAccount('comment',task,post.id))task();};
                node.append(b);const replies=document.createElement('div');const view=document.createElement('button');view.className='p-button p-button--ghost';view.textContent='Lihat balasan';let replyPage=1;
                view.onclick=async()=>{view.disabled=true;try{const more=await comments(c.id,replies,replyPage);replyPage++;view.hidden=!more;view.textContent='Balasan berikutnya';}catch(e){notice(e.message);}finally{view.disabled=false;}};node.append(view,replies);
            }target.append(node);
        }
        if(!result.data.length&&page===1)target.textContent=parentId?'Belum ada balasan.':'Jadilah yang pertama memulai percakapan.';
        if(!parentId)$('#comments-more').hidden=!result.has_more;return result.has_more;
    }
    $('#comments-more').onclick=async()=>{const button=$('#comments-more');button.disabled=true;try{await comments(null,$('#comments-list'),commentPage+1);commentPage++;}catch(e){notice(e.message);}finally{button.disabled=false;}};
    $('#cancel-reply').onclick=()=>{reply=null;$('#reply-label').textContent='';$('#cancel-reply').hidden=true;};
    $('[data-comment-login]').onclick=()=>{const post=commentPost;if(post)gate('comment',()=>handle('comment',post.id),post.id);};
    $('#comment-form').onsubmit=event=>{event.preventDefault();const post=commentPost;
        if(!post||!requireAccount('comment',()=>handle('comment',post.id),post.id))return;
        submit(event.target,async data=>{
            try { await api('/api/posts/'+post.id+'/comments','POST',{content:data.content,parent_id:reply}); }
            catch(error) {
                if(error.status===401||(error.status===419&&!user)){
                    await recoverSession('comment',async()=>{
                        await handle('comment',post.id);
                        $('#comment-form textarea').value=data.content;
                        $('#comment-form textarea').focus();
                    },post.id);
                    return;
                }
                throw error;
            }
            post.counts.comments++;updatePost(post);
            if(post!==commentPost)return;
            $('#comments-count').textContent=post.counts.comments;event.target.reset();reply=null;$('#reply-label').textContent='';$('#cancel-reply').hidden=true;commentPage=1;$('#comments-list').replaceChildren();await comments();notice('Komentar terkirim.');
        });
    };
    async function messages(page=messagePage){
        const listing=currentPost.listing.id,version=contactVersion,history=$('#contact-history');history.setAttribute('aria-busy','true');
        try{
            const result=await api(`/api/listings/${listing}/messages?page=${page}`);
            if(currentPost?.listing.id!==listing||contactMode!=='message'||version!==contactVersion)return;
            const previousHeight=history.scrollHeight,fragment=document.createDocumentFragment();
            for(const m of [...result.data].reverse()){const p=document.createElement('p');p.className=`p-chat ${m.mine?'p-chat--outgoing':''}`;p.textContent=m.body;fragment.append(p);}
            if(page===1){history.append(fragment);history.scrollTop=history.scrollHeight;}else{history.prepend(fragment);history.scrollTop+=history.scrollHeight-previousHeight;}
            messagePage=page;$('#contact-more').hidden=!result.has_more;
        }finally{history.setAttribute('aria-busy','false');}
    }
    $('#contact-more').onclick=async()=>{const b=$('#contact-more');b.disabled=true;try{await messages(messagePage+1);}catch(e){notice(e.message);}finally{b.disabled=false;}};
    $('#contact-form').onsubmit=event=>{event.preventDefault();submit(event.target,async data=>{
        const version=contactVersion,survey=contactMode==='survey';await api(`/api/listings/${currentPost.listing.id}/${survey?'surveys':'messages'}`,'POST',survey?{requested_at:new Date(data.requested_at).toISOString(),note:data.body}:{body:data.body});if(version!==contactVersion)return;event.target.reset();if(survey){close('#feed-contact');notice('Permintaan survei terkirim. Agent akan mengonfirmasi jadwal.');}else{messagePage=1;$('#contact-history').replaceChildren();await messages();}
    });};
    $('#phone-send').onclick=async()=>{const b=$('#phone-send'),form=$('#phone-form');if(b.disabled||form.getAttribute('aria-busy')==='true')return;b.disabled=true;form.setAttribute('aria-busy','true');try{const r=await api('/api/feed/phone/otp','POST',{phone:form.elements.phone.value});form.querySelector('[data-error]').textContent=r.message;}catch(e){form.querySelector('[data-error]').textContent=e.message;}finally{b.disabled=false;form.removeAttribute('aria-busy');}};
    $('#phone-form').onsubmit=event=>{event.preventDefault();submit(event.target,async data=>{await api('/api/feed/phone/verify','POST',data);user.phone_verified=true;close('#feed-phone');const task=pending;pending=null;if(task)await task();});};
    $('#download-form').onsubmit=event=>{event.preventDefault();submit(event.target,async()=>{const ids=[...new FormData(event.target).getAll('media_ids')].map(Number);const result=await api(`/api/posts/${currentPost.id}/downloads`,'POST',{media_ids:ids});$('#download-links').innerHTML='<p class="p-muted">Pilih tiap file untuk menyimpan. Link berlaku 5 menit.</p>'+result.files.map((file,i)=>`<a class="p-button p-button--secondary" href="${escape(file.url)}" target="_blank" rel="noopener" download>Download ${i+1}</a>`).join('');});};

    window.addEventListener('popstate',async()=>{
        await initialSession;
        const params=new URLSearchParams(location.search),section=params.get('panel');
        if(customerSections.includes(section)){workspace.show(section);return;}
        workspace.close();
        if(app.dataset.post){if(!posts.has(Number(app.dataset.post)))await loadSelectedPost();return;}
        mode=['following','saved'].includes(params.get('view'))?params.get('view'):'for-you';filters={};
        for(const key of ['q','type','transaction','category','min_price','max_price','agent'])if(params.get(key))filters[key]=params.get(key);
        if(mode==='saved')filters.saved=1;load(true);
    });
    const initialSession = syncSession().catch(()=>notice('Status akun belum dapat dimuat. Anda tetap dapat menjelajah.'));
    (async()=>{
        await initialSession;
        if(app.dataset.googleMessage){if(!user){setAuthMode('login');setAuthView('credentials');open('#feed-auth');$('#feed-auth-form [data-error]').textContent=app.dataset.googleMessage;}else notice(app.dataset.googleMessage);}
        try{const config=await api('/api/feed/config');downloadSettings=config.downloads;app.searchCategories=config.categories;for(const c of config.categories){const o=document.createElement('option');o.value=c.id;o.textContent=c.name;$('#feed-category').append(o);}}catch{}
        for(const [key,value] of Object.entries(filters))if($('#feed-search-form').elements[key])$('#feed-search-form').elements[key].value=value;
        $('#sidebar-search').elements.q.value=filters.q||'';
        if(app.dataset.agent){filters.agent=app.dataset.agent;try{await showAgent(app.dataset.agent);}catch(e){notice(e.message);}}
        if(app.dataset.post)await loadSelectedPost();
        else await load(true);
        if(customerSections.includes(initialQuery.get('panel')))workspace.show(initialQuery.get('panel'));
    })();
}
