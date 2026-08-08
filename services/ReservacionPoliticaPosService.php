<?php

namespace Services;

use DateTimeImmutable;
use Model\TicketMesa;

/**
 * Política única para decidir qué puede hacer POS con una mesa/reservación.
 *
 * Este servicio no persiste ni presenta. Devuelve hechos y decisiones para que
 * el serializer, el contexto de mesa y la mutación walk-in no los reconstruyan
 * por separado.
 */
final class ReservacionPoliticaPosService
{
    public const ACCION_ABRIR_TICKET = 'ABRIR_TICKET';
    public const ACCION_CONFIRMAR_RESERVACION = 'CONFIRMAR_RESERVACION_PROXIMA';
    public const ACCION_RESERVACION_PROXIMA = 'RESERVACION_PROXIMA';
    public const ACCION_INICIAR_RESERVACION = 'INICIAR_SERVICIO';
    public const ACCION_REGISTRAR_AUSENCIA = 'REGISTRAR_AUSENCIA';
    public const ACCION_CONSULTAR_TICKET = 'CONSULTAR_TICKET';

    /** @return array<string, mixed> */
    public static function evaluar(
        $reservacion,
        ?DateTimeImmutable $ahora = null,
        ?array $ticket = null,
        ?DateTimeImmutable $horaConsulta = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $datos = self::aArray($reservacion);
        $ticketAbierto = self::ticketAbierto($datos, $ticket);
        $vigencia = ReservacionVigenciaService::clasificar($datos, $ahora, $ticket);
        $inicio = self::fechaHora($datos);
        $segundosParaInicio = $inicio instanceof DateTimeImmutable
            ? $inicio->getTimestamp() - $ahora->getTimestamp()
            : null;
        $minutosParaInicio = $segundosParaInicio === null
            ? null
            : (int)ceil($segundosParaInicio / 60);
        $ventana = (string)($vigencia['ventana_operativa']['estado'] ?? 'futura');
        if ((string)($datos['estado'] ?? '') === 'en_curso') {
            $ventana = 'en_curso';
        }

        $requiereAdvertencia = (string)($datos['estado'] ?? '') === 'confirmada'
            && !$ticketAbierto
            && !$vigencia['ausencia_pendiente']
            && (bool)$vigencia['influye_disponibilidad']
            && $segundosParaInicio !== null
            && $segundosParaInicio > ReservacionConfig::BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS * 60
            && $segundosParaInicio <= ReservacionConfig::AVISO_RESERVACION_PROXIMA_MINUTOS * 60;
        $bloqueoWalkIn = (string)($datos['estado'] ?? '') === 'confirmada'
            && !$ticketAbierto
            && (
                (bool)$vigencia['dentro_tolerancia']
                || (bool)$vigencia['ausencia_pendiente']
                || ($segundosParaInicio !== null
                    && $segundosParaInicio >= 0
                    && $segundosParaInicio <= ReservacionConfig::BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS * 60)
            );
        $puedeIniciar = (bool)$vigencia['puede_iniciar_servicio'] && !$ticketAbierto;
        $disponibleParaTicket = !$ticketAbierto && !$bloqueoWalkIn;
        $accionPrimaria = self::accionPrimaria(
            $ticketAbierto,
            (bool)$vigencia['ausencia_pendiente'],
            $puedeIniciar,
            $requiereAdvertencia,
            $bloqueoWalkIn
        );
        $ventanaVisual = self::ventanaVisual(
            $segundosParaInicio,
            (bool)$vigencia['ausencia_pendiente'],
            (bool)$vigencia['dentro_tolerancia'],
            $ticketAbierto
        );

        $resultado = [
            'ocupada_fisicamente' => $ticketAbierto,
            'ticket_abierto' => $ticketAbierto,
            'ventana_pos' => $ventana,
            'minutos_para_inicio' => $minutosParaInicio,
            'minutos_desde_inicio' => $segundosParaInicio !== null && $segundosParaInicio < 0
                ? (int)ceil(abs($segundosParaInicio) / 60)
                : null,
            'inicio_reservacion' => $inicio?->format('Y-m-d H:i:s'),
            'en_inicio_exacto' => $segundosParaInicio === 0,
            'en_tolerancia' => (bool)$vigencia['dentro_tolerancia'],
            'tolerancia_vencida' => (bool)$vigencia['tolerancia_vencida'],
            'ausencia_pendiente' => (bool)$vigencia['ausencia_pendiente'],
            'reservacion_influye' => (bool)$vigencia['influye_disponibilidad'],
            'influye_disponibilidad' => (bool)$vigencia['influye_disponibilidad'],
            'bloquea_walk_ins' => !$disponibleParaTicket,
            'bloqueo_walk_in' => $bloqueoWalkIn,
            'disponible_para_ticket' => $disponibleParaTicket,
            'requiere_advertencia_ticket' => $requiereAdvertencia,
            'puede_iniciar_reservacion' => $puedeIniciar,
            'puede_iniciar_servicio' => $puedeIniciar,
            'puede_marcar_no_show' => (bool)$vigencia['puede_marcar_no_show'] && !$ticketAbierto,
            'accion_primaria' => $accionPrimaria,
            'accion_pendiente' => $vigencia['ausencia_pendiente']
                ? self::ACCION_REGISTRAR_AUSENCIA
                : null,
            'muestra_advertencia' => $requiereAdvertencia || (bool)$vigencia['ausencia_pendiente'],
            'estado_temporal' => $ventana,
            'ventana_visual_pos' => $ventanaVisual,
            'prioridad_pos' => self::prioridad($ticketAbierto, $vigencia, $requiereAdvertencia, $bloqueoWalkIn),
            'tolerancia_hasta' => $vigencia['limite_tolerancia'],
            'puede_registrar_ausencia' => (bool)$vigencia['puede_marcar_no_show'] && !$ticketAbierto,
        ];

        if ($horaConsulta instanceof DateTimeImmutable) {
            $resultado['proyeccion_mapa'] = self::proyeccionMapa(
                $datos,
                $horaConsulta,
                $ahora,
                $ticketAbierto,
                $resultado
            );
        }

        return $resultado;
    }

