<?php

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\TicketMesa;

/** Reglas de reservaciones que alimentan el buzón reutilizable. */
final class ReservacionBuzonService
{
    public const TIPO_HORARIO_AFECTADO = 'reservacion_horario_afectado';
    public const TIPO_GRUPO_GRANDE = 'reservacion_grupo_grande';
    public const TIPO_AUSENCIA_PENDIENTE = 'reservacion_ausencia_pendiente';
    public const TIPO_SIN_ASIGNACION_PROXIMA = 'reservacion_sin_asignacion_proxima';
    public const ENTIDAD_IMPACTO_RESERVACION = 'horario_impacto_reservacion';
    public const ENTIDAD_RESERVACION = 'reservacion';

    public static function dedupHorario(int $impactoReservacionId): string
    {
        return 'reservacion_horario_afectado:' . $impactoReservacionId;
    }

    public static function dedupAusencia(int $reservacionId): string
    {
        return self::TIPO_AUSENCIA_PENDIENTE . ':' . $reservacionId;
    }

    public static function dedupSinAsignacion(int $reservacionId): string
    {
        return self::TIPO_SIN_ASIGNACION_PROXIMA . ':' . $reservacionId;
    }

    public static function dedupGrupoGrande(int $reservacionId): string
    {
        return self::TIPO_GRUPO_GRANDE . ':' . $reservacionId;
    }

    public static function crearSeguimientoHorarioEnTransaccion(
        \mysqli $db,
        int $impactoReservacionId,
        string $prioridad,
        ?string $visibleFrom,
        bool $requiereAccion = true
    ): int {
        return BuzonNotificacionesService::crearEnTransaccion($db, [
            'tipo' => self::TIPO_HORARIO_AFECTADO,
            'modulo' => 'reservaciones',
            'entidad_tipo' => self::ENTIDAD_IMPACTO_RESERVACION,
            'entidad_id' => $impactoReservacionId,
            'prioridad' => $prioridad,
            'visible_from' => $visibleFrom,
            'requiere_accion' => $requiereAccion,
            'dedup_key' => self::dedupHorario($impactoReservacionId),
        ]);
    }

    /**
     * Sincroniza sólo las reservaciones del día actual y las que ya tienen un
     * aviso temporal abierto. La lectura de reservaciones, mesas y tickets es
     * por lote para que el refresco del buzón no introduzca N+1.
     *
     * @return array{procesadas:int,avisos_creados:int,avisos_cerrados:int,resumen:array}
     */
    public static function sincronizarPendientesTemporales(?DateTimeImmutable $ahora = null): array
    {
        $ahora = $ahora ?? ReservacionConfig::ahora();
        $fechaActual = $ahora->format('Y-m-d');
        $reservaciones = self::reservacionesRelevantes($fechaActual);
        $abiertos = self::avisosTemporalesAbiertos();
        $tickets = [];
        foreach (TicketMesa::abiertosParaMapa() as $ticket) {
            $reservacionId = (int)($ticket['reservacion_id'] ?? 0);
            if ($reservacionId > 0) {
                $tickets[$reservacionId] = $ticket;
            }
        }

        $horarioEfectivo = HorarioOperacionService::obtenerHorarioEfectivo($fechaActual);
        $db = ActiveRecord::getDB();
        $avisosCreados = 0;
        $avisosCerrados = 0;
        $transaccion = false;

        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la sincronización del buzón.');
            }
            $transaccion = true;
            HorarioOperacionImpactoService::actualizarSeguimientosVencidosEnTransaccion($db);

