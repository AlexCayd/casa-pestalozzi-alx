<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Model\ActiveRecord;
use Services\AsignacionMesasService;
use Services\CapacidadReservacionesService;
use Services\OcupacionMesasService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionConfig;

$fallos = [];
$afirmar = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    if (!$condicion) {
        $fallos[] = $mensaje;
    }
};

$mesa = static function (int $id, int $capacidad = 4, string $tipo = 'mesa', int $activo = 1, int $reservable = 1): array {
    return [
        'id' => $id,
        'numero' => $id,
        'nombre' => 'Mesa ' . $id,
        'tipo' => $tipo,
        'capacidad' => $capacidad,
        'activo' => $activo,
        'reservable' => $reservable,
    ];
};
$mesas = array_map(static fn(int $id): array => $mesa($id), range(1, 11));
$mesas[] = $mesa(12, 20, 'barra');
$mesas[] = $mesa(13, 20, 'mesa', 0, 1);

$evaluar = static function (array $disponibles, array $demanda = []) use ($mesas): array {
    return CapacidadReservacionesService::calcular(
        $mesas,
        [
            'fecha' => '2026-08-20',
            'hora' => '15:00:00',
            'intervalo' => [
                'inicio' => '2026-08-20 15:00:00',
                'fin' => '2026-08-20 16:30:00',
            ],
            'mesa_ids_disponibles' => $disponibles,
            'mesa_ids_proyectadas' => [],
        ],
        $demanda
    );
};

$c1 = $evaluar(range(1, 11));
$afirmar($c1['capacidad_fisica_total'] === 44 && $c1['capacidad_fisica_comprometida'] === 0, 'C1: la capacidad total sólo suma mesas físicas reservables.');
$afirmar($c1['capacidad_fisica_libre'] === 44 && $c1['capacidad_real_disponible'] === 44, 'C1: sin ocupación quedan 44 asientos reales.');

$c2 = $evaluar([3, 4, 5, 6, 7, 8, 9, 10, 11]);
$afirmar($c2['capacidad_fisica_comprometida'] === 8 && $c2['capacidad_fisica_libre'] === 36, 'C2: una reservación de 6 en dos mesas de 4 descuenta 8.');
$afirmar($c2['capacidad_fisica_libre'] !== 38, 'C2: no se descuenta sólo el número de comensales.');

$c3 = $evaluar([4, 5, 6, 7, 8, 9, 10, 11]);
$afirmar($c3['capacidad_fisica_comprometida'] === 12 && $c3['capacidad_fisica_libre'] === 32, 'C3: dos grupos bloqueados se suman una sola vez por mesa.');

$demandaCinco = [['id' => 201, 'comensales' => 5, 'estado' => 'confirmada', 'influye_disponibilidad' => true]];
$c4 = $evaluar(range(1, 11), $demandaCinco);
$afirmar($c4['demanda_no_asignada'] === 5, 'C4: una confirmada sin mesas se cuenta como demanda pendiente.');

$c5 = $evaluar([3, 4, 5, 6, 7, 8, 9, 10, 11], $demandaCinco);
$afirmar($c5['capacidad_fisica_libre'] === 36 && $c5['capacidad_real_disponible'] === 31, 'C5: la capacidad real combina mesas bloqueadas y demanda sin asignar.');

$c6 = $evaluar(range(1, 11), []);
$afirmar($c6['demanda_no_asignada'] === 0, 'C6: una reservación fuera del intervalo no descuenta.');
$afirmar($evaluar(range(1, 11), [['id' => 202, 'comensales' => 5, 'estado' => 'cancelada']])['demanda_no_asignada'] === 0, 'C7: una cancelada no descuenta.');
$afirmar($evaluar(range(1, 11), [['id' => 203, 'comensales' => 4, 'estado' => 'confirmada', 'influye_disponibilidad' => false]])['demanda_no_asignada'] === 0, 'C8: una ausencia pendiente no descuenta capacidad.');

