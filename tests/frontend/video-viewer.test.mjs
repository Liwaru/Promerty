import test from 'node:test';
import assert from 'node:assert/strict';
import { videoOrientation, playbackTime, videoViewer } from '../../resources/js/video-viewer.js';

test('intrinsic dimensions determine orientation including absent and square metadata', () => {
    assert.equal(videoOrientation(720,1280),'portrait');assert.equal(videoOrientation(1920,1080),'landscape');
    assert.equal(videoOrientation(0,0),'unknown');assert.equal(videoOrientation(1080,1080),'square');
});
test('time labels remain useful before metadata and across minute boundaries', () => {
    assert.equal(playbackTime(NaN),'0:00');assert.equal(playbackTime(Infinity),'0:00');
    assert.equal(playbackTime(-1),'0:00');assert.equal(playbackTime(96.014),'1:36');assert.equal(playbackTime(8),'0:08');
});
test('viewer escapes listing content and uses real counts without unauthorized download', () => {
    const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const post={id:1,media:[{url:'/storage/video.mp4'}],listing:{title:'<script>bad</script>',type:'secondary',transaction_type:'sale',category:'Rumah',location:{city:'Batam'},price:0},agent:{name:'QA <img onerror=bad>'},caption:'<img onerror=bad>',viewer:{},counts:{likes:0,comments:2,shares:0}};
    const options={escape,money:()=> 'Rp0',icon:()=>'<svg></svg>',avatar:'QA',actionButton:(a,n,t)=>`<button data-action="${a}">${t}</button>`,allowDownload:false};
    const html=videoViewer(post,options);assert.ok(!html.includes('<script>'));assert.ok(html.includes('&lt;img onerror=bad&gt;'));
    assert.ok(!html.includes('data-action="download"'));assert.ok(html.includes('data-action="comment">2'));
    assert.ok(!videoViewer(post,{...options,allowDownload:true}).includes('data-action="download"'));
    const permitted = {...post,media:[{...post.media[0],downloadable:true}]};
    const permittedHtml = videoViewer(permitted,{...options,allowDownload:true});
    assert.match(permittedHtml,/data-action="download"/);
    assert.doesNotMatch(permittedHtml,/fullscreen|<video[^>]*\bcontrols\b/i);
    assert.ok(!videoViewer({...post,media:[]},options).includes('<video '));
});
