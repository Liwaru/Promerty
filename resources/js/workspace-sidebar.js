const sidebar = document.querySelector('.ws-sidebar');

if (sidebar) {
    const toggle = sidebar.querySelector('.ws-toggle');
    const narrow = matchMedia('(max-width:760px)');
    const setExpanded = (expanded) => toggle.setAttribute('aria-expanded', String(expanded));
    const links = [...sidebar.querySelectorAll('.ws-nav a')];
    const initial = sidebar.querySelector('.ws-nav [aria-current]');
    const markLocation = () => {
        const current = location.hash && links.find(link => link.pathname === location.pathname && link.hash === location.hash);
        for (const link of links) link.removeAttribute('aria-current');
        (current || initial)?.setAttribute('aria-current', 'page');
    };

    sidebar.dataset.ready = 'true';
    markLocation();
    window.addEventListener('hashchange', markLocation);
    toggle.addEventListener('click', () => setExpanded(toggle.getAttribute('aria-expanded') !== 'true'));
    narrow.addEventListener('change', () => setExpanded(false));
    sidebar.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !narrow.matches || toggle.getAttribute('aria-expanded') !== 'true') return;
        event.preventDefault();
        setExpanded(false);
        toggle.focus();
    });
}
