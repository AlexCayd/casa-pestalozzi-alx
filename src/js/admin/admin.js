/**
 * Gestiona la navegación responsive del panel de administración.
 * Sincroniza la apertura del sidebar con controles, teclado y breakpoints.
 */
(function () {
    function initMobileSidebar() {
        const body = document.body;
        const sidebar = document.querySelector('[data-admin-sidebar]');
        const toggle = document.querySelector('[data-admin-sidebar-toggle]');
        const closeButton = document.querySelector('[data-admin-sidebar-close]');
        const backdrop = document.querySelector('[data-admin-sidebar-backdrop]');
        const mobileQuery = window.matchMedia('(max-width: 992px)');

        if (!sidebar || !toggle || !closeButton || !backdrop) {
            return;
        }

        function setOpen(isOpen) {
            const shouldOpen = mobileQuery.matches && isOpen;

            body.classList.toggle('is-sidebar-open', shouldOpen);
            toggle.setAttribute('aria-expanded', String(shouldOpen));
            toggle.setAttribute('aria-label', shouldOpen ? 'Cerrar navegación' : 'Abrir navegación');
            sidebar.setAttribute('aria-hidden', String(mobileQuery.matches && !shouldOpen));
            sidebar.inert = mobileQuery.matches && !shouldOpen;
        }

        toggle.addEventListener('click', function () {
            setOpen(!body.classList.contains('is-sidebar-open'));
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
        });

        setOpen(false);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initMobileSidebar();
    });
})();
