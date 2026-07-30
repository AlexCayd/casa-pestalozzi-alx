<?php

/**
 * Representa las mesas del restaurante y sus consultas operativas.
 * Distingue mesas existentes, activas y reservables.
 */

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

    public static function reservables(): array
    {
        return self::consultarSQL(
            "SELECT id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             WHERE activo = 1
               AND reservable = 1
               AND tipo = 'mesa'
               AND capacidad > 0
             ORDER BY numero ASC, id ASC"
        );
    }

    public static function reservablesPorIds(array $mesaIds): array
    {
        $mesaIds = self::normalizarIds($mesaIds);

        if (empty($mesaIds)) {
            return [];
        }

        return self::consultarSQL(
            "SELECT id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             WHERE id IN (" . implode(',', $mesaIds) . ")
               AND activo = 1
               AND reservable = 1
               AND tipo = 'mesa'
               AND capacidad > 0
             ORDER BY FIELD(id, " . implode(',', $mesaIds) . ")"
        );
    }

    public static function reservablesParaActualizar(array $mesaIds = []): array
    {
        $mesaIds = self::normalizarIds($mesaIds);
        $where = '';

        if (!empty($mesaIds)) {
            $idsBloqueo = $mesaIds;
            sort($idsBloqueo, SORT_NUMERIC);
            $where = "AND id IN (" . implode(',', $idsBloqueo) . ")";
        }

        return self::consultarSQL(
            "SELECT id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             WHERE activo = 1
               AND reservable = 1
               AND tipo = 'mesa'
               AND capacidad > 0
               {$where}
             ORDER BY id ASC
             FOR UPDATE"
        );
    }

    public static function buscarTodasParaMapa(): array
    {
        return self::consultarSQL(
            "SELECT id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             ORDER BY numero ASC, id ASC"
        );
    }

    public static function capacidadReservableTotal(): int
    {
        $resultado = self::$db->query(
            "SELECT COALESCE(SUM(capacidad), 0) AS capacidad_total
             FROM mesas
             WHERE activo = 1
               AND reservable = 1
               AND tipo = 'mesa'
               AND capacidad > 0"
        );

        if ($resultado === false) {
            throw new \RuntimeException(self::$db->error);
        }

        $fila = $resultado->fetch_assoc() ?: ['capacidad_total' => 0];
        $resultado->free();

        return (int)$fila['capacidad_total'];
    }

    private static function normalizarIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
