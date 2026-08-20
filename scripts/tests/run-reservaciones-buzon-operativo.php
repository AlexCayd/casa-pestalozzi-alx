<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\BuzonNotificacionesService;
use Services\PosReservacionSerializer;
use Services\ReservacionBuzonService;
use Services\ReservacionConfig;
use Services\ReservacionMapaAdministrativaService;
use Services\ReservacionPoliticaPosService;
use Services\ReservacionVigenciaService;

function buzonAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/services/ReservacionBuzonService.php');
$generic = file_get_contents($root . '/services/BuzonNotificacionesService.php');
$controller = file_get_contents($root . '/controllers/AdminBuzonController.php');
$critical = file_get_contents($root . '/controllers/AdminReservacionController.php');
$reservationView = file_get_contents($root . '/views/admin/reservations/show.php');
$routes = file_get_contents($root . '/public/index.php');
$inboxJs = file_get_contents($root . '/src/js/admin/buzon.js');
$inboxView = file_get_contents($root . '/views/admin/partials/_buzon.php');
$topbarView = file_get_contents($root . '/views/admin/partials/_topbar.php');
$impactService = file_get_contents($root . '/services/HorarioOperacionImpactoService.php');
$reservationModel = file_get_contents($root . '/models/Reservacion.php');
$mapService = file_get_contents($root . '/services/ReservacionMapaAdministrativaService.php');
$posJs = file_get_contents($root . '/src/js/modules/punto-de-venta.js');

foreach ([$service, $generic, $controller, $critical, $reservationView, $routes, $inboxJs, $inboxView, $topbarView, $impactService, $reservationModel, $mapService, $posJs] as $source) {
    buzonAssert(is_string($source), 'se pudieron leer los contratos operativos');
}

buzonAssert(ReservacionBuzonService::TIPO_AUSENCIA_PENDIENTE === 'reservacion_ausencia_pendiente', 'tipo de ausencia pendiente');
buzonAssert(ReservacionBuzonService::TIPO_SIN_ASIGNACION_PROXIMA === 'reservacion_sin_asignacion_proxima', 'tipo de asignación próxima');
buzonAssert(ReservacionBuzonService::dedupAusencia(41) === 'reservacion_ausencia_pendiente:41', 'deduplicación de ausencia');
buzonAssert(ReservacionBuzonService::dedupSinAsignacion(41) === 'reservacion_sin_asignacion_proxima:41', 'deduplicación de asignación');
buzonAssert(str_contains($service, 'sincronizarPendientesTemporales'), 'sincronización temporal disponible');
buzonAssert(str_contains($service, 'ReservacionVigenciaService::clasificar'), 'sincronizador usa vigencia canónica');
buzonAssert(str_contains($service, 'ReservacionPoliticaPosService::evaluar'), 'sincronizador usa política POS canónica');
buzonAssert(str_contains($service, 'TicketMesa::abiertosParaMapa'), 'sincronizador lee tickets abiertos por lote');
buzonAssert(str_contains($generic, 'cerrarTipoEntidadEnTransaccion'), 'cierre transaccional por tipo y entidad');
buzonAssert(str_contains($generic, 'WHERE tipo = ? AND entidad_tipo = ? AND entidad_id = ?'), 'cierre filtra tipo y entidad');
buzonAssert(str_contains($generic, 'requiere_accion'), 'buzón distingue acción y seguimiento');
buzonAssert(str_contains($service, 'use Model\\ActiveRecord;'), 'ReservacionBuzonService importa ActiveRecord correctamente');
buzonAssert(str_contains($generic, 'ON DUPLICATE KEY UPDATE'), 'upsert idempotente del buzón');
buzonAssert(str_contains($controller, "'CSRF_INVALIDO'") && str_contains($controller, 'sincronizarPendientesTemporales'), 'endpoint de sync protegido');
buzonAssert(str_contains($routes, '/admin/api/buzon/sincronizar'), 'ruta de sincronización del buzón');
buzonAssert(!str_contains($critical, 'sincronizarBuzonReservacion'), 'alta/edición/estado/asignación no sincronizan el buzón');
buzonAssert(str_contains($inboxJs, "data-inbox-refresh-seconds") && str_contains($inboxJs, "'/admin/api/buzon/sincronizar'"), 'refresh usa configuración y endpoint');
buzonAssert(substr_count($inboxJs, 'window.setInterval') === 1, 'el buzón no duplica intervalos');
buzonAssert(str_contains($inboxJs, "button('Revisar', 'review'") && str_contains($inboxJs, 'markItemRead'), 'tarjeta abre detalle y marca leído');
buzonAssert(str_contains($inboxJs, 'Marcar seguimiento como resuelto') && str_contains($inboxJs, 'data-schedule-impact-resolve'), 'resolución usa una sola acción');
buzonAssert(!str_contains($inboxJs, 'Mantener reservación') && !str_contains($inboxJs, 'Coordinar'), 'buzón no guarda motivos de resolución');
buzonAssert(str_contains($inboxJs, 'No pudimos actualizar las notificaciones.') && str_contains($inboxJs, 'items.length'), 'error no borra datos cargados');
buzonAssert(!str_contains($inboxJs, 'Marcar leída'), 'no existe botón independiente de lectura');
buzonAssert(str_contains($inboxView, 'has-high-priority') && str_contains($inboxView, 'REFRESCO_ESTADOS_SEGUNDOS'), 'estado visual inicial del icono');
buzonAssert(str_contains($topbarView, 'data-inbox-open') && str_contains($topbarView, 'data-inbox-count'), 'trigger del buzón vive en el topbar');
buzonAssert(str_contains($impactService, 'ESTADOS_ITEM_FINALES') && str_contains($impactService, 'ESTADOS_ITEM_PENDIENTES'), 'estados de afectación están centralizados');
buzonAssert(str_contains($impactService, 'cerrarTipoEntidadEnTransaccion'), 'fuente usa cierre por entidad');
buzonAssert(str_contains($critical, 'impacto_reservacion_id') && str_contains($reservationView, 'Esta reservación requiere atención'), 'Gestionar conserva el contexto de la afectación');
buzonAssert(str_contains($reservationModel, "r.estado NOT IN ('pendiente_verificacion', 'expirada')"), 'listado admin excluye holds por defecto');
buzonAssert(str_contains($mapService, "'fuera_horario_operacion'") && str_contains($mapService, '&& !$fueraHorarioOperacion'), 'mapa separa seguimiento de proyección');
buzonAssert(str_contains($posJs, 'Fuera de horario de operación'), 'POS presenta el mismo indicador');

