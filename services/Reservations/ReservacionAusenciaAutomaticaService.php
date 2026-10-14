<?php

namespace Services\Reservations;

use DateTimeImmutable;
use Model\ActiveRecord;
use Services\Pos\PuntoVentaReservacionService;
use Services\Scheduling\HorarioOperacionImpactoService;

/**
 * Registra solas las ausencias: una reserva confirmada cuyo cliente no llegó
 * cuando venció la tolerancia (hora + TOLERANCIA_LLEGADA_MINUTOS) pasa a
 * `no_show` y su mesa queda libre.
 *
 * Antes la mesa se quedaba apartada en «Sin llegar» hasta que alguien pulsara
 * «No llegó», y ese estado intermedio se arrastraba por el mapa, la ficha, el
 * buzón y los casos. Ahora no existe más allá de unos segundos: lo resuelve la
 * primera lectura que llegue después del vencimiento.
 *
 * No hay cron en la aplicación, así que se ejecuta en lectura: el sondeo del
 * POS (cada 30 s), el panel de reservaciones y el buzón. Es barato —una
 * consulta indexada y, casi siempre, cero filas— e idempotente: cada reserva
 * pasa por `PuntoVentaReservacionService::noShow`, que bloquea la fila, revalida
 * la tolerancia con el reloj canónico y rechaza si hay un ticket abierto (el
 * cliente sí llegó). Dos tablets que sondean a la vez no registran dos veces.
 */
final class ReservacionAusenciaAutomaticaService
{
    /** Motivo con el que queda registrada: la distingue de una marcada a mano. */
    public const MOTIVO = 'ausencia_automatica';

    /** Una lectura no debe cargar con un atraso grande: el resto, en la siguiente. */
    private const LOTE = 50;

    /** @var bool Una vez por petición basta. */
    private static bool $procesadoEnPeticion = false;

    /**
     * @return array{procesadas:int, ids:array<int,int>}
     */
    public static function procesar(?DateTimeImmutable $ahora = null): array
    {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $limite = $ahora->modify('-' . ReservacionConfig::TOLERANCIA_LLEGADA_MINUTOS . ' minutes');
        $limiteSql = ActiveRecord::escaparString($limite->format('Y-m-d H:i:s'));
        $lote = self::LOTE;

        $resultado = ActiveRecord::getDB()->query(
            "SELECT id
             FROM reservaciones
             WHERE estado = 'confirmada'
               AND fecha <= DATE('{$limiteSql}')
               AND TIMESTAMP(fecha, hora) < '{$limiteSql}'
             ORDER BY fecha ASC, hora ASC, id ASC
             LIMIT {$lote}"
        );
        if ($resultado === false) {
            error_log('ReservacionAusenciaAutomaticaService::procesar - ' . ActiveRecord::getDB()->error);
            return ['procesadas' => 0, 'ids' => []];
        }
        $candidatas = array_map('intval', array_column($resultado->fetch_all(MYSQLI_ASSOC), 'id'));
        $resultado->free();

        $ids = [];
        foreach ($candidatas as $id) {
            $mutacion = PuntoVentaReservacionService::noShow($id, 0, false, false, self::MOTIVO);
            if (($mutacion['ok'] ?? false) !== true || !empty($mutacion['idempotente'])) {
                // Ticket abierto (llegó y se sentó) o tolerancia aún vigente
                // según el reloj canónico: no es una ausencia.
                continue;
            }
            $ids[] = $id;
            // Igual que el no-show manual: deja de contar en las afectaciones
            // por cambio de horario.
            HorarioOperacionImpactoService::reconciliarReservacion($id, null);
        }

        return ['procesadas' => count($ids), 'ids' => $ids];
    }

    /**
     * Para las lecturas frecuentes: una sola pasada por petición y nunca un
     * error que tumbe la pantalla que la invoca.
     */
    public static function procesarEnLectura(): void
    {
        if (self::$procesadoEnPeticion) {
            return;
        }
        self::$procesadoEnPeticion = true;
        try {
            self::procesar();
        } catch (\Throwable $error) {
            error_log('ReservacionAusenciaAutomaticaService::procesarEnLectura - ' . $error->getMessage());
        }
    }
}