$c9 = $evaluar([1, 2, 4, 5, 6, 7, 8, 9, 10, 11]);
$afirmar($c9['capacidad_fisica_comprometida'] === 4 && $c9['demanda_no_asignada'] === 0, 'C9: en_curso/ticket sólo se representa por la mesa bloqueada.');

$c10 = $evaluar([1, 2, 4, 5, 6, 7, 8, 9, 10, 11], [['id' => 204, 'comensales' => 4, 'estado' => 'confirmada']]);
$afirmar($c10['capacidad_fisica_comprometida'] === 4 && $c10['demanda_no_asignada'] === 4, 'C10: la fórmula pura conserva hechos distintos; el doble conteo de ticket se evita en la consulta NOT EXISTS/open ticket.');

$c11 = $evaluar([2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
$c11['mesa_ids_proyectadas'] = [1];
$c11 = CapacidadReservacionesService::calcular($mesas, [
    'mesa_ids_disponibles' => range(1, 11),
    'mesa_ids_proyectadas' => [1],
], []);
$afirmar($c11['capacidad_fisica_libre'] === 44 && $c11['capacidad_proyectada'] === 4, 'C11: una liberación proyectada aumenta la capacidad del intervalo futuro.');
$c12 = $evaluar([2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
$afirmar($c12['capacidad_fisica_comprometida'] === 4, 'C12: el bloque actual conserva el ticket abierto.');

$c13 = $evaluar([1, 2, 3]);
$mesasDispersas = array_map(static fn(array $fila): object => (object)$fila, array_slice($mesas, 0, 3));
$autoC13 = AsignacionMesasService::seleccionarMesasPublicas($mesasDispersas, 6);
$afirmar($c13['capacidad_real_disponible'] === 12 && $autoC13 === [], 'C13: capacidad suficiente sin grupo automático queda separada de la asignación.');

if (in_array('--dynamic', $argv, true)) {
    $fallos = array_merge($fallos, ejecutarFixturesDinamicos());
}

if ($fallos !== []) {
    fwrite(STDERR, "FAIL: runner Etapa 5\n");
    foreach ($fallos as $fallo) {
        fwrite(STDERR, "- {$fallo}\n");
    }
    exit(1);
}

echo "PASS: capacidad física, demanda no asignada, proyección y políticas base de Etapa 5.\n";
echo in_array('--dynamic', $argv, true)
    ? "INFO: fixtures dinámicos ejecutados y eliminados en una base temporal protegida.\n"
    : "INFO: casos C1–C13 ejecutados en memoria; no se modificó ninguna base de datos.\n";

/** @return array<int, string> */
function ejecutarFixturesDinamicos(): array
{
    $fallos = [];
    $env = [];
    $envPath = dirname(__DIR__, 2) . '/includes/.env';
    if (is_file($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = explode('=', $linea, 2);
            $env[trim($clave)] = trim(trim($valor), "\"'");
        }
    }

    $host = $env['DB_HOST'] ?? 'localhost';
    $user = $env['DB_USER'] ?? 'root';
    $pass = $env['DB_PASS'] ?? '';
    $activa = strtolower((string)($env['DB_NAME'] ?? ''));
    $temp = 'casa_pestalozzi_tmp_etapa5_' . gmdate('Ymd_His') . '_' . random_int(100, 999);
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $temp) || $temp === $activa || $temp === 'casa-pestalozzi') {
        return ['La protección de base temporal rechazó el nombre generado.'];
    }

    $servidor = null;
    $db = null;
    try {
        $servidor = new mysqli($host, $user, $pass);
        if ($servidor->connect_errno) {
            throw new RuntimeException('No fue posible conectar a MySQL: ' . $servidor->connect_error);
        }
        $servidor->set_charset('utf8mb4');
        if (!$servidor->query("CREATE DATABASE {$temp} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new RuntimeException('No fue posible crear la base temporal: ' . $servidor->error);
        }
        $db = new mysqli($host, $user, $pass, $temp);
        if ($db->connect_errno) {
            throw new RuntimeException('No fue posible seleccionar la base temporal: ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
        $ddl = file_get_contents(dirname(__DIR__, 2) . '/database/ddl.sql');
        if (!is_string($ddl)) {
            throw new RuntimeException('No fue posible leer database/ddl.sql.');
        }
        $ddl = preg_replace('/DELIMITER \/\/.*?DELIMITER ;/s', '', $ddl) ?? $ddl;
        if (!$db->multi_query($ddl)) {
            throw new RuntimeException('Falló el DDL temporal: ' . $db->error);
        }
        while ($db->more_results()) {
            $db->next_result();
            if ($db->errno) {
                throw new RuntimeException('Falló una sentencia del DDL temporal: ' . $db->error);
            }
        }

        $db->query("INSERT INTO horarios_operacion (dia_semana, abierto, hora_apertura, hora_cierre) VALUES
            (0,1,'09:00:00','23:00:00'),(1,1,'09:00:00','23:00:00'),(2,1,'09:00:00','23:00:00'),
            (3,1,'09:00:00','23:00:00'),(4,1,'09:00:00','23:00:00'),(5,1,'09:00:00','23:00:00'),
            (6,1,'09:00:00','23:00:00')");
        $values = [];
        for ($id = 1; $id <= 11; $id++) {
            $values[] = "({$id},{$id},'Mesa {$id}','mesa',4,0,0,1,1)";
        }
        if (!$db->query('INSERT INTO mesas (id,numero,nombre,tipo,capacidad,pos_x,pos_y,activo,reservable) VALUES ' . implode(',', $values))) {
            throw new RuntimeException('Falló la carga de mesas: ' . $db->error);
        }
        $fecha = '2026-08-20';
        $db->query("INSERT INTO reservaciones (id,nombre,contacto_tipo,contacto,fecha,hora,comensales,origen,estado) VALUES
            (201,'Asignada','ninguno',NULL,'{$fecha}','15:00:00',6,'admin','confirmada'),
            (202,'Sin mesas','ninguno',NULL,'{$fecha}','15:15:00',5,'admin','confirmada'),
            (203,'Cancelada','ninguno',NULL,'{$fecha}','15:15:00',5,'admin','cancelada'),
            (204,'Fuera intervalo','ninguno',NULL,'{$fecha}','18:00:00',5,'admin','confirmada'),
            (205,'Ausencia','ninguno',NULL,'{$fecha}','12:00:00',4,'admin','confirmada'),
            (206,'Con ticket','ninguno',NULL,'{$fecha}','14:00:00',4,'admin','confirmada'),
            (207,'En curso','ninguno',NULL,'{$fecha}','14:00:00',4,'admin','en_curso')");
        $db->query("INSERT INTO reservacion_mesas (reservacion_id,mesa_id,orden) VALUES
            (201,1,1),(201,2,2),(206,4,1),(207,5,1)");
        $db->query("INSERT INTO tickets (id,comensales,nombre,hora_apertura,estado,reservacion_id) VALUES
            (301,4,'Ticket walk-in','{$fecha} 14:00:00','abierto',NULL),
            (302,4,'Ticket reservado','{$fecha} 14:00:00','abierto',206),
            (303,4,'Ticket en curso','{$fecha} 14:00:00','abierto',207)");
        $db->query("INSERT INTO ticket_mesas (ticket_id,mesa_id,orden) VALUES (301,3,1),(302,4,1),(303,5,1)");
        if ($db->errno) {
            throw new RuntimeException('Falló la carga de fixtures: ' . $db->error);
        }

        ActiveRecord::setDB($db);
        $_ENV['RESERVATION_TEST_NOW'] = $fecha . ' 14:30:00';
        $ocupacion = OcupacionMesasService::evaluarHorario($fecha, '15:30:00', 0, false, null, new DateTimeImmutable($fecha . ' 14:30:00', ReservacionConfig::timezone()));
        $resumen = OcupacionMesasService::resumenCapacidad(\Model\Mesa::reservables(), $ocupacion);
        if (($resumen['capacidad_fisica_total'] ?? 0) !== 44) {
            $fallos[] = 'Dinámica C1: capacidad física total incorrecta.';
        }
        if (($resumen['capacidad_fisica_comprometida'] ?? 0) !== 8 || ($resumen['demanda_no_asignada'] ?? 0) !== 5 || ($resumen['capacidad_real_disponible'] ?? 0) !== 31) {
            $fallos[] = 'Dinámica C5: el resumen mixto no es 44/8/5/31.';
        }
        $ocupacionActual = OcupacionMesasService::evaluarHorario($fecha, '14:30:00', 0, false, null, new DateTimeImmutable($fecha . ' 14:30:00', ReservacionConfig::timezone()));
        $actual = OcupacionMesasService::resumenCapacidad(\Model\Mesa::reservables(), $ocupacionActual);
        if (($actual['capacidad_fisica_comprometida'] ?? 0) !== 20) {
            $fallos[] = 'Dinámica C12/C10: tickets actuales no bloquearon todas sus mesas exactamente una vez.';
        }
        $demanda = CapacidadReservacionesService::consultarDemandaNoAsignada($fecha, $ocupacion['intervalo']);
        $idsDemanda = array_map(static fn(array $fila): int => (int)$fila['id'], $demanda);
        sort($idsDemanda, SORT_NUMERIC);
        if ($idsDemanda !== [202]) {
            $fallos[] = 'Dinámica C6–C10: NOT EXISTS/estado/traslape no filtraron la demanda esperada.';
        }

        $token = 'etapa5_idempotencia_20260820_001';
        $datos = [
            'request_token' => $token,
            'nombre' => 'Admin capacidad',
            'contacto_tipo' => 'email',
            'contacto' => 'admin@example.com',
            'fecha' => '2026-08-21',
            'hora' => '15:00',
            'comensales' => 2,
            'asignar_automaticamente' => '0',
            'confirmaciones' => 'SIN_ASIGNACION',
        ];
        $primera = ReservacionAdministrativaService::crear($datos);
        $segunda = ReservacionAdministrativaService::crear($datos);
        if (!(($primera['ok'] ?? false) && ($segunda['ok'] ?? false) && ($segunda['idempotente'] ?? false) && ($primera['id'] ?? 0) === ($segunda['id'] ?? -1))) {
            $fallos[] = 'Dinámica C15: la creación administrativa no fue idempotente.';
        }
        $conflictoToken = $datos;
        $conflictoToken['comensales'] = 3;
        $conflicto = ReservacionAdministrativaService::crear($conflictoToken);
        if (($conflicto['codigo'] ?? '') !== 'REQUEST_TOKEN_CONFLICTO') {
            $fallos[] = 'Dinámica C15: reutilizar el token con otros datos no produjo conflicto.';
        }

        $sobreCapacidad = ReservacionAdministrativaService::crear([
            'request_token' => 'etapa5_sobrecapacidad_20260821',
            'nombre' => 'Admin sobrecapacidad',
            'contacto_tipo' => 'email',
            'contacto' => 'sobre@example.com',
            'fecha' => '2026-08-21',
            'hora' => '15:00',
            'comensales' => 44,
            'asignar_automaticamente' => '0',
            'confirmar_sobrecapacidad' => '1',
            'confirmaciones' => 'CAPACIDAD_OPERATIVA_EXCEDIDA,SIN_ASIGNACION',
        ]);
        if (!(($sobreCapacidad['ok'] ?? false) && ($sobreCapacidad['requiere_asignacion_manual'] ?? false) && empty($sobreCapacidad['mesa_ids']))) {
            $fallos[] = 'Dinámica C14: la sobrecapacidad explícita no confirmó una reservación sin mesas.';
        }
    } catch (Throwable $error) {
        $fallos[] = 'Prueba dinámica bloqueada: ' . $error->getMessage();
    } finally {
        if ($db instanceof mysqli) {
            $db->close();
        }
        if ($servidor instanceof mysqli) {
            $servidor->query("DROP DATABASE IF EXISTS {$temp}");
            $servidor->close();
        }
    }
    return $fallos;
}
