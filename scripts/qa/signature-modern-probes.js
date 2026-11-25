async(page)=>{
    page=await page.context().newPage();
    const root=`docs/customer/signature-modern/probes-${Date.now()}`,rows=[];
    await page.addInitScript(()=>{window.__signatureCls=0;try{new PerformanceObserver(list=>{for(const e of list.getEntries())if(!e.hadRecentInput)window.__signatureCls+=e.value}).observe({type:'layout-shift',buffered:true});}catch{}});
    await page.emulateMedia({reducedMotion:'reduce'});
    for(const [device,width,height]of[['desktop',1440,1000],['short',390,568],['small',375,667],['landscape',844,390]]){
        await page.setViewportSize({width,height});for(const theme of ['light','dark']){
            await page.goto('http://127.0.0.1:8011/search?q=Kota+QA');await page.waitForSelector('.s-card');await page.evaluate(t=>window.PromertyTheme.set(t),theme);
            await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
            const data=await page.evaluate(()=>{
                const app=document.querySelector('.f-app'),canvas=document.createElement('canvas').getContext('2d');
                const rgb=name=>{const el=document.createElement('span');el.style.color=`var(${name})`;app.append(el);const value=getComputedStyle(el).color;el.remove();canvas.clearRect(0,0,1,1);canvas.fillStyle=value;canvas.fillRect(0,0,1,1);return[...canvas.getImageData(0,0,1,1).data].slice(0,3)};
                const lum=rgb=>rgb.map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4}).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0);
                const ratio=(a,b)=>{const x=lum(rgb(a)),y=lum(rgb(b));return Math.round((Math.max(x,y)+.05)/(Math.min(x,y)+.05)*100)/100};
                return{overflow:document.documentElement.scrollWidth>innerWidth,gridVideos:document.querySelectorAll('video').length,playAffordances:document.querySelectorAll('.s-preview-play').length,text:ratio('--color-text','--color-bg'),gold:ratio('--customer-gold-ink','--customer-surface'),selected:ratio('--customer-gold-ink','--color-accent-subtle'),control:ratio('--customer-control-line','--color-bg'),mediaGold:ratio('--color-primary-soft','--brand-dark-background'),transition:getComputedStyle(document.querySelector('.p-button')).transitionDuration,reducedMotion:matchMedia('(prefers-reduced-motion:reduce)').matches,clsObserved:window.__signatureCls,externalResources:performance.getEntriesByType('resource').filter(r=>new URL(r.name).origin!==location.origin).length};
            });
            if(data.overflow||data.gridVideos||data.gold<4.5||data.selected<4.5||data.control<3||data.mediaGold<4.5||data.externalResources)throw Error('Signature visual/contrast gate');
            await page.locator('#search-input').focus();await page.keyboard.press('Tab');if(await page.locator('#search-form button[aria-label=Cari]').evaluate(e=>e===document.activeElement)===false)throw Error('Query keyboard order');
            await page.screenshot({path:`${root}/search-${device}-${theme}.png`});
            await page.goto('http://127.0.0.1:8011/?q=SIGNATURE_NO_MATCH_FINAL');await page.waitForSelector('#feed-state h2');await page.locator('#feed-state button').focus();const cta=await page.locator('#feed-state button').boundingBox();if(cta.y< -1||cta.y+cta.height>height+1)throw Error('Empty action obscured');await page.screenshot({path:`${root}/empty-${device}-${theme}.png`});
            rows.push({device,theme,...data,emptyCtaReachable:true});
        }
    }
    const result={root,rows,qa:'promerty_qa',performanceNote:'Short local observations, not production CWV/load certification; 640-style reflow is not native zoom'};const dl=page.waitForEvent('download');await page.evaluate(v=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(v,null,2)],{type:'application/json'}));a.download='probes.json';a.click()},result);await(await dl).saveAs(`${root}/probes.json`);return result;
}
