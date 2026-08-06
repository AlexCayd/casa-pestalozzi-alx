<?php

namespace Services;

use InvalidArgumentException;
use Model\ActiveRecord;
use Model\Reservacion;
use Model\ReservacionMesa;
use Model\TicketMesa;
use Model\VerificacionContacto;

/**
 * Fachada transaccional del backoffice de reservaciones.
 *
 * El flujo publico conserva su contrato binario. Este servicio expone para
 * administracion la capacidad estimada, la propuesta canonica y las
 * advertencias que deben confirmarse antes de guardar.
 */
final class ReservacionAdministrativaService
{
    public const SIN_CONTACTO = 'SIN_CONTACTO';
    public const SIN_ASIGNACION = 'SIN_ASIGNACION';
    public const CAPACIDAD_INSUFICIENTE = 'CAPACIDAD_INSUFICIENTE';
    public const CONTACTO_TIPO_NO_EDITABLE = 'CONTACTO_TIPO_NO_EDITABLE';

    /** @return array<string, mixed> */
    public static function consultarDisponibilidad(
        string $fecha,
        $personas,
        int $excluirReservacionId = 0,
        ?string $horaSolicitada = null
    ): array {
        $personasValidas = filter_var($personas, FILTER_VALIDATE_INT);
        if ($personasValidas === false
            || $personasValidas < 1
            || $personasValidas > ReservacionConfig::MAX_COMENSALES_ADMIN
        ) {
            return [
                'ok' => false,
                'codigo' => ReservacionService::DATOS_INVALIDOS,
                'fecha' => trim($fecha),
                'personas' => $personasValidas === false ? null : (int)$personasValidas,
                'horarios' => [],
                'detalle_horarios' => [],
                'disponible' => false,
                'horario_valido' => false,
            ];
        }

        $calendario = HorarioReservacionService::resolverFecha(trim($fecha));
        $fechaResuelta = (string)($calendario['fecha'] ?? trim($fecha));
        $fechaInvalida = in_array((string)($calendario['codigo'] ?? ''), [
            HorarioReservacionService::FECHA_INVALIDA,
            HorarioReservacionService::FECHA_PASADA,
            'FECHA_FUERA_DE_HORIZONTE',
            HorarioReservacionService::ERROR_INTERNO,
        ], true);
        $base = [
            'ok' => !$fechaInvalida && (bool)($calendario['reservable'] ?? false),
            'codigo' => $calendario['codigo'] ?? ReservacionService::HORARIO_INVALIDO,
            'fecha' => $fechaResuelta,
            'personas' => (int)$personasValidas,
            'abierto' => (bool)($calendario['abierto'] ?? false),
            'reservable' => (bool)($calendario['reservable'] ?? false),
            'horarios' => [],
            'detalle_horarios' => [],
            'alternativas' => [],
            'disponible' => false,
            'horario_valido' => false,
            'motivo' => $calendario['motivo_no_disponible'] ?? null,
            'detalle_horario' => $calendario['detalle_horario'] ?? null,
        ];

        if (!$base['ok']) {
            return $base;
        }

        $alternativas = [];
        foreach ((array)($calendario['horarios'] ?? []) as $hora) {
            $horaCorta = substr((string)$hora, 0, 5);
            $evaluacion = self::evaluarDisponibilidad(
                $fechaResuelta,
                $horaCorta,
                (int)$personasValidas,
                $excluirReservacionId,
                false
            );
            // Para administracion, "disponible" significa horario valido. La
            // capacidad y las mesas se muestran como decisiones separadas.
            $slot = [
                'hora' => $horaCorta,
                'disponible' => (bool)($evaluacion['horario_valido'] ?? false),
                'horario_valido' => (bool)($evaluacion['horario_valido'] ?? false),
                'capacidad_estimada_suficiente' => (bool)($evaluacion['capacidad_estimada_suficiente'] ?? false),
                'capacidad_estimada' => (int)($evaluacion['capacidad_estimada'] ?? 0),
                'capacidad_estimada_horario' => (int)($evaluacion['capacidad_estimada'] ?? 0),
                'capacidad_total' => (int)($evaluacion['capacidad_total'] ?? 0),
                'capacidad_realmente_libre' => (int)($evaluacion['capacidad_realmente_libre'] ?? 0),
                'capacidad_proyectada' => (int)($evaluacion['capacidad_proyectada'] ?? 0),
                'asignacion_automatica_posible' => (bool)($evaluacion['asignacion_automatica_posible'] ?? false),
                'asignacion_automatica_habilitada' => (bool)($evaluacion['asignacion_automatica_habilitada'] ?? false),
                'mesa_ids' => array_values(array_map('intval', (array)($evaluacion['mesa_ids'] ?? []))),
                'capacidad_asignada' => (int)($evaluacion['capacidad_asignada'] ?? 0),
                'requiere_asignacion_manual' => (bool)($evaluacion['requiere_asignacion_manual'] ?? true),
                'nivel_advertencia' => (string)($evaluacion['nivel_advertencia'] ?? 'ninguno'),
                'warnings' => array_values((array)($evaluacion['warnings'] ?? [])),
                'depende_liberacion_proyectada' => (bool)($evaluacion['depende_liberacion_proyectada'] ?? false),
            ];
            $base['horarios'][] = ['hora' => $horaCorta, 'disponible' => $slot['disponible']];
            $base['detalle_horarios'][$horaCorta] = $slot;
            if ($slot['disponible'] && count($alternativas) < ReservacionConfig::MAX_HORARIOS_ALTERNATIVOS) {
                $alternativas[] = $horaCorta;
            }
        }

        $horaSolicitada = $horaSolicitada !== null
            ? HorarioReservacionService::normalizarHoraCorta($horaSolicitada)
            : '';
        $base['hora'] = $horaSolicitada !== '' ? $horaSolicitada : null;
        $seleccion = $horaSolicitada !== ''
            ? ($base['detalle_horarios'][$horaSolicitada] ?? null)
            : null;
        $base['horario_valido'] = $seleccion !== null
            ? (bool)$seleccion['horario_valido']
            : $base['horarios'] !== [];
        $base['disponible'] = $base['horario_valido'];
        $base['motivo'] = $base['disponible'] ? 'horario_valido' : 'sin_horario_valido';
        $base['alternativas'] = $horaSolicitada !== '' && !$base['disponible'] ? $alternativas : [];

        if ($seleccion !== null) {
            foreach ($seleccion as $campo => $valor) {
                if ($campo !== 'hora') {
                    $base[$campo] = $valor;
                }
            }
        }
        $base['horarios_alternativos'] = $alternativas;

        return $base;
    }

