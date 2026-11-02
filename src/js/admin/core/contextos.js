(function () {
    function bootContextos() {
        const trigger = document.querySelector('[data-context-trigger]');
        const menu = document.getElementById('admin-context-menu');
        if (!trigger || !menu) return;
        function position() {
            const rect = trigger.getBoundingClientRect();
            const width = Math.min(280, window.innerWidth - 24);
            menu.style.width = width + 'px';
            menu.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12)) + 'px';
            menu.style.top = Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - menu.offsetHeight - 12)) + 'px';
        }
        menu.addEventListener('toggle', function (event) {
            const open = event.newState === 'open';
            trigger.setAttribute('aria-expanded', String(open));
            if (open) position();
        });
        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (!menu.matches(':popover-open')) menu.showPopover();
                position();
                const links = menu.querySelectorAll('a');
                if (links.length) links[event.key === 'ArrowUp' ? links.length - 1 : 0].focus();
            }
        });
        menu.addEventListener('keydown', function (event) {
            const links = Array.from(menu.querySelectorAll('a'));
            const index = links.indexOf(document.activeElement);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (links.length) links[(index + (event.key === 'ArrowDown' ? 1 : links.length - 1)) % links.length].focus();
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault(); if (links.length) links[event.key === 'Home' ? 0 : links.length - 1].focus();
            } else if (event.key === 'Escape') { event.stopPropagation(); menu.hidePopover(); trigger.focus(); }
        });
        window.addEventListener('resize', function () { if (menu.matches(':popover-open')) position(); });
        // Popover vive en la capa superior: no lo recorta el sidebar con scroll.
        window.addEventListener('scroll', function () { if (menu.matches(':popover-open')) position(); }, true);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootContextos);
    else bootContextos();
})();
