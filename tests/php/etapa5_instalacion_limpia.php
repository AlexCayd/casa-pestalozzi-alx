<?php

declare(strict_types=1);

/** Ensayo destructible sólo sobre una base temporal generada por esta suite. */
$_ENV['APP_ENV'] = 'testing';
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('APP_ENV=testing');
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\DisponibilidadReservacionService;
use Services\HorarioReservacionService;
use Services\ReservacionConfig;

$db = ActiveRecord::getDB();
$suffix = date('YmdHis') . '_' . bin2hex(random_bytes(3));
$database = 'casa_pestalozzi_etapa5_clean_' . $suffix;
if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1 || $database === 'casa_pestalozzi') {
    throw new RuntimeException('Nombre de base temporal inválido.');
}

/** Ejecuta scripts con bloques DELIMITER usados por los triggers del DDL. */
$runScript = static function (mysqli $connection, string $path): void {
    $lines = preg_split('/\R/', (string)file_get_contents($path)) ?: [];
    $delimiter = ';';
    $buffer = '';
    $flush = static function (string $sql) use ($connection): void {
        if (trim($sql) === '') {
            return;
        }
        if (!$connection->multi_query($sql)) {
            throw new RuntimeException($connection->error . ' — script');
        }
        do {
            if ($resultado = $connection->store_result()) {
                $resultado->free();
            }
        } while ($connection->more_results() && $connection->next_result());
        if ($connection->errno) {
            throw new RuntimeException($connection->error . ' — script');
        }
    };

    foreach ($lines as $linea) {
        if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $linea, $matches) === 1) {
            $flush($buffer);
            $buffer = '';
            $delimiter = trim($matches[1]);
            continue;
        }
        $buffer .= $linea . "\n";
        if ($delimiter !== ';' && str_ends_with(rtrim($buffer), $delimiter)) {
            $statement = substr(rtrim($buffer), 0, -strlen($delimiter));
            $flush($statement);
            $buffer = '';
        }
    }
    $flush($buffer);
};

$result = [
    'ok' => false,
    'database' => $database,
    'ddl' => false,
    'dml' => false,
    'counts' => [],
    'service_smoke' => false,
    'dropped' => false,
];

try {
    if (!$db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        throw new RuntimeException($db->error);
    }
    if (!$db->select_db($database)) {
        throw new RuntimeException($db->error);
    }
    mysqli_query($db, "SET time_zone = '-06:00'");
    ActiveRecord::setDB($db);

    $runScript($db, dirname(__DIR__, 2) . '/database/ddl.sql');
    $result['ddl'] = true;
    $runScript($db, dirname(__DIR__, 2) . '/database/dml.sql');
    $result['dml'] = true;

    foreach (['mesas', 'horarios_operacion', 'reservaciones', 'tickets', 'ticket_mesas'] as $table) {
        $row = $db->query("SELECT COUNT(*) AS total FROM `{$table}`")->fetch_assoc();
        $result['counts'][$table] = (int)$row['total'];
    }

    $now = new DateTimeImmutable('2026-11-01 12:00:00', ReservacionConfig::timezone());
    $schedule = HorarioReservacionService::resolverFecha('2026-11-02', $now);
    $availability = DisponibilidadReservacionService::consultarUna('2026-11-02', '14:00:00', 6, 0, $now);
    $result['service_smoke'] = $schedule['reservable'] === true
        && $availability['disponible'] === true;
    $result['ok'] = $result['ddl'] && $result['dml'] && $result['service_smoke'];
} finally {
    if (!$db->query("DROP DATABASE IF EXISTS `{$database}`")) {
        throw new RuntimeException('No se pudo eliminar la base temporal: ' . $db->error);
    }
    $result['dropped'] = true;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] && $result['dropped'] ? 0 : 1);
