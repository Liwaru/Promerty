import assert from 'node:assert/strict';

// Invoked by Playwright MCP with its existing isolated page; no browser dependency/install required.
export default async function verifyDialogs(page,origin='http://127.0.0.1:8012') {
    await page.setViewportSize({width:1440,height:900});await page.goto(origin+'/');
    await page.waitForLoadState('networkidle');
    const auth=page.locator('#feed-auth'),account=page.locator('#feed-account');
    const closed=async id=>page.waitForFunction(id=>!document.getElementById(id).open,id);
    await page.evaluate(()=>{window.qaBehindHits=0;document.querySelector('.f-header [data-feed-action=search]').addEventListener('click',()=>window.qaBehindHits++);});
    await account.click();await page.locator('#auth-email').click();assert.equal(await auth.evaluate(d=>d.open),true);
    const behind=await page.locator('.f-header [data-feed-action=search]').boundingBox();
    await page.mouse.click(behind.x+behind.width/2,behind.y+behind.height/2);await closed('feed-auth');
    assert.equal(await page.evaluate(()=>window.qaBehindHits),0);assert.equal(await page.evaluate(()=>document.activeElement.id),'feed-account');
    for(const method of ['x','escape']){await account.click();if(method==='x')await auth.locator('[data-close]').click();else await page.keyboard.press('Escape');await closed('feed-auth');assert.equal(await page.evaluate(()=>document.activeElement.id),'feed-account');}
    await account.click();await auth.locator('[data-auth-mode=register]').click();await page.locator('#auth-email').fill('unsaved@example.test');
    await page.keyboard.press('Escape');await page.locator('#dialog-discard [data-keep]').click();await closed('dialog-discard');
    assert.equal(await page.locator('#auth-email').inputValue(),'unsaved@example.test');assert.equal(await auth.evaluate(d=>d.open),true);
    await auth.locator('[data-close]').click();await page.locator('#dialog-discard [data-discard]').click();await closed('feed-auth');
    await account.click();assert.equal(await page.locator('#auth-email').inputValue(),'');
    let release;const held=new Promise(resolve=>release=resolve);let requests=0;
    await page.route('**/web-auth/login',async route=>{requests++;await held;await route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({message:'Synthetic validation',errors:{email:['Synthetic validation']}})});});
    await page.locator('#auth-email').fill('qa@example.test');await page.locator('#auth-password').fill('Synthetic-QA-password');
    await page.locator('#feed-auth-form button[type=submit]').click();await page.waitForFunction(()=>document.querySelector('#feed-auth-form').getAttribute('aria-busy')==='true');
    await page.mouse.click(10,10);assert.equal(await auth.evaluate(d=>d.open),true);
    assert.match(await auth.locator('[data-dialog-feedback]').innerText(),/Proses masih berjalan/);
    await page.locator('#feed-auth-form').evaluate(f=>f.requestSubmit());release();
    await page.waitForFunction(()=>document.querySelector('#feed-auth-form').getAttribute('aria-busy')!=='true');assert.equal(requests,1);
    await page.unroute('**/web-auth/login');await page.keyboard.press('Escape');await page.locator('#dialog-discard [data-discard]').click();await closed('feed-auth');
    const more=page.locator('.f-sidebar [data-feed-action=settings]');
    await more.click();await page.waitForFunction(()=>document.querySelector('#customer-more').dataset.dialogState==='open');
    await more.click();await page.waitForTimeout(40);await more.click();
    await page.waitForFunction(()=>document.querySelector('#customer-more').dataset.dialogState==='open');
    assert.equal(await page.locator('#customer-more').evaluate(d=>d.open&&!d.inert),true);
    await page.keyboard.press('Escape');await closed('customer-more');
    await page.emulateMedia({reducedMotion:'reduce'});await more.click();
    assert.equal(await page.locator('#customer-more').evaluate(d=>d.getAnimations().length),0);
    await page.keyboard.press('Escape');await closed('customer-more');await page.emulateMedia({reducedMotion:'no-preference'});
    return {outside:true,inside:true,noClickThrough:true,x:true,escape:true,focusRestoration:true,unsavedKeepDiscard:true,pendingWorkProtected:true,singleSubmit:requests===1,rapidReopen:true,reducedMotion:true};
}
