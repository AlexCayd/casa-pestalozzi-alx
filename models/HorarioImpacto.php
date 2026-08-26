<?php

namespace Model;

/** Lote persistente originado por un cambio de horario. */
class HorarioImpacto extends ActiveRecord
{
    protected static $tabla = 'horario_impactos';
    protected static $columnasDB = [
        'id', 'tipo_origen', 'origen_id', 'estado', 'dedup_key',
        'created_by', 'created_at', 'resolved_at',
    ];

    public $id = null;
    public $tipo_origen = '';
    public $origen_id = null;
    public $estado = 'pendiente';
    public $dedup_key = '';
    public $created_by = null;
    public $created_at = null;
    public $resolved_at = null;

    public function __construct(array $args = [])
    {
        foreach ($args as $propiedad => $valor) {
            if (property_exists($this, $propiedad)) {
                $this->$propiedad = $valor;
            }
        }
    }
}
