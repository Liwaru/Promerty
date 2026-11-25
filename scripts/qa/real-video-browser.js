async(page)=>{
    const root='C:/Users/Hendra Huang/Documents/new laravel/Promerty/';
    const context=page.context().browser().contexts().findLast(c=>c.pages().some(p=>p.url().includes('127.0.0.1:8017/')));
    if(!context)throw Error('Open authenticated QA browser context first');
    const p=await context.newPage(),base='http://127.0.0.1:8017',checks=[],errors=[],networkErrors=[],mediaResponses=[];
    p.setDefaultTimeout(12000);p.setDefaultNavigationTimeout(20000);
    p.on('pageerror',e=>errors.push(e.message));
    p.on('response',r=>{if(r.status()>=400)networkErrors.push({status:r.status(),url:r.url()});if(r.url().includes('/storage/qa-real-video/'))mediaResponses.push({status:r.status(),url:r.url(),range:r.headers()['content-range'],mime:r.headers()['content-type']});});
    const check=(ok,message)=>{if(!ok)throw Error(message);};
    const test=async(name,fn)=>{try{checks.push({name,status:'PASS',evidence:await fn()});}catch(e){checks.push({name,status:'FAIL',error:e.message});}};
    const post=id=>p.locator(`.f-post[data-id="${id}"]`);
    const show=async id=>{await post(id).scrollIntoViewIfNeeded();await post(id).locator('video').evaluate(v=>v.scrollIntoView({block:'center'}));await p.waitForFunction(id=>{const v=document.querySelector(`.f-post[data-id="${id}"] video`);return v?.videoWidth>0&&v.readyState>=2;},id,{timeout:30000});};
    const state=async id=>post(id).locator('video').evaluate(v=>({width:v.videoWidth,height:v.videoHeight,duration:v.duration,time:v.currentTime,paused:v.paused,muted:v.muted,ready:v.readyState,error:v.error?.message||null,objectFit:getComputedStyle(v).objectFit,rect:{width:v.getBoundingClientRect().width,height:v.getBoundingClientRect().height},decoded:v.getVideoPlaybackQuality?.().totalVideoFrames}));
    await test('Real Laravel feed/query and media metadata',async()=>{
        await p.goto(base+'/?agent=15');await p.waitForSelector('.f-post[data-id="156"]');
        const api=await p.evaluate(async()=>await(await fetch('/api/feed?agent=15')).json());
        check(api.data.length===2&&api.data.every(x=>[156,157].includes(x.id)),'Expected two real QA database posts');
        return api.data.map(x=>({id:x.id,title:x.listing.title,media:x.media}));
    });
    for(const [id,orientation] of [[156,'portrait'],[157,'landscape']])await test(orientation+' playback, controls, seek and fullscreen',async()=>{
        await show(id);const v=post(id).locator('video');
        if(await v.evaluate(v=>v.paused))await post(id).locator('[data-action=play]').click();
        const before=await state(id);await p.waitForTimeout(1400);const playing=await state(id);
        check(playing.time>before.time+.1&&playing.decoded>0,'Playback did not advance/decode');
        check(playing.objectFit==='contain','Video must use contain');
        check(Math.abs(playing.width/playing.height-(orientation==='portrait'?9/16:16/9))<.001,'Intrinsic aspect ratio mismatch');
        await post(id).locator('[data-action=play]').click();check(await v.evaluate(v=>v.paused),'Pause failed');
        const t=await v.evaluate(v=>v.currentTime);await p.waitForTimeout(350);check(Math.abs(await v.evaluate(v=>v.currentTime)-t)<.08,'Paused time advanced');
        const muted=await v.evaluate(v=>v.muted);await post(id).locator('[data-action=mute]').click();check(await v.evaluate(v=>v.muted)!==muted,'Mute toggle failed');await post(id).locator('[data-action=mute]').click();check(await v.evaluate(v=>v.muted)===muted,'Mute restore failed');
        await v.evaluate(v=>{v.currentTime=8;});await p.waitForFunction(id=>{const v=document.querySelector(`.f-post[data-id="${id}"] video`);return !v.seeking&&Math.abs(v.currentTime-8)<.2;},id,{timeout:20000});
        await post(id).locator('[data-action=play]').click();await p.waitForTimeout(500);
        await post(id).locator('[data-action=fullscreen]').click();await p.waitForFunction(()=>!!document.fullscreenElement);
        const full=await p.evaluate(()=>({tag:document.fullscreenElement.className,controls:document.fullscreenElement.querySelector('video').controls}));check(full.controls,'Native fullscreen controls missing');
        await p.screenshot({path:root+'docs/customer/qa/real-video-'+orientation+'-fullscreen.png'});
        await p.evaluate(()=>document.exitFullscreen());await p.waitForFunction(()=>!document.fullscreenElement);await p.waitForTimeout(250);
        await p.screenshot({path:root+'docs/customer/qa/real-video-'+orientation+'-desktop-dark.png'});
        return {playing,pausedStable:true,muteToggles:true,seekSeconds:8,fullscreen:full};
    });
    await test('Switch video pauses previous',async()=>{await show(156);await p.waitForTimeout(500);const states=await p.locator('video').evaluateAll(vs=>vs.map(v=>({post:v.closest('.f-post').dataset.id,paused:v.paused})));check(states.find(x=>x.post==='157').paused,'Offscreen landscape still playing');return states;});
    await test('Property detail modal',async()=>{await post(156).locator('[data-action=detail]').click();await p.waitForSelector('#feed-detail[open]');const text=await p.locator('#detail-body').innerText();check(text.includes('QA Video Portrait')&&text.includes('sintetis'),'Synthetic listing detail missing');await p.keyboard.press('Escape');return {syntheticDetail:true};});
    await test('Search actual API and viewer Back',async()=>{
        await p.goto(base+'/search?q=QA%20Video&kind=video&sort=relevance');await p.waitForSelector('.s-card[data-result-post]');
        const cards=await p.locator('.s-card[data-result-post]').evaluateAll(es=>es.map(e=>({id:e.dataset.resultPost,href:e.getAttribute('href')})));
        check(cards.length===2&&cards.every(x=>['156','157'].includes(x.id)),'Search must return real database fixtures');
        await p.screenshot({path:root+'docs/customer/qa/real-video-search.png'});await p.locator('.s-card[data-result-post="157"]').click();await show(157);await p.goBack();await p.waitForSelector('.s-card[data-result-post="157"]');await p.goForward();await show(157);return {cards,backForward:true};
    });
    const matrix=[];
    for(const [width,height,theme,scheme] of [[1440,900,'dark','dark'],[1366,768,'light','light'],[768,1024,'system','dark'],[390,844,'dark','dark'],[390,844,'light','light'],[320,568,'system','light']]){
        await test(`Responsive ${width}x${height} ${theme}`,async()=>{
            await p.setViewportSize({width,height});await p.emulateMedia({colorScheme:scheme});await p.goto(base+'/?agent=15');await p.evaluate(t=>window.PromertyTheme.set(t),theme);
            const items=[];for(const [id,orientation] of [[156,'portrait'],[157,'landscape']]){await show(id);await p.waitForTimeout(350);const v=post(id).locator('video');if(await v.evaluate(v=>v.paused))await post(id).locator('[data-action=play]').click();const t=await v.evaluate(v=>v.currentTime);await p.waitForTimeout(700);const s=await state(id);check(s.time>t+.1,'Responsive playback did not advance');check(s.objectFit==='contain'&&!s.error,'Responsive video error/fit');const overflow=await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);check(!overflow,'Horizontal overflow');await p.screenshot({path:root+`docs/customer/qa/real-video-${orientation}-${width}-${theme}.png`});items.push({id,...s});}
            const result={width,height,theme,resolved:await p.evaluate(()=>document.documentElement.dataset.theme),items};matrix.push(result);return result;
        });
    }
    await test('System live changes',async()=>{await p.evaluate(()=>window.PromertyTheme.set('system'));await p.emulateMedia({colorScheme:'dark'});await p.waitForFunction(()=>document.documentElement.dataset.theme==='dark');await p.emulateMedia({colorScheme:'light'});await p.waitForFunction(()=>document.documentElement.dataset.theme==='light');return {dark:true,light:true};});
    await p.setViewportSize({width:1366,height:768});await p.goto(base+'/?agent=15');await p.evaluate(()=>window.PromertyTheme.set('dark'));await show(156);
    const report={checks,matrix,errors,networkErrors,mediaResponses,url:base+'/?agent=15',database:'promerty_qa',noBrowserFixtureOrDomMediaInjection:true};
    // Return evidence to the MCP caller; persist it outside the browser, without downloads.
    return report;
}
