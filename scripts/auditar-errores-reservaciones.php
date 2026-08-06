<?php

declare(strict_types=1);

use Services\ReservacionErrorCatalog;

require_once __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$relativeFiles = [
    'services/ReservacionService.php',
    'services/ReservacionPublicaService.php',
    'services/ReservacionAdministrativaService.php',
    'services/ReservacionMantenimientoService.php',
    'services/ReservacionMapaAdministrativaService.php',
    'services/ReservacionVigenciaService.php',
    'services/AsignacionMesasService.php',
    'services/DisponibilidadReservacionService.php',
    'services/HorarioReservacionService.php',
    'services/ContactoAccesoService.php',
    'services/PuntoVentaReservacionService.php',
    'services/PosReservacionQueryService.php',
    'services/HorarioOperacionService.php',
    'services/ReservacionErrorCatalog.php',
    'controllers/ReservacionController.php',
    'controllers/AdminReservacionController.php',
    'controllers/ReservacionOperacionController.php',
    'controllers/PuntoVentaController.php',
    'controllers/AdminConfigurationController.php',
    'controllers/AuthController.php',
    'classes/Auth.php',
    'models/Reservacion.php',
    'models/ReservacionMesa.php',
    'models/TicketMesa.php',
];
$jsFiles = array_merge(
    glob($root . '/src/js/modules/reservation-*.js') ?: [],
    glob($root . '/src/js/modules/punto-de-venta.js') ?: [],
    glob($root . '/src/js/admin/reservations/*.js') ?: [],
    glob($root . '/src/js/components/reservation-*.js') ?: []
);

$errors = [];
$warnings = [];
$info = [];
$emitted = [];
$declarations = [];
$domainMessages = [];
$legacyConsumers = [];
$localTranslations = [];
$sensitiveContext = [];
$filesRead = [];
$typeCounts = [];

$report = static function (array &$bucket, string $message): void {
    $bucket[] = $message;
};

foreach ($relativeFiles as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $report($errors, "ERROR archivo ausente: $relative");
        continue;
    }
    $contents = (string)file_get_contents($path);
    $filesRead[] = $relative;

    if (preg_match('/(?:Ã|Â|â|ï¿½|�)/u', $contents)) {
        $report($errors, "ERROR mojibake en $relative");
    }

    if (preg_match_all('/public\s+const\s+[A-Z][A-Z0-9_]*\s*=\s*[\x27\"]([A-Z][A-Z0-9_]+)[\x27\"]/', $contents, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $codigo = $match[1];
            $emitted[$codigo][] = $relative;
            $declarations[$codigo][] = $relative;
        }
    }
    if (preg_match_all('/[\x27\"]codigo[\x27\"]\s*=>\s*[\x27\"]([A-Z][A-Z0-9_]+)[\x27\"]/', $contents, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $codigo = $match[1];
            $emitted[$codigo][] = $relative;
        }
    }
    if (preg_match_all('/\bcodigo\s*=\s*[\x27\"]([A-Z][A-Z0-9_]+)[\x27\"]/', $contents, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $codigo = $match[1];
            $emitted[$codigo][] = $relative;
        }
    }
    if (preg_match_all('/(?:errorJson|enriquecer)\s*\(\s*[\x27\"]([A-Z][A-Z0-9_]+)[\x27\"]/', $contents, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $codigo = $match[1];
            $emitted[$codigo][] = $relative;
        }
    }

    if (str_starts_with($relative, 'services/') && $relative !== 'services/ReservacionErrorCatalog.php') {
        if (preg_match_all('/[\x27\"](?:mensaje|msg|mensaje_bloqueo)[\x27\"]\s*=>\s*[\x27\"]([^\x27\"]+)[\x27\"]/', $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $domainMessages[] = $relative . ': ' . trim($match[1]);
            }
        }
    }

        if (preg_match('/sprintf\s*\(\s*[\x27"][^\x27"]*(?:capacidad|minutos|reservaci[oó]n|mesa|ticket)/iu', $contents)) {
            $sensitiveContext[] = $relative;
        }
    if ($relative !== 'services/ReservacionErrorCatalog.php'
        && preg_match('/(?:\[\s*[\x27"](?:msg|message|mensaje_bloqueo)[\x27"]\s*\]|->(?:msg|message|mensaje_bloqueo)|(?:msg|message|mensaje_bloqueo)[\x27"]\s*=>)/i', $contents)
    ) {
        $legacyConsumers[] = $relative;
    }
    if (str_starts_with($relative, 'controllers/') && preg_match('/(?:mensaje|message)\s*=>\s*[\x27"][^\x27"]{8,}[\x27"]/iu', $contents)) {
        $localTranslations[] = $relative;
    }
}

