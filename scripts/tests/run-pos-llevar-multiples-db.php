<?php

declare(strict_types=1);

use Model\ActiveRecord;
use Services\Pos\PuntoVentaReservacionService;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar pedidos para llevar.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$primero = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [14],
    'comensales' => 2,
    'nombre' => 'Fixture Llevar 1',
], 0);
$segundo = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [14],
    'comensales' => 3,
    'nombre' => 'Fixture Llevar 2',
], 0);
$assert(($primero['ok'] ?? false) === true, 'No se pudo abrir el primer ticket para Llevar.');
$assert(($segundo['ok'] ?? false) === true, 'Un ticket abierto bloqueó el siguiente pedido para Llevar.');
$assert((int)$primero['ticket_id'] !== (int)$segundo['ticket_id'], 'Los pedidos para Llevar reutilizaron el mismo ticket.');

$db = ActiveRecord::getDB();
$ids = [(int)$primero['ticket_id'], (int)$segundo['ticket_id']];
$resultado = $db->query(
    'SELECT COUNT(*) AS total FROM tickets t
     INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
     WHERE t.estado = \'abierto\' AND tm.mesa_id = 14
       AND t.id IN (' . implode(',', $ids) . ')'
);
$assert($resultado !== false, 'No se pudieron contar los tickets abiertos para Llevar.');
$fila = $resultado->fetch_assoc();
$resultado->free();
$assert((int)($fila['total'] ?? 0) === 2, 'No hay dos pedidos abiertos simultáneamente en Llevar.');

$sala = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [1],
    'comensales' => 2,
    'nombre' => 'Fixture Mesa salón',
], 0);
$assert(($sala['ok'] ?? false) === true, 'No se pudo abrir el ticket de control en una mesa del salón.');
$salaDuplicada = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [1],
    'comensales' => 2,
    'nombre' => 'Fixture Mesa salón duplicada',
    'allow_multiple' => true,
], 0);
$assert(
    ($salaDuplicada['codigo'] ?? '') === PuntoVentaReservacionService::TICKET_ABIERTO,
    'allow_multiple permitió duplicar un ticket en una mesa física.'
);

$mezcla = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [1, 14],
    'comensales' => 2,
    'nombre' => 'Fixture Llevar y salón',
], 0);
$assert(
    ($mezcla['codigo'] ?? '') === PuntoVentaReservacionService::DATOS_INVALIDOS,
    'Un pedido para Llevar aceptó una mesa física adicional.'
);

// ── Barras: varios tickets hasta el tope de Configuración → POS ──────
// La Barra Blanca (id 12 en deploy.sql) admite hasta 4 tickets a la vez; el
// quinto se rechaza con BARRA_LLENA y la capacidad del salón no se mueve.
$fechaHoy = \Services\Reservations\ReservacionConfig::fechaActual();
$horaHoy = \Services\Reservations\ReservacionConfig::horaActual();
$capacidadAntes = \Services\Tables\OcupacionMesasService::resumenCapacidad(
    \Model\Mesa::reservables(),
    \Services\Tables\OcupacionMesasService::evaluarHorario($fechaHoy, $horaHoy)
);
$assert(\Model\ConfiguracionPos::maxTicketsBarra() === 4, 'El tope por barra de una base nueva no es 4.');

$ticketsBarra = [];
for ($i = 1; $i <= 4; $i++) {
    $barra = PuntoVentaReservacionService::abrirWalkIn([
        'mesa_ids' => [12],
        'comensales' => 2,
        'nombre' => 'Fixture Barra ' . $i,
    ], 0);
    $assert(($barra['ok'] ?? false) === true, "No se pudo abrir el ticket {$i} de la barra: " . json_encode($barra));
    $ticketsBarra[] = (int)$barra['ticket_id'];
}
$assert(count(array_unique($ticketsBarra)) === 4, 'Los tickets de la barra se repitieron.');

$quinto = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [12],
    'comensales' => 1,
    'nombre' => 'Fixture Barra 5',
], 0);
$assert(($quinto['codigo'] ?? '') === PuntoVentaReservacionService::BARRA_LLENA, 'Un quinto ticket entró a una barra con tope 4.');
$assert(($quinto['tickets_abiertos'] ?? 0) === 4 && ($quinto['tope_tickets'] ?? 0) === 4, 'BARRA_LLENA no informa abiertos y tope.');

$barraYMesa = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [12, 2],
    'comensales' => 4,
    'nombre' => 'Fixture Barra y salón',
], 0);
$assert(($barraYMesa['codigo'] ?? '') === PuntoVentaReservacionService::DATOS_INVALIDOS, 'Una barra se unió a una mesa del salón.');

$capacidadDespues = \Services\Tables\OcupacionMesasService::resumenCapacidad(
    \Model\Mesa::reservables(),
    \Services\Tables\OcupacionMesasService::evaluarHorario($fechaHoy, $horaHoy)
);
$assert(
    (int)$capacidadDespues['capacidad_fisica_total'] === (int)$capacidadAntes['capacidad_fisica_total']
        && (int)$capacidadDespues['capacidad_fisica_libre'] === (int)$capacidadAntes['capacidad_fisica_libre'],
    'Los tickets de barra movieron la capacidad del salón.'
);

// El mapa del POS pinta la barra llena con su conteo.
$mapa = \Services\Pos\PosReservacionQueryService::paraFecha($fechaHoy, '', ['superficie' => 'admin']);
$estadoBarra = null;
foreach ((array)($mapa['mesas_estado'] ?? []) as $mesaEstado) {
    if ((int)($mesaEstado['id'] ?? 0) === 12) {
        $estadoBarra = $mesaEstado;
    }
}
$assert($estadoBarra !== null, 'El mapa no trae el estado de la barra.');
$assert($estadoBarra['estado_visual_pos'] === 'ocupada' && $estadoBarra['subtitulo_pos'] === '4/4', 'El mapa no pinta la barra llena con 4/4.');

fwrite(STDOUT, "PASS: dos pedidos simultáneos para Llevar; protección de mesa física y mezcla de mesas; barra con tope de 4 tickets.\n");
