/* Ayuda de nomenclatura compartida por los mapas de POS y Reservaciones. */
(function (window, document) {
    'use strict';

    var lastTriggerByDialog = new WeakMap();
    var initializedDialogs = new WeakSet();
    var initializedHelpLayouts = new WeakSet();
    var scheduledHelpLayouts = new WeakSet();

    function otherModalIsOpen() {
        return Boolean(document.querySelector(
            'dialog[open]:not([data-map-help-dialog]), .mesa-modal--open, [role="dialog"][aria-modal="true"][aria-hidden="false"]'
        ));
    }

    function dialogFor(trigger) {
        var map = trigger.closest('[data-map-component]');
        return map ? map.querySelector('[data-map-help-dialog]') : null;
    }

    function closestTarget(target, selector) {
        return target && typeof target.closest === 'function'
            ? target.closest(selector)
            : null;
    }

    function positionHelpButton(map) {
        if (!map || typeof map.querySelector !== 'function' || typeof window.getComputedStyle !== 'function') return;

        var trigger = map.querySelector('[data-map-help-open]');
        var viewport = map.querySelector('.mesas-map__viewport');
        var floor = map.querySelector('.mesas-map__floor');
        if (!trigger || !trigger.style || !viewport || !floor) return;

        trigger.style.removeProperty('--map-help-offset-top');
        trigger.style.removeProperty('--map-help-offset-right');

        var baseStyle = window.getComputedStyle(trigger);
        var baseTop = parseFloat(baseStyle.top);
        var baseRight = parseFloat(baseStyle.right);
        if (!Number.isFinite(baseTop) || !Number.isFinite(baseRight)) return;

        var obstacles = [];
        var clearance = 2;
        Array.prototype.forEach.call(floor.querySelectorAll('.mesa-pin'), function (pin) {
            var rect = pin.getBoundingClientRect();
            if (rect.width > 0 && rect.height > 0) obstacles.push(rect);
        });
        Array.prototype.forEach.call(viewport.querySelectorAll('.mapa-canvas-overlay, [role="alert"], [role="status"], .operational-map__structured, .pos-ticket-selection-bar'), function (item) {
            if (item === trigger) return;
            var rect = item.getBoundingClientRect();
            if (rect.width > 0 && rect.height > 0) obstacles.push(rect);
        });

        var collides = function (buttonRect) {
            return obstacles.some(function (obstacle) {
                return buttonRect.right > obstacle.left - clearance
                    && buttonRect.left < obstacle.right + clearance
                    && buttonRect.bottom > obstacle.top - clearance
                    && buttonRect.top < obstacle.bottom + clearance;
            });
        };

        var baseRect = trigger.getBoundingClientRect();
        if (!collides(baseRect)) return;

        var maxTopDelta = Math.floor(Math.min(viewport.clientHeight * 0.4, 180) / 4) * 4;
        var maxRightDelta = Math.floor(Math.min(viewport.clientWidth * 0.4, 240) / 4) * 4;
        var candidates = [];
        for (var topDelta = 0; topDelta <= maxTopDelta; topDelta += 4) {
            for (var rightDelta = 0; rightDelta <= maxRightDelta; rightDelta += 4) {
                if (topDelta === 0 && rightDelta === 0) continue;
                candidates.push({
                    top: baseTop + topDelta,
                    right: baseRight + rightDelta,
                    distance: topDelta * topDelta + rightDelta * rightDelta,
                    topDelta: topDelta
                });
            }
        }
        candidates.sort(function (a, b) {
            return a.distance - b.distance || a.topDelta - b.topDelta;
        });

        for (var i = 0; i < candidates.length; i += 1) {
            var candidate = candidates[i];
            trigger.style.setProperty('--map-help-offset-top', candidate.top + 'px');
            trigger.style.setProperty('--map-help-offset-right', candidate.right + 'px');
            if (!collides(trigger.getBoundingClientRect())) return;
        }

        trigger.style.removeProperty('--map-help-offset-top');
        trigger.style.removeProperty('--map-help-offset-right');
    }

    function scheduleHelpLayout(map) {
        if (scheduledHelpLayouts.has(map)) return;
        scheduledHelpLayouts.add(map);
        var update = function () {
            scheduledHelpLayouts.delete(map);
            positionHelpButton(map);
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(update);
        } else {
            update();
        }
    }

    function watchHelpLayout(map) {
        if (!map || initializedHelpLayouts.has(map) || typeof map.querySelector !== 'function') return;
        var viewport = map.querySelector('.mesas-map__viewport');
        var floor = map.querySelector('.mesas-map__floor');
        if (!viewport || !floor) return;
        initializedHelpLayouts.add(map);

        if (typeof window.ResizeObserver === 'function') {
            var resizeObserver = new window.ResizeObserver(function () {
                scheduleHelpLayout(map);
            });
            resizeObserver.observe(viewport);
            resizeObserver.observe(floor);
        }

        if (typeof window.MutationObserver === 'function') {
            var mutationObserver = new window.MutationObserver(function () {
                scheduleHelpLayout(map);
            });
            mutationObserver.observe(floor, {
                attributes: true,
                attributeFilter: ['class', 'style', 'hidden'],
                childList: true,
                subtree: true
            });
        }

        scheduleHelpLayout(map);
    }

    function initializeHelpLayouts() {
        if (typeof document.querySelectorAll !== 'function') return;
        Array.prototype.forEach.call(document.querySelectorAll('[data-map-component]'), watchHelpLayout);
    }

    function initializeDialog(dialog) {
        if (initializedDialogs.has(dialog)) return;
        initializedDialogs.add(dialog);

        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        dialog.addEventListener('close', function () {
            var trigger = lastTriggerByDialog.get(dialog);
            lastTriggerByDialog.delete(dialog);
            if (trigger && trigger.isConnected && typeof trigger.focus === 'function') {
                trigger.focus();
            }
        });
    }

    document.addEventListener('click', function (event) {
        var closeButton = closestTarget(event.target, '[data-map-help-close]');
        if (closeButton) {
            var closeDialog = closeButton.closest('[data-map-help-dialog]');
            if (closeDialog && closeDialog.open) closeDialog.close();
            return;
        }

        var trigger = closestTarget(event.target, '[data-map-help-open]');
        if (!trigger || otherModalIsOpen()) return;

        var map = trigger.closest('[data-map-component]');
        if (map) positionHelpButton(map);
        var dialog = dialogFor(trigger);
        if (!dialog || dialog.open || typeof dialog.showModal !== 'function') return;

        initializeDialog(dialog);
        lastTriggerByDialog.set(dialog, trigger);
        dialog.showModal();

        var initialFocus = dialog.querySelector('[autofocus], [data-map-help-close]');
        if (initialFocus && typeof initialFocus.focus === 'function') {
            initialFocus.focus();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeHelpLayouts, { once: true });
    } else {
        initializeHelpLayouts();
    }

    if (typeof window.addEventListener === 'function') {
        window.addEventListener('resize', function () {
            if (typeof document.querySelectorAll !== 'function') return;
            Array.prototype.forEach.call(document.querySelectorAll('[data-map-component]'), scheduleHelpLayout);
        });
    }
})(window, document);
