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

        document.querySelectorAll('[data-user-delete]').forEach(function (button) {
            button.addEventListener('click', function () {
                openModal(button);
            });
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

    document.addEventListener('DOMContentLoaded', function () {
        initAdminSidebar();
        initPasswordToggles();
        initPasswordStrengthValidation();
        initDeleteConfirmations();
        initUserDeleteModal();
    });
})();
