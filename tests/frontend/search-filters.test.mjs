import test from 'node:test';
import assert from 'node:assert/strict';
import {activeFilterKeys,advancedKeys,initAdvancedFilters} from '../../resources/js/search-filters.js';

test('active filters exclude defaults, keywords, sorting, unknown keys and nonrestricting zero lower bounds',()=>{
    const values=new URLSearchParams('q=Batam&sort=latest&kind=property&type=&city=Jakarta&min_price=0&max_price=0&bedrooms=0&bathrooms=0&min_area=0&location=Nagoya&transaction=rent&price_period=year');
    assert.deepEqual(activeFilterKeys(values),['location','transaction','max_price','price_period']);
    assert.deepEqual(activeFilterKeys(values,advancedKeys),['max_price','price_period']);
});
test('empty reset state has no active filters; advanced constraints survive URL roundtrip',()=>{
    assert.deepEqual(activeFilterKeys(new URLSearchParams('kind=video')),[]);
    const params=new URLSearchParams({min_price:'100',max_price:'200',bedrooms:'2',min_area:'120'});
    assert.equal(activeFilterKeys(new URLSearchParams(params.toString()),advancedKeys).length,4);
});
function setup(reduced=false){
    const motions=[];const label={textContent:''},attrs={};
    const button={setAttribute(k,v){attrs[k]=v},querySelector(){return label},addEventListener(){},focus(){}};
    const panel={hidden:true,inert:true,style:{},scrollHeight:240,contains(){return false},getBoundingClientRect(){return {height:120}},animate(){let done;const a={finished:new Promise(resolve=>done=resolve),cancel(){done()},finish(){done()}};motions.push(a);return a}};
    globalThis.document={activeElement:null};globalThis.window={matchMedia:()=>({matches:reduced})};globalThis.getComputedStyle=()=>({opacity:'1'});
    return {controller:initAdvancedFilters(button,panel),panel,attrs,label,motions};
}
test('rapid collapse/reopen cannot hide the newly opened fields or leave them inert',async()=>{
    const x=setup();const a=x.controller.setOpen(true),b=x.controller.setOpen(false),c=x.controller.setOpen(true);x.motions.at(-1).finish();await Promise.all([a,b,c]);
    assert.equal(x.panel.hidden,false);assert.equal(x.panel.inert,false);assert.equal(x.attrs['aria-expanded'],'true');assert.equal(x.label.textContent,'Sembunyikan filter lanjutan');
});
test('reduced motion removes hidden focus targets immediately without disabling form controls',async()=>{
    const x=setup(true);await x.controller.setOpen(true);await x.controller.setOpen(false);
    assert.equal(x.panel.hidden,true);assert.equal(x.panel.inert,true);assert.equal(x.attrs['aria-expanded'],'false');assert.equal(x.motions.length,0);assert.equal(x.panel.disabled,undefined);
});
