<?php

namespace Services;

/**
 * Serializa cambios de configuración con altas/modificaciones que validan el
 * horario. Se toma antes del lock de fecha para conservar un orden global.
 */
final class HorarioConfigLock
{
    private const CLAVE = '__horario_operativo_global__';

    public static function adquirir(\mysqli $db, int $timeout = 10): bool
    {
        return FechaOperacionLock::adquirir($db, self::CLAVE, $timeout);
    }

    public static function liberar(\mysqli $db): void
    {
        FechaOperacionLock::liberar($db, self::CLAVE);
    }
}
