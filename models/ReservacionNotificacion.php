<?php

namespace Model;

/** Intención durable de notificación; un dispatcher futuro consume esta cola. */
class ReservacionNotificacion extends ActiveRecord
{
    protected static $tabla = 'reservacion_notificaciones';
    protected static $columnasDB = [
        'id', 'impacto_reservacion_id', 'reservacion_id', 'evento', 'estado',
        'dedup_key', 'intentos', 'available_at', 'sent_at', 'failed_at',
        'last_error', 'created_at', 'updated_at',
    ];

    public $id = null;
    public $impacto_reservacion_id = null;
    public $reservacion_id = null;
    public $evento = 'reservation.schedule_change';
    public $estado = 'pendiente';
    public $dedup_key = '';
    public $intentos = 0;
    public $available_at = null;
    public $sent_at = null;
    public $failed_at = null;
    public $last_error = null;
    public $created_at = null;
    public $updated_at = null;
}
