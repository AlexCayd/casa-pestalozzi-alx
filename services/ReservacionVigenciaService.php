<?php

/**
 * Clasifica la condición operativa derivada de una reservación.
 *
 * Los estados continúan siendo persistidos en `reservaciones.estado`; conceptos
 * como tolerancia vencida, visibilidad o influencia en capacidad son capacidades
 * calculadas y nunca deben guardarse como un estado adicional.
 */

namespace Services;

use DateTimeImmutable;
use Model\TicketMesa;

final class ReservacionVigenciaService
{
    /**
     * Conserva pendientes del bloque inmediatamente anterior, del actual y de
     * todos los posteriores. Los estados finales nunca vuelven a la cola.
     *
     * @param array<int, array<string, mixed>|object> $reservaciones
     * @param array<int, string> $horarios
     * @return array<int, array<string, mixed>|object>
     */
    public static function filtrarPendientesOperacion(
        array $reservaciones,
        string $fecha,
        array $horarios,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $pendientes = array_values(array_filter(
            $reservaciones,
            static function ($reservacion): bool {
                return in_array(
                    (string)self::valor($reservacion, 'estado', ''),
                    ReservacionConfig::ESTADOS_LISTA_OPERATIVA,
                    true
                );
            }
        ));

        if ($pendientes === [] || $fecha !== $ahora->format('Y-m-d')) {
            return $pendientes;
        }

        $horas = array_values(array_unique(array_filter(array_map(
            static fn($hora): string => HorarioReservacionService::normalizarHoraCorta(
                (string)$hora
            ),
            $horarios
        ))));
        // Los endpoints de disponibilidad omiten horas vencidas del día. Para
        // la lista operativa se reconstruye la jornada completa desde el mismo
        // horario efectivo, de modo que el bloque actual y el anterior no
        // desaparezcan por haber pasado el reloj.
        try {
            $horarioEfectivo = HorarioOperacionService::obtenerHorarioEfectivo($fecha);
            if (
                ($horarioEfectivo['abierto'] ?? false)
                && !empty($horarioEfectivo['hora_apertura'])
                && !empty($horarioEfectivo['hora_cierre'])
            ) {
                $horasCompletas = HorarioReservacionService::generarIntervalos(
                    (string)$horarioEfectivo['hora_apertura'],
                    (string)$horarioEfectivo['hora_cierre']
                );
                if ($horasCompletas !== []) {
                    $horas = array_map(
                        static fn(string $hora): string => substr($hora, 0, 5),
                        $horasCompletas
                    );
                }
            }
        } catch (\Throwable $error) {
            // El listado aún puede resolver un rango seguro con los horarios
            // ya entregados por el llamador.
        }
        sort($horas, SORT_STRING);
        if ($horas === []) {
            $horas = array_values(array_unique(array_filter(array_map(
                static fn($reservacion): string => HorarioReservacionService::normalizarHoraCorta(
                    (string)self::valor($reservacion, 'hora', '')
                ),
                $pendientes
            ))));
            sort($horas, SORT_STRING);
        }
        if ($horas === []) {
            return $pendientes;
        }

        $horaActual = $ahora->format('H:i');
        $indiceActual = 0;
        foreach ($horas as $indice => $hora) {
            if ($hora <= $horaActual) {
                $indiceActual = $indice;
                continue;
            }
            break;
        }
        $horaInicio = $horas[max(0, $indiceActual - 1)];
        $horaFin = $horas[count($horas) - 1];

        return array_values(array_filter(
            $pendientes,
            static function ($reservacion) use ($horaInicio, $horaFin): bool {
                $hora = HorarioReservacionService::normalizarHoraCorta(
                    (string)self::valor($reservacion, 'hora', '')
                );

                return $hora >= $horaInicio && $hora <= $horaFin;
            }
        ));
    }

