async(page)=>{
    const checks=[],base='http://127.0.0.1:8011',assert=(c,m)=>{if(!c)throw new Error(m);};
    const test=async(name,run)=>{try{checks.push({name,status:'PASS',evidence:await run()});}catch(e){checks.push({name,status:'FAIL',error:e.message});}};
    await page.goto(base+'/posts/1');await page.waitForSelector('.f-post');
    await test('Send message through listing contact UI',async()=>{
        await page.locator('[data-action=message]').click();await page.waitForSelector('#feed-contact[open]');
        await page.locator('#contact-form [name=body]').fill('SYNTHETIC QA UI end-to-end message');await page.locator('#contact-form button').click();
        await page.waitForFunction(()=>document.querySelector('#contact-history')?.textContent.includes('SYNTHETIC QA UI end-to-end message'));
        await page.screenshot({path:'docs/customer/qa/message-send-mobile.png'});await page.locator('#feed-contact [data-close]').click();return{uiSent:true,historyUpdated:true};
    });
    await test('Submit survey through detail UI and read pending history',async()=>{
        await page.locator('[data-action=detail]').click();await page.locator('[data-detail-action=survey]').click();
        await page.locator('#contact-form [name=requested_at]').fill(new Date(Date.now()+86400000).toISOString().slice(0,16));await page.locator('#contact-form [name=body]').fill('SYNTHETIC QA UI visit — NOT A REAL APPOINTMENT');
        await page.locator('#contact-form button').click();await page.waitForFunction(()=>!document.getElementById('feed-contact').open);await page.locator('#feed-detail [data-close]').click();
        await page.locator('.f-mobile-nav [data-feed-action=settings]').click();await page.locator('[data-customer=surveys]').click();
        await page.waitForFunction(()=>document.querySelector('[data-list]')?.textContent.includes('SYNTHETIC QA UI visit'));
        assert((await page.locator('.c-list-row').first().innerText()).toLowerCase().includes('menunggu'),'Visit was falsely confirmed');
        await page.screenshot({path:'docs/customer/qa/survey-submit-mobile.png'});await page.locator('#customer-workspace [data-close]').click();return{uiSubmitted:true,status:'pending',automaticConfirmation:false};
    });
    await test('UI unfollow refreshes feed; like/save toggle restores state',async()=>{
        await page.goto(base+'/');await page.waitForSelector('.f-post');await page.locator('.f-mobile-nav [data-feed-action=settings]').click();await page.locator('[data-customer=connections]').click();await page.waitForSelector('[data-customer=unfollow]');
        await page.locator('[data-customer=unfollow]').click();await page.waitForFunction(()=>document.querySelector('[data-list-state]')?.textContent.includes('Belum mengikuti'));
        await page.locator('#customer-workspace [data-close]').click();assert(await page.locator('.f-post [data-action=follow]').first().getAttribute('aria-pressed')==='false','Feed follow state stale');
        await page.locator('.f-post [data-action=follow]').first().click();await page.waitForFunction(()=>document.querySelector('.f-post [data-action=follow]')?.getAttribute('aria-pressed')==='true');
        for(const action of ['like','save']){
            const b=page.locator(`.f-post [data-action=${action}]`).first(),initial=await b.getAttribute('aria-pressed');await b.click();
            await page.waitForFunction(({action,initial})=>document.querySelector(`.f-post [data-action=${action}]`)?.getAttribute('aria-pressed')!==initial,{action,initial});
            await b.click();await page.waitForFunction(({action,initial})=>document.querySelector(`.f-post [data-action=${action}]`)?.getAttribute('aria-pressed')===initial,{action,initial});
        }return{unfollowRefresh:true,followRestored:true,likeSaveRestored:true};
    });
    await test('Combined public filters and agent profile',async()=>{
        await page.locator('.f-mobile-nav [data-feed-action=search]').click();const form=page.locator('#feed-search-form');
        await form.locator('[name=q]').fill('Kota QA');await form.locator('[name=type]').selectOption('secondary');await form.locator('[name=transaction]').selectOption('rent');await form.locator('[name=category]').selectOption({label:'Ruko'});
        await form.locator('[name=min_price]').fill('40000000');await form.locator('[name=max_price]').fill('60000000');await form.locator('button').first().click();
        await page.waitForFunction(()=>document.querySelectorAll('.f-post').length===1&&document.querySelector('.f-post').dataset.id==='2');
        await page.locator('[data-action=agent]').first().click();await page.waitForFunction(()=>document.querySelector('#agent-body')?.textContent.includes('SYNTHETIC QA agent'));
        await page.screenshot({path:'docs/customer/qa/agent-public-mobile.png'});await page.locator('#feed-agent [data-close]').click();return{matchedPost:2,preservedUrl:page.url(),publicAgent:true};
    });
    const evidence={checks,environment:'promerty_qa',synthetic:true};const dl=page.waitForEvent('download');await page.evaluate(v=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(v,null,2)],{type:'application/json'}));a.download='journey.json';a.click()},evidence);await(await dl).saveAs('docs/customer/qa/journey.json');return checks;
}
