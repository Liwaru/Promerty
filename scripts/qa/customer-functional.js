async(page)=>{
    const base='http://127.0.0.1:8011',checks=[];
    const assert=(ok,message)=>{if(!ok)throw new Error(message);};
    const test=async(name,task)=>{try{checks.push({name,status:'PASS',evidence:await task()});}catch(e){checks.push({name,status:'FAIL',error:e.message});}};
    const request=async(path,method='GET',body)=>page.evaluate(async({path,method,body})=>{
        const session=await fetch('/web-auth/me',{headers:{Accept:'application/json'}}).then(r=>r.json());
        const r=await fetch(path,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':session.csrf_token,'X-Requested-With':'XMLHttpRequest'},...(body?{body:JSON.stringify(body)}:{})});
        return{status:r.status,body:await r.json().catch(()=>({}))};
    },{path,method,body});
    const login=async(kind='verified')=>{
        await page.locator('#feed-account').click();await page.locator('[data-auth-mode=login]').click();
        await page.evaluate(()=>{const i=document.createElement('input');i.type='file';i.id='private-qa-login';i.hidden=true;document.body.append(i)});
        await page.locator('#private-qa-login').setInputFiles(`C:/Users/Hendra Huang/Documents/new laravel/Promerty/storage/app/private/phase4-20261110-73e56946/customer-runtime/comprehensive-${kind}.json`);
        await page.evaluate(async()=>{const i=document.getElementById('private-qa-login'),c=JSON.parse(await i.files[0].text()),f=document.getElementById('feed-auth-form');f.elements.email.value=c.email;f.elements.password.value=c.password;i.remove();f.requestSubmit()});
        await page.waitForFunction(()=>!document.getElementById('feed-auth').open);
    };
    const workspace=async(section)=>{
        if(await page.locator('#customer-workspace').evaluate(el=>el.open))await page.locator('#customer-workspace [data-close]').click();
        await page.locator('.f-mobile-nav [data-feed-action=settings]').click();await page.locator(`#customer-workspace [data-customer=${section}]`).click();
        if(section==='profile')await page.waitForSelector('[data-profile-form]');else await page.waitForFunction(()=>!document.querySelector('[data-list-state]')?.textContent.includes('Memuat'));
    };
    const save=async()=>{const data={checks,environment:'promerty_qa',synthetic:true};const dl=page.waitForEvent('download');await page.evaluate(value=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(value,null,2)],{type:'application/json'}));a.download='functional.json';a.click()},data);await(await dl).saveAs('docs/customer/qa/functional.json');};
    await page.context().clearCookies();await page.setViewportSize({width:390,height:844});await page.goto(base+'/');await page.waitForSelector('.f-post');
    await test('Guest access and protected endpoints',async()=>{
        const feed=await request('/api/feed'),owned=await request('/api/customer/profile');assert(feed.status===200&&owned.status===401,'Guest access boundary');
        await page.locator('.f-post [data-action=save]').first().click();assert(await page.locator('#feed-auth').evaluate(el=>el.open),'Guest must see login gate');await page.locator('#feed-auth [data-close]').first().click();
        return{publicFeed:200,privateProfile:401};
    });
    await test('Normal email login',async()=>{await login();const s=await request('/web-auth/me');assert(s.body.user?.role==='customer'&&s.body.user.phone_verified,'Verified fixture login');return{role:'customer',fixture:'preverified, not SMS-delivered'};});
    await test('CSRF enforced over real HTTP',async()=>{
        const status=await page.evaluate(()=>fetch('/web-auth/logout',{method:'POST',headers:{Accept:'application/json'}}).then(r=>r.status));assert(status===419,'CSRF must reject');return{missingToken:status};
    });
    await test('Privileges, ownership, IDOR and private serializers',async()=>{
        const escalation=await request('/api/customer/profile','POST',{name:'SYNTHETIC QA verified','role_id':999});assert(escalation.status===422,'Role escalation must fail');
        const thread=await request('/api/customer/conversations/2/messages'),send=await request('/api/customer/conversations/2/messages','POST',{body:'FORBIDDEN'});
        const notification=await request('/api/customer/notifications/26/read','PATCH');const comment=await request('/api/customer/comments/1','DELETE');
        assert(thread.status===404&&send.status===404&&notification.status===404&&comment.status===403,'Foreign resources must be inaccessible');
        const notes=await request('/api/customer/notifications'),publicPost=await request('/api/posts/1');
        assert(!JSON.stringify(notes.body).includes('PRIVATE_NOTIFICATION_PAYLOAD')&&!JSON.stringify(notes.body).includes('FOREIGN_PRIVATE'),'Notification privacy');
        assert(!('email'in publicPost.body.data.agent)&&!('phone'in publicPost.body.data.agent),'Public agent contact leak');
        return{escalation:422,foreignThread:404,foreignSend:404,foreignNotification:404,foreignComment:403,privateDataExcluded:true};
    });
    await test('Duplicate likes, saves and follow; throttle independence',async()=>{
        const start=await request('/api/posts/1');for(let i=0;i<12;i++)assert((await request('/api/posts/1/like','POST')).status===200,'Like failed');
        for(const path of ['/api/posts/1/save','/api/agents/2/follow'])for(let i=0;i<2;i++)assert((await request(path,'POST')).status===200,'Idempotent social action');
        const end=await request('/api/posts/1');assert(end.body.data.counts.likes<=start.body.data.counts.likes+1,'Duplicate likes incremented');
        const following=await request('/api/customer/following'),saved=await request('/api/feed?saved=1');assert(following.body.data.some(a=>a.id===2)&&saved.body.data.some(p=>p.id===1),'Saved/followed navigation');
        return{duplicateReactionSafe:true,following:200,saved:200};
    });
    let commentId;
    await test('Comment, reply, edit and delete with literal HTML',async()=>{
        const root=await request('/api/posts/1/comments','POST',{content:'SYNTHETIC QA <b>literal text</b>'});assert(root.status===201,'Comment create');commentId=root.body.data.id;
        const reply=await request('/api/posts/1/comments','POST',{content:'SYNTHETIC QA reply',parent_id:commentId});assert(reply.status===201,'Reply');
        const children=await request(`/api/posts/1/comments?parent_id=${commentId}`);assert(children.body.data.some(c=>c.id===reply.body.data.id),'Reply list');
        assert((await request(`/api/customer/comments/${commentId}`,'PATCH',{content:'SYNTHETIC QA edited'})).status===200,'Own edit');
        assert((await request(`/api/customer/comments/${reply.body.data.id}`,'DELETE')).status===200,'Own reply delete');
        assert((await request(`/api/customer/comments/${commentId}`,'DELETE')).status===200,'Own root delete');return{create:201,reply:201,edit:200,delete:200};
    });
    let conversation;
    await test('Verified Customer sends from listing, then uses inbox',async()=>{
        for(const body of ['SYNTHETIC QA conversation start','SYNTHETIC QA second message']){const r=await request('/api/listings/1/messages','POST',{body});assert(r.status===201,'Listing message failed');conversation=r.body.data.conversation_id;}
        const inbox=await request('/api/customer/conversations');assert(inbox.body.data.filter(c=>c.id===conversation).length===1,'Conversation not present or duplicated');
        assert((await request(`/api/customer/conversations/${conversation}/messages`,'POST',{body:'SYNTHETIC QA inbox send'})).status===201,'Inbox send');
        const messages=await request(`/api/customer/conversations/${conversation}/messages`);assert(messages.body.data.length>=3&&messages.body.data[0].mine,'Own history');return{listingSend:201,inboxSend:201,conversation,history:200};
    });
    await test('Survey create, retry and owned history',async()=>{
        const data={requested_at:new Date(Math.floor((Date.now()+172800000)/60000)*60000).toISOString(),note:'SYNTHETIC QA comprehensive visit'};
        const first=await request('/api/listings/1/surveys','POST',data),retry=await request('/api/listings/1/surveys','POST',data);
        assert([200,201].includes(first.status)&&retry.status===200&&first.body.data.id===retry.body.data.id,'Survey retry duplicated or throttled');
        const list=await request('/api/customer/surveys');assert(list.body.data.some(s=>s.id===first.body.data.id&&s.status==='pending'&&!s.can_cancel),'Survey history');
        const past=await request('/api/listings/1/surveys','POST',{requested_at:'2020-01-01'});assert(past.status===422,'Past date validation');return{create:first.status,retry:200,sameId:true,past:422,cancellation:'dependency'};
    });
    await test('Notification pagination and read acknowledgement',async()=>{
        const first=await request('/api/customer/notifications'),second=await request('/api/customer/notifications?page=2');
        assert(first.body.data.length===20&&first.body.has_more&&second.body.data.length>=5,'Pagination');
        const id=first.body.data[0].id;assert((await request(`/api/customer/notifications/${id}/read`,'PATCH')).status===200,'Mark read');
        return{firstPage:20,secondPage:second.body.data.length,read:200,origin:'explicit synthetic fixtures; not real event delivery'};
    });
    await test('Focus survives workspace navigation and Escape returns to opener',async()=>{
        await workspace('profile');const focus=await page.evaluate(()=>document.getElementById('customer-workspace').contains(document.activeElement));assert(focus,'Focus escaped modal');
        await page.keyboard.press('Tab');assert(await page.evaluate(()=>document.getElementById('customer-workspace').contains(document.activeElement)),'Tab escaped modal');
        await page.keyboard.press('Escape');assert(await page.evaluate(()=>document.activeElement.dataset.feedAction==='settings'),'Focus not returned');return{headingFocus:true,tabContained:true,escapeRestores:true};
    });
    await test('Avatar validation and real upload through Customer form',async()=>{
        await workspace('profile');const form=page.locator('[data-profile-form]');
        await form.locator('[name=photo]').setInputFiles({name:'invalid.svg',mimeType:'image/svg+xml',buffer:Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"/>')});
        await form.locator('[type=submit]').click();await page.waitForFunction(()=>!!document.querySelector('[data-profile-form] [data-error]').textContent);
        const invalid=await form.locator('[name=photo]').getAttribute('aria-invalid');assert(invalid==='true','Invalid avatar field not identified');
        await form.locator('[name=photo]').setInputFiles('C:/Users/Hendra Huang/Documents/new laravel/Promerty/storage/app/private/phase4-20261110-73e56946/customer-runtime/app/public/qa-only/synthetic-placeholder.jpg');
        await form.locator('[type=submit]').click();await page.waitForFunction(()=>document.querySelector('#feed-notice')?.textContent.includes('berhasil disimpan'));
        const result=await request('/api/customer/profile');assert(result.body.data.photo?.startsWith('/storage/customer-avatars/'),'Avatar not stored');
        const imageStatus=await page.evaluate(url=>fetch(url).then(r=>r.status),result.body.data.photo);assert(imageStatus===200,'Avatar not served');
        await page.screenshot({path:'docs/customer/qa/avatar-mobile.png'});await page.locator('#customer-workspace [data-close]').click();return{invalidRejected:true,validUploaded:true,imageStatus};
    });
    await test('Session expiry reauthenticates and returns to Customer inbox',async()=>{
        await page.context().clearCookies();await page.locator('.f-mobile-nav [data-feed-action=messages]').click();await page.waitForFunction(()=>document.getElementById('feed-auth').open);
        await page.locator('[data-auth-mode=login]').click();await page.evaluate(()=>{const i=document.createElement('input');i.type='file';i.id='private-qa-login';i.hidden=true;document.body.append(i)});
        await page.locator('#private-qa-login').setInputFiles('C:/Users/Hendra Huang/Documents/new laravel/Promerty/storage/app/private/phase4-20261110-73e56946/customer-runtime/comprehensive-verified.json');
        await page.evaluate(async()=>{const i=document.getElementById('private-qa-login'),c=JSON.parse(await i.files[0].text()),f=document.getElementById('feed-auth-form');f.elements.email.value=c.email;f.elements.password.value=c.password;i.remove();f.requestSubmit()});
        await page.waitForSelector('#customer-workspace[open] [data-conversation]');await page.locator('#customer-workspace [data-close]').click();return{reauthGate:true,pendingDestination:'inbox'};
    });
    await test('Unavailable resources and invalid inputs fail safely',async()=>{
        assert((await request('/api/posts/999999')).status===404,'Missing post');assert((await request('/api/agents/999999')).status===404,'Missing agent');
        assert((await request('/api/feed?min_price=200&max_price=100')).status===422,'Price range');assert((await request('/api/listings/1/messages','POST',{body:''})).status===422,'Empty message');
        return{missingPost:404,missingAgent:404,invalidRange:422,emptyMessage:422};
    });
    await test('Logout revokes access and clears owned DOM and private drafts',async()=>{await page.locator('#feed-account').click();await page.waitForFunction(()=>document.getElementById('feed-account').textContent==='Masuk');const r=await request('/api/customer/profile');assert(r.status===401,'Logout access');assert(await page.locator('[data-customer-body]').innerText()==='','Owned DOM retained');assert(await page.locator('#contact-history').innerText()==='','Contact history retained');return{privateAccess:401,ownedDomCleared:true,historyCleared:true};});
    await save();return{checks,passed:checks.filter(c=>c.status==='PASS').length,failed:checks.filter(c=>c.status==='FAIL').length};
}