            foreach ($reservaciones as $reservacion) {
                $id = (int)($reservacion['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }

                $ticket = $tickets[$id] ?? null;
                $vigencia = ReservacionVigenciaService::clasificar($reservacion, $ahora, $ticket);
                $politica = ReservacionPoliticaPosService::evaluar(
                    $reservacion,
                    $ahora,
                    $ticket,
                    null,
                    ['sin_mesas' => (int)($reservacion['mesas_count'] ?? 0) === 0]
                );
                $esActual = (string)($reservacion['fecha'] ?? '') === $fechaActual;
                $esFinal = in_array((string)($reservacion['estado'] ?? ''), ReservacionConfig::ESTADOS_FINALES, true);
                $ausenciaPendiente = (bool)($vigencia['ausencia_pendiente'] ?? false)
                    && (bool)($vigencia['puede_marcar_no_show'] ?? false)
                    && !$ticket
                    && (string)($reservacion['estado'] ?? '') === 'confirmada';
                $habiaAusencia = isset($abiertos[self::TIPO_AUSENCIA_PENDIENTE][$id]);

                if ($ausenciaPendiente && ($esActual || $habiaAusencia)) {
                    BuzonNotificacionesService::crearEnTransaccion($db, [
                        'tipo' => self::TIPO_AUSENCIA_PENDIENTE,
                        'modulo' => 'reservaciones',
                        'entidad_tipo' => self::ENTIDAD_RESERVACION,
                        'entidad_id' => $id,
                        'prioridad' => BuzonNotificacionesService::PRIORIDAD_ALTA,
                        'dedup_key' => self::dedupAusencia($id),
                    ]);
                    $avisosCreados++;
                } elseif (!$ausenciaPendiente) {
                    $avisosCerrados += BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion(
                        $db,
                        self::TIPO_AUSENCIA_PENDIENTE,
                        self::ENTIDAD_RESERVACION,
                        $id,
                        null,
                        self::motivoCierreAusencia($reservacion, $ticket, $esFinal)
                    );
                }

                $fueraHorario = $esActual && !self::dentroHorarioEfectivo($reservacion, $horarioEfectivo);
                $ventana = (string)($vigencia['ventana_operativa']['estado'] ?? $politica['ventana_pos'] ?? 'futura');
                $sinAsignacionProxima = $esActual
                    && !$esFinal
                    && (string)($reservacion['estado'] ?? '') === 'confirmada'
                    && !$ticket
                    && (int)($reservacion['mesas_count'] ?? 0) === 0
                    && !$ausenciaPendiente
                    && !$fueraHorario
                    && (int)($reservacion['comensales'] ?? 0) <= ReservacionConfig::MAX_COMENSALES_PUBLICO
                    && in_array($ventana, ['advertencia', 'bloqueo', 'tolerancia'], true);
                $habiaSinAsignacion = isset($abiertos[self::TIPO_SIN_ASIGNACION_PROXIMA][$id]);

                if ($sinAsignacionProxima) {
                    $prioridad = in_array($ventana, ['bloqueo', 'tolerancia'], true)
                        ? BuzonNotificacionesService::PRIORIDAD_ALTA
                        : BuzonNotificacionesService::PRIORIDAD_NORMAL;
                    BuzonNotificacionesService::crearEnTransaccion($db, [
                        'tipo' => self::TIPO_SIN_ASIGNACION_PROXIMA,
                        'modulo' => 'reservaciones',
                        'entidad_tipo' => self::ENTIDAD_RESERVACION,
                        'entidad_id' => $id,
                        'prioridad' => $prioridad,
                        'dedup_key' => self::dedupSinAsignacion($id),
                    ]);
                    $avisosCreados++;
                } elseif (!$sinAsignacionProxima || $habiaSinAsignacion) {
                    $avisosCerrados += BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion(
                        $db,
                        self::TIPO_SIN_ASIGNACION_PROXIMA,
                        self::ENTIDAD_RESERVACION,
                        $id,
                        null,
                        self::motivoCierreSinAsignacion($reservacion, $ticket, $esFinal, $ausenciaPendiente, $fueraHorario)
                    );
                }

                $grupoGrande = self::grupoGrandeRequiereAccion($reservacion, $esFinal);
                $habiaGrupo = isset($abiertos[self::TIPO_GRUPO_GRANDE][$id]);
                if ($grupoGrande && ($esActual || $habiaGrupo)) {
                    BuzonNotificacionesService::crearEnTransaccion($db, [
                        'tipo' => self::TIPO_GRUPO_GRANDE,
                        'modulo' => 'reservaciones',
                        'entidad_tipo' => self::ENTIDAD_RESERVACION,
                        'entidad_id' => $id,
                        'prioridad' => BuzonNotificacionesService::PRIORIDAD_ALTA,
                        'dedup_key' => self::dedupGrupoGrande($id),
                    ]);
                    $avisosCreados++;
                } elseif (!$grupoGrande) {
                    $avisosCerrados += BuzonNotificacionesService::cerrarTipoEntidadEnTransaccion(
                        $db,
                        self::TIPO_GRUPO_GRANDE,
                        self::ENTIDAD_RESERVACION,
                        $id,
                        null,
                        $esFinal ? 'reservacion_finalizada' : 'coordinacion_completada'
                    );
                }
            }

            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la sincronización del buzón.');
            }
            $transaccion = false;
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionBuzonService::sincronizarPendientesTemporales - ' . $e->getMessage());
        }

