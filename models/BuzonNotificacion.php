<?php

namespace Model;

/** Registro visual de una acción pendiente, sin datos personales. */
class BuzonNotificacion extends ActiveRecord
{
    protected static $tabla = 'buzon_notificaciones';
    protected static $columnasDB = [
        'id', 'tipo', 'modulo', 'entidad_tipo', 'entidad_id', 'prioridad',
        'visible_from', 'leida_at', 'cerrada_at', 'cerrada_por', 'cierre_motivo',
        'dedup_key', 'created_at', 'updated_at',
    ];

    public $id = null;
    public $tipo = '';
    public $modulo = '';
    public $entidad_tipo = '';
    public $entidad_id = 0;
    public $prioridad = 'normal';
    public $visible_from = null;
    public $leida_at = null;
    public $cerrada_at = null;
    public $cerrada_por = null;
    public $cierre_motivo = null;
    public $dedup_key = '';
    public $created_at = null;
    public $updated_at = null;
}
