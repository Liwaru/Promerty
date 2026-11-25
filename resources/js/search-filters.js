export const minimumKeys=['min_price','bedrooms','bathrooms','min_area','floors','balconies','min_building_area'];
export const advancedKeys=[...minimumKeys,'max_price','price_period'];
export const filterKeys=['location','type','transaction','category',...advancedKeys];
export function activeFilterKeys(values,keys=filterKeys){
    return keys.filter(key=>{const value=String(typeof values.get==='function'?values.get(key)??'':values[key]??'').trim();return value!==''&&(!minimumKeys.includes(key)||Number(value)!==0);});
}
export function initAdvancedFilters(button,panel){
    let current=false,version=0,animation=null;
    function setOpen(open,{animate=true}={}){
        if(current===open&&panel.hidden===!open)return Promise.resolve();
        const height=panel.hidden?0:panel.getBoundingClientRect().height,opacity=panel.hidden?0:Number(getComputedStyle(panel).opacity);
        animation?.cancel();const token=++version;current=open;
        button.setAttribute('aria-expanded',String(open));button.querySelector('[data-toggle-label]').textContent=open?'Sembunyikan filter lanjutan':'Tampilkan filter lanjutan';
        if(!open&&panel.contains(document.activeElement))button.focus({preventScroll:true});
        panel.inert=!open;panel.hidden=false;
        const end=open?panel.scrollHeight:0;
        if(!animate||window.matchMedia('(prefers-reduced-motion: reduce)').matches||!panel.animate){panel.hidden=!open;panel.style.overflow='';return Promise.resolve();}
        panel.style.overflow='hidden';
        const motion=panel.animate([{height:height+'px',opacity},{height:end+'px',opacity:open?1:0}],{duration:200,easing:'cubic-bezier(.2,.7,.2,1)',fill:'both'});animation=motion;
        return motion.finished.catch(()=>{}).then(()=>{if(token!==version)return;motion.cancel();animation=null;panel.hidden=!open;panel.style.overflow='';});
    }
    button.addEventListener('click',()=>setOpen(!current));
    return {setOpen,isOpen:()=>current};
}
