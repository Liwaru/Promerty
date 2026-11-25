import {searchParams,safeSearchReturn} from './search-state';
import {initMoreDrawer} from './more-drawer';
export const customerSections=['messages','surveys','activity','profile','settings','notifications','connections'];
export function setNavigationActive(app,action){
    app.querySelectorAll('.f-sidebar .f-nav[data-feed-action],.f-mobile-nav [data-feed-action],.c-more-mobile-nav [data-feed-action]').forEach(button=>{
        const selected=button.dataset.feedAction===action||(['notifications','connections'].includes(action)&&button.dataset.feedAction==='settings')||(button.closest('.f-mobile-nav')&&['profile','surveys','activity'].includes(action)&&button.dataset.feedAction==='settings');
        if(selected)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');
    });
}
export function customerSearchUrl(){
    const current=new URL(location.href),back=safeSearchReturn(current.searchParams.get('from'),location.origin);
    const params=searchParams(back?new URL(back,location.origin).searchParams:current.searchParams);
    return '/search'+(params.size?'?'+params:'');
}
const search=document.getElementById('promerty-search');
if(search){
    let sessionUser=null;
    const more=initMoreDrawer(search,{onAccount:async()=>{
        if(!sessionUser){location.assign('/?panel=profile');return;}
        const response=await fetch('/web-auth/logout',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}});
        if(!response.ok)throw new Error('Keluar belum berhasil. Muat ulang halaman dan coba lagi.');
        // A new anonymous session has a new CSRF token. Reload the existing public search URL.
        location.reload();
    }});
    fetch('/web-auth/me',{headers:{Accept:'application/json'},credentials:'same-origin'}).then(response=>response.ok?response.json():Promise.reject()).then(result=>{sessionUser=result.user;more.setUser(sessionUser);}).catch(()=>{});
    search.addEventListener('click',event=>{const action=event.target.closest('[data-feed-action]')?.dataset.feedAction;if(!action)return;if(action==='search'){search.scrollTop=0;return;}if(action==='profile'&&sessionUser){location.assign(sessionUser.role==='agent'?'/agents/'+sessionUser.id:'/account/profile');return;}location.assign(customerSections.includes(action)?'/?panel='+action:action==='for-you'?'/':'/?view='+action);});
    setNavigationActive(search,'search');
}
