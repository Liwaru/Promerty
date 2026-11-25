import test from 'node:test';
import assert from 'node:assert/strict';
import { propertyDetail } from '../../resources/js/property-detail.js';

const escape = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const post = () => ({listing:{title:'Rumah',type:'secondary',transaction_type:'rent',price:50000000,price_period:'year',location:{label:'Area publik',city:'Batam',visibility:'approximate'}},media:[],agent:{name:'Agent'},viewer:{saved:false}});

test('rental detail retains the period and does not invent missing specifications',()=>{
    const html=propertyDetail(post(),escape,n=>`Rp ${n}`);
    assert.match(html,/50000000 \/ tahun/); assert.match(html,/Disewakan/);
    assert.doesNotMatch(html,/<dt>Kamar tidur<\/dt>/); assert.doesNotMatch(html,/wa\.me/);
    assert.match(html,/Titik privat tidak dibagikan/);
});
test('untrusted listing text and media attributes are escaped',()=>{
    const p=post();p.listing.title='<script>bad()</script>';p.media=[{type:'image',url:'" onerror="bad()'}];
    const html=propertyDetail(p,escape,String);
    assert.doesNotMatch(html,/<script>/);assert.match(html,/&lt;script&gt;/);assert.match(html,/src="&quot; onerror=&quot;bad\(\)"/);
});
test('available specifications render zero values and saved action accurately',()=>{
    const p=post();p.listing.bedrooms=0;p.listing.land_area='120.00';p.viewer.saved=true;
    const html=propertyDetail(p,escape,String);
    assert.match(html,/<dt>Kamar tidur<\/dt><dd>0<\/dd>/);assert.match(html,/120.00 m²/);assert.match(html,/Hapus dari tersimpan/);
});
test('gallery preserves all supplied image URLs and safe agent action',()=>{
    const p=post();p.media=[{type:'image',url:'/original/one.jpg'},{type:'image',url:'/original/two.webp'}];
    const html=propertyDetail(p,escape,String);
    assert.match(html,/src="\/original\/one.jpg"/);assert.match(html,/src="\/original\/two.webp"/);
    assert.match(html,/data-detail-action="agent"/);assert.match(html,/2 \/ 2/);
});
test('additional public specifications keep zero values and escape tower text',()=>{
    const p=post();p.listing.orientation='Timur';p.listing.additional_specs={carport:0,garage:2,apartment_floor:10,tower:'<script>synthetic</script>'};
    const html=propertyDetail(p,escape,String);
    assert.match(html,/<dt>Carport<\/dt><dd>0<\/dd>/);assert.match(html,/<dt>Garasi<\/dt><dd>2<\/dd>/);
    assert.match(html,/<dt>Arah rumah<\/dt><dd>Timur<\/dd>/);assert.match(html,/<dt>Lantai apartemen<\/dt><dd>10<\/dd>/);
    assert.doesNotMatch(html,/<script>/);assert.match(html,/&lt;script&gt;synthetic&lt;\/script&gt;/);
});