    /**
     * @param array<string, mixed>|object $reservacion
     * @param array<string, mixed>|object|null $ticket
     * @return array<string, mixed>
     */
    public static function clasificar(
        $reservacion,
        ?DateTimeImmutable $ahora = null,
        $ticket = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $estado = (string)self::valor($reservacion, 'estado', '');
        $holdExpiresAt = self::fechaOpcional(self::valor($reservacion, 'hold_expires_at'));
        $arrivedAt = self::fechaOpcional(self::valor($reservacion, 'arrived_at'));
        $fechaHora = self::fechaHoraProgramada($reservacion);
        $limiteTolerancia = $fechaHora?->modify(
            '+' . ReservacionConfig::TOLERANCIA_RESERVACION_MINUTOS . ' minutes'
        );

        $ticketEstado = (string)self::valor(
            $ticket,
            'estado',
            self::valor($reservacion, 'ticket_estado', '')
        );
        $ticketClosedAt = self::valor(
            $ticket,
            'closed_at',
            self::valor($reservacion, 'ticket_closed_at')
        );
        $ticketId = (int)self::valor(
            $ticket,
            'id',
            self::valor($reservacion, 'ticket_id', 0)
        );
        $ticketAbiertoExplicito = self::valor(
            $reservacion,
            'ticket_abierto',
            self::valor($ticket, 'ticket_abierto')
        );
        $ticketAbierto = $ticketAbiertoExplicito !== null
            ? filter_var($ticketAbiertoExplicito, FILTER_VALIDATE_BOOL)
            : ($ticketId > 0 && $ticketEstado === TicketMesa::ESTADO_ABIERTO && $ticketClosedAt === null);

        $holdVigente = $estado === ReservacionConfig::ESTADO_RETENCION_PENDIENTE
            && $holdExpiresAt instanceof DateTimeImmutable
            && $holdExpiresAt > $ahora;
        $tieneLlegada = $arrivedAt instanceof DateTimeImmutable || in_array($estado, ['llego', 'en_curso'], true);
        $tieneEvidenciaFisica = $tieneLlegada || $ticketAbierto;
        $toleranciaVencida = $estado === 'confirmada'
            && !$tieneEvidenciaFisica
            && $limiteTolerancia instanceof DateTimeImmutable
            && $ahora >= $limiteTolerancia;
        $confirmadaVigente = $estado === 'confirmada' && (!$toleranciaVencida || $tieneEvidenciaFisica);
        $operativaPersistida = in_array($estado, ['llego', 'en_curso'], true);
        $influyeDisponibilidad = $holdVigente || $confirmadaVigente || $operativaPersistida;
        $visibleCliente = $confirmadaVigente || $operativaPersistida;
        $cuentaLimite = $visibleCliente;
        $elegibleNoShow = $estado === 'confirmada'
            && $toleranciaVencida
            && !$tieneLlegada
            && !$ticketAbierto;
        $puedeConfirmarLlegada = $estado === 'confirmada'
            && !$tieneLlegada
            && !$ticketAbierto;
        $inconsistenciaRecuperable = (
            $estado === 'confirmada'
            && $tieneEvidenciaFisica
        ) || (
            $estado === 'llego'
            && $ticketAbierto
        );
        $antesODuranteHora = !($fechaHora instanceof DateTimeImmutable) || $ahora <= $fechaHora;

        return [
            'cuenta_limite' => $cuentaLimite,
            'visible_cliente' => $visibleCliente,
            'influye_disponibilidad' => $influyeDisponibilidad,
            'visible_operacion' => in_array($estado, ReservacionConfig::estadosPermitidos(), true),
            'editable' => !$ticketAbierto && (
                $holdVigente
                || ($confirmadaVigente && $antesODuranteHora)
                || ($estado === 'llego' && $antesODuranteHora)
            ),
            'elegible_no_show' => $elegibleNoShow,
            'tolerancia_vencida' => $toleranciaVencida,
            'puede_confirmar_llegada' => $puedeConfirmarLlegada,
            'llegada_tardia' => $puedeConfirmarLlegada && $toleranciaVencida,
            'hold_vigente' => $holdVigente,
            'ticket_abierto' => $ticketAbierto,
            'tiene_llegada' => $tieneLlegada,
            'inconsistencia_recuperable' => $inconsistenciaRecuperable,
            'limite_tolerancia' => $limiteTolerancia?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Condición SQL equivalente a `influye_disponibilidad`.
     *
     * El instante se toma del reloj PHP para evitar diferencias de zona horaria
     * entre la conexión de MySQL y el servidor de aplicación.
     */
    public static function condicionSqlInfluyeDisponibilidad(
        string $alias = 'r',
        ?DateTimeImmutable $ahora = null
    ): string {
        self::validarAlias($alias);
        $instante = self::instanteSql($ahora);
        $ticketAbierto = self::condicionSqlTieneTicketAbierto($alias);

        return "(
            (
                {$alias}.estado = '" . ReservacionConfig::ESTADO_RETENCION_PENDIENTE . "'
                AND {$alias}.hold_expires_at IS NOT NULL
                AND {$alias}.hold_expires_at > {$instante}
            )
            OR (
                {$alias}.estado = 'confirmada'
                AND (
                    {$alias}.arrived_at IS NOT NULL
                    OR {$ticketAbierto}
                    OR TIMESTAMP({$alias}.fecha, {$alias}.hora)
                        + INTERVAL " . ReservacionConfig::TOLERANCIA_RESERVACION_MINUTOS . " MINUTE
                        > {$instante}
                )
            )
            OR {$alias}.estado IN ('llego', 'en_curso')
        )";
    }

    public static function condicionSqlVisibleCliente(
        string $alias = 'r',
        ?DateTimeImmutable $ahora = null
    ): string {
        self::validarAlias($alias);
        $instante = self::instanteSql($ahora);
        $ticketAbierto = self::condicionSqlTieneTicketAbierto($alias);

        return "(
            (
                {$alias}.estado = 'confirmada'
                AND (
                    {$alias}.arrived_at IS NOT NULL
                    OR {$ticketAbierto}
                    OR TIMESTAMP({$alias}.fecha, {$alias}.hora)
                        + INTERVAL " . ReservacionConfig::TOLERANCIA_RESERVACION_MINUTOS . " MINUTE
                        > {$instante}
                )
            )
            OR {$alias}.estado IN ('llego', 'en_curso')
        )";
    }

