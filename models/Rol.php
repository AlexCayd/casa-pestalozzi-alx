<?php

namespace Model;

use Services\Security\Permisos;

/**
 * Roles del personal y sus permisos por módulo (tablas roles y rol_permisos).
 *
 * Sólo lee y escribe: qué es válido, quién puede tocar qué y cómo se decide
 * el acceso vive en Services\Security\Permisos y RolService.
 */
class Rol extends ActiveRecord
{
    public const TIPO_SISTEMA = 'sistema';
    public const TIPO_PREDEFINIDO = 'predefinido';
    public const TIPO_PERSONALIZADO = 'personalizado';

    protected static $tabla = 'roles';
    protected static $columnasDB = ['id', 'slug', 'nombre', 'descripcion', 'tipo', 'created_at', 'updated_at'];

    public $id;
    public $slug;
    public $nombre;
    public $descripcion;
    public $tipo = self::TIPO_PERSONALIZADO;
    public $created_at;
    public $updated_at;

    /**
     * Respaldo de los roles predefinidos cuando la base no responde (y en las
     * pruebas que pintan una vista sin base): son los permisos con los que
     * nacen en deploy.sql. Los de sistema no lo necesitan, se calculan.
     */
    public const PERMISOS_POR_DEFECTO = [
        Permisos::MESERO => ['pdv' => Permisos::EDITAR],
        Permisos::COCINERO => ['area' => Permisos::EDITAR],
    ];

    /** Nombres de respaldo para los mismos casos. */
    public const NOMBRES_POR_DEFECTO = [
        Permisos::SUPER_ADMIN => 'Super admin',
        Permisos::ADMIN => 'Administrador',
        Permisos::MESERO => 'Mesero',
        Permisos::COCINERO => 'Cocinero',
    ];

    /** Sistema primero, luego los predefinidos y al final los creados. */
    public static function todos(): array
    {
        return self::consultarSQL(
            "SELECT * FROM " . static::$tabla .
            " ORDER BY FIELD(tipo, 'sistema', 'predefinido', 'personalizado'), id ASC"
        );
    }

    public static function porSlug(string $slug): ?self
    {
        $stmt = self::getDB()->prepare('SELECT * FROM ' . static::$tabla . ' WHERE slug = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $slug);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila ? static::crearObjeto($fila) : null;
    }

    public function esSistema(): bool
    {
        return $this->tipo === self::TIPO_SISTEMA;
    }

    /**
     * Los permisos guardados de un rol. Los de sistema no tienen renglones:
     * lo tienen todo.
     *
     * @return array<string, string>
     */
    public function permisos(): array
    {
        if ($this->esSistema()) {
            return Permisos::todos();
        }

        $stmt = self::getDB()->prepare('SELECT modulo, nivel FROM rol_permisos WHERE rol_id = ?');
        if (!$stmt) {
            return [];
        }
        $id = (int) $this->id;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $permisos = [];
        while ($fila = $res->fetch_assoc()) {
            $permisos[(string) $fila['modulo']] = (string) $fila['nivel'];
        }
        $stmt->close();

        return Permisos::normalizar($permisos);
    }

    /**
     * Todos los roles con sus permisos, en una consulta: lo que necesitan el
     * listado, el formulario de usuarios y los logins.
     *
     * @return array<string, array{rol: Rol, permisos: array<string, string>, acceso: string}>
     */
    public static function catalogo(): array
    {
        $roles = [];
        foreach (self::todos() as $rol) {
            $roles[(string) $rol->id] = $rol;
        }

        $permisos = array_fill_keys(array_keys($roles), []);
        $res = self::getDB()->query('SELECT rol_id, modulo, nivel FROM rol_permisos');
        if ($res) {
            while ($fila = $res->fetch_assoc()) {
                $permisos[(string) $fila['rol_id']][(string) $fila['modulo']] = (string) $fila['nivel'];
            }
            $res->free();
        }

        $catalogo = [];
        foreach ($roles as $id => $rol) {
            $suyos = $rol->esSistema() ? Permisos::todos() : Permisos::normalizar($permisos[$id] ?? []);
            $catalogo[(string) $rol->slug] = [
                'rol' => $rol,
                'permisos' => $suyos,
                'acceso' => Permisos::acceso($rol->esSistema(), $suyos),
            ];
        }

        return $catalogo;
    }

    /**
     * Slugs de los roles que entran por una vía (Permisos::ACCESO_*). Es lo
     * que filtran los dos logins: una cuenta cuyo rol entra con contraseña no
     * entra por NIP aunque conserve uno viejo, y al revés.
     *
     * @return list<string>
     */
    public static function slugsConAcceso(string $acceso): array
    {
        $slugs = [];
        foreach (self::catalogo() as $slug => $info) {
            if ($info['acceso'] === $acceso) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /** @return array<string, int> slug => usuarios que lo tienen */
    public static function usuariosPorRol(): array
    {
        $conteo = [];
        $res = self::getDB()->query('SELECT rol, COUNT(*) AS n FROM usuarios GROUP BY rol');
        if ($res) {
            while ($fila = $res->fetch_assoc()) {
                $conteo[(string) $fila['rol']] = (int) $fila['n'];
            }
            $res->free();
        }

        return $conteo;
    }
}
