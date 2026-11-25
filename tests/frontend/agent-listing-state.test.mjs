import test from 'node:test';
import assert from 'node:assert/strict';
import {listingActions,listingPayload,listingStatus} from '../../resources/js/agent-listing-state.js';

test('only editable lifecycle states expose edit/publication actions',()=>{
    assert.deepEqual(listingActions('draft'),{editable:true,publishable:true,archivable:true});
    assert.deepEqual(listingActions('active'),{editable:false,publishable:false,archivable:true});
    assert.deepEqual(listingActions('archived'),{editable:true,publishable:true,archivable:false});
    assert.deepEqual(listingActions('sold'),{editable:false,publishable:false,archivable:false});
    assert.equal(listingActions('unexpected').editable,false);
    assert.equal(listingStatus('active'),'Terpublikasi');
});
test('form keeps exact price strings, optional zero and nested property specifications',()=>{
    const form=new FormData();form.set('title',' Synthetic property ');form.set('price','9999999999999999.99');
    form.set('bedrooms','0');form.set('address','');form.set('garage','0');form.set('tower',' Tower QA ');
    const result=listingPayload(form);
    assert.equal(result.title,'Synthetic property');assert.equal(result.price,'9999999999999999.99');
    assert.equal(result.bedrooms,'0');assert.equal(result.address,null);assert.equal(result.additional_specs.garage,'0');
    assert.equal(result.additional_specs.tower,'Tower QA');assert.equal(result.additional_specs.apartment_floor,null);
    assert.equal('garage' in result,false);
    form.set('pending_media',new Blob(['SYNTHETIC']),'fixture.txt');
    assert.equal('pending_media' in listingPayload(form),false);
});
