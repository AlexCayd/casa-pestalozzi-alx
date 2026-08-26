<?php

/**
 * Centraliza creacion, edicion y transiciones de reservaciones.
 * Los controladores solo traducen la peticion y el formato de respuesta.
 */

namespace Services;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\Mesa;
use Model\Reservacion;
use Model\ReservacionMesa;

class ReservacionService
{
    public const RESERVACION_CREADA = 'RESERVACION_CREADA';
    public const RESERVACION_CREADA_SIN_MESA = 'RESERVACION_CREADA_SIN_MESA';
    public const CREADA = self::RESERVACION_CREADA;
    public const CREADA_SIN_MESAS = self::RESERVACION_CREADA_SIN_MESA;
    public const ACTUALIZADA = 'ACTUALIZADA';
    public const ACTUALIZADA_REQUIERE_ASIGNACION = 'ACTUALIZADA_REQUIERE_ASIGNACION';
    public const COMENTARIO_ACTUALIZADO = 'COMENTARIO_ACTUALIZADO';
    public const CONFIRMADA = 'CONFIRMADA';
    public const COMPLETADA = 'COMPLETADA';
    public const CANCELADA = 'CANCELADA';
    public const NO_SHOW = 'NO_SHOW';
    public const RESERVACION_NO_EXISTE = 'RESERVACION_NO_ENCONTRADA';
    public const RESERVACION_PASADA = 'RESERVACION_PASADA';
    public const RESERVACION_HORARIO_PASADO = 'RESERVACION_HORARIO_PASADO';
    public const ESTADO_INVALIDO = 'ESTADO_INVALIDO';
    public const ESTADO_NO_EDITABLE = 'ESTADO_NO_EDITABLE';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const HORARIO_INVALIDO = HorarioReservacionService::HORARIO_INVALIDO;
    public const SIN_DISPONIBILIDAD = ReservacionPublicaService::SIN_DISPONIBILIDAD;
    public const CONFIRMAR_SIN_MESA = 'CONFIRMAR_SIN_MESA';
    public const REQUIERE_CONFIRMACION_SIN_CONTACTO = 'REQUIERE_CONFIRMACION_SIN_CONTACTO';
    public const REQUIERE_CONFIRMACION_CAPACIDAD = 'REQUIERE_CONFIRMACION_CAPACIDAD';
    public const COMENTARIO_NO_DISPONIBLE = 'COMENTARIO_NO_DISPONIBLE';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    public static function generarRequestToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Punto estable para scripts y futura automatización de vencimientos. */
    public static function expirarRetenciones(int $limite = 100, bool $simulacion = false): array
    {
        return ReservacionPublicaService::expirarRetenciones($limite, $simulacion);
    }

    public static function crearAdministrativa(array $post, ?int $usuarioId = null): array
    {
        return ReservacionAdministrativaService::crear($post, $usuarioId);
    }

