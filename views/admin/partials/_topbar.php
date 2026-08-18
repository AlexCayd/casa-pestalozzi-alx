<?php
/**
 * Barra superior compartida del panel de administracion.
 * Muestra el control del sidebar, el nombre actual y el usuario.
 */
$currentModuleTitle = $topbarTitle
    ?? $title
    ?? ($modules[$activeModule]['title'] ?? 'Panel');

// Usuario autenticado (sesión iniciada por el login con NIP)
$authNombre = trim((string) ($_SESSION['nombre'] ?? 'Administrador'));
$authPalabras = preg_split('/\s+/', $authNombre) ?: [];
$authIniciales = strtoupper(
    mb_substr($authPalabras[0] ?? 'A', 0, 1) . mb_substr($authPalabras[1] ?? '', 0, 1)
);
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
        <?php if (($pendingScheduleImpactReservations ?? 0) > 0) : ?>
            <div class="admin-topbar__pending-impact" role="status">
                <span>
                    Hay <?php echo (int)$pendingScheduleImpactReservations; ?>
                    <?php echo (int)$pendingScheduleImpactReservations === 1 ? 'reservación pendiente' : 'reservaciones pendientes'; ?>
                    por un cambio de horario.
                </span>
                <a href="/admin/configuracion/horarios<?php echo !empty($pendingScheduleImpactId) ? '?impacto_id=' . (int)$pendingScheduleImpactId : ''; ?>">
                    Resolver
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-topbar__actions">
        <button
            class="admin-topbar__support"
            type="button"
            data-admin-modal-open="admin-problem-modal"
            aria-haspopup="dialog"
            aria-controls="admin-problem-modal"
            aria-label="Reportar un problema"
            title="Reportar un problema"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                <path d="M12 9v4"/>
                <path d="M12 17h.01"/>
            </svg>
        </button>

        <button
            class="admin-theme-toggle"
            type="button"
            aria-label="Cambiar tema"
            aria-pressed="false"
            data-admin-theme-toggle
            data-admin-magnetic
        >
            <svg class="admin-theme-toggle__moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" />
            </svg>
            <svg class="admin-theme-toggle__sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="4" />
                <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
            </svg>
        </button>

        <div class="admin-topbar__user" aria-label="Usuario actual">
            <span><?php echo htmlspecialchars($authNombre, ENT_QUOTES, 'UTF-8'); ?></span>
            <strong><?php echo htmlspecialchars($authIniciales, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>

        <form method="POST" action="/logout" class="admin-topbar__logout-form">
            <button class="admin-topbar__logout" type="submit" title="Cerrar sesión" aria-label="Cerrar sesión">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <path d="m16 17 5-5-5-5"/>
                    <path d="M21 12H9"/>
                </svg>
            </button>
        </form>
    </div>
</header>
