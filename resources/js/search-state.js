import {filterKeys} from './search-filters.js';

export const defaultSearchKind='property';
export const searchKeys=['q','kind','sort',...filterKeys];
export function searchParams(values) {
    const result=new URLSearchParams();
    for(const key of searchKeys){const raw=typeof values.get==='function'?values.get(key):values[key];const value=String(raw??'').trim();if(value)result.set(key,value);}
    return result;
}
export function safeSearchReturn(value,origin) {
    if(!value)return null;
    try{const url=new URL(value,origin);return url.origin===origin&&url.pathname==='/search'?'/search'+(url.search||''):null;}catch{return null;}
}
export function publicSearchCard(item,kind) {
    if(kind==='agent')return {id:item.id,name:item.name,username:item.username,bio:item.bio,photo:item.photo,branch:item.branch,agency:item.agency};
    const l=item.listing,m=item.media?.find(media=>media.type===(item.content_type==='photo'?'image':'video')&&media.url);
    return {id:item.id,listing_id:l.id,title:l.title,price:l.price,price_period:l.price_period,type:l.type,transaction:l.transaction_type,
        project:l.project_name,category:l.category,bedrooms:l.bedrooms,bathrooms:l.bathrooms,balconies:l.additional_specs?.balconies,
        location:[...new Set([l.location?.label,l.location?.district,l.location?.city].filter(Boolean))].join(', '),agent:item.agent.name,
        poster:m?.type==='image'?m.url:m?.poster,media_type:m?.type,provider:m?.external_video?.provider};
}
// Invalidate cached pre-scope results in Search and its existing viewer.
export const searchCacheKey=url=>'promerty-search:listing-v4:'+url;
