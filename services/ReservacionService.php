<?php

/**
 * Centraliza creacion, edicion y transiciones de reservaciones.
 * Los controladores solo traducen la peticion y el formato de respuesta.
 */

namespace Services;

use Model\ActiveRecord;
use Model\Mesa;
use Model\Reservacion;
use Model\ReservacionMesa;

class ReservacionService
{
    public const CREADA = 'CREADA';
    public const CREADA_SIN_MESAS = 'CREADA_SIN_MESAS';
    public const ACTUALIZADA = 'ACTUALIZADA';
    public const ACTUALIZADA_REQUIERE_ASIGNACION = 'ACTUALIZADA_REQUIERE_ASIGNACION';
    public const COMENTARIO_ACTUALIZADO = 'COMENTARIO_ACTUALIZADO';
    public const CONFIRMADA = 'CONFIRMADA';
    public const COMPLETADA = 'COMPLETADA';
    public const CANCELADA = 'CANCELADA';
    public const NO_SHOW = 'NO_SHOW';
    public const RESERVACION_NO_EXISTE = 'RESERVACION_NO_EXISTE';
    public const RESERVACION_PASADA = 'RESERVACION_PASADA';
    public const RESERVACION_HORARIO_PASADO = 'RESERVACION_HORARIO_PASADO';
    public const ESTADO_INVALIDO = 'ESTADO_INVALIDO';
    public const ESTADO_NO_EDITABLE = 'ESTADO_NO_EDITABLE';
    public const DATOS_INVALIDOS = 'DATOS_INVALIDOS';
    public const HORARIO_INVALIDO = 'HORARIO_INVALIDO';
    public const CONFIRMAR_SIN_MESA = 'CONFIRMAR_SIN_MESA';
    public const COMENTARIO_NO_DISPONIBLE = 'COMENTARIO_NO_DISPONIBLE';
    public const ERROR_INTERNO = 'ERROR_INTERNO';

    private const ESTADOS_ACTIVOS = ['pendiente', 'confirmada'];
    private const ESTADOS_CAMBIO_CANONICO = ['confirmada', 'completada', 'cancelada', 'no_show'];

    public static function crearPublica(array $post): array
    {
        return self::crearReservacion($post, [
            'origen' => 'publica',
            'max_comensales' => ReservacionConfig::MAX_COMENSALES_PUBLICO,
            'tipo_asignacion' => 'publica',
            'permitir_comentario_admin' => false,
            'asignar_automaticamente' => true,
        ]);
    }

    public static function crearAdministrativa(array $post): array
    {
        $asignarAutomaticamente = !array_key_exists('asignar_automaticamente', $post)
            || (string)$post['asignar_automaticamente'] === '1';

        return self::crearReservacion($post, [
            'origen' => 'administrativa',
            'max_comensales' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'tipo_asignacion' => 'general',
            'permitir_comentario_admin' => true,
            'asignar_automaticamente' => $asignarAutomaticamente,
        ]);
    }

