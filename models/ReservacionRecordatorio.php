<?php

namespace Model;

/** Registro sin PII de un recordatorio operativo preparado. */
final class ReservacionRecordatorio extends ActiveRecord
{
    protected static $tabla = 'reservacion_recordatorios';
    protected static $columnasDB = [
        'id', 'reservacion_id', 'reservacion_raiz_id', 'tipo', 'dedup_key',
        'access_token_hash', 'access_expires_at', 'access_invalidated_at',
        'notification_delivery_status', 'notification_delivery_updated_at',
        'created_at', 'updated_at',
    ];

    public $id = null;
    public $reservacion_id = null;
    public $reservacion_raiz_id = null;
    public $tipo = 'dia_anterior';
    public $dedup_key = '';
    public $access_token_hash = null;
    public $access_expires_at = null;
    public $access_invalidated_at = null;
    public $notification_delivery_status = 'pending';
    public $notification_delivery_updated_at = null;
    public $created_at = null;
    public $updated_at = null;
}
