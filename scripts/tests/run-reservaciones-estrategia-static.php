<?php

declare(strict_types=1);

/*
 * Contrato estático del panel estratégico de reservaciones (fase 6).
 *
 * Sin base: comprueba que las dos pantallas están cableadas de punta a punta
 * —rutas, controlador, vistas, hoja y bundle registrados— y que la
 * sub-navegación sale en las TRES pantallas del módulo. Es lo que se rompe en
 * silencio: una vista que deja de incluir el parcial no falla, sólo deja al
 * usuario sin forma de volver.
 */

$root = dirname(__DIR__, 2);

function assertEstrategia(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        fwrite(STDERR, "FALLO: {$mensaje}\n");
        exit(1);
    }
}

$leer = static function (string $ruta) use ($root): string {
    $contenido = @file_get_contents($root . '/' . $ruta);
    assertEstrategia(is_string($contenido), "se pudo leer {$ruta}");

    return str_replace(["\r\n", "\r"], "\n", $contenido);
};

$index = $leer('public/index.php');
$controlador = $leer('controllers/AdminReservacionEstrategiaController.php');
$gulp = $leer('gulpfile.js');
$rango = $leer('services/Shared/RangoPeriodo.php');

// ── Rutas ─────────────────────────────────────────────────────────────
foreach ([
    "\$router->get('/admin/reservaciones/grupos-grandes', [AdminReservacionEstrategiaController::class, 'gruposGrandes']);",
    "\$router->get('/admin/reservaciones/casos', [AdminReservacionEstrategiaController::class, 'casos']);",
    "\$router->post('/admin/api/reservaciones/reubicaciones/revisar', [AdminReservacionEstrategiaController::class, 'revisarReubicacion']);",
] as $ruta) {
    assertEstrategia(str_contains($index, $ruta), "ruta registrada: {$ruta}");
}
assertEstrategia(
    str_contains($index, 'use Controllers\AdminReservacionEstrategiaController;'),
    'public/index.php importa el controlador estratégico'
);

// ── Controlador ───────────────────────────────────────────────────────
foreach (['gruposGrandes', 'casos', 'revisarReubicacion'] as $metodo) {
    assertEstrategia(
        preg_match('/public static function ' . $metodo . '\(Router \$router\): void/', $controlador) === 1,
        "el controlador expone {$metodo}()"
    );
}
assertEstrategia(
    str_contains($controlador, "'activeModule' => 'reservations'"),
    'las pantallas marcan Reservaciones en el sidebar'
);
assertEstrategia(
    str_contains($controlador, 'AdminCsrfService::validar(')
        && str_contains($controlador, 'ReservacionReubicacion::marcarRevisada(')
        && str_contains($controlador, 'BuzonNotificacionesService::cerrarEntidad('),
    '«Marcar revisada» valida CSRF de administración y delega en el mismo modelo y cierre de buzón que el POS'
);
assertEstrategia(
    substr_count($controlador, 'RangoPeriodo::resolverProspectivo(') === 2,
    'las dos pantallas resuelven un periodo prospectivo'
);
assertEstrategia(
    str_contains($rango, 'public static function resolverProspectivo(')
        && str_contains($rango, 'public static function resolver(array $query, int $presetPorDefecto = 30): array'),
    'RangoPeriodo suma el prospectivo sin cambiar la firma de resolver()'
);

// ── Vistas y sub-navegación ───────────────────────────────────────────
$subnav = $leer('views/admin/reservations/_subnav.php');
foreach (['/admin/reservaciones\'', '/admin/reservaciones/grupos-grandes', '/admin/reservaciones/casos', 'admin-tabs__tab', 'aria-current="page"'] as $pieza) {
    assertEstrategia(str_contains($subnav, $pieza), "la sub-navegación contiene {$pieza}");
}
assertEstrategia(str_contains($subnav, 'unset($subnavActiva'), 'el parcial de sub-navegación limpia sus parámetros');

foreach ([
    'views/admin/reservations/index.php' => 'historico',
    'views/admin/reservations/grupos-grandes.php' => 'grupos',
    'views/admin/reservations/casos.php' => 'casos',
] as $vista => $clave) {
    $contenido = $leer($vista);
    assertEstrategia(
        str_contains($contenido, "\$subnavActiva = '{$clave}'; include __DIR__ . '/_subnav.php';"),
        "{$vista} incluye la sub-navegación con «{$clave}» activa"
    );
}

$grupos = $leer('views/admin/reservations/grupos-grandes.php');
$casos = $leer('views/admin/reservations/casos.php');
assertEstrategia(str_contains($grupos, '_range-picker.php') && str_contains($casos, '_range-picker.php'), 'las dos pantallas usan el selector de periodo compartido');
assertEstrategia(str_contains($grupos, 'href="/admin/reservaciones/crear"'), '«Nuevo grupo» lleva al alta existente');
assertEstrategia(str_contains($grupos, "['url_pos']") && str_contains($grupos, "url_detalle"), 'cada grupo enlaza al POS y al detalle');
assertEstrategia(str_contains($grupos, 'admin-visually-hidden'), 'la franja dice su estado en texto, no sólo en color');
assertEstrategia(str_contains($casos, '_pagination.php'), 'Casos pagina con el parcial compartido');
assertEstrategia(
    str_contains($casos, 'data-caso-no-show') && str_contains($casos, 'data-caso-revisar') && str_contains($casos, "'mode' => 'assign'"),
    'Casos ofrece no-show, marcar revisada y asignar mesas'
);

// ── Build y JS ────────────────────────────────────────────────────────
assertEstrategia(is_file($root . '/src/scss/admin/modules/reservations-estrategia.scss'), 'existe la hoja del módulo');
assertEstrategia(
    str_contains($gulp, 'adminReservationEstrategiaJs: "src/js/admin/reservations/estrategia.js"')
        && str_contains($gulp, 'concat("reservation-estrategia.js")'),
    'gulpfile compila el JS de Casos'
);
$js = $leer('src/js/admin/reservations/estrategia.js');
assertEstrategia(
    str_contains($js, 'ConfirmationModal.get().open(') && preg_match('/\b(alert|confirm|prompt)\s*\(/', $js) !== 1,
    'Casos confirma con el componente y sin diálogos nativos'
);
assertEstrategia(
    str_contains($js, '/admin/api/reservaciones/estado') && str_contains($js, 'casos_ausencia_pendiente'),
    'el no-show va al endpoint admin con su motivo'
);

// ── Sin color literal ─────────────────────────────────────────────────
$sinColor = [
    'src/scss/admin/modules/reservations-estrategia.scss',
    'src/scss/admin/modules/reservations/_subnav.scss',
    'src/js/admin/reservations/estrategia.js',
    'views/admin/reservations/grupos-grandes.php',
    'views/admin/reservations/casos.php',
    'views/admin/reservations/_subnav.php',
];
foreach ($sinColor as $archivo) {
    assertEstrategia(
        preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(\s*[0-9]/', $leer($archivo)) !== 1,
        "{$archivo} no contiene hex ni rgb(a) literales"
    );
}

echo "OK run-reservaciones-estrategia-static\n";
