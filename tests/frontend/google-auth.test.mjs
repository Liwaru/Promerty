import test from 'node:test';
import assert from 'node:assert/strict';
import {googleReturnPath,bindGoogleLogin} from '../../resources/js/google-auth.js';

test('OAuth return paths preserve known local pages without query tokens',()=>{
    for(const path of ['/','/search','/posts/12','/agents/3'])assert.equal(googleReturnPath(path),path);
    for(const path of ['https://evil.test','//evil.test','/\\evil.test','/admin','/posts/1?code=secret','/auth/google/callback','/posts/0','/search\n',null])assert.equal(googleReturnPath(path),'/');
});
test('configured Google button starts one local navigation and marks busy',()=>{
    const handlers={},attrs={},visited=[];
    const button={disabled:false,dataset:{googleUrl:'/auth/google/redirect'},addEventListener:(k,f)=>handlers[k]=f,setAttribute:(k,v)=>attrs[k]=v};
    bindGoogleLogin(button,'/posts/12',url=>visited.push(url));handlers.click();handlers.click();
    assert.deepEqual(visited,['/auth/google/redirect?return_to=%2Fposts%2F12']);assert.equal(attrs['aria-busy'],'true');
});
test('disabled and tampered Google controls cannot navigate',()=>{
    let listener;let calls=0;
    const button={disabled:true,dataset:{googleUrl:'/auth/google/redirect'},addEventListener:(_,f)=>listener=f};
    bindGoogleLogin(button,'/',()=>calls++);assert.equal(listener,undefined);
    button.disabled=false;bindGoogleLogin(button,'/',()=>calls++);button.dataset.googleUrl='https://evil.test';listener();assert.equal(calls,0);
});
test('OAuth resolves the selected post at click time',()=>{
    let click;
    let currentPath='/';
    const visited=[];
    const button={disabled:false,dataset:{googleUrl:'/auth/google/redirect'},addEventListener:(_,fn)=>click=fn,setAttribute:()=>{}};
    bindGoogleLogin(button,()=>currentPath,url=>visited.push(url));
    currentPath='/posts/27';
    click();
    assert.deepEqual(visited,['/auth/google/redirect?return_to=%2Fposts%2F27']);
});
test('browser Back restores the configured button after cached navigation',()=>{
    const handlers={},lifecycle={},attrs={};let calls=0;
    const button={disabled:false,dataset:{googleUrl:'/auth/google/redirect'},addEventListener:(k,f)=>handlers[k]=f,setAttribute:(k,v)=>attrs[k]=v,removeAttribute:k=>delete attrs[k]};
    bindGoogleLogin(button,'/',()=>calls++,{addEventListener:(k,f)=>lifecycle[k]=f});
    handlers.click();assert.equal(button.disabled,true);lifecycle.pageshow();assert.equal(button.disabled,false);assert.equal(attrs['aria-busy'],undefined);
    handlers.click();assert.equal(calls,2);
});
