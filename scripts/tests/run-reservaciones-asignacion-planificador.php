<?php

declare(strict_types=1);

/*
 * Motor de asignación: mesa, pareja, bloque exterior y reubicación.
 *
 * Prueba pura. El contexto del día se fabrica a mano y la ocupación sale del
 * mismo OcupacionMesasService que usa producción, así que esto prueba el
 * planificador y su lectura de tickets y reservas a la vez, sin base.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Reservations\AsignacionPlanificadorService as Planificador;
use Services\Reservations\ReservacionConfig;
use Services\Tables\OcupacionMesasService;

/** @param mixed $condicion */
function asegurar($condicion, string $mensaje): void
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$tz = ReservacionConfig::timezone();
$fecha = '2026-11-20';
$ahora = new DateTimeImmutable($fecha . ' 10:00:00', $tz);

function mesa(int $id, string $tipo, ?string $zona, int $capacidad, int $activo = 1): object
{
    return (object)[
        'id' => $id,
        'numero' => $id,
        'nombre' => ($tipo === 'mesa' ? 'Mesa ' : 'Elemento ') . $id,
        'tipo' => $tipo,
        'zona' => $zona,
        'capacidad' => $capacidad,
        'pos_x' => 0,
        'pos_y' => 0,
        'activo' => $activo,
        'reservable' => $tipo === 'mesa' ? 1 : 0,
    ];
}

/** El plano de deploy.sql: 1–7 interior, 8–11 exterior, barras, Caja y Llevar. */
function plano(array $cambios = []): array
{
    $mesas = [];
    for ($i = 1; $i <= 7; $i++) {
        $mesas[$i] = mesa($i, 'mesa', 'interior', 4);
    }
    for ($i = 8; $i <= 11; $i++) {
        $mesas[$i] = mesa($i, 'mesa', 'exterior', 4);
    }
    $mesas[12] = mesa(12, 'barra', null, 8);
    $mesas[13] = mesa(13, 'especial', null, 0);
    $mesas[14] = mesa(14, 'especial', null, 0);
    $mesas[15] = mesa(15, 'barra', null, 6);
    $mesas[16] = mesa(16, 'barra', null, 6);
    foreach ($cambios as $id => $mesaCambiada) {
        $mesas[$id] = $mesaCambiada;
    }
    return array_values($mesas);
}

/**
 * @param array<int, array{id:int, hora:string, comensales:int, mesas:array<int,int>, estado?:string}> $reservas
 * @param array<int, array{id:int, apertura:string, mesas:array<int,int>}> $tickets
 */
function contexto(string $fecha, DateTimeImmutable $ahora, array $plano, array $reservas = [], array $tickets = []): array
{
    $filas = [];
    foreach ($reservas as $reserva) {
        foreach ($reserva['mesas'] as $mesaId) {
            $filas[] = [
                'mesa_id' => $mesaId,
                'reservacion_id' => $reserva['id'],
                'nombre' => 'Reserva ' . $reserva['id'],
                'contacto' => '',
                'fecha' => $fecha,
                'hora' => $reserva['hora'],
                'comensales' => $reserva['comensales'],
                'estado' => $reserva['estado'] ?? 'confirmada',
                'hold_expires_at' => null,
                'fuente' => ($reserva['estado'] ?? 'confirmada') === 'pendiente_verificacion' ? 'hold' : 'reservacion',
                'reservacion_influye_en_disponibilidad' => true,
            ];
        }
    }
    $abiertos = array_map(static fn(array $ticket): array => [
        'id' => $ticket['id'],
        'comensales' => 2,
        'hora_apertura' => $fecha . ' ' . $ticket['apertura'],
        'closed_at' => null,
        'estado' => 'abierto',
        'reservacion_id' => null,
        'mesa_ids' => $ticket['mesas'],
    ], $tickets);

    return [
        'fecha' => $fecha,
        'ahora' => $ahora,
        'bloquear' => false,
        'reservaciones' => $filas,
        'tickets' => $abiertos,
        'mesas' => $plano,
        'mesas_reservables' => array_values(array_filter($plano, static fn($mesa): bool =>
            (int)$mesa->activo === 1 && (int)$mesa->reservable === 1 && $mesa->tipo === 'mesa' && (int)$mesa->capacidad > 0)),
    ];
}

