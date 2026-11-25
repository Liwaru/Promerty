export default async function (base) {
    const origin='http://127.0.0.1:8042';
    const page=await base.context().newPage();
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.setViewportSize({width:1440,height:900});
    await page.goto(origin+'/');
    const login=await page.evaluate(async()=>{
        const csrf=(await fetch('/web-auth/me').then(r=>r.json())).csrf_token;
        return (await fetch('/web-auth/login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
            body:JSON.stringify({email:'agent-workflow@example.test',password:'synthetic-browser-fixture'})})).status;
    });
    if(login!==200)throw Error('Synthetic Agent QA login failed');
    await page.goto(origin+'/agent');
    const records=[];
    const failures=[];
    for(const [width,height] of [[360,640],[640,360]]){
        const result=await page.evaluate(async([w,h])=>{
            const csrf=document.querySelector('meta[name="csrf-token"]').content;
            const headers={Accept:'application/json','X-CSRF-TOKEN':csrf};
            const api=async(path,method='GET',body=null)=>{
                const multipart=body instanceof FormData;
                const response=await fetch(path,{method,headers:{...headers,...(body&&!multipart?{'Content-Type':'application/json'}:{})},
                    body:body?(multipart?body:JSON.stringify(body)):undefined});
                return {status:response.status,json:await response.json()};
            };
            const join=(...chunks)=>{const out=new Uint8Array(chunks.reduce((n,c)=>n+c.length,0));let at=0;for(const chunk of chunks){out.set(chunk,at);at+=chunk.length;}return out;};
            const ascii=s=>new TextEncoder().encode(s);
            const words=(...values)=>{const b=new Uint8Array(values.length*4),v=new DataView(b.buffer);values.forEach((x,i)=>v.setUint32(i*4,x));return b;};
            const short=(...values)=>{const b=new Uint8Array(values.length*2),v=new DataView(b.buffer);values.forEach((x,i)=>v.setUint16(i*2,x));return b;};
            const zero=n=>new Uint8Array(n);
            const box=(kind,...data)=>{const bytes=join(...data);return join(words(8+bytes.length),ascii(kind),bytes);};
            const chunks=[],keyframes=[];
            const c=document.createElement('canvas');c.width=w;c.height=h;const ctx=c.getContext('2d');
            let avcConfig,encodingError;
            const videoCodec={codec:'avc1.42001f',width:w,height:h,framerate:30,bitrate:700000,latencyMode:'realtime',avc:{format:'avc'}};
            const support=await VideoEncoder.isConfigSupported(videoCodec);
            if(!support.supported)return {stage:'encoder',supported:false};
            const encoder=new VideoEncoder({output:(chunk,meta)=>{
                const sample=zero(chunk.byteLength);chunk.copyTo(sample);chunks.push(sample);
                if(chunk.type==='key')keyframes.push(chunks.length);
                if(meta.decoderConfig?.description)avcConfig=new Uint8Array(meta.decoderConfig.description);
            },error:error=>{encodingError=error.name;}});
            encoder.configure(videoCodec);
            for(let i=0;i<90;i++){
                ctx.fillStyle='#101719';ctx.fillRect(0,0,w,h);ctx.fillStyle='#edbe54';ctx.fillRect(0,0,w,20);ctx.fillRect(0,h-20,w,20);
                ctx.font='bold 22px sans-serif';ctx.fillStyle='#fff';ctx.fillText('PROMERTY QA · SYNTHETIC',16,h/2);
                ctx.fillStyle='#edbe54';ctx.fillRect(20+(i*3)%(w-70),h/2+32,50,30);
                const frame=new VideoFrame(c,{timestamp:Math.round(i*1e6/30),duration:33333});
                encoder.encode(frame,{keyFrame:i%15===0});frame.close();
                if(i%10===9)await encoder.flush();
            }
            await encoder.flush();encoder.close();
            if(encodingError||!avcConfig||chunks.length!==90)return {stage:'encode',count:chunks.length,encodingError};
            const identity=words(65536,0,0,0,65536,0,0,0,1073741824);
            const ftyp=box('ftyp',ascii('isom'),words(512),ascii('isomiso2avc1mp41'));
            const mdat=box('mdat',...chunks);
            const visual=join(zero(6),short(1),zero(16),short(w,h),words(4718592,4718592,0),short(1),
                zero(32),short(24,65535),box('avcC',avcConfig));
            const table=box('stbl',box('stsd',words(0,1),box('avc1',visual)),
                box('stts',words(0,1,chunks.length,1000)),box('stsc',words(0,1,1,chunks.length,1)),
                box('stsz',words(0,0,chunks.length,...chunks.map(c=>c.length))),
                box('stco',words(0,1,ftyp.length+8)),box('stss',words(0,keyframes.length,...keyframes)));
            const mdia=box('mdia',box('mdhd',words(0,0,0,30000,90000),short(21956,0)),
                box('hdlr',words(0,0),ascii('vide'),zero(12),ascii('Synthetic\0')),
                box('minf',box('vmhd',words(1,0,0)),box('dinf',box('dref',words(0,1),box('url ',words(1)))),table));
            const moov=box('moov',box('mvhd',words(0,0,0,1000,3000,65536),short(256,0),zero(8),identity,zero(24),words(2)),
                box('trak',box('tkhd',words(7,0,0,1,0,3000),zero(16),identity,words(w*65536,h*65536)),mdia));
            const bytes=join(ftyp,mdat,moov);
            const categories=await api('/api/agent/listing-options');
            const category=categories.json.categories.find(x=>x.slug==='rumah');
            const draft=await api('/api/agent/listings','POST',{title:'QA sementara · AVC '+w+'×'+h+' · '+Date.now(),category_id:category.id,
                transaction_type:'sale',price:'100000000',price_period:'total',location_label:'Batam'});
            if(draft.status!==201)return {stage:'draft',status:draft.status};
            const id=draft.json.data.id,path='/api/agent/listings/'+id;
            const form=new FormData();
            form.append('media',new File([bytes],'qa-synthetic-avc.mp4',{type:'video/mp4'}));
            const upload=await api(path+'/media','POST',form);
            if(upload.status!==201)return {stage:'upload',status:upload.status,id,error:upload.json.errors?.media??upload.json.message};
            const stored=(await api(path)).json.data.media[0];
            const ready=await api(path+'/publish','POST');
            return {stage:'publish',status:ready.status,id,media:stored,post:ready.json.data?.public_url,bytes:bytes.length,codec:videoCodec.codec,samples:chunks.length};
        },[width,height]);
        records.push(result);
        if(result.status!==200||!result.post){failures.push(result.stage);continue;}
        const customer=await base.context().newPage();
        await customer.setViewportSize({width:1440,height:900});
        await customer.emulateMedia({reducedMotion:'reduce'});
        try {
            await customer.goto(origin+result.post);
            const button=customer.locator('[data-action=play]').first();
            await button.click();
            const evidence=await customer.evaluate(async()=>{
                const video=document.querySelector('video');
                const frame=await Promise.race([new Promise(resolve=>video.requestVideoFrameCallback((_,meta)=>resolve({
                    frames:meta.presentedFrames,width:meta.width,height:meta.height,time:meta.mediaTime}))),new Promise(resolve=>setTimeout(()=>resolve(null),4500))]);
                const start=video.currentTime;await new Promise(r=>setTimeout(r,350));
                const moving=video.currentTime>start;
                const response=await fetch(video.dataset.src,{headers:{Range:'bytes=0-31'}});
                return {frame,moving,width:video.videoWidth,height:video.videoHeight,fit:getComputedStyle(video).objectFit,
                    range:response.status,rangeBytes:(await response.arrayBuffer()).byteLength,mediaError:video.error?.code??null,
                    orientation:video.closest('.f-post').dataset.orientation};
            });
            result.evidence=evidence;
            if(!evidence.frame||!evidence.moving)failures.push('visual '+width+'×'+height);
            await customer.screenshot({path:'.playwright-mcp/media-readiness-local-'+width+'x'+height+'.png'});
            await button.click();
            result.paused=await customer.locator('video').evaluate(v=>v.paused);
            const seek=customer.locator('.f-video-seek');
            await seek.focus();await seek.press('End');
            result.seek=await customer.locator('video').evaluate(v=>Math.abs(v.duration-v.currentTime)<0.15);
            await customer.setViewportSize({width:390,height:844});
            result.mobileFits=await customer.evaluate(()=>document.documentElement.scrollWidth<=innerWidth&&getComputedStyle(document.querySelector('video')).objectFit==='contain');
        }catch(error){failures.push(error.message.split('\n')[0]);}
        finally{await customer.close();}
    }
    await page.close();
    return {records,failures};
}
