<?php

declare(strict_types=1);

/*
 * Iconos de las opciones de variante.
 *
 * Lo que protege:
 *  · que la lista permitida del servidor (Variantes::ICONOS) y los dos
 *    catálogos de trazos (core/icons.js y _icons.php) sean la misma, trazo por
 *    trazo: el POS pinta con el de JS y el formulario con el de PHP;
 *  · que las reglas automáticas sólo apunten a iconos que existen y que las
 *    opciones de las plantillas y de los fixtures tengan el icono esperado;
 *  · que el editor reciba las reglas (no las repite en JS) y que la columna
 *    viva sólo en el DDL: database/ lleva ddl.sql, deploy.sql y development.sql.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/views/admin/partials/_icons.php';

use Services\Menu\Variantes;

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};

// 1 · Lista permitida = catálogo PHP = catálogo JS, con los mismos trazos.
$php = admin_icon_paths();
// Sin \r: con autocrlf el checkout de Windows deja CRLF, y el `$` de /m se
// para antes del \n, no del \r; la prueba no encontraba ningún icono.
$js = str_replace("\r\n", "\n", (string) file_get_contents($root . '/src/js/admin/core/icons.js'));
preg_match_all("/^\\s*'(op-[a-z-]+)': '(.*)',?\$/m", $js, $m, PREG_SET_ORDER);
$trazosJs = [];
foreach ($m as $fila) {
    $trazosJs[$fila[1]] = $fila[2];
}
$enPhp = array_values(array_filter(array_keys($php), static fn (string $k): bool => str_starts_with($k, 'op-')));

$assert(count(Variantes::ICONOS) >= 30, 'Variantes::ICONOS ofrece la familia completa.');
$assert(count(array_diff(array_keys(Variantes::ICONOS), array_keys($trazosJs))) === 0, 'Toda clave permitida existe en core/icons.js.');
$assert(count(array_diff(array_keys($trazosJs), array_keys(Variantes::ICONOS))) === 0, 'core/icons.js no tiene iconos op-* fuera de la lista permitida.');
$assert(count(array_diff(array_keys(Variantes::ICONOS), $enPhp)) === 0, 'Toda clave permitida existe en _icons.php.');
$assert(count(array_diff($enPhp, array_keys(Variantes::ICONOS))) === 0, '_icons.php no tiene iconos op-* fuera de la lista permitida.');
foreach (array_keys(Variantes::ICONOS) as $clave) {
    $assert(($trazosJs[$clave] ?? null) === ($php[$clave] ?? null), "El trazo de {$clave} es idéntico en JS y PHP.");
    $assert(trim((string) Variantes::ICONOS[$clave]) !== '', "{$clave} tiene nombre para el selector.");
    $assert(admin_icon($clave, 16) !== '', "admin_icon('{$clave}') pinta algo.");
}

// 2 · Las reglas sólo apuntan a iconos del catálogo y compilan en PHP.
foreach (Variantes::ICONO_REGLAS as $i => $regla) {
    $assert(isset(Variantes::ICONOS[$regla['icono']]), "La regla {$i} apunta a un icono permitido ({$regla['icono']}).");
    $assert(isset($regla['grupo']) || isset($regla['opcion']), "La regla {$i} mira el grupo o la opción.");
    foreach (['grupo', 'opcion'] as $campo) {
        if (isset($regla[$campo])) {
            $assert(@preg_match('~' . $regla[$campo] . '~u', '') !== false, "La expresión {$campo} de la regla {$i} es válida.");
            $assert(!str_contains($regla[$campo], '~'), "La expresión {$campo} de la regla {$i} no usa el delimitador.");
        }
    }
}

// 3 · Deducción: plantillas, fixtures y casos de contexto.
$esperado = [
    ['Leche', 'Entera', 'op-leche'],
    ['Leche', 'Normal', 'op-leche'],
    ['Leche', 'Deslactosada', 'op-leche'],
    ['Leche', 'Almendra', 'op-almendra'],
    ['Leche', 'Avena', 'op-avena'],
    ['Leche', 'Soya', 'op-planta'],
    ['Leche', 'Sin leche', 'op-sin'],
    ['Leche', 'Extra', 'op-extra'],
    ['Tamaño', 'Regular', 'op-tamano-mediano'],
    ['Tamaño', 'Grande', 'op-tamano-grande'],
    ['Tamaño', 'Chico', 'op-tamano-chico'],
    ['Término', 'Rojo', 'op-termino-rojo'],
    ['Término', 'Medio', 'op-termino-medio'],
    ['Término', 'Tres cuartos', 'op-termino-tres-cuartos'],
    ['Término', 'Bien cocido', 'op-termino-bien'],
    ['Extra', 'Sin extra', 'op-sin'],
    ['Extra', 'Porción extra', 'op-extra'],
    ['Sabor', 'Clásico', 'op-estrella'],
    ['Sabor', 'Frutos rojos', 'op-fruta'],
    ['Presentación', 'Media orden', 'op-mitad'],
    // «Medio» fuera de un término es una media orden, no un término medio.
    ['Porción', 'Medio', 'op-mitad'],
    ['Proteína', 'Arrachera', 'op-carne'],
    // Los fixtures: tres huevos distintos, el camarón y la presentación.
    ['Huevos', 'Estrellados', 'op-huevo-estrellado'],
    ['Huevos', 'Revueltos', 'op-huevo-revuelto'],
    ['Huevos', 'Pochados', 'op-huevo-pochado'],
    ['Huevos', 'Fritos', 'op-huevo-estrellado'],
    ['Proteína extra', 'Camarón', 'op-camaron'],
    ['Proteína extra', 'Pollo', 'op-pollo'],
    ['Proteína extra', 'Sin proteína', 'op-sin'],
    ['Presentación', 'Individual', 'op-individual'],
    ['Presentación', 'Para compartir', 'op-compartir'],
    // «Frito» fuera de un grupo de huevos no es un huevo estrellado.
    ['Guarnición', 'Plátano frito', 'op-fruta'],
];
foreach ($esperado as [$grupo, $opcion, $icono]) {
    $assert(Variantes::iconoSugerido($grupo, $opcion) === $icono, "«{$grupo} · {$opcion}» deduce {$icono}.");
}
$assert(Variantes::iconoSugerido('Estilo', 'Norteño') === null, 'Sin regla que case no se inventa un icono (el POS pinta la inicial).');
foreach (Variantes::PLANTILLAS as $grupo => $opciones) {
    foreach ($opciones as $op) {
        $assert(Variantes::iconoSugerido($grupo, $op['nombre']) !== null, "La plantilla «{$grupo} · {$op['nombre']}» tiene icono automático.");
    }
}

// 4 · Elegido gana a deducido; uno desconocido cae al deducido.
$assert(Variantes::iconoEfectivo('op-copa', 'Leche', 'Avena') === 'op-copa', 'El icono elegido gana al automático.');
$assert(Variantes::iconoEfectivo(null, 'Leche', 'Avena') === 'op-avena', 'NULL es automático.');
$assert(Variantes::iconoEfectivo('op-no-existe', 'Leche', 'Avena') === 'op-avena', 'Un icono fuera del catálogo no llega al POS.');

// 5 · Contratos de datos: DDL, editor y POS.
$ddl = (string) file_get_contents($root . '/database/ddl.sql');
$assert(preg_match('/CREATE TABLE producto_variantes \(.*?icono VARCHAR\(32\) NULL/s', $ddl) === 1, 'ddl.sql declara producto_variantes.icono.');
$assert(!is_file($root . '/database/actualizaciones/variantes-iconos.sql'), 'La columna vive sólo en ddl.sql, sin migración aparte.');
$servicio = (string) file_get_contents($root . '/services/Menu/Variantes.php');
$assert(str_contains($servicio, "'iconos' => ['catalogo' => self::ICONOS, 'reglas' => self::ICONO_REGLAS]"), 'datosEditor() entrega catálogo y reglas al editor.');
$assert(str_contains($servicio, "'i' => self::iconoEfectivo("), 'paraPos() manda el icono ya resuelto.');
$editor = (string) file_get_contents($root . '/src/js/admin/menu/variantes.js');
$assert(str_contains($editor, 'datos.iconos.reglas') && !str_contains($editor, "'op-leche'"), 'El editor usa las reglas del servidor en vez de repetirlas.');

echo "Iconos de variantes: {$assertions} comprobaciones OK\n";