    /** @return array<string, mixed> */
    public static function proyeccionMapa(
        array $reservacion,
        DateTimeImmutable $horaConsulta,
        ?DateTimeImmutable $ahora = null,
        bool $ticketAbierto = false,
        ?array $hechosActuales = null
    ): array {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $inicio = self::fechaHora($reservacion);
        if (!$inicio) {
            return [
                'ventana_mapa' => 'futura',
                'minutos_para_inicio_mapa' => null,
                'reservacion_influye_mapa' => false,
                'ausencia_pendiente_mapa' => false,
                'en_inicio_exacto_mapa' => false,
            ];
        }

        $segundos = $inicio->getTimestamp() - $horaConsulta->getTimestamp();
        $minutos = (int)ceil($segundos / 60);
        $hechosActuales = $hechosActuales ?? self::evaluar($reservacion, $ahora);
        $ausenciaPendiente = (bool)($hechosActuales['ausencia_pendiente'] ?? false);
        $ventana = self::ventanaVisual(
            $segundos,
            $ausenciaPendiente,
            $segundos < 0 && $segundos >= -ReservacionConfig::TOLERANCIA_LLEGADA_MINUTOS * 60,
            $ticketAbierto
        );

        return [
            'ventana_mapa' => $ventana,
            'minutos_para_inicio_mapa' => $minutos,
            'minutos_desde_inicio_mapa' => $segundos < 0 ? (int)ceil(abs($segundos) / 60) : null,
            'reservacion_influye_mapa' => (bool)($hechosActuales['influye_disponibilidad'] ?? false),
            'ausencia_pendiente_mapa' => $ausenciaPendiente,
            'en_inicio_exacto_mapa' => $segundos === 0,
            'en_tolerancia_mapa' => $segundos < 0
                && $segundos >= -ReservacionConfig::TOLERANCIA_LLEGADA_MINUTOS * 60,
            'ticket_bloquea_consulta' => $ticketAbierto,
        ];
    }

    private static function ventanaVisual(
        ?int $segundos,
        bool $ausenciaPendiente,
        bool $enTolerancia,
        bool $ticketAbierto
    ): string {
        if ($ticketAbierto) {
            return 'ticket';
        }
        if ($ausenciaPendiente) {
            return 'ausencia_pendiente';
        }
        if ($segundos === null) {
            return 'futura';
        }
        if ($segundos > ReservacionConfig::AVISO_RESERVACION_PROXIMA_MINUTOS * 60) {
            return 'futura';
        }
        if ($segundos > ReservacionConfig::BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS * 60) {
            return 'advertencia';
        }
        if ($segundos === 0) {
            return 'inicio';
        }
        if ($segundos >= 0) {
            return 'bloqueo';
        }
        if ($enTolerancia) {
            return 'tolerancia';
        }

        return 'ausencia_pendiente';
    }

    private static function accionPrimaria(
        bool $ticketAbierto,
        bool $ausenciaPendiente,
        bool $puedeIniciar,
        bool $requiereAdvertencia,
        bool $bloqueoWalkIn
    ): string {
        if ($ticketAbierto) {
            return self::ACCION_CONSULTAR_TICKET;
        }
        if ($ausenciaPendiente) {
            return self::ACCION_REGISTRAR_AUSENCIA;
        }
        if ($puedeIniciar) {
            return self::ACCION_INICIAR_RESERVACION;
        }
        if ($requiereAdvertencia) {
            return self::ACCION_CONFIRMAR_RESERVACION;
        }
        if ($bloqueoWalkIn) {
            return self::ACCION_RESERVACION_PROXIMA;
        }

        return self::ACCION_ABRIR_TICKET;
    }

    private static function prioridad(
        bool $ticketAbierto,
        array $vigencia,
        bool $requiereAdvertencia,
        bool $bloqueoWalkIn
    ): int {
        if ($ticketAbierto) {
            return 1000;
        }
        if (!empty($vigencia['ausencia_pendiente'])) {
            return 900;
        }
        if (!empty($vigencia['dentro_tolerancia'])) {
            return 800;
        }
        if ($bloqueoWalkIn) {
            return 700;
        }
        if ($requiereAdvertencia) {
            return 600;
        }

        return 100;
    }

    private static function ticketAbierto(array $reservacion, ?array $ticket): bool
    {
        if ($ticket !== null) {
            return true;
        }
        if (array_key_exists('ticket_abierto', $reservacion)) {
            return filter_var($reservacion['ticket_abierto'], FILTER_VALIDATE_BOOL);
        }
        $estado = (string)($reservacion['ticket_estado'] ?? '');

        return !empty($reservacion['ticket_id'])
            && $estado === TicketMesa::ESTADO_ABIERTO
            && empty($reservacion['ticket_closed_at']);
    }

    private static function fechaHora(array $reservacion): ?DateTimeImmutable
    {
        $fecha = trim((string)($reservacion['fecha'] ?? ''));
        $hora = trim((string)($reservacion['hora'] ?? ''));
        if ($fecha === '' || $hora === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($fecha . ' ' . $hora, ReservacionConfig::timezone());
        } catch (\Throwable $error) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function aArray($registro): array
    {
        if (is_array($registro)) {
            return $registro;
        }
        if (is_object($registro)) {
            return get_object_vars($registro);
        }

        return [];
    }
}
