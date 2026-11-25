async (page) => {
    // Run through Playwright MCP after authenticating normally with the private QA account.
    const errors = [], failures = [], checks = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if(response.status()>=400)failures.push({url:response.url(),status:response.status()}); });
    await page.goto('http://127.0.0.1:8011/');
    await page.waitForSelector('.f-post');
    const session = await page.evaluate(()=>fetch('/web-auth/me',{headers:{Accept:'application/json'}}).then(r=>r.json()));
    if(session.user?.role!=='customer')throw new Error('Authenticate with the synthetic QA Customer first.');
    await page.emulateMedia({reducedMotion:'reduce'});
    for(const [device,width,height] of [['desktop',1440,1000],['tablet',820,1180],['mobile',390,844]]) {
        await page.setViewportSize({width,height});
        for(const theme of ['light','dark']) {
            await page.evaluate(value=>window.PromertyTheme.set(value),theme);
            await page.screenshot({path:`docs/customer/${device}-${theme}.png`});
            const feed = await page.evaluate(()=>({
                overflow:document.documentElement.scrollWidth>innerWidth,
                postHeight:Math.round(document.querySelector('.f-post').getBoundingClientRect().height),
                feedHeight:Math.round(document.querySelector('.f-scroll').getBoundingClientRect().height),
                unlabelledButtons:[...document.querySelectorAll('button')].filter(b=>b.getClientRects().length&&!b.textContent.trim()&&!b.getAttribute('aria-label')).length,
                h1Count:document.querySelectorAll('h1').length,
            }));
            if(feed.overflow || feed.unlabelledButtons || feed.postHeight!==feed.feedHeight)throw new Error('Feed layout gate failed.');
            checks.push({device,theme,surface:'feed',...feed});
            for(const section of ['profile','messages','surveys']) {
                await page.locator(`${width<=760?'.f-mobile-nav':'.f-sidebar'} [data-feed-action=settings]`).first().click();
                await page.locator(`#customer-workspace [data-customer=${section}]`).click();
                if(section==='profile')await page.waitForSelector('[data-profile-form]');
                else await page.waitForFunction(()=>!document.querySelector('[data-list-state]')?.textContent.includes('Memuat'));
                const metrics=await page.locator('#customer-workspace').evaluate(el=>({overflow:el.scrollWidth>el.clientWidth,heading:el.querySelector('h2').textContent}));
                if(metrics.overflow)throw new Error('Customer workspace overflow.');
                await page.screenshot({path:`docs/customer/${section}-${device}-${theme}.png`});
                checks.push({device,theme,surface:section,...metrics});
                await page.locator('#customer-workspace [data-close]').click();
            }
        }
    }
    await page.emulateMedia({reducedMotion:'no-preference'});
    const evidence={checks,errors,failures,environment:'promerty_qa',synthetic:true,scope:'visual matrix and panel loading, not full functional/security signoff'};
    const downloadPromise=page.waitForEvent('download');
    await page.evaluate(value=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(value,null,2)],{type:'application/json'}));a.download='browser-verification.json';a.click();},evidence);
    await(await downloadPromise).saveAs('docs/customer/browser-verification.json');
    return {screenshots:24,checks:checks.length,errors,failures,environment:'promerty_qa'};
}
