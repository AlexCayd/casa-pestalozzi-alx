<?php

namespace Services;

/**
 * Catálogo único de resultados que cruzan las superficies de reservaciones.
 *
 * Los servicios devuelven códigos y contexto; esta clase es la única fuente
 * de traducción visible para las superficies de reservaciones y POS.
 */
final class ReservacionErrorCatalog
{
    public const TIPO_ERROR = 'error';
    public const TIPO_CONFLICTO = 'conflicto_recuperable';
    public const TIPO_ADVERTENCIA = 'advertencia';
    public const TIPO_DECISION = 'decision_requerida';
    public const TIPO_INFORMACION = 'informacion';

    /**
     * Código emitido actualmente o requerido por el contrato vigente.
     * El auditor estático compara este registro con los códigos encontrados
     * en servicios y controladores relacionados.
     *
     * @var array<string, string>
     */
    private const CODE_TYPES = [
        // Resultados y estados de reservación.
        'OK' => self::TIPO_INFORMACION,
        'RESERVACION_CREADA' => self::TIPO_INFORMACION,
        'RESERVACION_CREADA_SIN_MESA' => self::TIPO_DECISION,
        'ACTUALIZADA' => self::TIPO_INFORMACION,
        'ACTUALIZADA_REQUIERE_ASIGNACION' => self::TIPO_DECISION,
        'COMENTARIO_ACTUALIZADO' => self::TIPO_INFORMACION,
        'CONFIRMADA' => self::TIPO_INFORMACION,
        'COMPLETADA' => self::TIPO_INFORMACION,
        'CANCELADA' => self::TIPO_INFORMACION,
        'NO_SHOW' => self::TIPO_INFORMACION,
        'RESERVACION_CONFIRMADA' => self::TIPO_INFORMACION,
        'RESERVACION_MODIFICADA' => self::TIPO_INFORMACION,
        'RESERVACION_CANCELADA' => self::TIPO_INFORMACION,
        'REEMPLAZO_CREADO' => self::TIPO_INFORMACION,
        'REEMPLAZO_CONFIRMADO' => self::TIPO_INFORMACION,
        'RETENCION_CREADA' => self::TIPO_INFORMACION,
        'RETENCIONES_EXPIRADAS' => self::TIPO_INFORMACION,
        'HORARIOS_ACTUALIZADOS' => self::TIPO_INFORMACION,
        'HORARIOS_OBTENIDOS' => self::TIPO_INFORMACION,
        'EXCEPCION_CREADA' => self::TIPO_INFORMACION,
        'EXCEPCION_ACTUALIZADA' => self::TIPO_INFORMACION,
        'EXCEPCION_ELIMINADA' => self::TIPO_INFORMACION,
        'EXCEPCION_ESTADO_ACTUALIZADO' => self::TIPO_INFORMACION,
        'ANUNCIO_ACTUALIZADO' => self::TIPO_INFORMACION,
        'DISPONIBILIDAD_CONSULTADA' => self::TIPO_INFORMACION,
        'HORARIO_DISPONIBLE' => self::TIPO_INFORMACION,
        'ASIGNACION_GUARDADA' => self::TIPO_INFORMACION,
        'CONTACTO_VERIFICADO' => self::TIPO_INFORMACION,
        'OTP_GENERADO' => self::TIPO_INFORMACION,
        'OTP_SOLICITADO' => self::TIPO_INFORMACION,
        'GESTION_SALIDA' => self::TIPO_INFORMACION,

        // Seguridad, sesión y entrada.
        'SESION_PUBLICA_EXPIRADA' => self::TIPO_ERROR,
        'NO_AUTORIZADO' => self::TIPO_ERROR,
        'PERMISO_DENEGADO' => self::TIPO_ERROR,
        'CSRF_INVALIDO' => self::TIPO_ERROR,
        'CONTACTO_NO_VERIFICADO' => self::TIPO_ERROR,
        'CONTACTO_NO_COINCIDE' => self::TIPO_ERROR,
        'DATOS_INVALIDOS' => self::TIPO_ERROR,
        'DATOS_INCOMPLETOS' => self::TIPO_ERROR,
        'METODO_NO_PERMITIDO' => self::TIPO_ERROR,
        'METODO_INVALIDO' => self::TIPO_ERROR,
        'ERROR_INTERNO' => self::TIPO_ERROR,
        'ERROR_DISPONIBILIDAD' => self::TIPO_ERROR,
        'RESPUESTA_INVALIDA' => self::TIPO_ERROR,
        'FECHA_RESPUESTA_MISMATCH' => self::TIPO_CONFLICTO,
        'MAPA_CARGA_FALLIDA' => self::TIPO_ERROR,
        'TOTAL_TICKET_NO_DISPONIBLE' => self::TIPO_ERROR,
        'TICKET_NO_VALIDO' => self::TIPO_ERROR,
        'TICKET_ITEMS_PENDIENTES' => self::TIPO_CONFLICTO,
        'TICKET_CIERRE_FALLIDO' => self::TIPO_ERROR,
        'PAGO_INVALIDO' => self::TIPO_ERROR,
        'PAGO_REQUERIDO' => self::TIPO_ERROR,
        'PAGO_INSUFICIENTE' => self::TIPO_ERROR,
        'METODO_PAGO_INVALIDO' => self::TIPO_ERROR,
        'COMANDA_ENVIO_FALLIDO' => self::TIPO_ERROR,
        'ITEM_ID_REQUERIDO' => self::TIPO_ERROR,
        'ITEM_CANCELACION_FALLIDA' => self::TIPO_ERROR,
        'ITEM_ENTREGA_FALLIDA' => self::TIPO_ERROR,
        'TICKET_ID_REQUERIDO' => self::TIPO_ERROR,
        'TICKET_ACTUALIZACION_FALLIDA' => self::TIPO_ERROR,
        'TICKET_ITEMS_NO_DISPONIBLES' => self::TIPO_ERROR,
        'SUGERENCIAS_NO_CONFIGURADAS' => self::TIPO_INFORMACION,
        'SUGERENCIAS_TICKET_INVALIDO' => self::TIPO_CONFLICTO,
        'SUGERENCIAS_ERROR' => self::TIPO_ERROR,
        'CORTE_CAJA_ERROR' => self::TIPO_ERROR,
        'ERROR_CONSULTA_HORARIOS' => self::TIPO_ERROR,
        'ERROR_ACTUALIZACION_HORARIOS' => self::TIPO_ERROR,
        'EXCEPCION_NO_ENCONTRADA' => self::TIPO_ERROR,
        'EXCEPCION_DUPLICADA' => self::TIPO_CONFLICTO,

        // OTP.
        'REENVIO_NO_DISPONIBLE' => self::TIPO_CONFLICTO,
        'OTP_INCORRECTO' => self::TIPO_ERROR,
        'OTP_EXPIRADO' => self::TIPO_ERROR,
        'OTP_INTENTOS_AGOTADOS' => self::TIPO_CONFLICTO,
        'VERIFICACION_NO_ENCONTRADA' => self::TIPO_ERROR,

        // Horarios y operación.
        'FECHA_INVALIDA' => self::TIPO_ERROR,
        'FECHA_PASADA' => self::TIPO_ERROR,
        'FECHA_FUERA_DE_HORIZONTE' => self::TIPO_ERROR,
        'FECHA_CERRADA' => self::TIPO_ERROR,
        'FECHA_PASADA_SOLO_LECTURA' => self::TIPO_INFORMACION,
        'HORARIO_INVALIDO' => self::TIPO_ERROR,
        'HORARIO_NO_VALIDO' => self::TIPO_ERROR,
        'HORARIO_PASADO' => self::TIPO_ERROR,
        'EXCEPCION_ID_INVALIDO' => self::TIPO_ERROR,
        'EXCEPCION_FECHA_REQUERIDA' => self::TIPO_ERROR,
        'EXCEPCION_FECHA_INVALIDA' => self::TIPO_ERROR,
        'EXCEPCION_FECHA_PASADA' => self::TIPO_ERROR,
        'EXCEPCION_TIPO_INVALIDO' => self::TIPO_ERROR,
        'EXCEPCION_MOTIVO_DEMASIADO_LARGO' => self::TIPO_ERROR,
        'EXCEPCION_HORA_APERTURA_REQUERIDA' => self::TIPO_ERROR,
        'EXCEPCION_HORA_APERTURA_INVALIDA' => self::TIPO_ERROR,
        'EXCEPCION_HORA_CIERRE_REQUERIDA' => self::TIPO_ERROR,
        'EXCEPCION_HORA_CIERRE_INVALIDA' => self::TIPO_ERROR,
        'EXCEPCION_HORAS_INVALIDAS' => self::TIPO_ERROR,
        'EXCEPCION_HORARIO_PASADO' => self::TIPO_ERROR,
        'HORARIO_SIN_CONFIGURACION' => self::TIPO_ERROR,
        'DIA_INACTIVO' => self::TIPO_ERROR,
        'ANTICIPACION_INSUFICIENTE' => self::TIPO_ERROR,
        'DESPUES_DE_ULTIMA_RESERVACION' => self::TIPO_ERROR,
        'ULTIMA_RESERVACION_SUPERADA' => self::TIPO_ERROR,
        'JORNADA_TERMINADA' => self::TIPO_ERROR,

        // Disponibilidad, capacidad y asignación.
        'SIN_DISPONIBILIDAD' => self::TIPO_CONFLICTO,
        'CAPACIDAD_INSUFICIENTE' => self::TIPO_CONFLICTO,
        'CAPACIDAD_OPERATIVA_EXCEDIDA' => self::TIPO_DECISION,
        'SIN_ASIGNACION' => self::TIPO_DECISION,
        'REQUIERE_CONFIRMACION' => self::TIPO_DECISION,
        'REQUIERE_CONFIRMACION_CAPACIDAD' => self::TIPO_DECISION,
        'REQUIERE_CONFIRMACION_SIN_CONTACTO' => self::TIPO_DECISION,
        'REQUIERE_REASIGNACION' => self::TIPO_CONFLICTO,
        'ASIGNACION_VACIA' => self::TIPO_ERROR,
        'MESAS_INVALIDAS' => self::TIPO_ERROR,
        'MESA_NO_RESERVABLE' => self::TIPO_ERROR,
        'MESAS_ASIGNADAS_NO_DISPONIBLES' => self::TIPO_CONFLICTO,
        'MESAS_SIN_ASIGNAR' => self::TIPO_DECISION,
        'MESA_OCUPADA' => self::TIPO_CONFLICTO,
        'MESA_OCUPADA_EN_HORARIO' => self::TIPO_CONFLICTO,
        'GRUPO_NO_DISPONIBLE' => self::TIPO_CONFLICTO,
        'AGRUPACION_NO_AUTORIZADA' => self::TIPO_ERROR,
        'SUPERPOSICION_NO_AUTORIZADA' => self::TIPO_CONFLICTO,
        'CONFLICTO_DE_ASIGNACION' => self::TIPO_CONFLICTO,
        'CONFLICTO_TICKETS_ABIERTOS' => self::TIPO_CONFLICTO,
        'CONFLICTO_TICKET_ABIERTO' => self::TIPO_CONFLICTO,
        'DEPENDE_LIBERACION_PROYECTADA' => self::TIPO_ADVERTENCIA,
        'LIBERAR_ASIGNACION_ACTUAL' => self::TIPO_DECISION,
        'LIBERACION_NO_AUTORIZADA' => self::TIPO_ERROR,
        'VERSION_DESACTUALIZADA' => self::TIPO_CONFLICTO,
        'CONFLICTO_CONCURRENTE' => self::TIPO_CONFLICTO,

        // Identidad y reglas de reservación.
        'RESERVACION_NO_ENCONTRADA' => self::TIPO_ERROR,
        'NO_EXISTE' => self::TIPO_ERROR,
        'RESERVACION_NO_PERTENECE_AL_CONTACTO' => self::TIPO_ERROR,
        'RESERVACION_NO_EDITABLE' => self::TIPO_ERROR,
        'ESTADO_INVALIDO' => self::TIPO_ERROR,
        'ESTADO_NO_EDITABLE' => self::TIPO_ERROR,
        'RESERVACION_PASADA' => self::TIPO_ERROR,
        'RESERVACION_HORARIO_PASADO' => self::TIPO_ERROR,
        'MODIFICACION_NO_PERMITIDA' => self::TIPO_ERROR,
        'CANCELACION_NO_PERMITIDA' => self::TIPO_ERROR,
        'RESERVACION_DUPLICADA' => self::TIPO_CONFLICTO,
        'LIMITE_RESERVACIONES_ALCANZADO' => self::TIPO_CONFLICTO,
        'RETENCION_EXPIRADA' => self::TIPO_CONFLICTO,
        'SIN_CONTACTO' => self::TIPO_DECISION,
        'CONTACTO_TIPO_NO_EDITABLE' => self::TIPO_ERROR,
        'COMENTARIO_NO_DISPONIBLE' => self::TIPO_ERROR,
        'CONFIRMAR_SIN_MESA' => self::TIPO_DECISION,
        'REQUEST_TOKEN_CONFLICTO' => self::TIPO_CONFLICTO,
        'REEMPLAZO_NO_ENCONTRADO' => self::TIPO_ERROR,
        'HOLD_MODIFICACION_EXPIRADO' => self::TIPO_CONFLICTO,
        'LIMITE_MODIFICACION_VENCIDO' => self::TIPO_ERROR,
        'TOKEN_OPERACION_INVALIDO' => self::TIPO_ERROR,
        'TOKEN_OPERACION_CONFLICTIVO' => self::TIPO_CONFLICTO,
        'CAMBIO_CONCURRENTE' => self::TIPO_CONFLICTO,
        'DISPONIBILIDAD_CAMBIO' => self::TIPO_CONFLICTO,
        'HORARIO_ORIGINAL_NO_PRESERVABLE' => self::TIPO_CONFLICTO,

        // Tickets y operación POS.
        'TICKET_ABIERTO' => self::TIPO_CONFLICTO,
        'TICKET_NO_ENCONTRADO' => self::TIPO_ERROR,
        'TICKET_YA_CERRADO' => self::TIPO_CONFLICTO,
        'TICKET_CERRADO' => self::TIPO_INFORMACION,
        'TICKET_DUPLICADO' => self::TIPO_CONFLICTO,
        'MESAS_TICKET_EN_CONFLICTO' => self::TIPO_CONFLICTO,
        'RESERVACION_YA_EN_CURSO' => self::TIPO_CONFLICTO,
        'RESERVACION_SIN_TICKET' => self::TIPO_ADVERTENCIA,
        'RESERVACION_PROXIMA' => self::TIPO_ADVERTENCIA,
        'TOLERANCIA_VIGENTE' => self::TIPO_ADVERTENCIA,
        'TOLERANCIA_LLEGADA_VENCIDA' => self::TIPO_DECISION,
        'REGISTRO_AUSENCIA_NO_DISPONIBLE' => self::TIPO_ERROR,
        'RESERVACION_YA_NO_SHOW' => self::TIPO_INFORMACION,
        'RESERVACION_CON_TICKET_ABIERTO' => self::TIPO_CONFLICTO,

        // Mantenimiento y compatibilidad histórica.
        'AMBIENTE_NO_PERMITIDO' => self::TIPO_ERROR,
        'CONFIRMACION_INVALIDA' => self::TIPO_ERROR,
        'RESERVACIONES_AFECTADAS' => self::TIPO_CONFLICTO,

        // Códigos de validación de campos; no se emiten como causa principal.
        'REQUEST_TOKEN_INVALIDO' => self::TIPO_ERROR,
        'NOMBRE_REQUERIDO' => self::TIPO_ERROR,
        'NOMBRE_INVALIDO' => self::TIPO_ERROR,
        'NOMBRE_DEMASIADO_LARGO' => self::TIPO_ERROR,
        'CONTACTO_TIPO_INVALIDO' => self::TIPO_ERROR,
        'CONTACTO_INVALIDO' => self::TIPO_ERROR,
        'FECHA_REQUERIDA' => self::TIPO_ERROR,
        'HORA_REQUERIDA' => self::TIPO_ERROR,
        'HORA_INVALIDA' => self::TIPO_ERROR,
        'COMENSALES_REQUERIDOS' => self::TIPO_ERROR,
        'COMENSALES_INVALIDOS' => self::TIPO_ERROR,
        'COMENSALES_FUERA_DE_RANGO' => self::TIPO_ERROR,
        'NOTA_DEMASIADO_LARGA' => self::TIPO_ERROR,
        'COMENTARIO_DEMASIADO_LARGO' => self::TIPO_ERROR,
        'HORARIO_NO_DISPONIBLE' => self::TIPO_ERROR,
        'DIA_NO_DISPONIBLE' => self::TIPO_ERROR,
        'CONTACTO_REQUERIDO' => self::TIPO_ERROR,
        'CONTACTO_TIPO_NO_EDITABLE_CAMPO' => self::TIPO_ERROR,
        'FECHA_NO_VALIDA' => self::TIPO_ERROR,
        'HORA_NO_VALIDA' => self::TIPO_ERROR,
        'DATOS_RESERVACION_INVALIDOS' => self::TIPO_ERROR,
        'OTP_INVALIDO' => self::TIPO_ERROR,
    ];

