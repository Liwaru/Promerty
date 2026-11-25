/** Keep only active/next media loaded. */
export function coordinatePlayback({ articles, active, muted = true, blocked = false, reducedMotion = false, onBlocked = () => {} }) {
    const video = active?.querySelector('video');
    const nextVideo = active?.nextElementSibling?.querySelector('video');
    for (const article of articles) {
        const candidate = article.querySelector('video');
        if (!candidate) continue;
        candidate.muted = muted;
        if (blocked || candidate !== video) candidate.pause();
        if (blocked) continue;
        if (candidate === video || candidate === nextVideo) {
            if (!candidate.getAttribute('src')) {
                if (candidate.dataset.time !== undefined) {
                    const time = Number(candidate.dataset.time);
                    delete candidate.dataset.time;
                    candidate.addEventListener('loadedmetadata', () => { candidate.currentTime = time; }, { once:true });
                }
                candidate.preload = 'metadata';
                candidate.src = candidate.dataset.src;
            }
        } else if (candidate.getAttribute('src')) {
            candidate.dataset.time = String(candidate.currentTime);
            candidate.removeAttribute('src');
            candidate.load();
        }
    }
    if (!blocked && video && video.paused && !reducedMotion && video.dataset.userPaused !== 'true') {
        video.play().catch(onBlocked);
    }
}
