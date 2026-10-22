<?php

namespace Model;

/**
 * Unidades de negocio (Restaurante, Panadería).
 *
 * Se asignan a administradores desde Usuarios. Auth y Contextos aplican la
 * frontera de acceso: Restaurante operativo o Panadería en demostración.
 * El esquema de datos operativos por unidad pertenece a una etapa posterior.
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
