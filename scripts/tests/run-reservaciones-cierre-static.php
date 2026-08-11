<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function assertClosureContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALLO: {$message}\n");
        exit(1);
    }
}

$auth = file_get_contents($root . '/classes/Auth.php');
$controller = file_get_contents($root . '/controllers/PuntoVentaController.php');
$pos = file_get_contents($root . '/src/js/modules/punto-de-venta.js');
$availability = file_get_contents($root . '/services/DisponibilidadReservacionService.php');

assertClosureContract(is_string($auth), 'se pudo leer la frontera de autorización');
assertClosureContract(is_string($controller), 'se pudo leer el controlador POS');
assertClosureContract(is_string($pos), 'se pudo leer el consumidor POS');
assertClosureContract(is_string($availability), 'se pudo leer el motor de disponibilidad');

assertClosureContract(
    str_contains($auth, "'/api/sugerencias'")
        && str_contains($auth, "in_array(\$url, self::APIS_POS, true)"),
    'sugerencias queda dentro de las APIs protegidas del POS'
);
assertClosureContract(
    str_contains($controller, "\$resultado['codigo'] = 'TICKET_CREADO';")
        && str_contains($controller, "\$resultado['codigo'] = 'NO_SHOW';")
        && str_contains($controller, "\$cierre['codigo'] = 'TICKET_CERRADO';"),
    'mutaciones POS confirmadas exponen códigos con commit canónico'
);
assertClosureContract(
    str_contains($pos, 'var committed = result && result.commit === true;')
        && !str_contains($pos, 'result.commit === true || result.ok === true'),
    'el flujo de operaciones no usa ok como alias de commit'
);
assertClosureContract(
    str_contains($pos, "clasificarResultadoAperturaTicket(result) === 'exito'"),
    'la apertura de walk-in valida el resultado de ticket por contrato'
);
assertClosureContract(
    str_contains($pos, "resultado.ok === true &&\n    resultado.commit === true")
        && !str_contains($pos, 'resultado.commit === true || resultado.tipo === \'exito\''),
    'la apertura POS exige commit verdadero para reconocer éxito'
);
assertClosureContract(
    substr_count($pos, 'if (result.commit === true) {') >= 2,
    'los cierres de ticket no usan ok como alias de commit'
);
assertClosureContract(
    str_contains($availability, '$horaSolicitadaEntrada')
        && str_contains($availability, 'HorarioReservacionService::HORARIO_INVALIDO')
        && str_contains($availability, 'HorarioReservacionService::validarHora('),
    'la consulta de horarios separa hora inválida de falta de capacidad'
);

echo "Reservaciones: seguridad y contrato de cierre estático OK\n";
