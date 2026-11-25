async(page)=>{
    const root='docs/customer/signature/resume-20261110-1525',checks=[],contrasts=[];
    const assert=(ok,message)=>{if(!ok)throw new Error(message)};
    await page.emulateMedia({reducedMotion:'reduce'});
    for(const [device,width,height]of[['laptop',1280,720],['short',390,568],['reflow',640,450]]){
        await page.setViewportSize({width,height});
        for(const theme of ['light','dark']){
            await page.goto('http://127.0.0.1:8011/');await page.waitForSelector('.f-post');await page.evaluate(t=>window.PromertyTheme.set(t),theme);
            const search=page.locator(`${width<=760?'.f-mobile-nav':'.f-header'} [data-feed-action=search]`);
            await search.click();
            for(let n=0;n<16;n++){await page.keyboard.press('Tab');assert(await page.locator('#feed-search').evaluate(el=>el.matches(':modal')&&(el.contains(document.activeElement)||document.activeElement===document.body)),'Search focus reached background content');}
            await page.locator('#feed-search-form button').first().focus();
            const action=await page.locator('#feed-search-form button').first().boundingBox();assert(action.y>=0&&action.y+action.height<=height,'Search action obscured');
            await page.screenshot({path:`${root}/probe-search-${device}-${theme}.png`});
            await page.keyboard.press('Escape');assert(await search.evaluate(el=>el===document.activeElement),'Escape failed to return focus');
            const contrast=await page.evaluate(()=>{
                const app=document.querySelector('.f-app'),s=getComputedStyle(app),c=document.createElement('canvas').getContext('2d');
                const rgb=value=>{c.clearRect(0,0,1,1);c.fillStyle=value;c.fillRect(0,0,1,1);return [...c.getImageData(0,0,1,1).data].slice(0,3)};
                const resolve=name=>{const e=document.createElement('span');e.style.color=`var(${name})`;app.append(e);const v=getComputedStyle(e).color;e.remove();return rgb(v)};
                const lum=rgb=>rgb.map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4}).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0);
                const ratio=(a,b)=>{const x=lum(resolve(a)),y=lum(resolve(b));return Math.round((Math.max(x,y)+.05)/(Math.min(x,y)+.05)*100)/100};
                return {text:ratio('--color-text','--customer-surface'),muted:ratio('--color-text-muted','--customer-surface'),goldInk:ratio('--customer-gold-ink','--customer-surface'),goldSelected:ratio('--customer-gold-ink','--color-accent-subtle'),controlBoundary:ratio('--customer-control-line','--color-bg'),primary:ratio('--color-on-primary','--color-primary'),error:ratio('--color-error','--color-bg'),reducedMotion:matchMedia('(prefers-reduced-motion:reduce)').matches,transition:getComputedStyle(document.querySelector('.p-button')).transitionDuration};
            });
            assert(contrast.text>=4.5&&contrast.muted>=4.5&&contrast.goldInk>=4.5&&contrast.goldSelected>=4.5&&contrast.primary>=4.5&&contrast.error>=4.5&&contrast.controlBoundary>=3,'Contrast gate');contrasts.push({device,theme,...contrast});
            await page.goto('http://127.0.0.1:8011/?q=SIGNATURE_NO_MATCH_QA');await page.waitForSelector('#feed-state h2');
            await page.locator('#feed-state button').focus();const box=await page.locator('#feed-state button').boundingBox();assert(box.y>=0&&box.y+box.height<=height,'Empty CTA obscured');
            await page.screenshot({path:`${root}/probe-empty-${device}-${theme}.png`});
            assert(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),'Horizontal overflow');
            await page.goto('http://127.0.0.1:8011/posts/1');await page.waitForSelector('.f-post');await page.locator('[data-action=message]').click();await page.waitForSelector('#contact-form textarea');
            await page.locator('#contact-form button').focus();const compose=await page.locator('#contact-form button').boundingBox();assert(compose.y>=0&&compose.y+compose.height<=height,'Composer send obscured');
            await page.screenshot({path:`${root}/probe-contact-${device}-${theme}.png`});await page.keyboard.press('Escape');
            checks.push({device,theme,searchFocusTrap:true,escapeFocusReturn:true,emptyCtaReachable:true,contactSendReachable:true,horizontalOverflow:false});
        }
    }
    const result={checks,contrasts,reflowNote:'640x450 is viewport reflow, not native browser 200% zoom',qa:'promerty_qa'};
    const dl=page.waitForEvent('download');await page.evaluate(v=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(v,null,2)],{type:'application/json'}));a.download='probes.json';a.click()},result);await(await dl).saveAs(`${root}/probes.json`);return result;
}
