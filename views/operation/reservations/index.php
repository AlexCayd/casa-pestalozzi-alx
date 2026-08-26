<?php

use Services\ReservacionErrorCatalog;
/**
 * Herramienta diaria de reservaciones; el shell vive en views/operation/layout.php.
 */

$filtros = is_array($filtros ?? null) ? $filtros : [];
$estadoLabels = is_array($estadoLabels ?? null) ? $estadoLabels : [];
$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$returnUrl = (string)($returnUrl ?? '');
$fechaMinima = (string)($fechaMinima ?? \Services\ReservacionConfig::fechaActual());
$fechaInicial = (string)($filtros['fecha'] ?? $fechaMinima);
$modoSoloLectura = (bool)($modoSoloLectura ?? false);
$operacionEditable = (bool)($operacionEditable ?? !$modoSoloLectura);
$fechaInvalidaRecibida = (string)($fechaInvalidaRecibida ?? '');
$horaInicial = \Services\HorarioReservacionService::normalizarHoraCorta((string)($filtros['hora'] ?? ''));
$horaSolicitadaInicial = \Services\HorarioReservacionService::normalizarHoraCorta(
    (string)($horaSolicitadaInicial ?? $horaInicial)
);
$initialReservacionId = (int)($initialReservacionId ?? 0);
$initialOperationIntent = (string)($initialOperationIntent ?? '');
$initialOperationNotice = is_array($initialOperationNotice ?? null) ? $initialOperationNotice : null;
$comentarioAdminDisponible = (bool)($comentarioAdminDisponible ?? false);
$puedeCrearAdministrativa = (bool)($puedeCrearAdministrativa ?? false);
$puedeCrearDesdeMapa = (bool)($puedeCrearDesdeMapa ?? $puedeCrearAdministrativa);
$puedeCapturarContacto = (bool)($puedeCapturarContacto ?? $puedeCrearAdministrativa);
$createReservationAction = (string)($createReservationAction ?? '/admin/reservaciones/crear');
$availabilityEndpoint = (string)($availabilityEndpoint ?? '/admin/api/reservaciones/disponibilidad');
$superficieOperativa = (string)($superficieOperativa ?? ($puedeCrearAdministrativa ? 'admin' : 'waiter'));

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$alertasNormalizadas = [];
$agregarAlerta = static function ($tipo, $mensajes) use (&$alertasNormalizadas, &$agregarAlerta): void {
    if ($mensajes === null || $mensajes === '') {
        return;
    }

    if (is_array($mensajes)) {
        foreach ($mensajes as $mensaje) {
            $agregarAlerta($tipo, $mensaje);
        }
        return;
    }

    $tipo = is_string($tipo) ? $tipo : 'error';
    $alertasNormalizadas[$tipo][] = (string)$mensajes;
};

foreach ($alertas as $tipo => $mensajes) {
    $agregarAlerta($tipo, $mensajes);
}

$operationalGlobalNotice = ['hidden' => true];
if ($initialOperationNotice !== null) {
    $operationalGlobalNotice = $initialOperationNotice;
} elseif ($alertasNormalizadas !== []) {
    $tipoInicial = (string)array_key_first($alertasNormalizadas);
    $mensajesIniciales = $alertasNormalizadas[$tipoInicial] ?? [];
    $tipoAvisoInicial = $tipoInicial === 'exito'
        ? 'success'
        : ($tipoInicial === 'warning' ? 'warning' : 'error');
    $operationalGlobalNotice = [
        'type' => $tipoAvisoInicial,
        'hidden' => false,
    ];
    $operationalGlobalNotice['title'] = match ($tipoAvisoInicial) {
        'success' => 'Cambios guardados',
        'warning' => 'Aviso de operación',
        default => 'No se pudo completar la operación',
    };
    $operationalGlobalNotice['summary'] = (string)($mensajesIniciales[0] ?? 'Consulta el estado de la operación.');
    $noticeCode = $tipoAvisoInicial === 'success'
        ? 'ACTUALIZADA'
        : ($tipoAvisoInicial === 'warning' ? 'DATOS_INVALIDOS' : 'ERROR_INTERNO');
    $notice = ReservacionErrorCatalog::presentar($noticeCode);
    $operationalGlobalNotice['mensaje'] = $notice['consecuencia'];
} elseif ($modoSoloLectura) {
    $notice = ReservacionErrorCatalog::presentar('FECHA_PASADA_SOLO_LECTURA');
    $operationalGlobalNotice = [
        'type' => 'info',
        'codigo' => 'FECHA_PASADA_SOLO_LECTURA',
        'title' => $notice['titulo'],
        'summary' => $notice['mensaje'],
        'mensaje' => $notice['consecuencia'],
        'hidden' => false,
    ];
}
?>

