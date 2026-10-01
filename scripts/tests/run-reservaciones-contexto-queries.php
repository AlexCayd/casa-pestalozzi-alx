<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Model\ActiveRecord;
use Services\Reservations\CapacidadReservacionesService;
use Services\Reservations\ReservacionConfig;
use Services\Tables\OcupacionMesasService;

function assertContextQueries(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class ContextQueryResult
{
    private array $rows;

    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }

    public function fetch_assoc(): array|false
    {
        return array_shift($this->rows) ?? false;
    }

    public function free(): void
    {
    }
}

final class ContextQueryStatement
{
    private ContextQueryDatabase $db;
    private array $params = [];

    public function __construct(ContextQueryDatabase $db)
    {
        $this->db = $db;
    }

    public function bind_param(string $types, &...$params): bool
    {
        foreach ($params as $param) {
            $this->params[] = (string)$param;
        }
        return true;
    }

    public function execute(): bool
    {
        return true;
    }

    public function get_result(): ContextQueryResult
    {
        $this->db->registrar('demanda_intervalo');
        $fin = new DateTimeImmutable((string)($this->params[1] ?? '2099-01-01 00:00:00'));
        $inicio = new DateTimeImmutable((string)($this->params[2] ?? '2099-01-01 00:00:00'));
        $demanda = $this->db->demanda()[0];
        $inicioDemanda = new DateTimeImmutable($demanda['fecha'] . ' ' . $demanda['hora']);
        $finDemanda = $inicioDemanda->modify('+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes');
        $traslapa = $inicioDemanda < $fin && $finDemanda > $inicio;
        return new ContextQueryResult($traslapa ? [$demanda] : []);
    }

    public function close(): void
    {
    }
}

final class ContextQueryDatabase
{
    public string $error = '';
    private array $conteos = [
        'asignaciones' => 0,
        'tickets' => 0,
        'mesas' => 0,
        'demanda_dia' => 0,
        'demanda_intervalo' => 0,
    ];

    public function query(string $sql): ContextQueryResult|false
    {
        if (str_contains($sql, 'FROM reservacion_mesas rm')
            && str_contains($sql, 'INNER JOIN reservaciones r')) {
            $this->registrar('asignaciones');
            return new ContextQueryResult([[
                'mesa_id' => 1,
                'reservacion_id' => 101,
                'nombre' => 'Reserva de prueba',
                'contacto' => '',
                'fecha' => '2099-01-01',
                'hora' => '13:00:00',
                'comensales' => 2,
                'estado' => 'confirmada',
                'hold_expires_at' => null,
            ]]);
        }
        if (str_contains($sql, 'FROM tickets t')) {
            $this->registrar('tickets');
            return new ContextQueryResult([]);
        }
        if (str_contains($sql, 'FROM mesas')) {
            $this->registrar('mesas');
            return new ContextQueryResult([
                ['id' => 1, 'numero' => 1, 'nombre' => 'Mesa 1', 'tipo' => 'mesa', 'capacidad' => 4, 'pos_x' => 0, 'pos_y' => 0, 'activo' => 1, 'reservable' => 1],
                ['id' => 2, 'numero' => 2, 'nombre' => 'Mesa 2', 'tipo' => 'mesa', 'capacidad' => 4, 'pos_x' => 0, 'pos_y' => 0, 'activo' => 1, 'reservable' => 1],
                ['id' => 3, 'numero' => 3, 'nombre' => 'Mesa 3', 'tipo' => 'mesa', 'capacidad' => 4, 'pos_x' => 0, 'pos_y' => 0, 'activo' => 0, 'reservable' => 1],
            ]);
        }
        if (str_contains($sql, 'FROM reservaciones r')) {
            $this->registrar('demanda_dia');
            return new ContextQueryResult($this->demanda());
        }
        $this->error = 'Consulta inesperada en el fake de ocupación.';
        return false;
    }

    public function prepare(string $sql): ContextQueryStatement|false
    {
        return new ContextQueryStatement($this);
    }

    public function real_escape_string(string $value): string
    {
        return addslashes($value);
    }

    public function registrar(string $categoria): void
    {
        $this->conteos[$categoria]++;
    }

    public function conteos(): array
    {
        return $this->conteos;
    }

    public function demanda(): array
    {
        return [[
            'id' => 102,
            'fecha' => '2099-01-01',
            'hora' => '14:00:00',
            'comensales' => 3,
            'estado' => 'confirmada',
            'influye_disponibilidad' => true,
        ]];
    }
}

$fecha = '2099-01-01';
$ahora = new DateTimeImmutable('2098-12-01 10:00:00', ReservacionConfig::timezone());
$horarios = ['11:30:00', '12:00:00', '13:00:00', '13:30:00', '14:00:00', '14:30:00', '15:00:00', '15:30:00', '16:00:00', '16:30:00'];

$dbIndividual = new ContextQueryDatabase();
ActiveRecord::setDB($dbIndividual);
$individual = [];
foreach ($horarios as $hora) {
    $individual[] = CapacidadReservacionesService::evaluarHorario($fecha, $hora, 0, false, null, $ahora);
}

$dbContexto = new ContextQueryDatabase();
ActiveRecord::setDB($dbContexto);
$contexto = OcupacionMesasService::prepararContextoFecha($fecha, false, null, $ahora, 0, true);
$preparado = [];
foreach ($horarios as $hora) {
    $preparado[] = CapacidadReservacionesService::evaluarHorario(
        $fecha,
        $hora,
        0,
        false,
        null,
        $ahora,
        '',
        $contexto
    );
}

$camposCapacidad = [
    'capacidad_fisica_total',
    'capacidad_fisica_libre',
    'demanda_no_asignada',
    'capacidad_real_disponible',
    'exceso_capacidad',
    'mesa_ids_libres',
    'mesa_ids_bloqueadas',
    'demanda_no_asignada_ids',
];
foreach ($horarios as $indice => $hora) {
    $antes = $individual[$indice];
    $despues = $preparado[$indice];
    foreach ($camposCapacidad as $campo) {
        assertContextQueries(
            ($antes[$campo] ?? null) === ($despues[$campo] ?? null),
            "{$hora} conserva capacidad {$campo}"
        );
    }
    foreach (['mesa_ids_disponibles', 'mesa_ids_bloqueadas', 'ocupacion_reservaciones'] as $campo) {
        assertContextQueries(
            ($antes['ocupacion'][$campo] ?? null) === ($despues['ocupacion'][$campo] ?? null),
            "{$hora} conserva ocupación {$campo}"
        );
    }
}

$consultasIndividuales = $dbIndividual->conteos();
$consultasContexto = $dbContexto->conteos();
assertContextQueries($consultasIndividuales['asignaciones'] === count($horarios), 'el control individual consulta ocupación una vez por slot');
assertContextQueries($consultasContexto['asignaciones'] === 1, 'el contexto consulta asignaciones una vez por fecha');
assertContextQueries($consultasContexto['tickets'] === 1, 'el contexto consulta tickets una vez por fecha');
assertContextQueries($consultasContexto['mesas'] === 1, 'el contexto consulta mesas una vez por fecha');
assertContextQueries($consultasContexto['demanda_dia'] === 1, 'el contexto consulta demanda una vez por fecha');
assertContextQueries($consultasContexto['demanda_intervalo'] === 0, 'el contexto no vuelve a consultar demanda por slot');

fwrite(STDOUT, json_encode([
    'ok' => true,
    'slots' => count($horarios),
    'consultas_individuales' => $consultasIndividuales,
    'consultas_contexto' => $consultasContexto,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
