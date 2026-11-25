async(page)=>{
    const root=`docs/customer/navigation/qa-${Date.now()}`,checks=[],comparisons=[],errors=[],http=[];
    const base='http://127.0.0.1:8011',assert=(v,m)=>{if(!v)throw Error(m)};
    page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)http.push({path:new URL(r.url()).pathname,status:r.status()})});
    const ready=()=>page.waitForFunction(()=>document.querySelector('#search-results')?document.querySelector('#search-results').getAttribute('aria-busy')==='false':!!document.querySelector('.f-post'));
    const sidebar=()=>page.locator('.f-sidebar').evaluate(el=>{const box=n=>{const r=n.getBoundingClientRect(),s=getComputedStyle(n);return{x:Math.round(r.x*100)/100,y:Math.round(r.y*100)/100,width:Math.round(r.width*100)/100,height:Math.round(r.height*100)/100,font:s.fontFamily,size:s.fontSize,padding:s.padding,gap:s.gap}};return{box:box(el),brand:box(el.querySelector('.c-brand-lockup')),search:box(el.querySelector('#sidebar-search')),items:[...el.querySelectorAll('.f-nav')].map(n=>({action:n.dataset.feedAction,...box(n)})),background:getComputedStyle(el).backgroundColor,scrollable:el.scrollHeight>el.clientHeight};});
    await page.context().clearCookies();await page.emulateMedia({reducedMotion:'reduce'});
    for(const [device,width,height]of[['desktop',1440,1000],['laptop',1280,720],['tablet',820,1180],['mobile',390,844],['landscape',844,390],['short',390,568]]){
        await page.setViewportSize({width,height});for(const theme of ['light','dark']){
            const pair=[];for(const [surface,path]of[['home','/'],['search','/search']]){
                await page.goto(base+path);await ready();await page.evaluate(t=>window.PromertyTheme.set(t),theme);
                assert(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),'Horizontal overflow');
                if(width>760)pair.push(await sidebar());else assert(await page.locator('.f-mobile-nav').isVisible(),'Mobile navigation missing');
                await page.screenshot({path:`${root}/${surface}-${device}-${theme}.png`});
            }
            if(width>760)assert(JSON.stringify(pair[0])===JSON.stringify(pair[1]),'Sidebar geometry/style differs '+device+' '+theme);
            comparisons.push({device,theme,matchingSidebar:width>760,sidebar:pair[0]||'hidden; shared bottom navigation'});
        }
    }
    await page.setViewportSize({width:1440,height:1000});await page.goto(base+'/search?q=Kota+QA&type=primary');await ready();const url=page.url();
    await page.locator('#sidebar-search button').click();assert(await page.locator('#search-input').evaluate(e=>e===document.activeElement),'Sidebar did not focus main input');assert(page.url()===url,'Sidebar discarded filters');checks.push({name:'Search sidebar focuses main input without changing filters',status:'PASS'});
    await page.emulateMedia({colorScheme:'dark',reducedMotion:'reduce'});await page.locator('[data-theme-select]').selectOption('system');await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');await page.emulateMedia({colorScheme:'light',reducedMotion:'reduce'});await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
    await page.locator('[data-theme-select]').selectOption('dark');await page.emulateMedia({colorScheme:'light',reducedMotion:'reduce'});assert(await page.evaluate(()=>document.documentElement.dataset.theme)==='dark','Manual override lost');await page.reload();await ready();assert(await page.evaluate(()=>window.PromertyTheme.preference)==='dark','Refresh lost preference');
    await page.locator('.f-sidebar .f-nav[data-feed-action=for-you]').click();await ready();assert(await page.evaluate(()=>document.documentElement.dataset.theme)==='dark','Cross-page preference lost');checks.push({name:'System follows OS; manual preference overrides and persists across refresh/navigation',status:'PASS'});
    await page.locator('#sidebar-search button').click();await ready();assert(await page.locator('#search-input').evaluate(e=>e===document.activeElement),'Feed search focus');await page.goBack();await ready();await page.goForward();await ready();checks.push({name:'Home/Search Back and Forward',status:'PASS'});
    await page.locator('.f-sidebar .f-nav[data-feed-action=messages]').click();await page.waitForSelector('#feed-auth[open]');assert(new URL(page.url()).searchParams.get('panel')==='messages','Missing workspace deep link');await page.locator('[data-auth-mode=login]').click();
    await page.evaluate(()=>{const i=document.createElement('input');i.type='file';i.id='private-nav-login';i.hidden=true;document.body.append(i)});await page.locator('#private-nav-login').setInputFiles('C:/Users/Hendra Huang/Documents/new laravel/Promerty/storage/app/private/phase4-20261110-73e56946/customer-runtime/comprehensive-verified.json');
    await page.evaluate(async()=>{const i=document.getElementById('private-nav-login'),c=JSON.parse(await i.files[0].text()),f=document.getElementById('feed-auth-form');f.elements.email.value=c.email;f.elements.password.value=c.password;i.remove();f.requestSubmit()});await page.waitForSelector('#customer-workspace[open]');
    for(const section of ['messages','surveys','activity','profile','settings']){
        if(await page.locator('#customer-workspace').evaluate(e=>e.open))await page.keyboard.press('Escape');
        await page.locator(`.f-sidebar .f-nav[data-feed-action=${section}]`).click();await page.waitForSelector('#customer-workspace[open]');
        assert(await page.locator(`.f-sidebar .f-nav[data-feed-action=${section}]`).getAttribute('aria-current')==='page','Workspace active state '+section);
        assert(new URL(page.url()).searchParams.get('panel')===section,'Workspace URL '+section);await page.screenshot({path:`${root}/workspace-${section}-dark.png`});
    }
    await page.locator('[data-customer=profile]').click();await page.waitForSelector('[data-profile-form]');await page.goBack();await page.waitForFunction(()=>document.querySelector('#customer-title').textContent==='Atur pengalaman Anda');await page.goForward();await page.waitForSelector('[data-profile-form]');await page.keyboard.press('Escape');checks.push({name:'Customer workspace active sections and Back/Forward',status:'PASS'});
    for(const section of ['following','saved']){await page.locator(`.f-sidebar .f-nav[data-feed-action=${section}]`).click();await page.waitForFunction(s=>document.querySelector(`.f-sidebar .f-nav[data-feed-action=${s}]`)?.getAttribute('aria-current')==='page',section);assert(new URL(page.url()).searchParams.get('view')===section,'Feed mode URL');}
    await page.goBack();await page.waitForFunction(()=>document.querySelector('.f-sidebar .f-nav[data-feed-action=following]')?.getAttribute('aria-current')==='page');checks.push({name:'Following/Saved active state and Back',status:'PASS'});
    await page.goto(base+'/search');await ready();await page.locator('[data-result-post]').first().click();await page.waitForSelector('.f-post');await page.locator('.s-viewer-context a').first().click();await ready();checks.push({name:'Search to existing viewer and return',status:'PASS'});
    const result={root,checks,comparisons,pageErrors:errors,httpErrors:http,qa:'promerty_qa'};const dl=page.waitForEvent('download');await page.evaluate(v=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(v,null,2)],{type:'application/json'}));a.download='navigation.json';a.click()},result);await(await dl).saveAs(`${root}/browser.json`);return result;
}
