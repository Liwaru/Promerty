export default async function(page){
    const check=(value,message)=>{if(!value)throw new Error(message);};
    const root='C:/Users/Hendra Huang/Documents/new laravel/Promerty/docs/customer/qa/';
    const errors=[],bad=[],matrix=[];page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)bad.push(r.status()+' '+r.url());});
    await page.goto('http://localhost:8000/search');await page.evaluate(()=>sessionStorage.removeItem('promerty-search-history:guest'));
    for(const [w,h] of [[1440,900],[1366,768],[1280,720],[768,1024],[390,844],[320,568]]){
        await page.setViewportSize({width:w,height:h});await page.goto('http://localhost:8000/search');await page.waitForFunction(()=>document.querySelector('#search-results').getAttribute('aria-busy')==='false');
        if(w<=760)await page.goto('http://localhost:8000/');
        await page.locator(w>760?'#sidebar-search button':'.f-header [data-feed-action=search]').click();await page.waitForTimeout(300);
        const result=await page.evaluate(()=>{
            const r=s=>{const b=document.querySelector(s).getBoundingClientRect();return {x:b.x,y:b.y,w:b.width,h:b.height,cy:b.y+b.height/2,bottom:b.bottom};};
            const logo=r('.f-sidebar-brand img'),title=r('#search-drawer-title'),icon=r('#sidebar-search button .p-icon'),input=r('#search-drawer-input');
            return {w:innerWidth,h:innerHeight,logo,title,icon,input,drawer:r('#customer-search-drawer'),footer:r('.c-search-footer'),overflow:document.documentElement.scrollWidth>innerWidth,listOverflow:document.querySelector('.c-search-results').scrollHeight>document.querySelector('.c-search-results').clientHeight};
        });
        if(w>760){check(Math.abs(result.logo.cy-result.title.cy)<=1,'title alignment '+w);check(Math.abs(result.icon.cy-result.input.cy)<=1,'input alignment '+w);check(!result.listOverflow,'initial desktop list should fit '+w);}
        check(!result.overflow&&result.drawer.bottom<=h+1&&result.footer.bottom<=h+1,'viewport clipping '+w);
        const values=await page.locator('[data-search-options] button').evaluateAll(els=>els.map(e=>e.dataset.query));
        check(values.length===6,'initial six recommendations');check(values.slice(3).join(',')==='Nagoya,Tiban,Nongsa','initial areas');
        if(w===1366){for(const theme of ['dark','light']){await page.evaluate(t=>window.PromertyTheme.set(t),theme);await page.waitForTimeout(250);await page.screenshot({path:root+'search-compact-'+theme+'.png'});}}
        if(w===320)await page.screenshot({path:root+'search-compact-mobile.png'});
        matrix.push(result);await page.keyboard.press('Escape');await page.waitForTimeout(220);
    }
    // Five private test queries in our own browser tab; only three may be rendered.
    await page.evaluate(()=>sessionStorage.setItem('promerty-search-history:guest',JSON.stringify(['sewa rumah Bengkong','rumah Nagoya','apartemen Tiban','ruko Nongsa','rumah Baloi'])));
    await page.setViewportSize({width:1366,height:450});await page.goto('http://localhost:8000/search');await page.waitForTimeout(400);await page.locator('#sidebar-search button').click();await page.waitForTimeout(280);
    check(await page.locator('[data-suggestion-type=recent]').count()===3,'three recent queries maximum');
    const inputY=await page.locator('#search-drawer-input').evaluate(e=>e.getBoundingClientRect().y),scroll=await page.locator('#promerty-search').evaluate(e=>e.scrollTop);
    await page.locator('.c-search-results').hover();await page.mouse.wheel(0,700);await page.waitForTimeout(200);
    check(await page.locator('.c-search-results').evaluate(e=>e.scrollTop>0),'internal list scroll');
    check(await page.locator('#search-drawer-input').evaluate(e=>e.getBoundingClientRect().y)===inputY,'input fixed while list scrolls');
    check(await page.locator('#promerty-search').evaluate(e=>e.scrollTop)===scroll,'list wheel does not move page');
    check(await page.locator('.c-search-footer').evaluate(e=>e.getBoundingClientRect().bottom<=innerHeight),'footer accessible on short viewport');
    await page.screenshot({path:root+'search-compact-history-scroll.png'});
    await page.locator('#search-drawer-input').fill('ba');await page.waitForTimeout(700);
    const dynamic=await page.locator('[data-search-options]').innerText();
    check(await page.locator('.c-search-results').evaluate(e=>e.scrollTop)===0,'new query starts at first suggestion');
    check(await page.locator('[data-search-options] h3').count()===1,'one dynamic section');
    check(!dynamic.includes('CONTOH PENCARIAN')&&!dynamic.includes('PENCARIAN TERAKHIR'),'replace initial sections');
    check(await page.locator('[data-search-options] button').count()<=8,'bounded suggestions');
    await page.screenshot({path:root+'search-compact-dynamic.png'});
    await page.locator('#search-drawer-input').fill('batu ampar');await page.waitForTimeout(600);check(await page.locator('[data-query="Batu Ampar"]').count()===1,'other Batam areas remain reachable');
    await page.locator('#search-drawer-input').fill('sewa rumah 2 lantai di Bengkong');await page.keyboard.press('Enter');await page.waitForURL('**q=sewa+rumah+2+lantai+di+Bengkong');
    await page.waitForFunction(()=>document.querySelectorAll('#search-grid .s-card').length===2);
    check((await page.locator('#search-interpretation').innerText()).includes('2 lantai'),'existing parser result');
    check(!errors.length&&!bad.length,'browser errors '+errors.join(';')+' '+bad.join(';'));
    return {status:'PASS',matrix,recentMax:3,internalScroll:true,dynamicSections:1,errors,bad};
}
