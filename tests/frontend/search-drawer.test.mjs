import {test} from 'node:test';
import assert from 'node:assert/strict';
import {matchingAreas,rememberSearch,drawerSearchUrl,normalizeSearch,drawerSuggestions} from '../../resources/js/search-drawer-state.js';
test('area suggestions are bounded, case insensitive and accept existing Center alias',()=>{
    assert.deepEqual(matchingAreas('batam c'),['Batam Center']);
    assert.deepEqual(matchingAreas('BENG'),['Bengkong']);
    assert.deepEqual(matchingAreas('Batam Centre'),['Batam Center']);
    assert.deepEqual(matchingAreas('Jakarta'),[]);
});
test('recent queries deduplicate case, normalize whitespace and retain at most five',()=>{
    let values=[];for(const value of ['a','b','c','d','e','f',' B '])values=rememberSearch(values,value);
    assert.deepEqual(values,['B','f','e','d','c']);
    assert.equal(normalizeSearch(' sewa   rumah '),'sewa rumah');assert.equal(normalizeSearch('x'.repeat(200)).length,120);
});
test('drawer navigation keeps valid tab/sort and starts fresh filters with safe encoding',()=>{
    const url=drawerSearchUrl('sewa rumah 2 lantai & Bengkong','?kind=property&sort=price_asc&location=Nagoya&type=primary&page=8&redirect=https://evil.test');
    assert.equal(url,'/search?kind=property&sort=price_asc&q=sewa+rumah+2+lantai+%26+Bengkong');
    assert.equal(drawerSearchUrl('https://evil.test','?kind=agent&sort=price_asc'),'/search?kind=agent&q=https%3A%2F%2Fevil.test');
});
test('typing replaces recommendations with one deduplicated bounded set; noninitial areas stay searchable',()=>{
    assert.deepEqual(drawerSuggestions(''),[]);
    assert.deepEqual(drawerSuggestions('batu ampar'),[{value:'Batu Ampar',type:'area'}]);
    const result=drawerSuggestions('rumah',{categories:[{name:'Rumah'}],items:Array.from({length:12},(_,i)=>({listing:{title:i?'Rumah '+i:'Rumah'}})),recent:['Rumah 2','rumah']});
    assert.equal(result.length,8);assert.equal(new Set(result.map(x=>x.value.toLowerCase())).size,8);
    assert.equal(result[0].type,'category');assert.equal(result[1].type,'property');
    assert.deepEqual(drawerSuggestions('zznotfound',{recent:['Rumah']}),[]);
});
