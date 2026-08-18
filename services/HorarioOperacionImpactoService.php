<?php

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Reservacion;

/**
 * Autoridad única para comparar horario efectivo anterior/nuevo y mantener el
 * seguimiento que nace de esa comparación.
 */
final class HorarioOperacionImpactoService
{
    public const ESTADO_IMPACTO_PENDIENTE = 'pendiente';
    public const ESTADO_IMPACTO_RESUELTO = 'resuelto';
    public const ESTADO_ITEM_PENDIENTE = 'pendiente_notificacion';
    public const ESTADO_ITEM_ENCOLADO = 'notificacion_encolada';
    public const ESTADO_ITEM_SIN_CONTACTO = 'sin_contacto';
    public const ESTADO_ITEM_MANUAL = 'atendida_manual';
    public const ESTADO_ITEM_CLIENTE = 'resuelta_por_cliente';
    public const EVENTO_NOTIFICACION = 'reservation.schedule_change';

    /** @return array{semanal: array<int, array<string, mixed>>, excepciones: array<string, array<string, mixed>>} */
    public static function snapshotActual(): array
    {
        $semanal = [];
        foreach (\Model\HorarioOperacion::todosOrdenados() as $horario) {
            $semanal[(int)$horario->dia_semana] = [
                'abierto' => (int)$horario->abierto === 1,
                'hora_apertura' => self::hora((string)$horario->hora_apertura),
                'hora_cierre' => self::hora((string)$horario->hora_cierre),
            ];
        }

        $excepciones = [];
        foreach (\Model\ExcepcionOperacion::listarOrdenadas() as $excepcion) {
            $excepciones[(string)$excepcion->fecha] = [
                'id' => (int)$excepcion->id,
                'fecha' => (string)$excepcion->fecha,
                'tipo' => (string)$excepcion->tipo,
                'hora_apertura' => self::hora((string)$excepcion->hora_apertura),
                'hora_cierre' => self::hora((string)$excepcion->hora_cierre),
                'activo' => (int)$excepcion->activo === 1,
            ];
        }

        return ['semanal' => $semanal, 'excepciones' => $excepciones];
    }

    /**
     * Evalúa un cambio semanal contra la configuración que está persistida.
     * Sólo devuelve reservaciones que eran válidas antes y quedan inválidas.
     */
    public static function evaluarHorarioSemanal(array $horarios): array
    {
        $antes = self::snapshotActual();
        $despues = $antes;
        $despues['semanal'] = [];
        foreach ($horarios as $horario) {
            $dia = (int)($horario['dia_semana'] ?? -1);
            $despues['semanal'][$dia] = [
                'abierto' => (int)($horario['abierto'] ?? 0) === 1,
                'hora_apertura' => self::hora((string)($horario['hora_apertura'] ?? '')),
                'hora_cierre' => self::hora((string)($horario['hora_cierre'] ?? '')),
            ];
        }
        ksort($despues['semanal']);

        return self::evaluarSnapshots($antes, $despues);
    }

    /**
     * Evalúa create/edit/activate/deactivate de una excepción. Para eliminar,
     * pasa null como $despues.
     */
    public static function evaluarExcepcion(?array $datos, ?int $id = null): array
    {
        $antes = self::snapshotActual();
        $despues = $antes;

        if ($id !== null) {
            foreach ($despues['excepciones'] as $fecha => $excepcion) {
                if ((int)($excepcion['id'] ?? 0) === $id) {
                    unset($despues['excepciones'][$fecha]);
                }
            }
        }

        if ($datos !== null) {
            $fecha = (string)($datos['fecha'] ?? '');
            if ($fecha !== '') {
                $despues['excepciones'][$fecha] = [
                    'id' => $id ?? 0,
                    'fecha' => $fecha,
                    'tipo' => (string)($datos['tipo'] ?? 'cerrado'),
                    'hora_apertura' => self::hora((string)($datos['hora_apertura'] ?? '')),
                    'hora_cierre' => self::hora((string)($datos['hora_cierre'] ?? '')),
                    'activo' => (int)($datos['activo'] ?? 0) === 1,
                ];
            }
        }
        ksort($despues['excepciones']);

        return self::evaluarSnapshots($antes, $despues);
    }

