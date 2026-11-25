// Cancellable transitions: a superseded close must never hide a reopened dialog.
export class DialogMotion {
    state='closed';
    version=0;
    animation=null;
    async run(open, {before=()=>{}, after=()=>{}, animate=()=>null}={}) {
        const version=++this.version;
        this.animation?.cancel();
        this.state=open?'opening':'closing';before();
        const animation=animate(open);this.animation=animation;
        if(animation)await animation.finished.catch(()=>{});
        if(version!==this.version)return false;
        animation?.cancel();this.animation=null;this.state=open?'open':'closed';after();return true;
    }
}

export function isOutsideRect(rect,x,y){return x<rect.left||x>rect.right||y<rect.top||y>rect.bottom;}
export function dismissalPolicy({busy=false,critical=false,dirty=false}){return busy?'busy':critical?'critical':dirty?'confirm':'close';}
