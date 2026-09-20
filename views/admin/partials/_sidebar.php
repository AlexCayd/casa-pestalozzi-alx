<?php
/**
 * Navegacion lateral compartida por los modulos de administracion.
 * Renderiza las rutas disponibles y senala el modulo activo.
 */
// El catálogo de iconos vive en _icons.php: lo comparten el sidebar, el topbar
// y las vistas de módulo. Aquí tenía su propia copia, y era la razón de que
// existieran dos juegos del mismo dibujo en el proyecto.
require_once __DIR__ . '/_icons.php';

// 'productos' era un alias local de 'products'. Se resuelve aquí y no en el
// catálogo: es una clave de menú de este sidebar, no un icono distinto.
$sidebarIconoModulo = static function (string $clave): string {
    if ($clave === 'productos') {
        $clave = 'products';
    }
    $icono = admin_icon($clave, 24);

    // El respaldo lo pone el sidebar y no el helper: una entrada de navegación
    // sin icono descuadra la fila entera, mientras que un botón suelto sin él
    // simplemente se queda sin adorno.
    return $icono !== '' ? $icono : admin_icon('analytics', 24);
};
?>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Navegación de administración" data-admin-sidebar data-lenis-prevent>
    <div class="admin-sidebar__header">
        <a class="admin-sidebar__brand" href="/admin/analytics" title="Casa Pestalozzi">
            <span class="admin-sidebar__brand-mark" aria-hidden="true">CP</span>
            <?php /* ═══ COMPONENTE REUTILIZABLE → views/templates/header-casa-pestalozzi.php
                     Sin enlace: ya vamos dentro del <a> de la marca, y un <a>
                     dentro de otro es marcado inválido. En dos líneas, que es
                     como se veía: antes rompía contra un ancho máximo y ahora
                     lo pide el parcial. */ ?>
            <?php
            $hcpEtiqueta = 'span';
            $hcpNivel = 'span';
            $hcpHref = '';
            $hcpClase = 'admin-sidebar__brand-text';
            $hcpDosLineas = true;
            include __DIR__ . '/../../templates/header-casa-pestalozzi.php';
            ?>
        </a>

        <?php /* SVG y no la "x" literal que había: una equis de texto hereda la
                 caja tipográfica —se apoya en la línea base y queda descentrada
                 en el botón— y cambia de forma con la fuente. */ ?>
        <button
            class="admin-sidebar__close"
            type="button"
            aria-label="Cerrar navegación"
            data-admin-sidebar-close><?php echo admin_icon('cerrar', 18); ?></button>
    </div>

    <nav class="admin-sidebar__nav">
        <?php foreach ($modules as $moduleKey => $module): ?>
            <a
                class="admin-sidebar__link <?php echo $activeModule === $moduleKey ? 'is-active' : ''; ?>"
                href="<?php echo $module['path']; ?>"
                title="<?php echo htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8'); ?>">
                <span class="admin-sidebar__link-mark" aria-hidden="true">
                    <?php echo $sidebarIconoModulo((string) $moduleKey); ?>
                </span>
                <span class="admin-sidebar__link-text">
                    <?php echo $module['title']; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
