(function () {
    'use strict';

    function initOperationalShell() {
        var page = document.querySelector('[data-operational-page]');
        if (!page) {
            return;
        }

        var drawer = page.querySelector('[data-operational-drawer]');
        var backdrop = page.querySelector('[data-operational-drawer-backdrop]');
        var toggles = Array.prototype.slice.call(page.querySelectorAll('[data-operational-drawer-toggle]'));
        var closeButtons = Array.prototype.slice.call(page.querySelectorAll('[data-operational-drawer-close]'));
        var countSource = page.querySelector('[data-operation-count], #mapa-reserva-count');
        var countTargets = Array.prototype.slice.call(page.querySelectorAll('[data-operational-drawer-count]'));
        var panelClose = page.querySelector('[data-operation-panel-close]');
        var operationRoot = page.querySelector('[data-page="reservation-operation"]');
        var lastFocus = null;

        function updateNavigation(fecha, hora) {
            var date = String(fecha || '').trim();
            var hour = String(hora || '').trim();
            Array.prototype.forEach.call(page.querySelectorAll('[data-operational-nav]'), function (link) {
                var url = new URL(link.href, window.location.origin);
                if (date) {
                    url.searchParams.set('fecha', date);
                } else {
                    url.searchParams.delete('fecha');
                }
                if (hour && hour !== 'Sin horario') {
                    url.searchParams.set('hora', hour);
                } else {
                    url.searchParams.delete('hora');
                }
                link.href = url.pathname + (url.search ? url.search : '');
            });
            var mapDate = page.querySelector('[data-operational-map-date]');
            if (mapDate && date) {
                mapDate.textContent = date;
            }
        }

        function syncHeaderContext() {
            var dateSource = page.querySelector('[data-operational-context-date], #mapa-fecha');
            var hourSource = page.querySelector('[data-operational-context-hour]');
            var date = dateSource ? (dateSource.value || dateSource.textContent) : '';
            var hour = hourSource ? (hourSource.value || hourSource.textContent) : '';
            updateNavigation(date, hour);
        }

        function focusableElements() {
            if (!drawer) {
                return [];
            }
            return Array.prototype.slice.call(drawer.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(function (element) {
                return element.offsetParent !== null;
            });
        }

        function setDrawer(open, restoreFocus) {
            if (!drawer) {
                return;
            }
            if (open) {
                lastFocus = document.activeElement;
            }
            page.classList.toggle('is-drawer-open', open);
            drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
            drawer.toggleAttribute('inert', !open);
            toggles.forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            if (backdrop) {
                backdrop.hidden = !open;
            }
            if (operationRoot) {
                operationRoot.classList.toggle('is-panel-suppressed', open);
            }
            if (open) {
                var focusable = focusableElements();
                (focusable[0] || drawer).focus();
            } else if (restoreFocus !== false && lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            }
        }

        function syncCount() {
            if (!countSource) {
                return;
            }
            var value = (countSource.textContent || '0').trim();
            countTargets.forEach(function (target) {
                target.textContent = value;
            });
        }

        toggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                setDrawer(!page.classList.contains('is-drawer-open'));
            });
        });
        closeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setDrawer(false);
            });
        });
        if (backdrop) {
            backdrop.addEventListener('click', function () {
                setDrawer(false);
            });
        }
        if (panelClose && operationRoot) {
            panelClose.addEventListener('click', function () {
                operationRoot.classList.add('is-panel-dismissed');
                var drawerToggle = toggles[0];
                if (drawerToggle) {
                    drawerToggle.focus();
                }
            });
        }

        page.addEventListener('click', function (event) {
            if (event.target.closest('[data-operation-reservation]')) {
                if (operationRoot) {
                    operationRoot.classList.remove('is-panel-dismissed');
                }
                setDrawer(false, false);
            } else if (event.target.closest('.reserva-card')) {
                setDrawer(false, false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && page.classList.contains('is-drawer-open')) {
                event.preventDefault();
                setDrawer(false);
                return;
            }
            if (event.key !== 'Tab' || !page.classList.contains('is-drawer-open') || !drawer) {
                return;
            }
            var focusable = focusableElements();
            if (!focusable.length) {
                event.preventDefault();
                drawer.focus();
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        if (countSource && window.MutationObserver) {
            new MutationObserver(syncCount).observe(countSource, { childList: true, characterData: true, subtree: true });
        }
        Array.prototype.forEach.call(page.querySelectorAll('[data-operational-context-date], [data-operational-context-hour]'), function (source) {
            if (window.MutationObserver) {
                new MutationObserver(syncHeaderContext).observe(source, { childList: true, characterData: true, subtree: true });
            }
        });
        document.addEventListener('operational:contextchange', function (event) {
            var detail = event.detail || {};
            updateNavigation(detail.fecha, detail.hora);
        });
        document.addEventListener('operational:close-drawer', function () {
            setDrawer(false, false);
        });
        syncCount();
        syncHeaderContext();
        setDrawer(false, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initOperationalShell);
    } else {
        initOperationalShell();
    }
})();
