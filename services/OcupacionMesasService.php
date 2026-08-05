<?php

/**
 * Ocupación de mesas para el núcleo de reservaciones.
 *
 * La clase separa la ocupación planificada (reservacion_mesas) de la física
 * (ticket_mesas). Las consultas son de lectura; no expiran holds ni cierran
 * tickets como efecto secundario.
 */

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Mesa;
use Model\TicketMesa;

final class OcupacionMesasService
{
    public const CONTEXTO_ACTUAL = 'actual';
    public const CONTEXTO_PROYECTADO = 'proyectado';
    public const CONTEXTO_FUTURO = 'fecha_futura';
    public const CONTEXTO_HISTORICO = 'historico';

    /**
     * Devuelve el estado interno de todas las mesas para un intervalo.
     *
     * @return array<string, mixed>
     */
    public static function evaluarHorario(
        string $fecha,
        string $hora,
        int|array $excluirReservacionId = 0,
        bool $bloquear = false,
        ?array $ticketsAbiertos = null,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $horaSql = HorarioReservacionService::normalizarHoraSql($hora);
        $objetivo = self::fechaHora($fecha, $horaSql);
        if (!$objetivo) {
            return [
                'ok' => false,
                'contexto' => self::CONTEXTO_HISTORICO,
                'mesas' => [],
                'ocupacion_bloqueante' => [],
                'ocupacion_reservaciones' => [],
                'tickets_por_mesa' => [],
                'mesa_ids_disponibles' => [],
                'mesa_ids_proyectadas' => [],
                'tickets_ignorados' => [],
                'alertas_operativas' => [],
            ];
        }

        $contexto = self::contexto($objetivo, $ahora);
        $intervalo = [
            'inicio' => $objetivo,
            'fin' => $objetivo->modify('+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes'),
        ];
        $reservaciones = self::reservacionesDelDia($fecha, $excluirReservacionId, $ahora, $bloquear);
        $ocupacionReservaciones = self::ocupacionReservacionesEnIntervalo(
            $reservaciones,
            $intervalo,
            $excluirReservacionId
        );

        $tickets = $ticketsAbiertos ?? TicketMesa::abiertosParaMapa($bloquear);
        $evaluacionTickets = self::evaluarTickets($tickets, $fecha, $horaSql, $ahora);
        $mesas = Mesa::buscarTodasParaMapa();
        $porMesa = [];
        $alertas = [];

        foreach ($mesas as $mesa) {
            $mesaId = (int)$mesa->id;
            $elegible = self::mesaElegible($mesa);
            $estado = [
                'mesa_id' => $mesaId,
                'numero' => (int)$mesa->numero,
                'capacidad' => (int)$mesa->capacidad,
                'disponible' => false,
                'fuente' => $elegible ? 'libre' : 'no_reservable',
                'reservacion_id' => null,
                'ticket_id' => null,
                'tipo' => $elegible ? 'libre' : 'no_reservable',
                'liberacion_estimada' => null,
            ];

            if (isset($evaluacionTickets['por_mesa'][$mesaId])
                && $evaluacionTickets['por_mesa'][$mesaId]['bloquea_disponibilidad']
            ) {
                $ticket = $evaluacionTickets['por_mesa'][$mesaId];
                $estado = array_merge($estado, [
                    'disponible' => false,
                    'fuente' => 'ticket_abierto',
                    'tipo' => 'ticket_abierto',
                    'ticket_id' => (int)$ticket['ticket_id'],
                    'reservacion_id' => $ticket['reservacion_id'],
                    'liberacion_estimada' => $ticket['liberacion_estimada'],
                ]);
            }

            if (isset($ocupacionReservaciones[$mesaId])) {
                $reserva = $ocupacionReservaciones[$mesaId];
                // Un ticket físico gana sobre la asignación planificada. Si
                // no hay ticket bloqueante, la reservación sí ocupa la mesa.
                if ($estado['fuente'] !== 'ticket_abierto') {
                    $estado = array_merge($estado, [
                        'disponible' => false,
                        'fuente' => $reserva['fuente'],
                        'tipo' => $reserva['fuente'],
                        'reservacion_id' => (int)$reserva['reservacion_id'],
                    ]);
                }
            }

            if ($estado['fuente'] === 'libre' && $elegible) {
                $estado['disponible'] = true;
            }
            $porMesa[$mesaId] = $estado;
        }

        foreach ($evaluacionTickets['por_mesa'] as $mesaId => $ticket) {
            if (($ticket['tipo'] ?? '') === 'ticket_proyectado'
                && ($porMesa[$mesaId]['fuente'] ?? '') === 'libre'
            ) {
                $porMesa[$mesaId]['fuente'] = 'ticket_proyectado';
                $porMesa[$mesaId]['tipo'] = 'ticket_proyectado';
                $porMesa[$mesaId]['ticket_id'] = (int)$ticket['ticket_id'];
                $porMesa[$mesaId]['liberacion_estimada'] = $ticket['liberacion_estimada'];
                $porMesa[$mesaId]['disponible'] = true;
            }
        }

        foreach ($porMesa as $estado) {
            if ($estado['fuente'] === 'ticket_abierto' && $estado['reservacion_id'] !== null) {
                $alertas[] = [
                    'tipo' => 'ticket_abierto',
                    'mesa_id' => (int)$estado['mesa_id'],
                    'ticket_id' => (int)$estado['ticket_id'],
                    'reservacion_id' => (int)$estado['reservacion_id'],
                ];
            }
        }

        $disponibles = [];
        $proyectadas = [];
        foreach ($porMesa as $mesaId => $estado) {
            if ($estado['disponible']) {
                $disponibles[] = (int)$mesaId;
            }
            if ($estado['fuente'] === 'ticket_proyectado') {
                $proyectadas[] = (int)$mesaId;
            }
        }
        sort($disponibles, SORT_NUMERIC);
        sort($proyectadas, SORT_NUMERIC);

        $ocupacionBloqueante = [];
        foreach ($porMesa as $mesaId => $estado) {
            if (!$estado['disponible'] && $estado['fuente'] !== 'no_reservable') {
                $ocupacionBloqueante[(int)$mesaId] = $estado;
            }
        }

        return [
            'ok' => true,
            'fecha' => $fecha,
            'hora' => $horaSql,
            'contexto' => $contexto,
            'objetivo' => $objetivo->format('Y-m-d H:i:s'),
            'intervalo' => [
                'inicio' => $intervalo['inicio']->format('Y-m-d H:i:s'),
                'fin' => $intervalo['fin']->format('Y-m-d H:i:s'),
            ],
            'mesas' => $porMesa,
            'ocupacion_bloqueante' => $ocupacionBloqueante,
            'ocupacion_reservaciones' => $ocupacionReservaciones,
            'tickets_por_mesa' => $evaluacionTickets['por_mesa'],
            'tickets_bloqueantes' => $evaluacionTickets['bloqueantes'],
            'ocupacion_fisica' => $evaluacionTickets['fisica'],
            'mesa_ids_disponibles' => $disponibles,
            'mesa_ids_proyectadas' => $proyectadas,
            'mesas_proyectadas' => $proyectadas,
            'tickets_ignorados' => $evaluacionTickets['ignorados'],
            'alertas_operativas' => $alertas,
        ];
    }