    public static function obtenerHorariosDisponiblesParaFecha(string $fecha, bool $permitirHistorica = false): array
    {
        try {
            $calendario = HorarioReservacionService::resolverFecha(
                trim($fecha),
                null,
                $permitirHistorica
            );
            $codigo = (string)($calendario['codigo'] ?? HorarioReservacionService::ERROR_INTERNO);
            $esFechaInvalida = in_array($codigo, [
                HorarioReservacionService::FECHA_INVALIDA,
                HorarioReservacionService::FECHA_PASADA,
                'FECHA_FUERA_DE_HORIZONTE',
            ], true);

            return [
                'ok' => !$esFechaInvalida && $codigo !== HorarioReservacionService::ERROR_INTERNO,
                'codigo' => $codigo,
                'fecha' => (string)($calendario['fecha'] ?? trim($fecha)),
                'abierto' => (bool)($calendario['abierto'] ?? false),
                'reservable' => (bool)($calendario['reservable'] ?? false),
                'origen' => $calendario['origen'] ?? null,
                'tipo' => $calendario['tipo'] ?? null,
                'detalle_horario' => $calendario['detalle_horario'] ?? null,
                'hora_apertura' => $calendario['hora_apertura'] ?? null,
                'hora_cierre' => $calendario['hora_cierre'] ?? null,
                'horarios_candidatos' => $calendario['horarios_candidatos'] ?? [],
                'horarios' => $calendario['horarios'] ?? [],
                'horarios_reservables' => $calendario['horarios'] ?? [],
                'horarios_mapa' => HorarioReservacionService::horariosConfiguradosParaMapa(
                    trim($fecha),
                    null
                ),
                'motivo_no_disponible' => $calendario['motivo_no_disponible'] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log('ReservacionService::obtenerHorariosDisponiblesParaFecha - ' . $e->getMessage());

            return self::respuestaDisponibilidadError(
                $fecha,
                HorarioReservacionService::ERROR_INTERNO
            );
        }
    }

    // detalleHorarioPublico() vivía aquí, privado y sin un solo llamador: lo
    // que publica el detalle de horario es HorarioReservacionService::resolverFecha(),
    // que es por donde pasan todas las consultas de disponibilidad. Tener dos
    // constructores del mismo contrato era justamente lo que dejó a la landing
    // pintando la tarjeta de horario especial con los campos vacíos.

    public static function validarHorarioDisponible(string $fecha, string $hora): array
    {
        return HorarioReservacionService::validarHora($fecha, $hora);
    }

    /**
     * Revalida mesas actuales cuando cambian fecha, hora o comensales.
     * Si dejan de ser válidas, libera la asignación y solicita reasignación.
     */
    public static function actualizarDatos(
        int $reservacionId,
        array $datos,
        ?int $usuarioId = null
    ): array
    {
        $resultado = ReservacionAdministrativaService::actualizar($reservacionId, $datos, $usuarioId);
        if (($resultado['ok'] ?? false) === true) {
            HorarioOperacionImpactoService::reconciliarReservacion($reservacionId, $usuarioId);
        }
        return $resultado;

    }

    public static function actualizarComentario(int $reservacionId, string $comentario): array
    {
        if ($reservacionId < 1) {
            return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
        }

        $db = ActiveRecord::getDB();
        $comentario = trim($comentario);

        if (self::longitud($comentario) > ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES) {
            return [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'errors' => [
                    'comentario_admin' => ['El comentario interno es demasiado largo.'],
                ],
            ];
        }

        try {
            $db->begin_transaction();

            $reservacion = self::fila(
                "SELECT id, fecha, hora, estado
                 FROM reservaciones
                 WHERE id = {$reservacionId}
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$reservacion) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
            }

            $editable = self::validarEditableFila($reservacion);
            if (!$editable['ok']) {
                $db->rollback();
                return $editable;
            }

            self::ejecutar(
                "UPDATE reservaciones
                 SET comentario_admin = '" . ActiveRecord::escaparString($comentario) . "'
                 WHERE id = {$reservacionId}
                 LIMIT 1"
            );

            $db->commit();

            return ['ok' => true, 'codigo' => self::COMENTARIO_ACTUALIZADO];
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                error_log('ReservacionService rollback - ' . $rollbackError->getMessage());
            }

            error_log('ReservacionService::actualizarComentario - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    public static function confirmar(int $reservacionId, ?int $usuarioId = null): array
    {
        return self::cambiarEstado($reservacionId, 'confirmada', $usuarioId);
    }

    public static function completar(int $reservacionId, ?int $usuarioId = null): array
    {
        return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
    }

    public static function cancelar(int $reservacionId, ?int $usuarioId = null): array
    {
        return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
    }

    public static function marcarNoShow(int $reservacionId, ?int $usuarioId = null): array
    {
        return PuntoVentaReservacionService::noShow(
            $reservacionId,
            (int)($usuarioId ?? 0),
            false,
            false
        );
    }

    public static function ejecutarAccionOperativa(
        int $reservacionId,
        string $nuevoEstado,
        int $usuarioId,
        string $motivo = '',
        ?int $meseroId = null
    ): array {
        $resultado = match ($nuevoEstado) {
            'confirmada' => self::cambiarEstado($reservacionId, 'confirmada', $usuarioId),
            'en_curso' => PuntoVentaReservacionService::comenzar($reservacionId, $usuarioId, $meseroId),
            'no_show' => PuntoVentaReservacionService::noShow(
                $reservacionId,
                $usuarioId,
                false,
                false,
                $motivo
            ),
            'cancelada' => ReservacionAdministrativaService::cancelar(
                $reservacionId,
                $usuarioId,
                $motivo
            ),
            default => ['ok' => false, 'codigo' => self::ESTADO_INVALIDO],
        };
        if (($resultado['ok'] ?? false) === true && $nuevoEstado === 'no_show') {
            HorarioOperacionImpactoService::reconciliarReservacion($reservacionId, $usuarioId);
        }
        return $resultado;
    }

    public static function cambiarEstado(
        int $reservacionId,
        string $nuevoEstado,
        ?int $usuarioId = null
    ): array
    {
        if ($reservacionId < 1) {
            return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
        }

        if (!in_array($nuevoEstado, ReservacionConfig::estadosPermitidos(), true)) {
            return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
        }
        if (in_array($nuevoEstado, ['en_curso', 'completada', 'cancelada', 'no_show'], true)) {
            return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
        }

        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();

            $reservacion = self::fila(
                "SELECT *
                 FROM reservaciones
                 WHERE id = {$reservacionId}
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$reservacion) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
            }

            $estadoActual = (string)$reservacion['estado'];
            // La confirmacion de una retencion publica requiere su flujo OTP;
            // el backoffice solo puede crear en estado confirmado y no puede
            // saltarse esa verificacion con una transicion operativa.
            if ($nuevoEstado === 'confirmada' && $estadoActual === 'pendiente_verificacion') {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ESTADO_NO_EDITABLE];
            }
            $codigoNoEditable = self::codigoNoEditable($reservacion);
            if ($codigoNoEditable === self::RESERVACION_PASADA
                || $codigoNoEditable === self::RESERVACION_HORARIO_PASADO) {
                $db->rollback();
                return ['ok' => false, 'codigo' => $codigoNoEditable];
            }

            $transiciones = ReservacionConfig::TRANSICIONES[$estadoActual] ?? [];
            if (!in_array($nuevoEstado, $transiciones, true)) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }

