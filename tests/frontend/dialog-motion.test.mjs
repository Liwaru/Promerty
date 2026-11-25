import test from 'node:test';
import assert from 'node:assert/strict';
import {DialogMotion,isOutsideRect,dismissalPolicy} from '../../resources/js/dialog-motion.js';

test('backdrop geometry distinguishes inside, padding/border and genuinely outside points',()=>{
    const box={left:100,right:500,top:100,bottom:600};
    for(const [x,y] of [[100,100],[500,600],[300,350]])assert.equal(isOutsideRect(box,x,y),false);
    for(const [x,y] of [[99,200],[501,200],[300,99],[300,601]])assert.equal(isOutsideRect(box,x,y),true);
});
test('dismissal protects pending work and explicit decisions before considering unsaved input',()=>{
    assert.equal(dismissalPolicy({busy:true,dirty:true}),'busy');
    assert.equal(dismissalPolicy({critical:true,dirty:true}),'critical');
    assert.equal(dismissalPolicy({dirty:true}),'confirm');
    assert.equal(dismissalPolicy({}),'close');
});
function animation(){let resolve;return {finished:new Promise(r=>resolve=r),cancel(){resolve();},finish(){resolve();}};}
test('a rapid reopen cancels a pending close without running its stale hide callback',async()=>{
    const motion=new DialogMotion(),opening=animation(),closing=animation(),reopening=animation();let hidden=0;
    const first=motion.run(true,{animate:()=>opening});opening.finish();await first;
    const close=motion.run(false,{animate:()=>closing,after:()=>hidden++});
    const reopen=motion.run(true,{animate:()=>reopening});
    assert.equal(motion.state,'opening');reopening.finish();
    assert.equal(await close,false);assert.equal(await reopen,true);assert.equal(hidden,0);assert.equal(motion.state,'open');
});
test('rapid close during opening settles closed once and ignores the stale open completion',async()=>{
    const motion=new DialogMotion(),enter=animation(),exit=animation();let shown=0,hidden=0;
    const a=motion.run(true,{animate:()=>enter,after:()=>shown++});
    const b=motion.run(false,{animate:()=>exit,after:()=>hidden++});exit.finish();
    assert.equal(await a,false);assert.equal(await b,true);assert.equal(shown,0);assert.equal(hidden,1);assert.equal(motion.state,'closed');
});
test('reduced motion runs open/close immediately without an animation or dangling closing state',async()=>{
    const motion=new DialogMotion();const events=[];
    await motion.run(true,{before:()=>events.push('show'),after:()=>events.push('shown')});
    assert.equal(motion.state,'open');
    await motion.run(false,{before:()=>events.push('close'),after:()=>events.push('closed')});
    assert.equal(motion.state,'closed');assert.equal(motion.animation,null);assert.deepEqual(events,['show','shown','close','closed']);
});