    /** @return array<string, mixed> */
    public static function evaluarDisponibilidad(
        string $fecha,
        string $hora,
        int $personas,
        int $excluirReservacionId = 0,
        bool $bloquear = false
    ): array {
        $resultado = [
            'ok' => false,
            'codigo' => ReservacionService::DATOS_INVALIDOS,
            'fecha' => $fecha,
            'hora' => HorarioReservacionService::normalizarHoraCorta($hora),
            'comensales' => $personas,
            'horario_valido' => false,
            'capacidad_estimada_suficiente' => false,
            'capacidad_estimada' => 0,
            'capacidad_total' => 0,
            'capacidad_realmente_libre' => 0,
            'capacidad_proyectada' => 0,
            'capacidad_asignada' => 0,
            'mesa_ids' => [],
            'asignacion_automatica_solicitada' => false,
            'asignacion_automatica_habilitada' => $personas <= ReservacionConfig::MAX_COMENSALES_PUBLICO,
            'asignacion_automatica_posible' => false,
            'requiere_asignacion_manual' => true,
            'nivel_advertencia' => 'ninguno',
            'warnings' => [],
            'depende_liberacion_proyectada' => false,
            'ocupacion' => [],
        ];

        if ($personas < 1 || $personas > ReservacionConfig::MAX_COMENSALES_ADMIN) {
            return $resultado;
        }

        $horario = HorarioReservacionService::validarHora($fecha, $hora);
        if (!($horario['ok'] ?? false)) {
            $resultado['codigo'] = $horario['codigo'] ?? ReservacionService::HORARIO_INVALIDO;
            return $resultado + ['siguiente_horario_valido' => $horario['siguiente_horario_valido'] ?? null];
        }

        $resultado['ok'] = true;
        $resultado['codigo'] = ReservacionService::CONFIRMADA;
        $resultado['fecha'] = (string)$horario['fecha'];
        $resultado['hora'] = (string)$horario['hora_corta'];
        $resultado['horario_valido'] = true;
        $ocupacion = OcupacionMesasService::evaluarHorario(
            (string)$horario['fecha'],
            (string)$horario['hora'],
            $excluirReservacionId,
            $bloquear
        );
        $resultado['ocupacion'] = $ocupacion;
        $mesas = MesaProxy::reservables();
        $capacidad = OcupacionMesasService::resumenCapacidad($mesas, $ocupacion);
        $resultado['capacidad_total'] = (int)$capacidad['capacidad_total'];
        $resultado['capacidad_realmente_libre'] = (int)$capacidad['capacidad_realmente_libre'];
        $resultado['capacidad_proyectada'] = (int)$capacidad['capacidad_proyectada'];
        $resultado['capacidad_estimada'] = (int)$capacidad['capacidad_estimada_horario'];
        $resultado['capacidad_estimada_suficiente'] = $resultado['capacidad_estimada'] >= $personas;
        $disponibles = array_values(array_filter(
            $mesas,
            static fn($mesa): bool => !empty($ocupacion['mesas'][(int)$mesa->id]['disponible'])
        ));
        usort($disponibles, static fn($a, $b): int => ((int)$a->numero <=> (int)$b->numero) ?: ((int)$a->id <=> (int)$b->id));

        if ($personas <= ReservacionConfig::MAX_COMENSALES_PUBLICO) {
            $seleccion = AsignacionMesasService::seleccionarMesasGeneral(
                $disponibles,
                $personas,
                (array)($ocupacion['mesa_ids_proyectadas'] ?? [])
            );
            $resultado['asignacion_automatica_posible'] = $seleccion !== [];
            $resultado['mesa_ids'] = array_map(static fn($mesa): int => (int)$mesa->id, $seleccion);
            $resultado['capacidad_asignada'] = AsignacionMesasService::capacidadTotal($seleccion);
            $resultado['depende_liberacion_proyectada'] = array_intersect(
                $resultado['mesa_ids'],
                array_map('intval', (array)($ocupacion['mesa_ids_proyectadas'] ?? []))
            ) !== [];
        }

        $warnings = [];
        if (!$resultado['capacidad_estimada_suficiente']) {
            $warnings[] = self::CAPACIDAD_INSUFICIENTE;
        }
        $resultado['warnings'] = $warnings;
        $resultado['nivel_advertencia'] = $warnings !== [] ? 'reforzada' : 'ninguno';

        return $resultado;
    }

