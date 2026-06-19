<?php
/**
 * Barra superior compartida del panel de administración.
 * Muestra contexto de navegación, usuario y acceso al menú responsive.
 */
?>
<header class="admin-topbar">
    <button
        class="admin-menu-toggle"
        type="button"
        aria-label="Abrir navegación"
        aria-controls="admin-sidebar"
        aria-expanded="false"
        data-admin-sidebar-toggle
    >
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div class="admin-topbar__heading">
        <p class="admin-topbar__eyebrow <?php echo !empty($topbarSection) ? 'admin-topbar__eyebrow--breadcrumb' : ''; ?>">
            Panel de administración
            <?php if (!empty($topbarSection)): ?>
                <span aria-hidden="true">/</span> <?php echo htmlspecialchars($topbarSection, ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </p>
        <?php if (empty($compactTopbar)): ?>
            <h1><?php echo $title ?? 'Panel'; ?></h1>
        <?php endif; ?>
    </div>

    <div class="admin-topbar__user" aria-label="Usuario actual">
        <span>Administrador</span>
        <strong>AD</strong>
    </div>
</header>
