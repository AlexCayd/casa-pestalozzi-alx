<?php
/**
 * Layout general del panel de administración.
 * Compone sidebar, topbar, contenido y recursos específicos de cada módulo.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Casa Pestalozzi Admin - <?php echo $title ?? 'Panel'; ?></title>
    <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
    <link rel="apple-touch-icon" href="/build/images/logo.svg">
    <script>
        (function () {
            var root = document.documentElement;

            root.classList.add('admin-sidebar-preload');

            try {
                if (window.localStorage.getItem('cp-admin-sidebar-collapsed') === '1') {
                    root.classList.add('admin-sidebar-collapsed');
                }
            } catch (error) {
                // localStorage puede no estar disponible en contextos restringidos.
            }

            // Tema aplicado antes de pintar para evitar FOUC. El CLARO es el
            // valor por defecto del panel; el oscuro se queda para las pantallas
            // de piso, que son turnos completos en tablet.
            //
            // Sin nada guardado se consulta la preferencia del sistema, que
            // antes no se miraba nunca: quien tiene el equipo en oscuro entra en
            // oscuro la primera vez, y a partir de ahí manda su elección.
            var theme = 'light';
            try {
                var guardado = window.localStorage.getItem('cp-admin-theme');
                if (guardado === 'dark' || guardado === 'light') {
                    theme = guardado;
                } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    theme = 'dark';
                }
            } catch (error) {}
            root.setAttribute('data-admin-theme', theme);

            // Habilita la capa de animación salvo con reduced-motion.
            try {
                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    root.classList.add('admin-anim-ready');
                }
            } catch (error) {}
        })();
    </script>
    <?php /*
        Geist y Geist Mono son locales (gulp copyVendorFonts). Antes llegaban por
        Google Fonts en una petición bloqueante; se precargan las dos redondas,
        que son las que se ven en la primera pintura — las itálicas no.
    */ ?>
    <link rel="preload" href="/build/fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/build/css/admin.css?v=pulido-v2">
    <?php foreach ($styles ?? [] as $stylesheet): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($stylesheet, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
</head>
<body class="admin-body">
    <div class="admin-shell">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <button
            class="admin-sidebar-backdrop"
            type="button"
            aria-label="Cerrar navegación"
            data-admin-sidebar-backdrop
        ></button>

        <div class="admin-main">
            <?php include_once __DIR__ . '/partials/_topbar.php'; ?>

            <main class="admin-content">
                <?php echo $content; ?>
            </main>
        </div>
    </div>

    <?php include_once __DIR__ . '/partials/_buzon.php'; ?>
    <?php include_once __DIR__ . '/partials/_problem-report-modal.php'; ?>

    <?php /*
        Vendorizados en /build/js/vendor por `gulp copyVendorJs`, igual que en la
        landing. Venían por CDN en tres peticiones bloqueantes, y además el
        Lenis del CDN estaba fijado en 1.0.42 —paquete @studio-freight,
        deprecado— mientras package.json ya traía el 1.3.26 que usa la landing:
        las dos mitades del proyecto corrían versiones distintas.

        El orden no es opcional: ScrollTrigger se registra sobre el gsap global.
    */ ?>
    <script src="/build/js/vendor/gsap.min.js" defer></script>
    <script src="/build/js/vendor/ScrollTrigger.min.js" defer></script>
    <script src="/build/js/vendor/lenis.min.js" defer></script>
    <script src="/build/js/admin.js?v=pulido-v2" defer></script>
    <?php foreach ($scripts ?? [] as $script): ?>
        <script src="<?php echo htmlspecialchars($script, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