    /** @return array<string, mixed> */
    public static function crear(array $post, ?int $usuarioId = null): array
    {
        $validacion = self::validarEntrada($post);
        if (!$validacion['ok']) {
            return $validacion;
        }
        $datos = $validacion['datos'];
        $requestToken = trim((string)($post['request_token'] ?? ''));
        if ($requestToken === '') {
            $requestToken = ReservacionService::generarRequestToken();
        }
        if (preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $requestToken) !== 1) {
            return self::errorDatos('REQUEST_TOKEN_INVALIDO', 'request_token');
        }

        $solicitaAsignacion = !array_key_exists('asignar_automaticamente', $post)
            || (string)$post['asignar_automaticamente'] === '1';
        $confirmaciones = self::confirmaciones($post);
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $lockFecha = false;
        $transaccion = false;
        try {
            $lockHorario = HorarioConfigLock::adquirir($db, 10);
            if (!$lockHorario || !FechaOperacionLock::adquirir($db, $datos['fecha'], 10)) {
                return ['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO];
            }
            $lockFecha = true;
            $db->begin_transaction();
            $transaccion = true;

            $existente = Reservacion::buscarPorRequestToken($requestToken);
            if ($existente) {
                $db->commit();
                $transaccion = false;
                return self::resultadoExistente($existente);
            }

            $revalidacion = self::evaluarDisponibilidad($datos['fecha'], $datos['hora'], $datos['comensales'], 0, true);
            if (!($revalidacion['horario_valido'] ?? false)) {
                return self::rollback($db, self::errorHorario($revalidacion));
            }
            $warningCodes = self::warningCodes($datos, $revalidacion, $solicitaAsignacion, false);
            $faltantes = array_values(array_diff($warningCodes, $confirmaciones));
            if ($faltantes !== []) {
                return self::rollback($db, self::respuestaAdvertencias($faltantes, $revalidacion, $datos));
            }

            $reservacion = new Reservacion();
            $reservacion->nombre = $datos['nombre'];
            $reservacion->contacto_tipo = $datos['contacto_tipo'];
            $reservacion->contacto = $datos['contacto'];
            $reservacion->fecha = $datos['fecha'];
            $reservacion->hora = $datos['hora'];
            $reservacion->comensales = $datos['comensales'];
            $reservacion->nota = $datos['nota'];
            $reservacion->comentario_admin = $datos['comentario_admin'];
            $reservacion->request_token = $requestToken;
            $guardado = $reservacion->crearAdministrativa();
            if (!($guardado['resultado'] ?? false)) {
                throw new \RuntimeException('No se pudo guardar la reservacion administrativa.');
            }
            $id = (int)$guardado['id'];
            $asignacion = ['ok' => false, 'mesa_ids' => [], 'codigo' => AsignacionMesasService::SIN_CAPACIDAD];
            if ($solicitaAsignacion && $datos['comensales'] <= ReservacionConfig::MAX_COMENSALES_PUBLICO) {
                $asignacion = AsignacionMesasService::asignarAutomaticamente($id, false);
                if (!($asignacion['ok'] ?? false)) {
                    $faltantes = array_values(array_unique(array_merge($warningCodes, [self::SIN_ASIGNACION])));
                    $faltantes = array_values(array_diff($faltantes, $confirmaciones));
                    if ($faltantes !== []) {
                        return self::rollback($db, self::respuestaAdvertencias($faltantes, $revalidacion, $datos));
                    }
                }
            }

            $db->commit();
            $transaccion = false;
            return self::resultadoGuardado($id, $datos, $revalidacion, $asignacion, false);
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionAdministrativaService::crear - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO];
        } finally {
            if ($lockFecha) {
                FechaOperacionLock::liberar($db, $datos['fecha']);
            }
            if ($lockHorario) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function actualizar(int $id, array $post, ?int $usuarioId = null): array
    {
        if ($id < 1) {
            return ['ok' => false, 'codigo' => ReservacionService::RESERVACION_NO_EXISTE];
        }
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $locks = [];
        $transaccion = false;
        try {
            $lockHorario = HorarioConfigLock::adquirir($db, 10);
            if (!$lockHorario) {
                return ['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO];
            }
            $filaInicial = self::fila("SELECT id, fecha FROM reservaciones WHERE id = {$id} LIMIT 1");
            if (!$filaInicial) {
                return ['ok' => false, 'codigo' => ReservacionService::RESERVACION_NO_EXISTE];
            }
            $fechas = array_values(array_unique([(string)$filaInicial['fecha'], trim((string)($post['fecha'] ?? ''))]));
            sort($fechas, SORT_STRING);
            foreach ($fechas as $fecha) {
                if ($fecha !== '') {
                    if (!FechaOperacionLock::adquirir($db, $fecha, 10)) {
                        return ['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO];
                    }
                    $locks[] = $fecha;
                }
            }
            $db->begin_transaction();
            $transaccion = true;
            $fila = self::fila("SELECT * FROM reservaciones WHERE id = {$id} LIMIT 1 FOR UPDATE");
            if (!$fila) {
                return self::rollback($db, self::RESERVACION_NO_EXISTE);
            }
            if ((string)$fila['estado'] !== 'confirmada') {
                return self::rollback($db, ReservacionService::ESTADO_NO_EDITABLE);
            }

            $validacion = self::validarEntrada($post, $fila);
            if (!$validacion['ok']) {
                return self::rollback($db, $validacion);
            }
            $datos = $validacion['datos'];
            $solicitaAsignacion = (string)($post['asignar_automaticamente'] ?? '0') === '1';
            $revalidacion = self::evaluarDisponibilidad($datos['fecha'], $datos['hora'], $datos['comensales'], $id, true);
            if (!($revalidacion['horario_valido'] ?? false)) {
                return self::rollback($db, self::errorHorario($revalidacion));
            }

            $actuales = ReservacionMesa::obtenerIdsPorReservacion($id);
            $preservar = false;
            if (!$solicitaAsignacion && $actuales !== []) {
                $mesasActuales = \Model\Mesa::reservablesParaActualizar($actuales);
                $ocupacion = AsignacionMesasService::obtenerOcupacionParaHorario($datos['fecha'], $datos['hora'], $id, true);
                $preservar = count($mesasActuales) === count($actuales)
                    && !AsignacionMesasService::hayConflictoHorario($ocupacion, $actuales)
                    && AsignacionMesasService::validarCapacidad($mesasActuales, $actuales, $datos['comensales']);
            }
            $warningCodes = self::warningCodes($datos, $revalidacion, $solicitaAsignacion, $preservar);
            $confirmaciones = self::confirmaciones($post);
            $faltantes = array_values(array_diff($warningCodes, $confirmaciones));
            if ($faltantes !== []) {
                return self::rollback($db, self::respuestaAdvertencias($faltantes, $revalidacion, $datos));
            }

            if ($actuales !== [] && (!$preservar || $solicitaAsignacion)) {
                ReservacionMesa::eliminarAsignacion($id);
            }
            self::actualizarFila($id, $datos);

            $asignacion = ['ok' => false, 'mesa_ids' => [], 'codigo' => AsignacionMesasService::SIN_CAPACIDAD];
            if ($solicitaAsignacion && $datos['comensales'] <= ReservacionConfig::MAX_COMENSALES_PUBLICO) {
                $asignacion = AsignacionMesasService::asignarAutomaticamente($id, false);
                if (!($asignacion['ok'] ?? false)) {
                    $warning = self::SIN_ASIGNACION;
                    if (!in_array($warning, $confirmaciones, true)) {
                        return self::rollback($db, self::respuestaAdvertencias([$warning], $revalidacion, $datos));
                    }
                }
            }
            $db->commit();
            $transaccion = false;
            return self::resultadoGuardado($id, $datos, $revalidacion, $asignacion, false, $preservar, false);
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionAdministrativaService::actualizar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO];
        } finally {
            foreach (array_reverse($locks) as $fecha) {
                FechaOperacionLock::liberar($db, $fecha);
            }
            if ($lockHorario) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function cancelar(int $id, ?int $usuarioId = null, string $motivo = ''): array
    {
        if ($id < 1) {
            return ['ok' => false, 'codigo' => ReservacionService::RESERVACION_NO_EXISTE];
        }
        $db = ActiveRecord::getDB();
        $lockHorario = false;
        $locks = [];
        $transaccion = false;
        try {
            $lockHorario = HorarioConfigLock::adquirir($db, 10);
            $previa = self::fila(
                "SELECT r.id, r.fecha,
                        (SELECT reemplazo.fecha
                         FROM reservaciones AS reemplazo
                         WHERE reemplazo.reemplaza_reservacion_id = r.id
                           AND reemplazo.estado = 'pendiente_verificacion'
                         ORDER BY reemplazo.id DESC LIMIT 1) AS fecha_reemplazo_pendiente
                 FROM reservaciones AS r
                 WHERE r.id = {$id}
                 LIMIT 1"
            );
            if (!$previa) {
                return ['ok' => false, 'codigo' => ReservacionService::RESERVACION_NO_EXISTE];
            }
            $fechas = array_values(array_unique(array_filter([
                trim((string)$previa['fecha']),
                trim((string)($previa['fecha_reemplazo_pendiente'] ?? '')),
            ], static fn(string $fecha): bool => $fecha !== '')));
            sort($fechas, SORT_STRING);
            if (!$lockHorario) {
                return ['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO];
            }
            foreach ($fechas as $fecha) {
                if (!FechaOperacionLock::adquirir($db, $fecha, 10)) {
                    return ['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO];
                }
                $locks[] = $fecha;
            }
            $db->begin_transaction();
            $transaccion = true;
            $fila = self::fila("SELECT * FROM reservaciones WHERE id = {$id} LIMIT 1 FOR UPDATE");
            if (!$fila) {
                return self::rollback($db, ReservacionService::RESERVACION_NO_EXISTE);
            }
            if ((string)$fila['estado'] === 'cancelada') {
                $db->commit();
                $transaccion = false;
                return ['ok' => true, 'codigo' => ReservacionService::CANCELADA, 'idempotente' => true];
            }
            if (!in_array((string)$fila['estado'], ['confirmada', 'pendiente_verificacion'], true)) {
                return self::rollback($db, ReservacionService::ESTADO_INVALIDO);
            }
            $ticket = $db->query(
                "SELECT t.id FROM tickets t
                 WHERE t.reservacion_id = {$id}
                   AND " . TicketMesa::condicionSqlAbierto('t') . "
                 LIMIT 1 FOR UPDATE"
            );
            if ($ticket === false) {
                throw new \RuntimeException($db->error);
            }
            $ticketAbierto = $ticket->num_rows > 0;
            $ticket->free();
            if ($ticketAbierto) {
                return self::rollback($db, ReservacionService::ESTADO_INVALIDO);
            }

            $pendiente = self::fila(
                "SELECT id FROM reservaciones
                 WHERE reemplaza_reservacion_id = {$id}
                   AND estado = 'pendiente_verificacion'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            if ($pendiente) {
                self::ejecutar("UPDATE reservaciones SET estado = 'expirada', hold_expires_at = NULL, estado_changed_at = NOW() WHERE id = " . (int)$pendiente['id'] . " AND estado = 'pendiente_verificacion'");
                VerificacionContacto::invalidarPorReservaciones([(int)$pendiente['id']]);
            }
            if ((string)$fila['estado'] === 'pendiente_verificacion') {
                VerificacionContacto::invalidarPorReservaciones([$id]);
            }
            self::ejecutar("UPDATE reservaciones SET estado = 'cancelada', hold_expires_at = NULL, estado_changed_at = NOW() WHERE id = {$id} LIMIT 1");
            $db->commit();
            $transaccion = false;
            return ['ok' => true, 'codigo' => ReservacionService::CANCELADA, 'idempotente' => false, 'motivo' => trim($motivo)];
        } catch (\Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            error_log('ReservacionAdministrativaService::cancelar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO];
        } finally {
            foreach (array_reverse($locks) as $fecha) {
                FechaOperacionLock::liberar($db, $fecha);
            }
            if ($lockHorario) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function validarEntrada(array $post, ?array $existente = null): array
    {
        $errors = [];
        $nombre = trim((string)($post['nombre'] ?? ($existente['nombre'] ?? '')));
        $tipo = trim((string)($post['contacto_tipo'] ?? ($existente['contacto_tipo'] ?? 'ninguno')));
        $contactoEntrada = trim((string)($post['contacto'] ?? ($existente['contacto'] ?? '')));
        $fecha = trim((string)($post['fecha'] ?? ($existente['fecha'] ?? '')));
        $hora = HorarioReservacionService::normalizarHoraSql((string)($post['hora'] ?? ($existente['hora'] ?? '')));
        $personas = filter_var($post['comensales'] ?? ($existente['comensales'] ?? null), FILTER_VALIDATE_INT);
        $nota = trim((string)($post['nota'] ?? ($existente['nota'] ?? '')));
        $comentario = trim((string)($post['comentario_admin'] ?? ($existente['comentario_admin'] ?? '')));

        if ($nombre === '' || self::longitud($nombre) > ReservacionConfig::NOMBRE_MAX_CARACTERES) {
            $errors['nombre'][] = 'NOMBRE_REQUERIDO';
        }
        if (!in_array($tipo, ['email', 'telefono', 'ninguno'], true)) {
            $errors['contacto_tipo'][] = 'CONTACTO_TIPO_INVALIDO';
        }
        if ($existente && in_array((string)$existente['contacto_tipo'], ['email', 'telefono'], true)
            && $tipo !== (string)$existente['contacto_tipo']) {
            return [
                'ok' => false,
                'codigo' => self::CONTACTO_TIPO_NO_EDITABLE,
                'field_codes' => ['contacto_tipo' => ['CONTACTO_TIPO_NO_EDITABLE_CAMPO']],
            ];
        }
        if ($tipo === 'ninguno' && $contactoEntrada !== '') {
            $errors['contacto'][] = 'CONTACTO_TIPO_INVALIDO';
        }
        $contacto = null;
        if ($tipo !== 'ninguno') {
            if ($contactoEntrada === '') {
                $errors['contacto'][] = 'CONTACTO_REQUERIDO';
            } else {
                try {
                    $contacto = ContactoService::normalizar($tipo, $contactoEntrada);
                } catch (InvalidArgumentException $e) {
                    $errors['contacto'][] = 'CONTACTO_INVALIDO';
                }
            }
        }
        if ($personas === false || $personas < 1 || $personas > ReservacionConfig::MAX_COMENSALES_ADMIN) {
            $errors['comensales'][] = 'COMENSALES_FUERA_DE_RANGO';
        }
        if (self::longitud($nota) > ReservacionConfig::NOTA_MAX_CARACTERES) {
            $errors['nota'][] = 'NOTA_DEMASIADO_LARGA';
        }
        if (self::longitud($comentario) > ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES) {
            $errors['comentario_admin'][] = 'COMENTARIO_DEMASIADO_LARGO';
        }
        if ($fecha === '' || !HorarioReservacionService::fechaValida($fecha)) {
            $errors['fecha'][] = 'FECHA_INVALIDA';
        }
        if ($hora === '') {
            $errors['hora'][] = 'HORA_NO_VALIDA';
        }
        if ($errors !== []) {
            return [
                'ok' => false,
                'codigo' => ReservacionService::DATOS_INVALIDOS,
                'field_codes' => $errors,
                'contexto' => ['max_comensales' => ReservacionConfig::MAX_COMENSALES_ADMIN],
            ];
        }

        return [
            'ok' => true,
            'datos' => [
                'nombre' => $nombre,
                'contacto_tipo' => $tipo,
                'contacto' => $contacto,
                'fecha' => $fecha,
                'hora' => $hora,
                'comensales' => (int)$personas,
                'nota' => $nota,
                'comentario_admin' => $comentario,
            ],
        ];
    }

    /** @return string[] */
    private static function warningCodes(array $datos, array $evaluacion, bool $solicitaAsignacion, bool $preservar): array
    {
        $warnings = [];
        if ($datos['contacto_tipo'] === 'ninguno' || empty($datos['contacto'])) {
            $warnings[] = self::SIN_CONTACTO;
        }
        if (!($evaluacion['capacidad_estimada_suficiente'] ?? false)) {
            $warnings[] = self::CAPACIDAD_INSUFICIENTE;
        }
        $asignada = $preservar || ($solicitaAsignacion && ($evaluacion['asignacion_automatica_posible'] ?? false));
        if (!$asignada) {
            $warnings[] = self::SIN_ASIGNACION;
        }

        return array_values(array_unique($warnings));
    }

    /** @return string[] */
    private static function confirmaciones(array $post): array
    {
        $raw = $post['confirmaciones'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[,|\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        return array_values(array_unique(array_filter(array_map('strval', $raw), static fn(string $code): bool => in_array($code, [
            self::SIN_CONTACTO,
            self::SIN_ASIGNACION,
            self::CAPACIDAD_INSUFICIENTE,
        ], true))));
    }

    /** @return array<string, mixed> */
    private static function respuestaAdvertencias(array $codes, array $evaluacion, array $datos): array
    {
        return [
            'ok' => false,
            'codigo' => $codes[0] ?? self::SIN_ASIGNACION,
            'warnings' => array_values($codes),
            'confirmaciones_requeridas' => array_values($codes),
            'requiere_confirmacion_sin_contacto' => in_array(self::SIN_CONTACTO, $codes, true),
            'requiere_confirmacion_capacidad' => in_array(self::CAPACIDAD_INSUFICIENTE, $codes, true),
            'requiere_asignacion_manual' => in_array(self::SIN_ASIGNACION, $codes, true),
            'capacidad_solicitada' => (int)$datos['comensales'],
            'capacidad_disponible' => (int)($evaluacion['capacidad_estimada'] ?? 0),
            'capacidad_total' => (int)($evaluacion['capacidad_total'] ?? 0),
            'capacidad_realmente_libre' => (int)($evaluacion['capacidad_realmente_libre'] ?? 0),
            'capacidad_proyectada' => (int)($evaluacion['capacidad_proyectada'] ?? 0),
            'depende_liberacion_proyectada' => (bool)($evaluacion['depende_liberacion_proyectada'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private static function resultadoGuardado(int $id, array $datos, array $evaluacion, array $asignacion, bool $idempotente, bool $preservar = false, bool $esCreacion = true): array
    {
        $mesaIds = array_values(array_map('intval', (array)($asignacion['mesa_ids'] ?? [])));
        if ($mesaIds === [] && $preservar) {
            $mesaIds = ReservacionMesa::obtenerIdsPorReservacion($id);
        }
        $sinMesas = $mesaIds === [];
        $warnings = [];
        if ($sinMesas) {
            $warnings[] = self::SIN_ASIGNACION;
        }
        if (!($evaluacion['capacidad_estimada_suficiente'] ?? false)) {
            $warnings[] = self::CAPACIDAD_INSUFICIENTE;
        }
        if ($datos['contacto_tipo'] === 'ninguno') {
            $warnings[] = self::SIN_CONTACTO;
        }

        return [
            'ok' => true,
            'codigo' => $esCreacion
                ? ($sinMesas ? ReservacionService::RESERVACION_CREADA_SIN_MESA : ReservacionService::RESERVACION_CREADA)
                : ($sinMesas ? ReservacionService::ACTUALIZADA_REQUIERE_ASIGNACION : ReservacionService::ACTUALIZADA),
            'id' => $id,
            'idempotente' => $idempotente,
            'asignacion' => $sinMesas ? 'PENDIENTE' : 'ASIGNADA',
            'mesa_ids' => $mesaIds,
            'requiere_asignacion_manual' => $sinMesas,
            'sin_contacto' => $datos['contacto_tipo'] === 'ninguno',
            'warnings' => array_values(array_unique($warnings)),
            'capacidad_solicitada' => (int)$datos['comensales'],
            'capacidad_disponible' => (int)($evaluacion['capacidad_estimada'] ?? 0),
            'capacidad_total' => (int)($evaluacion['capacidad_total'] ?? 0),
            'depende_liberacion_proyectada' => (bool)($evaluacion['depende_liberacion_proyectada'] ?? false),
            'mesas_proyectadas' => array_values(array_map('intval', (array)($evaluacion['ocupacion']['mesa_ids_proyectadas'] ?? []))),
        ];
    }

    private static function resultadoExistente(Reservacion $reservacion): array
    {
        $datos = [
            'comensales' => (int)$reservacion->comensales,
            'contacto_tipo' => (string)$reservacion->contacto_tipo,
        ];
        $mesaIds = ReservacionMesa::obtenerIdsPorReservacion((int)$reservacion->id);
        return [
            'ok' => true,
            'codigo' => $mesaIds === [] ? ReservacionService::RESERVACION_CREADA_SIN_MESA : ReservacionService::RESERVACION_CREADA,
            'id' => (int)$reservacion->id,
            'idempotente' => true,
            'asignacion' => $mesaIds === [] ? 'PENDIENTE' : 'ASIGNADA',
            'mesa_ids' => $mesaIds,
            'requiere_asignacion_manual' => $mesaIds === [],
            'sin_contacto' => $datos['contacto_tipo'] === 'ninguno',
            'capacidad_solicitada' => $datos['comensales'],
        ];
    }

    private static function actualizarFila(int $id, array $datos): void
    {
        $db = ActiveRecord::getDB();
        $contacto = $datos['contacto'] === null ? 'NULL' : "'" . ActiveRecord::escaparString((string)$datos['contacto']) . "'";
        $sets = [
            "nombre = '" . ActiveRecord::escaparString($datos['nombre']) . "'",
            "contacto_tipo = '" . ActiveRecord::escaparString($datos['contacto_tipo']) . "'",
            "contacto = {$contacto}",
            "fecha = '" . ActiveRecord::escaparString($datos['fecha']) . "'",
            "hora = '" . ActiveRecord::escaparString($datos['hora']) . "'",
            'comensales = ' . (int)$datos['comensales'],
            "nota = NULLIF('" . ActiveRecord::escaparString($datos['nota']) . "', '')",
            "comentario_admin = NULLIF('" . ActiveRecord::escaparString($datos['comentario_admin']) . "', '')",
        ];
        self::ejecutar("UPDATE reservaciones SET " . implode(', ', $sets) . " WHERE id = {$id} LIMIT 1");
    }

    private static function errorDatos(string $fieldCode, string $field): array
    {
        return [
            'ok' => false,
            'codigo' => ReservacionService::DATOS_INVALIDOS,
            'field_codes' => [$field => [$fieldCode]],
        ];
    }

    private static function errorHorario(array $evaluacion): array
    {
        return [
            'ok' => false,
            'codigo' => ReservacionService::HORARIO_INVALIDO,
            'field_codes' => ['hora' => ['HORARIO_NO_DISPONIBLE']],
            'codigo_horario' => $evaluacion['codigo'] ?? null,
        ];
    }

    private static function rollback(\mysqli $db, $resultado): array
    {
        $db->rollback();
        return is_array($resultado) ? $resultado : ['ok' => false, 'codigo' => $resultado];
    }

    private static function fila(string $query): ?array
    {
        $resultado = ActiveRecord::getDB()->query($query);
        if ($resultado === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $fila = $resultado->fetch_assoc() ?: null;
        $resultado->free();
        return $fila;
    }

    private static function ejecutar(string $query): void
    {
        if (ActiveRecord::getDB()->query($query) === false) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
    }

    private static function longitud(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

/** Alias local para mantener la fachada legible sin cambiar el modelo. */
final class MesaProxy
{
    public static function reservables(): array
    {
        return \Model\Mesa::reservables();
    }
}