function planear(array $contexto, int $comensales, string $hora, array $excluir = [], int $maximo = 12): array
{
    $evaluacion = OcupacionMesasService::evaluarHorarioConContexto($contexto, $hora, $excluir, $contexto['ahora']);

    return Planificador::planificarConContexto($contexto, $evaluacion, $comensales, $hora, $excluir, $maximo, $contexto['ahora']);
}

/** Reservas de 2 personas que ocupan, a esa hora, las mesas dadas (una cada una). */
function ocupar(array $mesaIds, string $hora, int $desdeId = 900): array
{
    $reservas = [];
    foreach (array_values($mesaIds) as $indice => $mesaId) {
        $reservas[] = ['id' => $desdeId + $indice, 'hora' => $hora, 'comensales' => 2, 'mesas' => [$mesaId]];
    }
    return $reservas;
}

$hora = '19:00:00';

// 1. Una mesa: interior primero, por número.
$plan = planear(contexto($fecha, $ahora, plano()), 2, $hora);
asegurar($plan['ok'] && $plan['mesa_ids'] === [1] && $plan['tipo'] === Planificador::TIPO_INDIVIDUAL, '1–4 personas toman la Mesa 1');
$plan = planear(contexto($fecha, $ahora, plano(), ocupar([1], $hora)), 4, $hora);
asegurar($plan['mesa_ids'] === [2], 'con la 1 ocupada se toma la 2');

// 2. Interior lleno → desborde al exterior, en orden.
$plan = planear(contexto($fecha, $ahora, plano(), ocupar(range(1, 7), $hora)), 4, $hora);
asegurar($plan['mesa_ids'] === [8], 'interior lleno desborda a la Mesa 8');
$plan = planear(contexto($fecha, $ahora, plano(), ocupar(range(1, 8), $hora)), 4, $hora);
asegurar($plan['mesa_ids'] === [9], 'con la 8 también ocupada sigue la 9');

// 3. Parejas en orden de preferencia.
$plan = planear(contexto($fecha, $ahora, plano()), 6, $hora);
asegurar($plan['mesa_ids'] === [3, 4] && $plan['tipo'] === Planificador::TIPO_PAREJA, '5–8 personas toman [3,4]');
$plan = planear(contexto($fecha, $ahora, plano(), ocupar([3], $hora)), 6, $hora);
asegurar($plan['mesa_ids'] === [7, 8], 'sin [3,4] sigue [7,8]');
$plan = planear(contexto($fecha, $ahora, plano(), ocupar([3, 7], $hora)), 6, $hora);
asegurar($plan['mesa_ids'] === [6, 9], 'sin [7,8] sigue [6,9]');
$plan = planear(contexto($fecha, $ahora, plano(), ocupar([3, 7, 6], $hora)), 8, $hora);
asegurar($plan['mesa_ids'] === [10, 11], 'al final [10,11]');
$plan = planear(contexto($fecha, $ahora, plano(), ocupar([3, 7, 6, 10], $hora)), 6, $hora);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_SIN_MESA, 'sin parejas libres no hay asignación');

// 4. Ticket en el bloque → no hay bloque aunque haya interior libre.
$ahoraNoche = new DateTimeImmutable($fecha . ' 19:00:00', $tz);
$plan = planear(contexto($fecha, $ahoraNoche, plano(), [], [['id' => 1, 'apertura' => '18:45:00', 'mesas' => [10]]]), 9, '19:00:00');
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_TICKET_EN_BLOQUE, 'un ticket abierto en la Mesa 10 impide el bloque');

// 5. Bloque libre: de 9 hasta el máximo.
$plan = planear(contexto($fecha, $ahora, plano()), 10, $hora);
asegurar($plan['ok'] && $plan['mesa_ids'] === [8, 9, 10, 11] && $plan['tipo'] === Planificador::TIPO_BLOQUE, '9 o más toman el exterior completo');
asegurar($plan['reubicaciones'] === [], 'sin reservas en el bloque no se mueve nada');
$plan = planear(contexto($fecha, $ahora, plano()), 16, $hora, [], 16);
asegurar($plan['ok'] && $plan['capacidad_total'] === 16, 'con máximo 16 el bloque recibe 16');

