<?php

namespace Model;

/** Enlace público de un solo uso; sólo almacena el hash del secreto. */
class ReservacionMagicLink extends ActiveRecord
{
    protected static $tabla = 'reservacion_magic_links';
    protected static $columnasDB = [
        'id', 'public_id', 'reservacion_id', 'impacto_reservacion_id', 'purpose',
        'token_hash', 'expires_at', 'used_at', 'invalidated_at', 'created_at',
    ];

    public $id = null;
    public $public_id = '';
    public $reservacion_id = null;
    public $impacto_reservacion_id = null;
    public $purpose = 'schedule_change';
    public $token_hash = '';
    public $expires_at = null;
    public $used_at = null;
    public $invalidated_at = null;
    public $created_at = null;
}
