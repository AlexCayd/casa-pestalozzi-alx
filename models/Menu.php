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

    // Funcion para validar el menu
    public function validar() {
        static::$alertas = [];

        if (!$this->nombre) {
            static::setAlerta('error', 'El nombre del platillo es obligatorio');
        }
        if (!$this->descripcion) {
            static::setAlerta('error', 'La descripción es obligatoria');
        }
        if ($this->precio === '' || $this->precio === null) {
            static::setAlerta('error', 'El precio es obligatorio');
        } elseif (!is_numeric($this->precio) || (float)$this->precio < 0) {
            static::setAlerta('error', 'El precio debe ser un número válido');
        }
        if (!$this->categoria_id) {
            static::setAlerta('error', 'La categoría es obligatoria');
        }

        return static::$alertas;
    }
}