// 6. Reubicación simple: la pareja de la 9 pasa al interior.
$contexto = contexto($fecha, $ahora, plano(), [['id' => 501, 'hora' => $hora, 'comensales' => 2, 'mesas' => [9]]]);
$plan = planear($contexto, 10, $hora);
asegurar($plan['ok'] && $plan['mesa_ids'] === [8, 9, 10, 11], 'el grupo toma el bloque');
asegurar(count($plan['reubicaciones']) === 1, 'hay una reubicación');
asegurar($plan['reubicaciones'][0]['reservacion_id'] === 501
    && $plan['reubicaciones'][0]['mesas_antes'] === [9]
    && $plan['reubicaciones'][0]['mesas_despues'] === [1], 'la pareja de la 9 va a la Mesa 1');

// 7. Dos desplazadas que se solapan no comparten mesa; si no se solapan, sí.
$contexto = contexto($fecha, $ahora, plano(), [
    ['id' => 601, 'hora' => '19:00:00', 'comensales' => 2, 'mesas' => [9]],
    ['id' => 602, 'hora' => '19:30:00', 'comensales' => 2, 'mesas' => [10]],
]);
$plan = planear($contexto, 10, '19:00:00');
$destinos = array_column($plan['reubicaciones'], 'mesas_despues', 'reservacion_id');
asegurar($plan['ok'] && $destinos[601] === [1] && $destinos[602] === [2], 'solapadas reciben mesas distintas');
$contexto = contexto($fecha, $ahora, plano(), [
    ['id' => 611, 'hora' => '18:00:00', 'comensales' => 2, 'mesas' => [9]],
    ['id' => 612, 'hora' => '19:30:00', 'comensales' => 2, 'mesas' => [10]],
]);
$plan = planear($contexto, 10, '18:45:00');
$destinos = array_column($plan['reubicaciones'], 'mesas_despues', 'reservacion_id');
asegurar($plan['ok'] && $destinos[611] === [1] && $destinos[612] === [1], 'sin solape pueden compartir la Mesa 1');

// 8. Interior lleno: la desplazada no tiene a dónde ir → no se ofrece.
$reservas = array_merge(ocupar(range(1, 7), $hora), [['id' => 701, 'hora' => $hora, 'comensales' => 2, 'mesas' => [9]]]);
$plan = planear(contexto($fecha, $ahora, plano(), $reservas), 10, $hora);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_SIN_REUBICACION, 'sin destino en el interior el horario no se ofrece');

// 9. Pareja desplazada: sólo [3,4] es destino.
$pareja = [['id' => 801, 'hora' => $hora, 'comensales' => 6, 'mesas' => [7, 8]]];
$plan = planear(contexto($fecha, $ahora, plano(), array_merge($pareja, ocupar([3], $hora))), 10, $hora);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_SIN_REUBICACION, 'con [3,4] ocupada la pareja no se reubica');
$plan = planear(contexto($fecha, $ahora, plano(), $pareja), 10, $hora);
asegurar($plan['ok'] && $plan['reubicaciones'][0]['mesas_antes'] === [7, 8]
    && $plan['reubicaciones'][0]['mesas_despues'] === [3, 4], 'la pareja [7,8] se mueve entera a [3,4]');

// 10. Ticket proyectado en el destino: criterio estricto.
$plan = planear(
    contexto($fecha, $ahora, plano(), $pareja, [['id' => 2, 'apertura' => '09:30:00', 'mesas' => [3]]]),
    10,
    $hora
);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_SIN_REUBICACION, 'un ticket proyectado en [3,4] impide reubicar ahí');

// 11. Otro grupo grande en el bloque.
$plan = planear(contexto($fecha, $ahora, plano(), [['id' => 1101, 'hora' => $hora, 'comensales' => 9, 'mesas' => [8, 9, 10]]]), 10, $hora);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_GRUPO_EN_BLOQUE, 'otro grupo de 9+ en el bloque lo bloquea');

