import test from 'node:test';
import assert from 'node:assert/strict';
import {searchParams,safeSearchReturn,publicSearchCard} from '../../resources/js/search-state.js';
test('search state keeps structured zero values, strips unknown and private keys',()=>{
    const p=searchParams(new URLSearchParams('q=Batam&bedrooms=0&password=secret&page=9&location=Batam+Kota'));
    assert.equal(p.get('bedrooms'),'0');assert.equal(p.get('location'),'Batam Kota');assert.equal(p.has('password'),false);assert.equal(p.has('page'),false);
    assert.equal(p.has('sort'),false);assert.equal(searchParams(new URLSearchParams()).toString(),'');
});
test('viewer return accepts only same-origin dedicated search URLs',()=>{
    const origin='https://promerty.example';
    assert.equal(safeSearchReturn('/search?q=Batam',origin),'/search?q=Batam');
    for(const u of ['https://evil.example/search','//evil.example/search','javascript:alert(1)','/admin','/search/../admin'])assert.equal(safeSearchReturn(u,origin),null);
});
test('cached grid cards exclude private coordinates, session details and viewer flags',()=>{
    const c=publicSearchCard({id:1,viewer:{saved:true},password:'secret',listing:{id:2,title:'Home',price:0,location:{city:'Batam',latitude:'private'},internal:'private'},agent:{name:'Agent',phone:'secret'},media:[{type:'video',url:'/tour.mp4',poster:'/poster.jpg'}]},'video');
    assert.equal(c.poster,'/poster.jpg');assert.equal(c.location,'Batam');assert.equal(c.price,0);
    assert.doesNotMatch(JSON.stringify(c),/private|secret|viewer|latitude|phone/);
});

test('property cards keep public listing facts and select valid lead media',()=>{
    const card=publicSearchCard({id:11,content_type:'video',
        listing:{id:15,project_name:'Tamarama Residence',category:'Apartemen',bedrooms:1,bathrooms:1,
            additional_specs:{balconies:2,internal:'private'},location:{label:'Batam',city:'Batam'}},
        agent:{name:'QA'},media:[{type:'image',url:'/obsolete.jpg'},
            {type:'video',url:'https://provider.example/video',external_video:{provider:'tiktok',url:'irrelevant'}}]},'property');
    assert.deepEqual([card.project,card.category,card.bedrooms,card.bathrooms,card.balconies,card.location,card.provider],
        ['Tamarama Residence','Apartemen',1,1,2,'Batam','tiktok']);
    assert.doesNotMatch(JSON.stringify(card),/private|irrelevant|obsolete/);
    assert.equal(searchParams({floors:1,balconies:2,min_building_area:45}).get('balconies'),'2');
});
