async(page)=>{
    const root='docs/customer/signature',records=[];
    const restore=async()=>{
        await page.evaluate(()=>{
            const remove=group=>{for(let i=0;i<group.cssRules.length;i++){const r=group.cssRules[i];if(r.style?.getPropertyValue('--customer-surface')){while(group.cssRules.length>i)group.deleteRule(i);return true;}if(r.cssRules&&remove(r))return true;}return false;};
            let removed=false;for(const sheet of document.styleSheets)if(sheet.href&&remove(sheet)){removed=true;break;}
            if(!removed)throw new Error('Signature CSS boundary not found');
            const lockup=document.querySelector('.c-brand-lockup');if(lockup)lockup.replaceWith(lockup.querySelector('a'));
            document.querySelectorAll('.c-nav-label,.c-dialog-eyebrow,#feed-filter-count').forEach(el=>el.remove());
            document.querySelector('.f-sidebar-foot').innerHTML='<span class="p-muted text-xs">PT Promex Batam City</span><button class="p-button p-button--ghost" data-feed-action="settings">Preferensi tampilan</button>';
            document.querySelector('#feed-auth .p-eyebrow').textContent='Selamat datang di Promerty';
            for(const name of ['min_price','max_price'])document.querySelector(`#feed-search-form [name=${name}]`).removeAttribute('placeholder');
        });
        await page.addStyleTag({path:root+'/baseline-customer.css'});
    };
    await page.context().clearCookies();await page.emulateMedia({reducedMotion:'reduce'});
    for(const [device,width,height]of[['desktop',1440,1000],['mobile',390,844]]){
        await page.setViewportSize({width,height});
        for(const theme of ['light','dark']){
            await page.goto('http://127.0.0.1:8011/');await page.waitForSelector('.f-post');await page.evaluate(t=>window.PromertyTheme.set(t),theme);await restore();
            const shot=async surface=>{await page.screenshot({path:`${root}/before-reconstructed-${surface}-${device}-${theme}.png`});records.push({surface,device,theme});};
            await shot('feed');await page.locator(`${device==='mobile'?'.f-mobile-nav':'.f-header'} [data-feed-action=search]`).click();await shot('search');await page.locator('#feed-search [data-close]').click();
            await page.locator('#feed-account').click();await shot('auth');await page.locator('#feed-auth [data-close]').first().click();
            await page.goto('http://127.0.0.1:8011/posts/2');await page.waitForSelector('.f-post');await restore();await page.locator('[data-action=detail]').click();await page.addScriptTag({path:root+'/baseline-detail.js'});
            await page.evaluate(async()=>{const post=(await fetch('/api/posts/2').then(r=>r.json())).data;const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));document.getElementById('detail-body').innerHTML=window.signatureBaselineDetail(post,escape,n=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(n||0)));});
            await shot('detail');await page.locator('#feed-detail [data-close]').click();
            await page.goto('http://127.0.0.1:8011/?q=SIGNATURE_NO_MATCH_QA');await page.waitForSelector('#feed-state h2');await restore();
            await page.evaluate(()=>{document.getElementById('feed-state').innerHTML='<span class="f-empty-symbol"><svg class="p-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v11H3ZM9 21v-8h6v8"/></svg></span><span class="p-eyebrow p-muted">Temukan kemungkinan baru</span><h2>Cerita berikutnya segera hadir.</h2><p>Belum ada properti terpublikasi yang cocok. Kembali lagi nanti atau ubah filter pencarian Anda.</p><button class="p-button p-button--primary" data-feed-action="search">Sesuaikan pencarian</button><span class="p-muted text-xs">Anda bebas menjelajah tanpa masuk.</span>';});
            await shot('empty');
        }
    }
    const result={stage:'before-reconstructed',records,method:'Original Customer CSS and original presentation markup reconstructed from source read before editing; live QA data and unchanged business endpoints. Initial baseline files were overwritten by a capture-stage naming error. These are explicitly reconstructed, not untouched originals.'};
    const dl=page.waitForEvent('download');await page.evaluate(v=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(v,null,2)],{type:'application/json'}));a.download='reconstructed.json';a.click()},result);await(await dl).saveAs(root+'/before-reconstructed.json');return{captures:records.length,stage:result.stage};
}