    /**
     * Clasifica tickets abiertos sin alterar su estado. Sólo los tickets del
     * día actual participan en una consulta del día actual; una fecha futura
     * ignora los tickets abiertos actuales.
     *
     * @param array<int, array<string, mixed>|object> $tickets
     * @return array<string, mixed>
     */
    public static function evaluarTickets(
        array $tickets,
        string $fecha,
        string $hora,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $objetivo = self::fechaHora($fecha, $hora);
        $porMesa = [];
        $fisica = [];
        $bloqueantes = [];
        $ignorados = [];
        $contexto = $objetivo ? self::contexto($objetivo, $ahora) : self::CONTEXTO_HISTORICO;

        foreach ($tickets as $raw) {
            $ticket = is_array($raw) ? $raw : get_object_vars($raw);
            $ticketId = (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0);
            $mesaIds = self::normalizarIds((array)($ticket['mesa_ids'] ?? []));
            if ($ticketId < 1 || $mesaIds === []) {
                continue;
            }

            $apertura = self::fechaHoraTicket((string)($ticket['hora_apertura'] ?? ''));
            $liberacion = $apertura?->modify(
                '+' . ReservacionConfig::DURACION_ESTIMADA_TICKET_MINUTOS . ' minutes'
            )->modify(
                '+' . ReservacionConfig::RETRASO_ESTIMADO_TICKET_MINUTOS . ' minutes'
            );
            $aplicaFecha = $fecha === $ahora->format('Y-m-d');
            $objetivoFuturo = $objetivo instanceof DateTimeImmutable && $objetivo > $ahora;
            $proyectado = $aplicaFecha
                && $objetivoFuturo
                && $liberacion instanceof DateTimeImmutable
                && $liberacion <= $objetivo;
            $bloquea = $aplicaFecha && !$proyectado;
            $tipo = $proyectado ? 'ticket_proyectado' : 'ticket_abierto';
            $resumen = [
                'ticket_id' => $ticketId,
                'reservacion_id' => !empty($ticket['reservacion_id']) ? (int)$ticket['reservacion_id'] : null,
                'origen' => (string)($ticket['origen'] ?? (!empty($ticket['reservacion_id']) ? 'reservacion' : 'walk_in')),
                'walk_in' => empty($ticket['reservacion_id']),
                'hora_apertura' => (string)($ticket['hora_apertura'] ?? ''),
                'mesa_ids' => $mesaIds,
                'ocupada_fisicamente' => true,
                'aplica_fecha' => $aplicaFecha,
                'bloquea_disponibilidad' => $bloquea,
                'disponible_proyectada' => $proyectado,
                'tipo' => $tipo,
                'estado_proyeccion' => !$aplicaFecha ? 'ignorado_fecha' : ($proyectado ? 'liberado_proyectado' : 'ocupada'),
                'liberacion_estimada' => $liberacion?->format('Y-m-d H:i:s'),
            ];
            $fisica[] = $resumen;
            if (!$aplicaFecha) {
                $ignorados[] = $resumen;
                continue;
            }
            if ($bloquea) {
                $bloqueantes[] = $resumen;
            }
            foreach ($mesaIds as $mesaId) {
                if (!isset($porMesa[$mesaId]) || $bloquea) {
                    $porMesa[$mesaId] = ['mesa_id' => $mesaId] + $resumen;
                }
            }
        }

        ksort($porMesa, SORT_NUMERIC);
        return [
            'contexto' => $contexto,
            'por_mesa' => $porMesa,
            'fisica' => $fisica,
            'bloqueantes' => $bloqueantes,
            'ignorados' => $ignorados,
            'mesas_proyectadas' => array_values(array_map(
                'intval',
                array_keys(array_filter($porMesa, static fn(array $ticket): bool => $ticket['tipo'] === 'ticket_proyectado'))
            )),
        ];
    }

