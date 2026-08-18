<?php

namespace Model;

/** Resultado de seguimiento de una reservación dentro de un impacto. */
class HorarioImpactoReservacion extends ActiveRecord
{
    protected static $tabla = 'horario_impacto_reservaciones';
    protected static $columnasDB = [
        'id', 'impacto_id', 'reservacion_id', 'estado', 'notification_prepared_at',
        'access_token_hash', 'access_expires_at', 'access_invalidated_at', 'resolved_by',
        'resolved_at', 'created_at', 'updated_at',
    ];

    public $id = null;
    public $impacto_id = null;
    public $reservacion_id = null;
    public $estado = 'pendiente_notificacion';
    public $notification_prepared_at = null;
    public $access_token_hash = null;
    public $access_expires_at = null;
    public $access_invalidated_at = null;
    public $resolved_by = null;
    public $resolved_at = null;
    public $created_at = null;
    public $updated_at = null;
}
