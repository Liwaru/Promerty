async(page)=>{
    const stage='after',root=`docs/customer/signature-modern/${stage}-${Date.now()}`,records=[],errors=[],failed=[];
    if(!['before','after'].includes(stage))throw Error('Invalid capture stage');
    page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)failed.push({status:r.status(),path:new URL(r.url()).pathname})});
    await page.context().clearCookies();await page.emulateMedia({reducedMotion:'reduce'});
    for(const [device,width,height]of[['desktop',1440,1000],['mobile',390,844]]){
        await page.setViewportSize({width,height});for(const theme of ['light','dark']){
            const shot=async surface=>{await page.screenshot({path:`${root}/${surface}-${device}-${theme}.png`});records.push({surface,device,theme,overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)});};
            await page.goto('http://127.0.0.1:8011/posts/1');await page.waitForSelector('.f-post');await page.evaluate(t=>window.PromertyTheme.set(t),theme);await shot('feed');
            await page.locator('#feed-account').click();await shot('login');await page.locator('[data-auth-mode=register]').click();if(stage==='after')await page.waitForFunction(()=>document.querySelector('#auth-reason').textContent.startsWith('Daftar untuk'));await shot('register');await page.locator('#feed-auth-form [type=submit]').focus();const submit=await page.locator('#feed-auth-form [type=submit]').boundingBox();if(submit.y<0||submit.y+submit.height>height)throw Error('Registration submit inaccessible');await page.locator('#feed-auth [data-close]').first().click();
            await page.locator('[data-action=comment]').first().click();await page.waitForSelector('#feed-comments[open]');await shot('comments');await page.locator('#feed-comments [data-close]').click();
            await page.goto('http://127.0.0.1:8011/search?q=Kota+QA');await page.waitForSelector('.s-card');await shot('search');
            await page.goto('http://127.0.0.1:8011/posts/2');await page.waitForSelector('.f-post');await page.locator('[data-action=detail]').click();await page.waitForFunction(()=>document.querySelector('#detail-body img')?.naturalWidth>0);await shot('detail');await page.locator('#feed-detail [data-close]').click();
            await page.goto('http://127.0.0.1:8011/agents/2');await page.waitForSelector('#feed-agent[open] button');await shot('agent');await page.locator('#feed-agent [data-close]').click();
            const empty=url=>url.pathname==='/api/feed';await page.route(empty,r=>r.fulfill({status:200,contentType:'application/json',body:'{"data":[],"next_cursor":null}'}));
            await page.goto('http://127.0.0.1:8011/');await page.waitForSelector('#feed-state h2');await shot('empty-feed');await page.locator('#feed-state button').click();await page.waitForSelector('#search-input');if(new URL(page.url()).pathname!=='/search')throw Error('Empty CTA');await page.unrouteAll();
        }
    }
    const result={stage,root,records,errors,failed,qa:'promerty_qa',emptyFeed:'explicit empty-response injection; no database deletion',synthetic:true};const dl=page.waitForEvent('download');await page.evaluate(v=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(v,null,2)],{type:'application/json'}));a.download='visual.json';a.click()},result);await(await dl).saveAs(`${root}/visual.json`);return{stage,root,captures:records.length,errors,failed};
}
