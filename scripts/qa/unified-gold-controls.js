async(page)=>{
    const c=page.context().browser().contexts().findLast(c=>c.__architecturalVideo),p=c.pages()[0],checks=[];
    await p.bringToFront();
    for(const theme of ['light','dark']){
        await p.evaluate(t=>window.PromertyTheme.set(t),theme);
        for(const id of [156,157]){
            const post=p.locator('.f-post[data-id="'+id+'"]'),v=post.locator('video');
            await p.bringToFront();await post.scrollIntoViewIfNeeded();await p.waitForTimeout(350);
            await v.evaluate(v=>{v.currentTime=3});
            for(let i=0;i<2&&!await v.evaluate(v=>v.paused);i++){await post.locator('[data-action=play]').click();await p.waitForTimeout(150);}
            const pause=await v.evaluate(v=>v.paused);await post.locator('[data-action=play]').click();
            await p.bringToFront();const start=await v.evaluate(v=>v.currentTime);
            await p.waitForFunction(({id,start})=>document.querySelector('.f-post[data-id="'+id+'"] video').currentTime>start+.1,{id,start},{timeout:8000});const play=true;
            const muted=await v.evaluate(v=>v.muted);await post.locator('[data-action=mute]').click();const mute=(await v.evaluate(v=>v.muted))!==muted;await post.locator('[data-action=mute]').click();
            await post.locator('[data-action=fullscreen]').click();await p.waitForFunction(()=>!!document.fullscreenElement,null,{timeout:5000});
            const fullscreen=await p.evaluate(()=>!!document.fullscreenElement);
            await p.evaluate(()=>document.exitFullscreen());await p.waitForFunction(()=>!document.fullscreenElement);
            await v.evaluate(v=>v.pause());checks.push({id,theme,pause,play,mute,fullscreen,exit:true,exitMethod:'standard Fullscreen API'});
        }
    }
    return {checks};
}
