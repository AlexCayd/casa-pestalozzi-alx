<?php

namespace Services;

use Model\ActiveRecord;
use Model\TicketMesa;

/**
 * Herramientas temporales y deliberadamente exclusivas de APP_ENV=development.
 */
final class ReservacionMantenimientoService
{
    public const OK = 'OK';
    public const AMBIENTE_NO_PERMITIDO = 'AMBIENTE_NO_PERMITIDO';
    public const DATOS_INVALIDOS = ReservacionService::DATOS_INVALIDOS;
    public const CONFIRMACION_INVALIDA = 'CONFIRMACION_INVALIDA';
    public const ERROR_INTERNO = ReservacionService::ERROR_INTERNO;
    public const CONFIRMACION_LIMPIEZA = 'LIMPIAR RESERVACIONES';
    public const CONFIRMACION_PENDIENTES_VIGENTES = 'LIMPIAR PENDIENTES VIGENTES';
    private const ESTADOS_LIMPIABLES = ['no_show', 'expirada', 'pendiente_verificacion'];

    public static function disponible(): bool
    {
        return in_array(
            ReservacionConfig::appEnvironment(),
            ['development', 'testing'],
            true
        );
    }

    public static function vistaPreviaPendientesVencidas(): array
    {
        if (!self::disponible()) {
            return self::ambienteNoPermitido();
        }

        $db = ActiveRecord::getDB();
        $ahora = self::ahoraSql($db);
        $resultado = $db->query(
            "SELECT COUNT(*) AS total
             FROM reservaciones
             WHERE estado = 'pendiente_verificacion'
               AND hold_expires_at IS NOT NULL
               AND hold_expires_at <= '{$ahora}'"
        );
        if (!$resultado) {
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
        $total = (int)($resultado->fetch_assoc()['total'] ?? 0);
        $resultado->free();

        return [
            'ok' => true,
            'codigo' => self::OK,
            'total' => $total,
            'hora_corte' => $ahora,
        ];
    }

    public static function procesarPendientesVencidas(bool $confirmado): array
    {
        if (!self::disponible()) {
            return self::ambienteNoPermitido();
        }
        if (!$confirmado) {
            return ['ok' => false, 'codigo' => self::CONFIRMACION_INVALIDA];
        }

        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            $db->begin_transaction();
            $transaccion = true;
            $ahora = self::ahoraSql($db);
            $resultado = $db->query(
                "SELECT id
                 FROM reservaciones
                 WHERE estado = 'pendiente_verificacion'
                   AND hold_expires_at IS NOT NULL
                   AND hold_expires_at <= '{$ahora}'
                 ORDER BY id
                 FOR UPDATE"
            );
            if (!$resultado) {
                throw new \RuntimeException($db->error);
            }
            $ids = [];
            while ($fila = $resultado->fetch_assoc()) {
                $ids[] = (int)$fila['id'];
            }
            $resultado->free();

            if ($ids !== []) {
                $idsSql = implode(',', $ids);
                if (!$db->query(
                    "UPDATE reservaciones
                     SET estado = 'expirada',
                         estado_changed_at = NOW()
                     WHERE id IN ({$idsSql})
                       AND estado = 'pendiente_verificacion'
                       AND hold_expires_at IS NOT NULL
                       AND hold_expires_at <= '{$ahora}'"
                )) {
                    throw new \RuntimeException($db->error);
                }
            }

            $db->commit();
            $transaccion = false;
            return [
                'ok' => true,
                'codigo' => self::OK,
                'procesadas' => count($ids),
                'omitidas' => 0,
                'fallidas' => 0,
                'ids' => $ids,
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionMantenimientoService::procesarPendientesVencidas - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    public static function vistaPreviaLimpieza(array $entrada): array
    {
        if (!self::disponible()) {
            return self::ambienteNoPermitido();
        }
        $filtros = self::normalizarFiltros($entrada);
        if (!($filtros['ok'] ?? false)) {
            return $filtros;
        }

        try {
            $seleccion = self::seleccionarLimpieza($filtros, false);
            return array_merge([
                'ok' => true,
                'codigo' => self::OK,
                'filtros' => $filtros,
            ], self::resumenSeleccion($seleccion));
        } catch (\Throwable $e) {
            error_log('ReservacionMantenimientoService::vistaPreviaLimpieza - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    public static function limpiar(array $entrada, array $opciones = []): array
    {
        if (!self::disponible()) {
            return self::ambienteNoPermitido();
        }
        $filtros = self::normalizarFiltros($entrada);
        if (!($filtros['ok'] ?? false)) {
            return $filtros;
        }
        if (trim((string)($entrada['confirmacion'] ?? '')) !== self::CONFIRMACION_LIMPIEZA) {
            return ['ok' => false, 'codigo' => self::CONFIRMACION_INVALIDA];
        }
        if (
            $filtros['incluir_pendientes_vigentes']
            && trim((string)($entrada['confirmacion_pendientes_vigentes'] ?? ''))
                !== self::CONFIRMACION_PENDIENTES_VIGENTES
        ) {
            return ['ok' => false, 'codigo' => self::CONFIRMACION_INVALIDA];
        }

        $db = ActiveRecord::getDB();
        $transaccion = false;
        try {
            $db->begin_transaction();
            $transaccion = true;
            $seleccion = self::seleccionarLimpieza($filtros, true);
            $ids = array_values(array_map(
                static fn(array $fila): int => (int)$fila['id'],
                $seleccion['procesables']
            ));

            if ($ids !== []) {
                $idsSql = implode(',', $ids);
                if (!$db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$idsSql})")) {
                    throw new \RuntimeException($db->error);
                }
                if (!$db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$idsSql})")) {
                    throw new \RuntimeException($db->error);
                }
                if (!empty($opciones['forzar_error'])) {
                    throw new \RuntimeException('Fallo intermedio solicitado por prueba.');
                }
                if (!$db->query("DELETE FROM reservaciones WHERE id IN ({$idsSql})")) {
                    throw new \RuntimeException($db->error);
                }
                if ($db->affected_rows !== count($ids)) {
                    throw new \RuntimeException('La selección cambió durante la limpieza.');
                }
            }

            $db->commit();
            $transaccion = false;
            $resumen = self::resumenSeleccion($seleccion);
            return [
                'ok' => true,
                'codigo' => self::OK,
                'procesadas' => count($ids),
                'omitidas' => $resumen['omitidas'],
                'fallidas' => 0,
                'ids' => $ids,
                'relaciones_eliminadas' => $resumen['relaciones'],
                'motivos_omision' => $resumen['motivos_omision'],
            ];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionMantenimientoService::limpiar - ' . $e->getMessage());
            return [
                'ok' => false,
                'codigo' => self::ERROR_INTERNO,
                'procesadas' => 0,
                'omitidas' => 0,
                'fallidas' => 1,
            ];
        }
    }

    private static function normalizarFiltros(array $entrada): array
    {
        $desde = trim((string)($entrada['fecha_desde'] ?? ''));
        $hasta = trim((string)($entrada['fecha_hasta'] ?? ''));
        if (!self::fechaValida($desde) || !self::fechaValida($hasta) || $desde > $hasta) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }

        $estadosEntrada = $entrada['estados'] ?? [];
        if (!is_array($estadosEntrada)) {
            $estadosEntrada = [$estadosEntrada];
        }
        $estados = array_values(array_unique(array_intersect(
            self::ESTADOS_LIMPIABLES,
            array_map('strval', $estadosEntrada)
        )));
        if ($estados === []) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS];
        }

