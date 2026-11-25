export default async function(page){
    const check=(value,message)=>{if(!value)throw new Error(message);};
    const root='C:/Users/Hendra Huang/Documents/new laravel/Promerty/docs/customer/qa/';
    const empty='http://localhost:8000/?category=2147483647',errors=[],bad=[],matrix=[];
    page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.status()>=400)bad.push(r.status()+' '+r.url());});
    await page.setViewportSize({width:1366,height:768});await page.goto(empty);await page.waitForSelector('.c-empty-editorial');
    await page.evaluate(()=>window.PromertyTheme.set('light'));await page.waitForTimeout(220);
    const contrast=await page.evaluate(()=>{
        const canvas=document.createElement('canvas'),ctx=canvas.getContext('2d',{willReadFrequently:true});
        const rgb=css=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=css;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].slice(0,3);};
        const lum=css=>rgb(css).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
        const ratio=(a,b)=>{const x=[lum(a),lum(b)].sort((a,b)=>a-b);return Number(((x[1]+.05)/(x[0]+.05)).toFixed(2));};
        const app=getComputedStyle(document.querySelector('.f-app')),button=getComputedStyle(document.querySelector('.c-empty-copy .p-button'));
        const heading=getComputedStyle(document.querySelector('.c-empty-copy h2')),text=getComputedStyle(document.querySelector('.c-empty-copy p')),gold=getComputedStyle(document.querySelector('.c-empty-copy .p-eyebrow'));
        const active=getComputedStyle(document.querySelector('.f-nav[aria-current=page]')),icon=getComputedStyle(document.querySelector('.f-nav[aria-current=page] .p-icon'));
        return {heading:ratio(heading.color,app.backgroundColor),subtitle:ratio(text.color,app.backgroundColor),button:ratio(button.color,button.backgroundColor),buttonBoundary:ratio(button.borderTopColor,app.backgroundColor),activeIcon:ratio(icon.color,active.backgroundColor),goldLabel:ratio(gold.color,app.backgroundColor),buttonBg:button.backgroundColor,buttonText:button.color};
    });
    check(contrast.heading>=4.5&&contrast.subtitle>=4.5&&contrast.button>=4.5&&contrast.goldLabel>=4.5&&contrast.buttonBoundary>=3&&contrast.activeIcon>=3,'actual text and control contrast');
    const primary=page.locator('.c-empty-copy .p-button');await primary.hover();await page.waitForTimeout(220);const hover=await primary.evaluate(e=>{
        const c=getComputedStyle(e),bg=getComputedStyle(e.closest('.f-app')).backgroundColor;
        const lum=css=>css.match(/[\d.]+/g).slice(0,3).map(Number).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
        const pair=[lum(c.borderTopColor),lum(bg)].sort((a,b)=>a-b);
        return {background:c.backgroundColor,color:c.color,border:c.borderTopColor,borderContrast:Number(((pair[1]+.05)/(pair[0]+.05)).toFixed(2))};
    });check(hover.background!==contrast.buttonBg&&hover.borderContrast>=3,'gold hover and control boundary');
    await primary.focus();check(await primary.evaluate(e=>getComputedStyle(e).outlineStyle)!=='none','visible keyboard focus');
    for(const [width,height] of [[1440,900],[1366,768],[768,1024],[390,844],[320,568]]){
        await page.setViewportSize({width,height});await page.goto(empty);await page.waitForSelector('.c-empty-editorial');await page.waitForTimeout(150);
        check(await page.evaluate(()=>document.documentElement.dataset.theme)==='light','theme persists on navigation');
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Home overflow '+width);
        const more=page.locator(width>760?'.f-sidebar [data-feed-action=settings]':'.f-mobile-nav [data-feed-action=settings]');
        await more.click();await page.waitForTimeout(250);let box=await page.locator('#customer-more').boundingBox();check(box.x>=0&&box.x+box.width<=width+1&&box.y+box.height<=height+1,'More bounds '+width);
        if(width===320)await page.screenshot({path:root+'light-refine-mobile-more.png'});
        await page.keyboard.press('Escape');await page.waitForTimeout(220);
        await page.locator(width>760?'#sidebar-search button':'.f-header [data-feed-action=search]').click();await page.waitForTimeout(250);box=await page.locator('#customer-search-drawer').boundingBox();check(box.x>=0&&box.x+box.width<=width+1&&box.y+box.height<=height+1,'Search drawer bounds '+width);
        check(await page.locator('#search-drawer-input').evaluate(e=>e===document.activeElement),'drawer autofocus');
        await page.locator('#search-drawer-input').fill('beng');await page.waitForTimeout(600);check(await page.locator('[data-query=Bengkong]').count()===1,'suggestion preserved');await page.keyboard.press('Escape');await page.waitForTimeout(220);
        await page.locator('#feed-account').click();await page.waitForTimeout(250);box=await page.locator('#feed-auth').boundingBox();check(box.x>=0&&box.x+box.width<=width+1&&box.y+box.height<=height+1,'login bounds '+width);
        if(width===1366)await page.screenshot({path:root+'light-refine-login.png'});
        await page.locator('[data-auth-mode=register]').click();check(await page.locator('#feed-auth input[name=name]').isVisible(),'register preserved');await page.keyboard.press('Escape');await page.waitForTimeout(220);
        await page.goto('http://localhost:8000/search');await page.waitForFunction(()=>document.querySelector('#search-results').getAttribute('aria-busy')==='false');
        check(!await page.locator('#search-filters').evaluate(d=>d.open),'Search default collapsed');await page.locator('#search-filters summary').click();check(await page.locator('[name=type]').isVisible(),'Primary/Secondary retained');
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Search overflow '+width);
        if(width===1366)await page.screenshot({path:root+'light-refine-search-page.png'});
        if(width===320)await page.screenshot({path:root+'light-refine-mobile-search.png'});
        matrix.push({width,height,home:true,more:true,searchDrawer:true,auth:true,search:true});
    }
    await page.setViewportSize({width:1366,height:768});await page.goto(empty);await page.waitForSelector('.c-empty-editorial');
    await page.locator('.f-sidebar [data-feed-action=settings]').click();await page.locator('[name=customer-theme][value=dark]').check();check(await page.evaluate(()=>document.documentElement.dataset.theme)==='dark','Dark selection');await page.reload();await page.waitForSelector('.c-empty-editorial');check(await page.evaluate(()=>document.documentElement.dataset.theme)==='dark','Dark persistence');
    await page.locator('.f-sidebar [data-feed-action=settings]').click();await page.emulateMedia({colorScheme:'dark',reducedMotion:'reduce'});await page.locator('[name=customer-theme][value=system]').check();await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');await page.emulateMedia({colorScheme:'light',reducedMotion:'reduce'});await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
    await page.keyboard.press('Escape');await page.waitForTimeout(100);await page.locator('#sidebar-search button').click();check(await page.locator('#customer-search-drawer').evaluate(d=>d.getAnimations().length)===0,'reduced motion');await page.keyboard.press('Escape');
    check(!errors.length&&!bad.length,'browser errors '+errors+' '+bad);
    return {status:'PASS',contrast,hover,matrix,system:true,errors,bad};
}
