<?php

namespace Services;

use Model\ConfiguracionReservaciones;

/** Frontera tolerante para la configuración persistida de comunicaciones. */
final class ReservacionNotificacionConfigService
{
    public const HORA_PREDETERMINADA = '18:00';

    public static function obtener(): array
    {
        try {
            $configuracion = ConfiguracionReservaciones::obtener();
            return $configuracion ? $configuracion->valoresFormulario() : self::predeterminada();
        } catch (\Throwable $e) {
            error_log('ReservacionNotificacionConfigService::obtener - configuración no disponible.');
            return self::predeterminada();
        }
    }

    public static function obtenerOCrear(): array
    {
        return ConfiguracionReservaciones::obtenerOCrear()->valoresFormulario();
    }

    /** @return array{ok:bool,codigo:string,configuracion:array,errors?:array} */
    public static function guardar(array $entrada, ?int $usuarioId): array
    {
        $validacion = self::validar($entrada);
        if (!$validacion['ok']) {
            return $validacion;
        }
        $datos = $validacion['configuracion'];
        $modelo = new ConfiguracionReservaciones([
            'recordatorio_dia_anterior_activo' => $datos['recordatorio_dia_anterior_activo'] ? 1 : 0,
            'hora_recordatorio' => $datos['hora_recordatorio'] . ':00',
            'updated_by' => $usuarioId,
        ]);
        $alertas = $modelo->validar();
        if ($alertas !== []) {
            return [
                'ok' => false,
                'codigo' => 'CONFIGURACION_RESERVACIONES_INVALIDA',
                'configuracion' => $datos,
                'errors' => array_values($alertas['error'] ?? []),
            ];
        }
        if (!$modelo->guardarConfiguracion()) {
            throw new \RuntimeException('No fue posible guardar la configuración de reservaciones.');
        }
        return [
            'ok' => true,
            'codigo' => 'CONFIGURACION_RESERVACIONES_ACTUALIZADA',
            'configuracion' => $modelo->valoresFormulario(),
        ];
    }

    /** @return array{ok:bool,codigo:string,configuracion:array,errors?:array} */
    public static function validar(array $entrada): array
    {
        $activoRaw = $entrada['recordatorio_dia_anterior_activo'] ?? false;
        $activoValido = is_bool($activoRaw)
            || $activoRaw === 0
            || $activoRaw === 1
            || $activoRaw === '0'
            || $activoRaw === '1';
        $activo = $activoRaw === true || $activoRaw === 1 || $activoRaw === '1';
        $hora = is_scalar($entrada['hora_recordatorio'] ?? null)
            ? trim((string)$entrada['hora_recordatorio'])
            : '';
        $errors = [];
        if (!$activoValido) {
            $errors[] = 'El estado del recordatorio no es válido.';
        }
        if (!self::horaValida($hora)) {
            $errors[] = 'La hora del recordatorio debe usar el formato HH:MM.';
        }
        $configuracion = [
            'recordatorio_dia_anterior_activo' => $activo,
            'hora_recordatorio' => $hora !== '' ? $hora : self::HORA_PREDETERMINADA,
            'updated_at' => '',
        ];
        return $errors === []
            ? ['ok' => true, 'codigo' => 'OK', 'configuracion' => $configuracion]
            : [
                'ok' => false,
                'codigo' => 'CONFIGURACION_RESERVACIONES_INVALIDA',
                'configuracion' => $configuracion,
                'errors' => $errors,
            ];
    }

    public static function recordatoriosHabilitados(): bool
    {
        return (bool)self::obtener()['recordatorio_dia_anterior_activo'];
    }

    public static function horaEfectiva(): string
    {
        $hora = (string)(self::obtener()['hora_recordatorio'] ?? '');
        return self::horaValida($hora) ? $hora : self::HORA_PREDETERMINADA;
    }

    public static function horaValida(string $hora): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora) === 1;
    }

    private static function predeterminada(): array
    {
        return [
            'recordatorio_dia_anterior_activo' => false,
            'hora_recordatorio' => self::HORA_PREDETERMINADA,
            'updated_at' => '',
        ];
    }
}
