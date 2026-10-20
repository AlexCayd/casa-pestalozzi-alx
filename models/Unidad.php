<?php

namespace Model;

/**
 * Unidades de negocio (Restaurante, Panadería).
 *
 * Hoy sólo se asignan a las cuentas de administrador desde Usuarios y no
 * restringen nada: son la base de la futura integración multiempresa, donde
 * un admin verá sólo los módulos y datos de su unidad.
 */
class Unidad extends ActiveRecord
{
    protected static $tabla = 'unidades';
    protected static $columnasDB = ['id', 'nombre', 'slug'];

    public $id;
    public $nombre;
    public $slug;

    public static function todas(): array
    {
        return self::consultarSQL('SELECT * FROM ' . static::$tabla . ' ORDER BY id ASC');
    }
}
