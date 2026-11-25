import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const tokens=JSON.parse(fs.readFileSync(new URL('../../design-tokens/promerty.json',import.meta.url),'utf8'));
const resolve=value=>value.startsWith('{')?tokens.brand[value.slice(1,-1)]:value;
const luminance=hex=>hex.slice(1).match(/../g).map(c=>parseInt(c,16)/255).map(c=>c<=.04045?c/12.92:((c+.055)/1.055)**2.4).reduce((n,c,i)=>n+c*[.2126,.7152,.0722][i],0);
const ratio=(a,b)=>{const values=[luminance(resolve(a)),luminance(resolve(b))].sort((a,b)=>a-b);return(values[1]+.05)/(values[0]+.05);};
test('light hover, deep gold labels and field boundaries retain accessible contrast',()=>{
    for(const background of [tokens.light.bg,tokens.light.card,tokens.light.surface,tokens.brand['light-champagne-surface'],tokens.brand['light-metallic-ivory']]){
        assert.ok(ratio(tokens.light.text,background)>=4.5);
        assert.ok(ratio(tokens.light['text-muted'],background)>=4.5);
        assert.ok(ratio(tokens.brand['gold-ink'],background)>=4.5);
        assert.ok(ratio(tokens.brand['gold-focus'],background)>=3);
        assert.ok(ratio(tokens.brand['light-gold-deep'],background)>=3);
        assert.ok(ratio(tokens.light['control-border'],background)>=3);
    }
    assert.ok(ratio(tokens.light['on-primary'],tokens.light.primary)>=4.5);
    assert.ok(ratio(tokens.light['on-primary'],tokens.light['primary-soft'])>=4.5);
    for(const stop of ['gold-soft','gold-primary','gold-deep']){
        assert.ok(ratio(tokens.dark['on-primary'],tokens.brand[stop])>=4.5,'Dark metallic CTA '+stop);
    }
});
test('architectural variants and shared theme mask preserve every original building path',()=>{
    const paths=file=>[...fs.readFileSync(new URL('../../public/images/'+file,import.meta.url),'utf8').matchAll(/<path\b[^>]*\bd="([^"]+)"/g)].map(m=>m[1]);
    const original=paths('promerty-architecture.svg');assert.equal(original.length,5);
    assert.deepEqual(paths('promerty-architecture-light.svg'),original);
    assert.deepEqual(paths('promerty-architecture-mask.svg'),original);
});
