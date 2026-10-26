<?php

/**
 * Representa las mesas del restaurante y sus consultas operativas.
 * Distingue mesas existentes, activas y reservables.
 */

namespace Model;

class Mesa extends RegistroRestaurante {
    protected static $tabla = 'mesas';
    protected static $columnasDB = ['id', 'numero', 'nombre', 'tipo', 'zona', 'capacidad', 'pos_x', 'pos_y', 'activo', 'reservable'];

    public $id;
    public $numero;
    public $nombre;
    public $tipo = 'mesa';
    /**
     * 'interior' | 'exterior' | null. Decide la asignación automática: el
     * interior primero y el exterior completo (Mesas 8–11) como bloque para
     * los grupos grandes. Barras, Caja y Llevar no tienen zona.
     */
    public $zona = null;
    public $capacidad = 4;
    public $pos_x = 0;
    public $pos_y = 0;
    public $activo = 1;
    public $reservable = 1;

    public static function reservables(): array
    {
        return self::consultarSQL(
            "SELECT id, numero, nombre, tipo, zona, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             WHERE activo = 1
               AND reservable = 1
               AND tipo = 'mesa'
               AND capacidad > 0" . self::filtroUnidad() . "
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
            "SELECT id, numero, nombre, tipo, zona, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             WHERE id IN (" . implode(',', $mesaIds) . ")
               AND activo = 1
               AND reservable = 1
               AND tipo = 'mesa'
               AND capacidad > 0" . self::filtroUnidad() . "
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
            "SELECT id, numero, nombre, tipo, zona, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             WHERE activo = 1
               AND reservable = 1
               AND tipo = 'mesa'
               AND capacidad > 0" . self::filtroUnidad() . "
               {$where}
             ORDER BY id ASC
             FOR UPDATE"
        );
    }

    /** Bloquea relaciones actuales aunque una mesa ya no sea reservable. */
    public static function bloquearPorIds(array $mesaIds): array
    {
        $mesaIds = self::normalizarIds($mesaIds);
        if ($mesaIds === []) {
            return [];
        }

        return self::consultarSQL(
            "SELECT id FROM mesas
             WHERE id IN (" . implode(',', $mesaIds) . ")" . self::filtroUnidad() . "
             ORDER BY id ASC
             FOR UPDATE"
        );
    }

    public static function buscarTodasParaMapa(): array
    {
        return self::consultarSQL(
            "SELECT id, numero, nombre, tipo, zona, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas WHERE 1=1" . self::filtroUnidad() . "
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
               AND capacidad > 0" . self::filtroUnidad()
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
