import test from 'node:test';
import assert from 'node:assert/strict';
import {uploadAgentMedia} from '../../resources/js/agent-upload.js';

function fixture() {
    const xhr = new EventTarget();
    xhr.upload = new EventTarget();
    xhr.open = (method, url) => { xhr.method = method; xhr.url = url; };
    xhr.setRequestHeader = (name, value) => { xhr.headers.set(name, value); };
    xhr.headers = new Map();
    let sends = 0;
    xhr.send = () => { sends++; };
    xhr.respond = (status, responseText) => {
        Object.assign(xhr, {status, responseText});
        xhr.dispatchEvent(new Event('load'));
    };
    const progress = [];
    const result = uploadAgentMedia('/local/media', new FormData(), 'synthetic', n => progress.push(n), () => xhr);
    return {xhr, progress, result, sends: () => sends};
}

test('byte progress does not mark an upload stored until the server acknowledges it', async () => {
    const {xhr, progress, result} = fixture();
    xhr.upload.dispatchEvent(Object.assign(new Event('progress'), {lengthComputable: true, loaded: 1, total: 4}));
    xhr.upload.dispatchEvent(Object.assign(new Event('progress'), {lengthComputable: true, loaded: 999, total: 1000}));
    xhr.upload.dispatchEvent(new Event('load'));
    assert.deepEqual(progress, [25, 99, 100]);
    const pending = Symbol();
    assert.equal(await Promise.race([result, Promise.resolve(pending)]), pending);
    xhr.respond(201, '{"data":{"id":42}}');
    assert.equal((await result).data.id, 42);
    assert.equal(xhr.headers.get('X-CSRF-TOKEN'), 'synthetic');
});

test('connection interruption never automatically sends a second multipart POST', async () => {
    const {xhr, result, sends} = fixture();
    xhr.dispatchEvent(new Event('error'));
    await assert.rejects(result, /Periksa daftar media/);
    assert.equal(sends(), 1);
});

test('HTTP 413 and a success status with a missing acknowledgement are explicit failures', async () => {
    const oversized = fixture();
    oversized.xhr.respond(413, '<html></html>');
    await assert.rejects(oversized.result, /melampaui batas server/);
    const ambiguous = fixture();
    ambiguous.xhr.respond(201, '{}');
    await assert.rejects(ambiguous.result, /belum dapat dipastikan/);
});
