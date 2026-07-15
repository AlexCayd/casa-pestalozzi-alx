<?php

/**
 * Valida fechas, horarios configurados y disponibilidad de reservacion.
 */

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\HorarioReservacion;

class HorarioReservacionService
{
    public const FECHA_INVALIDA = 'FECHA_INVALIDA';
    public const FECHA_PASADA = 'FECHA_PASADA';
    public const HORARIO_INVALIDO = 'HORARIO_INVALIDO';
    public const HORARIO_PASADO = 'HORARIO_PASADO';
    public const DIA_INACTIVO = 'DIA_INACTIVO';
    public const HORARIO_DISPONIBLE = 'HORARIO_DISPONIBLE';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    public static function validarHorarioReservacion($fecha, $hora): array
    {
        $fecha = (string)$fecha;
        $horaSql = self::normalizarHoraSql((string)$hora);

        if (!self::fechaValida($fecha)) {
            return ['ok' => false, 'codigo' => self::FECHA_INVALIDA];
        }

        if (self::fechaPasada($fecha)) {
            return ['ok' => false, 'codigo' => self::FECHA_PASADA, 'fecha' => $fecha];
        }

        if ($horaSql === '') {
            return ['ok' => false, 'codigo' => self::HORARIO_INVALIDO, 'fecha' => $fecha];
        }

        try {
            $diaSemana = (int)(new DateTimeImmutable($fecha))->format('w');
            $db = ActiveRecord::getDB();
            $dia = self::diaReservacion($diaSemana);

            if (!$dia || (int)$dia['activo'] !== 1) {
                return ['ok' => false, 'codigo' => self::DIA_INACTIVO, 'fecha' => $fecha, 'hora' => $horaSql];
            }

            $existe = self::existeHorario($diaSemana, $horaSql);

            if (!$existe) {
                return ['ok' => false, 'codigo' => self::HORARIO_INVALIDO, 'fecha' => $fecha, 'hora' => $horaSql];
            }

            if (self::horarioPasadoHoy($fecha, $horaSql)) {
                return ['ok' => false, 'codigo' => self::HORARIO_PASADO, 'fecha' => $fecha, 'hora' => $horaSql];
            }

            return [
                'ok' => true,
                'codigo' => self::HORARIO_DISPONIBLE,
                'fecha' => $fecha,
                'hora' => $horaSql,
                'hora_corta' => substr($horaSql, 0, 5),
            ];
        } catch (\Throwable $e) {
            error_log('HorarioReservacionService::validarHorarioReservacion - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    public static function disponibilidadPublica(string $fecha): array
    {
        return self::disponibilidadParaFecha($fecha);
    }

    public static function disponibilidadParaFecha(string $fecha): array
    {
        if (!self::fechaValida($fecha)) {
            return [
                'ok' => false,
                'codigo' => self::FECHA_INVALIDA,
                'msg' => 'La fecha seleccionada no es valida.',
                'errors' => ['fecha' => ['La fecha seleccionada no es valida.']],
            ];
        }

        if (self::fechaPasada($fecha)) {
            return [
                'ok' => false,
                'codigo' => self::FECHA_PASADA,
                'fecha' => $fecha,
                'msg' => 'La fecha seleccionada ya paso. Elige otra fecha.',
                'errors' => ['fecha' => ['La fecha seleccionada ya paso. Elige otra fecha.']],
            ];
        }

        try {
            $diaSemana = (int)(new DateTimeImmutable($fecha))->format('w');
            $dia = self::diaReservacion($diaSemana);
            $diaActivo = $dia && (int)$dia['activo'] === 1;

            if (!$diaActivo) {
                return [
                    'ok' => true,
                    'fecha' => $fecha,
                    'dia_activo' => false,
                    'horarios' => [],
                ];
            }

            $horarios = array_values(array_filter(array_map(static function ($horario): string {
                return self::normalizarHoraCorta((string)($horario->hora ?? ''));
            }, self::horariosParaFecha($fecha))));

            if ($fecha === self::hoy()) {
                $horarios = array_values(array_filter($horarios, static function (string $hora) use ($fecha): bool {
                    return !self::horarioPasadoHoy($fecha, self::normalizarHoraSql($hora));
                }));
            }

            return [
                'ok' => true,
                'fecha' => $fecha,
                'dia_activo' => true,
                'horarios' => $horarios,
            ];
        } catch (\Throwable $e) {
            error_log('HorarioReservacionService::disponibilidadParaFecha - ' . $e->getMessage());
            return [
                'ok' => false,
                'codigo' => self::ERROR_INTERNO,
                'msg' => 'No fue posible consultar los horarios. Intentalo nuevamente.',
                'errors' => [],
            ];
        }
    }

    public static function horariosParaFecha(string $fecha): array
    {
        if (!self::fechaValida($fecha)) {
            return [];
        }

        $diaSemana = (int)(new DateTimeImmutable($fecha))->format('w');

        return HorarioReservacion::consultarSQL(
            "SELECT h.id, h.dia_id, h.hora
             FROM horarios_reservacion h
             INNER JOIN dias_reservacion d ON d.id = h.dia_id
             WHERE d.dia_semana = {$diaSemana}
               AND d.activo = 1
             ORDER BY h.hora ASC"
        );
    }

    public static function fechaSeguraGet(string $fecha): string
    {
        return self::fechaValida($fecha) ? $fecha : self::hoy();
    }

    public static function diasConHorariosActivos(): array
    {
        try {
            $resultado = ActiveRecord::getDB()->query(
                "SELECT DISTINCT d.dia_semana
                 FROM dias_reservacion d
                 INNER JOIN horarios_reservacion h ON h.dia_id = d.id
                 WHERE d.activo = 1
                 ORDER BY d.dia_semana ASC"
            );

            if ($resultado === false) {
                throw new \RuntimeException(ActiveRecord::getDB()->error);
            }

            $dias = [];
            while ($fila = $resultado->fetch_assoc()) {
                $dias[] = (int)$fila['dia_semana'];
            }

            $resultado->free();

            return $dias;
        } catch (\Throwable $e) {
            error_log('HorarioReservacionService::diasConHorariosActivos - ' . $e->getMessage());
            return [0, 1, 2, 3, 4, 5, 6];
        }
    }

    public static function fechaValida(string $fecha): bool
    {
        if ($fecha === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
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
            return self::normalizarHoraCorta((string)($horario->hora ?? ''));
        }, $horarios)));

        if (empty($horasDisponibles)) {
            return '';
        }

        if ($fecha === self::hoy()) {
            $horaActual = (new DateTimeImmutable('now', ReservacionConfig::timezone()))->format('H:i');

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

        return $horario <= new DateTimeImmutable('now', ReservacionConfig::timezone());
    }

    private static function diaReservacion(int $diaSemana): ?array
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT id, dia_semana, activo
             FROM dias_reservacion
             WHERE dia_semana = {$diaSemana}
             LIMIT 1"
        );

        if ($resultado === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }

        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();

        return $fila;
    }

    private static function existeHorario(int $diaSemana, string $horaSql): bool
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT h.id
             FROM horarios_reservacion h
             INNER JOIN dias_reservacion d ON d.id = h.dia_id
             WHERE d.dia_semana = {$diaSemana}
               AND d.activo = 1
               AND h.hora = '" . ActiveRecord::escaparString($horaSql) . "'
             LIMIT 1"
        );

        if ($resultado === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }

        $existe = $resultado->num_rows > 0;
        $resultado->free();

        return $existe;
    }
}
