import {openDialog,dismissDialog,markDialogClean} from './dialogs';
import {listingActions,listingPayload,listingStatus} from './agent-listing-state';
import {uploadAgentMedia} from './agent-upload';
import {uploadResumableVideo} from './video-upload.js';
import {externalVideo,externalVideoMarkup} from './external-video.js';

const legacyAgentPages={'#agent-video-title':'/agent/listings?view=video','#agent-list-title':'/agent/listings',
    '#agent-projects':'/agent/projects','#agent-media-and-publication':'/agent/media'};
if(location.pathname==='/agent'&&legacyAgentPages[location.hash])location.replace(legacyAgentPages[location.hash]);

const publicProfile=document.querySelector('[data-agent-portfolio]');
if(publicProfile){
    if(typeof IntersectionObserver==='function'){
        const observer=new IntersectionObserver(entries=>{
            for(const {target,isIntersecting} of entries){
                if(!isIntersecting)continue;
                observer.unobserve(target);
                target.addEventListener('loadeddata',()=>target.classList.add('is-ready'),{once:true});
                target.preload='metadata';
                target.src=target.dataset.portfolioFrame;
            }
        });
        publicProfile.querySelectorAll('[data-portfolio-frame]').forEach(video=>observer.observe(video));
    }
    const follow=publicProfile.querySelector('[data-follow-agent]');
    follow?.addEventListener('click',async()=>{
        const error=publicProfile.querySelector('[data-profile-follow-error]');
        const counter=publicProfile.querySelector('[data-profile-followers]');
        const wasFollowing=follow.getAttribute('aria-pressed')==='true';
        follow.disabled=true;error.textContent='';
        try{
            const response=await fetch('/api/agents/'+follow.dataset.followAgent+'/follow',{
                method:wasFollowing?'DELETE':'POST',credentials:'same-origin',
                headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},
            });
            if(!response.ok)throw new Error('Pengaturan mengikuti belum tersimpan. Muat ulang halaman dan coba lagi.');
            const {active}=await response.json();
            follow.setAttribute('aria-pressed',String(active));
            follow.textContent=active?'Berhenti mengikuti':'Ikuti Agent';
            const count=Number(counter.textContent.replace(/\./g,''));
            counter.textContent=new Intl.NumberFormat('id-ID').format(Math.max(0,count+Number(active)-Number(wasFollowing)));
        }catch(err){error.textContent=err.message;}
        finally{follow.disabled=false;}
    });
}

