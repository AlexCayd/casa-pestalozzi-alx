/* Ayuda de nomenclatura compartida por los mapas de POS y Reservaciones. */
(function (window, document) {
    'use strict';

    var lastTriggerByDialog = new WeakMap();
    var initializedDialogs = new WeakSet();

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
})(window, document);
