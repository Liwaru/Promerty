import test from 'node:test';
import assert from 'node:assert/strict';
import {externalVideo,externalVideoMarkup} from '../../resources/js/external-video.js';

test('TikTok keeps its opaque decimal ID, loads only on demand and has a fallback link', () => {
    const id='7699394949875371270';
    const url='https://www.tiktok.com/@provider_test/video/'+id;
    const source=externalVideo({external_video:{provider:'tiktok',id,url}});
    assert.equal(source.src,'https://www.tiktok.com/player/v1/'+id+'?autoplay=0&controls=1&rel=0');
    assert.match(externalVideoMarkup(source),/data-external-load/);
    assert.match(externalVideoMarkup(source),/rel="noopener noreferrer"/);
    assert.doesNotMatch(externalVideoMarkup(source),/<iframe/);
    assert.equal(externalVideo({external_video:{provider:'tiktok',id:Number(id),url}}),null);
    assert.equal(externalVideo({external_video:{provider:'tiktok',id,url:url+'/suffix'}}),null);
});

test('Instagram frames are derived only from an exact public Reel identity', () => {
    const id='AB_cd10',url='https://www.instagram.com/reel/'+id+'/';
    assert.equal(externalVideo({external_video:{provider:'instagram',id,url}}).src,url+'embed/');
    assert.equal(externalVideo({external_video:{provider:'instagram',id,url:'https://evil.test/reel/'+id+'/'}}),null);
});