            if ($nuevoEstado === 'confirmada') {
                if (!ReservacionMesa::tieneMesasAsignadas($reservacionId)) {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => self::CONFIRMAR_SIN_MESA];
                }
            }

            $estadoSql = ActiveRecord::escaparString($nuevoEstado);
            self::ejecutar(
                "UPDATE reservaciones
                 SET estado = '{$estadoSql}',
                     estado_changed_at = NOW()
                 WHERE id = {$reservacionId}
                 LIMIT 1"
            );
            $db->commit();

            return ['ok' => true, 'codigo' => self::codigoEstado($nuevoEstado)];
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                error_log('ReservacionService rollback - ' . $rollbackError->getMessage());
            }

            error_log('ReservacionService::cambiarEstado - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    public static function estadoActivo(string $estado): bool
    {
        return in_array($estado, ReservacionConfig::ESTADOS_EDITABLES, true);
    }

    public static function estadoLabels(): array
    {
        return ReservacionConfig::ESTADO_LABELS;
    }

    public static function estadosEditables(): array
    {
        return ReservacionConfig::ESTADOS_EDITABLES;
    }

    public static function estadosFinales(): array
    {
        return ReservacionConfig::ESTADOS_FINALES;
    }

    public static function estadosOcupanMesa(): array
    {
        return ReservacionConfig::ESTADOS_OCUPAN_MESA;
    }

    public static function transiciones(): array
    {
        return ReservacionConfig::TRANSICIONES;
    }

    public static function codigoNoEditable($reservacion): string
    {
        $estado = (string)self::valor($reservacion, 'estado', '');
        $fecha = (string)self::valor($reservacion, 'fecha', '');

        if ($estado === 'pendiente_verificacion') {
            return self::ESTADO_NO_EDITABLE;
        }

        $vigencia = ReservacionVigenciaService::clasificar($reservacion);
        if (!$vigencia['editable']) {
            if ($fecha !== '' && $fecha < ReservacionConfig::fechaActual()) {
                return self::RESERVACION_PASADA;
            }

            if ($vigencia['tolerancia_vencida']) {
                return self::RESERVACION_HORARIO_PASADO;
            }

            return self::ESTADO_NO_EDITABLE;
        }

        if ($fecha !== '' && $fecha < ReservacionConfig::fechaActual()) {
            return self::RESERVACION_PASADA;
        }

        return '';
    }

    /**
     * Expone el contrato derivado sin obligar a controladores o vistas a
     * reconstruir reglas temporales.
     *
     * @param array<string, mixed>|object $reservacion
     * @param array<string, mixed>|object|null $ticket
     * @return array<string, mixed>
     */
    public static function clasificarVigencia($reservacion, $ticket = null): array
    {
        return ReservacionVigenciaService::clasificar($reservacion, null, $ticket);
    }

    public static function puedeEditar($reservacion): bool
    {
        return (string)self::valor($reservacion, 'estado', '') === 'confirmada'
            && self::codigoNoEditable($reservacion) === '';
    }

    private static function crearReservacion(array $post, array $opciones): array
    {
        $requestToken = trim((string)($post['request_token'] ?? ''));
        if ($requestToken === '') {
            $requestToken = self::generarRequestToken();
        } elseif (preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $requestToken) !== 1) {
            return [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'field_codes' => ['request_token' => ['REQUEST_TOKEN_INVALIDO']],
            ];
        }

        $validacion = self::validarDatosReservacion($post, [
            'max_comensales' => (int)$opciones['max_comensales'],
            'permitir_comentario_admin' => (bool)$opciones['permitir_comentario_admin'],
            'validar_nota' => true,
            'contacto_requerido' => (bool)($opciones['contacto_requerido'] ?? true),
        ]);

        if (!$validacion['ok']) {
            return $validacion;
        }

        $datos = $validacion['datos'];
        $horario = $validacion['horario'];
        $sinContacto = $datos['contacto'] === null || $datos['contacto'] === '';
        $confirmoSinContacto = (string)($post['confirmar_sin_contacto'] ?? '') === '1';
        $permitirCapacidadInsuficiente =
            (string)($post['permitir_capacidad_insuficiente'] ?? '') === '1';

        if ($sinContacto && !$confirmoSinContacto) {
            return [
                'ok' => false,
                'codigo' => self::REQUIERE_CONFIRMACION_SIN_CONTACTO,
                'errors' => [],
                'requiere_confirmacion_sin_contacto' => true,
            ];
        }

        $db = ActiveRecord::getDB();
        $fechaLock = (string)$horario['fecha'];
        $horarioLock = false;
        $lockAdquirido = false;
        $transaccionIniciada = false;

        try {
            $horarioLock = HorarioConfigLock::adquirir($db);
            if (!$horarioLock) {
                throw new \RuntimeException('No fue posible bloquear la configuración de horarios.');
            }
            $lockAdquirido = FechaOperacionLock::adquirir($db, $fechaLock);
            if (!$lockAdquirido) {
                throw new \RuntimeException('No fue posible obtener el lock de la fecha.');
            }
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No fue posible iniciar la transaccion de creacion.');
            }
            $transaccionIniciada = true;

            $existente = Reservacion::buscarPorRequestToken($requestToken);
            if ($existente) {
                if (!$db->commit()) {
                    throw new \RuntimeException('No fue posible confirmar el reintento idempotente.');
                }
                $transaccionIniciada = false;

                return self::resultadoCreacionExistente($existente);
            }

            $reservacion = new Reservacion();
            $reservacion->nombre = $datos['nombre'];
            $reservacion->contacto_tipo = $datos['contacto_tipo'];
            $reservacion->contacto = $datos['contacto'];
            $reservacion->fecha = $horario['fecha'];
            $reservacion->hora = $horario['hora'];
            $reservacion->comensales = $datos['comensales'];
            $reservacion->nota = $datos['nota'];
            $reservacion->comentario_admin = $datos['comentario_admin'];
            $reservacion->origen = 'admin';
            $reservacion->request_token = $requestToken;
            $reservacion->estado = 'confirmada';

            $horarioFinal = self::validarHorarioDisponible($datos['fecha'], $datos['hora']);
            if (!$horarioFinal['ok']) {
                return self::respuestaHorarioInvalido($horarioFinal);
            }

            $reservacion->fecha = $horarioFinal['fecha'];
            $reservacion->hora = $horarioFinal['hora'];
            $capacidad = DisponibilidadReservacionService::resumenHorario(
                (string)$reservacion->fecha,
                (string)$reservacion->hora,
                (int)$reservacion->comensales,
                0,
                true,
                false
            );
            $capacidadDisponible = (int)($capacidad['capacidad_disponible'] ?? 0);
            $capacidadSuficiente = ($capacidad['ok'] ?? false)
                && $capacidadDisponible >= (int)$reservacion->comensales;

            if (!$capacidadSuficiente && !$permitirCapacidadInsuficiente) {
                return [
                    'ok' => false,
                    'codigo' => self::REQUIERE_CONFIRMACION_CAPACIDAD,
                    'errors' => [],
                    'contexto' => [
                        'capacidad_solicitada' => (int)$reservacion->comensales,
                        'capacidad_disponible' => $capacidadDisponible,
                    ],
                    'requiere_confirmacion_capacidad' => true,
                    'capacidad_solicitada' => (int)$reservacion->comensales,
                    'capacidad_disponible' => $capacidadDisponible,
                    'capacidad_total' => (int)($capacidad['capacidad_total'] ?? 0),
                ];
            }

            $asignarAutomaticamente = (bool)$opciones['asignar_automaticamente'];
            if (!$capacidadSuficiente) {
                $asignarAutomaticamente = false;
            }
            $guardado = $reservacion->crearAdministrativa();

            if (!$guardado || !$guardado['resultado']) {
                throw new \RuntimeException('No se pudo guardar la reservacion.');
            }

            $reservacionId = (int)$guardado['id'];

            if ((bool)$opciones['permitir_comentario_admin']) {
                self::guardarComentarioAdmin($reservacionId, $datos['comentario_admin']);
            }

            $asignacion = ['ok' => false, 'codigo' => AsignacionMesasService::SIN_CAPACIDAD, 'mesa_ids' => []];

            if ($asignarAutomaticamente) {
                $asignacion = AsignacionMesasService::asignarAutomaticamente(
                    $reservacionId,
                    false
                );
            }

            $codigoAsignacion = (string)($asignacion['codigo'] ?? AsignacionMesasService::ERROR_INTERNO);
            $sinMesaPermitido = !$asignarAutomaticamente
                || $permitirCapacidadInsuficiente;
            if (!($asignacion['ok'] ?? false) && !$sinMesaPermitido) {
                throw new \RuntimeException('La asignacion inicial fallo con codigo ' . $codigoAsignacion . '.');
            }

            if (!$db->commit()) {
                throw new \RuntimeException('No fue posible confirmar la creacion.');
            }
            $transaccionIniciada = false;

            return array_merge(
                self::resultadoCreacion($reservacionId, $asignacion, false),
                [
                    'sin_contacto' => $sinContacto,
                    'capacidad_solicitada' => (int)$reservacion->comensales,
                    'capacidad_disponible' => $capacidadDisponible,
                    'capacidad_total' => (int)($capacidad['capacidad_total'] ?? 0),
                    'requiere_asignacion_manual' => !($asignacion['ok'] ?? false),
                ]
            );
        } catch (\mysqli_sql_exception $e) {
            if ($transaccionIniciada) {
                $db->rollback();
                $transaccionIniciada = false;
            }

            if ((int)$e->getCode() === 1062) {
                try {
                    $existente = Reservacion::buscarPorRequestToken($requestToken);
                    if ($existente) {
                        return self::resultadoCreacionExistente($existente);
                    }
                } catch (\Throwable $consultaError) {
                    error_log('ReservacionService::crearReservacion idempotencia - ' . $consultaError->getMessage());
                }
            }

            error_log('ReservacionService::crearReservacion - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO, 'errors' => []];
        } catch (\Throwable $e) {
            if ($transaccionIniciada) {
                $db->rollback();
                $transaccionIniciada = false;
            }
            error_log('ReservacionService::crearReservacion - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO, 'errors' => []];
        } finally {
            if ($transaccionIniciada) {
                $db->rollback();
            }
            if ($lockAdquirido) {
                FechaOperacionLock::liberar($db, $fechaLock);
            }
            if ($horarioLock) {
                HorarioConfigLock::liberar($db);
            }
        }
    }

    private static function resultadoCreacionExistente(Reservacion $reservacion): array
    {
        $asignadas = ReservacionMesa::obtenerPorReservacion((int)$reservacion->id);
        $mesaIds = array_map(static fn($mesa): int => (int)$mesa->id, $asignadas);

        return array_merge(self::resultadoCreacion((int)$reservacion->id, [
            'ok' => $mesaIds !== [],
            'codigo' => $mesaIds !== []
                ? AsignacionMesasService::ASIGNACION_GUARDADA
                : AsignacionMesasService::SIN_CAPACIDAD,
            'mesa_ids' => $mesaIds,
        ], true), [
            'sin_contacto' => trim((string)($reservacion->contacto ?? '')) === '',
            'requiere_asignacion_manual' => $mesaIds === [],
        ]);
    }

    private static function resultadoCreacion(int $reservacionId, array $asignacion, bool $idempotente): array
    {
        $mesas = ReservacionMesa::obtenerPorReservacion($reservacionId);
        $mesasNombres = array_map(static fn($mesa): string => (string)$mesa->nombre, $mesas);
        $mesaIds = array_map(static fn($mesa): int => (int)$mesa->id, $mesas);
        $sinMesas = $mesaIds === [];

        return [
            'ok' => true,
            'codigo' => $sinMesas ? self::RESERVACION_CREADA_SIN_MESA : self::RESERVACION_CREADA,
            'asignacion' => $sinMesas ? 'PENDIENTE' : 'ASIGNADA',
            'id' => $reservacionId,
            'idempotente' => $idempotente,
            'mesa' => $mesasNombres[0] ?? '',
            'mesa2' => $mesasNombres[1] ?? '',
            'mesas' => $mesasNombres,
            'mesa_ids' => $mesaIds,
            'requiere_confirmacion' => $sinMesas,
            'warning_code' => $sinMesas
                ? 'SIN_ASIGNACION'
                : ($asignacion['advertencia_codigo'] ?? null),
            'depende_liberacion_proyectada' => (bool)($asignacion['depende_liberacion_proyectada'] ?? false),
            'mesas_proyectadas' => array_values(array_map(
                'intval',
                (array)($asignacion['mesas_proyectadas'] ?? [])
            )),
        ];
    }

    private static function normalizarDatosAdmin(array $datos): array
    {
        return self::validarDatosReservacion($datos, [
            'max_comensales' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'permitir_comentario_admin' => true,
            'validar_nota' => false,
            'validar_horario' => false,
            'contacto_requerido' => false,
            'preservar_tipo_sin_contacto' => true,
        ]);
    }

    private static function validarDatosReservacion(array $datos, array $opciones): array
    {
        $errors = [];
        $fieldCodes = [];
        $maxComensales = (int)($opciones['max_comensales'] ?? ReservacionConfig::MAX_COMENSALES_PUBLICO);
        $permitirComentarioAdmin = (bool)($opciones['permitir_comentario_admin'] ?? false);
        $validarNota = (bool)($opciones['validar_nota'] ?? true);
        $validarHorario = (bool)($opciones['validar_horario'] ?? true);
        $contactoRequerido = (bool)($opciones['contacto_requerido'] ?? true);
        $preservarTipoSinContacto = (bool)($opciones['preservar_tipo_sin_contacto'] ?? false);

        $nombre = trim((string)($datos['nombre'] ?? ''));
        $contactoTipo = trim((string)($datos['contacto_tipo'] ?? ''));
        $contactoValor = trim((string)($datos['contacto'] ?? ''));
        $contacto = null;
        $fecha = trim((string)($datos['fecha'] ?? ''));
        $horaOriginal = trim((string)($datos['hora'] ?? ''));
        $hora = HorarioReservacionService::normalizarHoraSql($horaOriginal);
        $comensalesValor = $datos['comensales'] ?? null;
        $comensales = filter_var($comensalesValor, FILTER_VALIDATE_INT);
        $nota = trim((string)($datos['nota'] ?? ''));
        $comentario = trim((string)($datos['comentario_admin'] ?? ''));
        $horario = null;
        $codigoHorario = '';
        if ($contactoValor === '' && !$contactoRequerido) {
            $contactoTipo = 'ninguno';
        }

        if ($nombre === '') {
            self::agregarError($errors, $fieldCodes, 'nombre', 'NOMBRE_REQUERIDO');
        } elseif (preg_match('/[\p{L}\p{N}]/u', $nombre) !== 1) {
            self::agregarError($errors, $fieldCodes, 'nombre', 'NOMBRE_INVALIDO');
        } elseif (self::longitud($nombre) > ReservacionConfig::NOMBRE_MAX_CARACTERES) {
            self::agregarError($errors, $fieldCodes, 'nombre', 'NOMBRE_DEMASIADO_LARGO');
        }

        if ($contactoTipo === 'ninguno') {
            if ($contactoRequerido) {
                self::agregarError(
                    $errors,
                    $fieldCodes,
                    'contacto_tipo',
                    'CONTACTO_TIPO_INVALIDO'
                );
            }
            $contacto = null;
        } elseif (!in_array($contactoTipo, ContactoService::TIPOS, true)) {
            self::agregarError(
                $errors,
                $fieldCodes,
                'contacto_tipo',
                'CONTACTO_TIPO_INVALIDO'
            );
        } elseif ($contactoValor !== '' || $contactoRequerido) {
            try {
                $contacto = ContactoService::normalizar($contactoTipo, $contactoValor);
            } catch (\InvalidArgumentException $e) {
                self::agregarError(
                    $errors,
                    $fieldCodes,
                    'contacto',
                    'CONTACTO_INVALIDO'
                );
            }
        }
        if ($contactoValor === '' && !$contactoRequerido) {
            $contactoTipo = 'ninguno';
            $contacto = null;
        }

        if ($fecha === '') {
            self::agregarError($errors, $fieldCodes, 'fecha', 'FECHA_REQUERIDA');
        } elseif (!HorarioReservacionService::fechaValida($fecha)) {
            self::agregarError($errors, $fieldCodes, 'fecha', 'FECHA_INVALIDA');
        }

        if ($horaOriginal === '') {
            self::agregarError($errors, $fieldCodes, 'hora', 'HORA_REQUERIDA');
        } elseif ($hora === '') {
            self::agregarError($errors, $fieldCodes, 'hora', 'HORA_INVALIDA');
        }

        if ($comensalesValor === null || $comensalesValor === '') {
            self::agregarError($errors, $fieldCodes, 'comensales', 'COMENSALES_REQUERIDOS');
        } elseif ($comensales === false) {
            self::agregarError($errors, $fieldCodes, 'comensales', 'COMENSALES_INVALIDOS');
        } elseif ($comensales < 1 || $comensales > $maxComensales) {
            self::agregarError(
                $errors,
                $fieldCodes,
                'comensales',
                'COMENSALES_FUERA_DE_RANGO'
            );
        }

        if ($validarNota && self::longitud($nota) > ReservacionConfig::NOTA_MAX_CARACTERES) {
            self::agregarError($errors, $fieldCodes, 'nota', 'NOTA_DEMASIADO_LARGA');
        }

        if ($permitirComentarioAdmin && self::longitud($comentario) > ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES) {
            self::agregarError($errors, $fieldCodes, 'comentario_admin', 'COMENTARIO_DEMASIADO_LARGO');
        }

        if ($validarHorario && empty($errors['fecha']) && empty($errors['hora'])) {
            $horario = self::validarHorarioDisponible($fecha, $hora);

            if (!$horario['ok']) {
                $codigoHorario = (string)($horario['codigo'] ?? HorarioReservacionService::HORARIO_INVALIDO);
                $field = in_array($codigoHorario, [
                    HorarioReservacionService::HORARIO_INVALIDO,
                    HorarioReservacionService::HORARIO_PASADO,
                ], true) ? 'hora' : 'fecha';

                self::agregarError(
                    $errors,
                    $fieldCodes,
                    $field,
                    self::codigoCampoHorario($codigoHorario)
                );
            }
        }

        if (!empty($errors)) {
            return [
                'ok' => false,
                'codigo' => $codigoHorario === HorarioReservacionService::ERROR_INTERNO
                    ? self::ERROR_INTERNO
                    : self::DATOS_INVALIDOS,
                'codigo_horario' => $codigoHorario,
                'field_codes' => $fieldCodes,
                'contexto' => ['max_comensales' => $maxComensales],
                'siguiente_horario_valido' => is_array($horario)
                    ? ($horario['siguiente_horario_valido'] ?? null)
                    : null,
            ];
        }

        return [
            'ok' => true,
            'datos' => [
                'nombre' => $nombre,
                'contacto_tipo' => $contactoTipo,
                'contacto' => $contacto,
                'fecha' => $horario['fecha'] ?? $fecha,
                'hora' => $horario['hora'] ?? $hora,
                'comensales' => (int)$comensales,
                'nota' => $nota,
                'comentario_admin' => $permitirComentarioAdmin ? $comentario : '',
            ],
            'horario' => $horario ?: [
                'ok' => true,
                'fecha' => $fecha,
                'hora' => $hora,
                'hora_corta' => substr($hora, 0, 5),
            ],
        ];
    }

    private static function respuestaDisponibilidadError(string $fecha, string $codigo): array
    {
        return [
            'ok' => false,
            'codigo' => $codigo,
            'fecha' => $fecha,
            'abierto' => false,
            'origen' => $codigo === HorarioReservacionService::FECHA_INVALIDA ? 'invalido' : null,
            'tipo' => null,
            'horarios' => [],
        ];
    }

    private static function actualizarFila(
        int $reservacionId,
        array $datos,
        string $estado,
        ?int $usuarioId
    ): void
    {
        $contactoSql = $datos['contacto'] === null || $datos['contacto'] === ''
            ? 'NULL'
            : "'" . ActiveRecord::escaparString($datos['contacto']) . "'";
        $estadoSql = ActiveRecord::escaparString($estado);
        $sets = [
            "nombre = '" . ActiveRecord::escaparString($datos['nombre']) . "'",
            "contacto_tipo = '" . ActiveRecord::escaparString($datos['contacto_tipo']) . "'",
            "contacto = {$contactoSql}",
            "fecha = '" . ActiveRecord::escaparString($datos['fecha']) . "'",
            "hora = '" . ActiveRecord::escaparString($datos['hora']) . "'",
            "comensales = " . (int)$datos['comensales'],
            "estado_changed_at = CASE WHEN estado <> '{$estadoSql}' THEN NOW() ELSE estado_changed_at END",
            "estado = '{$estadoSql}'",
            "comentario_admin = '" . ActiveRecord::escaparString($datos['comentario_admin']) . "'",
        ];

        self::ejecutar(
            "UPDATE reservaciones
             SET " . implode(', ', $sets) . "
             WHERE id = {$reservacionId}
             LIMIT 1"
        );
    }

    private static function guardarComentarioAdmin(int $reservacionId, string $comentario): void
    {
        if ($reservacionId < 1) {
            return;
        }

        self::ejecutar(
            "UPDATE reservaciones
             SET comentario_admin = '" . ActiveRecord::escaparString($comentario) . "'
             WHERE id = {$reservacionId}
             LIMIT 1"
        );
    }

    private static function validarEditableFila(array $reservacion): array
    {
        $codigo = self::codigoNoEditable($reservacion);

        if ($codigo === '') {
            return ['ok' => true];
        }

        return ['ok' => false, 'codigo' => $codigo];
    }

    private static function respuestaHorarioInvalido(array $horario): array
    {
        $codigoHorario = (string)($horario['codigo'] ?? HorarioReservacionService::HORARIO_INVALIDO);
        $field = in_array($codigoHorario, [
            HorarioReservacionService::HORARIO_INVALIDO,
            HorarioReservacionService::HORARIO_PASADO,
        ], true) ? 'hora' : 'fecha';

        return [
            'ok' => false,
            'codigo' => $codigoHorario === HorarioReservacionService::ERROR_INTERNO
                ? self::ERROR_INTERNO
                : self::HORARIO_INVALIDO,
            'codigo_horario' => $codigoHorario,
            'field_codes' => [
                $field => [self::codigoCampoHorario($codigoHorario)],
            ],
            'siguiente_horario_valido' => $horario['siguiente_horario_valido'] ?? null,
        ];
    }

    private static function codigoCampoHorario(string $codigo): string
    {
        return match ($codigo) {
            HorarioReservacionService::FECHA_INVALIDA => 'FECHA_INVALIDA',
            HorarioReservacionService::FECHA_PASADA => 'FECHA_PASADA',
            HorarioReservacionService::HORARIO_PASADO => 'HORARIO_PASADO',
            HorarioReservacionService::DIA_INACTIVO => 'DIA_NO_DISPONIBLE',
            HorarioReservacionService::HORARIO_INVALIDO => 'HORARIO_NO_DISPONIBLE',
            default => 'HORARIO_INVALIDO',
        };
    }

    private static function agregarError(array &$errors, array &$fieldCodes, string $field, string $code): void
    {
        $errors[$field][] = $code;
        $fieldCodes[$field][] = $code;
    }

    private static function codigoEstado(string $estado): string
    {
        return match ($estado) {
            'confirmada' => self::CONFIRMADA,
            'completada' => self::COMPLETADA,
            'cancelada' => self::CANCELADA,
            'no_show' => self::NO_SHOW,
            default => self::ERROR_INTERNO,
        };
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

    private static function longitud(string $valor): int
    {
        return function_exists('mb_strlen') ? mb_strlen($valor, 'UTF-8') : strlen($valor);
    }

    private static function valor($item, string $campo, $default = '')
    {
        if (is_array($item)) {
            return $item[$campo] ?? $default;
        }

        if (is_object($item)) {
            return $item->$campo ?? $default;
        }

        return $default;
    }
}
