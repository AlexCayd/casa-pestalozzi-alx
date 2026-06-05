<?php
namespace Model;

class DiaReservacion extends ActiveRecord {
    protected static $tabla = 'dias_reservacion';
    protected static $columnasDB = ['id', 'dia_semana', 'nombre', 'hora_apertura', 'hora_cierre', 'activo'];

    public $id;
    public $dia_semana;
    public $nombre;
    public $hora_apertura;
    public $hora_cierre;
    public $activo = 1;
}