$zona = ReservacionConfig::timezone();
$ahora = new DateTimeImmutable('2026-08-19 13:00:00', $zona);
$ausencia = [
    'id' => 41,
    'estado' => 'confirmada',
    'fecha' => '2026-08-19',
    'hora' => '11:00:00',
    'comensales' => 4,
    'hold_expires_at' => null,
];
$vigenciaAusencia = ReservacionVigenciaService::clasificar($ausencia, $ahora, null);
buzonAssert($vigenciaAusencia['ausencia_pendiente'] === true, 'ausencia después de tolerancia');
buzonAssert($vigenciaAusencia['puede_marcar_no_show'] === true, 'no-show permitido sin ticket');

$proxima = $ausencia;
$proxima['id'] = 42;
$proxima['hora'] = '13:31:00';
$vigenciaProxima = ReservacionVigenciaService::clasificar($proxima, $ahora, null);
$politicaProxima = ReservacionPoliticaPosService::evaluar($proxima, $ahora, null, null, ['sin_mesas' => true]);
buzonAssert(($vigenciaProxima['ventana_operativa']['estado'] ?? '') === 'advertencia', 'ventana normal entre 60 y 30 minutos');
buzonAssert($politicaProxima['muestra_advertencia'] === true, 'advertencia canónica de próxima reservación');

$critica = $proxima;
$critica['id'] = 43;
$critica['hora'] = '13:30:00';
$politicaCritica = ReservacionPoliticaPosService::evaluar($critica, $ahora, null, null, ['sin_mesas' => true]);
buzonAssert(($politicaCritica['ventana_pos'] ?? '') === 'bloqueo', 'ventana alta dentro de 30 minutos');
buzonAssert($politicaCritica['bloqueo_walk_in'] === true, 'bloqueo canónico incluido en la ventana alta');

$fuera = PosReservacionSerializer::reservacion(
    array_merge($proxima, ['hora' => '11:00:00']),
    null,
    [],
    $ahora,
    ['horario_efectivo' => ['abierto' => true, 'hora_apertura' => '12:00:00', 'hora_cierre' => '18:00:00']]
);
buzonAssert($fuera['fuera_horario_operacion'] === true, 'serializer deriva fuera de horario');
$mapa = ReservacionMapaAdministrativaService::proyectar([$fuera], []);
buzonAssert(count($mapa['reservaciones_admin']) === 1, 'mapa admin conserva reservación fuera de horario');
buzonAssert($mapa['reservaciones_admin'][0]['en_proyeccion_mapa'] === false, 'fuera de horario no entra en proyección del mapa');

buzonAssert(ReservacionBuzonService::grupoGrandeVisibleParaBuzon([
    'estado' => 'confirmada', 'comensales' => 13, 'contacto_tipo' => 'ninguno', 'contacto' => '', 'mesas_count' => 0,
]) === true, 'grupo grande sin coordinación genera seguimiento');
buzonAssert(ReservacionBuzonService::grupoGrandeVisibleParaBuzon([
    'estado' => 'confirmada', 'comensales' => 13, 'contacto_tipo' => 'email', 'contacto' => 'a@b.test', 'mesas_count' => 1,
]) === false, 'grupo grande coordinado no genera aviso redundante');

fwrite(STDOUT, "Reservaciones: buzón operativo, detalle y fuera de horario OK\n");
