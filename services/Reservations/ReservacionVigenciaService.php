<?php

/**
 * Clasifica la condición operativa derivada de una reservación.
 *
 * Los estados continúan siendo persistidos en `reservaciones.estado`; conceptos
 * como tolerancia vencida, visibilidad o influencia en capacidad son capacidades
 * calculadas y nunca deben guardarse como un estado adicional.
 */

namespace Services\Reservations;

use DateTimeImmutable;
use Services\Scheduling\HorarioOperacionService;

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
                // Una ausencia pendiente es una decisión operativa, no una
                // nueva reservación. Debe permanecer visible para registrar el
                // no-show aunque el selector de horas ya haya pasado el bloque
                // en que fue programada.
                if (self::valor($reservacion, 'accion_pendiente', '') === 'REGISTRAR_AUSENCIA') {
                    return true;
                }
                $hora = HorarioReservacionService::normalizarHoraCorta(
                    (string)self::valor($reservacion, 'hora', '')
                );

                return $hora >= $horaInicio && $hora <= $horaFin;
            }
        ));
    }

    /**
     * @param array<string, mixed>|object $reservacion
     * @return array<string, mixed>
     */
    public static function resolverVentanaOperativa(
        $reservacion,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $fechaHora = self::fechaHoraProgramada($reservacion);
        if (!$fechaHora) {
            return [
                'estado' => 'futura',
                'minutos_restantes' => null,
                'minutos_retraso' => 0,
            ];
        }

        $segundosRestantes = $fechaHora->getTimestamp() - $ahora->getTimestamp();
        $minutosRestantes = (int)ceil($segundosRestantes / 60);
        $minutosRetraso = $segundosRestantes < 0
            ? (int)ceil(abs($segundosRestantes) / 60)
            : 0;
        $estado = match (true) {
            $segundosRestantes > ReservacionConfig::AVISO_RESERVACION_PROXIMA_MINUTOS * 60
                => 'futura',
            $segundosRestantes > ReservacionConfig::BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS * 60
                => 'advertencia',
            $segundosRestantes >= 0 => 'bloqueo',
            $segundosRestantes >= -ReservacionConfig::TOLERANCIA_LLEGADA_MINUTOS * 60
                => 'tolerancia',
            default => 'ausencia_pendiente',
        };

        return [
            'estado' => $estado,
            'minutos_restantes' => $minutosRestantes,
            'minutos_retraso' => $minutosRetraso,
            'inicio' => $fechaHora->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Sin punto de venta no hay señal de llegada: una confirmada ocupa sus
     * mesas durante todo su intervalo, y pasada la tolerancia queda a la
     * espera de que el anfitrión la resuelva como completada o no-show.
     *
     * @param array<string, mixed>|object $reservacion
     * @return array<string, mixed>
     */
    public static function clasificar(
        $reservacion,
        ?DateTimeImmutable $ahora = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $estado = (string)self::valor($reservacion, 'estado', '');
        $holdExpiresAt = self::fechaOpcional(self::valor($reservacion, 'hold_expires_at'));
        $fechaReservacion = trim((string)self::valor($reservacion, 'fecha', ''));
        $fechaActualRestaurante = self::fechaActualRestaurante($ahora);
        $fechaHora = self::fechaHoraProgramada($reservacion);
        $limiteTolerancia = $fechaHora?->modify(
            '+' . ReservacionConfig::TOLERANCIA_LLEGADA_MINUTOS . ' minutes'
        );

        $holdVigente = $estado === ReservacionConfig::ESTADO_RETENCION_PENDIENTE
            && $holdExpiresAt instanceof DateTimeImmutable
            && $holdExpiresAt > $ahora;
        $confirmadaVigente = $estado === 'confirmada';
        $toleranciaVencida = $confirmadaVigente
            && $limiteTolerancia instanceof DateTimeImmutable
            && $ahora > $limiteTolerancia;
        $dentroTolerancia = $confirmadaVigente
            && $fechaHora instanceof DateTimeImmutable
            && $ahora >= $fechaHora
            && $limiteTolerancia instanceof DateTimeImmutable
            && $ahora <= $limiteTolerancia;
        $ausenciaPendiente = $toleranciaVencida;
        $influyeDisponibilidad = $holdVigente || $confirmadaVigente;
        $visibleCliente = $confirmadaVigente
            && $fechaReservacion !== ''
            && $fechaReservacion >= $fechaActualRestaurante;
        $cuentaLimite = $visibleCliente;
        $ventana = self::resolverVentanaOperativa($reservacion, $ahora);
        // Completar es registrar que la mesa se atendió: tiene sentido desde la
        // hora programada en adelante, no antes.
        $puedeCompletar = $confirmadaVigente
            && $fechaHora instanceof DateTimeImmutable
            && $ahora >= $fechaHora;
        $antesODuranteHora = !($fechaHora instanceof DateTimeImmutable) || $ahora <= $fechaHora;

        return [
            'cuenta_limite' => $cuentaLimite,
            'visible_cliente' => $visibleCliente,
            'dentro_tolerancia' => $dentroTolerancia,
            'influye_disponibilidad' => $influyeDisponibilidad,
            'reservacion_influye_en_disponibilidad' => $influyeDisponibilidad,
            'visible_operacion' => in_array($estado, ReservacionConfig::estadosPermitidos(), true),
            'editable' => $holdVigente || ($confirmadaVigente && $antesODuranteHora),
            'elegible_no_show' => $ausenciaPendiente,
            'puede_marcar_no_show' => $ausenciaPendiente,
            'puede_completar' => $puedeCompletar,
            'tolerancia_vencida' => $toleranciaVencida,
            'ausencia_pendiente' => $ausenciaPendiente,
            'ventana_operativa' => $ventana,
            'hold_vigente' => $holdVigente,
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
        return "(
            (
                {$alias}.estado = '" . ReservacionConfig::ESTADO_RETENCION_PENDIENTE . "'
                AND {$alias}.hold_expires_at IS NOT NULL
                AND {$alias}.hold_expires_at > {$instante}
            )
            OR {$alias}.estado = 'confirmada'
        )";
    }

    public static function fechaActualRestaurante(?DateTimeImmutable $ahora = null): string
    {
        return ($ahora ?? ReservacionConfig::ahora())->format('Y-m-d');
    }

    public static function condicionSqlVisibleCliente(
        string $alias = 'r',
        ?DateTimeImmutable $ahora = null
    ): string {
        self::validarAlias($alias);
        $fechaActual = self::fechaActualRestaurante($ahora);
        return "(
            (
                {$alias}.estado = 'confirmada'
                AND {$alias}.fecha >= '{$fechaActual}'
            )
        )";
    }

    public static function condicionSqlCuentaLimite(
        string $alias = 'r',
        ?DateTimeImmutable $ahora = null
    ): string {
        self::validarAlias($alias);
        $instante = self::instanteSql($ahora);
        $fechaActual = self::fechaActualRestaurante($ahora);
        return "(
            (
                {$alias}.estado = 'pendiente_verificacion'
                AND {$alias}.reemplaza_reservacion_id IS NULL
                AND {$alias}.fecha >= '{$fechaActual}'
                AND {$alias}.hold_expires_at IS NOT NULL
                AND {$alias}.hold_expires_at > {$instante}
            )
            OR (
                {$alias}.estado = 'confirmada'
                AND {$alias}.fecha >= '{$fechaActual}'
            )
        )";
    }

    /**
     * Interpreta la fecha y hora de una reservación con la zona horaria canónica.
     * La usan las políticas temporales que necesitan el instante programado.
     *
     * @param array<string, mixed>|object $reservacion
     */
    public static function fechaHoraProgramada($reservacion): ?DateTimeImmutable
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