    /**
     * Revalida mesas actuales cuando cambian fecha, hora o comensales.
     * Si dejan de ser validas, libera la asignacion y revierte confirmada a pendiente.
     */
    public static function actualizarDatos(int $reservacionId, array $datos): array
    {
        if ($reservacionId < 1) {
            return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
        }

        $normalizados = self::normalizarDatosAdmin($datos);

        if (!$normalizados['ok']) {
            return $normalizados;
        }

        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();

            $reservacion = self::fila(
                "SELECT id, nombre, email, fecha, hora, comensales, estado
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

            $datosLimpios = $normalizados['datos'];
            $fechaActual = (string)$reservacion['fecha'];
            $horaActual = HorarioReservacionService::normalizarHoraSql((string)$reservacion['hora']);
            $comensalesActuales = (int)$reservacion['comensales'];
            $estadoActual = (string)$reservacion['estado'];
            $estadoNuevo = $estadoActual;
            $requiereAsignacion = false;

            $cambioOperativo = $datosLimpios['fecha'] !== $fechaActual
                || $datosLimpios['hora'] !== $horaActual
                || $datosLimpios['comensales'] !== $comensalesActuales;

            $horario = HorarioReservacionService::validarHorarioReservacion($datosLimpios['fecha'], $datosLimpios['hora']);

            if (!$horario['ok']) {
                $db->rollback();
                return self::respuestaHorarioInvalido($horario);
            }

            $datosLimpios['fecha'] = $horario['fecha'];
            $datosLimpios['hora'] = $horario['hora'];

            if ($cambioOperativo) {
                $mesaIdsActuales = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);

                if (empty($mesaIdsActuales)) {
                    if ($estadoActual === 'confirmada') {
                        $estadoNuevo = 'pendiente';
                        $requiereAsignacion = true;
                    }
                } else {
                    $mesasActuales = Mesa::reservablesParaActualizar($mesaIdsActuales);
                    $ocupacion = AsignacionMesasService::obtenerOcupacionParaHorario(
                        $datosLimpios['fecha'],
                        $datosLimpios['hora'],
                        $reservacionId,
                        true
                    );

                    $asignacionValida = count($mesasActuales) === count($mesaIdsActuales)
                        && !AsignacionMesasService::hayConflictoHorario($ocupacion, $mesaIdsActuales)
                        && AsignacionMesasService::validarCapacidad($mesasActuales, $mesaIdsActuales, $datosLimpios['comensales']);

                    if (!$asignacionValida) {
                        ReservacionMesa::eliminarAsignacion($reservacionId);
                        $requiereAsignacion = true;

                        if ($estadoActual === 'confirmada') {
                            $estadoNuevo = 'pendiente';
                        }
                    }
                }
            }

            self::actualizarFila($reservacionId, $datosLimpios, $estadoNuevo);
            $db->commit();

            return [
                'ok' => true,
                'codigo' => $requiereAsignacion ? self::ACTUALIZADA_REQUIERE_ASIGNACION : self::ACTUALIZADA,
                'requiere_asignacion' => $requiereAsignacion,
            ];
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                error_log('ReservacionService rollback - ' . $rollbackError->getMessage());
            }

