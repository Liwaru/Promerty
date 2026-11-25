import {closeDialog,allowDialogChange,markDialogClean} from './dialogs.js';
import {unreadNotificationsOnly} from './notification-preference.js';
// Customer workspace. Data is paginated and owned by the authenticated customer.
export function customerWorkspace({ app, api, open, notice, escape: e, getUser, gate, refresh, recoverSession, interactionsChanged, openSettings=()=>{}, onSection=()=>{}, onClose=()=>{} }) {
    const dialog = app.querySelector('#customer-workspace');
    const body = dialog.querySelector('[data-customer-body]');
    const title = dialog.querySelector('h2');
    const labels = {profile:'Ruang pribadi Anda',messages:'Percakapan',surveys:'Rencana kunjungan',activity:'Jejak penelusuran',notifications:'Pemberitahuan',connections:'Agen yang Anda ikuti',settings:'Atur pengalaman Anda'};
    let generation = 0;
    const button = (label, action, extra='') => `<button class="p-button p-button--ghost" data-customer="${action}" ${extra}>${label}</button>`;
    const date = value => value ? new Date(value).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}) : '';
    const status = {pending:'Menunggu konfirmasi',confirmed:'Dikonfirmasi',completed:'Selesai',cancelled:'Dibatalkan',rejected:'Ditolak',rescheduled:'Jadwal diusulkan'};

    async function show(section, options={}) {
        if(section==='settings'){openSettings();return;}
        if(dialog.open&&!await allowDialogChange(dialog))return;
        if (section !== 'settings' && !getUser()) { gate(section,()=>show(section, options)); return; }
        const user = getUser(), agent = user.role === 'agent';
        if (section === 'profile' && !['customer','agent'].includes(user.role)) { location.assign('/account/profile'); return; }
        if (section !== 'activity' && user.role !== 'customer' && (!agent || !['profile','messages','surveys'].includes(section))) {
            notice('Menu ini tidak tersedia untuk akun Anda.'); return;
        }
        const base = section === 'activity' ? '/api/social' : agent ? '/api/personal' : '/api/customer';
        const incoming = options.scope === 'incoming' && (section === 'messages' || user.incoming_surveys);
        const version = ++generation;
        title.textContent = labels[section] || labels.profile;
        body.innerHTML = '<p role="status">Menyiapkan ruang Anda…</p>';
        open('#customer-workspace');
        onSection(section);
        title.tabIndex=-1; title.focus({preventScroll:true});
        const current = () => version === generation && dialog.open;
        try {
            if (section === 'profile') {
                const {data:u} = await api(base+'/profile'); if (!current()) return;
                body.innerHTML = `<div class="c-profile-heading">${u.photo?`<img class="p-avatar" src="${e(u.photo)}" alt="Foto profil Anda">`:''}<div><span class="p-eyebrow p-muted">${agent?'Agent Promerty':'Customer Promerty'}</span><h3>${e(u.name)}</h3></div></div><form class="p-stack" data-profile-form><label class="p-field">Nama lengkap<input class="p-input" name="name" value="${e(u.name)}" maxlength="150" autocomplete="name" required></label><label class="p-field">Username<input class="p-input" name="username" value="${e(u.username)}" maxlength="50" autocomplete="username" pattern="[A-Za-z0-9_.]+"><small class="p-muted">Huruf, angka, titik, dan garis bawah.</small></label><label class="p-field">Tentang Anda<textarea class="p-textarea" name="bio" maxlength="1000">${e(u.bio)}</textarea></label><label class="p-field">Foto profil<input class="p-input" name="photo" type="file" accept="image/jpeg,image/png,image/webp"><small class="p-muted">JPG, PNG, atau WebP · maksimal 2 MB, 4096 × 4096 piksel.</small></label><p data-error class="p-field-error" role="alert"></p><button class="p-button p-button--primary" type="submit">Simpan profil</button></form><p class="c-footnote">${u.phone_verified?'Nomor HP terverifikasi.':'Nomor HP belum terverifikasi. Verifikasi diperlukan untuk pesan dan survei.'} Perubahan email, nomor HP, dan password memerlukan alur keamanan tersendiri.</p><nav class="c-links" aria-label="Akun Anda">${(agent?['messages','surveys','activity']:['messages','surveys','connections','notifications','activity']).map(k=>button(labels[k],k)).join('')}<button class="p-button p-button--ghost" data-feed-action="saved">Properti tersimpan</button><button class="p-button p-button--ghost" data-feed-action="account">Keluar dari akun</button></nav>`;
                body.querySelector('nav').insertAdjacentHTML('afterbegin','<a class="p-button p-button--ghost" href="/account/profile#connected-accounts">Keamanan Akun</a>');
                body.querySelector('form').onsubmit = async event => {
                    event.preventDefault(); const form = event.target, submit = form.querySelector('[type=submit]'); if(submit.disabled)return; submit.disabled = true;
                    form.querySelector('[data-error]').textContent = ''; form.querySelectorAll('[aria-invalid]').forEach(i=>i.removeAttribute('aria-invalid'));
                    try {
                        const data = new FormData(form); if (!data.get('photo')?.size) data.delete('photo');
                        const result=await api(base+'/profile','POST',data); await refresh();
                        if(current()){
                            const heading=body.querySelector('.c-profile-heading');heading.querySelector('h3').textContent=result.data.name;
                            if(result.data.photo){let image=heading.querySelector('img');if(!image){image=document.createElement('img');image.className='p-avatar';image.alt='Foto profil Anda';heading.prepend(image);}image.src=result.data.photo;}
                            form.elements.photo.value='';
                        }
                        markDialogClean(dialog);notice('Profil Anda berhasil disimpan.');
                    }
                    catch(err) { if(err.status===401){await recoverSession('profile',()=>show('profile'));return;} form.querySelector('[data-error]').textContent = err.message; for(const name of Object.keys(err.errors || {})) form.elements[name]?.setAttribute('aria-invalid','true'); }
                    finally { submit.disabled = false; }
                }; return;
            }
            const thread = section === 'messages' && options.conversation;
            const kind = options.type || 'likes';
            const unreadOnly=section==='notifications' && unreadNotificationsOnly();
            const scopeOptions = agent && !thread && (section === 'messages' || (section === 'surveys' && user.incoming_surveys))
                ? '<nav class="p-row" aria-label="Jenis '+(section==='messages'?'percakapan':'survei')+'">'
                    + button(section==='messages'?'Saya mulai':'Survei pribadi',section,`data-scope="started" aria-pressed="${!incoming}"`)
                    + button(section==='messages'?'Pesan masuk':'Survei listing saya',section,`data-scope="incoming" aria-pressed="${incoming}"`) + '</nav>'
                : '';
            const endpoint = thread ? `conversations/${options.conversation}/messages` : ({messages:agent?'conversations?scope='+(incoming?'incoming':'started'):'conversations',surveys:incoming?'incoming-surveys':'surveys',connections:'following',activity:`activity?type=${kind}`}[section] || (unreadOnly?'notifications?unread=1':section));
            body.innerHTML = `${scopeOptions}${thread?button('← Semua percakapan','messages'):''}${section==='activity'?`<nav class="p-row" aria-label="Jenis aktivitas">${[['likes','Disukai'],['comments','Komentar'],['saves','Disimpan']].map(([k,l])=>button(l,'activity',`data-type="${k}" aria-pressed="${kind===k}"`)).join('')}</nav>`:''}${section==='connections'?'<p class="p-muted">Koneksi profesional untuk membantu menemukan properti Anda.</p>':''}<div class="c-list" data-list role="list"></div><p data-list-state role="status">Memuat…</p><button class="p-button p-button--secondary" data-more hidden>Muat berikutnya</button>${section==='surveys'?'<p class="c-footnote">Permintaan belum menjadi jadwal pasti sampai Agent mengonfirmasi. Hubungi Agent untuk perubahan; kebijakan pembatalan mandiri belum ditetapkan.</p>':''}`;
            const list = body.querySelector('[data-list]'), state = body.querySelector('[data-list-state]'), more = body.querySelector('[data-more]');
            if(thread){body.insertBefore(more,list);list.tabIndex=0;list.setAttribute('aria-label','Riwayat percakapan');}
            let page = 1;
            const load = async () => {
                more.disabled = true; state.textContent = 'Memuat…';
                try {
                    const result = await api(`${base}/${endpoint}${endpoint.includes('?')?'&':'?'}page=${page}`); if (!current()) return;
                    if (agent && thread) body.querySelector('.c-message-form').hidden = !result.can_reply;
                    const fragment = document.createDocumentFragment();
                    for(const item of thread ? [...result.data].reverse() : result.data) {
                        const row = document.createElement('article'); row.className = `c-list-row ${thread && item.mine?'c-message-mine':''}`; row.setAttribute('role','listitem');
                        if(thread) row.innerHTML = `<span class="p-eyebrow p-muted">${item.mine?'Anda':agent?'Lawan bicara':'Agent'}</span><p>${e(item.body)}</p><time>${e(date(item.created_at))}</time>`;
                        else if(section==='messages') row.innerHTML = `<h3>${e(item.agent?.name || 'Lawan bicara tidak tersedia')}</h3><p>${e(item.last_message || 'Belum ada pesan.')}</p><time>${e(date(item.last_message_at))}</time>${button('Buka percakapan →','messages',`data-conversation="${item.id}"`)}`;
                        else if(section==='connections') row.innerHTML = `<h3>${e(item.name)}</h3><p>${e([item.agency,item.branch,item.verified?'Terverifikasi':null].filter(Boolean).join(' · '))}</p><a class="p-button p-button--ghost" href="/agents/${item.id}">Lihat properti →</a>${button('Berhenti mengikuti','unfollow',`data-agent="${item.id}"`)}`;
                        else if(section==='surveys') row.innerHTML = `<span class="p-eyebrow p-muted">${e(status[item.status] || item.status)}</span><h3>${e(item.listing?.title || 'Listing tidak tersedia')}</h3><p>Waktu pilihan: ${e(date(item.requested_at))}</p>${item.proposed_at?`<p>Usulan Agent: ${e(date(item.proposed_at))}</p>`:''}<p>${e(item.agent?.name)}</p>${item.note?`<p>${e(item.note)}</p>`:''}`;
                        else if(section==='notifications') row.innerHTML = `<span class="p-eyebrow p-muted">${item.read_at?'Sudah dibaca':'Baru'}</span><h3>${e(item.title)}</h3><p>${e(item.message)}</p><time>${e(date(item.created_at))}</time>${!item.read_at?button('Tandai dibaca','read',`data-notification="${item.id}"`):''}`;
                        else row.innerHTML = `<h3>${e(item.title)}</h3>${item.content?`<p>${e(item.content)}</p>`:''}<time>${e(date(item.created_at))}</time><a class="p-button p-button--ghost" href="/posts/${item.post_id}">Buka properti →</a>${kind==='comments'?button('Ubah komentar','edit-comment',`data-comment="${item.id}"`)+button('Hapus komentar','delete-comment',`data-comment="${item.id}"`):''}`;
                        fragment.append(row);
                    }
                    const previousHeight=list.scrollHeight;
                    if(thread && page>1){list.prepend(fragment);list.scrollTop+=list.scrollHeight-previousHeight;}
                    else{list.append(fragment);if(thread)list.scrollTop=list.scrollHeight;}
                    state.textContent = agent && thread && !result.can_reply ? 'Percakapan ini tersedia untuk dibaca.' : !list.children.length ? ({messages:'Belum ada percakapan. Mulai dari tombol Hubungi Agent pada properti.',surveys:'Belum ada permintaan survei.',notifications:'Belum ada pemberitahuan.',connections:'Belum mengikuti Agent. Temukan Agent dari feed.',activity:'Belum ada aktivitas pada properti publik.'}[section]) : '';
                    page++; more.hidden = !result.has_more; more.textContent = thread?'Pesan sebelumnya':'Muat berikutnya';
                } catch(err) { if(err.status===401){await recoverSession(section,()=>show(section,options));return;} if(current()) { state.textContent = err.message; more.hidden = false; more.textContent = 'Coba lagi'; } }
                finally { if(current()) more.disabled = false; }
            };
            more.onclick = load;
            if(thread) {
                const form = document.createElement('form'); form.className = 'p-stack c-message-form'; form.hidden = agent;
                form.innerHTML = '<label class="p-field">Pesan untuk Agent<textarea class="p-textarea" name="body" maxlength="4000" required></textarea></label><p data-error class="p-field-error" role="alert"></p><button type="submit" class="p-button p-button--primary">Kirim pesan</button>';
                form.onsubmit = async event => { event.preventDefault(); const b=form.querySelector('button'); if(b.disabled)return;b.disabled=true;
                    try { await api(`${base}/conversations/${options.conversation}/messages`,'POST',{body:form.elements.body.value}); form.reset();markDialogClean(dialog);b.disabled=false; await show('messages',options); notice('Pesan terkirim.'); }
                    catch(err) { if(err.status===401){await recoverSession('messages',()=>show('messages',options));return;} form.querySelector('[data-error]').textContent = err.code==='PHONE_VERIFICATION_REQUIRED'?'Nomor HP perlu diverifikasi melalui alur verifikasi akun. Pesan belum dikirim.':err.message; }
                    finally { b.disabled=false; }
                }; body.append(form);
            }
            await load();
        } catch(err) { if(err.status===401){await recoverSession(section,()=>show(section,options));return;} if(current()) { body.replaceChildren(); const p=document.createElement('p');p.setAttribute('role','alert');p.textContent=err.message;body.append(p); const retry=document.createElement('button');retry.className='p-button';retry.textContent='Coba lagi';retry.onclick=()=>show(section,options);body.append(retry); } }
    }
    dialog.addEventListener('click',async event=>{
        const b=event.target.closest('[data-customer]'); if(!b)return;
        const action=b.dataset.customer;if(b.disabled)return; b.disabled=true;
        try {
            if(action==='unfollow') { dialog.setAttribute('aria-busy','true');await api(`/api/agents/${b.dataset.agent}/follow`,'DELETE');dialog.removeAttribute('aria-busy');await interactionsChanged('follow',Number(b.dataset.agent));await show('connections');notice('Agent tidak lagi diikuti.'); }
            else if(action==='read') {dialog.setAttribute('aria-busy','true');await api(`/api/customer/notifications/${b.dataset.notification}/read`,'PATCH');dialog.removeAttribute('aria-busy');await show('notifications');}
            else if(action==='delete-comment') {
                if(!window.confirm('Hapus komentar Anda? Balasan mengikuti aturan moderasi yang berlaku.'))return;
                dialog.setAttribute('aria-busy','true');await api(`/api/social/comments/${b.dataset.comment}`,'DELETE');dialog.removeAttribute('aria-busy');await interactionsChanged('comment');await show('activity',{type:'comments'});notice('Komentar dihapus.');
            } else if(action==='edit-comment') {
                const row=b.closest('article'), p=row.querySelector('p');
                if(row.querySelector('form'))return;
                const form=document.createElement('form');form.className='p-stack';form.innerHTML='<label class="p-field">Ubah komentar<textarea class="p-textarea" name="content" maxlength="2000" required></textarea></label><p data-error class="p-field-error" role="alert"></p><button type="submit" class="p-button p-button--primary">Simpan komentar</button>';
                form.elements.content.value=p?.textContent || '';row.append(form);form.elements.content.focus();
                form.onsubmit=async ev=>{ev.preventDefault();const submit=form.querySelector('button');if(submit.disabled)return;submit.disabled=true;try{await api(`/api/social/comments/${b.dataset.comment}`,'PATCH',{content:form.elements.content.value});markDialogClean(dialog);submit.disabled=false;await show('activity',{type:'comments'});notice('Komentar diperbarui.');}catch(err){form.querySelector('[data-error]').textContent=err.message;}finally{submit.disabled=false;}};
            } else await show(action,{type:b.dataset.type,conversation:b.dataset.conversation,scope:b.dataset.scope});
        }catch(err){notice(err.message);}finally{b.disabled=false;dialog.removeAttribute('aria-busy');}
    });
    dialog.addEventListener('close',()=>{generation++;onClose();});
    return { show, close:()=>closeDialog(dialog), clear:()=>{generation++;closeDialog(dialog,{immediate:true,restore:false});body.replaceChildren();} };
}
