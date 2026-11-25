async(page)=>{
    const context=page.context().browser().contexts().find(c=>c.__qaAccounts),accounts=context?.__qaAccounts;
    if(!accounts)throw Error('Initialize QA context with credentials in memory, never in this file');
    const p=await context.newPage(),base='http://127.0.0.1:8017',results=[],errors=[],downloads=[];
    p.setDefaultTimeout(12000);p.on('pageerror',e=>errors.push(e.message));p.on('download',d=>downloads.push(d.suggestedFilename()));
    const assert=(x,m)=>{if(!x)throw Error(m)};
    for(const account of accounts){try{
        await p.goto(base+'/?agent=15');await p.waitForSelector('#feed-account');
        await p.locator('#feed-account').click();await p.waitForSelector('#feed-auth[open]');await p.locator('#auth-email').fill(account.email);await p.locator('#auth-password').fill('deliberately-wrong');
        const wrong=p.waitForResponse(r=>r.url().endsWith('/web-auth/login')&&r.request().method()==='POST');await p.locator('.c-auth-submit').first().click();assert((await wrong).status()===422,'Wrong password accepted');await p.waitForFunction(()=>document.querySelector('#feed-auth-form').getAttribute('aria-busy')!=='true');
        await p.locator('#auth-password').fill(account.password);const login=p.waitForResponse(r=>r.url().endsWith('/web-auth/login')&&r.request().method()==='POST');await p.locator('.c-auth-submit').first().click();const response=await login,data=await response.json();assert(response.status()===200&&data.user.role===account.role,'Login role mismatch');assert(data.redirect_to==='/'&&data.dashboard_available===(account.role==='customer'),'Incorrect destination metadata');
        if(account.role!=='customer')await p.waitForURL(base+'/');await p.waitForFunction(()=>document.querySelector('#feed-account').textContent==='Keluar');await p.reload();await p.waitForFunction(()=>document.querySelector('#feed-account').textContent==='Keluar');
        const state=await p.evaluate(async()=>{const get=async u=>{const r=await fetch(u,{headers:{Accept:'application/json'}});return {status:r.status,body:await r.json().catch(()=>null)}};return {me:await get('/web-auth/me'),profile:await get('/api/customer/profile'),admin:await get('/api/admin/agents/pending')};});
        assert(state.me.body.user.role===account.role,'Session lost on refresh');assert(state.profile.status===(account.role==='customer'?200:403),'Customer scope bypass');assert(state.admin.status===(account.role==='admin'?200:403),'Admin/Owner separation failed');
        const security=await p.evaluate(async()=>{
            const csrf=(await(await fetch('/web-auth/me')).json()).csrf_token;
            const noCsrf=await fetch('/web-auth/logout',{method:'POST',headers:{Accept:'application/json'}});
            const badCsrf=await fetch('/web-auth/logout',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':'invalid'}});
            const update=await fetch('/api/me',{method:'PATCH',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({role:'owner',role_id:6})});
            return {noCsrf:noCsrf.status,badCsrf:badCsrf.status,profileUpdate:update.status,role:(await(await fetch('/web-auth/me')).json()).user.role};
        });assert(security.noCsrf===419&&security.badCsrf===419,'CSRF bypass');assert(security.profileUpdate===200&&security.role===account.role,'Role escalation');
        const missing=[];for(const path of ['/agent/dashboard','/branch/dashboard','/admin/dashboard','/owner/dashboard']){const r=await p.request.get(base+path,{headers:{Accept:'application/json'}});assert(r.status()===404,'Unexpected dashboard route');missing.push({path,status:r.status()});}
        await p.screenshot({path:'C:/Users/Hendra Huang/Documents/new laravel/Promerty/docs/customer/qa/five-role-'+account.role+'.png'});
        await p.locator('#feed-account').click();await p.waitForFunction(()=>document.querySelector('#feed-account').textContent==='Masuk');const guest=await p.evaluate(async()=>{const me=await(await fetch('/web-auth/me')).json();const r=await fetch('/api/admin/agents/pending',{headers:{Accept:'application/json'}});return {user:me.user,adminStatus:r.status};});assert(guest.user===null&&guest.adminStatus===401,'Logout or guest boundary failed');
        results.push({role:account.role,email:account.email,status:'PASS',wrongPassword:422,redirectTo:data.redirect_to,actualPath:account.role==='customer'?'/?agent=15':'/',dashboardAvailable:data.dashboard_available,sessionRefresh:true,customerProfile:state.profile.status,adminApi:state.admin.status,csrf:security,missingDashboards:missing,logout:true,guestAdmin:guest.adminStatus});
    }catch(e){results.push({role:account.role,email:account.email,status:'FAIL',error:e.message});await context.clearCookies();}}
    // Public registration is exercised only with a synthetic non-deliverable email.
    await p.goto(base+'/');const registration=await p.evaluate(async()=>{const csrf=(await(await fetch('/web-auth/me')).json()).csrf_token;const password=crypto.randomUUID();const r=await fetch('/web-auth/register',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({name:'QA role escalation test — synthetic',email:'qa-escalation-'+crypto.randomUUID()+'@example.invalid',password,password_confirmation:password,role:'owner',role_id:6})});const data=await r.json();const out={status:r.status,createdRole:data.user?.role,id:data.user?.id};await fetch('/web-auth/logout',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':data.csrf_token}});return out;});
    assert(registration.status===200&&registration.createdRole==='customer','Public registration escalation');
    assert(!downloads.length,'Unexpected downloads');return {results,registration,errors,downloads,database:'promerty_qa',origin:base,viewport:[1366,768]};
}
