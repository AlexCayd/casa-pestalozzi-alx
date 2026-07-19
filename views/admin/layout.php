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

            // Tema (oscuro por defecto) aplicado antes de pintar para evitar FOUC.
            var theme = 'dark';
            try {
                if (window.localStorage.getItem('cp-admin-theme') === 'light') {
                    theme = 'light';
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
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=Space+Grotesk:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="/build/css/admin.css">
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/@studio-freight/lenis@1.0.42/dist/lenis.min.js" defer></script>
    <script src="/build/js/admin.js" defer></script>
    <?php foreach ($scripts ?? [] as $script): ?>
        <script src="<?php echo htmlspecialchars($script, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
