export default async function(page){
    const check=(value,message)=>{if(!value)throw new Error(message);};
    const root='C:/Users/Hendra Huang/Documents/new laravel/Promerty/docs/customer/qa/';
    const errors=[],bad=[],matrix=[];page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)bad.push(r.status()+' '+r.url());});
    const ready=()=>page.waitForFunction(()=>document.querySelector('#search-results')?.getAttribute('aria-busy')==='false');
    const summary=()=>page.locator('#search-filters summary');
    for(const [width,height] of [[1440,900],[1366,768],[390,844],[320,568]]){
        await page.setViewportSize({width,height});await page.goto('http://localhost:8000/search');await ready();
        check(await page.locator('.s-header,[data-open-search-drawer],a[aria-label="Kembali ke feed"]').count()===0,'removed header markup');
        check(!await page.locator('#search-filters').evaluate(e=>e.open),'default collapsed');
        check(!await page.locator('[name=location]').isVisible()&&!await page.locator('#apply-search-filters').isVisible()&&!await page.locator('#search-filters button[type=reset]').isVisible(),'all filter fields/actions inside disclosure');
        check(await page.getByRole('button',{name:'Hapus Filter',exact:true}).count()===0,'no duplicate reset visible in empty state');
        check(!await page.locator('#advanced-search-filters').isVisible(),'advanced initially hidden');
        const position=await page.evaluate(()=>({width:innerWidth,introTop:document.querySelector('.s-intro').getBoundingClientRect().top,sidebarPosition:getComputedStyle(document.querySelector('.f-sidebar')).position,overflow:document.documentElement.scrollWidth>innerWidth}));
        check(!position.overflow&&position.introTop>=0&&position.introTop<=32,'natural top spacing');matrix.push(position);
        for(const theme of width===1366?['dark','light']:['dark']){
            await page.evaluate(t=>window.PromertyTheme.set(t),theme);await page.waitForTimeout(220);
            if(width===1366||width<=390)await page.screenshot({path:root+'search-cleanup-closed-'+width+'-'+theme+'.png'});
        }
        await summary().click();await page.locator('[name=location]').fill('Nagoya');await page.locator('[name=type]').selectOption('secondary');await page.locator('#toggle-advanced-filters').click();await page.locator('[name=min_price]').fill('40000000');
        await summary().click();check(!await page.locator('#apply-search-filters').isVisible(),'close hides apply');
        await summary().click();check(await page.locator('[name=location]').inputValue()==='Nagoya','main values retained');check(await page.locator('[name=min_price]').inputValue()==='40000000','advanced values retained');
        if(width===1366||width===320){await page.evaluate(()=>document.querySelector('#promerty-search').scrollTop=0);await page.screenshot({path:root+'search-cleanup-open-'+width+'.png'});}
    }
    await page.setViewportSize({width:1366,height:768});await page.goto('http://localhost:8000/search?location=Nagoya&kind=property&min_price=40000000');await ready();
    check(!await page.locator('#search-filters').evaluate(e=>e.open),'fresh URL with active filters stays collapsed');
    check(await page.locator('#search-filter-count').innerText()==='(2)','active count visible');
    check(await page.locator('#search-filter-count').isVisible(),'active count outside collapsed content');
    check(await page.locator('[data-result-post]').count()===1,'URL filters still applied');
    await summary().click();check(await page.locator('[name=location]').inputValue()==='Nagoya','URL edit value');
    await page.locator('#toggle-advanced-filters').click();check(await page.locator('[name=min_price]').inputValue()==='40000000','URL advanced edit value');
    // Open/closed disclosure state is restored together with cached results/scroll.
    const viewer=[];
    for(const open of [true,false]){
        if(await page.locator('#search-filters').evaluate(e=>e.open)!==open)await summary().click();
        await page.locator('[data-result-post]').first().click();await page.waitForURL('**/posts/**');await page.waitForTimeout(350);
        const saved=await page.evaluate(()=>{const from=new URL(location.href).searchParams.get('from');const d=JSON.parse(sessionStorage.getItem('promerty-search:listing-v4:'+from));return {scroll:d.scroll,filtersOpen:d.filtersOpen,advancedOpen:d.advancedOpen};});
        check(saved.filtersOpen===open,'cache stores main disclosure');
        await page.getByRole('link',{name:'← Hasil pencarian',exact:true}).click();await page.waitForURL('**/search?*');await ready();await page.waitForTimeout(180);
        await page.waitForFunction(expected=>Math.abs(document.querySelector('#promerty-search').scrollTop-expected)<=1,saved.scroll,{timeout:5000});
        const restored=await page.locator('#promerty-search').evaluate(e=>e.scrollTop);
        check(await page.locator('#search-filters').evaluate(e=>e.open)===open,'viewer restores chosen disclosure');
        check(await page.locator('#toggle-advanced-filters').getAttribute('aria-expanded')===String(saved.advancedOpen),'viewer restores advanced disclosure');
        check(Math.abs(restored-saved.scroll)<=1,'viewer restores scroll');viewer.push({...saved,restored});
    }
    await page.locator('[data-kind=video]').click();await ready();check(await page.locator('[data-result-post]').count()===1,'deduplicated video results');
    await page.goBack();await ready();check(await page.locator('[data-kind=property]').getAttribute('aria-current')==='page','Back restores tab');check(!await page.locator('#search-filters').evaluate(e=>e.open),'Back retains collapsed state');
    await page.goForward();await ready();check(await page.locator('[data-kind=video]').getAttribute('aria-current')==='page','Forward restores tab');
    await page.locator('#sidebar-search button').click();check(await page.locator('#customer-search-drawer').evaluate(d=>d.open),'sidebar Search drawer preserved');await page.keyboard.press('Escape');await page.waitForTimeout(220);
    await page.locator('.f-sidebar [data-feed-action=settings]').click();check(await page.locator('#customer-more').evaluate(d=>d.open),'More preserved');await page.keyboard.press('Escape');await page.waitForTimeout(220);
    await page.setViewportSize({width:320,height:568});await page.goto('http://localhost:8000/');await page.waitForTimeout(400);await page.locator('.f-header [data-feed-action=search]').click();check(await page.locator('#customer-search-drawer').evaluate(d=>d.open),'existing Home mobile Search drawer');await page.keyboard.press('Escape');await page.waitForTimeout(220);
    await page.locator('#feed-account').click();check(await page.getByRole('heading',{name:'Masuk Ke Promerty',exact:true}).isVisible(),'login preserved');await page.keyboard.press('Escape');await page.waitForTimeout(220);
    check(!errors.length&&!bad.length,'browser errors '+errors+' '+bad);
    return {status:'PASS',matrix,viewer,activeCount:2,errors,bad};
}
