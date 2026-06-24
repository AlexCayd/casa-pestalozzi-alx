<?php
/**
 * Navegación lateral compartida por los módulos de administración.
 * Renderiza las rutas disponibles y señala el módulo activo.
 */
?>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Navegación de administración" data-admin-sidebar>
    <div class="admin-sidebar__header">
        <a class="admin-sidebar__brand" href="/admin/analytics">
            <span>
                CASA PESTOLOZZI
            </span>
        </a>

        <button
            class="admin-sidebar__close"
            type="button"
            aria-label="Cerrar navegación"
            data-admin-sidebar-close>×</button>
    </div>

    <nav class="admin-sidebar__nav">
        <?php foreach ($modules as $moduleKey => $module): ?>
            <a
                class="admin-sidebar__link <?php echo $activeModule === $moduleKey ? 'is-active' : ''; ?>"
                href="<?php echo $module['path']; ?>">
                <?php echo $module['title']; ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
