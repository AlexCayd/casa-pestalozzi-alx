<?php

declare(strict_types=1);

/*
 * Contrato de los turnos del corte de caja (Services\Pos\CorteCaja): qué
 * acepta el formulario, qué ventana cubre cada turno —incluidos los que pasan
 * la medianoche— y cuál está en curso. Sin base de datos.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Pos\CorteCaja;

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};
$leer = static fn (string $ruta): string => (string) file_get_contents($root . '/' . $ruta);
$t = static fn (string $nombre, string $inicio, string $fin): array => compact('nombre', 'inicio', 'fin');

// ── normalizarTurnos() ──────────────────────────────────────
$r = CorteCaja::normalizarTurnos([
    $t('Vespertino', '15:00', '23:00'),
    $t('', '', ''),
    $t(' Matutino ', '08:00', '15:00'),
]);
$assert($r['errores'] === [], 'dos turnos contiguos son válidos');
$assert(array_column($r['turnos'], 'nombre') === ['Matutino', 'Vespertino'], 'se ordenan por inicio, sin la fila vacía y con el nombre recortado');

$assert(CorteCaja::normalizarTurnos([])['turnos'] === [], 'sin filas, sin turnos');
$assert(CorteCaja::normalizarTurnos(['basura', null])['turnos'] === [], 'filas que no son arreglo se ignoran');

$errores = static fn (array $filas): array => CorteCaja::normalizarTurnos($filas)['errores'];
$assert(count($errores([$t('', '08:00', '15:00')])) === 1, 'horas sin nombre');
$assert(count($errores([$t(str_repeat('x', 41), '08:00', '15:00')])) === 1, 'nombre de más de 40');
$assert(count($errores([$t('Mañana', '8:00', '15:00')])) === 1, 'hora sin formato HH:MM');
$assert(count($errores([$t('Mañana', '24:00', '15:00')])) === 1, 'hora fuera de rango');
$assert(count($errores([$t('Mañana', '08:00', '')])) === 1, 'falta el fin');
$assert(count($errores([$t('Mañana', '08:00', '08:00')])) === 1, 'inicio igual al fin');
$assert(count($errores([$t('Turno', '08:00', '12:00'), $t('turno', '13:00', '15:00')])) === 1, 'nombres repetidos sin importar mayúsculas');
$assert(count($errores([$t('A', '08:00', '15:00'), $t('B', '14:00', '20:00')])) === 1, 'solape simple');
$assert(count($errores([$t('Noche', '22:00', '03:00'), $t('Madrugada', '02:00', '06:00')])) === 1, 'solape tras la medianoche');
$assert(count($errores([$t('Noche', '18:00', '02:00'), $t('Mañana', '01:00', '09:00')])) === 1, 'el nocturno de ayer pisa la madrugada de hoy');
$assert($errores([$t('Noche', '18:00', '02:00'), $t('Mañana', '08:00', '18:00')]) === [], 'nocturno y diurno que se tocan no se solapan');
$filas = array_map(static fn (int $h): array => $t("T{$h}", sprintf('%02d:00', $h), sprintf('%02d:00', $h + 1)), range(0, 9));
$assert(count(CorteCaja::normalizarTurnos($filas)['turnos']) === CorteCaja::MAX_TURNOS, 'tope de turnos');

// ── ventana() ───────────────────────────────────────────────
$assert(CorteCaja::ventana('2026-10-24', null) === ['desde' => '2026-10-24 00:00:00', 'hasta' => '2026-10-25 00:00:00'], 'día completo');
$assert(CorteCaja::ventana('2026-10-24', $t('M', '08:00', '15:00')) === ['desde' => '2026-10-24 08:00:00', 'hasta' => '2026-10-24 15:00:00'], 'turno diurno');
$assert(CorteCaja::ventana('2026-10-31', $t('N', '18:00', '02:00')) === ['desde' => '2026-10-31 18:00:00', 'hasta' => '2026-11-01 02:00:00'], 'nocturno cruza el día y el mes');

// ── turnoVigente() ──────────────────────────────────────────
$turnos = CorteCaja::normalizarTurnos([$t('Mañana', '08:00', '15:00'), $t('Noche', '18:00', '02:00')])['turnos'];
$en = static fn (string $momento): array => CorteCaja::turnoVigente($turnos, new DateTimeImmutable($momento));
$assert($en('2026-10-24 08:00:00') === ['indice' => 0, 'fecha' => '2026-10-24'], 'el inicio pertenece al turno');
$assert($en('2026-10-24 15:00:00') === ['indice' => null, 'fecha' => '2026-10-24'], 'el fin ya no (ventana semiabierta)');
$assert($en('2026-10-24 23:30:00') === ['indice' => 1, 'fecha' => '2026-10-24'], 'nocturno antes de medianoche');
$assert($en('2026-10-25 01:30:00') === ['indice' => 1, 'fecha' => '2026-10-24'], 'nocturno después de medianoche es del día anterior');
$assert($en('2026-10-25 03:00:00') === ['indice' => null, 'fecha' => '2026-10-25'], 'entre turnos, el día de hoy');
$assert(CorteCaja::turnoVigente([], new DateTimeImmutable('2026-10-24 12:00')) === ['indice' => null, 'fecha' => '2026-10-24'], 'sin turnos, el día');

// ── Cableado ────────────────────────────────────────────────
$assert(str_contains($leer('public/index.php'), "post('/admin/configuracion/pos/turnos'"), 'ruta de guardado registrada');
$controlador = $leer('controllers/PuntoVentaController.php');
$metodo = substr($controlador, (int) strpos($controlador, 'function corteCaja'), 2500);
$assert(str_contains($metodo, 'CorteCaja::resumen(') && !str_contains($metodo, 'CURDATE()'), 'el corte del POS ya no está fijado a CURDATE()');
$assert(preg_match('/^\s*turnos_caja\s+JSON NULL,/m', $leer('database/ddl.sql')) === 1, 'configuracion_pos.turnos_caja en el esquema');
$js = $leer('src/js/modules/punto-de-venta.js');
$assert(str_contains($js, "'/api/corte-caja' + query") && str_contains($js, 'data-caja-turno'), 'el modal pide el corte por turno');

echo "Corte por turnos: {$assertions} comprobaciones OK\n";
