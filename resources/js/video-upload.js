// Incremental SHA-256 (FIPS 180-4) so hashing a large File only retains one slice.
const K=Uint32Array.of(
    0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
    0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
    0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
    0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
    0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
    0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
    0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
    0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2);

const rotate=(x,n)=>(x>>>n)|(x<<(32-n));
const hex=bytes=>Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join('');

export async function fileSha256(file) {
    const state=Uint32Array.of(0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19);
    const words=new Uint32Array(64);
    const block=(data,offset)=>{
        for(let i=0;i<16;i++)words[i]=data.getUint32(offset+4*i);
        for(let i=16;i<64;i++){
            const x=words[i-15],y=words[i-2];
            words[i]=words[i-16]+(rotate(x,7)^rotate(x,18)^(x>>>3))+words[i-7]+(rotate(y,17)^rotate(y,19)^(y>>>10));
        }
        let [a,b,c,d,e,f,g,h]=state;
        for(let i=0;i<64;i++){
            const t=(h+(rotate(e,6)^rotate(e,11)^rotate(e,25))+((e&f)^(~e&g))+K[i]+words[i])|0;
            const u=((rotate(a,2)^rotate(a,13)^rotate(a,22))+((a&b)^(a&c)^(b&c)))|0;
            h=g;g=f;f=e;e=(d+t)|0;d=c;c=b;b=a;a=(t+u)|0;
        }
        const next=[a,b,c,d,e,f,g,h];
        for(let i=0;i<8;i++)state[i]+=next[i];
    };
    let last=new Uint8Array();
    for(let start=0;start<file.size;start+=4194304){
        const bytes=new Uint8Array(await file.slice(start,start+4194304).arrayBuffer());
        const data=new DataView(bytes.buffer);
        const end=bytes.length-(bytes.length%64);
        for(let offset=0;offset<end;offset+=64)block(data,offset);
        last=bytes.slice(end);
    }
    const padding=new Uint8Array(last.length<56?64:128);
    padding.set(last);
    padding[last.length]=0x80;
    const view=new DataView(padding.buffer);
    view.setBigUint64(padding.length-8,BigInt(file.size)*8n);
    for(let offset=0;offset<padding.length;offset+=64)block(view,offset);
    return Array.from(state,n=>n.toString(16).padStart(8,'0')).join('');
}

export async function uploadResumableVideo(api,path,file,onProgress,onSession) {
    onProgress('hashing',0);
    const sha256=await fileSha256(file);
    const initial=await api(path,{method:'POST',body:JSON.stringify({size:file.size,sha256})});
    if(initial.media_id){
        if(initial.sha256!==sha256)throw new Error('Checksum video tersimpan tidak cocok.');
        onSession(null);
        return initial;
    }
    const {id,chunk_bytes:chunk}=initial;
    if(!/^[a-f0-9]{48}$/.test(id) || !Number.isInteger(chunk) || chunk<1 || chunk>4194304
        || !Number.isInteger(initial.offset) || initial.offset<0 || initial.offset>file.size
        || (initial.offset!==file.size && initial.offset%chunk!==0))throw new Error('Posisi upload tidak dapat diverifikasi.');
    onSession(id);
    let offset=initial.offset;
    onProgress('uploading',offset/file.size*100);
    while(offset<file.size){
        const slice=file.slice(offset,offset+chunk);
        const body=new FormData();
        body.append('chunk',slice,'chunk.bin');
        body.append('offset',String(offset));
        body.append('sha256',hex(new Uint8Array(await crypto.subtle.digest('SHA-256',await slice.arrayBuffer()))));
        const result=await api(path+'/'+id+'/chunk',{method:'POST',body,signal:AbortSignal.timeout(120000)});
        if(result.offset!==offset+slice.size)throw new Error('Posisi upload berubah. Pilih kembali file yang sama untuk melanjutkan.');
        offset=result.offset;
        onProgress('uploading',offset/file.size*100);
    }
    onProgress('validating',100);
    const result=await api(path+'/'+id+'/finish',{method:'POST',signal:AbortSignal.timeout(180000)});
    if(!result.media_id || result.sha256!==sha256)throw new Error('Integritas video belum terkonfirmasi.');
    onSession(null);
    return result;
}
