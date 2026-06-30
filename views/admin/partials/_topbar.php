<?php
/**
 * Barra superior compartida del panel de administracion.
 * Muestra el control del sidebar, el nombre actual y el usuario.
 */
$currentModuleTitle = $topbarTitle
    ?? $title
    ?? ($modules[$activeModule]['title'] ?? 'Panel');
?>
<header class="admin-topbar">
    <button
        class="admin-menu-toggle"
        type="button"
        aria-label="Abrir navegacion"
        aria-controls="admin-sidebar"
        aria-expanded="false"
        data-admin-sidebar-toggle
    >
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div class="admin-topbar__heading">
        <p class="admin-topbar__module">
            <?php echo htmlspecialchars($currentModuleTitle, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    </div>

    <div class="admin-topbar__user" aria-label="Usuario actual">
        <span>Administrador</span>
        <strong>AD</strong>
    </div>
</header>
