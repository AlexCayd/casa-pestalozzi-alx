<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function assertUxRefinement(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    assertUxRefinement(is_string($contents), "se pudo leer {$path}");
    return $contents;
};

$publicView = $read('views/reservaciones/cambio-horario.php');
$publicScript = $read('src/js/modules/schedule-change-access.js');
assertUxRefinement(substr_count($publicView, '$inline = false;') === 2, 'cambio de horario usa pickers compactos');
assertUxRefinement(!str_contains($publicView, '$inline = true;'), 'cambio de horario no deja pickers inline');
assertUxRefinement(substr_count($publicScript, 'inline: false') === 2, 'JS público inicializa ambos pickers como popover');
assertUxRefinement(str_contains($publicView, 'A nombre de'), 'el titular tiene jerarquía visible');
assertUxRefinement(str_contains($publicView, 'Selecciona una fecha para ver horarios.'), 'hora tiene un estado inicial único');
assertUxRefinement(str_contains($publicScript, "setTimeHint('Consultando horarios…')"), 'hora comunica la consulta en curso');
assertUxRefinement(str_contains($publicScript, "setTimeHint('Elige una hora disponible.')"), 'hora comunica disponibilidad');
assertUxRefinement(str_contains($publicScript, "setTimeHint('No hay horarios disponibles para esa fecha.')"), 'hora comunica ausencia de horarios');
assertUxRefinement(str_contains($publicView, 'data-max-guests'), 'cambio de horario expone el máximo de personas desde PHP');
assertUxRefinement(str_contains($publicView, 'schedule-change-context') && str_contains($publicView, 'schedule-change-editor'), 'cambio de horario separa contexto y edición');
assertUxRefinement(str_contains($publicView, 'schedule-change-logo__mark') && !str_contains($publicView, '<img src="/build/images/logo.svg"'), 'logo público usa la marca enmascarada');
assertUxRefinement(str_contains($publicView, 'rows="2"'), 'indicaciones usa una altura compacta');
assertUxRefinement(str_contains($publicScript, 'var pillMaxGuests = Math.min(6, maxGuests)'), 'pills de personas respetan el máximo del formulario');
assertUxRefinement(str_contains($publicScript, 'guestPills.hidden = value > pillMaxGuests'), 'pills y stepper no se muestran simultáneamente');
assertUxRefinement(str_contains($publicScript, "card.replaceChildren(success)"), 'éxito reemplaza el contenido completo de la tarjeta');
assertUxRefinement(str_contains($publicScript, 'Nuevo horario confirmado') && str_contains($publicScript, 'Tu reservación está lista'), 'éxito usa la jerarquía final solicitada');
assertUxRefinement(str_contains($publicScript, 'schedule-change-success__mark') && str_contains($publicScript, 'Te esperamos en Casa Pestalozzi.'), 'éxito conserva marca visual y copy de confirmación');

$scheduleStyle = $read('src/scss/components/_schedule-change-access.scss');
assertUxRefinement(str_contains($scheduleStyle, '.guests-stepper[hidden]') && str_contains($scheduleStyle, '.schedule-change-status[hidden]'), 'estados ocultos públicos tienen autoridad CSS');
assertUxRefinement(str_contains($scheduleStyle, 'grid-template-columns: minmax(220px, .65fr) minmax(0, 1.45fr)'), 'cambio de horario usa composición desktop de dos zonas');
assertUxRefinement(str_contains($scheduleStyle, 'grid-template-areas:') && str_contains($scheduleStyle, '"date time"'), 'cambio de horario conserva orden compacto de escritorio');
assertUxRefinement(str_contains($scheduleStyle, 'mask: url(\'/build/images/logo.svg\')') && str_contains($scheduleStyle, 'background: var(--accent)'), 'logo usa máscara y token de marca');
assertUxRefinement(str_contains($scheduleStyle, 'display: inline-flex') && str_contains($scheduleStyle, 'max-width: 220px'), 'stepper de grupos grandes es compacto');
assertUxRefinement(str_contains($scheduleStyle, 'schedule-change-page button') && str_contains($scheduleStyle, 'cursor: pointer') && str_contains($scheduleStyle, 'cursor: not-allowed'), 'controles públicos tienen cursores scoped');
assertUxRefinement(str_contains($scheduleStyle, 'min-height: 64px') && str_contains($scheduleStyle, 'schedule-change-form__action'), 'indicaciones y CTA comparten una fila de acción compacta');
assertUxRefinement(str_contains($scheduleStyle, 'schedule-change-submit') && str_contains($scheduleStyle, 'color: var(--ink)'), 'CTA conserva contraste en hover y foco');
assertUxRefinement(str_contains($scheduleStyle, '.schedule-change-card:has(> .schedule-change-success)') && str_contains($scheduleStyle, 'min-height: clamp(360px, 50vh, 480px)'), 'éxito reduce la proporción de la tarjeta');
assertUxRefinement(str_contains($scheduleStyle, '&:empty { min-height: 0; margin: 0; }'), 'status vacío no reserva una banda visual');

