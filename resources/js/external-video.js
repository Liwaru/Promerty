export function externalVideo(media) {
    const link = media?.external_video;
    if (link?.provider === 'tiktok' && typeof link.id === 'string' && /^[1-9][0-9]{17,19}$/.test(link.id)) {
        const match = /^https:\/\/www\.tiktok\.com\/@([A-Za-z0-9_.]{1,24})\/video\/([0-9]+)$/.exec(link.url);
        if (match?.[2] === link.id) return {name:'TikTok', url:link.url, src:`https://www.tiktok.com/player/v1/${link.id}?autoplay=0&controls=1&rel=0`};
    }
    if (link?.provider === 'instagram' && typeof link.id === 'string' && /^[A-Za-z0-9_-]{5,30}$/.test(link.id)) {
        const url = `https://www.instagram.com/reel/${link.id}/`;
        if (link.url === url) return {name:'Instagram Reels', url, src:url+'embed/'};
    }
    return null;
}

export function externalVideoMarkup(source) {
    if (!source) return '';
    return `<div class="p-external-video" data-external-src="${source.src}">
        <div class="p-external-frame"><button type="button" class="p-button p-button--secondary" data-external-load>Muat video ${source.name}</button></div>
        <div class="p-external-links"><button type="button" class="p-button p-button--ghost" data-external-close hidden>Tutup pemutar</button>
        <a href="${source.url}" target="_blank" rel="noopener noreferrer">Buka di ${source.name} ↗</a></div>
        <p role="status" class="p-external-status" hidden></p>
    </div>`;
}

if (typeof document !== 'undefined') {
    let active = null;
    let timer;
    const observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.target === active && !entry.isIntersecting)) close();
    });
    function close(restoreFocus = false) {
        if (!active) return;
        clearTimeout(timer);
        observer.unobserve(active);
        active.querySelector('iframe')?.remove();
        active.querySelector('[data-external-close]').hidden = true;
        const button = active.querySelector('[data-external-load]');
        button.hidden = false;
        active.querySelector('.p-external-status').hidden = true;
        active = null;
        if (restoreFocus) button.focus();
    }
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-external-load], [data-external-close]');
        if (!button) return;
        if (button.hasAttribute('data-external-close')) return close(true);
        close();
        const panel = button.closest('.p-external-video');
        const frame = document.createElement('iframe');
        frame.title = button.textContent.replace('Muat video', 'Pemutar');
        frame.sandbox = 'allow-scripts allow-same-origin';
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        frame.src = panel.dataset.externalSrc;
        active = panel;
        const closeButton = panel.querySelector('[data-external-close]');
        closeButton.hidden = false;
        panel.querySelector('.p-external-frame').append(frame);
        button.hidden = true;
        closeButton.focus();
        observer.observe(panel);
        if (new URL(frame.src).hostname === 'www.tiktok.com') {
            timer = setTimeout(() => report('Jika pemutar gagal dimuat, buka video di TikTok.'), 12000);
        } else {
            report('Jika Reel tidak tampil, gunakan tautan Instagram. Reel privat atau dengan sematan dibatasi mungkin memerlukan akses dari pemilik.');
        }
    });
    function report(message) {
        const status = active?.querySelector('.p-external-status');
        if (!status) return;
        status.textContent = message;
        status.hidden = !message;
    }
    window.addEventListener('message', event => {
        if (event.origin !== 'https://www.tiktok.com'
            || event.source !== active?.querySelector('iframe')?.contentWindow
            || event.data?.['x-tiktok-player'] !== true) return;
        if (event.data.type === 'onPlayerReady') {
            clearTimeout(timer);
            report('');
        }
        if (['onPlayerError', 'onError'].includes(event.data.type)) {
            clearTimeout(timer);
            report('Video tidak tersedia di pemutar. Coba buka di TikTok.');
        }
    });
    document.addEventListener('promerty:dialog-closing', event => {
        if (event.target.contains(active)) close();
    }, true);
    document.addEventListener('visibilitychange', () => { if (document.hidden) close(); });
    window.addEventListener('pagehide', () => close());
}
