import {externalVideo,externalVideoMarkup} from './external-video.js';

export function propertyDetail(post, escape, money) {
    const l=post.listing, loc=l.location, media=post.media[0];
    const period={month:' / bulan',year:' / tahun'}[l.price_period] || '';
    const labels={full_furnished:'Berperabot lengkap',semi_furnished:'Sebagian berperabot',unfurnished:'Tanpa perabot'};
    const furnishing=l.furnishing==='unknown'?null:(labels[l.furnishing] || l.furnishing);
    const images=post.media.filter(item=>item.type==='image'&&item.url);
    const mediaHtml=images.length?`<div class="c-detail-gallery" tabindex="0" aria-label="Galeri foto properti, geser atau gunakan tombol panah">${images.map((item,index)=>`<figure><img class="c-detail-media" src="${escape(item.url)}" alt="${escape(l.title)} — foto ${index+1}" loading="lazy"><figcaption>${index+1} / ${images.length}</figcaption></figure>`).join('')}</div>`:media?.poster?`<img class="c-detail-media" src="${escape(media.poster)}" alt="Pratinjau ${escape(l.title)}" loading="lazy"><p class="c-footnote">Video dapat diputar dari feed.</p>`:'';
    const specs=[['Kategori',l.category],['Tipe unit',l.unit_type],['Luas tanah',l.land_area==null?null:`${l.land_area} m²`],['Luas bangunan',l.building_area==null?null:`${l.building_area} m²`],['Kamar tidur',l.bedrooms],['Kamar mandi',l.bathrooms],['Lantai',l.floors],['Furnishing',furnishing],['Sertifikat',l.certificate],['Listrik',l.electricity],['Fasilitas',l.facilities?.length ? l.facilities.join(', ') : null]];
    specs.push(['Arah rumah',l.orientation],['Carport',l.additional_specs?.carport],['Garasi',l.additional_specs?.garage],['Lantai apartemen',l.additional_specs?.apartment_floor],['Tower',l.additional_specs?.tower]);
    specs.push(['Proyek',l.project_name],['Balkon',l.additional_specs?.balconies],['Konsep',l.additional_specs?.concept]);
    const facts=specs.filter(([,v])=>v!==null&&v!==undefined&&v!=='');
    return `<div class="c-detail-layout"><div class="c-detail-visual">${externalVideoMarkup(externalVideo(media))||mediaHtml}${images.length>1?'<p class="c-footnote">Geser untuk melihat foto berikutnya.</p>':''}</div><div class="c-detail-summary p-stack">
        <div class="p-row c-detail-classification"><span class="p-badge">${l.type==='primary'?'Primary':'Secondary'}</span><span class="p-badge">${l.transaction_type==='rent'?'Disewakan':'Dijual'}</span></div>
        <h3 class="c-detail-title">${escape(l.title)}</h3><div class="c-detail-price"><span class="p-price">${money(l.price)}${period}</span>${l.negotiable?'<p class="c-footnote">Harga dapat dinegosiasikan.</p>':''}</div>
        ${facts.length?`<section class="c-detail-section"><h4>Spesifikasi properti</h4><dl class="c-specs">${facts.map(([k,v])=>`<div><dt>${k}</dt><dd>${escape(v)}</dd></div>`).join('')}</dl></section>`:''}
        <section class="c-detail-section"><h4>Lokasi publik</h4><p>${escape([loc?.label,loc?.district,loc?.city].filter(Boolean).join(', ')||'Lokasi publik belum tersedia.')}</p><p class="c-footnote">${loc?.visibility==='approximate'?'Lokasi berupa area perkiraan. Titik privat tidak dibagikan.':loc?'Lokasi publik dari project.':''}</p></section>
        <section class="c-detail-section"><h4>Tentang properti</h4><p class="f-caption">${escape(l.description||post.caption||'Deskripsi belum tersedia.')}</p></section>
        <div class="c-detail-agent"><span class="p-eyebrow">Pendamping properti Anda</span><button class="p-button p-button--ghost" data-detail-action="agent">${escape(post.agent.name)} <span aria-hidden="true">↗</span></button>${post.agent.verified?'<span class="c-footnote">Agent terverifikasi</span>':''}</div>
        <div class="p-row c-detail-actions"><button class="p-button p-button--ghost" data-detail-action="read-comments">Baca komentar publik</button><button class="p-button p-button--primary" data-detail-action="message">Hubungi Agent</button><button class="p-button p-button--secondary" data-detail-action="survey">Ajukan survei</button><button class="p-button p-button--ghost" data-detail-action="save" aria-pressed="${post.viewer.saved}">${post.viewer.saved?'Hapus dari tersimpan':'Simpan properti'}</button></div><p class="c-footnote">Kontak WhatsApp publik belum tersedia. Gunakan pesan internal setelah verifikasi akun.</p>
    </div></div>`;
}
