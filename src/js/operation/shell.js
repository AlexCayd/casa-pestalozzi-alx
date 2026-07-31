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
        var mapToggles = Array.prototype.slice.call(page.querySelectorAll('[data-operational-map-toggle]'));
        var userMenus = Array.prototype.slice.call(page.querySelectorAll('[data-operational-user-menu]'));
        var lastFocus = null;
        var mapStateKey = page.getAttribute('data-operational-map-state-key') || 'pos';
        var MAP_MAX_KEY = 'cp-' + mapStateKey + '-map-maximized';

        function notifyMapResize(maximized) {
            var dispatchResize = function () {
                try {
                    window.dispatchEvent(new Event('resize'));
                } catch (error) {
                    var resizeEvent = document.createEvent('Event');
                    resizeEvent.initEvent('resize', true, true);
                    window.dispatchEvent(resizeEvent);
                }

                page.dispatchEvent(new CustomEvent('operational:mapresize', {
                    bubbles: true,
                    detail: { maximized: Boolean(maximized) }
                }));
            };

            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(function () {
                    window.requestAnimationFrame(dispatchResize);
                });
            } else {
                window.setTimeout(dispatchResize, 0);
            }
        }

        function setMapMaximized(on) {
            if (on && page.classList.contains('is-drawer-open')) {
                setDrawer(false, false);
            }
            page.classList.toggle('is-map-maximized', on);
            mapToggles.forEach(function (toggle) {
                toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
                var mapLabel = toggle.getAttribute('data-operational-map-label');
                var label = toggle.classList.contains('operational-map-toggle--icon')
                    ? (on ? 'Restaurar vista' : 'Maximizar mapa')
                    : mapLabel
                    ? (on ? 'Restaurar ' + mapLabel : 'Maximizar ' + mapLabel)
                    : (on ? 'Restaurar vista' : 'Maximizar mapa');
                toggle.setAttribute('aria-label', label);
                toggle.setAttribute('title', label);
                var text = toggle.querySelector('.operational-map-toggle__label');
                if (text) {
                    text.textContent = label;
                }
            });
            try {
                window.localStorage.setItem(MAP_MAX_KEY, on ? '1' : '0');
            } catch (error) {
                /* almacenamiento no disponible: se ignora */
            }
            notifyMapResize(on);
        }

        if (mapToggles.length) {
            var savedMax = false;
            try {
                savedMax = window.localStorage.getItem(MAP_MAX_KEY) === '1';
            } catch (error) {
                savedMax = false;
            }
            setMapMaximized(savedMax);
            mapToggles.forEach(function (toggle) {
                toggle.addEventListener('click', function () {
                    setMapMaximized(!page.classList.contains('is-map-maximized'));
                });
            });
        }

        function userMenuItems(menu) {
            var panel = menu.querySelector('[data-operational-user-panel]');
            if (!panel) {
                return [];
            }

            return Array.prototype.slice.call(panel.querySelectorAll(
                '[role="menuitem"], button:not([disabled]), a[href]'
            )).filter(function (item) {
                return !item.hidden && item.offsetParent !== null;
            });
        }

        function setUserMenu(menu, open, restoreFocus) {
            var toggle = menu.querySelector('[data-operational-user-toggle]');
            var panel = menu.querySelector('[data-operational-user-panel]');
            if (!toggle || !panel) {
                return;
            }

            if (open) {
                userMenus.forEach(function (otherMenu) {
                    if (otherMenu !== menu) {
                        setUserMenu(otherMenu, false, false);
                    }
                });
            }

            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;

            if (!open && restoreFocus !== false) {
                toggle.focus();
            }
        }

        userMenus.forEach(function (menu) {
            var toggle = menu.querySelector('[data-operational-user-toggle]');
            var panel = menu.querySelector('[data-operational-user-panel]');

            if (!toggle || !panel) {
                return;
            }

            toggle.addEventListener('click', function () {
                var open = toggle.getAttribute('aria-expanded') === 'true';
                setUserMenu(menu, !open, false);
            });

            toggle.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowDown' && event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setUserMenu(menu, true, false);
                    var firstItem = userMenuItems(menu)[0];
                    if (firstItem) {
                        firstItem.focus();
                    }
                }
            });

            panel.addEventListener('keydown', function (event) {
                var items = userMenuItems(menu);
                if (event.key === 'Escape') {
                    event.preventDefault();
                    setUserMenu(menu, false, true);
                    return;
                }
                if (!items.length || ['ArrowDown', 'ArrowUp', 'Home', 'End'].indexOf(event.key) === -1) {
                    return;
                }

                event.preventDefault();
                var current = items.indexOf(document.activeElement);
                var next = current === -1 ? 0 : current;
                if (event.key === 'ArrowDown') next = Math.min(items.length - 1, next + 1);
                if (event.key === 'ArrowUp') next = Math.max(0, next - 1);
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = items.length - 1;
                items[next].focus();
            });
        });

        document.addEventListener('click', function (event) {
            userMenus.forEach(function (menu) {
                if (!menu.contains(event.target)) {
                    setUserMenu(menu, false, false);
                }
            });
        });

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
            if (event.key === 'Escape') {
                var openUserMenu = userMenus.find(function (menu) {
                    var toggle = menu.querySelector('[data-operational-user-toggle]');
                    return toggle && toggle.getAttribute('aria-expanded') === 'true';
                });
                if (openUserMenu) {
                    event.preventDefault();
                    setUserMenu(openUserMenu, false, true);
                    return;
                }
                if (page.classList.contains('is-drawer-open')) {
                    event.preventDefault();
                    setDrawer(false);
                    return;
                }
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