        return [
            'ok' => true,
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'estados' => $estados,
            'prefijo' => substr(trim((string)($entrada['prefijo'] ?? '')), 0, 80),
            'incluir_pendientes_vigentes' => !empty($entrada['incluir_pendientes_vigentes']),
        ];
    }

    private static function seleccionarLimpieza(array $filtros, bool $bloquear): array
    {
        $db = ActiveRecord::getDB();
        $estadosSql = implode(',', array_map(
            static fn(string $estado): string => "'" . $estado . "'",
            $filtros['estados']
        ));
        $prefijoSql = '';
        if ($filtros['prefijo'] !== '') {
            $prefijo = $db->real_escape_string($filtros['prefijo']) . '%';
            $prefijoSql = " AND (r.nombre LIKE '{$prefijo}' OR r.contacto LIKE '{$prefijo}')";
        }
        $lock = $bloquear ? ' FOR UPDATE' : '';
        $resultado = $db->query(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM reservacion_mesas rm WHERE rm.reservacion_id = r.id) AS relaciones_mesas,
                    (SELECT COUNT(*) FROM verificaciones_contacto vc WHERE vc.reservacion_id = r.id) AS relaciones_verificaciones,
                    EXISTS(
                        SELECT 1 FROM tickets ta WHERE ta.reservacion_id = r.id
                    ) AS tiene_ticket,
                    EXISTS(
                        SELECT 1 FROM tickets tope
                        WHERE tope.reservacion_id = r.id
                          AND " . TicketMesa::condicionSqlAbierto('tope') . "
                    ) AS ticket_abierto
             FROM reservaciones r
             WHERE r.fecha BETWEEN '{$filtros['fecha_desde']}' AND '{$filtros['fecha_hasta']}'
               AND r.estado IN ({$estadosSql})
               {$prefijoSql}
             ORDER BY r.id{$lock}"
        );
        if (!$resultado) {
            throw new \RuntimeException($db->error);
        }

        $procesables = [];
        $omitidas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $motivo = self::motivoOmision($fila, $filtros);
            if ($motivo === '') {
                $procesables[] = $fila;
            } else {
                $omitidas[] = ['id' => (int)$fila['id'], 'motivo' => $motivo];
            }
        }
        $resultado->free();

        return ['procesables' => $procesables, 'omitidas_detalle' => $omitidas];
    }

    private static function motivoOmision(array $fila, array $filtros): string
    {
        if (!empty($fila['ticket_abierto'])) {
            return 'TICKET_ABIERTO';
        }
        if ((string)$fila['estado'] === 'en_curso') {
            return 'ESTADO_OPERATIVO';
        }
        if (
            !empty($fila['tiene_ticket'])
        ) {
            return 'EVIDENCIA_OPERATIVA';
        }
        if (
            (string)$fila['estado'] === 'pendiente_verificacion'
            && !$filtros['incluir_pendientes_vigentes']
            && ReservacionVigenciaService::clasificar($fila)['hold_vigente']
        ) {
            return 'PENDIENTE_VIGENTE';
        }

        return '';
    }

    private static function resumenSeleccion(array $seleccion): array
    {
        $relaciones = ['mesas' => 0, 'verificaciones' => 0, 'tickets' => 0];
        foreach ($seleccion['procesables'] as $fila) {
            $relaciones['mesas'] += (int)$fila['relaciones_mesas'];
            $relaciones['verificaciones'] += (int)$fila['relaciones_verificaciones'];
        }
        $motivos = [];
        foreach ($seleccion['omitidas_detalle'] as $omision) {
            $motivo = (string)$omision['motivo'];
            $motivos[$motivo] = ($motivos[$motivo] ?? 0) + 1;
        }

        return [
            'procesables' => count($seleccion['procesables']),
            'omitidas' => count($seleccion['omitidas_detalle']),
            'fallidas' => 0,
            'ids' => array_values(array_map(
                static fn(array $fila): int => (int)$fila['id'],
                $seleccion['procesables']
            )),
            'relaciones' => $relaciones,
            'motivos_omision' => $motivos,
        ];
    }

    private static function fechaValida(string $fecha): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes) !== 1) {
            return false;
        }

        return checkdate((int)$partes[2], (int)$partes[3], (int)$partes[1]);
    }

    private static function ahoraSql(\mysqli $db): string
    {
        return $db->real_escape_string(
            ReservacionConfig::ahora()->format('Y-m-d H:i:s')
        );
    }

    private static function ambienteNoPermitido(): array
    {
        return ['ok' => false, 'codigo' => self::AMBIENTE_NO_PERMITIDO];
    }
}
