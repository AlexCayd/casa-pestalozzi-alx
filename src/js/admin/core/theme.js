/**
 * Conmutador de tema (claro/oscuro) del panel de administración.
 * Persiste la preferencia en localStorage y sincroniza el atributo
 * data-admin-theme en <html>. Emite el evento `admin:themechange` para que
 * otros módulos (p. ej. las gráficas de analytics) reaccionen.
 */
(function () {
    var STORAGE_KEY = 'cp-admin-theme';
    var root = document.documentElement;

    function currentTheme() {
        return root.getAttribute('data-admin-theme') === 'dark' ? 'dark' : 'light';
    }

    function persist(theme) {
        try {
            window.localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
            // localStorage puede no estar disponible en contextos restringidos.
        }
    }

    function updateToggle(button, theme) {
        var isDark = theme === 'dark';
        var label = isDark ? 'Activar tema claro' : 'Activar tema oscuro';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('aria-pressed', String(isDark));
    }

    function applyTheme(theme) {
        root.setAttribute('data-admin-theme', theme);
        persist(theme);
        document.querySelectorAll('[data-admin-theme-toggle]').forEach(function (button) {
            updateToggle(button, theme);
        });
        window.dispatchEvent(new CustomEvent('admin:themechange', { detail: { theme: theme } }));
    }

    function initThemeToggle() {
        var toggles = document.querySelectorAll('[data-admin-theme-toggle]');

        if (!toggles.length) {
            return;
        }

        toggles.forEach(function (button) {
            updateToggle(button, currentTheme());
            button.addEventListener('click', function () {
                applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
            });
        });
    }

    // Sincroniza entre pestañas abiertas del panel.
    window.addEventListener('storage', function (event) {
        if (event.key === STORAGE_KEY && event.newValue) {
            var theme = event.newValue === 'dark' ? 'dark' : 'light';
            if (theme !== currentTheme()) {
                root.setAttribute('data-admin-theme', theme);
                document.querySelectorAll('[data-admin-theme-toggle]').forEach(function (button) {
                    updateToggle(button, theme);
                });
                window.dispatchEvent(new CustomEvent('admin:themechange', { detail: { theme: theme } }));
            }
        }
    });

    document.addEventListener('DOMContentLoaded', initThemeToggle);
})();
