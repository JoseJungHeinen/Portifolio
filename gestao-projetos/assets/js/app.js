document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.getElementById('app-sidebar');
    const mains = Array.from(document.querySelectorAll('.app-main'));
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const storageKey = 'app-sidebar-collapsed';
    const isDesktop = () => window.innerWidth >= 768;

    // Fade-in na entrada
    body.classList.add('page-fade-in');

    const setCollapsedState = (collapsed) => {
        if (sidebar) {
            sidebar.classList.toggle('is-collapsed', collapsed);
        }
        mains.forEach(main => main.classList.toggle('is-collapsed', collapsed));
        body.classList.toggle('sidebar-collapsed', collapsed);
    };

    const syncToggle = () => {
        if (!sidebarToggle) return;
        const collapsed = sidebar ? sidebar.classList.contains('is-collapsed') : body.classList.contains('sidebar-collapsed');
        sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const icon = sidebarToggle.querySelector('.material-symbols-outlined');
        if (icon) {
            icon.textContent = collapsed ? 'menu' : 'menu_open';
        }
    };

    window.toggleAppSidebar = function () {
        if (!isDesktop() || !sidebar) return;
        const collapsed = !sidebar.classList.contains('is-collapsed');
        setCollapsedState(collapsed);
        localStorage.setItem(storageKey, collapsed ? 'true' : 'false');
        syncToggle();
    };

    if (isDesktop() && localStorage.getItem(storageKey) === 'true') {
        setCollapsedState(true);
    }

    if (sidebarToggle) {
        syncToggle();

        sidebarToggle.addEventListener('click', function (event) {
            event.preventDefault();
            window.toggleAppSidebar();
        });
    }

    // Marca item ativo na sidebar pela URL
    const current = window.location.pathname.split('/').pop();
    document.querySelectorAll('aside nav a, aside a').forEach(a => {
        const href = (a.getAttribute('href') || '').split('/').pop();
        if (href && href === current) {
            a.classList.add('bg-white', 'text-red-600', 'font-bold', 'shadow-sm', 'rounded-md');
            a.classList.remove('text-zinc-600', 'opacity-60');
        }
    });
});
