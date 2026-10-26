<?php

declare(strict_types=1);

/*
 * «Casos» del panel estratégico (fase 6) contra una base real.
 *
 * Sólo aparece la reservación que pide algo, una tarjeta por reservación con
 * todos sus casos del más urgente al menos, y una reservación en orden no sale.
 */

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Model\ReservacionReubicacion;
use Services\Reservations\ReservacionCasosAccionablesService as Casos;
use Services\Reservations\ReservacionConfig;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar los casos accionables.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = ActiveRecord::getDB();
$restaurantUnitId = NOTIFICATIONS_TEST_RESTAURANT_UNIT_ID;
$ahora = ReservacionConfig::ahora();
$fecha = $ahora->modify('+6 days')->format('Y-m-d');
$creadas = [];

$sembrar = static function (string $nombre, int $comensales, string $fechaReserva, string $hora, array $mesaIds, bool $conContacto = true) use ($db, &$creadas, $restaurantUnitId): int {
    $token = 'fx-casos-' . substr(md5($nombre . $fechaReserva . $hora), 0, 16);
    $tipo = $conContacto ? 'email' : 'ninguno';
    $contacto = $conContacto ? strtolower(str_replace(' ', '.', $nombre)) . '@example.test' : null;
    $stmt = $db->prepare(
        "INSERT INTO reservaciones
           (unidad_id, unidad_tipo, nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, request_token)
         VALUES ({$restaurantUnitId}, 'negocio', ?, ?, ?, ?, ?, ?, '', 'admin', 'confirmada', ?)"
    );
    $stmt->bind_param('sssssis', $nombre, $tipo, $contacto, $fechaReserva, $hora, $comensales, $token);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    if ($mesaIds !== []) {
        ReservacionMesa::reemplazarAsignacion($id, $mesaIds);
    }
    $creadas[] = $id;
    return $id;
};

$tarjetaDe = static function (array $listado, int $id): ?array {
    foreach ($listado['casos'] as $tarjeta) {
        if ((int)$tarjeta['reservacion']['id'] === $id) {
            return $tarjeta;
        }
    }
    return null;
};

