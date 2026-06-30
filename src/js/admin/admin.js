/**
 * Gestiona la navegacion global del panel de administracion.
 * Sincroniza drawer movil, colapso desktop y persistencia local del sidebar.
 */
(function () {
    function initAdminSidebar() {
        const body = document.body;
        const sidebar = document.querySelector('[data-admin-sidebar]');
        const toggle = document.querySelector('[data-admin-sidebar-toggle]');
        const closeButton = document.querySelector('[data-admin-sidebar-close]');
        const backdrop = document.querySelector('[data-admin-sidebar-backdrop]');
        const mobileQuery = window.matchMedia('(max-width: 992px)');
        const storageKey = 'cp-admin-sidebar-collapsed';
        const root = document.documentElement;

        if (!sidebar || !toggle || !closeButton || !backdrop) {
            return;
        }

        body.classList.toggle('is-sidebar-collapsed', root.classList.contains('admin-sidebar-collapsed'));

        function isCollapsed() {
            return root.classList.contains('admin-sidebar-collapsed');
        }

        function updateToggleLabel() {
            const isMobile = mobileQuery.matches;
            const isOpen = body.classList.contains('is-sidebar-open');
            const collapsed = isCollapsed();
            const label = isMobile
                ? (isOpen ? 'Cerrar navegacion' : 'Abrir navegacion')
                : (collapsed ? 'Expandir navegacion' : 'Contraer navegacion');

            toggle.setAttribute('aria-expanded', String(isMobile ? isOpen : !collapsed));
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
        }

        function setCollapsed(shouldCollapse) {
            const nextState = Boolean(shouldCollapse);

            root.classList.toggle('admin-sidebar-collapsed', nextState);
            body.classList.toggle('is-sidebar-collapsed', nextState);

            try {
                window.localStorage.setItem(storageKey, nextState ? '1' : '0');
            } catch (error) {
                // localStorage can be unavailable in private or restricted contexts.
            }

            updateToggleLabel();
        }

        function setOpen(isOpen) {
            const shouldOpen = mobileQuery.matches && isOpen;

            body.classList.toggle('is-sidebar-open', shouldOpen);
            sidebar.setAttribute('aria-hidden', String(mobileQuery.matches && !shouldOpen));
            sidebar.inert = mobileQuery.matches && !shouldOpen;
            updateToggleLabel();
        }

        toggle.addEventListener('click', function () {
            if (mobileQuery.matches) {
                setOpen(!body.classList.contains('is-sidebar-open'));
                return;
            }

            setCollapsed(!isCollapsed());
        });

        closeButton.addEventListener('click', function () {
            setOpen(false);
            toggle.focus();
        });

        backdrop.addEventListener('click', function () {
            setOpen(false);
            toggle.focus();
        });

        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setOpen(false);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && body.classList.contains('is-sidebar-open')) {
                setOpen(false);
                toggle.focus();
            }
        });

        mobileQuery.addEventListener('change', function () {
            setOpen(false);
            updateToggleLabel();
        });

        setOpen(false);

        window.requestAnimationFrame(function () {
            root.classList.remove('admin-sidebar-preload');
            root.classList.add('admin-sidebar-ready');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAdminSidebar();
    });
})();
