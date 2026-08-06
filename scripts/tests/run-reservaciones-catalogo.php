<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Services\ReservacionErrorCatalog;

$fallos = [];
$afirmar = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    if (!$condicion) {
        $fallos[] = $mensaje;
    }
};

$catalogo = ReservacionErrorCatalog::all();
$codigos = array_keys($catalogo);
$root = dirname(__DIR__, 2);
$tiposPermitidos = [
    ReservacionErrorCatalog::TIPO_ERROR,
    ReservacionErrorCatalog::TIPO_CONFLICTO,
    ReservacionErrorCatalog::TIPO_ADVERTENCIA,
    ReservacionErrorCatalog::TIPO_DECISION,
    ReservacionErrorCatalog::TIPO_INFORMACION,
];

$afirmar($codigos !== [], 'El catálogo no puede estar vacío.');
$afirmar(count($codigos) === count(array_unique($codigos)), 'Existen códigos duplicados en el catálogo.');

$mensajesKeys = [];
$aliases = ReservacionErrorCatalog::aliases();
foreach ($catalogo as $codigo => $definicion) {
    foreach (['tipo', 'http_status', 'mensaje_key', 'titulo', 'mensaje', 'consecuencia', 'acciones', 'commit'] as $campo) {
        $afirmar(array_key_exists($campo, $definicion), "$codigo no contiene el campo requerido $campo.");
    }
    $afirmar(is_string($definicion['tipo']) && $definicion['tipo'] !== '', "$codigo no tiene tipo.");
    $afirmar(in_array($definicion['tipo'], $tiposPermitidos, true), "$codigo tiene un tipo desconocido.");
    $afirmar(is_int($definicion['http_status']), "$codigo no tiene HTTP entero.");
    $afirmar(in_array($definicion['http_status'], [200, 401, 403, 404, 405, 409, 410, 422, 429, 500], true), "$codigo tiene HTTP fuera del contrato.");
    $afirmar(is_string($definicion['mensaje']) && trim($definicion['mensaje']) !== '', "$codigo no tiene mensaje.");
    $afirmar(is_array($definicion['acciones']), "$codigo no tiene acciones como array.");
    if ($definicion['tipo'] === ReservacionErrorCatalog::TIPO_DECISION) {
        $afirmar($definicion['acciones'] !== [], "$codigo requiere al menos una acción.");
    }
    $afirmar(!preg_match('/(?:Ã|Â|â|ï¿½|�)/u', (string)$definicion['mensaje']), "$codigo contiene mojibake.");
    $afirmar(!preg_match('/(?:SELECT|INSERT|UPDATE|DELETE|Trace|Exception|C:\\\\)/i', (string)$definicion['mensaje']), "$codigo expone contexto técnico.");
    if (!array_key_exists($codigo, $aliases)) {
        $mensajesKeys[] = $definicion['mensaje_key'];
    }
}

$afirmar(count($mensajesKeys) === count(array_unique($mensajesKeys)), 'Existen dos fuentes de traducción para una misma clave de mensaje.');
$afirmar($aliases === [], 'Etapa 3 no permite aliases de códigos emitidos.');

foreach (['SESION_PUBLICA_EXPIRADA', 'CSRF_INVALIDO', 'OTP_INCORRECTO', 'OTP_EXPIRADO', 'OTP_INTENTOS_AGOTADOS', 'VERIFICACION_NO_ENCONTRADA', 'CONTACTO_NO_COINCIDE', 'ERROR_INTERNO'] as $codigo) {
    $afirmar(ReservacionErrorCatalog::has($codigo), "Falta el código obligatorio $codigo.");
}

foreach (ReservacionErrorCatalog::aliases() as $alias => $canonico) {
    $afirmar(ReservacionErrorCatalog::has($alias), "El alias $alias no está registrado.");
    $afirmar(ReservacionErrorCatalog::has($canonico), "El alias $alias apunta a un código inexistente: $canonico.");
    $afirmar($alias !== $canonico, "El alias $alias crea un ciclo directo.");
}

$resultado = ReservacionErrorCatalog::enriquecer([
    'ok' => false,
    'codigo' => 'TOLERANCIA_LLEGADA_VENCIDA',
    'msg' => 'texto heredado que no debe ser la fuente',
    'message' => 'texto heredado alterno',
    'mensaje_bloqueo' => 'texto heredado de bloqueo',
]);
$afirmar($resultado['codigo'] === 'TOLERANCIA_LLEGADA_VENCIDA', 'La compatibilidad debe conservar el código recibido.');
$afirmar($resultado['codigo_canonico'] === 'TOLERANCIA_LLEGADA_VENCIDA', 'El resultado debe exponer el código canónico.');
$afirmar(!array_key_exists('msg', $resultado), 'El contrato no debe reemitir msg.');
$afirmar(!array_key_exists('message', $resultado), 'El contrato no debe reemitir message.');
$afirmar(!array_key_exists('mensaje_bloqueo', $resultado), 'El contrato no debe reemitir mensaje_bloqueo.');
$afirmar(isset($resultado['tipo'], $resultado['acciones'], $resultado['commit']), 'El resultado enriquecido no contiene el contrato común.');

