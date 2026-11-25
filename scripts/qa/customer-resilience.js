async(page)=>{
    const base='http://127.0.0.1:8011',checks=[],errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    const assert=(condition,message)=>{if(!condition)throw new Error(message);};
    const test=async(name,run)=>{try{checks.push({name,status:'PASS',evidence:await run()});}catch(e){checks.push({name,status:'FAIL',error:e.message});}finally{await page.unrouteAll({behavior:'ignoreErrors'});}};
    await page.context().clearCookies();await page.setViewportSize({width:390,height:844});
    await test('Photo detail uses actual image enum and retains rental period',async()=>{
        await page.goto(base+'/posts/2');await page.waitForSelector('.f-post');await page.locator('[data-action=detail]').click();await page.waitForFunction(()=>document.querySelector('#detail-body img')?.naturalWidth>0);
        const text=await page.locator('#detail-body').innerText();assert(text.includes('/ tahun'),'Rental period missing');await page.screenshot({path:'docs/customer/qa/detail-photo-mobile.png'});await page.locator('#feed-detail [data-close]').click();return{imageLoaded:true,rentalPeriod:true};
    });
    await test('Feed service failure exposes retry and recovers',async()=>{
        const matcher=url=>url.pathname==='/api/feed';await page.route(matcher,r=>r.fulfill({status:503,contentType:'application/json',body:'{"message":"SYNTHETIC QA injected outage"}'}));
        await page.goto(base+'/');await page.waitForSelector('#feed-state [data-feed-action=retry]');await page.screenshot({path:'docs/customer/qa/feed-error-mobile.png'});
        await page.unrouteAll();await page.locator('#feed-state [data-feed-action=retry]').click();await page.waitForSelector('.f-post');return{injected503:true,retryRecovered:true};
    });
    await test('Failed video has working retry',async()=>{
        await page.route('**/storage/qa-only/synthetic-placeholder.mp4',r=>r.abort('failed'));await page.goto(base+'/posts/1');await page.waitForSelector('[data-action=retry-media]');
        await page.unrouteAll();await page.locator('[data-action=retry-media]').click();await page.waitForFunction(()=>document.querySelector('video')?.readyState>=3);return{injectedFailure:true,retryReadyState:await page.locator('video').evaluate(v=>v.readyState)};
    });
    await test('Failed photo has working retry',async()=>{
        await page.route('**/storage/qa-only/synthetic-placeholder.jpg',r=>r.abort('failed'));await page.goto(base+'/posts/2');await page.waitForSelector('[data-action=retry-photo]');
        await page.unrouteAll();await page.locator('[data-action=retry-photo]').click();await page.waitForFunction(()=>document.querySelector('.f-carousel img')?.naturalWidth>0&&!document.querySelector('.f-carousel img').hidden);return{injectedFailure:true,imageRecovered:true};
    });
    await test('Delayed feed has visible loading state',async()=>{
        await page.route(url=>url.pathname==='/api/feed',async route=>{await new Promise(resolve=>setTimeout(resolve,1200));await route.continue();});
        await page.goto(base+'/',{waitUntil:'domcontentloaded'});await page.waitForFunction(()=>document.querySelector('#feed-state')?.textContent.includes('Memuat'));
        await page.screenshot({path:'docs/customer/qa/loading-mobile.png'});await page.waitForSelector('.f-post');return{delayMs:1200,loadingVisible:true,recovered:true};
    });
    await test('Search invalid bounds, empty state and reset',async()=>{
        await page.locator('.f-mobile-nav [data-feed-action=search]').click();await page.locator('#feed-search-form [name=min_price]').fill('200');await page.locator('#feed-search-form [name=max_price]').fill('100');await page.locator('#feed-search-form button').first().click();
        assert(await page.locator('#feed-search-form [name=max_price]').evaluate(el=>!el.validity.valid),'Invalid bounds accepted');
        await page.locator('#feed-search-form [type=reset]').click();await page.locator('#feed-search-form [name=q]').fill('SYNTHETIC_NO_MATCH_QA');await page.locator('#feed-search-form button').first().click();
        await page.waitForSelector('#feed-state h2');assert(await page.locator('#feed-state').innerText().then(t=>t.includes('Belum ada properti')),'Empty search missing');return{invalidBlocked:true,resetWorked:true,empty:true,url:page.url()};
    });
    await test('Long feed cursor and active media resource bounds',async()=>{
        await page.goto(base+'/');await page.waitForSelector('.f-post');const samples=[];
        for(let i=0;i<7;i++){
            const count=await page.locator('.f-post').count();samples.push(await page.evaluate(()=>({posts:document.querySelectorAll('.f-post').length,loadedVideos:document.querySelectorAll('.f-post video[src]').length,playingVideos:[...document.querySelectorAll('.f-post video')].filter(v=>!v.paused).length,domBytes:document.querySelector('#feed-scroll').outerHTML.length})));
            if(count>=34)break;
            await page.locator('#feed-scroll').evaluate(el=>el.scrollTop=el.scrollHeight);
            await page.waitForFunction(n=>document.querySelectorAll('.f-post').length>n,count);
        }
        const ids=await page.locator('.f-post').evaluateAll(nodes=>nodes.map(n=>n.dataset.id));assert(ids.length>=34&&new Set(ids).size===ids.length,'Cursor missing or duplicate posts');
        assert(samples.every(s=>s.loadedVideos<=2&&s.playingVideos<=1),'Unbounded active video resources');
        return{samples,uniquePosts:ids.length,limitation:'DOM grows with appended posts; no claim of unlimited-session memory stability'};
    });
    await test('Keyboard feed navigation and clipboard fallback when Web Share unavailable',async()=>{
        await page.goto(base+'/posts/2');await page.waitForSelector('.f-post');await page.context().grantPermissions(['clipboard-read','clipboard-write']);await page.bringToFront();
        await page.evaluate(()=>Object.defineProperty(navigator,'share',{value:undefined,configurable:true}));
        await page.locator('[data-action=share]').click();await page.waitForFunction(()=>document.querySelector('#feed-notice')?.textContent.includes('disalin'));
        const link=await page.evaluate(()=>navigator.clipboard.readText());assert(link===base+'/posts/2','Clipboard URL wrong');
        await page.goto(base+'/');await page.waitForSelector('.f-post');await page.locator('#feed-scroll').focus();await page.keyboard.press('PageDown');await page.waitForFunction(()=>document.querySelector('#feed-scroll').scrollTop>0);
        return{clipboardLink:link,keyboardScrolled:true};
    });
    const evidence={checks,unexpectedJavaScriptErrors:errors,environment:'promerty_qa',networkFailures:'Deliberate route fault injection; not production incidents'};
    const download=page.waitForEvent('download');await page.evaluate(value=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(value,null,2)],{type:'application/json'}));a.download='resilience.json';a.click()},evidence);await(await download).saveAs('docs/customer/qa/resilience.json');
    return{checks,unexpectedJavaScriptErrors:errors};
}
