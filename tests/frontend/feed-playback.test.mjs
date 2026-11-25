import test from 'node:test';
import assert from 'node:assert/strict';
import { coordinatePlayback } from '../../resources/js/feed-playback.js';

function fixture() {
    const videos = Array.from({length:4}, (_,index) => ({
        dataset:{src:`/video-${index}.mp4`},paused:true,currentTime:0,src:'',plays:0,
        getAttribute(){return this.src;},removeAttribute(){this.src='';},
        load(){this.currentTime=0;},pause(){this.paused=true;},
        play(){this.paused=false;this.plays++;return Promise.resolve();},
        addEventListener(name,callback){this[name]=callback;},
    }));
    const articles=videos.map(video=>({querySelector:()=>video}));
    articles.forEach((article,index)=>{article.nextElementSibling=articles[index+1];});
    return {videos,articles};
}
test('only dominant video plays; next gets metadata, distant media stays unloaded',()=>{
    const {videos,articles}=fixture();coordinatePlayback({articles,active:articles[0]});
    assert.equal(videos[0].paused,false);assert.equal(videos[0].muted,true);
    assert.equal(videos[1].paused,true);assert.equal(videos[1].preload,'metadata');
    assert.equal(videos[2].src,'');assert.equal(videos[3].src,'');
    coordinatePlayback({articles,active:articles[1]});assert.equal(videos[0].paused,true);assert.equal(videos[1].paused,false);
});
test('unloading distant media preserves playback position when revisited',()=>{
    const {videos,articles}=fixture();coordinatePlayback({articles,active:articles[0]});videos[0].currentTime=42;
    coordinatePlayback({articles,active:articles[2]});assert.equal(videos[0].src,'');assert.equal(videos[0].dataset.time,'42');
    coordinatePlayback({articles,active:articles[0]});videos[0].loadedmetadata();assert.equal(videos[0].currentTime,42);
});
test('background/modal state pauses all and does not autoplay',()=>{
    const {videos,articles}=fixture();coordinatePlayback({articles,active:articles[0]});
    coordinatePlayback({articles,active:articles[0],blocked:true});assert.ok(videos.every(video=>video.paused));
});
test('manual pause and reduced-motion are respected, mute preference applies to all',()=>{
    const {videos,articles}=fixture();videos[0].dataset.userPaused='true';
    coordinatePlayback({articles,active:articles[0],muted:false});assert.equal(videos[0].plays,0);assert.ok(videos.every(v=>!v.muted));
    coordinatePlayback({articles,active:articles[1],reducedMotion:true});assert.equal(videos[1].plays,0);
});
