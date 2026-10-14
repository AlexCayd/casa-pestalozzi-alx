<?php

/**
 * Límites de reservación que el admin edita en /admin/configuracion/reservaciones.
 *
 * Se leen en caminos calientes —cada consulta de disponibilidad, cada render de
 * la landing—, así que la fila se carga una sola vez por petición. La lectura
 * es tolerante: una base sin las columnas nuevas, o un contexto sin conexión
 * (las suites puras), responde con los valores históricos en vez de tumbar la
 * landing o el POS. El mismo criterio que ConfiguracionPos::meseroEditable().
 */

namespace Services\Reservations;

use Model\ActiveRecord;
use Model\ConfiguracionReservaciones;

final class ReservacionAjustesService
{
    /** @var array{max_comensales_publico: int, whatsapp_reservas: ?string}|null */
    private static ?array $cache = null;

    public static function maxComensalesPublico(): int
    {
        return self::cargar()['max_comensales_publico'];
    }

    /** Dígitos con lada de país, o null si el admin no capturó ninguno. */
    public static function whatsappReservas(): ?string
    {
        return self::cargar()['whatsapp_reservas'];
    }

    /**
     * Acota el máximo al rango que el sistema sabe atender. El piso lo pone el
     * control de comensales de la landing (pastillas fijas 1–6 y stepper desde
     * 7); el techo, el bloque exterior, que con sus 16 asientos es lo más grande
     * que el motor asigna solo.
     */
    public static function acotarMaxComensales(int $valor): int
    {
        return max(
            ReservacionConfig::MAX_COMENSALES_PUBLICO_MINIMO,
            min(ReservacionConfig::MAX_COMENSALES_PUBLICO_TOPE, $valor)
        );
    }

    /** Solo dígitos. Devuelve null si no queda nada que marcar. */
    public static function normalizarWhatsapp(?string $numero): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string)$numero) ?? '';

        return $digitos === '' ? null : $digitos;
    }

    /** Tras guardar, la petición en curso debe ver el valor nuevo. */
    public static function limpiarCache(): void
    {
        self::$cache = null;
    }

    /**
     * Reservaciones vivas de hoy en adelante, contadas por tamaño de grupo.
     *
     * Alimenta el aviso de Configuración al bajar el máximo: esas reservas no
     * se cancelan, pero desde ese momento cuentan como grupo grande —sin
     * autoservicio y con prioridad en el buzón—, y el admin tiene que saber
     * cuántas cambian de régimen antes de guardar.
     *
     * @return array<int, int> comensales => reservaciones
     */
    public static function reservacionesFuturasPorTamano(): array
    {
        $db = ActiveRecord::getDB();
        if (!$db) {
            return [];
        }

        try {
            $stmt = $db->prepare(
                "SELECT comensales, COUNT(*) AS total
                   FROM reservaciones
                  WHERE fecha >= ?
                    AND comensales >= ?
                    AND (estado = 'confirmada'
                         OR (estado = 'pendiente_verificacion' AND hold_expires_at > NOW()))
                  GROUP BY comensales"
            );
            if (!$stmt) {
                return [];
            }
            $hoy = ReservacionConfig::fechaActual();
            $minimo = ReservacionConfig::MAX_COMENSALES_PUBLICO_MINIMO;
            $stmt->bind_param('si', $hoy, $minimo);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $conteo = [];
            while ($fila = $resultado->fetch_assoc()) {
                $conteo[(int)$fila['comensales']] = (int)$fila['total'];
            }
            $stmt->close();
            return $conteo;
        } catch (\Throwable $e) {
            error_log('ReservacionAjustesService::reservacionesFuturasPorTamano - ' . $e->getMessage());
            return [];
        }
    }

    private static function cargar(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $valores = [
            'max_comensales_publico' => ReservacionConfig::MAX_COMENSALES_PUBLICO_PREDETERMINADO,
            'whatsapp_reservas' => null,
        ];

        if (ActiveRecord::getDB()) {
            try {
                $configuracion = ConfiguracionReservaciones::obtener();
                if ($configuracion !== null) {
                    $max = (int)($configuracion->max_comensales_publico ?? 0);
                    if ($max > 0) {
                        $valores['max_comensales_publico'] = self::acotarMaxComensales($max);
                    }
                    $valores['whatsapp_reservas'] = self::normalizarWhatsapp(
                        $configuracion->whatsapp_reservas !== null
                            ? (string)$configuracion->whatsapp_reservas
                            : null
                    );
                }
            } catch (\Throwable $e) {
                error_log('ReservacionAjustesService::cargar - ' . $e->getMessage());
            }
        }

        // Igual que el reloj de ReservacionConfig::ahora(): sólo en test se
        // admite fijar el máximo por entorno, para que las suites prueben un
        // límite distinto sin tocar la base compartida.
        if (ReservacionConfig::appEnvironment() === 'test') {
            $override = $_ENV['RESERVATION_TEST_MAX_COMENSALES'] ?? getenv('RESERVATION_TEST_MAX_COMENSALES');
            $override = filter_var($override, FILTER_VALIDATE_INT);
            if ($override !== false && $override !== null) {
                $valores['max_comensales_publico'] = self::acotarMaxComensales((int)$override);
            }
        }

        return self::$cache = $valores;
    }
}
