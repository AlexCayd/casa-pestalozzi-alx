<?php
/**
 * Layout general del panel de administración.
 * Compone sidebar, topbar, contenido y recursos específicos de cada módulo.
 */

// Primera página tras iniciar sesión: lleva la llegada (views/components/
// llegada.php). Se consume aquí arriba y no junto al marcado porque el <head>
// también la necesita, para precargar la cara del nombre.
$llegadaPendiente = \Classes\Auth::consumirLlegada();
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

            // Transición entre módulos (_finishes.scss, bloque 4c). Tiene que
            // vivir aquí: `pagereveal` se dispara antes de la primera pintura y
            // sólo lo oye un script síncrono del <head>. Pone una CLASE y no el
            // nombre porque el contenido aún no está parseado; la clase basta
            // para que el CSS nombre el contenido y el enlace activo sólo
            // mientras dura, y se retira al terminar (un nombre fijo le daría
            // grupo propio en la transición del tema y taparía su círculo).
            // La pareja de salida, `pageswap`, está en core/motion.js.
            //
            // En la página de la llegada no se registra: ahí el contenido no
            // debe tener grupo propio, o se pintaría encima del velo.
            var hayLlegada = <?php echo $llegadaPendiente ? 'true' : 'false'; ?>;
            window.addEventListener('pagereveal', function (evento) {
                var transicion = evento.viewTransition;
                if (!transicion || hayLlegada) {
                    return;
                }
                var desde = '';
                try {
                    var activacion = window.navigation && window.navigation.activation;
                    desde = activacion && activacion.from && activacion.from.url
                        ? activacion.from.url
                        : document.referrer;
                    // Sin origen conocido no se adivina: `new URL('')` daría la
                    // página actual y parecería venir de un módulo.
                    if (!desde) {
                        return;
                    }
                    var url = new URL(desde, location.href);
                    // Sólo de un módulo a otro: desde el login entra la llegada.
                    if (url.origin !== location.origin
                        || !/^\/admin(\/|$)/.test(url.pathname)
                        || url.pathname === '/admin/login') {
                        return;
                    }
                } catch (error) {
                    return;
                }
                root.classList.add('admin-vt-entra');
                // Ésta se queda: motion.js la lee más tarde para no repetir
                // con sus reveals la entrada que la transición ya hizo.
                root.classList.add('admin-entro-por-modulo');
                try { transicion.types.add('modulo'); } catch (error) {}
                var limpiar = function () { root.classList.remove('admin-vt-entra'); };
                transicion.finished.then(limpiar, limpiar);
            });
        })();
    </script>
    <?php /*
        Geist y Geist Mono son locales (gulp copyVendorFonts). Antes llegaban por
        Google Fonts en una petición bloqueante; se precargan las dos redondas,
        que son las que se ven en la primera pintura — las itálicas no.
    */ ?>
    <link rel="preload" href="/build/fonts/satoshi-variable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/build/fonts/expose-variable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <?php /*
        El `?v=` lo pone recursoVersionado() con el mtime del archivo (includes/
        funciones.php). Antes el bundle compartido se versionaba a mano y los de
        módulo salían pelados, así que recompilar un SCSS de módulo no llegaba
        al navegador: seguía sirviendo el suyo de caché.
    */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(recursoVersionado('/build/css/admin.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <?php foreach ($styles ?? [] as $stylesheet): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(recursoVersionado($stylesheet), ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
    <?php if ($llegadaPendiente): ?>
        <?php /* El nombre es lo primero que se ve: viene en caché del login,
                 pero sin la precarga podía pintarse un instante en la sans. */ ?>
        <link rel="preload" href="/build/fonts/KudosKapsOneNF.otf" as="font" type="font/otf" crossorigin>
    <?php endif; ?>
    <?php /*
        Precarga de los módulos del sidebar al apuntarlos (200 ms) o al pulsar.
        PREFETCH y no prerender: prerenderizar EJECUTA la página —sus consultas,
        sus gráficas, el sondeo del buzón— para algo que quizá no se abra; la
        precarga sólo trae el HTML, que es lo que de verdad tarda. Sólo enlaces
        del sidebar —GET de lectura— y nunca el módulo en el que ya se está.
        El servidor la reconoce por `Sec-Purpose: prefetch` (Auth::consumirLlegada
        no gasta la llegada con ella).
    */ ?>
    <script type="speculationrules">
    {"prefetch": [{"where": {"and": [{"selector_matches": ".admin-sidebar__link"}, {"not": {"selector_matches": ".is-active"}}]}, "eagerness": "moderate"}]}
    </script>
</head>
<body class="admin-body">
    <?php if ($llegadaPendiente): ?>
        <?php
        // El sidebar lleva la marca en dos formas: el nombre apilado (abierto)
        // y el monograma (plegado). Va primero el que antecede en el DOM; el JS
        // usa el primero que esté a la vista. En móvil ninguno lo está.
        $llegadaDestino = '.admin-sidebar__brand-mark, .admin-sidebar__brand-text';
        $llegadaLugar = 'Panel de administración';
        include __DIR__ . '/../components/llegada.php';
        ?>
    <?php endif; ?>
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
    <script src="<?php echo htmlspecialchars(recursoVersionado('/build/js/admin.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php foreach ($scripts ?? [] as $script): ?>
        <script src="<?php echo htmlspecialchars(recursoVersionado($script), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
