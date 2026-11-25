export const areaSuggestions=['Batam Center','Nagoya','Bengkong','Tiban','Nongsa','Baloi','Batu Aji','Sekupang','Sagulung','Batu Ampar'];
export const queryExamples=['Rumah 2 lantai Batam Center','Sewa rumah Bengkong','Apartemen Pollux Habibie','Ruko dijual Nagoya'];
export const initialAreas=['Nagoya','Tiban','Nongsa'];
// One bounded result set replaces the initial recommendations while typing.
export function drawerSuggestions(query,{categories=[],items=[],recent=[]}={}){
    const q=normalizeSearch(query);if(!q)return [];
    const choices=[...matchingAreas(q).map(value=>({value,type:'area'})),
        ...categories.filter(c=>c.name.toLowerCase().includes(q.toLowerCase())).map(c=>({value:c.name,type:'category'})),
        ...items.map(c=>({value:c.listing.title,type:'property'})),
        ...recent.filter(value=>value.toLowerCase().includes(q.toLowerCase())).map(value=>({value,type:'recent'}))];
    const seen=new Set();return choices.filter(({value})=>{const key=value.toLowerCase();if(seen.has(key))return false;seen.add(key);return true;}).slice(0,8);
}
export const normalizeSearch=value=>String(value??'').trim().replace(/\s+/g,' ').slice(0,120);
export function matchingAreas(value){const q=normalizeSearch(value).toLocaleLowerCase('id').replace('batam centre','batam center');return areaSuggestions.filter(area=>area.toLowerCase().includes(q));}
export function rememberSearch(values,value){const q=normalizeSearch(value);return q?[q,...values.filter(v=>typeof v==='string'&&v.toLowerCase()!==q.toLowerCase())].slice(0,5):values;}
export function drawerSearchUrl(value,current=''){
    const p=new URLSearchParams(current),next=new URLSearchParams();
    const kind=p.get('kind');if(['video','property','agent'].includes(kind))next.set('kind',kind);
    const sort=p.get('sort');if((kind==='agent'?['relevance','name']:['relevance','latest','price_asc','price_desc']).includes(sort))next.set('sort',sort);
    const q=normalizeSearch(value);if(q)next.set('q',q);
    return '/search'+(next.size?'?'+next:'');
}