<section
    class="reservation-operation admin-reservation-operation operational-module"
    aria-label="Operacion de reservaciones"
    data-page="reservation-operation"
    data-initial-fecha="<?php echo $h($fechaInicial); ?>"
    data-min-fecha="<?php echo $h($fechaMinima); ?>"
    data-initial-date-warning="<?php echo $fechaInvalidaRecibida !== '' ? '1' : '0'; ?>"
    data-operation-mode="<?php echo $modoSoloLectura ? 'solo_lectura' : 'operacion'; ?>"
    data-operation-editable="<?php echo $operacionEditable ? '1' : '0'; ?>"
    data-initial-hora="<?php echo $h($horaInicial); ?>"
    data-initial-requested-hour="<?php echo $h($horaSolicitadaInicial); ?>"
    data-initial-reservation-id="<?php echo $initialReservacionId; ?>"
    data-initial-operation-intent="<?php echo $h($initialOperationIntent); ?>"
    data-operation-surface="<?php echo $h($superficieOperativa); ?>"
    data-return-url="<?php echo $h($returnUrl); ?>"
    data-comment-enabled="<?php echo $comentarioAdminDisponible ? '1' : '0'; ?>"
    data-admin-csrf="<?php echo $h($adminCsrfToken ?? ''); ?>"
