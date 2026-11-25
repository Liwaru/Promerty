export const listingStatus = status => ({draft:'Draft',active:'Terpublikasi',archived:'Diarsipkan',sold:'Terjual',rented:'Tersewa',inactive:'Nonaktif'}[status] || 'Status tidak dikenal');
export function listingPayload(formData) {
    const data={};
    const specs=['carport','garage','apartment_floor','tower','balconies','concept'];
    for(const [key,value] of formData.entries()) {
        if(typeof value!=='string')continue;
        if(specs.includes(key)||key==='allow_video_download')continue;
        data[key]=value.trim()===''?null:value.trim();
    }
    data.additional_specs=Object.fromEntries(specs.map(key=>[key,formData.get(key)?.trim()||null]));
    const download = formData.get('allow_video_download');
    if (download === '0' || download === '1') data.additional_specs.allow_video_download = download === '1';
    return data;
}
export function listingActions(status) {
    return {editable:['draft','archived'].includes(status),publishable:['draft','archived'].includes(status),archivable:['draft','active'].includes(status)};
}
