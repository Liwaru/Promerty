async(page)=>{
    const errors=[],failed=[],checks=[];
    page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)failed.push({url:r.url(),status:r.status()})});
    await page.goto('http://127.0.0.1:8011/');await page.waitForSelector('.f-post');
    if(await page.locator('#feed-account').innerText()!=='Keluar')throw new Error('Use the synthetic Customer login before visual QA.');
    await page.emulateMedia({reducedMotion:'reduce'});
    for(const [device,width,height] of [['desktop',1440,1000],['tablet',820,1180],['mobile',390,844]]){
        await page.setViewportSize({width,height});
        for(const theme of ['light','dark']){
            await page.evaluate(t=>window.PromertyTheme.set(t),theme);
            await page.screenshot({path:`docs/customer/qa/feed-${device}-${theme}.png`});
            const feed=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth,unlabelledButtons:[...document.querySelectorAll('button')].filter(b=>b.getClientRects().length&&!b.textContent.trim()&&!b.getAttribute('aria-label')).length}));
            if(feed.overflow||feed.unlabelledButtons)throw new Error('Feed accessibility/layout gate');checks.push({device,theme,surface:'feed',...feed});
            for(const section of ['profile','messages','surveys','connections','activity','notifications']){
                await page.locator(`${width<=760?'.f-mobile-nav':'.f-sidebar'} [data-feed-action=settings]`).first().click();await page.locator(`#customer-workspace [data-customer=${section}]`).click();
                if(section==='profile')await page.waitForSelector('[data-profile-form]');else await page.waitForFunction(()=>!document.querySelector('[data-list-state]')?.textContent.includes('Memuat'));
                const layout=await page.locator('#customer-workspace').evaluate(el=>({overflow:el.scrollWidth>el.clientWidth,focusInside:el.contains(document.activeElement),rows:el.querySelectorAll('.c-list-row').length,error:!!el.querySelector('[role=alert]')?.textContent}));
                if(layout.overflow||!layout.focusInside||layout.error)throw new Error(`Workspace gate ${section}`);
                await page.screenshot({path:`docs/customer/qa/${section}-${device}-${theme}.png`});checks.push({device,theme,surface:section,...layout});await page.locator('#customer-workspace [data-close]').click();
            }
        }
    }
    await page.emulateMedia({reducedMotion:'no-preference'});
    const evidence={checks,errors,failedRequests:failed,environment:'promerty_qa',synthetic:true};
    const download=page.waitForEvent('download');await page.evaluate(value=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(value,null,2)],{type:'application/json'}));a.download='visual.json';a.click()},evidence);await(await download).saveAs('docs/customer/qa/visual.json');
    return{checks:checks.length,screenshots:42,errors,failedRequests:failed};
}
