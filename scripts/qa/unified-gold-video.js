async(page)=>{
    const c=page.context().browser().contexts().findLast(c=>c.__architecturalVideo),p=c.pages()[0];
    const main=page.context().browser().contexts().findLast(c=>c.__gold),side=main.__goldSide,results=[],errors=[],network=[],downloads=[];
    p.on('pageerror',e=>errors.push(e.message));p.on('response',r=>{if(r.status()>=400)network.push({status:r.status(),url:r.url()})});p.on('download',d=>downloads.push(d.suggestedFilename()));
    await p.goto('http://127.0.0.1:8017/?agent=15');await p.waitForSelector('.f-post[data-id="156"]');
    for(const theme of ['light','dark']){
        await p.evaluate(t=>window.PromertyTheme.set(t),theme);
        for(const [id,orientation] of [[156,'portrait'],[157,'landscape']]){
            const post=p.locator('.f-post[data-id="'+id+'"]'),v=post.locator('video');await post.scrollIntoViewIfNeeded();await p.waitForFunction(id=>{const v=document.querySelector('.f-post[data-id="'+id+'"] video');return v?.readyState>=2&&v.videoWidth>0},id);
            await v.evaluate(v=>{v.muted=true;return v.play()});const start=await v.evaluate(v=>v.currentTime);await p.waitForTimeout(500);const advanced=await v.evaluate(v=>v.currentTime)>start;
            await v.evaluate(v=>{v.pause();v.currentTime=3});await p.waitForTimeout(200);
            results.push({theme,orientation,advanced,...await v.evaluate(v=>{const r=v.getBoundingClientRect(),s=getComputedStyle(v);return {width:v.videoWidth,height:v.videoHeight,fit:s.objectFit,error:v.error?.message||null,rect:{x:r.x,y:r.y,width:r.width,height:r.height}}})});
            await p.screenshot({path:'C:/Users/Hendra Huang/Documents/new laravel/Promerty/docs/customer/qa/unified-gold-'+side+'-video-'+orientation+'-'+theme+'.png'});
        }
    }
    return {side,results,errors,network,downloads};
}
