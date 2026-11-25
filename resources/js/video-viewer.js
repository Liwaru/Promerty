/** Presentation and controls only; feed authorization and interactions stay in feed.js. */
import {externalVideo, externalVideoMarkup} from './external-video.js';

export function videoOrientation(width, height) {
    if (!(width > 0 && height > 0)) return 'unknown';
    return width === height ? 'square' : width > height ? 'landscape' : 'portrait';
}
export function playbackTime(seconds) {
    const value = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0;
    return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`;
}
export function videoViewer(post, { escape, money, icon, avatar, actionButton, allowDownload }) {
    const first = post.media[0], l = post.listing, caption = post.caption || l.description || l.title;
    const external = externalVideo(first);
    return `<div class="f-player f-video-stage${external ? ' f-external-stage' : ''}">
        ${external ? externalVideoMarkup(external) : first?.url ? `<video data-src="${escape(first.url)}" ${first.poster ? `poster="${escape(first.poster)}"` : ''} preload="none" playsinline muted loop aria-label="Video ${escape(l.title)}" tabindex="0"></video>` : ''}
        <section class="f-viewer-info" aria-label="Informasi properti" tabindex="0">
            <div class="f-agent-line"><button data-action="agent" aria-label="Buka profil ${escape(post.agent.name)}">${avatar}</button><button class="f-agent-button" data-action="agent"><strong>${escape(post.agent.name)}${post.agent.verified ? ' ✓' : ''}</strong></button><button class="f-viewer-follow" data-action="follow" aria-pressed="${post.viewer.following}">${post.viewer.following ? 'Diikuti' : 'Ikuti'}</button></div>
            <p class="f-viewer-meta">${l.type === 'primary' ? 'Primary' : 'Secondary'} · ${l.transaction_type === 'rent' ? 'Disewakan' : 'Dijual'} · ${escape(l.category)}</p>
            <h2>${escape(l.title)}</h2>
            <p class="f-viewer-location">${escape([...new Set([l.location?.label, l.location?.city].filter(Boolean))].join(', ') || 'Lokasi belum tersedia')}</p>
            <p class="f-viewer-price">${money(l.price)}${l.price_period === 'month' ? ' / bulan' : l.price_period === 'year' ? ' / tahun' : ''}</p>
            <p class="f-viewer-caption">${escape(caption)}</p>
            <div class="f-viewer-links"><button class="f-caption-toggle" data-action="detail">Selengkapnya</button><button data-action="message">Hubungi Agent</button><button data-action="survey">Survei</button></div>
        </section>
        <div class="f-viewer-actions" role="group" aria-label="Interaksi properti">${actionButton('like', 'Sukai properti', post.counts.likes, post.viewer.liked)}${actionButton('comment', 'Buka komentar', post.counts.comments)}${actionButton('save', 'Simpan properti', '', post.viewer.saved)}${actionButton('share', 'Bagikan properti', post.counts.shares)}${allowDownload && first?.downloadable && !external ? actionButton('download', 'Download media', '') : ''}</div>
        ${external ? '' : `<div class="f-playback" role="group" aria-label="Kontrol video"><input class="f-video-seek" type="range" min="0" max="100" step="0.1" value="0" disabled aria-label="Posisi video" aria-valuetext="0:00"><div class="f-playback-row"><button class="f-viewer-control" data-action="play" aria-label="Putar video" ${first?.url ? '' : 'disabled'}>${icon('play')}</button><output class="f-video-time" aria-label="Waktu video" aria-live="off">0:00 / 0:00</output><span class="f-playback-spacer"></span><button class="f-viewer-control" data-action="mute" aria-label="Aktifkan suara" aria-pressed="true" ${first?.url ? '' : 'disabled'}>${icon('volume')}</button></div></div>`}
        ${!external && first?.url ? '<div class="f-video-buffer" role="status" hidden><span aria-hidden="true"></span><span class="sr-only">Memuat video…</span></div>' : ''}
        <div class="f-media-status" role="status" ${first?.url ? 'hidden' : ''}>Media belum tersedia.</div>
    </div>`;
}
export function bindVideoControls(article, video, icon) {
    const seek = article.querySelector('.f-video-seek');
    if (!seek) return;
    const play = article.querySelector('[data-action=play]'), mute = article.querySelector('[data-action=mute]'), output = article.querySelector('.f-video-time');
    const sync = () => {
        const playState=video.paused?'play':'pause',muteState=video.muted?'muted':'volume';
        if(play.dataset.state!==playState){play.dataset.state=playState;play.setAttribute('aria-label',video.paused?'Putar video':'Jeda video');play.innerHTML=icon(playState);}
        if(mute.dataset.state!==muteState){mute.dataset.state=muteState;mute.setAttribute('aria-label',video.muted?'Aktifkan suara':'Matikan suara');mute.setAttribute('aria-pressed',String(video.muted));mute.innerHTML=icon(muteState);}
        const duration = Number.isFinite(video.duration) ? video.duration : 0;
        seek.disabled = !duration;seek.max = String(duration || 100);seek.value = String(video.currentTime || 0);
        seek.setAttribute('aria-valuetext', `${playbackTime(video.currentTime)} dari ${playbackTime(duration)}`);
        seek.style.setProperty('--video-progress', `${duration ? (video.currentTime / duration) * 100 : 0}%`);
        const label=`${playbackTime(video.currentTime)} / ${playbackTime(duration)}`;if(output.value!==label)output.value=label;
    };
    for (const event of ['play', 'pause', 'volumechange', 'loadedmetadata', 'durationchange', 'timeupdate', 'seeked', 'emptied']) video.addEventListener(event, sync);
    seek.addEventListener('input', () => { if (Number.isFinite(video.duration) && video.duration > 0) {video.currentTime = Math.min(video.duration, Math.max(0, Number(seek.value)));sync();} });
    const info = article.querySelector('.f-viewer-info');
    info.addEventListener('keydown', event => {if(event.target===info&&['ArrowUp','ArrowDown','PageUp','PageDown','Home','End'].includes(event.key))event.stopPropagation();});
    sync();
}

export function bindVideoBuffer(article, video, status) {
    const spinner = article.querySelector('.f-video-buffer');
    let showTimer;
    let failureTimer;

    const clear = () => {
        clearTimeout(showTimer);
        clearTimeout(failureTimer);
        showTimer = undefined;
        failureTimer = undefined;
        spinner.hidden = true;
    };
    const fail = () => {
        clear();
        if (!article.isConnected || !video.hasAttribute('src')) return;
        if (!status.querySelector('[data-action=retry-media]')) {
            status.innerHTML = 'Video belum dapat diputar. <button class="f-media-control" data-action="retry-media">Coba lagi</button>';
        }
        status.hidden = false;
    };
    const stillWaiting = () => article.isConnected && !video.paused && !video.ended
        && !document.hidden && (video.seeking || video.readyState < 3);
    const wait = () => {
        if (!stillWaiting() || showTimer !== undefined || !status.hidden) return;
        showTimer = setTimeout(() => { spinner.hidden = !stillWaiting(); }, 250);
        failureTimer = setTimeout(() => { if (stillWaiting()) fail(); else clear(); }, 12000);
    };

    for (const event of ['play', 'waiting', 'stalled', 'seeking']) video.addEventListener(event, wait);
    for (const event of ['pause', 'ended', 'emptied', 'abort']) video.addEventListener(event, clear);
    for (const event of ['canplay', 'playing', 'seeked']) video.addEventListener(event, () => {
        if (stillWaiting()) return;
        clear();
        status.hidden = true;
    });
    video.addEventListener('error', fail);
}
