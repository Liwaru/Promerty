import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createHash,randomBytes} from 'node:crypto';
import {fileSha256,uploadResumableVideo} from '../../resources/js/video-upload.js';

test('incremental SHA-256 matches Node across block and slice boundaries',async()=>{
    for(const size of [0,1,55,56,63,64,65,4194304+65]){
        const bytes=randomBytes(size);
        assert.equal(await fileSha256(new Blob([bytes])),createHash('sha256').update(bytes).digest('hex'));
    }
});

test('resuming proceeds from the acknowledged offset and checks the final checksum',async()=>{
    const contents='abcdefg', hash=createHash('sha256').update(contents).digest('hex');
    const calls=[];
    const api=async(path,{body})=>{
        if(path.endsWith('/chunk')){
            calls.push([Number(body.get('offset')),await body.get('chunk').text()]);
            return {offset:7};
        }
        if(path.endsWith('/finish'))return {media_id:1,sha256:hash};
        assert.equal(JSON.parse(body).sha256,hash);
        return {id:'a'.repeat(48),offset:4,chunk_bytes:4};
    };
    await uploadResumableVideo(api,'/video-upload',new Blob([contents]),()=>{},()=>{});
    assert.deepEqual(calls,[[4,'efg']]);
    await assert.rejects(()=>uploadResumableVideo(async path=> path.endsWith('/finish')
        ? {media_id:1,sha256:'wrong'} : {id:'a'.repeat(48),offset:7,chunk_bytes:4},
    '/video-upload',new Blob([contents]),()=>{},()=>{}),/Integritas/);
});