>
    <?php
    $operationalContextCapacityHtml =
        '<div class="reservation-operation-capacity" data-operation-capacity role="status" aria-live="polite" aria-label="0 de 0 lugares disponibles" title="0 de 0 lugares disponibles" hidden>' .
            '<strong class="reservation-operation-capacity__primary" aria-hidden="true"><span class="reservation-operation-capacity__real" data-operation-capacity-real>0</span><span class="reservation-operation-capacity__separator"> / </span><span class="reservation-operation-capacity__of" data-operation-capacity-of>0</span></strong>' .
        '</div>';

    ob_start();
    ?>
        <div class="reservation-operation__toolbar-left" data-operation-toolbar-left>
            <button
                type="button"
                class="admin-btn admin-btn--secondary operational-tables-trigger"
                aria-label="Ver lista de mesas"
                title="Ver lista de mesas"
                aria-expanded="false"
                aria-controls="operation-tables-modal"
                data-operation-tables-open
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <rect x="5" y="5" width="14" height="10" rx="2"></rect>
                    <path d="M8 15v4M16 15v4M5 19h14"></path>
                </svg>
                <span>Mesas</span>
            </button>
            <?php echo $operationalContextCapacityHtml; ?>
        </div>
        <div class="reservation-operation__toolbar-center" data-operation-toolbar-center>
            <?php
            $operationalFilterScope = 'context';
            include __DIR__ . '/_filters.php';
            ?>
        </div>
    <?php
    $operationalContextControlsHtml = (string)ob_get_clean();

    ob_start();
    if ($puedeCrearDesdeMapa):
    ?>
        <button
            class="admin-btn admin-btn--gold"
            type="button"
            data-operation-create
            data-create-date="<?php echo $h($fechaInicial); ?>"
            <?php echo !$operacionEditable ? 'hidden disabled aria-disabled="true"' : ''; ?>
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                <path d="M8 3v4M16 3v4M3 10h18M12 13v6M9 16h6"></path>
            </svg>
            <span>Crear reservación</span>
        </button>
    <?php
    endif;
    $operationalContextActionsHtml = (string)ob_get_clean();
    $operationalContextView = 'reservations';
    $operationalContextSelectionHtml = '';
    $operationalContextIncludeDrawerToggle = false;
    ?>

    <?php
    $operationalDrawerId = 'operation-reservations-drawer';
    $operationalDrawerTitleId = 'operation-reservations-title';
    $operationalDrawerClass = 'reservation-operation__reservations';
    $operationalDrawerAttributes = ['data-operation-results' => true];
    $operationalDrawerDateHtml = '';
    $operationalDrawerCountHtml = '<span class="mapa-reserva-count" data-operation-count>0</span>';
    $operationalDrawerSlotHtml = '';
    ob_start();
    $operationalFilterScope = 'drawer';
    include __DIR__ . '/_filters.php';
    $operationalDrawerFilterHtml = (string)ob_get_clean();
    $operationalDrawerListAttributes = ['data-operation-reservations' => true];
    $operationalDrawerListHtml = '<div class="reservation-operation-skeleton"><span></span><span></span><span></span></div>';
    $operationalActiveModule = 'reservations';
    $operationalMapHref = '/punto-de-venta';
    $operationalReservationsHref = '/admin/reservaciones/operacion';
    include __DIR__ . '/../partials/drawer.php';
    ?>

    <div class="reservation-operation__workspace operational-workspace" data-operation-mobile-view="tables">
        <?php include __DIR__ . '/../partials/context-bar.php'; ?>
        <div class="operational-content reservation-operation__content">
            <?php
                    $mapVisual = [
                        'context' => 'operacion-reservaciones',
                        'sectionClass' => 'reservation-operation__map',
                        'title' => '',
                        'subtitle' => '',
                        'ariaLabel' => 'Mapa de reservaciones',
                        'canvasMode' => 'operation',
                'loadingMode' => 'empty',
                'legendPosition' => 'footer',
                // La lista operativa vive en el modal de mesas; el canvas no
                // reserva altura para una segunda superficie de consulta.
                'structuredList' => false,
            ];
            include __DIR__ . '/../partials/map.php';
            ?>

        <aside class="reservation-operation__panel" data-operation-panel-shell aria-labelledby="operation-detail-title" aria-hidden="false">
                <div class="reservation-operation__panel-toolbar reservation-operation__header">
                    <span id="operation-detail-title">Detalle operativo</span>
                    <button type="button" class="operational-icon-button reservation-operation__panel-close" aria-label="Cerrar detalle" title="Cerrar detalle" data-operation-panel-close>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <div class="reservation-operation__panel-content reservation-operation__body" data-operation-panel>
                    <article class="reservation-operation-panel admin-card" role="status" aria-live="polite"></article>
                </div>
        </aside>
        </div>
    </div>

    <dialog
        class="operation-tables-modal"
        id="operation-tables-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="operation-tables-modal-title"
        tabindex="-1"
        data-operation-tables-modal
    >
        <div class="operation-tables-modal__surface">
            <header class="operation-tables-modal__head">
                <div>
                    <span class="operation-tables-modal__eyebrow">Mapa operativo</span>
                    <h2 id="operation-tables-modal-title">Estado de mesas</h2>
                    <p data-operation-tables-meta>Consulta actual</p>
                </div>
                <button type="button" class="operational-icon-button operation-tables-modal__close" aria-label="Cerrar estado de mesas" title="Cerrar estado de mesas" data-operation-tables-close>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"></path></svg>
                </button>
            </header>
            <div class="operation-tables-modal__body">
                <div class="operation-tables-modal__grid" data-operation-tables-grid role="list" aria-label="Lista de mesas"></div>
            </div>
        </div>
    </dialog>

        <section
            class="reservation-operation-assignment-bar assignment-toolbar"
            id="operation-assignment-bar"
            aria-labelledby="operation-assignment-title"
            aria-hidden="true"
            data-operation-assignment-bar
            hidden
        >
            <div class="reservation-operation-assignment-bar__identity">
                <span id="operation-assignment-title" tabindex="-1" data-operation-assignment-title>ASIGNACIÓN DE MESAS</span>
                <strong data-operation-assignment-reservation>Sin reservación</strong>
            </div>
            <div class="reservation-operation-assignment-bar__summary" aria-live="polite">
                <span><small>Personas</small><strong data-operation-assignment-people>0</strong></span>
                <span><small>Capacidad</small><strong data-operation-assignment-capacity>0</strong></span>
                <span><small>Diferencia</small><strong data-operation-assignment-difference>0</strong></span>
                <span class="reservation-operation-assignment-bar__tables"><small>Mesas</small><strong data-operation-assignment-tables>Sin mesas seleccionadas</strong></span>
            </div>
            <p class="reservation-operation-assignment-bar__refresh" data-operation-assignment-refresh role="status" aria-live="polite" hidden>Los datos se actualizaron. Tu selección local se conserva; vuelve a validar antes de guardar.</p>
            <div class="reservation-operation-assignment-bar__actions">
                <button type="button" class="admin-btn admin-btn--secondary" data-operation-assignment-cancel>Cancelar</button>
                <button type="button" class="admin-btn admin-btn--primary" data-operation-save data-operation-assignment-save data-disabled="1" disabled>Guardar asignación</button>
            </div>
        </section>

    <?php if ($puedeCrearDesdeMapa): ?>
    <?php
    $modalReservacion = new \Model\Reservacion();
    $modalReservacion->fecha = $fechaInicial;
    $modalReservacion->hora = '';
    $modalReservacion->comensales = 2;
    $modalReservacion->estado = 'confirmada';
    $modalReservacion->request_token = \Services\ReservacionService::generarRequestToken();
    $modalFormModo = 'crear';
    $reservacion = $modalReservacion;
    $errores = [];
    $editable = true;
    $fechaActual = $fechaMinima;
    $diasActivos = range(0, 6);
    $maxComensalesAdmin = \Services\ReservacionConfig::MAX_COMENSALES_ADMIN;
    $asignarAutomaticamente = true;
    $returnUrl = '/admin/reservaciones/operacion?fecha=' . rawurlencode($fechaInicial);
    $formTransport = 'json';
    $formActionsExternal = true;
    $formAction = $createReservationAction;
    $mostrarCamposContacto = $puedeCapturarContacto;
    $disponibilidadEndpoint = $availabilityEndpoint;
    ob_start();
    $modo = $modalFormModo;
    include __DIR__ . '/../../admin/reservations/_form.php';
    $modalFormHtml = (string)ob_get_clean();
    ?>
    <dialog class="operation-create-modal" aria-labelledby="operation-create-modal-title" data-operation-create-modal>
        <div class="operation-create-modal__head">
            <div>
                <span class="operation-create-modal__eyebrow">Reservaciones</span>
                <h2 id="operation-create-modal-title">Crear reservación</h2>
            </div>
            <button type="button" class="operational-icon-button operation-create-modal__close" aria-label="Cerrar crear reservación" data-operation-create-close>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <div class="operation-create-modal__body" data-operation-create-body>
            <div class="operation-create-modal__error" role="alert" data-operation-create-error hidden></div>
            <?php echo $modalFormHtml; ?>
        </div>
        <div class="operation-create-modal__footer" data-operation-create-footer>
            <button type="button" class="admin-btn admin-btn--secondary" data-operation-create-cancel>Cancelar</button>
            <button type="submit" class="admin-btn admin-btn--primary" data-form-save form="crear-reservation-admin-form">Crear reservación</button>
        </div>
    </dialog>
    <?php endif; ?>

    <div data-operation-confirmation-host></div>

</section>