$inboxView = $read('views/admin/partials/_buzon.php');
$inboxScript = $read('src/js/admin/buzon.js');
$inboxStyle = $read('src/scss/admin/shared/components/_buzon.scss');
assertUxRefinement(str_contains($inboxView, 'data-inbox-filters'), 'Buzón conserva el control segmentado');
assertUxRefinement(str_contains($inboxView, 'data-inbox-context') && str_contains($inboxView, 'hidden'), 'Buzón conserva el detalle ocultable');
assertUxRefinement(str_contains($inboxScript, "title', 'Volver'") && str_contains($inboxScript, "aria-label', 'Volver a notificaciones'"), 'volver usa icono accesible y tooltip corto');
assertUxRefinement(str_contains($inboxScript, "document.createElement('details')") && str_contains($inboxScript, 'Herramientas de prueba'), 'herramientas de prueba quedan relegadas a details');
assertUxRefinement(str_contains($inboxStyle, '&__filters[hidden]') && str_contains($inboxStyle, '&__list-view[hidden]') && str_contains($inboxStyle, '&__detail-view[hidden]'), 'Buzón tiene autoridad CSS para ocultar vistas');
assertUxRefinement(str_contains($inboxStyle, 'background: var(--admin-surface-soft)') && str_contains($inboxStyle, 'border-radius: 10px'), 'Buzón usa superficies y radios consistentes');

$rangeView = $read('views/admin/partials/_range-picker.php');
$rangeScript = $read('src/js/admin/core/range-picker.js');
assertUxRefinement(str_contains($rangeView, "'desde'"), 'range picker conserva parámetro start por defecto');
assertUxRefinement(str_contains($rangeView, "'hasta'"), 'range picker conserva parámetro end por defecto');
assertUxRefinement(str_contains($rangeView, '$rangeAllowFuture ?? false'), 'futuro queda bloqueado por defecto');
assertUxRefinement(str_contains($rangeView, '$rangePreserveQuery ?? false'), 'preservar query queda desactivado por defecto');
assertUxRefinement(str_contains($rangeScript, 'data-start-param'), 'range picker acepta nombres de query configurables');
assertUxRefinement(str_contains($rangeScript, 'data-allow-future'), 'range picker acepta futuro configurable');
assertUxRefinement(str_contains($rangeScript, 'preserveQuery'), 'range picker puede conservar filtros existentes');

$model = $read('models/Reservacion.php');
$ddl = $read('database/ddl.sql');
$migration = $read('database/migrations/2026_08_20_reservaciones_motivo_cancelacion.sql');
$service = $read('services/ReservacionAdministrativaService.php');
$detail = $read('views/admin/reservations/show.php');
assertUxRefinement(str_contains($ddl, 'motivo_cancelacion   VARCHAR(500) NULL'), 'DDL declara motivo_cancelacion');
assertUxRefinement(str_contains($migration, 'ADD COLUMN motivo_cancelacion VARCHAR(500) NULL'), 'migración forward declara motivo_cancelacion');
assertUxRefinement(str_contains($model, "'motivo_cancelacion'"), 'modelo declara motivo_cancelacion');
assertUxRefinement(substr_count($model, 'r.motivo_cancelacion') >= 3, 'consultas administrativas recuperan motivo_cancelacion');
assertUxRefinement(str_contains($service, 'motivo_cancelacion = NULLIF'), 'cancelación persiste el motivo');
assertUxRefinement(str_contains($service, 'estado_changed_at = NOW()'), 'cancelación conserva actualización de estado');
assertUxRefinement(str_contains($detail, "if (\$estado === 'cancelada')"), 'detalle condiciona cancelación al estado canónico');
assertUxRefinement(str_contains($detail, 'Sin motivo registrado.'), 'detalle soporta cancelaciones legacy sin motivo');

$reservationsIndex = $read('views/admin/reservations/index.php');
assertUxRefinement(str_contains($reservationsIndex, "\$rangeStartParam = 'fecha_inicio'") && str_contains($reservationsIndex, "\$rangeEndParam = 'fecha_fin'"), 'listado configura nombres fecha_inicio/fecha_fin');
assertUxRefinement(str_contains($reservationsIndex, '$rangeAllowFuture = true'), 'listado permite periodos futuros');
assertUxRefinement(str_contains($reservationsIndex, '$rangePreserveQuery = true'), 'listado preserva filtros al aplicar rango');
assertUxRefinement(str_contains($reservationsIndex, 'aria-label="Vista de lista"'), 'vista lista tiene nombre accesible');
assertUxRefinement(str_contains($reservationsIndex, 'aria-label="Vista de agenda"'), 'vista agenda tiene nombre accesible');

fwrite(STDOUT, "Reservaciones: contrato de refinamiento UX/UI OK\n");
