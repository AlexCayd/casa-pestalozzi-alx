<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Solo CLI.\n"); exit(1); }
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
$args = getopt('', ['host:', 'base:', 'entorno:']);
foreach (['host' => 'DB_HOST', 'base' => 'DB_NAME', 'entorno' => 'APP_ENV'] as $arg => $env) {
    if (($args[$arg] ?? null) !== ($_ENV[$env] ?? null)) {
        fwrite(STDERR, "Confirma --host, --base y --entorno con los valores de includes/.env.\n"); exit(2);
    }
}
if (!in_array($args['entorno'] ?? null, ['development', 'test'], true)) {
    fwrite(STDERR, "El preflight solo se conecta a development/test.\n"); exit(2);
}

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME'], (int) ($_ENV['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');
$legacy = "SELECT o.id AS oferta_id,
 CASE WHEN pn.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.nombre'))='true' THEN JSON_UNQUOTE(pn.valor) ELSE p.nombre END AS nombre,
 CASE WHEN pd.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.descripcion'))='true' THEN JSON_UNQUOTE(pd.valor) ELSE p.descripcion END AS descripcion,
 CASE WHEN pi.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.img'))='true' THEN CASE WHEN JSON_TYPE(pi.valor)='NULL' THEN NULL ELSE JSON_UNQUOTE(pi.valor) END ELSE p.img END AS img
 FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id
 LEFT JOIN producto_estandares pe ON pe.producto_id=p.id
 LEFT JOIN producto_publicaciones pp ON pp.id=pe.publicacion_id
 LEFT JOIN oferta_personalizaciones pn ON pn.oferta_id=o.id AND pn.campo='nombre'
 LEFT JOIN oferta_personalizaciones pd ON pd.oferta_id=o.id AND pd.campo='descripcion'
 LEFT JOIN oferta_personalizaciones pi ON pi.oferta_id=o.id AND pi.campo='img'";

try {
    $exists = $db->query("SHOW TABLES LIKE 'oferta_datos_comerciales'")->num_rows === 1;
    if (!$exists) throw new RuntimeException('Falta A1: oferta_datos_comerciales no existe.');
    $active = (bool) $db->query("SELECT 1 FROM esquema_versiones WHERE version='a3-datos-comerciales-local-v1' LIMIT 1")->fetch_row();
    $missing = array_map('intval', array_column($db->query(
        'SELECT o.id FROM ofertas_producto o LEFT JOIN oferta_datos_comerciales d ON d.oferta_id=o.id WHERE d.oferta_id IS NULL ORDER BY o.id'
    )->fetch_all(MYSQLI_ASSOC), 'id'));
    $sql = "SELECT legacy.oferta_id FROM ({$legacy}) legacy
      LEFT JOIN oferta_datos_comerciales d ON d.oferta_id=legacy.oferta_id
      WHERE d.oferta_id IS NULL
       OR NOT (CAST(d.nombre AS BINARY) <=> CAST(legacy.nombre AS BINARY))
       OR NOT (CAST(d.descripcion AS BINARY) <=> CAST(legacy.descripcion AS BINARY))
       OR NOT (CAST(d.img AS BINARY) <=> CAST(legacy.img AS BINARY))
      ORDER BY legacy.oferta_id";
    $different = array_map('intval', array_column($db->query($sql)->fetch_all(MYSQLI_ASSOC), 'oferta_id'));
    $orphaned = array_map('intval', array_column($db->query(
        'SELECT d.oferta_id FROM oferta_datos_comerciales d LEFT JOIN ofertas_producto o ON o.id=d.oferta_id WHERE o.id IS NULL ORDER BY d.oferta_id'
    )->fetch_all(MYSQLI_ASSOC), 'oferta_id'));
    echo 'A3 activo: ' . ($active ? 'sí' : 'no') . "\n";
    echo 'Ofertas sin datos comerciales: ' . count($missing) . ($missing ? ' [' . implode(',', $missing) . ']' : '') . "\n";
    echo 'Diferencias contra proyección M1.2: ' . count($different) . ($different ? ' [' . implode(',', $different) . ']' : '') . "\n";
    echo 'Filas huérfanas: ' . count($orphaned) . ($orphaned ? ' [' . implode(',', $orphaned) . ']' : '') . "\n";
    if ($active) echo "Nota: tras activar A3, las diferencias frente a la proyección M1.2 son informativas; productos_oferta ya lee datos locales.\n";
    elseif ($missing || $different || $orphaned) echo "Revisa y reconcilia los IDs reportados antes de ejecutar a3-datos-comerciales-local.sql.\n";
    else echo "Integridad lista para el corte A3.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Preflight A3: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $db->close();
}
