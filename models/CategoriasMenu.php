<?php
namespace Model;

class CategoriasMenu extends ActiveRecord {

    protected static $tabla = 'categorias';
    protected static $columnasDB = ['id', 'nombre', 'img', 'activo'];

    public $id;
    public $nombre;
    public $img;
    public $activo = 1;

    // Funcion para validaciones de categorias
    public function validar() {
        static::$alertas = [];

        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre de la categoría es obligatorio');
        }

        return static::$alertas;
    }
}