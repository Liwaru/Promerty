async(page)=>{
    const stage='after',root='docs/customer/signature',records=[],errors=[],failed=[];
    if(!['before','after'].includes(stage))throw new Error('Stage guard');
    page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)failed.push({url:r.url(),status:r.status()})});
    await page.context().clearCookies();await page.emulateMedia({reducedMotion:'reduce'});
    for(const [device,width,height]of[['desktop',1440,1000],['mobile',390,844]]){
        await page.setViewportSize({width,height});
        for(const theme of ['light','dark']){
            await page.goto('http://127.0.0.1:8011/');await page.waitForSelector('.f-post');await page.evaluate(t=>window.PromertyTheme.set(t),theme);
            const shot=async surface=>{await page.screenshot({path:`${root}/${stage}-${surface}-${device}-${theme}.png`});records.push({surface,device,theme,overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)});};
            await shot('feed');
            await page.locator(`${device==='mobile'?'.f-mobile-nav':'.f-header'} [data-feed-action=search]`).click();await shot('search');await page.locator('#feed-search [data-close]').click();
            await page.locator('#feed-account').click();await shot('auth');await page.locator('#feed-auth [data-close]').first().click();
            await page.goto('http://127.0.0.1:8011/posts/2');await page.waitForSelector('.f-post');await page.locator('[data-action=detail]').click();await page.waitForSelector('#detail-body img');await shot('detail');await page.locator('#feed-detail [data-close]').click();
            await page.goto('http://127.0.0.1:8011/?q=SIGNATURE_NO_MATCH_QA');await page.waitForSelector('#feed-state h2');await shot('empty');
        }
    }
    const result={stage,records,errors,failed,synthetic:true,qa:'promerty_qa'};
    const download=page.waitForEvent('download');await page.evaluate(value=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(value,null,2)],{type:'application/json'}));a.download='signature.json';a.click()},result);await(await download).saveAs(`${root}/${stage}.json`);
    return{stage,captures:records.length,errors,failed};
}