    /**
     * Método puro de comparación, útil para pruebas y para no duplicar la
     * regla de "válida antes, fuera después" en cada mutación.
     *
     * @return array{antes: array, despues: array, conflictos: array<int, array<string, mixed>>}
     */
    public static function evaluarSnapshots(array $antes, array $despues): array
    {
        $conflictos = [];
        foreach (Reservacion::buscarFuturasActivas(
            ReservacionConfig::fechaActual(),
            ReservacionConfig::horaActual()
        ) as $reservacion) {
            $fecha = (string)$reservacion->fecha;
            $hora = self::hora((string)$reservacion->hora);
            if ($hora === null) {
                continue;
            }
            $validaAntes = self::estaDentro($fecha, $hora, $antes);
            $validaDespues = self::estaDentro($fecha, $hora, $despues);
            if ($validaAntes && !$validaDespues) {
                $conflictos[] = [
                    'id' => (int)$reservacion->id,
                    'reservacion_id' => (int)$reservacion->id,
                    'nombre' => (string)$reservacion->nombre,
                    'fecha' => $fecha,
                    'hora' => substr($hora, 0, 5),
                    'estado' => (string)$reservacion->estado,
                ];
            }
        }

        return ['antes' => $antes, 'despues' => $despues, 'conflictos' => $conflictos];
    }