// 12. Ticket físico en el bloque en el momento actual.
$plan = planear(contexto($fecha, $ahora, plano(), [], [['id' => 3, 'apertura' => '09:30:00', 'mesas' => [10]]]), 9, '10:00:00');
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_TICKET_EN_BLOQUE, 'un ticket físico en la 10 impide el bloque ahora');

// 13. Ticket proyectado ya liberado: el bloque es estricto, el desborde no.
$conProyectado = contexto($fecha, $ahora, plano(), ocupar(range(1, 10), $hora), [['id' => 4, 'apertura' => '08:00:00', 'mesas' => [11]]]);
$plan = planear($conProyectado, 4, $hora);
asegurar($plan['ok'] && $plan['mesa_ids'] === [11], 'el desborde usa una mesa con liberación proyectada (como hoy)');
$plan = planear(contexto($fecha, $ahora, plano(), [], [['id' => 5, 'apertura' => '08:00:00', 'mesas' => [11]]]), 10, $hora);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_TICKET_EN_BLOQUE, 'el bloque no acepta un ticket proyectado');

// 14. Reserva ya iniciada en el bloque: no se mueve.
$plan = planear(contexto($fecha, $ahora, plano(), [['id' => 1401, 'hora' => '09:45:00', 'comensales' => 2, 'mesas' => [9]]]), 10, '10:30:00');
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_RESERVA_INICIADA, 'una reserva que ya empezó congela el bloque');

// 15. Determinismo: el orden de entrada no cambia el plan.
$reservasMezcla = [
    ['id' => 1502, 'hora' => '19:30:00', 'comensales' => 2, 'mesas' => [10]],
    ['id' => 1501, 'hora' => '19:00:00', 'comensales' => 2, 'mesas' => [9]],
];
$planA = planear(contexto($fecha, $ahora, plano(), $reservasMezcla), 10, $hora);
$planoInvertido = array_reverse(plano());
$planB = planear(contexto($fecha, $ahora, $planoInvertido, array_reverse($reservasMezcla)), 10, $hora);
asegurar($planA['mesa_ids'] === $planB['mesa_ids'] && $planA['reubicaciones'] === $planB['reubicaciones'], 'el plan es determinista');

// 16. Tope de búsqueda: termina aunque haya muchas desplazadas sin solución.
$muchas = [];
foreach ([8, 9, 10, 11] as $indice => $mesaId) {
    $muchas[] = ['id' => 1600 + $indice, 'hora' => '18:00:00', 'comensales' => 2, 'mesas' => [$mesaId]];
    $muchas[] = ['id' => 1610 + $indice, 'hora' => '19:30:00', 'comensales' => 2, 'mesas' => [$mesaId]];
}
$plan = planear(contexto($fecha, $ahora, plano(), array_merge($muchas, ocupar(range(2, 7), '18:45:00'))), 10, '18:45:00');
asegurar(!$plan['ok'], 'ocho desplazadas con una sola mesa interior libre no caben');

// 17. Límite de la asignación automática.
$plan = planear(contexto($fecha, $ahora, plano()), 13, $hora, [], 12);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_REQUIERE_MANUAL, 'por encima del máximo la asignación es manual');
$plan = planear(contexto($fecha, $ahora, plano()), 9, $hora, [], 8);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_REQUIERE_MANUAL, 'con máximo 8, nueve personas es manual');

// 18. Una mesa del exterior fuera de servicio rompe el bloque.
$plan = planear(contexto($fecha, $ahora, plano([11 => mesa(11, 'mesa', 'exterior', 4, 0)])), 10, $hora);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_BLOQUE_INCOMPLETO, 'sin la Mesa 11 no hay bloque');

// 19. Las barras nunca se asignan.
$plan = planear(contexto($fecha, $ahora, plano(), ocupar(range(1, 11), $hora)), 2, $hora);
asegurar(!$plan['ok'], 'con las 11 mesas ocupadas no se asigna una barra');

