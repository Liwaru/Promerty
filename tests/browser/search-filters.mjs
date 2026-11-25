import assert from 'node:assert/strict';

// Uses the actual Laravel page and the guarded QA fixture from search-filter-fixture.php.
export default async function verifySearchFilters(page,origin='http://localhost:8000') {
    await page.setViewportSize({width:1440,height:900});await page.goto(origin+'/search?kind=property&sort=price_asc');
    await page.waitForFunction(()=>document.querySelector('#search-results').getAttribute('aria-busy')==='false');
    assert.equal(await page.locator('.s-main-filters .p-field').count(),4);
    assert.equal(await page.locator('.s-advanced-grid .p-field').count(),9);
    assert.equal(await page.locator('#advanced-search-filters').evaluate(p=>p.hidden),true);
    assert.equal(await page.locator('[name=location]').getAttribute('placeholder'),'Kota, kawasan, atau proyek');
    assert.equal(await page.locator('#search-filters').evaluate(p=>p.open),false);
    await page.locator('#search-filters summary').click();
    const toggle=page.locator('#toggle-advanced-filters');await toggle.click();
    await page.locator('[name=min_price]').fill('40000000');await page.locator('[name=max_price]').fill('60000000');
    await page.locator('[name=price_period]').selectOption('year');await page.locator('[name=bedrooms]').fill('3');await page.locator('[name=bathrooms]').fill('2');await page.locator('[name=min_area]').fill('100');
    await toggle.click();await page.waitForFunction(()=>document.querySelector('#advanced-search-filters').hidden);
    assert.equal(await page.locator('[name=min_price]').inputValue(),'40000000');
    assert.equal(await page.locator('[name=min_price]').evaluate(i=>i.disabled),false);
    await page.locator('[name=location]').fill('Nagoya');await page.locator('[name=type]').selectOption('secondary');await page.locator('[name=transaction]').selectOption('rent');await page.locator('#search-category').selectOption({label:'Rumah'});
    const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/search'&&new URL(r.url()).searchParams.get('location')==='Nagoya');
    await page.locator('#apply-search-filters').click();assert.equal((await response).status(),200);
    await page.waitForFunction(()=>document.querySelector('#search-results').getAttribute('aria-busy')==='false');
    assert.equal(await page.locator('#search-filter-count').innerText(),'(10)');
    assert.equal(await page.locator('[data-result-post]').count(),1);
    assert.equal(await page.locator('#advanced-search-filters').evaluate(p=>p.hidden),true);
    assert.equal(await page.locator('[name=sort]').inputValue(),'price_asc');
    assert.equal(await page.evaluate(()=>document.activeElement.id),'search-heading');
    assert.equal(new URL(page.url()).searchParams.get('min_price'),'40000000');
    await page.locator('#search-filters summary').click();assert.equal(await page.locator('#search-filter-count').innerText(),'(10)');
    await page.locator('#search-filters summary').click();
    await page.locator('#search-filters button[type=reset]').click();
    await page.waitForFunction(()=>document.querySelector('#search-filter-count').textContent==='');
    assert.equal(await page.locator('[name=kind]').inputValue(),'property');assert.equal(await page.locator('[name=min_price]').inputValue(),'');
    await page.goBack();await page.waitForFunction(()=>document.querySelector('[name=min_price]').value==='40000000');
    assert.equal(await page.locator('#search-filter-count').innerText(),'(10)');assert.equal(await toggle.getAttribute('aria-expanded'),'false');
    await toggle.click();
    await page.locator('[name=min_price]').fill('90000000');await toggle.click();
    const before=page.url();await page.locator('#apply-search-filters').click();
    assert.equal(page.url(),before);assert.equal(await toggle.getAttribute('aria-expanded'),'true');assert.equal(await page.locator('#search-filter-error').isVisible(),true);
    assert.equal(await page.locator('[name=min_price]').inputValue(),'90000000');
    await page.locator('#search-filters button[type=reset]').click();await page.waitForFunction(()=>document.querySelector('#search-results').getAttribute('aria-busy')==='false');
    await page.locator('[name=location]').fill('QA-kawasan-belum-terdaftar');await page.locator('#apply-search-filters').click();
    await page.waitForFunction(()=>document.querySelector('#search-results').getAttribute('aria-busy')==='false');
    assert.equal(await page.locator('[data-result-post]').count(),0);assert.equal(await page.locator('[name=location]').inputValue(),'QA-kawasan-belum-terdaftar');
    assert.match(await page.locator('#search-state').innerText(),/Belum ada hasil yang cocok/);
    return {fourPrimary:true,nineAdvanced:true,hiddenValuesRetained:true,applyCount:10,matchingQaListing:1,sortRetained:true,reset:true,backRestore:true,invalidRangeRetained:true,unknownLocationEmpty:true};
}
