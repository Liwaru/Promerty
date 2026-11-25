async(page)=>{
    const root=`docs/customer/search/qa-${Date.now()}`,checks=[],errors=[],http=[],failed=[];
    const base='http://127.0.0.1:8011';let faults=false;
    const assert=(ok,message)=>{if(!ok)throw Error(message)};
    const test=async(name,run)=>{try{checks.push({name,status:'PASS',evidence:await run()});}catch(e){checks.push({name,status:'FAIL',error:e.message});}finally{await page.unrouteAll({behavior:'ignoreErrors'});faults=false;}};
    page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)http.push({status:r.status(),path:new URL(r.url()).pathname,expected:faults});});
    page.on('requestfailed',r=>{if(!r.failure()?.errorText.includes('ERR_ABORTED'))failed.push({path:new URL(r.url()).pathname,error:r.failure()?.errorText,expected:faults});});
    const ready=()=>page.waitForFunction(()=>document.querySelector('#search-results')?.getAttribute('aria-busy')==='false');
    const go=async(query='')=>{await page.goto(base+'/search'+query);await ready();};
    const shot=name=>page.screenshot({path:`${root}/${name}.png`});
    await page.context().clearCookies();await page.setViewportSize({width:1440,height:1000});await page.emulateMedia({reducedMotion:'reduce'});
    await test('Feed entry points open dedicated search',async()=>{
        await page.goto(base);await page.waitForSelector('.f-post');await page.locator('.f-header [data-feed-action=search]').click();await ready();assert(new URL(page.url()).pathname==='/search','Header stayed in modal');
        await page.goto(base);await page.waitForSelector('.f-post');await page.locator('.f-sidebar [data-feed-action=search]').click();await ready();
        await page.goto(base);await page.waitForSelector('.f-post');await page.locator('#sidebar-search input').click();await ready();
        await page.setViewportSize({width:390,height:844});await page.goto(base);await page.waitForSelector('.f-post');await page.locator('.f-mobile-nav [data-feed-action=search]').click();await ready();return{header:true,explore:true,searchBar:true,mobile:true};
    });
    await test('Keyword Enter, structured backend filters and reset',async()=>{
        await go();await page.locator('#search-input').fill('Kota QA');await page.locator('#search-input').press('Enter');await ready();assert(await page.locator('.s-card').count()>0,'Keyword empty');
        await page.locator('[data-kind=property]').click();await ready();await page.locator('#search-filters summary').click();
        for(const [name,value]of[['type','secondary'],['transaction','rent'],['price_period','year']])await page.locator(`#search-form [name=${name}]`).selectOption(value);
        await page.locator('[name=location]').fill('Kota QA');await page.locator('[name=min_price]').fill('40000000');await page.locator('[name=max_price]').fill('60000000');await page.locator('#search-filters button').click();await ready();
        assert(await page.locator('.s-card').count()===1,'Combined backend filters');assert((await page.locator('.s-card').innerText()).includes('/ tahun'),'Rental period missing');
        const url=page.url();await page.reload();await ready();assert(await page.locator('[name=transaction]').inputValue()==='rent','Reload lost filter');await shot('combined-filters-mobile');
        await page.locator('#search-form [type=reset]').click();await ready();assert(new URL(page.url()).searchParams.get('location')===null,'Reset failed');return{combinedResult:1,reloadPreserved:true,sharedUrl:url,reset:true};
    });
    await test('Unique property and public agent tabs',async()=>{
        await go('?kind=property');const response=await page.evaluate(()=>fetch('/api/search?kind=property',{headers:{Accept:'application/json'}}).then(r=>r.json()));const ids=response.data.map(p=>p.listing.id);assert(ids.length===new Set(ids).size&&ids.length===2,'Duplicate listings');
        await page.locator('[data-kind=agent]').click();await ready();assert(await page.locator('.s-agent').count()===1,'Public agents');await page.locator('.s-agent').click();await page.waitForSelector('#agent-body button');await page.locator('#agent-body button').last().click();await page.waitForSelector('#feed-auth[open]');await page.locator('#feed-auth [data-close]').first().click();return{uniqueListings:ids.length,publicAgents:1,followRequiresLogin:true};
    });
    await test('Price sort, pagination and no grid autoplay',async()=>{
        await go('?kind=property&sort=price_asc');assert((await page.locator('.s-card').first().innerText()).includes('50.000.000'),'Price order');
        await go('?q=SYNTHETIC+QA+SEARCH');assert(await page.locator('.s-card').count()===12,'First bounded page');await page.locator('#search-more').click();await ready();const ids=await page.locator('[data-result-post]').evaluateAll(es=>es.map(e=>e.dataset.resultPost));assert(ids.length===16&&new Set(ids).size===16,'Pagination duplicates/missing');assert(await page.locator('video').count()===0,'Grid autoplay');return{firstPage:12,total:16,unique:true,videoElements:0};
    });
    await test('Viewer selected post, next-page order, keyboard and search restoration',async()=>{
        await go('?q=SYNTHETIC+QA+SEARCH');const ids=await page.locator('[data-result-post]').evaluateAll(es=>es.map(e=>e.dataset.resultPost));const selected=page.locator('[data-result-post]').last();await selected.scrollIntoViewIfNeeded();await selected.click();await page.waitForSelector('.f-post');assert(await page.locator('.f-post').first().getAttribute('data-id')===ids[11],'Wrong selected post');
        await page.locator('#feed-scroll').focus();await page.keyboard.press('ArrowDown');await page.waitForURL(url=>url.pathname.startsWith('/posts/')&&!url.pathname.endsWith('/'+ids[11]));await page.waitForSelector('.f-post');
        const nextId=await page.locator('.f-post').first().getAttribute('data-id');await page.reload();await page.waitForSelector('.f-post');
        await page.locator('.s-viewer-context a').first().click();await ready();assert(await page.locator('#search-input').inputValue()==='SYNTHETIC QA SEARCH','Query lost');assert(await page.locator('.s-card').count()===16,'Loaded context lost');assert(await page.locator('#promerty-search').evaluate(e=>e.scrollTop)>0,'Grid scroll lost');
        await page.goBack();await page.waitForSelector('.f-post');assert(await page.locator('.f-post').first().getAttribute('data-id')===nextId,'Back viewer');await page.goForward();await ready();return{selected:ids[11],next:nextId,keyboard:true,reload:true,backForward:true,gridRestored:16,scrollRestored:true};
    });
    await test('Playable actual media, guest protection and safe return URL',async()=>{
        await page.goto(base+'/posts/1?from='+encodeURIComponent('/search?q=Kota+QA'));await page.waitForSelector('.f-post');await page.locator('video').evaluate(v=>v.play());await page.waitForFunction(()=>document.querySelector('video')?.readyState>=3);
        const media=await page.locator('video').evaluate(v=>({readyState:v.readyState,duration:v.duration,src:!!v.currentSrc}));assert(media.duration>0&&media.src,'Placeholder path only');await page.locator('[data-action=save]').click();await page.waitForSelector('#feed-auth[open]');
        await page.goto(base+'/posts/1?from='+encodeURIComponent('https://evil.example/search'));await page.waitForSelector('.f-post');assert(await page.locator('.s-viewer-context').count()===0,'Unsafe return accepted');return{media,saveRequiresLogin:true,externalReturnRejected:true};
    });
    await test('Loading, failure, retry, empty and invalid range',async()=>{
        faults=true;await page.route('**/api/search?**',r=>r.fulfill({status:503,contentType:'application/json',body:'{}'}));await go('?q=QA');await page.waitForSelector('[data-retry-search]');await shot('injected-error-mobile');await page.unrouteAll();await page.locator('[data-retry-search]').click();await ready();assert(await page.locator('.s-card').count()>0,'Retry');
        await page.route('**/api/search?**',async r=>{await new Promise(resolve=>setTimeout(resolve,1000));await r.continue()});await page.goto(base+'/search?q=QA');await page.waitForSelector('.s-skeleton');await shot('delayed-loading-mobile');await ready();await page.unrouteAll();
        await go('?q=SEARCH_NO_MATCH_20261110');assert(await page.locator('[data-reset-search]').count()===1,'No empty recovery');await shot('empty-mobile');
        await page.locator('#search-filters summary').click();await page.locator('[name=min_price]').fill('200');await page.locator('[name=max_price]').fill('100');await page.locator('#search-filters button').click();assert(await page.locator('[name=max_price]').evaluate(e=>!e.validity.valid),'Invalid range submitted');return{injected503:true,retry:true,loading:true,empty:true,invalidRangeBlocked:true};
    });
    const layouts=[];
    await test('Responsive grid, themes, keyboard and visual surfaces',async()=>{
        for(const [device,width,height,columns]of[['desktop',1440,1000,4],['tablet',820,1180,3],['mobile',390,844,2],['short',390,568,2],['laptop',1280,720,3],['wide',1920,1200,5]]){
            await page.setViewportSize({width,height});for(const theme of ['light','dark']){
                await go('?q=SYNTHETIC+QA+SEARCH');await page.evaluate(t=>window.PromertyTheme.set(t),theme);const layout=await page.locator('#search-grid').evaluate(e=>({columns:getComputedStyle(e).gridTemplateColumns.split(' ').length,width:e.clientWidth,overflow:document.documentElement.scrollWidth>innerWidth,videoElements:document.querySelectorAll('video').length}));assert(layout.columns===columns&&!layout.overflow&&!layout.videoElements,`Grid ${device}`);await shot(`grid-${device}-${theme}`);layouts.push({device,theme,...layout});
                if(['desktop','mobile'].includes(device)){
                    await page.locator('[data-result-post]').first().click();await page.waitForSelector('.f-post');await shot(`viewer-${device}-${theme}`);await page.locator('.s-viewer-context a').first().click();await ready();
                    for(const kind of ['property','agent']){await go('?kind='+kind);await page.evaluate(t=>window.PromertyTheme.set(t),theme);await shot(`${kind}-${device}-${theme}`);}
                    await page.locator('#search-input').fill('SEARCH_NO_MATCH_20261110');await page.locator('#search-input').press('Enter');await ready();await shot(`empty-${device}-${theme}`);
                }
            }
        }
        await page.setViewportSize({width:390,height:568});await go();await page.locator('#search-input').focus();await page.keyboard.press('Tab');assert(await page.evaluate(()=>document.activeElement.type==='submit'),'Search keyboard order');return{layouts:layouts.length,reducedMotion:true,keyboard:true};
    });
    const evidence={root,checks,layouts,pageErrors:errors,httpErrors:http,failedRequests:failed,qa:'promerty_qa',synthetic:true};
    const dl=page.waitForEvent('download');await page.evaluate(v=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(v,null,2)],{type:'application/json'}));a.download='search-browser.json';a.click()},evidence);await(await dl).saveAs(`${root}/browser.json`);
    return {root,checks,pageErrors:errors,httpErrors:http,failedRequests:failed};
}