            error_log('ReservacionService::actualizarDatos - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO];
        }
    }

    public static function actualizarComentario(int $reservacionId, string $comentario): array
    {
        if ($reservacionId < 1) {
            return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
        }

        if (!Reservacion::tieneComentarioAdmin()) {
            return ['ok' => false, 'codigo' => self::COMENTARIO_NO_DISPONIBLE];
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

    public static function confirmar(int $reservacionId): array
    {
        return self::cambiarEstado($reservacionId, 'confirmada');
    }

    public static function completar(int $reservacionId): array
    {
        return self::cambiarEstado($reservacionId, 'completada');
    }

    public static function cancelar(int $reservacionId): array
    {
        return self::cambiarEstado($reservacionId, 'cancelada');
    }

    public static function marcarNoShow(int $reservacionId): array
    {
        return self::cambiarEstado($reservacionId, 'no_show');
    }

    public static function cambiarEstado(int $reservacionId, string $nuevoEstado): array
    {
        if ($reservacionId < 1) {
            return ['ok' => false, 'codigo' => self::RESERVACION_NO_EXISTE];
        }

        if (!in_array($nuevoEstado, self::ESTADOS_CAMBIO_CANONICO, true)) {
            return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
        }

        $db = ActiveRecord::getDB();

        try {
            $db->begin_transaction();

            $reservacion = self::fila(
                "SELECT id, estado
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

            if ($nuevoEstado === 'confirmada') {
                if ($estadoActual !== 'pendiente') {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
                }

                if (!ReservacionMesa::tieneMesasAsignadas($reservacionId)) {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => self::CONFIRMAR_SIN_MESA];
                }
            } elseif (!self::estadoActivo($estadoActual)) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::ESTADO_INVALIDO];
            }

            $estadoSql = ActiveRecord::escaparString($nuevoEstado);
            self::ejecutar("UPDATE reservaciones SET estado = '{$estadoSql}' WHERE id = {$reservacionId} LIMIT 1");
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
        return in_array($estado, self::ESTADOS_ACTIVOS, true);
    }

    public static function codigoNoEditable($reservacion): string
    {
        $estado = (string)self::valor($reservacion, 'estado', '');
        $fecha = (string)self::valor($reservacion, 'fecha', '');
        $hora = HorarioReservacionService::normalizarHoraSql((string)self::valor($reservacion, 'hora', ''));

        if (!self::estadoActivo($estado)) {
            return self::ESTADO_NO_EDITABLE;
        }

        if ($fecha !== '' && $fecha < ReservacionConfig::fechaActual()) {
            return self::RESERVACION_PASADA;
        }

        if (HorarioReservacionService::horarioPasadoHoy($fecha, $hora)) {
            return self::RESERVACION_HORARIO_PASADO;
        }

        return '';
    }

    public static function puedeEditar($reservacion): bool
    {
        return self::codigoNoEditable($reservacion) === '';
    }

    private static function crearReservacion(array $post, array $opciones): array
    {
        $validacion = self::validarDatosReservacion($post, [
            'max_comensales' => (int)$opciones['max_comensales'],
            'permitir_comentario_admin' => (bool)$opciones['permitir_comentario_admin'],
            'validar_nota' => true,
        ]);

        if (!$validacion['ok']) {
            return $validacion;
        }

        $datos = $validacion['datos'];
        $horario = $validacion['horario'];

        try {
            $reservacion = new Reservacion();
            $reservacion->nombre = $datos['nombre'];
            $reservacion->email = $datos['email'];
            $reservacion->fecha = $horario['fecha'];
            $reservacion->hora = $horario['hora'];
            $reservacion->comensales = $datos['comensales'];
            $reservacion->nota = $datos['nota'];
            $reservacion->estado = 'pendiente';

            $guardado = $reservacion->guardar();

            if (!$guardado || !$guardado['resultado']) {
                return ['ok' => false, 'codigo' => self::ERROR_INTERNO, 'msg' => 'No se pudo guardar la reservacion.', 'errors' => []];
            }

            $reservacionId = (int)$guardado['id'];

            if ((bool)$opciones['permitir_comentario_admin']) {
                self::guardarComentarioAdmin($reservacionId, $datos['comentario_admin']);
            }

            $asignarAutomaticamente = (bool)$opciones['asignar_automaticamente'];
            $asignacion = ['ok' => false, 'codigo' => AsignacionMesasService::SIN_CAPACIDAD, 'mesa_ids' => []];

            if ($asignarAutomaticamente) {
                $asignacion = $opciones['tipo_asignacion'] === 'publica'
                    ? AsignacionMesasService::asignarAutomaticamentePublica($reservacionId)
                    : AsignacionMesasService::asignarAutomaticamente($reservacionId);
            }

            $mesasNombres = [];

            if ($asignacion['ok']) {
                $mesasNombres = array_map(static function ($mesa): string {
                    return (string)$mesa->nombre;
                }, ReservacionMesa::obtenerPorReservacion($reservacionId));
            } elseif (($asignacion['codigo'] ?? '') === AsignacionMesasService::ERROR_INTERNO) {
                error_log('ReservacionService::crearReservacion - no se pudo asignar mesa a reservacion ' . $reservacionId);
            }

            $esAdmin = $opciones['origen'] === 'administrativa';
            $sinMesas = !$asignarAutomaticamente || !$asignacion['ok'];

            return [
                'ok' => true,
                'codigo' => $esAdmin && $sinMesas ? self::CREADA_SIN_MESAS : self::CREADA,
                'id' => $reservacionId,
                'mesa' => $mesasNombres[0] ?? '',
                'mesa2' => $mesasNombres[1] ?? '',
                'mesas' => $mesasNombres,
                'mesa_ids' => $asignacion['mesa_ids'] ?? [],
                'requiere_confirmacion' => $sinMesas,
                'warning' => $sinMesas ? 'Solicitud recibida. Confirmaremos la disponibilidad de mesa para este horario.' : null,
            ];
        } catch (\Throwable $e) {
            error_log('ReservacionService::crearReservacion - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR_INTERNO, 'msg' => 'No se pudo guardar la reservacion.', 'errors' => []];
        }
    }

    private static function normalizarDatosAdmin(array $datos): array
    {
        return self::validarDatosReservacion($datos, [
            'max_comensales' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'permitir_comentario_admin' => true,
            'validar_nota' => false,
        ]);
    }

    private static function validarDatosReservacion(array $datos, array $opciones): array
    {
        $errors = [];
        $fieldCodes = [];
        $maxComensales = (int)($opciones['max_comensales'] ?? ReservacionConfig::MAX_COMENSALES_PUBLICO);
        $permitirComentarioAdmin = (bool)($opciones['permitir_comentario_admin'] ?? false);
        $validarNota = (bool)($opciones['validar_nota'] ?? true);

        $nombre = trim((string)($datos['nombre'] ?? ''));
        $email = strtolower(trim((string)($datos['email'] ?? '')));
        $fecha = trim((string)($datos['fecha'] ?? ''));
        $horaOriginal = trim((string)($datos['hora'] ?? ''));
        $hora = HorarioReservacionService::normalizarHoraSql($horaOriginal);
        $comensalesValor = $datos['comensales'] ?? null;
        $comensales = filter_var($comensalesValor, FILTER_VALIDATE_INT);
        $nota = trim((string)($datos['nota'] ?? ''));
        $comentario = trim((string)($datos['comentario_admin'] ?? ''));
        $horario = null;
        $codigoHorario = '';

        if ($nombre === '') {
            self::agregarError($errors, $fieldCodes, 'nombre', 'Escribe un nombre para la reservacion.', 'NOMBRE_REQUERIDO');
        } elseif (preg_match('/[\p{L}\p{N}]/u', $nombre) !== 1) {
            self::agregarError($errors, $fieldCodes, 'nombre', 'El nombre debe incluir letras o numeros.', 'NOMBRE_INVALIDO');
        } elseif (self::longitud($nombre) > ReservacionConfig::NOMBRE_MAX_CARACTERES) {
            self::agregarError($errors, $fieldCodes, 'nombre', 'El nombre es demasiado largo.', 'NOMBRE_DEMASIADO_LARGO');
        }

        if ($email === '') {
            self::agregarError($errors, $fieldCodes, 'email', 'Escribe un correo electronico.', 'EMAIL_REQUERIDO');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || self::longitud($email) > ReservacionConfig::EMAIL_MAX_CARACTERES) {
            self::agregarError($errors, $fieldCodes, 'email', 'Escribe un correo electronico valido.', 'EMAIL_INVALIDO');
        }

        if ($fecha === '') {
            self::agregarError($errors, $fieldCodes, 'fecha', 'Elige una fecha.', 'FECHA_REQUERIDA');
        } elseif (!HorarioReservacionService::fechaValida($fecha)) {
            self::agregarError($errors, $fieldCodes, 'fecha', 'La fecha seleccionada no es valida.', 'FECHA_INVALIDA');
        }

        if ($horaOriginal === '') {
            self::agregarError($errors, $fieldCodes, 'hora', 'Elige una hora.', 'HORA_REQUERIDA');
        } elseif ($hora === '') {
            self::agregarError($errors, $fieldCodes, 'hora', 'La hora seleccionada no es valida.', 'HORA_INVALIDA');
        }

        if ($comensalesValor === null || $comensalesValor === '') {
            self::agregarError($errors, $fieldCodes, 'comensales', 'Indica el numero de comensales.', 'COMENSALES_REQUERIDOS');
        } elseif ($comensales === false) {
            self::agregarError($errors, $fieldCodes, 'comensales', 'El numero de comensales debe ser entero.', 'COMENSALES_INVALIDOS');
        } elseif ($comensales < 1 || $comensales > $maxComensales) {
            self::agregarError(
                $errors,
                $fieldCodes,
                'comensales',
                'El numero de comensales debe estar entre 1 y ' . $maxComensales . '.',
                'COMENSALES_FUERA_DE_RANGO'
            );
        }

        if ($validarNota && self::longitud($nota) > ReservacionConfig::NOTA_MAX_CARACTERES) {
            self::agregarError($errors, $fieldCodes, 'nota', 'La nota es demasiado larga. Usa maximo 500 caracteres.', 'NOTA_DEMASIADO_LARGA');
        }

        if ($permitirComentarioAdmin && self::longitud($comentario) > ReservacionConfig::COMENTARIO_ADMIN_MAX_CARACTERES) {
            self::agregarError($errors, $fieldCodes, 'comentario_admin', 'El comentario interno es demasiado largo.', 'COMENTARIO_DEMASIADO_LARGO');
        }

        if (empty($errors['fecha']) && empty($errors['hora'])) {
            $horario = HorarioReservacionService::validarHorarioReservacion($fecha, $hora);

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
                    self::mensajeHorario($codigoHorario),
                    self::codigoCampoHorario($codigoHorario)
                );
            }
        }

        if (!empty($errors)) {
            return [
                'ok' => false,
                'codigo' => self::DATOS_INVALIDOS,
                'codigo_horario' => $codigoHorario,
                'msg' => self::primerError($errors),
                'errors' => $errors,
                'field_codes' => $fieldCodes,
            ];
        }

        return [
            'ok' => true,
            'datos' => [
                'nombre' => $nombre,
                'email' => $email,
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

    private static function actualizarFila(int $reservacionId, array $datos, string $estado): void
    {
        $sets = [
            "nombre = '" . ActiveRecord::escaparString($datos['nombre']) . "'",
            "email = '" . ActiveRecord::escaparString($datos['email']) . "'",
            "fecha = '" . ActiveRecord::escaparString($datos['fecha']) . "'",
            "hora = '" . ActiveRecord::escaparString($datos['hora']) . "'",
            "comensales = " . (int)$datos['comensales'],
            "estado = '" . ActiveRecord::escaparString($estado) . "'",
        ];

        if (Reservacion::tieneComentarioAdmin()) {
            $sets[] = "comentario_admin = '" . ActiveRecord::escaparString($datos['comentario_admin']) . "'";
        }

        self::ejecutar(
            "UPDATE reservaciones
             SET " . implode(', ', $sets) . "
             WHERE id = {$reservacionId}
             LIMIT 1"
        );
    }

    private static function guardarComentarioAdmin(int $reservacionId, string $comentario): void
    {
        if ($reservacionId < 1 || !Reservacion::tieneComentarioAdmin()) {
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
            'codigo' => self::HORARIO_INVALIDO,
            'codigo_horario' => $codigoHorario,
            'errors' => [
                $field => [self::mensajeHorario($codigoHorario)],
            ],
        ];
    }

    private static function mensajeHorario(string $codigo): string
    {
        return match ($codigo) {
            HorarioReservacionService::FECHA_INVALIDA => 'La fecha seleccionada no es valida.',
            HorarioReservacionService::FECHA_PASADA => 'No se pueden elegir fechas anteriores.',
            HorarioReservacionService::HORARIO_PASADO => 'Ese horario ya no esta disponible.',
            HorarioReservacionService::DIA_INACTIVO => 'El restaurante no recibe reservaciones en esa fecha.',
            HorarioReservacionService::HORARIO_INVALIDO => 'Ese horario no esta disponible.',
            default => 'No pudimos validar el horario. Intenta de nuevo.',
        };
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

    private static function agregarError(array &$errors, array &$fieldCodes, string $field, string $message, string $code): void
    {
        $errors[$field][] = $message;
        $fieldCodes[$field][] = $code;
    }

    private static function primerError(array $errors): string
    {
        foreach ($errors as $mensajes) {
            if (!empty($mensajes[0])) {
                return $mensajes[0];
            }
        }

        return 'Revisa los datos de tu reservacion.';
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