// 20. Una mesa sin zona: último recurso para una mesa suelta, nunca bloque ni destino.
$sinZona = plano();
$sinZona[] = mesa(17, 'mesa', null, 4);
$plan = planear(contexto($fecha, $ahora, $sinZona, ocupar(range(1, 11), $hora)), 2, $hora);
asegurar($plan['ok'] && $plan['mesa_ids'] === [17], 'una mesa sin zona entra al final');
$plan = planear(contexto($fecha, $ahora, $sinZona, array_merge(ocupar(range(1, 7), $hora), [['id' => 2001, 'hora' => $hora, 'comensales' => 2, 'mesas' => [9]]])), 10, $hora);
asegurar(!$plan['ok'] && $plan['motivo'] === Planificador::MOTIVO_SIN_REUBICACION, 'una mesa sin zona no recibe reubicaciones');

// 21. Al editar, la reserva propia no estorba.
$propia = [['id' => 2101, 'hora' => $hora, 'comensales' => 10, 'mesas' => [8, 9, 10, 11]]];
$plan = planear(contexto($fecha, $ahora, plano(), $propia), 11, $hora, [2101]);
asegurar($plan['ok'] && $plan['mesa_ids'] === [8, 9, 10, 11] && $plan['reubicaciones'] === [], 'editar un grupo conserva su bloque');

// 22. Dos solicitudes seguidas ven la ocupación de la anterior.
$primera = planear(contexto($fecha, $ahora, plano()), 2, $hora);
$segunda = planear(contexto($fecha, $ahora, plano(), [['id' => 2201, 'hora' => $hora, 'comensales' => 2, 'mesas' => $primera['mesa_ids']]]), 2, $hora);
asegurar($primera['mesa_ids'] === [1] && $segunda['mesa_ids'] === [2], 'la segunda solicitud toma la siguiente mesa');

// ── Agrupación canónica ──────────────────────────────────────────────
$porId = [];
foreach (plano() as $mesaPlano) {
    $porId[(int)$mesaPlano->id] = $mesaPlano;
}
$elegir = static fn(array $ids): array => array_map(static fn(int $id) => $porId[$id], $ids);
asegurar(Planificador::esAgrupacionCanonica($elegir([1]), 2, plano()), 'una mesa para 2 es canónica');
asegurar(Planificador::esAgrupacionCanonica($elegir([3, 4]), 6, plano()), '[3,4] para 6 es canónica');
asegurar(!Planificador::esAgrupacionCanonica($elegir([1, 2]), 6, plano()), '[1,2] no es una pareja canónica');
asegurar(Planificador::esAgrupacionCanonica($elegir([8, 9, 10, 11]), 10, plano()), 'el bloque para 10 es canónico');
asegurar(!Planificador::esAgrupacionCanonica($elegir([8, 9, 10]), 10, plano()), 'un bloque incompleto no es canónico');
asegurar(!Planificador::esAgrupacionCanonica($elegir([2, 4, 5]), 10, plano()), 'los tríos ya no son canónicos');
asegurar(!Planificador::esAgrupacionCanonica([$porId[12]], 2, plano()), 'una barra nunca es canónica');

// ── Propuesta para grupos mayores al máximo ──────────────────────────
$sugerir = static function (array $contexto, int $comensales) use ($hora): array {
    $evaluacion = OcupacionMesasService::evaluarHorarioConContexto($contexto, $hora, [], $contexto['ahora']);
    return Planificador::sugerirGrupoGrande($contexto['mesas_reservables'], $evaluacion, $comensales);
};
asegurar($sugerir(contexto($fecha, $ahora, plano()), 14) === [8, 9, 10, 11], 'para 14 se propone el exterior entero');
asegurar($sugerir(contexto($fecha, $ahora, plano()), 20) === [1, 8, 9, 10, 11], 'para 20, el exterior y luego el interior');
asegurar(
    $sugerir(contexto($fecha, $ahora, plano(), ocupar([9], $hora)), 14) === [1, 8, 10, 11],
    'una mesa ocupada del exterior se sustituye por una del interior'
);
asegurar($sugerir(contexto($fecha, $ahora, plano()), 50) === [], 'si lo libre no alcanza, no se propone nada');
asegurar(
    array_intersect($sugerir(contexto($fecha, $ahora, plano()), 44), [12, 13, 14, 15, 16]) === [],
    'barras, Caja y Llevar nunca se proponen'
);

fwrite(STDOUT, "Reservaciones: motor de asignación (mesa, pareja, bloque exterior y reubicación) OK\n");
