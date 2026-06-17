<?php
namespace Model;

class Menu extends ActiveRecord {
    protected static $tabla = 'menu';
    protected static $columnasDB = ['id', 'nombre', 'descripcion', 'precio', 'tag', 'activo', 'categoria_id'];

    public $id;
    public $nombre;
    public $descripcion;
    public $precio;
    public $tag;
    public $activo = 1;
    public $categoria_id;
}