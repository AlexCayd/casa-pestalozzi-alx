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
// El mismo resumen que la API y que la lista del cajón (cuenta tarjetas, no
// filas): el número del botón no puede contradecir a la pestaña que abre.
// _buzon.php, que se incluye después, lee estas mismas variables.
$buzonResumen = ['cantidad' => 0, 'cantidad_accionable' => 0, 'cantidad_seguimiento' => 0, 'prioridad_maxima_accionable' => null];
if (($contextoActivo ?? 'restaurante') === 'restaurante' && empty($modoMultinegocio)) {
    try {
        $buzonResumen = \Services\Reservations\ReservacionBuzonBandejaService::resumen();
    } catch (Throwable $e) {
        error_log('Buzón administrativo no disponible: ' . $e->getMessage());
    }
}
$buzonCantidadAccionable = (int)($buzonResumen['cantidad_accionable'] ?? 0);
$buzonCantidadSeguimiento = (int)($buzonResumen['cantidad_seguimiento'] ?? 0);
$buzonCantidadTotal = $buzonCantidadAccionable + $buzonCantidadSeguimiento;
$buzonPrioridadAccionable = (string)($buzonResumen['prioridad_maxima_accionable'] ?? '');

// Estado del botón. `has-action` es el que lo pinta de rojo: hay al menos una
// tarjeta en «Por atender», que es lo que cuenta el contador. Sólo «en espera»
// lo deja en papel con un punto (`has-followup`). Mismas reglas que
// updateCount() en src/js/admin/buzon.js.
$buzonClasesBoton = ['admin-topbar__inbox', $buzonCantidadTotal > 0 ? 'has-items' : 'is-empty'];
if ($buzonCantidadAccionable > 0) {
    $buzonClasesBoton[] = 'has-action';
}
if ($buzonCantidadSeguimiento > 0 && $buzonCantidadAccionable === 0) {
    $buzonClasesBoton[] = 'has-followup';
}
if ($buzonPrioridadAccionable === 'alta') {
    $buzonClasesBoton[] = 'has-high-priority';
}
// La cifra tiene que llegar también al lector de pantalla: aria-label pisa el
// contenido del botón, así que el «1» del contador no se anunciaba.
$buzonEtiqueta = 'Notificaciones';
if ($buzonCantidadAccionable > 0) {
    $buzonEtiqueta .= ': ' . $buzonCantidadAccionable . ' por atender';
} elseif ($buzonCantidadSeguimiento > 0) {
    $buzonEtiqueta .= ': ' . $buzonCantidadSeguimiento . ' en espera';
} else {
    $buzonEtiqueta .= ': todo al día';
}
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
            <span class="admin-context-name"><?php echo \Services\Security\Contextos::NOMBRES[$contextoActivo]; ?></span>
        </p>
    </div>

    <div class="admin-topbar__actions">
        <?php if ($contextoActivo === 'restaurante' && empty($modoMultinegocio)): ?>
        <button
            class="<?php echo htmlspecialchars(implode(' ', $buzonClasesBoton), ENT_QUOTES, 'UTF-8'); ?>"
            type="button"
            aria-label="<?php echo htmlspecialchars($buzonEtiqueta, ENT_QUOTES, 'UTF-8'); ?>"
            title="Notificaciones"
            aria-controls="admin-inbox-drawer"
            aria-expanded="false"
            data-inbox-open
        >
            <?php /* La campana y su badajo van en grupos separados: el cuerpo se
                     mece desde el aro y el badajo lo sigue con retraso, que es lo
                     que hace que el gesto se lea como campana y no como icono
                     que tiembla (ver _buzon.scss). */ ?>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <g class="admin-topbar__campana">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                </g>
                <g class="admin-topbar__badajo">
                    <path d="M10 21h4"/>
                </g>
            </svg>
            <span class="admin-topbar__inbox-badge" data-inbox-count<?php echo $buzonCantidadAccionable > 0 ? '' : ' hidden'; ?>><?php echo $buzonCantidadAccionable; ?></span>
        </button>

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

        <?php endif; ?>
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
                <?php /* La flecha va en su propio grupo para poder salir por la
                         puerta al apuntar sin mover el marco (_topbar.scss). */ ?>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <g class="admin-topbar__salida">
                        <path d="m16 17 5-5-5-5"/>
                        <path d="M21 12H9"/>
                    </g>
                </svg>
            </button>
        </form>
    </div>
</header>
