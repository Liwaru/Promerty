export default async function (page) {
    const origin='http://127.0.0.1:8042';
    const check=(condition,message)=>{if(!condition)throw new Error(message);};
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.emulateMedia({reducedMotion:'reduce'});
    const ready=()=>page.waitForFunction(()=>document.querySelector('#search-results')?.getAttribute('aria-busy')==='false');
    const cards=page.locator('[data-result-post]');
    await page.goto(origin+'/search');
    await ready();
    const cases=[
        ['sewa apartemen',[12,20]],['jual apartemen',[11,13,14]],['sewa rumah',[15]],['jual ruko',[17]],
        ['sewa gudang',[18]],['Pollux Habibie',[12,13,14]],['Permata Riverview',[19]],
        ['Tamarama Residence',[11]],['jual apartemen Tamarama',[11]],['sewa apartemen Pollux Habibie',[12]],
        ['Jakarta',[20]],['Tanjung Pinang',[21]],['Mezzanine',[11]],
    ];
    const verified={};
    for(const [query,expected] of cases){
        await page.locator('#search-input').fill(query);
        const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/search'
            &&new URL(r.url()).searchParams.get('q')===query);
        await page.locator('#search-input').press('Enter');
        check((await response).ok(),query+' API');
        await ready();
        const ids=await cards.evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.resultPost)).sort((a,b)=>a-b));
        check(JSON.stringify(ids)===JSON.stringify(expected),query+' rendered '+ids);
        verified[query]=ids;
    }
    await page.goto(origin+'/search?q=Pollux+Habibie');
    await ready();
    await page.locator('#search-filters summary').click();
    await page.locator('[name=transaction]').selectOption('sale');
    await page.locator('#search-category').selectOption({label:'Apartemen'});
    await page.locator('#toggle-advanced-filters').click();
    await page.locator('[name=max_price]').fill('1000000000');
    await page.locator('[name=bedrooms]').fill('1');
    const filteredResponse=page.waitForResponse(r=>new URL(r.url()).searchParams.get('max_price')==='1000000000');
    await page.locator('#apply-search-filters').click();
    check((await filteredResponse).ok(),'filter API');
    await ready();
    check(await cards.count()===1&&await cards.first().getAttribute('data-result-post')==='13','stacked filters');
    check(await page.evaluate(()=>document.activeElement.id)==='search-heading','filter focus');
    check((await cards.first().innerText()).includes('Instagram Reels'),'Instagram metadata badge');
    await cards.first().press('Enter');
    await page.waitForURL('**/posts/13?*');
    await page.getByRole('button',{name:'Muat video Instagram Reels'}).waitFor();
    check(await page.locator('iframe').count()===0,'provider frame is lazy');
    const revalidation=page.waitForResponse(response=>new URL(response.url()).pathname==='/api/search'
        &&new URL(response.url()).searchParams.get('max_price')==='1000000000');
    await page.getByRole('link',{name:/Hasil pencarian/}).click();
    check((await revalidation).ok(),'viewer return revalidates public results');
    await ready();
    check(await page.locator('[name=max_price]').inputValue()==='1000000000'&&await cards.count()===1,'viewer return restores filter');

    await page.goto(origin+'/search?q=sewa+apartemen+Pollux+Habibie');
    await ready();
    const photo=page.locator('[data-result-post="12"]');
    await photo.locator('img').waitFor();
    await page.waitForFunction(()=>document.querySelector('[data-result-post="12"] img')?.naturalWidth>0);
    await photo.click();
    await page.locator('[data-id="12"] .f-carousel img').waitFor();

    await page.goto(origin+'/search');
    await ready();
    check(await cards.count()===12,'first page size');
    await page.locator('#search-more').click();
    await ready();
    const paged=await cards.evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.resultPost)));
    check(paged.length>12&&new Set(paged).size===paged.length,'pagination dedupe');
    check(!paged.includes(22)&&!paged.includes(23),'draft/archive hidden');
    const local=page.locator('[data-result-post="2"]');
    await local.click();
    await page.locator('[data-id="2"] video').waitFor();
    const localUrl=await page.locator('[data-id="2"] video').getAttribute('data-src');
    check(localUrl?.startsWith('/api/listing-media/'),'local video remains linked');

    await page.goto(origin+'/search?q=jual+apartemen');
    await ready();
    const tamarama=page.locator('[data-result-post="11"]');
    const card=await tamarama.innerText();
    for(const content of ['Rp900.000.000','Dijual','Apartemen','Batam','1 KT','1 KM','2 balkon','TikTok']){
        check(card.includes(content),'Tamarama card missing '+content);
    }
    const matrix=[];
    for(const theme of ['dark','light']){
        await page.evaluate(value=>window.PromertyTheme.set(value),theme);
        for(const [width,height] of [[1440,900],[390,844],[320,700]]){
            await page.setViewportSize({width,height});
            const fits=await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth
                && document.querySelector('#promerty-search').scrollWidth<=innerWidth);
            check(fits,'horizontal overflow '+theme+' '+width);
            matrix.push({theme,width});
            if(width!==320)await page.screenshot({path:'.playwright-mcp/search-marketplace-'+theme+'-'+width+'.png'});
        }
    }
    await tamarama.click();
    await page.getByRole('button',{name:'Muat video TikTok'}).waitFor();
    await page.locator('[data-id="11"] [data-action=detail]').click();
    const detail=page.locator('#feed-detail[open]');
    check((await detail.innerText()).includes('Modern Mezzanine'),'detail shows public specification');
    await page.keyboard.press('Escape');
    await detail.waitFor({state:'hidden'});
    check(await page.evaluate(()=>document.activeElement?.dataset.action)==='detail','detail restores focus');
    check(errors.length===0,'application errors '+errors);
    await page.goto(origin+'/search?q=QA-kawasan-belum-terdaftar');
    await ready();
    check((await page.locator('#search-state').innerText()).includes('Belum ada hasil yang cocok'),'empty state');
    await page.route(origin+'/api/search?*',route=>route.fulfill({status:503,json:{message:'QA simulated failure'}}),{times:1});
    await page.reload();
    await page.getByRole('button',{name:'Coba lagi'}).click();
    await ready();
    check((await page.locator('#search-state').innerText()).includes('Belum ada hasil yang cocok'),'error retry');
    return {queries:verified,pagination:paged.length,stackedFilters:true,instagramMetadata:true,localViewer:true,photoFallback:true,errorRetry:true,matrix,errors};
}
