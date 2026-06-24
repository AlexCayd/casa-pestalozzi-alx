/**
 * Punto de entrada del módulo de analytics del panel de administración.
 * Inicia la página cuando el DOM y sus dependencias están disponibles.
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        if (window.AdminAnalyticsPage) {
            window.AdminAnalyticsPage.init();
        }
    });
})();
