<?php
namespace Model;

class HorarioReservacion extends ActiveRecord {
    protected static $tabla = 'horarios_reservacion';
    protected static $columnasDB = ['id', 'dia_id', 'hora'];

    public $id;
    public $dia_id;
    public $hora;
}