$resultadoCampos = ReservacionErrorCatalog::enriquecer([
    'ok' => false,
    'codigo' => 'DATOS_INVALIDOS',
    'field_codes' => [
        'comensales' => ['COMENSALES_FUERA_DE_RANGO'],
    ],
    'contexto' => ['max_comensales' => 12],
]);
$afirmar(isset($resultadoCampos['errors']['comensales'][0]), 'Los field_codes deben producir errores visibles catalogados.');
$afirmar(!str_contains($resultadoCampos['errors']['comensales'][0], '{'), 'La interpolación de campos dejó placeholders.');

$capacidad = ReservacionErrorCatalog::presentar('REQUIERE_CONFIRMACION_CAPACIDAD', [
    'capacidad_solicitada' => 8,
    'capacidad_disponible' => 6,
]);
$afirmar(!str_contains($capacidad['mensaje'], '{'), 'La capacidad no se interpoló desde contexto seguro.');

$proxima = ReservacionErrorCatalog::enriquecer([
    'ok' => false,
    'codigo' => 'REQUIERE_CONFIRMACION',
    'advertencia' => [
        'codigo' => 'RESERVACION_PROXIMA',
        'contexto' => ['hora' => '18:00', 'minutos_restantes' => 20],
    ],
]);
$afirmar(isset($proxima['advertencia']['presentacion']['mensaje']), 'Las advertencias anidadas deben salir del catálogo.');

$afirmar(ReservacionErrorCatalog::definition('REQUIERE_CONFIRMACION')['tipo'] === ReservacionErrorCatalog::TIPO_DECISION, 'Las decisiones deben conservar su tipo.');
$afirmar(ReservacionErrorCatalog::definition('CONFLICTO_CONCURRENTE')['http_status'] === 409, 'Los conflictos concurrentes deben responder HTTP 409.');
$afirmar(ReservacionErrorCatalog::definition('RESERVACION_CREADA')['commit'] === true, 'Un éxito persistido debe marcar commit=true.');
$afirmar(ReservacionErrorCatalog::definition('ERROR_INTERNO')['commit'] === false, 'Un error debe marcar commit=false.');

$archivosAuditar = array_merge(
    glob($root . '/services/Reservacion*.php') ?: [],
    glob($root . '/services/AsignacionMesasService.php') ?: [],
    glob($root . '/services/PuntoVentaReservacionService.php') ?: [],
    glob($root . '/services/DisponibilidadReservacionService.php') ?: [],
    glob($root . '/services/HorarioOperacionService.php') ?: [],
    glob($root . '/src/js/modules/reservation-*.js') ?: [],
    glob($root . '/src/js/modules/punto-de-venta.js') ?: [],
    glob($root . '/src/js/admin/reservations/*.js') ?: []
);
$codigosJsNoCatalogados = [];
$contratosHeredados = [];
$comparacionesTexto = [];
foreach (array_unique($archivosAuditar) as $archivo) {
    $contenido = (string)file_get_contents($archivo);
    if (str_ends_with(str_replace('\\', '/', $archivo), 'ReservacionErrorCatalog.php')) {
        continue;
    }
    if (preg_match('/[\x27"](?:msg|message|mensaje_bloqueo)[\x27"]\s*=>|(?:result|payload|data)\.(?:msg|message|mensaje_bloqueo)/i', $contenido)) {
        $contratosHeredados[] = $archivo;
    }
    if (preg_match('/(?:mensaje|msg|mensaje_bloqueo)[^\r\n]*(?:===|!==|==|!=)|(?:===|!==|==|!=)[^\r\n]*(?:mensaje|msg|mensaje_bloqueo)/i', $contenido)) {
        $comparacionesTexto[] = $archivo;
    }
    if (preg_match_all('/(?:codigo|motivo_bloqueo)\s*(?:===|!==|==|!=|:)\s*[\x27"]([A-Z][A-Z0-9_]+)[\x27"]/', $contenido, $matches)) {
        foreach ($matches[1] as $codigo) {
            if (!ReservacionErrorCatalog::has($codigo)) {
                $codigosJsNoCatalogados[] = $codigo . ' (' . $archivo . ')';
            }
        }
    }
}
$afirmar($contratosHeredados === [], 'Existen consumidores de contratos heredados: ' . implode(', ', $contratosHeredados));
$afirmar($comparacionesTexto === [], 'Existen comparaciones textuales en JS: ' . implode(', ', $comparacionesTexto));
$afirmar($codigosJsNoCatalogados === [], 'Existen códigos JS no catalogados: ' . implode(', ', $codigosJsNoCatalogados));

if ($fallos !== []) {
    fwrite(STDERR, "FAIL\n");
    foreach ($fallos as $fallo) {
        fwrite(STDERR, "- $fallo\n");
    }
    exit(1);
}

echo 'PASS: ' . count($catalogo) . " códigos catalogados; contrato común y compatibilidades básicas correctos.\n";
