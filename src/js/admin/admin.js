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

    function initPasswordToggles() {
        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            const targetId = button.dataset.target;
            const input = targetId ? document.getElementById(targetId) : null;

            if (!input) {
                return;
            }

            button.addEventListener('click', function () {
                const shouldShow = input.type === 'password';
                const label = shouldShow ? 'Ocultar contraseña' : 'Mostrar contraseña';

                input.type = shouldShow ? 'text' : 'password';
                button.classList.toggle('is-visible', shouldShow);
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
            });
        });
    }

    function initPasswordStrengthValidation() {
        const passwordPattern = /^(?=.*[A-Z])(?=.*\d).{8,}$/;

        document.querySelectorAll('[data-password-strength]').forEach(function (input) {
            const describedBy = input.getAttribute('aria-describedby');
            const form = input.closest('form');
            const feedback = describedBy
                ? document.getElementById(describedBy)
                : (form ? form.querySelector('[data-password-feedback]') : null);

            function updatePasswordFeedback() {
                if (!feedback) {
                    return;
                }

                const hasValue = input.value.length > 0;
                const isValid = passwordPattern.test(input.value);

                feedback.classList.toggle('is-valid', hasValue && isValid);
                feedback.classList.toggle('is-invalid', hasValue && !isValid);
            }

            input.addEventListener('input', updatePasswordFeedback);
            input.addEventListener('blur', updatePasswordFeedback);
            updatePasswordFeedback();
        });
    }

    function initDeleteConfirmations() {
        document.querySelectorAll('[data-confirm-delete]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                const confirmed = window.confirm('¿Seguro que deseas eliminar este usuario? Esta acción no se puede deshacer.');

                if (!confirmed) {
                    event.preventDefault();
                }
            });
        });
    }

    function initUserDeleteModal() {
        const modal = document.querySelector('[data-user-delete-modal]');

        if (!modal) {
            return;
        }

        const nameEl = modal.querySelector('[data-modal-username]');
        const selfWarning = modal.querySelector('[data-modal-self]');
        const input = modal.querySelector('[data-modal-input]');
        const confirmBtn = modal.querySelector('[data-modal-confirm]');
        const closers = modal.querySelectorAll('[data-modal-close]');

        let activeForm = null;
        let targetName = '';
        let lastFocused = null;

        function normalize(value) {
            return (value || '').trim().toLowerCase().replace(/\s+/g, ' ');
        }

        function refreshConfirm() {
            confirmBtn.disabled = targetName === '' || normalize(input.value) !== normalize(targetName);
        }

        function openModal(button) {
            activeForm = button.closest('form');
            targetName = button.getAttribute('data-user-name') || '';
            lastFocused = button;

            if (nameEl) {
                nameEl.textContent = targetName;
            }

            // Un admin puede borrarse a sí mismo mientras quede otro admin
            // activo; el efecto colateral (perder la sesión) no es obvio.
            if (selfWarning) {
                selfWarning.hidden = button.getAttribute('data-user-self') !== '1';
            }

            input.value = '';
            confirmBtn.disabled = true;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';

            window.requestAnimationFrame(function () {
                modal.classList.add('is-open');
                input.focus();
            });
        }

        function closeModal() {
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
            window.setTimeout(function () { modal.hidden = true; }, 220);

            if (lastFocused) {
                lastFocused.focus();
            }

            activeForm = null;
        }

        function submitDeletion() {
            if (confirmBtn.disabled || !activeForm) {
                return;
            }

            activeForm.submit();
        }

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-user-delete]');

            if (button) {
                openModal(button);
            }
        });

        input.addEventListener('input', refreshConfirm);
        confirmBtn.addEventListener('click', submitDeletion);
        closers.forEach(function (element) {
            element.addEventListener('click', closeModal);
        });

        document.addEventListener('keydown', function (event) {
            if (modal.hidden) {
                return;
            }

            if (event.key === 'Escape') {
                closeModal();
            } else if (event.key === 'Enter' && document.activeElement === input) {
                event.preventDefault();
                submitDeletion();
            }
        });
    }

    function initAdminModals() {
        let activeModal = null;
        let lastFocused = null;
        let closeTimer = null;

        function focusableElements(modal) {
            const scope = modal.querySelector('[data-admin-modal-dialog]') || modal;
            return Array.from(scope.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(function (element) {
                return !element.hidden && element.getAttribute('aria-hidden') !== 'true';
            });
        }

        function closeModal(restoreFocus) {
            if (!activeModal) {
                return;
            }

            const modal = activeModal;
            activeModal = null;
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
            modal.dispatchEvent(new CustomEvent('admin:modal-closed'));

            window.clearTimeout(closeTimer);
            closeTimer = window.setTimeout(function () {
                modal.hidden = true;
            }, 220);

            if (restoreFocus !== false && lastFocused && document.contains(lastFocused)) {
                lastFocused.focus();
            }
        }

        function openModal(modal, trigger) {
            if (!modal) {
                return;
            }

            if (activeModal && activeModal !== modal) {
                closeModal(false);
            }

            window.clearTimeout(closeTimer);
            lastFocused = trigger || document.activeElement;
            activeModal = modal;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';

            window.requestAnimationFrame(function () {
                modal.classList.add('is-open');
                const preferred = modal.querySelector('[autofocus]');
                const focusables = focusableElements(modal);
                const dialog = modal.querySelector('[data-admin-modal-dialog]');
                const focusTarget = preferred || focusables[0] || dialog;
                if (focusTarget) {
                    focusTarget.focus();
                }
                modal.dispatchEvent(new CustomEvent('admin:modal-opened'));
            });
        }

        document.querySelectorAll('[data-admin-modal-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                openModal(document.getElementById(trigger.dataset.adminModalOpen), trigger);
            });
        });

        document.querySelectorAll('[data-admin-modal]').forEach(function (modal) {
            modal.querySelectorAll('[data-admin-modal-close]').forEach(function (closer) {
                closer.addEventListener('click', function () {
                    if (modal === activeModal) {
                        closeModal(true);
                    }
                });
            });
        });

        document.addEventListener('admin:open-modal', function (event) {
            const modalId = event.detail && event.detail.id;
            openModal(modalId ? document.getElementById(modalId) : null, event.detail ? event.detail.trigger : null);
        });

        document.addEventListener('keydown', function (event) {
            if (!activeModal) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                closeModal(true);
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusables = focusableElements(activeModal);
            if (focusables.length === 0) {
                event.preventDefault();
                return;
            }

            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    }

    function initProblemReport() {
        const form = document.querySelector('[data-problem-report-form]');
        if (!form) {
            return;
        }

        const modal = form.closest('[data-admin-modal]');
        const routeInput = form.querySelector('[data-problem-route]');
        const browserSelect = form.querySelector('[data-problem-browser]');
        const otherGroup = form.querySelector('[data-problem-browser-other]');
        const otherInput = otherGroup ? otherGroup.querySelector('[name="navegador_otro"]') : null;
        const status = form.querySelector('[data-problem-status]');
        const validateButton = form.querySelector('[data-problem-validate]');
        const moduleInput = form.querySelector('[name="modulo"]');

        function detectBrowser() {
            const userAgent = window.navigator.userAgent;

            if (/Edg\//i.test(userAgent) || /Edge\//i.test(userAgent)) {
                return 'edge';
            }
            if (/Firefox\//i.test(userAgent)) {
                return 'firefox';
            }
            if (/Chrome\//i.test(userAgent) || /CriOS\//i.test(userAgent)) {
                return 'chrome';
            }
            if (/Safari\//i.test(userAgent) && !/Chrome\//i.test(userAgent)) {
                return 'safari';
            }
            return 'otro';
        }

        function updateOtherBrowser() {
            const isOther = browserSelect.value === 'otro';
            otherGroup.hidden = !isOther;
            otherInput.disabled = !isOther;
            otherInput.required = isOther;
            if (!isOther) {
                otherInput.value = '';
            }
        }

        function prepareContext() {
            routeInput.value = window.location.pathname;
            if (moduleInput.value.trim() === '') {
                const moduleTitle = document.querySelector('.admin-topbar__module');
                moduleInput.value = moduleTitle ? moduleTitle.textContent.trim() : '';
            }
            status.textContent = '';
            status.className = 'admin-form-status admin-modal__field--wide';
        }

        browserSelect.value = detectBrowser();
        browserSelect.addEventListener('change', updateOtherBrowser);
        updateOtherBrowser();
        if (modal) {
            modal.addEventListener('admin:modal-opened', prepareContext);
        }

        validateButton.addEventListener('click', function () {
            prepareContext();
            status.classList.remove('is-error', 'is-pending');

            if (!form.checkValidity()) {
                status.textContent = 'Revisa los campos obligatorios antes de continuar.';
                status.classList.add('is-error');
                form.reportValidity();
                return;
            }

            const datos = {};
            new FormData(form).forEach(function (valor, clave) {
                datos[clave] = valor;
            });

            validateButton.disabled = true;
            status.textContent = 'Enviando el reporte…';
            status.classList.add('is-pending');

            fetch('/admin/api/reportes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(datos)
            })
                .then(function (response) { return response.json(); })
                .then(function (resultado) {
                    validateButton.disabled = false;
                    status.classList.remove('is-pending');

                    if (!resultado || !resultado.ok) {
                        status.textContent = (resultado && resultado.msg) || 'No se pudo enviar el reporte.';
                        status.classList.add('is-error');
                        return;
                    }

                    // El folio se consulta en Configuración › Reportes del
                    // sistema, que es donde se le da seguimiento.
                    status.textContent = 'Reporte enviado. Puedes seguirlo en Configuración › Reportes del sistema.';
                    form.reset();
                })
                .catch(function () {
                    validateButton.disabled = false;
                    status.classList.remove('is-pending');
                    status.textContent = 'Error de conexión. Intenta de nuevo.';
                    status.classList.add('is-error');
                });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            validateButton.click();
        });
    }

    function initAdminSkipLink() {
        var skipLink = document.querySelector('.admin-body .skip-link');
        if (!skipLink) {
            return;
        }

        function focusTarget(event) {
            var targetId = skipLink.getAttribute('href');
            var target = targetId ? document.querySelector(targetId) : null;
            if (!target) {
                return;
            }
            if (event) {
                event.preventDefault();
            }
            target.focus({ preventScroll: true });
            target.scrollIntoView({
                behavior: window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
            });
        }

        skipLink.addEventListener('click', focusTarget);
        skipLink.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                focusTarget(event);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAdminSkipLink();
        initAdminSidebar();
        initPasswordToggles();
        initPasswordStrengthValidation();
        initDeleteConfirmations();
        initUserDeleteModal();
        initAdminModals();
        initProblemReport();
    });
})();
