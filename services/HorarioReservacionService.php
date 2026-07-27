<?php

/**
 * Valida fechas, horarios configurados y disponibilidad de reservacion.
 */

namespace Services;

use DateTimeImmutable;

class HorarioReservacionService
{
    public const FECHA_INVALIDA = 'FECHA_INVALIDA';
    public const FECHA_PASADA = 'FECHA_PASADA';
    public const HORARIO_INVALIDO = 'HORARIO_INVALIDO';
    public const HORARIO_PASADO = 'HORARIO_PASADO';
    public const DIA_INACTIVO = 'DIA_INACTIVO';
    public const HORARIO_DISPONIBLE = 'HORARIO_DISPONIBLE';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    /**
     * Genera la proyección reservable desde el horario operativo canónico.
     * La última reservación comienza, como máximo, una hora antes del cierre.
     */
    public static function generarIntervalos(string $horaApertura, string $horaCierre): array
    {
        $aperturaSql = self::normalizarHoraSql($horaApertura);
        $cierreSql = self::normalizarHoraSql($horaCierre);
        if ($aperturaSql === '' || $cierreSql === '') {
            throw new \InvalidArgumentException('Las horas de apertura y cierre deben tener un formato válido.');
        }

        $apertura = DateTimeImmutable::createFromFormat('!H:i:s', $aperturaSql);
        $cierre = DateTimeImmutable::createFromFormat('!H:i:s', $cierreSql);
        if (!$apertura instanceof DateTimeImmutable || !$cierre instanceof DateTimeImmutable || $apertura >= $cierre) {
            throw new \InvalidArgumentException('La hora de apertura debe ser anterior a la hora de cierre.');
        }

        $limiteUltimaReservacion = $cierre->modify(
            '-' . ReservacionConfig::MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION . ' minutes'
        );
        $intervalos = [];
        for ($actual = $apertura; $actual <= $limiteUltimaReservacion; $actual = $actual->modify('+' . ReservacionConfig::INTERVALO_RESERVACION_MINUTOS . ' minutes')) {
            $intervalos[$actual->format('H:i:s')] = true;
        }

        return array_keys($intervalos);
    }

    public static function filtrarHorariosPasados(string $fecha, array $horarios): array
    {
        $normalizados = [];

        foreach ($horarios as $horario) {
            $valor = is_object($horario) ? (string) ($horario->hora ?? '') : (string) $horario;
            $horaSql = self::normalizarHoraSql($valor);

            if ($horaSql === '' || self::horarioPasadoHoy($fecha, $horaSql)) {
                continue;
            }

            $normalizados[$horaSql] = true;
        }

        $resultado = array_keys($normalizados);
        sort($resultado, SORT_STRING);

        return $resultado;
    }

    public static function fechaSeguraGet(string $fecha): string
    {
        return self::fechaValida($fecha) ? $fecha : self::hoy();
    }

    public static function fechaValida(string $fecha): bool
    {
        if ($fecha === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, ReservacionConfig::timezone());
        $errors = DateTimeImmutable::getLastErrors();
        $sinErrores = $errors === false
            || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0);

        return $date instanceof DateTimeImmutable
            && $sinErrores
            && $date->format('Y-m-d') === $fecha;
    }

    public static function normalizarHoraSql(string $hora): string
    {
        $hora = trim($hora);

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $hora, $matches) !== 1) {
            return '';
        }

        return $matches[1] . ':' . $matches[2] . ':' . ($matches[3] ?? '00');
    }

    public static function normalizarHoraCorta(string $hora): string
    {
        $horaSql = self::normalizarHoraSql($hora);

        return $horaSql !== '' ? substr($horaSql, 0, 5) : '';
    }

    public static function horaPorDefecto(array $horarios, string $fecha): string
    {
        $horasDisponibles = array_values(array_filter(array_map(static function ($horario): string {
            $valor = is_object($horario) ? (string)($horario->hora ?? '') : (string)$horario;
            return self::normalizarHoraCorta($valor);
        }, $horarios)));

        if (empty($horasDisponibles)) {
            return '';
        }

        if ($fecha === self::hoy()) {
            $horaActual = ReservacionConfig::ahora()->format('H:i');

            foreach ($horasDisponibles as $hora) {
                if ($hora >= $horaActual) {
                    return $hora;
                }
            }
        }

        return $horasDisponibles[0];
    }

    public static function hoy(): string
    {
        return ReservacionConfig::fechaActual();
    }

    public static function fechaPasada(string $fecha): bool
    {
        return self::fechaValida($fecha) && $fecha < self::hoy();
    }

    public static function horarioPasadoHoy(string $fecha, string $horaSql): bool
    {
        if ($fecha !== self::hoy() || $horaSql === '') {
            return false;
        }

        $horario = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $fecha . ' ' . $horaSql,
            ReservacionConfig::timezone()
        );

        if (!$horario instanceof DateTimeImmutable) {
            return true;
        }

        return $horario <= ReservacionConfig::ahora();
    }

}
