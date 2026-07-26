<?php

/**
 * Fuente única de verdad para la disponibilidad pública de reservaciones.
 *
 * La consulta GET es orientativa. Toda mutación vuelve a ejecutar esta lógica
 * bajo lock de fecha, transacción y bloqueo ordenado de mesas.
 */

namespace Services;

use Model\Mesa;
use Model\TicketMesa;

final class DisponibilidadReservacionService
{
    public const DISPONIBILIDAD_CONSULTADA = 'DISPONIBILIDAD_CONSULTADA';
    public const SIN_DISPONIBILIDAD = 'SIN_DISPONIBILIDAD';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    /**
     * Devuelve slots sin exponer IDs, combinaciones o capacidad interna.
     *
     * @return array<string, mixed>
     */
    public static function consultar(string $fecha, $personas): array
    {
        $personas = filter_var($personas, FILTER_VALIDATE_INT);
        if (
            $personas === false
            || $personas < 1
            || $personas > ReservacionConfig::MAX_PUBLIC_GUESTS
        ) {
            return [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'mensaje' => 'Las reservaciones en línea son de 1 a 12 personas.',
                'fecha' => trim($fecha),
                'personas' => is_int($personas) ? $personas : null,
                'horarios' => [],
            ];
        }

        $horarios = ReservacionService::obtenerHorariosDisponiblesParaFecha($fecha);
        if (!($horarios['ok'] ?? false)) {
            $horarios['personas'] = $personas;
            return $horarios;
        }

        $slots = [];
        foreach ($horarios['horarios'] ?? [] as $hora) {
            $evaluacion = self::evaluarHorario((string)$fecha, (string)$hora, (int)$personas);
            $slots[] = [
                'hora' => substr((string)$hora, 0, 5),
                'disponible' => (bool)($evaluacion['ok'] ?? false),
            ];
        }

        return [
            'ok' => true,
            'codigo' => self::DISPONIBILIDAD_CONSULTADA,
            'fecha' => (string)$fecha,
            'personas' => (int)$personas,
            'abierto' => (bool)($horarios['abierto'] ?? false),
            'origen' => $horarios['origen'] ?? null,
            'tipo' => $horarios['tipo'] ?? null,
            'detalle_horario' => $horarios['detalle_horario'] ?? null,
            'horarios' => $slots,
            'mensaje' => $slots === [] ? ($horarios['mensaje'] ?? 'No hay horarios disponibles.') : null,
        ];
    }

    /**
     * Selecciona entre una y tres mesas con la misma estrategia usada por la
     * asignación definitiva. En una mutación, `$bloquear` debe ser true.
     *
     * @return array{ok: bool, codigo: string, mesa_ids: array<int, int>}
     */
    public static function evaluarHorario(
        string $fecha,
        string $hora,
        int $personas,
        int $excluirReservacionId = 0,
        bool $bloquear = false
    ): array {
        if ($personas < 1 || $personas > ReservacionConfig::MAX_PUBLIC_GUESTS) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'mesa_ids' => []];
        }

        $horario = ReservacionService::validarHorarioDisponible($fecha, $hora);
        if (!($horario['ok'] ?? false)) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'mesa_ids' => []];
        }

        // Bloquear todas las mesas reservables en orden de ID hace estable la
        // elección durante creaciones y modificaciones concurrentes.
        $mesas = $bloquear ? Mesa::reservablesParaActualizar() : Mesa::reservables();
        $ocupacion = AsignacionMesasService::obtenerOcupacionParaHorario(
            $fecha,
            (string)$horario['hora'],
            $excluirReservacionId,
            $bloquear
        );

        // La ocupación física se suma a la capacidad prometida. Al indexar por
        // mesa, una reservación en curso y su propio ticket quedan deduplicados.
        foreach (TicketMesa::ocupacionAbierta(
            $fecha,
            (string)$horario['hora'],
            $excluirReservacionId,
            $bloquear
        ) as $ticketOcupacion) {
            $mesaId = (int)$ticketOcupacion['mesa_id'];
            $ocupacion[$mesaId] = [
                'tipo' => !empty($ticketOcupacion['legacy']) ? 'ticket_legacy' : 'ticket',
                'ticket_id' => (int)$ticketOcupacion['ticket_id'],
                'reservacion_id' => $ticketOcupacion['reservacion_id'],
                'liberacion_estimada' => $ticketOcupacion['liberacion_estimada'],
            ];
        }
        $disponibles = array_values(array_filter(
            $mesas,
            static fn($mesa): bool => empty($ocupacion[(int)$mesa->id])
        ));
        usort($disponibles, static function ($a, $b): int {
            return ((int)$a->numero <=> (int)$b->numero)
                ?: ((int)$a->id <=> (int)$b->id);
        });

        $seleccion = AsignacionMesasService::seleccionarMesasPublicas($disponibles, $personas);
        if ($seleccion === [] || count($seleccion) > ReservacionConfig::MAX_PUBLIC_TABLES) {
            return ['ok' => false, 'codigo' => self::SIN_DISPONIBILIDAD, 'mesa_ids' => []];
        }

        return [
            'ok' => true,
            'codigo' => self::DISPONIBILIDAD_CONSULTADA,
            'mesa_ids' => array_map(static fn($mesa): int => (int)$mesa->id, $seleccion),
        ];
    }
}
