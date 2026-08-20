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
assertUxRefinement(str_contains($publicView, 'Selecciona una fecha para ver horarios disponibles.'), 'hora tiene un estado vacío único');

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
