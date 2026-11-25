import test from 'node:test';
import assert from 'node:assert/strict';
import {clampPanelWidth, panelBounds, initCommentPanel} from '../../resources/js/comment-panel.js';

test('width leaves room for sidebar and feed across desktop sizes',()=>{
    for(const viewport of [960,1024,1199,1200,1366,1920]){
        const {min,max}=panelBounds(viewport);
        assert.equal(min,320);assert.ok(max<=600);
        assert.ok(viewport-(viewport>=1200?224:76)-max>=360);
        assert.equal(clampPanelWidth(9999,viewport),max);
        assert.equal(clampPanelWidth(-10,viewport),min);
    }
    assert.equal(clampPanelWidth('invalid',1366),400);
});

function setup(width=1366,stored=null){
    const listeners=new Map();
    const element=()=>({attrs:{},addEventListener(name,fn){listeners.set(this.label+name,fn);},setAttribute(k,v){this.attrs[k]=String(v);},focus(){this.focused=true;}});
    const handle={...element(),label:'handle',setPointerCapture(){}};
    const closeButton=element(),opener=element();
    const panel={...element(),label:'panel',open:false,querySelector:s=>s==='[data-comment-resize]'?handle:closeButton,
        show(){this.open=true;this.modal=false;},showModal(){this.open=true;this.modal=true;},
        close(){this.open=false;queueMicrotask(()=>listeners.get('panelclose')());}};
    const classes=new Set(),props={};
    const app={querySelector:()=>panel,style:{setProperty:(k,v)=>props[k]=v},classList:{toggle(k,v){v?classes.add(k):classes.delete(k);},add:k=>classes.add(k),remove:(...ks)=>ks.forEach(k=>classes.delete(k))}};
    const media={...element(),label:'media',matches:width>=960};
    globalThis.window={innerWidth:width,matchMedia:()=>media,addEventListener:(n,fn)=>listeners.set('window'+n,fn)};
    globalThis.document={activeElement:opener};
    const storage=new Map(stored===null?[]:[['promerty.comments.width',stored]]);
    globalThis.localStorage={getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v)};
    const close=()=>{panel.close();opener.focus();};
    const controller=initCommentPanel(app,{dialogUI:{open:(_p,options)=>options.modal?panel.showModal():panel.show(),close,dismiss:close}});
    const fire=(label,name,data={})=>listeners.get(label+name)({preventDefault(){},...data});
    return {controller,panel,handle,media,classes,props,storage,opener,fire};
}

test('desktop panel is nonmodal, closes and restores focus',async()=>{
    const x=setup();x.controller.open();assert.equal(x.panel.modal,false);assert.equal(x.controller.isBlocking(),false);
    assert.ok(x.classes.has('has-comments'));assert.equal(x.panel.attrs.role,'region');
    x.fire('panel','keydown',{key:'Escape'});await Promise.resolve();
    assert.equal(x.panel.open,false);assert.equal(x.opener.focused,true);assert.equal(x.classes.size,0);
});

test('drag and keyboard resize clamp and persist width',()=>{
    const x=setup(1366,'480');assert.equal(x.props['--comment-width'],'480px');
    x.fire('handle','keydown',{key:'End'});assert.equal(x.storage.get('promerty.comments.width'),'600');
    x.fire('handle','keydown',{key:'Home'});assert.equal(x.handle.attrs['aria-valuenow'],'320');
    x.fire('handle','pointerdown',{button:0,pointerId:1});x.fire('handle','pointermove',{clientX:866});x.fire('handle','pointerup');
    assert.equal(x.storage.get('promerty.comments.width'),'500');assert.equal(x.classes.has('is-resizing-comments'),false);
});

test('mobile sheet blocks playback and breakpoint switches preserve panel',async()=>{
    const x=setup(390);x.controller.open();assert.equal(x.panel.modal,true);assert.equal(x.controller.isBlocking(),true);
    assert.equal(x.classes.has('has-comments'),false);
    window.innerWidth=1366;x.media.matches=true;x.fire('media','change');await Promise.resolve();
    assert.equal(x.panel.open,true);assert.equal(x.panel.modal,false);assert.ok(x.classes.has('has-comments'));
    window.innerWidth=390;x.media.matches=false;x.fire('media','change');await Promise.resolve();
    assert.equal(x.panel.modal,true);assert.equal(x.controller.isBlocking(),true);
});
