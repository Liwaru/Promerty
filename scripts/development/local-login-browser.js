async(page)=>{
    const c=page.context().browser().contexts().find(c=>c.__devMain),accounts=c?.__devAccounts;
    if(!accounts)throw Error('Initialize credentials in memory only');
    const p=await c.newPage(),base='http://localhost:8000',results=[],errors=[],downloads=[];p.setDefaultTimeout(12000);
    p.on('pageerror',e=>errors.push(e.message));p.on('download',d=>downloads.push(d.suggestedFilename()));
    const assert=(x,m)=>{if(!x)throw Error(m)};
    for(const a of accounts){try{
        await p.goto(base+'/');await p.waitForSelector('#feed-account');await p.locator('#feed-account').click();await p.waitForSelector('#feed-auth[open]');await p.locator('#auth-email').fill(a.email);await p.locator('#auth-password').fill(a.password);
        const waiting=p.waitForResponse(r=>r.url().endsWith('/web-auth/login')&&r.request().method()==='POST');await p.locator('.c-auth-submit').first().click();const response=await waiting,data=await response.json();assert(response.status()===200&&data.user.role===a.role,'Login/role mismatch');await p.waitForFunction(()=>document.querySelector('#feed-account').textContent==='Keluar');await p.waitForFunction(()=>!document.querySelector('#feed-auth').open);await p.reload();await p.waitForFunction(()=>document.querySelector('#feed-account').textContent==='Keluar');
        const state=await p.evaluate(async()=>{const get=async path=>{const r=await fetch(path,{headers:{Accept:'application/json'}});return{status:r.status,data:await r.json().catch(()=>null)}};return{me:await get('/web-auth/me'),profile:await get('/api/customer/profile'),admin:await get('/api/admin/agents/pending')};});
        assert(state.me.data.user.role===a.role,'Refresh session lost');assert(state.profile.status===(a.role==='customer'?200:403),'Customer boundary failed');assert(state.admin.status===(a.role==='admin'?200:403),'Internal API boundary failed');assert(data.redirect_to==='/'&&data.dashboard_available===(a.role==='customer'),'Incorrect dashboard fallback');
        const path=new URL(p.url()).pathname;assert(path==='/','Unexpected redirect');
        await p.screenshot({path:'C:/Users/Hendra Huang/Documents/new laravel/Promerty/docs/customer/qa/development-login-'+a.role+'.png'});
        await p.locator('#feed-account').click();await p.waitForFunction(()=>document.querySelector('#feed-account').textContent==='Masuk');const guest=await p.evaluate(async()=>{const r=await fetch('/api/admin/agents/pending',{headers:{Accept:'application/json'}});const me=await(await fetch('/web-auth/me')).json();return{status:r.status,user:me.user}});assert(guest.user===null&&guest.status===401,'Logout/guest boundary failed');
        results.push({email:a.email,role:a.role,status:'PASS',userId:data.user.id,login:response.status(),path,redirectTo:data.redirect_to,dashboardAvailable:data.dashboard_available,sessionAfterRefresh:true,customerProfile:state.profile.status,adminApi:state.admin.status,logout:true,guestAdmin:guest.status});
    }catch(e){results.push({email:a.email,role:a.role,status:'FAIL',error:e.message});break;}}
    await p.goto(base+'/');const customerRegression={};await p.locator('#sidebar-search button').click();await p.waitForSelector('#customer-search-drawer[open]');await p.keyboard.press('Escape');await p.waitForFunction(()=>!document.querySelector('#customer-search-drawer').open);customerRegression.searchDrawer=true;
    await p.getByRole('button',{name:'Lainnya',exact:true}).first().click();await p.waitForSelector('#customer-more[open]');await p.keyboard.press('Escape');await p.waitForFunction(()=>!document.querySelector('#customer-more').open);customerRegression.moreDrawer=true;
    await p.goto(base+'/search');await p.waitForSelector('#search-input');customerRegression.searchPage=true;
    const dashboards=[];for(const path of ['/agent/dashboard','/branch/dashboard','/admin/dashboard','/owner/dashboard']){const r=await p.request.get(base+path,{headers:{Accept:'application/json'}});assert(r.status()===404,'Unexpected internal dashboard');dashboards.push({path,status:r.status()});}
    await p.goto(base+'/');assert(!downloads.length,'Unexpected browser download');return {origin:base,database:'promerty (HTTP identity verified separately)',results,customerRegression,dashboards,errors,downloads,viewport:[1366,768]};
}