        return [
            'procesadas' => count($reservaciones),
            'avisos_creados' => $avisosCreados,
            'avisos_cerrados' => $avisosCerrados,
            'resumen' => BuzonNotificacionesService::resumen(),
        ];
    }

    /**
     * Crea el seguimiento de grupo sólo si existe una acción operativa real:
     * falta de mesas/asignación o falta de contacto. Un grupo coordinado no
     * llena el buzón por el número de personas.
     */
    public static function sincronizarGrupoGrande(int $reservacionId, ?int $usuarioId = null): bool
    {
        if ($reservacionId < 1) {
            return false;
        }
        $fila = self::reservacionPorId($reservacionId);
        $db = ActiveRecord::getDB();
        if (!$fila) {
            BuzonNotificacionesService::cerrarTipoEntidad(
                self::TIPO_GRUPO_GRANDE,
                self::ENTIDAD_RESERVACION,
                $reservacionId,
                $usuarioId,
                'fuente_inexistente'
            );
            return false;
        }
        $estadoFinal = in_array((string)$fila['estado'], ReservacionConfig::ESTADOS_FINALES, true);
        if (!self::grupoGrandeRequiereAccion($fila, $estadoFinal)) {
            BuzonNotificacionesService::cerrarTipoEntidad(
                self::TIPO_GRUPO_GRANDE,
                self::ENTIDAD_RESERVACION,
                $reservacionId,
                $usuarioId,
                $estadoFinal ? 'reservacion_finalizada' : 'coordinacion_completada'
            );
            return false;
        }

        BuzonNotificacionesService::crearEnTransaccion($db, [
            'tipo' => self::TIPO_GRUPO_GRANDE,
            'modulo' => 'reservaciones',
            'entidad_tipo' => self::ENTIDAD_RESERVACION,
            'entidad_id' => $reservacionId,
            'prioridad' => BuzonNotificacionesService::PRIORIDAD_ALTA,
            'dedup_key' => self::dedupGrupoGrande($reservacionId),
        ]);
        return true;
    }

    public static function cerrarGrupoGrande(int $reservacionId, ?int $usuarioId, string $motivo): int
    {
        return BuzonNotificacionesService::cerrarTipoEntidad(
            self::TIPO_GRUPO_GRANDE,
            self::ENTIDAD_RESERVACION,
            $reservacionId,
            $usuarioId,
            $motivo
        );
    }

    /** Permite que la API presente el mismo criterio que la sincronización. */
    public static function grupoGrandeVisibleParaBuzon($reservacion): bool
    {
        $datos = is_array($reservacion) ? $reservacion : [
            'comensales' => $reservacion->comensales ?? 0,
            'contacto_tipo' => $reservacion->contacto_tipo ?? '',
            'contacto' => $reservacion->contacto ?? '',
            'mesas_count' => $reservacion->mesas_count ?? 0,
            'estado' => $reservacion->estado ?? '',
        ];
        return self::grupoGrandeRequiereAccion(
            $datos,
            in_array((string)($datos['estado'] ?? ''), ReservacionConfig::ESTADOS_FINALES, true)
        );
    }

    /** @return array<int, array<string, mixed>> */
    private static function reservacionesRelevantes(string $fechaActual): array
    {
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT r.id, r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora,
                    r.comensales, r.estado, r.hold_expires_at, r.origen,
                    r.created_at, r.updated_at, COUNT(rm.id) AS mesas_count,
                    COALESCE(GROUP_CONCAT(m.id ORDER BY rm.orden SEPARATOR ','), '') AS mesa_ids
             FROM reservaciones r
             LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
             LEFT JOIN mesas m ON m.id = rm.mesa_id
             WHERE r.fecha = ?
                OR EXISTS (
                    SELECT 1 FROM buzon_notificaciones bn
                    WHERE bn.entidad_tipo = 'reservacion'
                      AND bn.entidad_id = r.id
                      AND bn.tipo IN ('reservacion_ausencia_pendiente',
                                      'reservacion_sin_asignacion_proxima',
                                      'reservacion_grupo_grande')
                      AND bn.cerrada_at IS NULL
                )
             GROUP BY r.id, r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora,
                      r.comensales, r.estado, r.hold_expires_at, r.origen,
                      r.created_at, r.updated_at
             ORDER BY r.fecha ASC, r.hora ASC, r.id ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $fechaActual);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $resultado = $stmt->get_result();
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $fila['id'] = (int)$fila['id'];
            $fila['comensales'] = (int)$fila['comensales'];
            $fila['mesas_count'] = (int)$fila['mesas_count'];
            $filas[] = $fila;
        }
        $stmt->close();
        return $filas;
    }

    /** @return array<string, array<int, true>> */
    private static function avisosTemporalesAbiertos(): array
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT tipo, entidad_id
             FROM buzon_notificaciones
             WHERE entidad_tipo = 'reservacion'
               AND tipo IN ('reservacion_ausencia_pendiente',
                            'reservacion_sin_asignacion_proxima',
                            'reservacion_grupo_grande')
               AND cerrada_at IS NULL"
        );
        if (!$resultado) {
            return [];
        }
        $abiertos = [];
        while ($fila = $resultado->fetch_assoc()) {
            $tipo = (string)$fila['tipo'];
            $id = (int)$fila['entidad_id'];
            $abiertos[$tipo][$id] = true;
        }
        $resultado->free();
        return $abiertos;
    }

    /** @return array<string, mixed>|null */
    private static function reservacionPorId(int $reservacionId): ?array
    {
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT r.id, r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora,
                    r.comensales, r.estado, r.hold_expires_at, r.origen,
                    r.created_at, r.updated_at, COUNT(rm.id) AS mesas_count
             FROM reservaciones r
             LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
             WHERE r.id = ?
             GROUP BY r.id, r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora,
                      r.comensales, r.estado, r.hold_expires_at, r.origen,
                      r.created_at, r.updated_at
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $reservacionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($fila) {
            $fila['mesas_count'] = (int)$fila['mesas_count'];
        }
        return $fila;
    }

    private static function grupoGrandeRequiereAccion(array $reservacion, bool $estadoFinal): bool
    {
        $sinContacto = !in_array((string)($reservacion['contacto_tipo'] ?? ''), ['email', 'telefono'], true)
            || trim((string)($reservacion['contacto'] ?? '')) === '';

        return (string)($reservacion['estado'] ?? '') === 'confirmada'
            && !$estadoFinal
            && (int)($reservacion['comensales'] ?? 0) > ReservacionConfig::MAX_COMENSALES_PUBLICO
            && ($sinContacto || (int)($reservacion['mesas_count'] ?? 0) === 0);
    }

    private static function dentroHorarioEfectivo(array $reservacion, array $horario): bool
    {
        if (!(bool)($horario['abierto'] ?? false)) {
            return false;
        }
        $hora = HorarioReservacionService::normalizarHoraSql((string)($reservacion['hora'] ?? ''));
        $apertura = HorarioReservacionService::normalizarHoraSql((string)($horario['hora_apertura'] ?? ''));
        $cierre = HorarioReservacionService::normalizarHoraSql((string)($horario['hora_cierre'] ?? ''));
        return $hora !== '' && $apertura !== '' && $cierre !== '' && $hora >= $apertura && $hora < $cierre;
    }

    private static function motivoCierreAusencia(array $reservacion, ?array $ticket, bool $estadoFinal): string
    {
        if ($estadoFinal) {
            return 'reservacion_finalizada';
        }
        if ($ticket) {
            return 'servicio_iniciado_ticket_abierto';
        }
        return (string)($reservacion['estado'] ?? '') === 'confirmada'
            ? 'tolerancia_no_vencida'
            : 'estado_operativo_resuelto';
    }

    private static function motivoCierreSinAsignacion(
        array $reservacion,
        ?array $ticket,
        bool $estadoFinal,
        bool $ausenciaPendiente,
        bool $fueraHorario
    ): string {
        if ($estadoFinal) {
            return 'reservacion_finalizada';
        }
        if ($ausenciaPendiente) {
            return 'reservacion_ausencia_pendiente';
        }
        if ($ticket) {
            return 'servicio_iniciado_ticket_abierto';
        }
        if ((int)($reservacion['mesas_count'] ?? 0) > 0) {
            return 'mesas_asignadas';
        }
        if ($fueraHorario) {
            return 'reservacion_fuera_horario_operacion';
        }
        return 'ventana_proxima_resuelta';
    }
}
