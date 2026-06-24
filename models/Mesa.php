<?php
namespace Model;

class Mesa extends ActiveRecord {
    protected static $tabla = 'mesas';
    protected static $columnasDB = ['id', 'numero', 'nombre', 'tipo', 'capacidad', 'pos_x', 'pos_y', 'activo', 'reservable'];

    public $id;
    public $numero;
    public $nombre;
    public $tipo = 'mesa';
    public $capacidad = 4;
    public $pos_x = 0;
    public $pos_y = 0;
    public $activo = 1;
    public $reservable = 1;
}
