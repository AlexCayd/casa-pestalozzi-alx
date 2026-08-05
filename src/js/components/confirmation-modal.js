/*
 * Shell común de confirmaciones operativas.
 *
 * Este componente sólo pinta estados y devuelve eventos. Cada consumidor
 * conserva sus reglas, endpoints y textos de negocio.
 */
(function (window, document) {
    'use strict';

    var sequence = 0;
    var defaultController = null;

    function textValue(value) {
        return value == null ? '' : String(value);
    }

    function renderBlock(target, value, className) {
        target.replaceChildren();
        if (value == null || value === '') {
            target.hidden = true;
            return;
        }

        target.hidden = false;
        if (Array.isArray(value)) {
            var list = document.createElement('ul');
            list.className = className + '-list';
            value.forEach(function (item) {
                var li = document.createElement('li');
                li.textContent = textValue(item);
                list.appendChild(li);
            });
            target.appendChild(list);
            return;
        }

        target.textContent = textValue(value);
    }

    function create(host) {
        host = host || document.body;
        var id = ++sequence;
        var root = document.createElement('div');
        root.className = 'cp-confirmation-modal';
        root.hidden = true;
        root.innerHTML =
            '<div class="cp-confirmation-modal__backdrop" data-confirmation-backdrop></div>' +
            '<div class="cp-confirmation-modal__dialog" role="dialog" aria-modal="true" tabindex="-1">' +
              '<div class="cp-confirmation-modal__head">' +
                '<div>' +
                  '<span class="cp-confirmation-modal__eyebrow" data-confirmation-eyebrow></span>' +
                  '<h2 class="cp-confirmation-modal__title" data-confirmation-title></h2>' +
                '</div>' +
                '<button type="button" class="cp-confirmation-modal__close" aria-label="Cerrar confirmación" data-confirmation-close>×</button>' +
              '</div>' +
              '<p class="cp-confirmation-modal__description" data-confirmation-description></p>' +
              '<div class="cp-confirmation-modal__summary" data-confirmation-summary></div>' +
              '<p class="cp-confirmation-modal__warning" role="note" data-confirmation-warning></p>' +
              '<p class="cp-confirmation-modal__consequence" data-confirmation-consequence></p>' +
              '<p class="cp-confirmation-modal__status" role="status" aria-live="polite" data-confirmation-status hidden></p>' +
              '<div class="cp-confirmation-modal__actions">' +
                '<button type="button" class="cp-confirmation-modal__button cp-confirmation-modal__button--secondary" data-confirmation-secondary></button>' +
                '<button type="button" class="cp-confirmation-modal__button cp-confirmation-modal__button--primary" data-confirmation-primary></button>' +
              '</div>' +
            '</div>';
        host.appendChild(root);

        var dialog = root.querySelector('.cp-confirmation-modal__dialog');
        var title = root.querySelector('[data-confirmation-title]');
        var description = root.querySelector('[data-confirmation-description]');
        var eyebrow = root.querySelector('[data-confirmation-eyebrow]');
        var summary = root.querySelector('[data-confirmation-summary]');
        var warning = root.querySelector('[data-confirmation-warning]');
        var consequence = root.querySelector('[data-confirmation-consequence]');
        var status = root.querySelector('[data-confirmation-status]');
        var secondary = root.querySelector('[data-confirmation-secondary]');
        var primary = root.querySelector('[data-confirmation-primary]');
        var closeButton = root.querySelector('[data-confirmation-close]');
        var backdrop = root.querySelector('[data-confirmation-backdrop]');
        var lastFocused = null;
        var current = null;
        var previousBodyClass = false;

        title.id = 'cp-confirmation-title-' + id;
        description.id = 'cp-confirmation-description-' + id;
        dialog.setAttribute('aria-labelledby', title.id);
        dialog.setAttribute('aria-describedby', description.id);

        function focusables() {
            return Array.prototype.slice.call(dialog.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
            ));
        }

        function setLoading(loading) {
            var isLoading = Boolean(loading);
            root.classList.toggle('is-loading', isLoading);
            primary.disabled = isLoading || primary.getAttribute('data-disabled') === '1';
            secondary.disabled = isLoading || secondary.getAttribute('data-disabled') === '1';
            closeButton.disabled = isLoading;
            primary.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            status.hidden = !isLoading;
            status.textContent = isLoading ? 'Procesando…' : '';
        }

        function close(restoreFocus) {
            if (root.hidden) return;
            var focusTarget = current && current.focusTarget;
            root.classList.remove('is-open', 'is-loading');
            root.hidden = true;
            if (previousBodyClass) {
                document.body.classList.remove('has-cp-confirmation');
                previousBodyClass = false;
            }
            var target = restoreFocus === false
                ? null
                : (focusTarget && document.contains(focusTarget) ? focusTarget : lastFocused);
            current = null;
            if (target && typeof target.focus === 'function') target.focus();
        }

        function open(options) {
            options = options || {};
            current = options;
            lastFocused = document.activeElement;
            root.className = 'cp-confirmation-modal cp-confirmation-modal--' + textValue(options.variant || 'default');
            root.hidden = false;
            eyebrow.textContent = textValue(options.eyebrow || 'Confirmación');
            title.textContent = textValue(options.title || 'Confirma esta acción');
            description.textContent = textValue(options.description || '');
            description.hidden = !description.textContent;
            renderBlock(summary, options.summary, 'cp-confirmation-modal__summary');
            renderBlock(warning, options.warning, 'cp-confirmation-modal__warning');
            renderBlock(consequence, options.consequence, 'cp-confirmation-modal__consequence');
            secondary.textContent = textValue(options.secondaryLabel || 'Cancelar');
            primary.textContent = textValue(options.primaryLabel || 'Continuar');
            secondary.hidden = options.secondaryHidden === true;
            primary.hidden = options.primaryHidden === true;
            secondary.setAttribute('data-disabled', options.secondaryDisabled ? '1' : '0');
            primary.setAttribute('data-disabled', options.primaryDisabled ? '1' : '0');
            if (options.primaryDisabled) primary.disabled = true;
            if (options.secondaryDisabled) secondary.disabled = true;
            if (options.loading) setLoading(true);
            if (!previousBodyClass) {
                document.body.classList.add('has-cp-confirmation');
                previousBodyClass = true;
            }
            window.requestAnimationFrame(function () {
                root.classList.add('is-open');
                var preferred = options.focus === 'primary' ? primary : (secondary.hidden ? primary : secondary);
                if (preferred && !preferred.disabled) preferred.focus();
            });
        }

        closeButton.addEventListener('click', function () {
            close(true);
        });
        backdrop.addEventListener('click', function () {
            if (!root.classList.contains('is-loading')) close(true);
        });
        secondary.addEventListener('click', function () {
            if (secondary.disabled) return;
            var callback = current && current.onSecondary;
            close(true);
            if (typeof callback === 'function') callback();
        });
        primary.addEventListener('click', function () {
            if (primary.disabled) return;
            var callback = current && current.onPrimary;
            if (current && current.loadingOnPrimary !== false) setLoading(true);
            if (typeof callback === 'function') callback();
        });
        root.addEventListener('keydown', function (event) {
            if (root.hidden) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
                return;
            }
            if (event.key !== 'Tab') return;
            var items = focusables();
            if (!items.length) {
                event.preventDefault();
                dialog.focus();
                return;
            }
            var first = items[0];
            var last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        return {
            element: root,
            open: open,
            close: close,
            setLoading: setLoading
        };
    }

    window.CPConfirmationModal = {
        create: create,
        get: function () {
            if (!defaultController || !document.contains(defaultController.element)) {
                defaultController = create(document.body);
            }
            return defaultController;
        }
    };
}(window, document));