    /** No quedan aliases de códigos emitidos; los nombres de constantes públicas son compatibilidad PHP. */
    private const ALIASES = [];

    /** Mensajes específicos; los restantes usan una traducción segura común. */
    private const TEXTS = [
        'SESION_PUBLICA_EXPIRADA' => [
            'titulo' => 'Sesión de reservaciones expirada',
            'mensaje' => 'Verifica nuevamente tu contacto para continuar.',
            'consecuencia' => 'No se realizó la operación solicitada.',
            'acciones' => [['id' => 'VERIFICAR_CONTACTO', 'tipo' => 'primary']],
        ],
        'CSRF_INVALIDO' => [
            'titulo' => 'Validación de seguridad no válida',
            'mensaje' => 'Recarga la página e inténtalo nuevamente.',
            'consecuencia' => 'La operación no inició o no se aplicaron cambios.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'NO_AUTORIZADO' => [
            'titulo' => 'Sesión requerida',
            'mensaje' => 'Inicia sesión para continuar.',
            'consecuencia' => 'La operación no se ejecutó.',
            'acciones' => [['id' => 'INICIAR_SESION', 'tipo' => 'primary']],
        ],
        'PERMISO_DENEGADO' => [
            'titulo' => 'Permiso insuficiente',
            'mensaje' => 'Tu cuenta no tiene permiso para realizar esta acción.',
            'consecuencia' => 'La operación no se ejecutó.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'CONTACTO_NO_VERIFICADO' => [
            'titulo' => 'Contacto no verificado',
            'mensaje' => 'Verifica tu contacto antes de continuar.',
            'consecuencia' => 'No se creó ni modificó la reservación.',
            'acciones' => [['id' => 'VERIFICAR_CONTACTO', 'tipo' => 'primary']],
        ],
        'CONTACTO_NO_COINCIDE' => [
            'titulo' => 'Contacto no coincide',
            'mensaje' => 'El contacto enviado no coincide con el contacto verificado.',
            'consecuencia' => 'No se autorizó la operación.',
            'acciones' => [['id' => 'VERIFICAR_CONTACTO', 'tipo' => 'primary']],
        ],
        'OTP_INCORRECTO' => [
            'titulo' => 'Código incorrecto',
            'mensaje' => 'El código no coincide. Revisa los dígitos e inténtalo nuevamente.',
            'consecuencia' => 'La verificación no terminó.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'OTP_EXPIRADO' => [
            'titulo' => 'Código vencido',
            'mensaje' => 'El código venció. Solicita uno nuevo para continuar.',
            'consecuencia' => 'La verificación no terminó.',
            'acciones' => [['id' => 'SOLICITAR_CODIGO', 'tipo' => 'primary']],
        ],
        'OTP_INTENTOS_AGOTADOS' => [
            'titulo' => 'Límite de intentos alcanzado',
            'mensaje' => 'Solicita un código nuevo para continuar.',
            'consecuencia' => 'El código actual ya no puede utilizarse.',
            'acciones' => [['id' => 'SOLICITAR_CODIGO', 'tipo' => 'primary']],
        ],
        'VERIFICACION_NO_ENCONTRADA' => [
            'titulo' => 'Código no disponible',
            'mensaje' => 'Solicita un código nuevo para continuar.',
            'consecuencia' => 'La verificación no terminó.',
            'acciones' => [['id' => 'SOLICITAR_CODIGO', 'tipo' => 'primary']],
        ],
        'REENVIO_NO_DISPONIBLE' => [
            'titulo' => 'Espera un momento',
            'mensaje' => 'Todavía no puedes solicitar otro código.',
            'consecuencia' => 'El código anterior conserva su vigencia.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'CAPACIDAD_INSUFICIENTE' => [
            'titulo' => 'Capacidad insuficiente',
            'mensaje' => 'La capacidad disponible no cubre la cantidad solicitada.',
            'consecuencia' => 'La operación no puede continuar automáticamente.',
            'acciones' => [['id' => 'ACTUALIZAR_MAPA', 'tipo' => 'primary'], ['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'CAPACIDAD_OPERATIVA_EXCEDIDA' => [
            'titulo' => 'Capacidad operativa excedida',
            'mensaje' => 'La solicitud supera la capacidad disponible para este horario.',
            'consecuencia' => 'La reservacion quedara confirmada sin garantia de asignacion fisica y debera resolverse manualmente.',
            'acciones' => [['id' => 'CONFIRMAR_SOBRECAPACIDAD', 'tipo' => 'primary'], ['id' => 'VOLVER', 'tipo' => 'secondary']],
        ],
        'SIN_ASIGNACION' => [
            'titulo' => 'Asignación manual pendiente',
            'mensaje' => 'No existe una combinación automática válida de mesas.',
            'consecuencia' => 'La reservación puede confirmarse y quedar pendiente de asignación.',
            'acciones' => [['id' => 'CONFIRMAR_SIN_MESAS', 'tipo' => 'primary'], ['id' => 'VOLVER', 'tipo' => 'secondary']],
        ],
        'MESA_OCUPADA' => [
            'titulo' => 'Mesa ocupada',
            'mensaje' => 'Una de las mesas cambió de estado y ya no está disponible.',
            'consecuencia' => 'No se aplicó la asignación solicitada.',
            'acciones' => [['id' => 'ACTUALIZAR_MAPA', 'tipo' => 'primary']],
        ],
        'CONFLICTO_CONCURRENTE' => [
            'titulo' => 'La información cambió',
            'mensaje' => 'La operación perdió vigencia porque otra acción actualizó los datos.',
            'consecuencia' => 'No se aplicó una escritura parcial.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'VERSION_DESACTUALIZADA' => [
            'titulo' => 'Datos desactualizados',
            'mensaje' => 'La reservación cambió desde la última consulta.',
            'consecuencia' => 'No se aplicó la modificación.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'TICKET_ABIERTO' => [
            'titulo' => 'Ticket abierto existente',
            'mensaje' => 'Esta mesa o reservación ya tiene un ticket abierto.',
            'consecuencia' => 'No se abrió un ticket paralelo.',
            'acciones' => [['id' => 'CONSULTAR_TICKET', 'tipo' => 'primary'], ['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'TOLERANCIA_VIGENTE' => [
            'titulo' => 'Tolerancia vigente',
            'mensaje' => 'La tolerancia de llegada sigue vigente.',
            'consecuencia' => 'La mesa continúa protegida por la reservación.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'TOLERANCIA_LLEGADA_VENCIDA' => [
            'titulo' => 'Tolerancia vencida',
            'mensaje' => 'La tolerancia de llegada venció. Registra la ausencia antes de utilizar la mesa.',
            'consecuencia' => 'No se puede iniciar el servicio desde este estado.',
            'acciones' => [['id' => 'REGISTRAR_AUSENCIA', 'tipo' => 'primary']],
        ],
        'REQUIERE_REASIGNACION' => [
            'titulo' => 'Se requiere reasignación',
            'mensaje' => 'Las mesas originales ya no están disponibles.',
            'consecuencia' => 'La operación no puede conservar la asignación actual.',
            'acciones' => [['id' => 'REASIGNAR_MESAS', 'tipo' => 'primary'], ['id' => 'ACTUALIZAR', 'tipo' => 'secondary']],
        ],
        'ERROR_INTERNO' => [
            'titulo' => 'No fue posible completar la operación',
            'mensaje' => 'Ocurrió un problema interno. Intenta nuevamente.',
            'consecuencia' => 'La operación se revirtió o no llegó a iniciar.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'METODO_NO_PERMITIDO' => [
            'titulo' => 'Método no permitido',
            'mensaje' => 'La operación solicitada no está disponible con esta petición.',
            'consecuencia' => 'No se ejecutó ninguna mutación.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'HORARIOS_ACTUALIZADOS' => [
            'titulo' => 'Horarios actualizados',
            'mensaje' => 'Los horarios de operación fueron actualizados.',
            'consecuencia' => 'La configuración quedó guardada.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'HORARIOS_OBTENIDOS' => [
            'titulo' => 'Horarios consultados',
            'mensaje' => 'La configuración de horarios está disponible.',
            'consecuencia' => 'Puedes revisar o modificar la jornada.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'EXCEPCION_CREADA' => [
            'titulo' => 'Excepción guardada',
            'mensaje' => 'El cierre u horario especial quedó guardado.',
            'consecuencia' => 'La configuración especial está activa.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'EXCEPCION_ACTUALIZADA' => [
            'titulo' => 'Excepción actualizada',
            'mensaje' => 'La excepción quedó actualizada.',
            'consecuencia' => 'La configuración especial está activa.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'EXCEPCION_ELIMINADA' => [
            'titulo' => 'Excepción eliminada',
            'mensaje' => 'La excepción quedó eliminada.',
            'consecuencia' => 'La fecha volverá a utilizar el horario semanal.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'EXCEPCION_ESTADO_ACTUALIZADO' => [
            'titulo' => 'Estado actualizado',
            'mensaje' => 'El estado de la excepción quedó actualizado.',
            'consecuencia' => 'La configuración operativa fue aplicada.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'ANUNCIO_ACTUALIZADO' => [
            'titulo' => 'Anuncio actualizado',
            'mensaje' => 'El anuncio principal quedó actualizado.',
            'consecuencia' => 'La nueva configuración está disponible para el sitio.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'RESERVACION_PROXIMA' => [
            'titulo' => 'Reservación próxima',
            'mensaje' => 'Hay una reservación próxima para las {hora}; faltan {minutos_restantes} minutos.',
            'consecuencia' => 'Confirma la operación sólo si la mesa quedará disponible a tiempo.',
            'acciones' => [['id' => 'CONFIRMAR', 'tipo' => 'primary'], ['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'RESERVACIONES_AFECTADAS' => [
            'titulo' => 'Reservaciones afectadas',
            'mensaje' => 'El cambio de horario afecta reservaciones existentes.',
            'consecuencia' => 'Confirma la operación después de revisar las reservaciones afectadas.',
            'acciones' => [['id' => 'CONFIRMAR', 'tipo' => 'primary'], ['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'EXCEPCION_NO_ENCONTRADA' => [
            'titulo' => 'Excepción no encontrada',
            'mensaje' => 'La excepción seleccionada ya no existe.',
            'consecuencia' => 'Actualiza la lista antes de continuar.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'EXCEPCION_DUPLICADA' => [
            'titulo' => 'Fecha ya configurada',
            'mensaje' => 'Ya existe una excepción para esa fecha.',
            'consecuencia' => 'Edita la excepción existente antes de guardar otra.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'DATOS_INVALIDOS' => [
            'titulo' => 'Datos inválidos',
            'mensaje' => 'Revisa los datos enviados e inténtalo nuevamente.',
            'consecuencia' => 'No se aplicaron cambios.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'REQUIERE_CONFIRMACION_SIN_CONTACTO' => [
            'titulo' => 'Falta confirmar el contacto',
            'mensaje' => 'Confirma que deseas crear la reservación sin contacto.',
            'consecuencia' => 'La reservación todavía no se creó.',
            'acciones' => [['id' => 'CONFIRMAR', 'tipo' => 'primary']],
        ],
        'REQUIERE_CONFIRMACION_CAPACIDAD' => [
            'titulo' => 'Capacidad insuficiente',
            'mensaje' => 'La reservación es para {capacidad_solicitada} personas, pero sólo hay capacidad disponible para {capacidad_disponible}.',
            'consecuencia' => 'La reservación todavía no se guardó.',
            'acciones' => [['id' => 'CONFIRMAR', 'tipo' => 'primary']],
        ],
        'RESERVACION_CREADA' => [
            'titulo' => 'Reservación creada',
            'mensaje' => 'Reservación creada y mesas asignadas correctamente.',
            'consecuencia' => 'La reservación quedó registrada.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'RESERVACION_CREADA_SIN_MESA' => [
            'titulo' => 'Reservación creada',
            'mensaje' => 'Reservación creada. No fue posible asignar mesas automáticamente.',
            'consecuencia' => 'La asignación de mesas queda pendiente.',
            'acciones' => [['id' => 'ASIGNAR_MESAS', 'tipo' => 'primary']],
        ],
        'ACTUALIZADA' => [
            'titulo' => 'Reservación actualizada',
            'mensaje' => 'Reservación guardada y mesas asignadas.',
            'consecuencia' => 'Los cambios quedaron registrados.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'ACTUALIZADA_REQUIERE_ASIGNACION' => [
            'titulo' => 'Reservación actualizada',
            'mensaje' => 'Reservación guardada. Queda pendiente asignar mesas.',
            'consecuencia' => 'La asignación de mesas queda pendiente.',
            'acciones' => [['id' => 'ASIGNAR_MESAS', 'tipo' => 'primary']],
        ],
        'DISPONIBILIDAD_CONSULTADA' => [
            'titulo' => 'Disponibilidad consultada',
            'mensaje' => 'La disponibilidad está actualizada.',
            'consecuencia' => 'Puedes continuar con la selección.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'SIN_DISPONIBILIDAD' => [
            'titulo' => 'Sin disponibilidad',
            'mensaje' => 'No encontramos disponibilidad para esa selección.',
            'consecuencia' => 'No se puede confirmar ese horario.',
            'acciones' => [['id' => 'ELEGIR_OTRO_HORARIO', 'tipo' => 'primary']],
        ],
        'FECHA_INVALIDA' => [
            'titulo' => 'Fecha no válida',
            'mensaje' => 'La fecha seleccionada no es válida.',
            'consecuencia' => 'No se puede consultar ese día.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'FECHA_PASADA' => [
            'titulo' => 'Fecha anterior',
            'mensaje' => 'No se pueden elegir fechas anteriores.',
            'consecuencia' => 'Selecciona una fecha vigente.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'FECHA_PASADA_SOLO_LECTURA' => [
            'titulo' => 'Modo histórico',
            'mensaje' => 'La fecha consultada ya pasó.',
            'consecuencia' => 'La asignación, los comentarios y los cambios de estado están deshabilitados.',
            'acciones' => [['id' => 'ELEGIR_OTRA_FECHA', 'tipo' => 'primary']],
        ],
        'FECHA_FUERA_DE_HORIZONTE' => [
            'titulo' => 'Fecha fuera de rango',
            'mensaje' => 'La fecha seleccionada todavía no está disponible para reservar.',
            'consecuencia' => 'Selecciona una fecha dentro del horizonte permitido.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'HORARIO_INVALIDO' => [
            'titulo' => 'Horario no disponible',
            'mensaje' => 'El horario seleccionado no está disponible para esta fecha.',
            'consecuencia' => 'No se aplicaron cambios.',
            'acciones' => [['id' => 'ELEGIR_OTRO_HORARIO', 'tipo' => 'primary']],
        ],
        'HORARIO_PASADO' => [
            'titulo' => 'Horario no disponible',
            'mensaje' => 'Ese horario ya pasó. Elige un horario posterior.',
            'consecuencia' => 'No se aplicaron cambios.',
            'acciones' => [['id' => 'ELEGIR_OTRO_HORARIO', 'tipo' => 'primary']],
        ],
        'DIA_INACTIVO' => [
            'titulo' => 'Día no operativo',
            'mensaje' => 'El restaurante no opera en la fecha seleccionada.',
            'consecuencia' => 'No hay horarios reservables para ese día.',
            'acciones' => [['id' => 'ELEGIR_OTRA_FECHA', 'tipo' => 'primary']],
        ],
        'HORARIO_SIN_CONFIGURACION' => [
            'titulo' => 'Horario no configurado',
            'mensaje' => 'No hay horarios configurados para la fecha seleccionada.',
            'consecuencia' => 'No se puede reservar ese día.',
            'acciones' => [['id' => 'ELEGIR_OTRA_FECHA', 'tipo' => 'primary']],
        ],
        'RETENCION_CREADA' => [
            'titulo' => 'Reservación apartada',
            'mensaje' => 'Conservaremos tus mesas durante {minutos} minutos mientras verificas el contacto.',
            'consecuencia' => 'La reservación queda pendiente de verificación.',
            'acciones' => [['id' => 'VERIFICAR_CONTACTO', 'tipo' => 'primary']],
        ],
        'CONTACTO_VERIFICADO' => [
            'titulo' => 'Contacto verificado',
            'mensaje' => 'Contacto verificado.',
            'consecuencia' => 'Puedes continuar con la reservación.',
            'acciones' => [['id' => 'CONTINUAR', 'tipo' => 'primary']],
        ],
        'OTP_GENERADO' => [
            'titulo' => 'Código generado',
            'mensaje' => 'Código generado para pruebas.',
            'consecuencia' => 'Usa el código para continuar.',
            'acciones' => [['id' => 'VERIFICAR_CODIGO', 'tipo' => 'primary']],
        ],
        'OTP_SOLICITADO' => [
            'titulo' => 'Código enviado',
            'mensaje' => 'Si el contacto es válido, recibirás un código.',
            'consecuencia' => 'La verificación queda pendiente.',
            'acciones' => [['id' => 'VERIFICAR_CODIGO', 'tipo' => 'primary']],
        ],
        'RESERVACION_CONFIRMADA' => [
            'titulo' => 'Reservación confirmada',
            'mensaje' => 'La reservación quedó confirmada.',
            'consecuencia' => 'La reservación está lista para operar.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'RESERVACION_MODIFICADA' => [
            'titulo' => 'Reservación actualizada',
            'mensaje' => 'Tu reservación fue actualizada.',
            'consecuencia' => 'Los cambios quedaron registrados.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'RESERVACION_CANCELADA' => [
            'titulo' => 'Reservación cancelada',
            'mensaje' => 'La reservación fue cancelada.',
            'consecuencia' => 'La reservación ya no ocupa disponibilidad.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'CANCELACION_NO_PERMITIDA' => [
            'titulo' => 'Cancelación no disponible',
            'mensaje' => 'La reservación ya no puede cancelarse en línea.',
            'consecuencia' => 'No se modificó la reservación.',
            'acciones' => [['id' => 'CONTACTAR_RESTAURANTE', 'tipo' => 'primary']],
        ],
        'REEMPLAZO_CREADO' => [
            'titulo' => 'Cambio pendiente',
            'mensaje' => 'Revisa el cambio y confírmalo para aplicarlo. Tu reservación original sigue confirmada.',
            'consecuencia' => 'La reservación original permanece vigente.',
            'acciones' => [['id' => 'CONFIRMAR_CAMBIO', 'tipo' => 'primary']],
        ],
        'REEMPLAZO_CONFIRMADO' => [
            'titulo' => 'Cambio aplicado',
            'mensaje' => 'Tu reservación fue actualizada.',
            'consecuencia' => 'Los cambios quedaron registrados.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'RETENCION_EXPIRADA' => [
            'titulo' => 'Retención vencida',
            'mensaje' => 'La retención venció.',
            'consecuencia' => 'La operación debe iniciarse nuevamente.',
            'acciones' => [['id' => 'INICIAR_NUEVAMENTE', 'tipo' => 'primary']],
        ],
        'REQUEST_TOKEN_CONFLICTO' => [
            'titulo' => 'Solicitud conflictiva',
            'mensaje' => 'La solicitud ya está asociada con otros datos. Inicia una nueva reservación.',
            'consecuencia' => 'No se repitió la operación.',
            'acciones' => [['id' => 'INICIAR_NUEVAMENTE', 'tipo' => 'primary']],
        ],
        'LIMITE_RESERVACIONES_ALCANZADO' => [
            'titulo' => 'Límite alcanzado',
            'mensaje' => 'Alcanzaste el límite de reservaciones activas.',
            'consecuencia' => 'No se creó una reservación adicional.',
            'acciones' => [['id' => 'CONSULTAR_RESERVACIONES', 'tipo' => 'primary']],
        ],
        'RESERVACION_DUPLICADA' => [
            'titulo' => 'Reservación duplicada',
            'mensaje' => 'Ya existe una reservación activa para este contacto en el horario seleccionado.',
            'consecuencia' => 'No se creó una segunda reservación.',
            'acciones' => [['id' => 'CONSULTAR_RESERVACIONES', 'tipo' => 'primary']],
        ],
        'RESERVACION_NO_PERTENECE_AL_CONTACTO' => [
            'titulo' => 'Reservación no autorizada',
            'mensaje' => 'La reservación no pertenece al contacto verificado.',
            'consecuencia' => 'No se aplicaron cambios.',
            'acciones' => [['id' => 'VERIFICAR_CONTACTO', 'tipo' => 'primary']],
        ],
        'CONTACTO_TIPO_NO_EDITABLE' => [
            'titulo' => 'Contacto no editable',
            'mensaje' => 'El tipo de contacto existente no puede cambiarse.',
            'consecuencia' => 'Corrige el valor o agrega un contacto si estaba vacío.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'RESERVACION_NO_ENCONTRADA' => [
            'titulo' => 'Reservación no encontrada',
            'mensaje' => 'No fue posible localizar la reservación.',
            'consecuencia' => 'No se aplicaron cambios.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'MAPA_CARGA_FALLIDA' => [
            'titulo' => 'No se pudo cargar el mapa',
            'mensaje' => 'No se pudo cargar la información operativa. Intenta de nuevo.',
            'consecuencia' => 'No se actualizó el estado de mesas ni reservaciones.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'TICKET_NO_VALIDO' => [
            'titulo' => 'Ticket no válido',
            'mensaje' => 'El ticket no existe o ya no puede utilizarse.',
            'consecuencia' => 'No se realizó la operación sobre la cuenta.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'TICKET_ITEMS_PENDIENTES' => [
            'titulo' => 'Hay productos pendientes',
            'mensaje' => 'Entrega o cancela todos los productos antes de cerrar la cuenta.',
            'consecuencia' => 'El ticket permanece abierto.',
            'acciones' => [['id' => 'ACTUALIZAR_TICKET', 'tipo' => 'primary']],
        ],
        'PAGO_INVALIDO' => [
            'titulo' => 'Pago no válido',
            'mensaje' => 'Revisa el comensal, método y monto del pago.',
            'consecuencia' => 'El ticket permanece abierto.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'PAGO_REQUERIDO' => [
            'titulo' => 'Falta registrar un pago',
            'mensaje' => 'Registra al menos un pago para continuar.',
            'consecuencia' => 'El ticket permanece abierto.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'PAGO_INSUFICIENTE' => [
            'titulo' => 'Pago insuficiente',
            'mensaje' => 'El monto recibido no cubre el total de la cuenta.',
            'consecuencia' => 'El ticket permanece abierto.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'METODO_PAGO_INVALIDO' => [
            'titulo' => 'Método de pago no válido',
            'mensaje' => 'Selecciona un método de pago válido.',
            'consecuencia' => 'El ticket permanece abierto.',
            'acciones' => [['id' => 'CORREGIR_DATOS', 'tipo' => 'primary']],
        ],
        'TOTAL_TICKET_NO_DISPONIBLE' => [
            'titulo' => 'No se pudo calcular la cuenta',
            'mensaje' => 'No fue posible calcular el total del ticket. Intenta de nuevo.',
            'consecuencia' => 'El ticket permanece abierto.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'TICKET_CIERRE_FALLIDO' => [
            'titulo' => 'No se pudo cerrar el ticket',
            'mensaje' => 'No fue posible cerrar el ticket. Intenta de nuevo.',
            'consecuencia' => 'La cuenta puede permanecer abierta.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'COMANDA_ENVIO_FALLIDO' => [
            'titulo' => 'No se pudo enviar la comanda',
            'mensaje' => 'No fue posible enviar la comanda. Intenta de nuevo.',
            'consecuencia' => 'Los productos no se confirmaron para preparación.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'ITEM_ID_REQUERIDO' => [
            'titulo' => 'Producto no identificado',
            'mensaje' => 'Selecciona un producto válido para continuar.',
            'consecuencia' => 'No se modificó el ticket.',
            'acciones' => [['id' => 'ACTUALIZAR_TICKET', 'tipo' => 'primary']],
        ],
        'ITEM_CANCELACION_FALLIDA' => [
            'titulo' => 'No se pudo cancelar el producto',
            'mensaje' => 'No fue posible cancelar el producto. Intenta de nuevo.',
            'consecuencia' => 'El ticket no cambió.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'ITEM_ENTREGA_FALLIDA' => [
            'titulo' => 'No se pudo entregar el producto',
            'mensaje' => 'No fue posible registrar la entrega. Intenta de nuevo.',
            'consecuencia' => 'El producto permanece en su estado anterior.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'TICKET_ID_REQUERIDO' => [
            'titulo' => 'Ticket no identificado',
            'mensaje' => 'Selecciona un ticket válido para continuar.',
            'consecuencia' => 'No se realizó la operación.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'TICKET_ACTUALIZACION_FALLIDA' => [
            'titulo' => 'No se pudo actualizar el ticket',
            'mensaje' => 'No fue posible actualizar el ticket. Intenta de nuevo.',
            'consecuencia' => 'El ticket conserva sus datos anteriores.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'TICKET_ITEMS_NO_DISPONIBLES' => [
            'titulo' => 'No se pudieron cargar los productos',
            'mensaje' => 'No fue posible consultar los productos del ticket.',
            'consecuencia' => 'La vista puede estar desactualizada.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'SUGERENCIAS_NO_CONFIGURADAS' => [
            'titulo' => 'Sugerencias no configuradas',
            'mensaje' => 'Las sugerencias automáticas todavía no están configuradas.',
            'consecuencia' => 'El ticket no se modifica.',
            'acciones' => [['id' => 'CERRAR', 'tipo' => 'secondary']],
        ],
        'SUGERENCIAS_TICKET_INVALIDO' => [
            'titulo' => 'Ticket no disponible',
            'mensaje' => 'El ticket ya no está abierto.',
            'consecuencia' => 'No se pudieron obtener sugerencias.',
            'acciones' => [['id' => 'ACTUALIZAR', 'tipo' => 'primary']],
        ],
        'SUGERENCIAS_ERROR' => [
            'titulo' => 'No se pudieron obtener sugerencias',
            'mensaje' => 'Intenta consultar las sugerencias nuevamente.',
            'consecuencia' => 'El ticket no se modifica.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
        'CORTE_CAJA_ERROR' => [
            'titulo' => 'No se pudo cargar el corte de caja',
            'mensaje' => 'No fue posible cargar el corte de caja. Intenta de nuevo.',
            'consecuencia' => 'No se mostró el resumen solicitado.',
            'acciones' => [['id' => 'REINTENTAR', 'tipo' => 'primary']],
        ],
    ];

    /** Traducciones de errores de campo; los servicios sólo emiten sus códigos. */
    private const FIELD_TEXTS = [
        'REQUEST_TOKEN_INVALIDO' => 'El identificador de la solicitud no es válido.',
        'NOMBRE_REQUERIDO' => 'Escribe un nombre para la reservación.',
        'NOMBRE_INVALIDO' => 'El nombre debe incluir letras o números.',
        'NOMBRE_DEMASIADO_LARGO' => 'El nombre es demasiado largo.',
        'CONTACTO_TIPO_INVALIDO' => 'Selecciona correo electrónico o teléfono.',
        'CONTACTO_INVALIDO' => 'El contacto no tiene un formato válido.',
        'FECHA_REQUERIDA' => 'Elige una fecha.',
        'FECHA_INVALIDA' => 'La fecha seleccionada no es válida.',
        'FECHA_NO_VALIDA' => 'La fecha seleccionada no es válida.',
        'HORA_REQUERIDA' => 'Elige una hora.',
        'HORA_INVALIDA' => 'La hora seleccionada no es válida.',
        'HORA_NO_VALIDA' => 'La hora seleccionada no es válida.',
        'COMENSALES_REQUERIDOS' => 'Indica el número de comensales.',
        'COMENSALES_INVALIDOS' => 'El número de comensales debe ser entero.',
        'COMENSALES_FUERA_DE_RANGO' => 'El número de comensales debe estar entre 1 y {max_comensales}.',
        'NOTA_DEMASIADO_LARGA' => 'La nota es demasiado larga.',
        'COMENTARIO_DEMASIADO_LARGO' => 'El comentario interno es demasiado largo.',
        'HORARIO_NO_DISPONIBLE' => 'El horario seleccionado no está disponible para esta fecha.',
        'DIA_NO_DISPONIBLE' => 'El restaurante no opera en la fecha seleccionada.',
        'CONTACTO_REQUERIDO' => 'Escribe el dato de contacto o selecciona Sin contacto.',
        'CONTACTO_TIPO_NO_EDITABLE_CAMPO' => 'El tipo de contacto existente no puede cambiarse.',
        'DATOS_RESERVACION_INVALIDOS' => 'Revisa los datos de la reservación.',
        'OTP_INVALIDO' => 'Escribe un código de seis dígitos.',
        'HORARIO_PASADO' => 'Ese horario ya pasó. Elige un horario posterior.',
        'FECHA_FUERA_DE_HORIZONTE' => 'La fecha seleccionada todavía no está disponible para reservar.',
        'HORARIO_SIN_CONFIGURACION' => 'No hay horarios configurados para la fecha seleccionada.',
        'ANTICIPACION_INSUFICIENTE' => 'El horario requiere más anticipación.',
        'DESPUES_DE_ULTIMA_RESERVACION' => 'Ese horario ya no puede reservarse.',
        'EXCEPCION_ID_INVALIDO' => 'Selecciona una excepción válida.',
        'EXCEPCION_FECHA_REQUERIDA' => 'Selecciona una fecha.',
        'EXCEPCION_FECHA_INVALIDA' => 'Selecciona una fecha válida.',
        'EXCEPCION_FECHA_PASADA' => 'No puedes registrar una excepción para una fecha anterior al día actual.',
        'EXCEPCION_TIPO_INVALIDO' => 'Selecciona un tipo de excepción válido.',
        'EXCEPCION_MOTIVO_DEMASIADO_LARGO' => 'El motivo no puede superar 160 caracteres.',
        'EXCEPCION_HORA_APERTURA_REQUERIDA' => 'Selecciona una hora de apertura.',
        'EXCEPCION_HORA_APERTURA_INVALIDA' => 'Selecciona una hora de apertura válida.',
        'EXCEPCION_HORA_CIERRE_REQUERIDA' => 'Selecciona una hora de cierre.',
        'EXCEPCION_HORA_CIERRE_INVALIDA' => 'Selecciona una hora de cierre válida.',
        'EXCEPCION_HORAS_INVALIDAS' => 'La hora de apertura debe ser anterior a la hora de cierre.',
        'EXCEPCION_HORARIO_PASADO' => 'El horario especial debe finalizar después de la hora actual.',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $catalogo = [];
        foreach (array_keys(self::CODE_TYPES) as $codigo) {
            $catalogo[$codigo] = self::definition($codigo);
        }
        return $catalogo;
    }

    /** @return array<string, string> */
    public static function aliases(): array
    {
        return self::ALIASES;
    }

    public static function has(string $codigo): bool
    {
        return array_key_exists($codigo, self::CODE_TYPES);
    }

    public static function canonical(string $codigo): string
    {
        return self::ALIASES[$codigo] ?? $codigo;
    }

    public static function code(string $codigo): string
    {
        $canonical = self::canonical($codigo);
        if (!self::has($canonical)) {
            throw new \InvalidArgumentException('Código de reservaciones no catalogado: ' . $codigo);
        }
        return $canonical;
    }

    public static function hasFieldCode(string $codigo): bool
    {
        return array_key_exists($codigo, self::FIELD_TEXTS);
    }

    /** @return array<string, string> */
    public static function fieldTexts(): array
    {
        return self::FIELD_TEXTS;
    }

    /** @return array<string, mixed> */
    public static function result(string $codigo, array $extra = [], array $contexto = []): array
    {
        $resultado = array_merge([
            'ok' => false,
            'codigo' => self::code($codigo),
        ], $extra);
        if ($contexto !== []) {
            $resultado['contexto'] = self::contextoSeguro($contexto);
        }
        return self::enriquecer($resultado);
    }

    /** @return array<string, mixed> */
    public static function definition(string $codigo): array
    {
        $canonical = self::canonical($codigo);
        if (!self::has($canonical)) {
            throw new \InvalidArgumentException('Código de reservaciones no catalogado: ' . $codigo);
        }

        $tipo = self::CODE_TYPES[$canonical];
        $http = match ($tipo) {
            self::TIPO_CONFLICTO => 409,
            self::TIPO_ERROR => 422,
            default => 200,
        };
        if (in_array($canonical, [
            'NO_AUTORIZADO',
            'SESION_PUBLICA_EXPIRADA',
            'CONTACTO_NO_VERIFICADO',
            'CONTACTO_NO_COINCIDE',
        ], true)) {
            $http = 401;
        } elseif (in_array($canonical, [
            'PERMISO_DENEGADO',
            'RESERVACION_NO_PERTENECE_AL_CONTACTO',
            'MODIFICACION_NO_PERMITIDA',
            'CANCELACION_NO_PERMITIDA',
        ], true)) {
            $http = 403;
        } elseif ($canonical === 'CSRF_INVALIDO') {
            $http = 403;
        } elseif ($canonical === 'METODO_NO_PERMITIDO') {
            $http = 405;
        } elseif ($canonical === 'RESERVACION_NO_ENCONTRADA') {
            $http = 404;
        } elseif (in_array($canonical, ['RETENCION_EXPIRADA', 'OTP_EXPIRADO'], true)) {
            $http = 410;
        } elseif (in_array($canonical, ['REENVIO_NO_DISPONIBLE', 'OTP_INTENTOS_AGOTADOS'], true)) {
            $http = 429;
        } elseif (in_array($canonical, [
            'SIN_DISPONIBILIDAD',
            'LIMITE_RESERVACIONES_ALCANZADO',
            'REQUEST_TOKEN_CONFLICTO',
        ], true)) {
            $http = 409;
        } elseif (in_array($canonical, ['RETENCION_EXPIRADA'], true)) {
            $http = 410;
        } elseif ($canonical === 'TOLERANCIA_LLEGADA_VENCIDA') {
            $http = 409;
        } elseif ($canonical === 'ESTADO_INVALIDO') {
            $http = 409;
        } elseif ($canonical === 'TOLERANCIA_VIGENTE') {
            $http = 422;
        } elseif (in_array($canonical, ['ERROR_ACTUALIZACION_HORARIOS', 'ERROR_CONSULTA_HORARIOS'], true)) {
            $http = 500;
        } elseif ($canonical === 'ERROR_INTERNO') {
            $http = 500;
        }

        $mensajeKey = 'reservaciones.codigo.' . strtolower($canonical);
        $base = [
            'codigo' => $canonical,
            'tipo' => $tipo,
            'http_status' => $http,
            'mensaje_key' => $mensajeKey,
            'titulo' => $tipo === self::TIPO_INFORMACION ? 'Operación completada' : 'No fue posible completar la operación',
            'mensaje' => $tipo === self::TIPO_INFORMACION
                ? 'La operación se completó.'
                : 'Revisa la información e inténtalo nuevamente.',
            'consecuencia' => $tipo === self::TIPO_INFORMACION
                ? 'El resultado quedó disponible para continuar.'
                : 'No se aplicaron cambios.',
            'acciones' => $tipo === self::TIPO_INFORMACION
                ? [['id' => 'CERRAR', 'tipo' => 'secondary']]
                : [['id' => 'REINTENTAR', 'tipo' => 'primary']],
            'commit' => false,
            'consumidores' => ['landing', 'administracion', 'mapa', 'pos'],
            'pruebas' => ['catalogo', 'propagacion_http'],
        ];

        if (in_array($canonical, [
            'RESERVACION_CREADA', 'ACTUALIZADA', 'COMENTARIO_ACTUALIZADO',
            'CONFIRMADA', 'COMPLETADA', 'CANCELADA', 'NO_SHOW',
            'RESERVACION_CREADA_SIN_MESA', 'RESERVACION_CONFIRMADA', 'RESERVACION_MODIFICADA',
            'RESERVACION_CANCELADA', 'REEMPLAZO_CREADO', 'REEMPLAZO_CONFIRMADO',
            'RETENCION_CREADA', 'RETENCIONES_EXPIRADAS', 'HORARIOS_ACTUALIZADOS',
            'EXCEPCION_CREADA', 'EXCEPCION_ACTUALIZADA',
            'EXCEPCION_ELIMINADA', 'EXCEPCION_ESTADO_ACTUALIZADO', 'ANUNCIO_ACTUALIZADO',
            'ASIGNACION_GUARDADA', 'CONTACTO_VERIFICADO', 'OTP_GENERADO',
            'OTP_SOLICITADO', 'GESTION_SALIDA',
        ], true)) {
            $base['commit'] = true;
        }

        $definition = array_merge($base, self::TEXTS[$canonical] ?? []);
        if ($canonical !== $codigo) {
            $definition['alias_recibido'] = $codigo;
        }
        return $definition;
    }

    /** @return array<string, mixed> */
    public static function presentar(string $codigo, array $contexto = []): array
    {
        $definition = self::definition($codigo);
        foreach (['titulo', 'mensaje', 'consecuencia'] as $campo) {
            $definition[$campo] = self::interpolar((string)$definition[$campo], $contexto);
        }
        return [
            'tipo' => $definition['tipo'],
            'http_status' => $definition['http_status'],
            'mensaje_key' => $definition['mensaje_key'],
            'titulo' => $definition['titulo'],
            'mensaje' => $definition['mensaje'],
            'consecuencia' => $definition['consecuencia'],
            'acciones' => $definition['acciones'],
            'commit' => (bool)$definition['commit'],
        ];
    }

    /** @param array<string, mixed> $resultado @param array<string, mixed> $contexto */
    public static function enriquecer(array $resultado, array $contexto = []): array
    {
        unset($resultado['msg'], $resultado['message'], $resultado['mensaje_bloqueo']);
        $codigoRecibido = trim((string)($resultado['codigo'] ?? 'ERROR_INTERNO'));
        $contextoResultado = array_intersect_key($resultado, array_flip([
            'fecha', 'hora', 'minutos', 'minutos_restantes', 'comensales',
            'max_comensales', 'mesa_ids', 'mesa_numeros', 'capacidad',
            'capacidad_solicitada', 'capacidad_disponible', 'estado',
            'capacidad_fisica_total', 'capacidad_fisica_comprometida',
            'capacidad_fisica_libre', 'demanda_no_asignada',
            'capacidad_real_disponible', 'capacidad_resultante', 'exceso_capacidad',
            'nombre', 'hora_objetivo', 'duracion_estimada_supera',
        ]));
        $contexto = self::contextoSeguro(array_merge(
            $contextoResultado,
            (array)($resultado['contexto'] ?? []),
            $contexto
        ));
        try {
            $presentacion = self::presentar($codigoRecibido, $contexto);
            $canonical = self::canonical($codigoRecibido);
        } catch (\InvalidArgumentException $e) {
            $codigoRecibido = $codigoRecibido !== '' ? $codigoRecibido : 'ERROR_INTERNO';
            $canonical = 'ERROR_INTERNO';
            $presentacion = self::presentar($canonical, $contexto);
            $resultado['codigo_no_catalogado'] = $codigoRecibido;
        }

        $resultado['codigo_canonico'] = $canonical;
        $resultado['tipo'] = $presentacion['tipo'];
        $resultado['http_status'] = $resultado['http_status'] ?? $presentacion['http_status'];
        $resultado['mensaje_key'] = $presentacion['mensaje_key'];
        $resultado['mensaje'] = $presentacion['mensaje'];
        $resultado['consecuencia'] = $presentacion['consecuencia'];
        $resultado['acciones'] = $presentacion['acciones'];
        $resultado['commit'] = array_key_exists('commit', $resultado)
            ? (bool)$resultado['commit']
            : $presentacion['commit'];

        if ($contexto !== []) {
            $resultado['contexto'] = $contexto;
        }

        if (array_key_exists('field_codes', $resultado)) {
            $resultado['errors'] = self::fieldErrors((array)$resultado['field_codes'], $contexto);
            if (array_key_exists('errores', $resultado)) {
                $resultado['errores'] = $resultado['errors'];
            }
        }

        foreach (['advertencia', 'bloqueo'] as $campo) {
            if (is_array($resultado[$campo] ?? null) && isset($resultado[$campo]['codigo'])) {
                $resultado[$campo] = self::enriquecerAnidado($resultado[$campo]);
            }
        }
        foreach (['advertencias', 'bloqueos'] as $campo) {
            if (!is_array($resultado[$campo] ?? null)) {
                continue;
            }
            $resultado[$campo] = array_map(
                static fn($item) => is_array($item) && isset($item['codigo'])
                    ? self::enriquecerAnidado($item)
                    : $item,
                $resultado[$campo]
            );
        }
        foreach (['warnings', 'advertencias', 'confirmaciones_requeridas'] as $campo) {
            if (!is_array($resultado[$campo] ?? null)) {
                continue;
            }
            $resultado[$campo . '_presentaciones'] = [];
            foreach ($resultado[$campo] as $codigoAnidado) {
                if (!is_string($codigoAnidado) || !self::has($codigoAnidado)) {
                    continue;
                }
                $resultado[$campo . '_presentaciones'][$codigoAnidado] = self::presentar($codigoAnidado, $contexto);
            }
        }

        return $resultado;
    }

    public static function httpStatus(string $codigo, int $fallback = 422): int
    {
        try {
            return (int)self::definition($codigo)['http_status'];
        } catch (\InvalidArgumentException $e) {
            return $fallback;
        }
    }

    private static function interpolar(string $texto, array $contexto): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', static function (array $match) use ($contexto): string {
            $valor = $contexto[$match[1]] ?? '';
            return is_scalar($valor) ? (string)$valor : '';
        }, $texto) ?? $texto;
    }

    /** @return array<string, array<int, string>> */
    private static function fieldErrors(array $fieldCodes, array $contexto): array
    {
        $errores = [];
        foreach ($fieldCodes as $field => $codes) {
            foreach ((array)$codes as $code) {
                $code = (string)$code;
                $texto = self::FIELD_TEXTS[$code] ?? self::FIELD_TEXTS['DATOS_RESERVACION_INVALIDOS'];
                $errores[(string)$field][] = self::interpolar($texto, $contexto);
            }
        }
        return $errores;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private static function enriquecerAnidado(array $item): array
    {
        $codigo = (string)$item['codigo'];
        $contexto = self::contextoSeguro((array)($item['contexto'] ?? []));
        $item['codigo_canonico'] = self::canonical($codigo);
        $item['presentacion'] = self::presentar($codigo, $contexto);
        if ($contexto !== []) {
            $item['contexto'] = $contexto;
        }
        return $item;
    }

    /** @return array<string, scalar> */
    private static function contextoSeguro(array $contexto): array
    {
        $permitidos = [
            'fecha', 'hora', 'minutos', 'minutos_restantes', 'comensales',
            'max_comensales', 'mesa_ids', 'mesa_numeros', 'capacidad',
            'capacidad_solicitada', 'capacidad_disponible', 'estado',
            'capacidad_fisica_total', 'capacidad_fisica_comprometida',
            'capacidad_fisica_libre', 'demanda_no_asignada',
            'capacidad_real_disponible', 'capacidad_resultante', 'exceso_capacidad',
            'nombre', 'hora_objetivo', 'duracion_estimada_supera',
        ];
        $seguro = [];
        foreach ($permitidos as $clave) {
            if (array_key_exists($clave, $contexto) && is_scalar($contexto[$clave])) {
                $seguro[$clave] = $contexto[$clave];
            }
        }
        return $seguro;
    }
}
