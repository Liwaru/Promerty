async(page)=>{
    await page.goto('http://127.0.0.1:8011/posts/1');await page.waitForSelector('.f-post');await page.locator('[data-action=message]').click();await page.waitForFunction(()=>document.querySelectorAll('#contact-history p').length===30);
    const last=await page.locator('#contact-history p').last().innerText();
    await page.route('**/api/listings/1/messages?page=2',route=>route.fulfill({status:503,contentType:'application/json',body:'{}'}));
    await page.locator('#contact-more').click();await page.waitForFunction(()=>!document.getElementById('contact-more').disabled);await page.unrouteAll();
    await page.locator('#contact-more').click();await page.waitForFunction(()=>document.querySelectorAll('#contact-history p').length>30);
    const contact=await page.locator('#contact-history').evaluate(el=>({rows:el.querySelectorAll('p').length,first:el.querySelector('p').textContent,last:el.querySelector('p:last-child').textContent,scrollable:el.scrollHeight>el.clientHeight}));
    const contactCompose=await page.locator('#contact-form').boundingBox();
    if(contact.last!==last||contact.first!=='SYNTHETIC QA conversation start'||contactCompose.y+contactCompose.height>844)throw new Error('Contact chronology/compose regression');
    await page.screenshot({path:'docs/customer/qa/message-send-mobile.png'});await page.locator('#feed-contact [data-close]').click();
    await page.locator('.f-mobile-nav [data-feed-action=settings]').click();await page.locator('[data-customer=messages]').click();await page.waitForSelector('[data-conversation]');await page.locator('[data-conversation]').first().click();await page.waitForFunction(()=>document.querySelectorAll('.c-list-row').length===20);
    await page.locator('[data-more]').click();await page.waitForFunction(()=>document.querySelectorAll('.c-list-row').length>20);
    const composer=await page.locator('.c-message-form').boundingBox(),rows=await page.locator('.c-list-row').count();
    if(composer.y+composer.height>844)throw new Error('Thread composer outside viewport');
    await page.screenshot({path:'docs/customer/qa/thread-pagination-mobile.png'});await page.locator('#customer-workspace [data-close]').click();
    const result={status:'PASS',contact,contactComposeBottom:contactCompose.y+contactCompose.height,threadRows:rows,threadComposeBottom:composer.y+composer.height,retryWithoutSkipping:true,environment:'promerty_qa'};
    const dl=page.waitForEvent('download');await page.evaluate(v=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([JSON.stringify(v,null,2)],{type:'application/json'}));a.download='chat.json';a.click()},result);await(await dl).saveAs('docs/customer/qa/chat.json');return result;
}