    /**
     * Compatibilidad pura para lectores existentes. Usa el intervalo canónico
     * y no el antiguo bloqueo previo de 30 minutos.
     */
    public static function ocupacionReservacionesEnVentana(
        array $asignaciones,
        string $hora,
        int $excluirReservacionId = 0
    ): array {
        $inicio = self::horaMinutos($hora);
        if ($inicio === null) {
            return [];
        }
        $resultado = [];
        foreach ($asignaciones as $asignacion) {
            if ($excluirReservacionId > 0 && (int)($asignacion['reservacion_id'] ?? 0) === $excluirReservacionId) {
                continue;
            }
            $horaReserva = self::horaMinutos((string)($asignacion['hora'] ?? ''));
            if ($horaReserva === null || !self::traslapaMinutos($horaReserva, $inicio)) {
                continue;
            }
            $mesaId = (int)($asignacion['mesa_id'] ?? 0);
            if ($mesaId < 1) {
                continue;
            }
            $resultado[$mesaId] = [
                'mesa_id' => $mesaId,
                'reservacion_id' => (int)($asignacion['reservacion_id'] ?? 0),
                'fuente' => ($asignacion['estado'] ?? '') === ReservacionConfig::ESTADO_RETENCION_PENDIENTE
                    ? 'hold'
                    : 'reservacion',
                'estado' => (string)($asignacion['estado'] ?? ''),
                'hora' => (string)($asignacion['hora'] ?? ''),
            ];
        }
        return $resultado;
    }