    /**
     * Persiste un lote y sus filas hijas dentro de la transacción del cambio
     * de horario. No duplica un retry del mismo before/after.
     */
    public static function persistir(
        array $evaluacion,
        string $tipoOrigen,
        ?int $origenId,
        ?int $usuarioId
    ): ?int {
        $conflictos = (array)($evaluacion['conflictos'] ?? []);
        if ($conflictos === []) {
            return null;
        }

        $db = ActiveRecord::getDB();
        $dedupKey = self::dedupKey($tipoOrigen, $origenId, $evaluacion);
        $stmt = $db->prepare(
            "INSERT INTO horario_impactos
                (tipo_origen, origen_id, estado, dedup_key, created_by)
             VALUES (?, NULLIF(?, 0), 'pendiente', ?, NULLIF(?, 0))
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el impacto de horario.');
        }
        $origen = $origenId ?? 0;
        $usuario = $usuarioId ?? 0;
        $stmt->bind_param('sisi', $tipoOrigen, $origen, $dedupKey, $usuario);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $impactoId = (int)$db->insert_id;
        $stmt->close();
        if ($impactoId < 1) {
            throw new \RuntimeException('No fue posible identificar el impacto de horario.');
        }

        $ids = array_values(array_unique(array_map(
            static fn(array $conflicto): int => (int)($conflicto['reservacion_id'] ?? $conflicto['id'] ?? 0),
            $conflictos
        )));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            throw new \RuntimeException('El impacto no contiene reservaciones válidas.');
        }
        $listaIds = implode(',', $ids);
        $sql = "INSERT IGNORE INTO horario_impacto_reservaciones
                    (impacto_id, reservacion_id, estado)
                SELECT ?, r.id,
                    CASE
                        WHEN r.contacto_tipo IN ('email', 'telefono')
                             AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''
                        THEN 'pendiente_notificacion'
                        ELSE 'sin_contacto'
                    END
                FROM reservaciones r
                WHERE r.id IN ({$listaIds})";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar las reservaciones afectadas.');
        }
        $stmt->bind_param('i', $impactoId);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();

        return $impactoId;
    }

    public static function contarPendientes(): int
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT COUNT(*) AS total FROM horario_impactos WHERE estado = 'pendiente'"
        );
        if (!$resultado) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $total = (int)($resultado->fetch_assoc()['total'] ?? 0);
        $resultado->free();

        return $total;
    }

    /** Cuenta reservaciones que todavía requieren una acción del restaurante. */
    public static function contarPendientesReservaciones(): int
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT COUNT(*) AS total
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             WHERE i.estado = 'pendiente'
               AND ir.estado IN ('pendiente_notificacion', 'sin_contacto')"
        );
        if (!$resultado) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $total = (int)($resultado->fetch_assoc()['total'] ?? 0);
        $resultado->free();

        return $total;
    }

    public static function primerPendienteId(): ?int
    {
        $resultado = ActiveRecord::getDB()->query(
            "SELECT id FROM horario_impactos
             WHERE estado = 'pendiente'
             ORDER BY created_at ASC, id ASC LIMIT 1"
        );
        if (!$resultado) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $fila = $resultado->fetch_assoc();
        $resultado->free();

        return $fila ? (int)$fila['id'] : null;
    }

    /** @return array<string, mixed>|null */
    public static function obtener(int $impactoId): ?array
    {
        if ($impactoId < 1) {
            return null;
        }
        $db = ActiveRecord::getDB();
        $stmt = $db->prepare(
            "SELECT i.id AS impacto_id, i.tipo_origen, i.origen_id, i.estado AS impacto_estado,
                    i.created_at AS impacto_created_at, ir.id AS impacto_reservacion_id,
                    ir.reservacion_id, ir.estado, ir.resolved_by, ir.resolved_at,
                    r.nombre, r.contacto_tipo, r.contacto, r.fecha, r.hora, r.comensales,
                    n.estado AS notificacion_estado
             FROM horario_impactos i
             JOIN horario_impacto_reservaciones ir ON ir.impacto_id = i.id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             LEFT JOIN reservacion_notificaciones n
               ON n.impacto_reservacion_id = ir.id
             WHERE i.id = ?
             ORDER BY
                 CASE WHEN r.contacto_tipo IN ('email', 'telefono')
                           AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''
                      THEN 0 ELSE 1 END,
                 r.fecha ASC, r.hora ASC, r.id ASC"
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible consultar el seguimiento.');
        }
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $filas = [];
        $impacto = null;
        while ($fila = $resultado->fetch_assoc()) {
            $impacto ??= [
                'id' => (int)$fila['impacto_id'],
                'tipo_origen' => (string)$fila['tipo_origen'],
                'origen_id' => $fila['origen_id'] !== null ? (int)$fila['origen_id'] : null,
                'estado' => (string)$fila['impacto_estado'],
                'created_at' => (string)$fila['impacto_created_at'],
            ];
            $tieneContacto = self::filaTieneContacto($fila);
            $filas[] = [
                'id' => (int)$fila['impacto_reservacion_id'],
                'reservacion_id' => (int)$fila['reservacion_id'],
                'nombre' => (string)$fila['nombre'],
                'fecha' => (string)$fila['fecha'],
                'hora' => substr((string)$fila['hora'], 0, 5),
                'comensales' => (int)$fila['comensales'],
                'contacto_tipo' => $tieneContacto ? (string)$fila['contacto_tipo'] : 'ninguno',
                'contacto' => $tieneContacto
                    ? ContactoService::enmascarar((string)$fila['contacto_tipo'], (string)$fila['contacto'])
                    : '',
                'tiene_contacto' => $tieneContacto,
                'estado' => (string)$fila['estado'],
                'notificacion_estado' => $fila['notificacion_estado'] !== null
                    ? (string)$fila['notificacion_estado'] : null,
                'manual_habilitada' => false,
                'test_link_disponible' => self::esEntornoPruebas() && in_array(
                    (string)$fila['estado'],
                    [self::ESTADO_ITEM_ENCOLADO],
                    true
                ),
            ];
        }
        $stmt->close();
        if ($impacto === null) {
            return null;
        }

        $impacto['reservaciones'] = $filas;
        $manualHabilitada = self::faseManualDisponible($impactoId);
        foreach ($impacto['reservaciones'] as &$fila) {
            $fila['manual_habilitada'] = $manualHabilitada && !$fila['tiene_contacto'];
        }
        unset($fila);
        $impacto['manual_habilitada'] = $manualHabilitada;
        $impacto['pendientes'] = count(array_filter(
            $impacto['reservaciones'],
            static fn(array $fila): bool => in_array(
                $fila['estado'],
                [self::ESTADO_ITEM_PENDIENTE, self::ESTADO_ITEM_SIN_CONTACTO],
                true
            )
        ));

        return $impacto;
    }

    /** Encola una única notificación y devuelve el secreto sólo en desarrollo. */
    public static function encolarNotificacion(int $impactoId, int $impactoReservacionId, ?int $adminId): array
    {
        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId, $adminId): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            if ((string)$fila['estado'] === self::ESTADO_ITEM_ENCOLADO) {
                return [
                    'ok' => true,
                    'codigo' => 'AVISO_ENCOLADO',
                    'impacto_id' => (int)$fila['impacto_id'],
                    'impacto_reservacion_id' => (int)$fila['impacto_reservacion_id'],
                    'idempotente' => true,
                ];
            }
            if (!self::filaTieneContacto($fila) || (string)$fila['estado'] !== self::ESTADO_ITEM_PENDIENTE) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_NOTIFICABLE'];
            }

            $dedup = 'schedule_change:' . (int)$fila['impacto_reservacion_id'];
            $stmt = $db->prepare(
                "INSERT INTO reservacion_notificaciones
                    (impacto_reservacion_id, reservacion_id, evento, estado, dedup_key, available_at)
                 VALUES (?, ?, ?, 'pendiente', ?, NOW())
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible preparar la notificación.');
            }
            $evento = self::EVENTO_NOTIFICACION;
            $impactoReservacion = (int)$fila['impacto_reservacion_id'];
            $reservacion = (int)$fila['reservacion_id'];
            $stmt->bind_param('iiss', $impactoReservacion, $reservacion, $evento, $dedup);
            if (!$stmt->execute()) {
                $mensaje = $stmt->error;
                $stmt->close();
                throw new \RuntimeException($mensaje);
            }
            $stmt->close();
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'notificacion_encolada', resolved_by = NULL, resolved_at = NULL
                 WHERE id = ? AND estado = 'pendiente_notificacion'"
            );
            $stmt->bind_param('i', $impactoReservacion);
            $stmt->execute();
            $stmt->close();
            $link = self::crearMagicLink($db, $fila, $adminId);
            self::actualizarEstadoImpacto($db, (int)$fila['impacto_id']);

            $respuesta = [
                'ok' => true,
                'codigo' => 'AVISO_ENCOLADO',
                'impacto_id' => (int)$fila['impacto_id'],
                'impacto_reservacion_id' => $impactoReservacion,
            ];
            if (self::esEntornoPruebas()) {
                $respuesta['test_redirect_url'] = $link;
            }

            return $respuesta;
        });
    }

    public static function encolarDisponibles(int $impactoId, ?int $adminId): array
    {
        $impacto = self::obtener($impactoId);
        if (!$impacto) {
            return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
        }
        $encoladas = [];
        foreach ((array)$impacto['reservaciones'] as $fila) {
            if ($fila['tiene_contacto'] && $fila['estado'] === self::ESTADO_ITEM_PENDIENTE) {
                $resultado = self::encolarNotificacion($impactoId, (int)$fila['id'], $adminId);
                if (($resultado['ok'] ?? false) === true) {
                    $encoladas[] = $resultado;
                }
            }
        }

        return ['ok' => true, 'codigo' => 'AVISOS_ENCOLADOS', 'encoladas' => $encoladas];
    }

    public static function agregarContacto(
        int $impactoId,
        int $impactoReservacionId,
        string $tipo,
        string $contacto,
        ?int $adminId
    ): array {
        try {
            $normalizado = ContactoService::normalizar($tipo, $contacto);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'codigo' => 'CONTACTO_INVALIDO'];
        }

        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId, $tipo, $normalizado): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || (string)$fila['estado'] !== self::ESTADO_ITEM_SIN_CONTACTO) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            if (!self::faseManualDisponible($impactoId)) {
                return ['ok' => false, 'codigo' => 'NOTIFICACIONES_PENDIENTES'];
            }

            $stmt = $db->prepare(
                "UPDATE reservaciones
                 SET contacto_tipo = ?, contacto = ?
                 WHERE id = ? AND contacto_tipo = 'ninguno' AND (contacto IS NULL OR TRIM(contacto) = '')"
            );
            if (!$stmt) {
                throw new \RuntimeException('No fue posible preparar el contacto.');
            }
            $reservacion = (int)$fila['reservacion_id'];
            $stmt->bind_param('ssi', $tipo, $normalizado, $reservacion);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                return ['ok' => false, 'codigo' => 'CONTACTO_NO_EDITABLE'];
            }
            $stmt->close();
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'pendiente_notificacion', resolved_by = NULL, resolved_at = NULL
                 WHERE id = ? AND estado = 'sin_contacto'"
            );
            $stmt->bind_param('i', $impactoReservacionId);
            $stmt->execute();
            $stmt->close();

            return ['ok' => true, 'codigo' => 'CONTACTO_AGREGADO'];
        });
    }

    public static function atenderManual(int $impactoId, int $impactoReservacionId, ?int $adminId): array
    {
        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId, $adminId): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || (string)$fila['estado'] !== self::ESTADO_ITEM_SIN_CONTACTO) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            if (!self::faseManualDisponible($impactoId)) {
                return ['ok' => false, 'codigo' => 'NOTIFICACIONES_PENDIENTES'];
            }
            $stmt = $db->prepare(
                "UPDATE horario_impacto_reservaciones
                 SET estado = 'atendida_manual', resolved_by = NULLIF(?, 0), resolved_at = NOW()
                 WHERE id = ? AND estado = 'sin_contacto'"
            );
            $usuario = $adminId ?? 0;
            $stmt->bind_param('ii', $usuario, $impactoReservacionId);
            $stmt->execute();
            $stmt->close();
            self::actualizarEstadoImpacto($db, $impactoId);

            return ['ok' => true, 'codigo' => 'AFECTACION_ATENDIDA_MANUALMENTE'];
        });
    }

    public static function regenerarLinkDePrueba(int $impactoId, int $impactoReservacionId, ?int $adminId): array
    {
        if (!self::esEntornoPruebas()) {
            return ['ok' => false, 'codigo' => 'NO_DISPONIBLE'];
        }

        return self::conTransaccion(function (\mysqli $db) use ($impactoId, $impactoReservacionId, $adminId): array {
            $fila = self::bloquearFilaImpacto($db, $impactoId, $impactoReservacionId);
            if (!$fila || (string)$fila['estado'] !== self::ESTADO_ITEM_ENCOLADO) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
            }
            if (!self::filaTieneContacto($fila)) {
                return ['ok' => false, 'codigo' => 'AFECTACION_NO_NOTIFICABLE'];
            }
            $link = self::crearMagicLink($db, $fila, $adminId);

            return [
                'ok' => true,
                'codigo' => 'LINK_PRUEBA_GENERADO',
                'test_redirect_url' => $link,
            ];
        });
    }

    /** Resuelve todos los lotes activos de la reservación si el reemplazo ya es válido. */
    public static function resolverPorCliente(int $reservacionId, string $fecha, string $hora, ?int $adminId = null): bool
    {
        try {
            $esValida = HorarioOperacionService::estaAbierto($fecha, $hora);
        } catch (\Throwable $e) {
            error_log('HorarioOperacionImpactoService::resolverPorCliente - ' . $e->getMessage());
            return false;
        }
        if ($reservacionId < 1 || !$esValida) {
            return false;
        }

        $resultado = self::conTransaccion(function (\mysqli $db) use ($reservacionId): bool {
            $stmt = $db->prepare(
                "SELECT ir.id, ir.impacto_id
                 FROM horario_impacto_reservaciones ir
                 JOIN horario_impactos i ON i.id = ir.impacto_id
                 WHERE ir.reservacion_id = ?
                   AND ir.estado = 'notificacion_encolada'
                 FOR UPDATE"
            );
            $stmt->bind_param('i', $reservacionId);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $filas = [];
            while ($fila = $resultado->fetch_assoc()) {
                $filas[] = $fila;
            }
            $stmt->close();
            foreach ($filas as $fila) {
                $actualizacion = $db->prepare(
                    "UPDATE horario_impacto_reservaciones
                     SET estado = 'resuelta_por_cliente', resolved_by = NULL, resolved_at = NOW()
                     WHERE id = ?"
                );
                $id = (int)$fila['id'];
                $actualizacion->bind_param('i', $id);
                $actualizacion->execute();
                $actualizacion->close();
                self::actualizarEstadoImpacto($db, (int)$fila['impacto_id']);
            }

            return $filas !== [];
        });

        return is_bool($resultado) ? $resultado : (bool)($resultado['ok'] ?? false);
    }

    public static function faseManualDisponible(int $impactoId): bool
    {
        $db = ActiveRecord::getDB();
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS total
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.impacto_id = ?
               AND ir.estado = 'pendiente_notificacion'
               AND r.contacto_tipo IN ('email', 'telefono')
               AND r.contacto IS NOT NULL AND TRIM(r.contacto) <> ''"
        );
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $total === 0;
    }

    public static function horarioValidoEnSnapshot(string $fecha, string $hora, array $snapshot): bool
    {
        $excepcion = $snapshot['excepciones'][$fecha] ?? null;
        if (is_array($excepcion) && !empty($excepcion['activo'])) {
            if (($excepcion['tipo'] ?? '') !== 'horario_especial') {
                return false;
            }
            return self::entre($hora, $excepcion['hora_apertura'] ?? null, $excepcion['hora_cierre'] ?? null);
        }

        $fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, ReservacionConfig::timezone());
        if (!$fechaObjeto instanceof DateTimeImmutable) {
            return false;
        }
        $horario = $snapshot['semanal'][(int)$fechaObjeto->format('w')] ?? null;
        if (!is_array($horario) || empty($horario['abierto'])) {
            return false;
        }

        return self::entre($hora, $horario['hora_apertura'] ?? null, $horario['hora_cierre'] ?? null);
    }

    private static function estaDentro(string $fecha, string $hora, array $snapshot): bool
    {
        return self::horarioValidoEnSnapshot($fecha, $hora, $snapshot);
    }

    private static function entre(string $hora, ?string $apertura, ?string $cierre): bool
    {
        return $apertura !== null && $cierre !== null && $hora >= $apertura && $hora < $cierre;
    }

    private static function hora(string $hora): ?string
    {
        $hora = trim($hora);
        if ($hora === '') {
            return null;
        }
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $hora) === 1
            ? substr($hora, 0, 5) . ':00'
            : null;
    }

    private static function dedupKey(string $tipo, ?int $id, array $evaluacion): string
    {
        $antes = $evaluacion['antes'] ?? [];
        $despues = $evaluacion['despues'] ?? [];
        return hash('sha256', $tipo . '|' . ($id ?? 0) . '|' . json_encode($antes) . '|' . json_encode($despues));
    }

    /** @param array<string, mixed> $fila */
    private static function filaTieneContacto(array $fila): bool
    {
        return in_array((string)($fila['contacto_tipo'] ?? ''), ['email', 'telefono'], true)
            && trim((string)($fila['contacto'] ?? '')) !== '';
    }

    /** @return array<string, mixed> */
    private static function bloquearFilaImpacto(\mysqli $db, int $impactoId, int $impactoReservacionId): ?array
    {
        $stmt = $db->prepare(
            "SELECT ir.id AS impacto_reservacion_id, ir.impacto_id, ir.reservacion_id, ir.estado,
                    r.contacto_tipo, r.contacto
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.id = ? AND ir.impacto_id = ?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('ii', $impactoReservacionId, $impactoId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila;
    }

    /** @param array<string, mixed> $fila */
    private static function crearMagicLink(\mysqli $db, array $fila, ?int $adminId): string
    {
        $base = ReservacionConfig::reservationPublicBaseUrl();
        if ($base === '') {
            throw new \RuntimeException('RESERVATION_PUBLIC_BASE_URL no está configurada.');
        }
        $stmt = $db->prepare(
            "UPDATE reservacion_magic_links
             SET invalidated_at = NOW()
             WHERE impacto_reservacion_id = ? AND used_at IS NULL AND invalidated_at IS NULL"
        );
        $impactoReservacion = (int)$fila['impacto_reservacion_id'];
        $stmt->bind_param('i', $impactoReservacion);
        $stmt->execute();
        $stmt->close();

        $publicId = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expira = ReservacionConfig::ahora()
            ->modify('+' . ReservacionConfig::scheduleChangeLinkTtlHours() . ' hours')
            ->format('Y-m-d H:i:s');
        $purpose = 'schedule_change';
        $reservacion = (int)$fila['reservacion_id'];
        $stmt = $db->prepare(
            "INSERT INTO reservacion_magic_links
                (public_id, reservacion_id, impacto_reservacion_id, purpose, token_hash, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('siisss', $publicId, $reservacion, $impactoReservacion, $purpose, $tokenHash, $expira);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new \RuntimeException($mensaje);
        }
        $stmt->close();

        return $base . '/reservaciones/acceso-cambio-horario?id=' . rawurlencode($publicId) . '#token=' . rawurlencode($token);
    }

    private static function actualizarEstadoImpacto(\mysqli $db, int $impactoId): void
    {
        $stmt = $db->prepare(
            "UPDATE horario_impactos i
             LEFT JOIN (
                 SELECT impacto_id,
                        SUM(estado IN ('pendiente_notificacion', 'sin_contacto')) AS pendientes
                 FROM horario_impacto_reservaciones
                 GROUP BY impacto_id
             ) pendientes ON pendientes.impacto_id = i.id
             SET i.estado = CASE WHEN COALESCE(pendientes.pendientes, 0) = 0
                                 THEN 'resuelto' ELSE 'pendiente' END,
                 i.resolved_at = CASE WHEN COALESCE(pendientes.pendientes, 0) = 0
                                      THEN COALESCE(i.resolved_at, NOW()) ELSE NULL END
             WHERE i.id = ?"
        );
        $stmt->bind_param('i', $impactoId);
        $stmt->execute();
        $stmt->close();
    }

    private static function esEntornoPruebas(): bool
    {
        return in_array(ReservacionConfig::appEnvironment(), ['development', 'testing'], true);
    }

    /** @param callable(\mysqli): array|bool $callback */
    private static function conTransaccion(callable $callback): array|bool
    {
        $db = ActiveRecord::getDB();
        $iniciada = false;
        try {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la operación de seguimiento.');
            }
            $iniciada = true;
            $resultado = $callback($db);
            $esFallo = is_array($resultado)
                ? (($resultado['ok'] ?? true) === false)
                : $resultado === false;
            if ($esFallo) {
                $db->rollback();
                return $resultado;
            }
            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la operación de seguimiento.');
            }
            return $resultado;
        } catch (\Throwable $e) {
            if ($iniciada) {
                $db->rollback();
            }
            error_log('HorarioOperacionImpactoService - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => 'ERROR_SEGUIMIENTO_HORARIO'];
        }
    }
}