const app=document.getElementById('promerty-agent');
if(app?.querySelector('#agent-editor')){
    const $=selector=>app.querySelector(selector), form=$('#agent-listing-form'),editor=$('#agent-editor'),preview=$('#agent-preview');
    const section=app.dataset.section, standalone=section==='create', filter=$('#agent-filter'), params=new URLSearchParams(location.search);
    const requestedPage=Number(params.get('page'));
    let current=null,page=Number.isSafeInteger(requestedPage)&&requestedPage>0?requestedPage:1,lastPage=1,busy=false,categories=[],savedPayload='',draftKey,limits={},canManageMedia=false;
    const token=()=>document.querySelector('meta[name="csrf-token"]').content;
    const money=value=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(value)).replace(/^Rp\s+/,'Rp');
    function notice(message,error=false){const node=$('#agent-notice');node.textContent=message;node.setAttribute('role',error?'alert':'status');if(error)node.focus();}
    function el(tag,text,className){const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(className)node.className=className;return node;}
    function videoFeedback(video,container){const message=el('p','','p-field-error');message.setAttribute('role','status');container.append(message);const update=()=>{message.textContent=video.videoWidth>0&&video.videoHeight>0?'':'Browser ini belum dapat menampilkan gambar video. Gunakan browser dengan dukungan HEVC atau unggah MP4 H.264.';};['loadedmetadata','loadeddata','resize'].forEach(event=>video.addEventListener(event,update));video.addEventListener('error',()=>{message.textContent='Video belum dapat diputar oleh browser ini. Periksa format dan dukungan codec.';});}
    function button(text,action){const b=el('button',text,'p-button p-button--secondary');b.type='button';if(busy){b.dataset.agentBusyOriginal='0';b.disabled=true;}b.addEventListener('click',()=>run(()=>action(b)));return b;}
    async function api(url,options={}){
        const headers={Accept:'application/json','X-CSRF-TOKEN':token(),...options.headers};
        if(options.body&&!(options.body instanceof FormData))headers['Content-Type']='application/json';
        const response=await fetch(url,{credentials:'same-origin',...options,headers});
        const data=await response.json().catch(()=>({message:response.status===413?'Ukuran unggahan melampaui batas server.':'Respons server tidak dapat dibaca.'}));
        if(!response.ok){const error=new Error(data.message||'Permintaan belum berhasil.');error.errors=data.errors||{};throw error;}return data;
    }
    function setBusy(value){busy=value;if(value){app.setAttribute('aria-busy','true');app.querySelectorAll('button:not([data-agent-close])').forEach(b=>{if(!b.hasAttribute('data-agent-busy-original'))b.dataset.agentBusyOriginal=b.disabled?'1':'0';b.disabled=true;});}else{app.removeAttribute('aria-busy');app.querySelectorAll('[data-agent-busy-original]').forEach(b=>{b.disabled=b.dataset.agentBusyOriginal==='1';delete b.dataset.agentBusyOriginal;});}}
    async function run(action){if(busy)return;setBusy(true);try{await action();}catch(error){if(editor.open||(standalone&&current&&canManageMedia)){const status=$('#agent-upload-status'),detail=Object.values(error.errors||{}).flat().join(' ')||error.message;if(!status.textContent.includes(detail))status.textContent=(status.textContent?status.textContent+' ':'')+detail;status.setAttribute('role','alert');status.tabIndex=-1;status.focus();}else notice(error.message,true);}finally{setBusy(false);}}
    function clearErrors(){const summary=$('#agent-form-errors');summary.replaceChildren();summary.hidden=true;form.querySelectorAll('[data-field-error]').forEach(n=>n.remove());form.querySelectorAll('[aria-invalid]').forEach(n=>{n.removeAttribute('aria-invalid');n.removeAttribute('aria-describedby');});}
    function formError(error){
        clearErrors();const summary=$('#agent-form-errors');summary.classList.add('a-error-summary');summary.hidden=false;summary.append(el('strong','Periksa isian berikut.'));
        for(const [key,messages] of Object.entries(error.errors||{})){
            const name=key.replace('additional_specs.','');const input=form.elements.namedItem(name);
            const text=messages.join(' ');const link=el(input?'a':'p',text);
            if(input){link.href='#'+input.id;link.onclick=e=>{e.preventDefault();input.closest('details')?.setAttribute('open','');input.focus();};const small=el('span',text,'p-field-error');small.id=input.id+'-error';small.dataset.fieldError='';input.after(small);input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby',small.id);}
            summary.append(link);
        }
        if(!Object.keys(error.errors||{}).length)summary.append(el('p',error.message));summary.focus();
    }
    function period(){const sale=form.elements.transaction_type.value==='sale';const select=form.elements.price_period;[...select.options].forEach(o=>o.disabled=sale?o.value!=='total':o.value==='total');if(select.selectedOptions[0]?.disabled)select.value=sale?'total':'year';}
    function apartment(){const category=categories.find(c=>String(c.id)===form.elements.category_id.value);const isApartment=/apart|apartment/i.test(category?.slug||category?.name||'');form.querySelectorAll('[data-apartment]').forEach(n=>n.hidden=!isApartment);}
    function inventory(){
        if(current)form.elements.listing_type.value=current.listing_type;
        const primary=form.elements.listing_type.value==='primary';
        form.querySelector('[data-primary-project]').hidden=!primary;form.elements.project_id.disabled=!primary;form.elements.project_id.required=primary;
        form.querySelectorAll('[data-secondary-location]').forEach(n=>n.hidden=primary);
        form.elements.location_label.disabled=primary;form.elements.location_label.required=!primary;form.elements.address.disabled=primary;
    }
    const thumbnails = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
        for (const {target, isIntersecting} of entries) {
            if (!isIntersecting) continue;
            thumbnails.unobserve(target);
            target.preload = 'metadata';
            target.src = target.dataset.source;
        }
    }) : null;
    const videoDate = new Intl.DateTimeFormat('id-ID',{dateStyle:'medium'});
    function mediaCover(title, action, {type, url, poster} = {}){
        const cover=button('',action);
        cover.className='a-video-cover';
        cover.setAttribute('aria-label','Pratinjau '+title);
        const label=el('span','Pratinjau','a-video-cover-label');
        const fallback=el('span',type==='video'?'Video':type==='image'?'Foto':'Tanpa media','a-video-placeholder');
        fallback.setAttribute('aria-hidden','true');
        cover.append(fallback);
        if(poster||type==='image'){
            const img=el('img');
            img.alt='';img.loading='lazy';img.decoding='async';
            img.addEventListener('error',()=>img.remove(),{once:true});
            img.src=poster||url;cover.append(img);
        }else if(type==='video'&&url?.startsWith('/')&&thumbnails){
            const frame=el('video');
            frame.muted=true;frame.playsInline=true;frame.preload='none';
            frame.setAttribute('aria-hidden','true');
            frame.dataset.source=url+'#t=0.1';
            frame.addEventListener('loadeddata',()=>frame.classList.add('is-ready'),{once:true});
            cover.append(frame);thumbnails.observe(frame);
        }
        cover.append(label);
        return cover;
    }
    function videoCard(item){
        const card=el('article',undefined,'a-video-card');
        card.dataset.videoId=String(item.id);
        const media={id:item.id,type:'video',url:item.url,poster:item.thumbnail};
        const cover=mediaCover(item.title,opener=>showPreview(item.listing_id,opener,{selectedMedia:media}),media);
        const info=el('div',undefined,'a-video-info');
        info.append(el('span',listingStatus(item.status),'a-badge'),el('h3',item.title));
        if(item.project_name)info.append(el('p',item.project_name,'p-muted'));
        info.append(el('p',money(item.price)+(item.price_period==='year'?' / tahun':item.price_period==='month'?' / bulan':''),'a-price'));
        const date=item.published_at||item.uploaded_at;
        if(date){
            const time=el('time',videoDate.format(new Date(date)));
            time.dateTime=date;
            const row=el('p',item.published_at?'Publikasi ':'Unggah ','p-muted');
            row.append(time);info.append(row);
        }
        const action=listingActions(item.status).editable
            ? button('Kelola',opener=>edit(item.listing_id,opener))
            : button('Detail',opener=>showPreview(item.listing_id,opener,{detailsOnly:true}));
        info.append(action);card.append(cover,info);
        return card;
    }
    function filters(){
        const values=new URLSearchParams(new FormData(filter));
        values.delete('view');values.set('page',page);
        return values;
    }
    function goToPage(nextPage){
        const url=new URL(location.href);
        if(nextPage===1)url.searchParams.delete('page');else url.searchParams.set('page',nextPage);
        location.assign(url);
    }
    function pagination(lastPage,previous,next){
        previous.hidden=page<=1;next.hidden=page>=lastPage;
        previous.onclick=()=>goToPage(page-1);next.onclick=()=>goToPage(page+1);
    }
    async function portfolio(){
        const grid=$('#agent-video-grid');
        if(!grid)return;
        const status=$('#agent-video-status');
        try{
            const result=await api('/api/agent/videos?'+filters());
            if(page>result.last_page){goToPage(result.last_page);return;}
            grid.querySelectorAll('video').forEach(frame=>thumbnails?.unobserve(frame));
            grid.replaceChildren(...result.data.map(videoCard));
            status.textContent='Belum ada video MP4 yang sesuai.';
            status.hidden=result.data.length>0;
            $('#agent-total').textContent=result.total+' video';
            pagination(result.last_page,$('[data-video-prev]'),$('[data-video-next]'));
        }catch{
            status.hidden=false;
            status.textContent='Video belum dapat dimuat. ';
            status.append(button('Coba lagi',portfolio));
        }
    }
    const videoStages={queued:'Optimasi menunggu worker. Original siap.',optimizing:'Mengoptimalkan video. Original siap.',
        optimized:'Video hasil optimasi siap. Original tersimpan.',original:'Video asli siap.',failed:'Optimasi gagal. Video asli tetap siap.'};
    async function list(){
        if(!filter)return;
        const container=$('#agent-listings');
        if(!container){await portfolio();return;}
        const data=await api('/api/agent/listings?'+filters());
        if(page>data.last_page){goToPage(data.last_page);return;}
        $('#agent-total').textContent=data.total+' hasil · '+data.summary.total+' total listing';
        container.querySelectorAll('video').forEach(frame=>thumbnails?.unobserve(frame));
        container.replaceChildren();
        if(!data.data.length)container.append(el('p','Belum ada listing yang sesuai.','p-muted'));
        for(const item of data.data){
            const card=el('article',undefined,'a-video-card'),info=el('div',undefined,'a-video-info');
            card.dataset.listingId=String(item.id);
            const {cover,photos,videos,video_status}=item.media_summary;
            const period=item.price_period==='year'?' / tahun':item.price_period==='month'?' / bulan':'';
            info.append(el('span',listingStatus(item.status),'a-badge'),el('h3',item.title),el('p',money(item.price)+period,'a-price'));
            if(section==='media'){
                info.append(el('p',photos+' foto · '+videos+' video','p-muted'));
                if(video_status)info.append(el('p',videoStages[video_status],'p-muted'));
                else if(cover)info.append(el('p',cover.type==='video'?'Tautan video tersedia':'Foto tersedia','p-muted'));
                else info.append(el('p',photos||videos?'Media belum dapat dipratinjau':'Belum ada media','p-muted'));
            }
            const actions=el('div',undefined,'p-row'),allowed=listingActions(item.status);
            actions.append(button('Detail',opener=>showPreview(item.id,opener,{detailsOnly:true})));
            if(section==='listings'&&allowed.editable)actions.append(button('Edit',opener=>edit(item.id,opener)));
            if(canManageMedia&&allowed.editable)actions.append(button(section==='media'?(photos||videos?'Kelola media':'Unggah media'):'Media',opener=>edit(item.id,opener,true)));
            if(canManageMedia&&allowed.publishable)actions.append(button('Publikasikan',()=>transition(item.id,'publish')));
            if(allowed.archivable)actions.append(button('Arsipkan',()=>transition(item.id,'archive')));
            info.append(actions);
            card.append(mediaCover(item.title,opener=>showPreview(item.id,opener),cover||undefined),info);
            container.append(card);
        }
        pagination(data.last_page,$('[data-agent-prev]'),$('[data-agent-next]'));
    }
    function setFields(data){
        form.reset();for(const input of form.elements){if(!input.name)continue;const value=data[input.name]??data.additional_specs?.[input.name];if(value!==undefined&&value!==null)input.value=typeof value==='boolean'&&input.name==='allow_video_download'?Number(value):value;}
        inventory();period();apartment();clearErrors();savedPayload=JSON.stringify(listingPayload(new FormData(form)));markDialogClean(editor);
    }
    async function edit(id,opener,media=false){
        current=id?(await api('/api/agent/listings/'+id)).data:null;
        draftKey=id?null:[...crypto.getRandomValues(new Uint8Array(16))].map(value=>value.toString(16).padStart(2,'0')).join('');
        if(current&&!listingActions(current.status).editable)throw new Error('Arsipkan listing sebelum mengedit.');
        setFields(current||{});$('#agent-editor-title').textContent=current?'Edit listing':'Tambah listing';$('#agent-media-panel').hidden=!current||!canManageMedia;
        $('#agent-save-status').textContent=current?'Draft tersimpan':'Belum disimpan';$('#agent-upload-status').textContent='';$('#agent-upload').value='';
        const cancel=$('[data-agent-cancel-video]');
        cancel.hidden=cancel.dataset.listingId!==String(current?.id)||!cancel.dataset.uploadId;
        const provider=current?.media[0]?.external_video?.provider;
        $('#agent-media-source').value=provider||'upload';
        $('#agent-media-url').value=current?.media[0]?.external_video?.url||'';
        syncSource();renderMedia();
        if(standalone)return;
        openDialog(editor,{opener});(media ? $('#agent-media-source') : form.elements.title).focus();
    }
    function syncSource(){
        const source=$('#agent-media-source').value;
        $('#agent-file-media').hidden=source!=='upload';
        $('#agent-link-media').hidden=source==='upload';
        $('#agent-media-url-label').textContent=source==='instagram'?'URL Instagram Reels':'URL TikTok';
    }
    function showMedia(media,container,title){
        const external=externalVideo(media);
        if(external){container.insertAdjacentHTML('beforeend',externalVideoMarkup(external));return;}
        const node=el(media.type==='video'?'video':'img');
        node.src=media.url;
        if(media.type==='video'){node.controls=true;node.preload='metadata';node.playsInline=true;}
        else node.alt=title;
        container.append(node);
        if(media.type==='video'){
            videoFeedback(node,container);
            if(videoStages[media.optimization])container.append(el('p',videoStages[media.optimization],'p-muted'));
        }
    }
    function renderMedia(){
        const container=$('#agent-media-list');container.replaceChildren();
        for(const media of current?.media||[]){const item=el('div');item.dataset.mediaId=media.id;
            showMedia(media,item,'Media listing tersimpan');
            const index=current.media.findIndex(m=>m.id===media.id);item.append(el('p',index===0?'Media utama / cover':'Media tambahan','p-muted'));
            for(const [delta,label] of [[-1,'Pindah ke depan'],[1,'Pindah ke belakang']])if(index+delta>=0&&index+delta<current.media.length)item.append(button(label,async()=>{
                const ids=current.media.map(m=>m.id);[ids[index],ids[index+delta]]=[ids[index+delta],ids[index]];
                await api(`/api/agent/listings/${current.id}/media-order`,{method:'PATCH',body:JSON.stringify({media_ids:ids})});
                current=(await api('/api/agent/listings/'+current.id)).data;renderMedia();container.querySelector(`[data-media-id="${media.id}"] button`)?.focus();$('#agent-upload-status').textContent='Urutan media disimpan.';
            }));
            item.append(button('Hapus media',async()=>{const result=await api(`/api/agent/listings/${current.id}/media/${media.id}`,{method:'DELETE'});current=(await api('/api/agent/listings/'+current.id)).data;renderMedia();await list();$('#agent-upload-status').textContent=media.external_video?'Tautan video dilepas.':result.file_removed?'Media dihapus.':'Media dilepas; penghapusan file tertunda.';}));container.append(item);}
    }
    async function showPreview(id,opener,{selectedMedia=null,detailsOnly=false}={}){
        const data=(await api('/api/agent/listings/'+id)).data,body=$('#agent-preview-body');body.replaceChildren();
        body.append(el('h2',data.title));body.querySelector('h2').id='agent-preview-title';body.append(el('p',listingStatus(data.status),'a-badge'));
        if(!detailsOnly){
            for(const media of selectedMedia ? [selectedMedia] : data.media)showMedia(media,body,data.title);
        }
        if(selectedMedia)body.querySelector('video')?.setAttribute('controlslist','nofullscreen');
        body.append(el('p',money(data.price),'a-price'),el('p',data.location_label+', Batam'),el('p',data.description||'Belum ada deskripsi.'));
        const specs=['land_area','building_area','bedrooms','bathrooms','floors','furnishing','certificate','unit_type'];
        const labels=['Luas tanah (m²)','Luas bangunan (m²)','Kamar tidur','Kamar mandi','Lantai','Furnished','Surat','Tipe unit'];
        const furnished={unknown:'Belum ditentukan',unfurnished:'Unfurnished',semi_furnished:'Semi furnished',full_furnished:'Full furnished'};
        const dl=el('dl');specs.forEach((key,i)=>{if(data[key]!==null&&data[key]!==undefined&&data[key]!==''){dl.append(el('dt',labels[i]),el('dd',key==='furnishing'?furnished[data[key]]||data[key]:data[key]));}});body.append(dl);
        for(const [label,value]of [['Arah rumah',data.orientation],['Carport',data.additional_specs?.carport],['Garasi',data.additional_specs?.garage],['Lantai apartemen',data.additional_specs?.apartment_floor],['Tower',data.additional_specs?.tower],['Balkon',data.additional_specs?.balconies],['Konsep',data.additional_specs?.concept]])if(value!==null&&value!==undefined&&value!=='')dl.append(el('dt',label),el('dd',value));
        if(data.public_url){const link=el('a','Lihat di Customer','p-button p-button--primary');link.href=data.public_url;body.append(link);}
        openDialog(preview,{opener:opener??app.querySelector(`[data-listing-id="${id}"] button`)});
    }
    async function transition(id,action){const data=await api(`/api/agent/listings/${id}/${action}`,{method:'POST'});await list();notice(action==='publish'?'Listing terpublikasi dan tersedia untuk Customer.':'Listing diarsipkan dan tidak lagi tampil pada Customer.');if(action==='publish')await showPreview(data.data.id);}
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;setBusy(true);$('#agent-save-status').textContent='Menyimpan…';
        try{
            const creation=!current, body=JSON.stringify(listingPayload(new FormData(form)));
            const response=await api('/api/agent/listings'+(creation?'':'/'+current.id),{
                method:creation?'POST':'PATCH',headers:creation?{'Idempotency-Key':draftKey}:{},body,
            });
            current=(await api('/api/agent/listings/'+response.data.id)).data;
            clearErrors();
            savedPayload=response.replayed?'':body;
            const unchanged=JSON.stringify(listingPayload(new FormData(form)))===savedPayload;
            if(!$('#agent-upload').files.length&&unchanged)markDialogClean(editor);
            $('#agent-media-panel').hidden=!canManageMedia;
            $('#agent-editor-title').textContent='Edit listing';
            $('#agent-save-status').textContent=response.replayed?'Draft sebelumnya ditemukan. Simpan lagi untuk menerapkan isian saat ini.':unchanged?'Draft tersimpan':'Draft tersimpan; ada perubahan yang belum disimpan.';
            renderMedia();await list();
        }
        catch(error){$('#agent-save-status').textContent='Belum tersimpan';formError(error);}finally{setBusy(false);}
    });
    $('#agent-media-source').addEventListener('change',syncSource);
    $('[data-agent-link]').addEventListener('click',()=>run(async()=>{
        if(!current)throw new Error('Simpan draft sebelum menambahkan tautan.');
        const input=$('#agent-media-url'),status=$('#agent-upload-status');
        if(!input.value.trim()||!input.reportValidity())throw new Error('Masukkan URL video yang lengkap.');
        status.setAttribute('role','status');
        status.textContent='Menyimpan tautan…';
        await api(`/api/agent/listings/${current.id}/media-link`,{method:'POST',body:JSON.stringify({url:input.value.trim(),provider:$('#agent-media-source').value})});
        current=(await api('/api/agent/listings/'+current.id)).data;
        renderMedia();
        status.textContent='Tautan video tersimpan.';
    }));
    $('[data-agent-upload]').addEventListener('click',()=>run(async()=>{
        if(!current)throw new Error('Simpan draft sebelum menambahkan media.');const files=[...$('#agent-upload').files];if(!files.length)throw new Error('Pilih foto atau video terlebih dahulu.');
        const progress=$('#agent-upload-progress');
        const status=$('#agent-upload-status');
        const listingId=current.id;
        const video=files.find(file=>/\.mp4$/i.test(file.name));
        const resumable=video && limits.resumable_video;
        if(video && files.length!==1)throw new Error('Pilih satu video untuk setiap upload.');
        if(video && video.size>limits.video_max_bytes)throw new Error('Ukuran video melebihi batas aplikasi.');
        if(!video && (current.media.length+files.length>limits.photo_count || current.media.some(media=>media.type==='video')))
            throw new Error(`Gunakan maksimal ${limits.photo_count} foto per listing. Periksa jumlah dan jenis media yang sudah tersimpan.`);
        let done=0;
        let uploadError;
        status.setAttribute('role','status');
        editor.setAttribute('aria-busy','true');
        progress.hidden=false;
        try {
            if(resumable){
                await uploadResumableVideo(api,`/api/agent/listings/${listingId}/video-upload`,video,(phase,percent)=>{
                    progress.value=percent;
                    status.textContent=phase==='hashing'?'Memeriksa checksum video…':phase==='validating'
                        ?'Upload selesai. Memvalidasi video…':`Mengunggah video… ${Math.floor(percent)}%`;
                },id=>{
                    const cancel=$('[data-agent-cancel-video]');
                    cancel.dataset.uploadId=id||'';
                    cancel.dataset.listingId=String(listingId);
                    cancel.hidden=!id;
                });
                done=1;
            }else{
            for(const file of files){
                progress.removeAttribute('value');
                status.textContent=`Mengunggah file ${done+1} dari ${files.length}…`;
                const body=new FormData();
                body.append('media',file);
                await uploadAgentMedia(`/api/agent/listings/${listingId}/media`,body,token(),percent=>{
                    progress.value=percent;
                    if(percent===100)status.textContent=`File ${done+1} dari ${files.length} terkirim. Menunggu validasi server…`;
                });
                done++;
            }
            }
            status.textContent=resumable?'Video asli tervalidasi dan siap dipratinjau.':`${done} media tersimpan secara privat.`;
        } catch(error) {
            uploadError=error;
            status.textContent=done?`${done} media dikonfirmasi tersimpan. `:resumable
                ?'Pilih kembali file yang sama lalu tekan Unggah untuk melanjutkan. ':'';
        } finally {
            // Require a new selection after an uncertain POST so already stored files aren't blindly resent.
            $('#agent-upload').value='';
            progress.hidden=true;
            await api('/api/agent/listings/'+listingId).then(async result=>{
                current=result.data;
                renderMedia();
                await list();
            }).catch(error=>{
                uploadError??=error;
                status.textContent+=done?' Daftar media belum diperbarui.':' Daftar media belum dapat diperiksa.';
            });
            editor.removeAttribute('aria-busy');
            if(JSON.stringify(listingPayload(new FormData(form)))===savedPayload)markDialogClean(editor);
        }
        if(uploadError)throw uploadError;
    }));
    $('[data-agent-cancel-video]').addEventListener('click',()=>run(async()=>{
        const cancel=$('[data-agent-cancel-video]');
        if(!current||!cancel.dataset.uploadId||cancel.dataset.listingId!==String(current.id))return;
        await api(`/api/agent/listings/${current.id}/video-upload/${cancel.dataset.uploadId}`,{method:'DELETE'});
        cancel.hidden=true;
        cancel.dataset.uploadId='';
        $('#agent-upload-status').textContent='Upload video dibatalkan.';
    }));
    app.querySelectorAll('[data-agent-close]').forEach(b=>b.addEventListener('click',()=>dismissDialog(b.closest('dialog'),{reason:'button'})));
    app.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('promerty:dialog-closing',()=>dialog.querySelectorAll('video').forEach(v=>v.pause())));
    preview.addEventListener('close',()=>$('#agent-preview-body').replaceChildren());
    form.elements.transaction_type.onchange=period;form.elements.category_id.onchange=apartment;
    form.elements.listing_type.onchange=inventory;
    if(filter){
        filter.elements.q.value=params.get('q')?.slice(0,150)||'';
        for(const key of ['status','sort']){
            const input=filter.elements[key], value=params.get(key);
            if([...input.options].some(option=>option.value===value))input.value=value;
        }
    }
    run(async()=>{const data=await api('/api/agent/listing-options');limits=data.limits;categories=data.categories;const select=form.elements.category_id;select.replaceChildren();for(const category of categories){const option=el('option',category.name);option.value=category.id;select.append(option);}
        const projects=form.elements.project_id;for(const project of data.projects||[]){const option=el('option',project.name);option.value=project.id;projects.append(option);}
        canManageMedia=data.can_manage_media;
        form.elements.listing_type.querySelector('[value="primary"]').disabled=!data.primary_available;
        $('#agent-upload-limits').textContent=limits.resumable_video
            ? `Foto: ${limits.image_max_kb/1024} MiB per file. Video: ${limits.video_max_bytes/1000000} MB, dapat dilanjutkan dengan memilih file yang sama.`
            : `Batas aplikasi: foto ${limits.image_max_kb/1024} MiB, video ${Math.min(limits.video_max_bytes,limits.video_max_kb*1024)/1048576} MiB. Batas upload PHP: ${limits.php_upload_max}.`;
        if(standalone){
            await edit(null);
            $('#agent-editor-title').textContent='Informasi properti';
            const projectId=params.get('project_id');
            if(data.primary_available&&[...projects.options].some(option=>option.value&&option.value===projectId)){
                form.elements.listing_type.value='primary';
                inventory();projects.value=projectId;
            }
        }
        await list();});
}