    /** @return array<string, mixed> */
    public static function resumenCapacidad(array $mesas, array $evaluacion): array
    {
        $disponibles = array_fill_keys(array_map('intval', (array)($evaluacion['mesa_ids_disponibles'] ?? [])), true);
        $proyectadas = array_fill_keys(array_map('intval', (array)($evaluacion['mesa_ids_proyectadas'] ?? [])), true);
        $total = 0;
        $libre = 0;
        $libreProyectada = 0;
        $ids = [];
        $idsProyectados = [];
        foreach ($mesas as $mesa) {
            $id = (int)($mesa->id ?? 0);
            $capacidad = (int)($mesa->capacidad ?? 0);
            $total += $capacidad;
            if (isset($disponibles[$id])) {
                $libre += $capacidad;
                $ids[] = $id;
            }
            if (isset($proyectadas[$id])) {
                $libreProyectada += $capacidad;
                $idsProyectados[] = $id;
            }
        }
        return [
            'capacidad_total' => $total,
            'capacidad_realmente_libre' => $libre - $libreProyectada,
            'capacidad_proyectada' => $libreProyectada,
            'capacidad_estimada_horario' => $libre,
            'mesa_ids_estimadas' => $ids,
            'mesa_ids_proyectadas' => $idsProyectados,
            'depende_liberacion_proyectada' => $idsProyectados !== [],
        ];
    }

    /** @return array<int, object> */
    public static function seleccionarAgrupacionAutorizada(
        array $mesas,
        int $comensales,
        array $mesaIdsProyectadas = []
    ): array
    {
        return AsignacionMesasService::seleccionarMesasPublicas(
            $mesas,
            $comensales,
            $mesaIdsProyectadas
        );
    }

    public static function agrupacionValida(array $mesas, int $comensales): bool
    {
        if ($mesas === []) {
            return false;
        }
        foreach ($mesas as $mesa) {
            if (!self::mesaElegible($mesa)) {
                return false;
            }
        }
        return array_sum(array_map(static fn($mesa): int => (int)($mesa->capacidad ?? 0), $mesas)) >= $comensales;
    }