    public static function condicionSqlCuentaLimite(
        string $alias = 'r',
        ?DateTimeImmutable $ahora = null
    ): string {
        return self::condicionSqlVisibleCliente($alias, $ahora);
    }

    public static function condicionSqlTieneTicketAbierto(string $reservacionAlias = 'r'): string
    {
        self::validarAlias($reservacionAlias);

        return "EXISTS (
            SELECT 1
            FROM tickets vigencia_ticket
            WHERE vigencia_ticket.reservacion_id = {$reservacionAlias}.id
              AND " . TicketMesa::condicionSqlAbierto('vigencia_ticket') . "
        )";
    }

    private static function fechaHoraProgramada($reservacion): ?DateTimeImmutable
    {
        $fecha = trim((string)self::valor($reservacion, 'fecha', ''));
        $hora = trim((string)self::valor($reservacion, 'hora', ''));
        if ($fecha === '' || $hora === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($fecha . ' ' . $hora, ReservacionConfig::timezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function fechaOpcional($valor): ?DateTimeImmutable
    {
        if ($valor instanceof DateTimeImmutable) {
            return $valor;
        }
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valor, ReservacionConfig::timezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function instanteSql(?DateTimeImmutable $ahora): string
    {
        return "'" . ($ahora ?? ReservacionConfig::ahora())->format('Y-m-d H:i:s') . "'";
    }

    private static function validarAlias(string $alias): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Alias SQL de reservación inválido.');
        }
    }

    private static function valor($origen, string $campo, $default = null)
    {
        if (is_array($origen)) {
            return array_key_exists($campo, $origen) ? $origen[$campo] : $default;
        }
        if (is_object($origen) && isset($origen->{$campo})) {
            return $origen->{$campo};
        }

        return $default;
    }
}
