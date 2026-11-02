<?php

/**
 * Lleva una base existente al modelo de subrecetas POR LOTE (ver database/ddl.sql,
 * tablas subrecetas, producciones_subreceta y movimientos_inventario).
 *
 * Es idempotente: cada paso mira el esquema antes de tocarlo, así que correrlo
 * dos veces no hace nada la segunda. Una base creada desde el ddl.sql vigente
 * ya está al día.
 *
 * Las subrecetas que ya existían quedan AL MOMENTO (por_lote = 0): siguen
 * descontando sus ingredientes al vender, como hasta ahora, y nada cambia hasta
 * que alguien marque una como «por lote» y registre su primera producción. Las
 * que se den de alta después nacen por lote.
 *
 *   php scripts/migrar-subrecetas-por-lote.php
 */
if (PHP_SAPI !== 'cli') {
    exit("Este script sólo puede ejecutarse desde CLI.\n");
}

require __DIR__ . '/../includes/app.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$base = (string) ($db->query('SELECT DATABASE()')->fetch_row()[0] ?? '');
if ($base === '') {
    fwrite(STDERR, "No hay base de datos seleccionada (DB_NAME).\n");
    exit(1);
}
$baseSql = $db->real_escape_string($base);

$tieneColumna = static function (string $tabla, string $columna) use ($db, $baseSql): bool {
    $res = $db->query(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = '{$baseSql}' AND TABLE_NAME = '{$tabla}' AND COLUMN_NAME = '{$columna}' LIMIT 1"
    );
    return (bool) $res->fetch_row();
};

echo "Migración de subrecetas por lote sobre «{$base}»:\n";

try {
    // 1 · Subrecetas: clase, existencia y mínimo.
    if (!$tieneColumna('subrecetas', 'por_lote')) {
        $db->query(
            "ALTER TABLE subrecetas
               ADD COLUMN por_lote     TINYINT(1)    NOT NULL DEFAULT 1 AFTER rendimiento,
               ADD COLUMN stock        DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER por_lote,
               ADD COLUMN stock_minimo DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER stock"
        );
        // Sólo justo después de crear la columna: las de antes, al momento.
        $db->query('UPDATE subrecetas SET por_lote = 0');
        echo "- subrecetas: por_lote, stock y stock_minimo añadidos; las {$db->affected_rows} existentes quedan «al momento».\n";
    } else {
        echo "- subrecetas: ya estaba al día.\n";
    }

    // 2 · Lotes producidos.
    $db->query(
        "CREATE TABLE IF NOT EXISTS producciones_subreceta (
           id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
           subreceta_id INT UNSIGNED NOT NULL,
           cantidad     DECIMAL(12,3) NOT NULL,
           costo_total  DECIMAL(12,4) NOT NULL DEFAULT 0,
           nota         VARCHAR(255) NULL,
           usuario_id   INT NULL,
           created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
           FOREIGN KEY (subreceta_id) REFERENCES subrecetas(id) ON DELETE CASCADE,
           CONSTRAINT fk_ps_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
           INDEX idx_ps_sub_fecha (subreceta_id, created_at)
         )"
    );
    echo "- producciones_subreceta: lista.\n";

    // 3 · Bitácora: movimientos de subreceta y de producción.
    if (!$tieneColumna('movimientos_inventario', 'subreceta_id')) {
        $db->query(
            "ALTER TABLE movimientos_inventario
               MODIFY COLUMN ingrediente_id INT UNSIGNED NULL,
               MODIFY COLUMN tipo ENUM('venta','cancelacion','ajuste','entrada','merma','produccion') NOT NULL,
               ADD COLUMN subreceta_id  INT UNSIGNED NULL AFTER ingrediente_id,
               ADD COLUMN produccion_id INT UNSIGNED NULL AFTER ticket_item_id,
               ADD INDEX idx_mi_sub (subreceta_id),
               ADD INDEX idx_mi_prod (produccion_id),
               ADD CONSTRAINT fk_mi_subreceta FOREIGN KEY (subreceta_id) REFERENCES subrecetas(id) ON DELETE CASCADE,
               ADD CONSTRAINT fk_mi_produccion FOREIGN KEY (produccion_id) REFERENCES producciones_subreceta(id) ON DELETE SET NULL"
        );
        echo "- movimientos_inventario: subreceta_id, produccion_id y el tipo «produccion» añadidos.\n";
    } else {
        echo "- movimientos_inventario: ya estaba al día.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'La migración se detuvo: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Listo. Marca como «por lote» las subrecetas que se producen por adelantado (Recetas → Subrecetas).\n";