try {
    notificationsTestAuthenticateStaff();
    $sinMesa = $sembrar('Casos Sin Mesa', 4, $fecha, '13:00:00', []);
    $sinContacto = $sembrar('Casos Sin Contacto', 2, $fecha, '13:30:00', [3], false);
    $reubicada = $sembrar('Casos Reubicada', 2, $fecha, '14:00:00', [2]);
    $doble = $sembrar('Casos Doble', 15, $fecha, '15:00:00', [], false);
    $enOrden = $sembrar('Casos En Orden', 2, $fecha, '16:00:00', [4]);
    ReservacionReubicacion::registrar($reubicada, null, [9], [2]);

    $listado = Casos::listar($fecha, $fecha, null, 1, 50, $ahora);

    $assert($tarjetaDe($listado, $enOrden) === null, 'Una reservación sin nada que resolver salió en Casos.');
    $assert(array_column($tarjetaDe($listado, $sinMesa)['casos'] ?? [], 'tipo') === [Casos::TIPO_SIN_MESA], 'La reserva sin mesa no trae su caso.');
    $assert(array_column($tarjetaDe($listado, $sinContacto)['casos'] ?? [], 'tipo') === [Casos::TIPO_SIN_CONTACTO], 'La reserva sin contacto no trae su caso.');
    $casoReubicada = ($tarjetaDe($listado, $reubicada)['casos'] ?? [])[0] ?? [];
    $assert(($casoReubicada['tipo'] ?? '') === Casos::TIPO_REUBICADA && (int)($casoReubicada['reubicacion_id'] ?? 0) > 0,
        'La reubicada no trae su caso con el id de la reubicación.');

    // Varios casos en UNA tarjeta, del más urgente al menos.
    $tarjetaDoble = $tarjetaDe($listado, $doble);
    $assert(array_column($tarjetaDoble['casos'] ?? [], 'tipo') === [Casos::TIPO_SIN_MESA, Casos::TIPO_SIN_CONTACTO],
        'Una reserva con dos casos no sale en una tarjeta ordenada por urgencia.');
    $assert(str_contains((string)$tarjetaDoble['casos'][0]['descripcion'], 'Grupo grande') && $tarjetaDoble['reservacion']['grupo_grande'] === true,
        'El grupo grande sin mesa no se explica como tal.');
    $assert(str_contains((string)$tarjetaDoble['reservacion']['url_pos'], 'reservation_id=' . $doble),
        'La tarjeta no lleva el enlace al POS con la reserva.');

    // Orden global: lo urgente primero.
    $severidades = array_column($listado['casos'], 'severidad');
    $ordenadas = $severidades;
    sort($ordenadas);
    $assert($severidades === $ordenadas, 'Las tarjetas no salen de la más urgente a la menos.');
    $assert($listado['por_tipo'][Casos::TIPO_SIN_MESA] === 2 && $listado['por_tipo'][Casos::TIPO_SIN_CONTACTO] === 2
        && $listado['por_tipo'][Casos::TIPO_REUBICADA] === 1, 'El conteo por tipo no cuadra: ' . json_encode($listado['por_tipo']));

    // Filtro por tipo y paginación.
    $soloSinMesa = Casos::listar($fecha, $fecha, Casos::TIPO_SIN_MESA, 1, 50, $ahora);
    $assert($soloSinMesa['total'] === 2, 'El filtro por tipo no deja sólo las reservas sin mesa.');
    $pagina2 = Casos::listar($fecha, $fecha, null, 2, 2, $ahora);
    $assert($pagina2['paginas'] === 2 && count($pagina2['casos']) === 2 && $pagina2['pagina'] === 2, 'La paginación no parte el listado.');

    // Revisada, la reubicación deja de ser un caso.
    ReservacionReubicacion::marcarRevisada((int)$casoReubicada['reubicacion_id'], null);
    $assert($tarjetaDe(Casos::listar($fecha, $fecha, null, 1, 50, $ahora), $reubicada) === null,
        'Una reubicación revisada sigue saliendo como caso.');

    // Ausencia pendiente: hoy, media hora después de su hora (tolerancia vencida).
    $haceMediaHora = $ahora->modify('-30 minutes');
    if ($haceMediaHora->format('Y-m-d') === $ahora->format('Y-m-d')) {
        $ausente = $sembrar('Casos Ausente', 2, $ahora->format('Y-m-d'), $haceMediaHora->format('H:i:00'), [6]);
        $hoy = Casos::listar($ahora->format('Y-m-d'), $ahora->format('Y-m-d'), null, 1, 50, $ahora);
        $tarjetaAusente = $tarjetaDe($hoy, $ausente);
        $assert(($tarjetaAusente['casos'][0]['tipo'] ?? '') === Casos::TIPO_AUSENCIA,
            'La reserva con tolerancia vencida no sale como «No llegó»: ' . json_encode($tarjetaAusente));
    }
} finally {
    if ($creadas !== []) {
        $ids = implode(',', array_map('intval', $creadas));
        $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$ids})");
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$ids})");
        $db->query("DELETE FROM reservacion_reubicaciones WHERE reservacion_id IN ({$ids}) OR causa_reservacion_id IN ({$ids})");
        $db->query("DELETE FROM buzon_notificaciones WHERE entidad_tipo = 'reservacion' AND entidad_id IN ({$ids})");
        $db->query("DELETE FROM reservacion_recordatorios WHERE reservacion_id IN ({$ids}) OR reservacion_raiz_id IN ({$ids})");
        $db->query("DELETE FROM reservaciones WHERE id IN ({$ids})");
    }
}

fwrite(STDOUT, "PASS: casos por reservación, urgencia, filtro, paginación, reubicada revisada y ausencia pendiente.\n");
