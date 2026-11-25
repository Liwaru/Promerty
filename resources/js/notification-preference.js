const preferenceKey='promerty.notifications.display';

export function unreadNotificationsOnly() {
    try { return localStorage.getItem(preferenceKey)==='unread'; }
    catch { return false; }
}

const select=typeof document==='undefined'?null:document.querySelector('[data-notification-preference]');
if(select){
    select.value=unreadNotificationsOnly()?'unread':'all';
    select.addEventListener('change',()=>{
        try { localStorage.setItem(preferenceKey,select.value==='unread'?'unread':'all'); }
        catch { select.value='all'; }
    });
}