    /** @return array<int, array<string, mixed>> */
    private static function reservacionesDelDia(
        string $fecha,
        int|array $excluirReservacionId,
        DateTimeImmutable $ahora,
        bool $bloquear
    ): array {
        $db = ActiveRecord::getDB();
        if (!$db) {
            throw new \RuntimeException('No hay conexión para consultar ocupación.');
        }
        $fechaSql = $db->real_escape_string($fecha);
        $ahoraSql = $db->real_escape_string($ahora->format('Y-m-d H:i:s'));
        $exclusiones = self::normalizarExclusiones($excluirReservacionId);
        $excluir = $exclusiones !== []
            ? 'AND r.id NOT IN (' . implode(',', $exclusiones) . ')'
            : '';
        $lock = $bloquear ? ' FOR UPDATE' : '';
        $sql = "SELECT rm.mesa_id, r.id AS reservacion_id, r.nombre, r.contacto,
                       r.fecha, r.hora, r.comensales, r.estado, r.hold_expires_at,
                       CASE WHEN r.estado = 'pendiente_verificacion' THEN 'hold'
                            ELSE 'reservacion' END AS fuente
                FROM reservacion_mesas rm
                INNER JOIN reservaciones r ON r.id = rm.reservacion_id
                WHERE r.fecha = '{$fechaSql}'
                  {$excluir}
                  AND (
                    (r.estado = 'pendiente_verificacion'
                     AND r.hold_expires_at IS NOT NULL
                     AND r.hold_expires_at > '{$ahoraSql}')
                    OR
                    (r.estado = 'confirmada'
                     AND NOT EXISTS (
                       SELECT 1 FROM tickets t
                       WHERE t.reservacion_id = r.id
                         AND " . \Model\TicketMesa::condicionSqlAbierto('t') . "))
                  )
                ORDER BY r.hora ASC, rm.mesa_id ASC{$lock}";
        $resultado = $db->query($sql);
        if (!$resultado) {
            throw new \RuntimeException($db->error);
        }
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = [
                'mesa_id' => (int)$fila['mesa_id'],
                'reservacion_id' => (int)$fila['reservacion_id'],
                'nombre' => (string)$fila['nombre'],
                'contacto' => (string)($fila['contacto'] ?? ''),
                'fecha' => (string)$fila['fecha'],
                'hora' => (string)$fila['hora'],
                'comensales' => (int)$fila['comensales'],
                'estado' => (string)$fila['estado'],
                'hold_expires_at' => $fila['hold_expires_at'] !== null ? (string)$fila['hold_expires_at'] : null,
                'fuente' => (string)$fila['fuente'],
            ];
        }
        $resultado->free();
        return $filas;
    }

    /** @return array<int, array<string, mixed>> */
    private static function ocupacionReservacionesEnIntervalo(
        array $asignaciones,
        array $intervalo,
        int|array $excluirReservacionId = 0
    ): array
    {
        $exclusiones = array_fill_keys(self::normalizarExclusiones($excluirReservacionId), true);
        $resultado = [];
        $inicio = $intervalo['inicio'];
        $fin = $intervalo['fin'];
        foreach ($asignaciones as $asignacion) {
            if (isset($exclusiones[(int)($asignacion['reservacion_id'] ?? 0)])) {
                continue;
            }
            $reserva = self::fechaHora((string)$asignacion['fecha'], (string)$asignacion['hora']);
            if (!$reserva) {
                continue;
            }
            $reservaFin = $reserva->modify('+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes');
            if ($reserva < $fin && $reservaFin > $inicio) {
                $resultado[(int)$asignacion['mesa_id']] = $asignacion;
            }
        }
        return $resultado;
    }

    /** @return array<int, int> */
    private static function normalizarExclusiones(int|array $ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private static function mesaElegible($mesa): bool
    {
        return (int)($mesa->activo ?? 0) === 1
            && (int)($mesa->reservable ?? 0) === 1
            && (string)($mesa->tipo ?? '') === 'mesa'
            && (int)($mesa->capacidad ?? 0) > 0;
    }

    private static function contexto(DateTimeImmutable $objetivo, DateTimeImmutable $ahora): string
    {
        if ($objetivo->format('Y-m-d') < $ahora->format('Y-m-d')) {
            return self::CONTEXTO_HISTORICO;
        }
        if ($objetivo->format('Y-m-d') > $ahora->format('Y-m-d')) {
            return self::CONTEXTO_FUTURO;
        }
        return $objetivo <= $ahora ? self::CONTEXTO_ACTUAL : self::CONTEXTO_PROYECTADO;
    }

    private static function fechaHora(string $fecha, string $hora): ?DateTimeImmutable
    {
        $hora = HorarioReservacionService::normalizarHoraSql($hora);
        if (!HorarioReservacionService::fechaValida($fecha) || $hora === '') {
            return null;
        }
        $resultado = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $fecha . ' ' . $hora,
            ReservacionConfig::timezone()
        );
        $errores = DateTimeImmutable::getLastErrors();
        return $resultado instanceof DateTimeImmutable
            && ($errores === false || (($errores['warning_count'] ?? 0) === 0 && ($errores['error_count'] ?? 0) === 0))
            ? $resultado
            : null;
    }

    private static function fechaHoraTicket(string $valor): ?DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($valor, ReservacionConfig::timezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function horaMinutos(string $hora): ?int
    {
        $hora = HorarioReservacionService::normalizarHoraSql($hora);
        if ($hora === '') {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', substr($hora, 0, 5)));
        return $h * 60 + $m;
    }

    private static function traslapaMinutos(int $a, int $b): bool
    {
        $duracion = ReservacionConfig::DURACION_RESERVACION_MINUTOS;
        $previo = ReservacionConfig::BLOQUEO_PREVIO_MESA_MINUTOS;
        return ($a - $previo) < ($b + $duracion)
            && ($b - $previo) < ($a + $duracion);
    }

    /** @return array<int, int> */
    private static function normalizarIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
