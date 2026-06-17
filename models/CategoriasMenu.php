<?php
namespace Model;

class CategoriasMenu extends ActiveRecord {

    protected static $tabla = 'categorias';
    protected static $columnasDB = ['id', 'nombre', 'img', 'activo'];

    public $id;
    public $nombre;
    public $img;
    public $activo = 1;


}