foreach ($emitted as $codigo => $locations) {
    if (!ReservacionErrorCatalog::has($codigo)) {
        $report($errors, 'ERROR código no catalogado: ' . $codigo . ' (' . implode(', ', array_unique($locations)) . ')');
    }
}

foreach ($declarations as $codigo => $locations) {
    if (count(array_unique($locations)) > 1) {
        $report($warnings, 'WARNING código declarado en varias clases: ' . $codigo . ' (' . implode(', ', array_unique($locations)) . ')');
    }
}

foreach ($jsFiles as $path) {
    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $contents = (string)file_get_contents($path);
    if (preg_match('/(?:Ã|Â|â|ï¿½|�)/u', $contents)) {
        $report($errors, "ERROR mojibake en $relative");
    }
    if (preg_match('/(?:mensaje|msg|mensaje_bloqueo)[^\r\n]*(?:===|!==|==|!=)|(?:===|!==|==|!=)[^\r\n]*(?:mensaje|msg|mensaje_bloqueo)/i', $contents)) {
        $report($warnings, "WARNING JavaScript compara texto de respuesta en $relative");
    }
    if (preg_match('/(?:result|payload|data)\.(?:msg|message|mensaje_bloqueo)|[\x27"](?:msg|message|mensaje_bloqueo)[\x27"]\s*:/i', $contents)) {
        $legacyConsumers[] = $relative;
    }
    if (preg_match_all('/(?:codigo|motivo_bloqueo)\s*(?:===|!==|==|!=|:)\s*[\x27\"]([A-Z][A-Z0-9_]+)[\x27\"]/', $contents, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            if (!ReservacionErrorCatalog::has($match[1])) {
                $report($errors, "ERROR código consumido en JavaScript no catalogado: {$match[1]} ($relative)");
            }
        }
    }
    if (preg_match('/(?:15|30|40|60|90)\s+minutos/i', $contents)) {
        $report($warnings, "WARNING literal temporal visible en $relative");
    }
}

if ($domainMessages !== []) {
    $report($errors, 'ERROR servicios de dominio producen textos visibles fuera del catálogo: ' . implode('; ', $domainMessages));
}
if ($legacyConsumers !== []) {
    $report($errors, 'ERROR consumidores heredados de msg/message/mensaje_bloqueo: ' . implode(', ', array_unique($legacyConsumers)));
}
if ($localTranslations !== []) {
    $report($errors, 'ERROR traducciones locales detectadas en controladores: ' . implode(', ', array_unique($localTranslations)));
}
if ($sensitiveContext !== []) {
    $report($errors, 'ERROR textos dinámicos sensibles fuera del catálogo: ' . implode(', ', array_unique($sensitiveContext)));
}

$report($info, 'INFO archivos PHP auditados: ' . count($filesRead));
$report($info, 'INFO códigos encontrados: ' . count($emitted));
$report($info, 'INFO mensajes literales de servicios detectados: ' . count($domainMessages));
$report($info, 'INFO consumidores heredados detectados: ' . count(array_unique($legacyConsumers)));
$report($info, 'INFO traducciones locales detectadas: ' . count(array_unique($localTranslations)));
$report($info, 'INFO contextos sensibles interpolados fuera del catálogo: ' . count(array_unique($sensitiveContext)));
foreach ($emitted as $codigo => $locations) {
    if (ReservacionErrorCatalog::has($codigo)) {
        $tipo = ReservacionErrorCatalog::definition($codigo)['tipo'];
        $typeCounts[$tipo] = ($typeCounts[$tipo] ?? 0) + 1;
    }
}
$report($info, 'INFO tipos de códigos encontrados: ' . json_encode($typeCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

foreach ($errors as $line) {
    echo $line . PHP_EOL;
}
foreach ($warnings as $line) {
    echo $line . PHP_EOL;
}
foreach ($info as $line) {
    echo $line . PHP_EOL;
}

echo sprintf(
    "RESUMEN errors=%d warnings=%d info=%d\n",
    count($errors),
    count($warnings),
    count($info)
);

exit($errors === [] ? 0 : 1);
