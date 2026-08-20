/*
 * Shell único para confirmaciones operativas.
 *
 * El componente sólo resuelve presentación, foco y estados de interacción.
 * Los consumidores conservan la causa, el resumen, la consecuencia y sus
 * endpoints, pero siempre los entregan mediante las mismas regiones.
 */
(function (window, document) {
    'use strict';

    var sequence = 0;
    var defaultController = null;

    /*
     * Bloqueo de scroll del documento.
     *
     * En el panel delega en AdminScrollLock (motion.js), que lleva contador y
     * además detiene Lenis: con el motor de scroll suave corriendo,
     * `overflow:hidden` no impide nada porque Lenis desplaza por código, y los
     * dos sistemas de modal se pisaban al restaurar el valor.
     * El respaldo es para el landing y el POS, donde ese lock no existe.
     */
    var overflowPropio = '';
    var bloqueosPropios = 0;

    function bloquearScrollDocumento() {
        if (window.AdminScrollLock) {
            window.AdminScrollLock.bloquear();
            return;
        }
        bloqueosPropios++;
        if (bloqueosPropios > 1) {
            return;
        }
        overflowPropio = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }

    function desbloquearScrollDocumento() {
        if (window.AdminScrollLock) {
            window.AdminScrollLock.desbloquear();
            return;
        }
        if (bloqueosPropios === 0) {
            return;
        }
        bloqueosPropios--;
        if (bloqueosPropios === 0) {
            document.body.style.overflow = overflowPropio;
            overflowPropio = '';
        }
    }

    function textValue(value) {
        return value == null ? '' : String(value);
    }

    function renderBlock(target, value, className) {
        target.replaceChildren();
        if (value == null || value === '' || (Array.isArray(value) && value.length === 0)) {
            target.hidden = true;
            target.setAttribute('aria-hidden', 'true');
            return;
        }

        target.hidden = false;
        target.removeAttribute('aria-hidden');
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

    function renderSummary(target, options) {
        target.replaceChildren();
        var rows = Array.isArray(options.summaryRows) ? options.summaryRows : [];
        if (rows.length) {
            target.hidden = false;
            target.removeAttribute('aria-hidden');
            var comparison = document.createElement('div');
            comparison.className = 'confirmation-modal__comparison';
            comparison.setAttribute('role', 'table');
            rows.forEach(function (row) {
                var item = document.createElement('div');
                item.className = 'confirmation-modal__comparison-row';
                item.setAttribute('role', 'row');
                if (row.changed) item.classList.add('is-changed');

                var label = document.createElement('strong');
                label.className = 'confirmation-modal__comparison-label';
                label.textContent = textValue(row.label);
                item.appendChild(label);

                ['current', 'proposed'].forEach(function (key) {
                    var cell = document.createElement('span');
                    cell.className = 'confirmation-modal__comparison-cell';
                    cell.setAttribute('role', 'cell');
                    var heading = document.createElement('small');
                    heading.textContent = key === 'current' ? 'Actual' : 'Nueva';
                    var value = document.createElement('span');
                    value.textContent = textValue(row[key]);
                    cell.append(heading, value);
                    item.appendChild(cell);
                });
                comparison.appendChild(item);
            });
            target.appendChild(comparison);
            return;
        }

        renderBlock(target, options.summary, 'confirmation-modal__summary');
    }

    function appendCustomContent(target, content) {
        target.replaceChildren();
        if (!(content instanceof Node)) {
            target.hidden = true;
            target.setAttribute('aria-hidden', 'true');
            return;
        }
        target.hidden = false;
        target.removeAttribute('aria-hidden');
        target.appendChild(content);
    }

    function create(host) {
        var id = ++sequence;
        var root = document.createElement('div');
        root.className = 'confirmation-modal';
        root.hidden = true;
        root.setAttribute('aria-hidden', 'true');
        root.inert = true;
        root.innerHTML =
            '<div class="confirmation-modal__backdrop" data-confirmation-backdrop></div>' +
            '<div class="confirmation-modal__dialog" role="dialog" aria-modal="true" tabindex="-1">' +
              '<header class="confirmation-modal__header">' +
                '<span class="confirmation-modal__icon" aria-hidden="true" data-confirmation-icon>!</span>' +
                '<div class="confirmation-modal__heading">' +
                  '<span class="confirmation-modal__eyebrow" data-confirmation-eyebrow></span>' +
                  '<h2 class="confirmation-modal__title" data-confirmation-title></h2>' +
                  '<p class="confirmation-modal__description" data-confirmation-description></p>' +
                '</div>' +
                '<button type="button" class="confirmation-modal__close" aria-label="Cerrar confirmación" data-confirmation-close>×</button>' +
              '</header>' +
              '<div class="confirmation-modal__body">' +
                '<div class="confirmation-modal__summary" data-confirmation-summary></div>' +
                '<div class="confirmation-modal__warning" role="note" data-confirmation-warning></div>' +
                '<div class="confirmation-modal__consequence" data-confirmation-consequence></div>' +
                '<div class="confirmation-modal__custom" data-confirmation-custom></div>' +
                '<p class="confirmation-modal__status" role="status" aria-live="polite" data-confirmation-status hidden></p>' +
              '</div>' +
              '<footer class="confirmation-modal__actions">' +
                '<button type="button" class="confirmation-modal__button confirmation-modal__button--secondary" data-confirmation-secondary></button>' +
                '<button type="button" class="confirmation-modal__button confirmation-modal__button--primary" data-confirmation-primary></button>' +
              '</footer>' +
            '</div>';
        // Portal global: el shell no debe heredar restricciones de paneles o
        // mapas. Un dialog nativo abierto vive en el top layer del navegador;
        // en ese caso el portal debe permanecer dentro de él para que el
        // overlay sea visible y reciba interacción sobre el formulario.
        var activeDialog = host && typeof host.closest === 'function'
            ? host.closest('dialog')
            : null;
        (activeDialog || document.body || host).appendChild(root);

        var dialog = root.querySelector('.confirmation-modal__dialog');
        var title = root.querySelector('[data-confirmation-title]');
        var description = root.querySelector('[data-confirmation-description]');
        var eyebrow = root.querySelector('[data-confirmation-eyebrow]');
        var icon = root.querySelector('[data-confirmation-icon]');
        var summary = root.querySelector('[data-confirmation-summary]');
        var warning = root.querySelector('[data-confirmation-warning]');
        var consequence = root.querySelector('[data-confirmation-consequence]');
        var custom = root.querySelector('[data-confirmation-custom]');
        var status = root.querySelector('[data-confirmation-status]');
        var secondary = root.querySelector('[data-confirmation-secondary]');
        var primary = root.querySelector('[data-confirmation-primary]');
        var closeButton = root.querySelector('[data-confirmation-close]');
        var backdrop = root.querySelector('[data-confirmation-backdrop]');
        var lastFocused = null;
        var current = null;
        var bodyScrollLocked = false;
        var resolveOpen = null;

        title.id = 'confirmation-modal-title-' + id;
        description.id = 'confirmation-modal-description-' + id;
        dialog.setAttribute('aria-labelledby', title.id);
        dialog.setAttribute('aria-describedby', description.id);

        function focusables() {
            return Array.prototype.slice.call(dialog.querySelectorAll(
                'button:not([disabled]):not([hidden]), input:not([disabled]):not([hidden]), select:not([disabled]):not([hidden]), textarea:not([disabled]):not([hidden]), a[href], [tabindex]:not([tabindex="-1"])'
            ));
        }

        function setStatus(message, isError) {
            status.textContent = textValue(message);
            status.hidden = !status.textContent;
            status.classList.toggle('is-error', Boolean(isError));
            status.setAttribute('role', isError ? 'alert' : 'status');
        }

        function setLoading(loading) {
            var isLoading = Boolean(loading);
            root.classList.toggle('is-loading', isLoading);
            primary.disabled = isLoading || primary.getAttribute('data-disabled') === '1';
            secondary.disabled = isLoading || secondary.getAttribute('data-disabled') === '1';
            closeButton.disabled = isLoading;
            dialog.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            if (isLoading) setStatus('Procesando…', false);
            else if (status.textContent === 'Procesando…') setStatus('', false);
        }

        function settle(result) {
            if (typeof resolveOpen === 'function') {
                var resolver = resolveOpen;
                resolveOpen = null;
                resolver(result || { action: 'close' });
            }
        }

        function close(restoreFocus, result) {
            if (root.hidden) return;
            root.classList.remove('is-open', 'is-loading');
            root.hidden = true;
            root.setAttribute('aria-hidden', 'true');
            root.inert = true;
            dialog.removeAttribute('aria-busy');
            primary.disabled = false;
            secondary.disabled = false;
            closeButton.disabled = false;
            primary.removeAttribute('data-disabled');
            secondary.removeAttribute('data-disabled');
            setStatus('', false);
            if (bodyScrollLocked) {
                desbloquearScrollDocumento();
                bodyScrollLocked = false;
            }
            var focusTarget = current && current.returnFocus;
            var target = restoreFocus === false
                ? null
                : (focusTarget && document.contains(focusTarget) ? focusTarget : lastFocused);
            current = null;
            if (target && typeof target.focus === 'function') target.focus();
            settle(result || { action: 'close' });
        }

        function requestClose(restoreFocus, result) {
            if (root.hidden || root.classList.contains('is-loading')) return false;
            if (current && (current.closeBehavior === 'non_cancelable' || current.close_behavior === 'non_cancelable')) return false;
            close(restoreFocus, result || { action: 'cancel' });
            return true;
        }

        function resolveInitialFocus(options) {
            if (options.initialFocus instanceof HTMLElement) return options.initialFocus;
            if (options.initialFocus === 'dialog') return dialog;
            if (options.initialFocus === 'primary') return primary;
            if (options.initialFocus === 'secondary') return secondary;
            var items = focusables();
            return options.primaryFocus === true ? primary : (items[0] || dialog);
        }

        function canonicalDecisionActions(options) {
            if (!(options.decision === true || options.tipo === 'decision_requerida')) {
                return null;
            }

            var mensaje = textValue(options.mensaje).trim();
            if (!mensaje && window.console && typeof window.console.error === 'function') {
                window.console.error(
                    'Decisión de reservación sin mensaje',
                    options.decisionData || options
                );
            }

            var actions = Array.isArray(options.actions)
                ? options.actions.filter(function (action) {
                    return action && action.id && action.label && action.tipo;
                })
                : [];
            if (!actions.length) {
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error(
                        'Decisión de reservación sin acciones canónicas',
                        options.decisionData || options
                    );
                }
                return [{ id: 'CERRAR', label: 'Cerrar', tipo: 'secondary' }];
            }
            return actions;
        }

        function actionByType(actions, type) {
            if (!Array.isArray(actions)) return null;
            for (var i = 0; i < actions.length; i++) {
                if (type === 'primary' && actions[i].tipo === 'primary') return actions[i];
                if (type === 'secondary' && actions[i].tipo !== 'primary') return actions[i];
            }
            return null;
        }

        function open(options) {
            options = options || {};
            if (!root.hidden) {
                close(false, { action: 'reopened' });
            }
            current = Object.assign({}, options);
            current.closeBehavior = options.closeBehavior || options.close_behavior || 'cancelable';
            current.decisionActions = canonicalDecisionActions(options);
            current.primaryAction = actionByType(current.decisionActions, 'primary');
            current.secondaryAction = actionByType(current.decisionActions, 'secondary');
            lastFocused = document.activeElement;
            current.returnFocus = options.returnFocus || options.return_focus || options.focusTarget || options.focus_target || lastFocused;
            root.className = 'confirmation-modal confirmation-modal--' + textValue(options.variant || 'default');
            if (options.extraClass) {
                root.classList.add(textValue(options.extraClass));
            }
            root.hidden = false;
            root.removeAttribute('aria-hidden');
            root.inert = false;
            icon.textContent = textValue(options.icon || (options.variant === 'danger' ? '!' : 'i'));
            eyebrow.textContent = textValue(options.eyebrow || 'Confirmación');
            title.textContent = textValue(options.title || 'Confirma esta acción');
            description.textContent = textValue(options.description || 'Revisa el resumen y las consecuencias antes de continuar.');
            description.hidden = !description.textContent;
            renderSummary(summary, options);
            renderBlock(warning, options.warning, 'confirmation-modal__warning');
            renderBlock(consequence, options.consequence, 'confirmation-modal__consequence');
            appendCustomContent(custom, options.customContent);
            setStatus(options.status || '', false);
            if (current.decisionActions) {
                secondary.textContent = textValue(current.secondaryAction && current.secondaryAction.label || 'Cerrar');
                primary.textContent = textValue(current.primaryAction && current.primaryAction.label || '');
                secondary.hidden = !current.secondaryAction;
                primary.hidden = !current.primaryAction;
            } else {
                secondary.textContent = textValue(options.secondaryLabel || 'Cerrar');
                primary.textContent = textValue(options.primaryLabel || 'Continuar');
                secondary.hidden = options.secondaryHidden === true;
                primary.hidden = options.primaryHidden === true;
            }
            secondary.setAttribute('data-disabled', options.secondaryDisabled ? '1' : '0');
            primary.setAttribute('data-disabled', options.primaryDisabled ? '1' : '0');
            secondary.disabled = Boolean(options.secondaryDisabled);
            primary.disabled = Boolean(options.primaryDisabled);
            closeButton.hidden = options.closeHidden === true;
            bloquearScrollDocumento();
            bodyScrollLocked = true;
            if (options.loading || options.initial_loading) setLoading(true);
            window.requestAnimationFrame(function () {
                root.classList.add('is-open');
                var preferred = resolveInitialFocus({
                    initialFocus: options.initialFocus || options.initial_focus,
                    primaryFocus: options.primaryFocus || options.primary_focus
                });
                if (preferred && !preferred.disabled && !preferred.hidden && typeof preferred.focus === 'function') {
                    preferred.focus();
                }
            });
            return new Promise(function (resolve) {
                resolveOpen = resolve;
            });
        }

        closeButton.addEventListener('click', function () {
            requestClose(true, { action: 'close' });
        });
        backdrop.addEventListener('click', function () {
            requestClose(true, { action: 'backdrop' });
        });
        secondary.addEventListener('click', function () {
            if (secondary.disabled) return;
            var callback = current && current.onSecondary;
            if (current && current.secondaryAction && typeof current.onAction === 'function') {
                callback = function () {
                    return current.onAction(current.secondaryAction);
                };
            }
            if (current && current.secondaryCloses === false) {
                try {
                    if (typeof callback === 'function') callback();
                } catch (error) {
                    if (window.console && typeof window.console.error === 'function') {
                        window.console.error(error);
                    }
                }
                return;
            }
            if (!requestClose(true, { action: 'secondary' })) return;
            try {
                if (typeof callback === 'function') callback();
            } catch (error) {
                // El cierre ya dejó el shell limpio; el error no debe bloquear
                // la siguiente apertura del mismo controlador.
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error(error);
                }
            }
        });
        primary.addEventListener('click', function () {
            if (primary.disabled) return;
            var callback = current && current.onPrimary;
            if (current && current.primaryAction && typeof current.onAction === 'function') {
                callback = function () {
                    return current.onAction(current.primaryAction);
                };
            }
            var options = current || {};
            if (current && current.loadingOnPrimary !== false) setLoading(true);
            try {
                var result = typeof callback === 'function' ? callback() : undefined;
                if (result === false) {
                    setLoading(false);
                    return;
                }
                if (result && typeof result.then === 'function') {
                    result.then(function (value) {
                        if (options.primaryCloses === false) {
                            setLoading(false);
                            settle({ action: 'primary', value: value });
                            return;
                        }
                        close(true, { action: 'primary', value: value });
                    }).catch(function (error) {
                        setLoading(false);
                        setStatus('No fue posible completar la acción. Inténtalo nuevamente.', true);
                    });
                    return;
                }
                if (options.primaryCloses === false) {
                    setLoading(false);
                    settle({ action: 'primary', value: result });
                    return;
                }
                close(true, { action: 'primary', value: result });
            } catch (error) {
                setLoading(false);
                setStatus('No fue posible completar la acción. Inténtalo nuevamente.', true);
            }
        });
        root.addEventListener('keydown', function (event) {
            if (root.hidden) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                requestClose(true, { action: 'escape' });
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
            requestClose: requestClose,
            setLoading: setLoading,
            setStatus: setStatus
        };
    }

    window.ConfirmationModal = {
        create: create,
        get: function () {
            if (!defaultController || !document.contains(defaultController.element)) {
                defaultController = create(document.body);
            }
            return defaultController;
        }
    };
}(window, document));
