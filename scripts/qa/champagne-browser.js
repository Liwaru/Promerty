async(page)=>{
    const c=page.context().browser().contexts().findLast(c=>c.__gold),p=c.__goldPage,side=c.__goldSide;
    const root='C:/Users/Hendra Huang/Documents/new laravel/Promerty/docs/customer/qa/champagne-',base='http://localhost:8000';
    const errors=[],network=[],downloads=[],matrix=[],components=[];
    p.on('pageerror',e=>errors.push(e.message));p.on('response',r=>{if(r.status()>=400)network.push({status:r.status(),url:r.url()})});p.on('download',d=>downloads.push(d.suggestedFilename()));
    const shot=async name=>{await p.mouse.move(10,10);await p.waitForTimeout(250);await p.screenshot({path:root+side+'-'+name+'.png'});};
    const state=()=>p.evaluate(()=>{const app=document.querySelector('.f-app'),sidebar=document.querySelector('.f-sidebar'),empty=document.querySelector('.c-empty-editorial'),button=empty.querySelector('.p-button');const rect=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height}};const css=(e,pseudo)=>{const s=getComputedStyle(e,pseudo);return {background:s.backgroundImage,mask:s.maskImage,opacity:s.opacity,pointerEvents:s.pointerEvents}};return {theme:document.documentElement.dataset.theme,dpr:devicePixelRatio,overflow:document.documentElement.scrollWidth>innerWidth,geometry:{sidebar:rect(sidebar),header:rect(document.querySelector('.f-header')),empty:rect(empty),button:rect(button)},ambient:css(app),ornament:css(app,'::before'),sidebar:css(sidebar,'::after'),empty:css(empty),corner:css(empty,'::after'),button:css(button),copy:empty.innerText};});
    for(const [width,height] of [[1920,1080],[1440,900],[1366,768],[1280,720]]){
        await p.setViewportSize({width,height});await p.goto(base+'/');await p.waitForSelector('.c-empty-editorial');
        for(const theme of ['light','dark']){await p.evaluate(t=>window.PromertyTheme.set(t),theme);await shot('home-'+theme+'-'+width);matrix.push({width,height,...await state()});}
    }
    await p.setViewportSize({width:1366,height:768});
    for(const theme of ['light','dark']){
        await p.goto(base+'/');await p.waitForSelector('.c-empty-editorial');await p.evaluate(t=>window.PromertyTheme.set(t),theme);await p.waitForTimeout(300);
        await p.locator('.f-sidebar').screenshot({path:root+side+'-sidebar-'+theme+'.png'});
        await p.locator('#sidebar-search button').click();await p.waitForSelector('#customer-search-drawer[open]');await shot('search-drawer-'+theme);await p.keyboard.press('Escape');await p.waitForFunction(()=>!document.querySelector('#customer-search-drawer').open);
        await p.getByRole('button',{name:'Lainnya',exact:true}).first().click();await p.waitForSelector('#customer-more[open]');await shot('more-drawer-'+theme);await p.keyboard.press('Escape');await p.waitForFunction(()=>!document.querySelector('#customer-more').open);
        await p.locator('#feed-account').click();await p.waitForSelector('#feed-auth[open]');await shot('login-'+theme);await p.keyboard.press('Escape');await p.waitForFunction(()=>!document.querySelector('#feed-auth').open);
        await p.goto(base+'/search');await p.waitForSelector('#search-input');await p.waitForFunction(()=>document.querySelector('#search-results').getAttribute('aria-busy')==='false');await shot('search-page-'+theme);
        components.push({theme,searchDrawer:true,moreDrawer:true,loginModal:true,escape:true,searchPage:true,overflow:await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth)});
    }
    await p.goto(base+'/');await p.waitForSelector('.c-empty-editorial');await p.evaluate(()=>window.PromertyTheme.set('system'));await p.emulateMedia({colorScheme:'dark'});await p.waitForFunction(()=>document.documentElement.dataset.theme==='dark');await p.emulateMedia({colorScheme:'light'});await p.waitForFunction(()=>document.documentElement.dataset.theme==='light');
    await p.evaluate(()=>window.PromertyTheme.set('dark'));await p.reload();await p.waitForSelector('.c-empty-editorial');const persistence=await p.evaluate(()=>document.documentElement.dataset.theme==='dark');
    return c['__unified'+side]={side,matrix,components,errors,network,downloads,system:{dark:true,light:true},persistence,scope:'Desktop actual Laravel dynamic responses via private read-only runtime 8027; static main assets direct from localhost:8000'};
}

