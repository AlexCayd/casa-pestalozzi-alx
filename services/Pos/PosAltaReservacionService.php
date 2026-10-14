<?php

namespace Services\Pos;

/**
 * Frontera del alta de una reservación hecha desde el piso.
 *
 * El servicio administrativo conserva la validación de horario, capacidad,
 * asignación, advertencias e idempotencia. Lo único que cambia para un mesero
 * es el contacto: no lo ve ni lo captura, así que el servidor ignora cualquier
 * valor que llegue y confirma internamente el alta sin contacto. Vivía dentro
 * del controlador de Operación; el «+ Reservación» del POS necesita la misma
 * regla y no una copia.
 */
final class PosAltaReservacionService
{
    /**
     * @param array<string, mixed> $entrada
     * @return array<string, mixed>
     */
    public static function normalizarEntrada(array $entrada, bool $esMesero): array
    {
        if (!$esMesero) {
            return $entrada;
        }

        $entrada['contacto_tipo'] = 'ninguno';
        $entrada['contacto'] = null;
        $entrada['confirmar_sin_contacto'] = '1';

        $confirmaciones = $entrada['confirmaciones'] ?? [];
        if (is_string($confirmaciones)) {
            $confirmaciones = preg_split('/[,|\s]+/', $confirmaciones, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($confirmaciones)) {
            $confirmaciones = [];
        }
        $confirmaciones[] = 'SIN_CONTACTO';
        $entrada['confirmaciones'] = array_values(array_unique(array_map('strval', $confirmaciones)));

        return $entrada;
    }
}
