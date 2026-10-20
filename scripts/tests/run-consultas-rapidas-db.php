<?php

declare(strict_types=1);

/*
 * Contrato de las consultas rápidas contra la base de desarrollo.
 *
 * Sólo lee. Comprueba lo que consumen la barra del panel y el Asistente: que
 * toda respuesta traiga `entendida`, `interpretacion` y `plantilla`; que la
 * consulta estructurada dé lo mismo que la frase; que el texto canónico que la
 * barra muestra tras cambiar un hueco lo vuelva a entender el intérprete
 * igual; y que lo no entendido salga como dato, con sugerencias.
 *
 *   php scripts/tests/run-consultas-rapidas-db.php
 */

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\Analytics\ConsultasRapidas as C;

$db = ActiveRecord::getDB();
$assertions = 0;
$assert = static function (bool $condicion, string $mensaje) use (&$assertions): void {
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
    $assertions++;
};
$uno = static fn (string $sql) => ($r = $db->query($sql)) ? $r->fetch_row()[0] ?? null : null;

$productoId = (int) $uno('SELECT id FROM productos WHERE activo = 1 ORDER BY id LIMIT 1');
$categoriaId = (int) $uno('SELECT id FROM categorias WHERE activo = 1 ORDER BY id LIMIT 1');
$ingredienteId = (int) $uno('SELECT id FROM ingredientes ORDER BY id LIMIT 1');
if ($productoId === 0 || $categoriaId === 0 || $ingredienteId === 0) {
    echo "SKIP: la base no tiene catálogo (carga deploy.sql).\n";
    exit(0);
}
$opcionId = (int) $uno(
    "SELECT v.id FROM producto_variantes v JOIN productos p ON p.id = v.producto_id
      WHERE v.activo = 1 AND v.predeterminada = 0 AND p.activo = 1 ORDER BY v.id LIMIT 1"
);
$opcionProducto = $opcionId > 0 ? (int) $uno("SELECT producto_id FROM producto_variantes WHERE id = {$opcionId}") : 0;

$forma = static function (array $r, string $caso) use ($assert): void {
    foreach (['ok', 'entendida', 'consulta', 'periodo', 'interpretacion', 'plantilla', 'respuesta', 'alternativas', 'sugerencias'] as $k) {
        $assert(array_key_exists($k, $r), "{$caso}: falta la clave «{$k}»");
    }
    foreach ($r['plantilla'] as $p) {
        $assert(in_array($p['tipo'], ['texto', 'hueco'], true), "{$caso}: parte de plantilla sin tipo válido");
        if ($p['tipo'] === 'hueco') {
            foreach (['campo', 'rotulo', 'valor', 'etiqueta'] as $k) {
                $assert(array_key_exists($k, $p), "{$caso}: hueco sin «{$k}»");
            }
            $assert(isset($p['catalogo']) || isset($p['opciones']), "{$caso}: hueco sin catálogo ni opciones");
            foreach ($p['opciones'] ?? [] as $o) {
                $assert(isset($o['valor'], $o['etiqueta']) && is_array($o['consulta']), "{$caso}: opción de hueco incompleta");
            }
        }
    }
};

// 1. Cada intención por la vía estructurada, y su ida y vuelta por texto.
$casos = [
    ['ventas_producto', $productoId, null, 'ayer'],
    ['ventas_producto', $opcionProducto ?: $productoId, $opcionId ?: null, 'semana_pasada'],
    ['ventas_categoria', $categoriaId, null, '14d'],
    ['ventas_total', null, null, 'mes_pasado'],
    ['top', null, null, '7d'],
    ['stock', $ingredienteId, null, null],
    ['bajo_stock', null, null, null],
    ['ticket_promedio', null, null, 'semana_pasada'],
    ['sin_venta', null, null, '30d'],
    ['meseros', null, null, 'mes'],
    ['horas_pico', null, null, '7d'],
    ['cancelaciones', null, null, '14d'],
    ['consumo', $ingredienteId, null, 'semana'],
];
foreach (['ticket_promedio', 'sin_venta', 'meseros', 'horas_pico', 'cancelaciones'] as $sinCosa) {
    foreach (array_keys(C::PERIODOS) as $p) {
        $casos[] = [$sinCosa, null, null, $p];
    }
}
foreach ($casos as [$intencion, $id, $opcion, $periodo]) {
    $r = C::responderEstructurada($intencion, $id, $opcion, $periodo);
    $caso = "estructurada {$intencion}";
    $forma($r, $caso);
    $assert($r['ok'] === true && $r['entendida'] === true, "{$caso}: debió entenderse");
    $assert($r['respuesta']['tipo'] === $intencion, "{$caso}: el tipo de respuesta debe ser la intención");
    $i = $r['interpretacion'];
    $assert($i['intencion'] === $intencion && $i['id'] === $id && $i['opcion'] === $opcion, "{$caso}: la interpretación no devuelve lo pedido");
    if ($periodo !== null) {
        $assert($i['periodo'] === $periodo, "{$caso}: el periodo no se respetó");
    }
    // El texto canónico vuelve a la misma consulta. Un nombre de catálogo que
    // el intérprete confunda no es un fallo del contrato, sólo se avisa.
    $vuelta = C::responder($i['texto'], 'todo')['interpretacion'];
    if ($vuelta['intencion'] !== $i['intencion'] || $vuelta['id'] !== $i['id'] || $vuelta['periodo'] !== $i['periodo'] || $vuelta['opcion'] !== $i['opcion']) {
        $assert(in_array($intencion, ['ventas_producto', 'ventas_categoria', 'stock', 'consumo'], true), "{$caso}: «{$i['texto']}» no vuelve a la misma consulta");
        echo "AVISO: «{$i['texto']}» se reinterpreta distinto (nombre ambiguo en el catálogo).\n";
    }

    // Sin texto libre: ningún hueco ofrece mandar una frase al intérprete y
    // ningún renglón trae una frase en vez de su consulta.
    foreach ($r['plantilla'] as $p) {
        $assert(!array_key_exists('pregunta', $p), "{$caso}: un hueco todavía trae «pregunta» (texto libre)");
    }
    foreach (array_merge($r['respuesta']['detalle'] ?? [], $r['alternativas']) as $d) {
        $assert(!array_key_exists('q', $d), "{$caso}: un renglón o alternativa todavía trae «q» (texto libre)");
        if (isset($d['consulta'])) {
            $assert(in_array($d['consulta']['intencion'] ?? '', C::INTENCIONES, true), "{$caso}: renglón con una consulta que no existe");
        }
        if (is_float($d['valor'] ?? null) || is_int($d['valor'] ?? null)) {
            // Un valor numérico mide la barra; si no es un conteo, se lee por
            // su valor_texto.
            $assert(!isset($d['valor_texto']) || is_string($d['valor_texto']), "{$caso}: valor_texto debe ser texto");
        }
    }
    // La comparación dice dirección y lectura por separado.
    if (!empty($r['respuesta']['comparacion'])) {
        $cmp = $r['respuesta']['comparacion'];
        $assert(in_array($cmp['tendencia'], ['sube', 'baja', 'igual'], true), "{$caso}: tendencia inválida");
        $assert(in_array($cmp['lectura'] ?? '', ['bien', 'mal', 'igual'], true), "{$caso}: falta la lectura de la comparación");
        if (in_array($intencion, ['sin_venta', 'cancelaciones'], true) && $cmp['tendencia'] !== 'igual') {
            $assert($cmp['lectura'] === ($cmp['tendencia'] === 'sube' ? 'mal' : 'bien'), "{$caso}: más {$intencion} debe leerse como peor");
        }
        if (in_array($intencion, ['consumo', 'horas_pico'], true)) {
            $assert($cmp['lectura'] === 'igual', "{$caso}: {$intencion} no es mejor ni peor");
        }
    }
}

// 1b. Las cuentas de las intenciones nuevas, contra SQL escrito aparte. Mismo
// criterio de venta que el panel: tickets cerrados, sin renglones cancelados,
// por COALESCE(hora_cierre, hora_apertura).
$p = C::periodo('30d');
$rango = "COALESCE(t.hora_cierre, t.hora_apertura) BETWEEN '{$p['desde']} 00:00:00' AND '{$p['hasta']} 23:59:59'";
$ticketsVivos = (int) $uno(
    "SELECT COUNT(DISTINCT t.id) FROM tickets t JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.estado <> 'cancelado'
      WHERE t.estado = 'cerrado' AND {$rango}"
);
$total = C::responderEstructurada('ventas_total', null, null, '30d')['respuesta'];
$promedio = C::responderEstructurada('ticket_promedio', null, null, '30d')['respuesta'];
if ($ticketsVivos > 0) {
    $assert($promedio['detalle'][1]['valor'] === number_format($ticketsVivos), 'ticket promedio: no cuenta los mismos tickets que ventas totales');
    $assert(str_contains($total['frase'], $promedio['cifra']), 'ticket promedio: la cifra no es el promedio que dicen las ventas totales');

    $pico = C::responderEstructurada('horas_pico', null, null, '30d')['respuesta'];
    $assert(array_sum(array_column($pico['detalle'], 'valor')) === $ticketsVivos, 'horas pico: las horas no suman los tickets del periodo');
    $assert(preg_match('/^\d{2}:00$/', (string) $pico['cifra']) === 1, 'horas pico: la cifra debe ser una hora');

    $meseros = C::responderEstructurada('meseros', null, null, '30d')['respuesta'];
    $importe = (float) $uno(
        "SELECT COALESCE(SUM(ti.precio * ti.cantidad), 0) FROM tickets t JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.estado <> 'cancelado'
          WHERE t.estado = 'cerrado' AND {$rango}"
    );
    $assert(str_contains($meseros['frase'], '$' . number_format($importe, 2)), 'meseros: «entre todos» no es la venta del periodo');
}
$cancelados = (int) $uno(
    "SELECT COALESCE(SUM(ti.cantidad), 0) FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
      WHERE ti.estado = 'cancelado' AND t.estado = 'cerrado' AND {$rango}"
);
$assert(C::responderEstructurada('cancelaciones', null, null, '30d')['respuesta']['cifra'] === $cancelados, 'cancelaciones: la cifra no cuadra con SQL');

$sinVenta = C::responderEstructurada('sin_venta', null, null, '30d')['respuesta'];
$enCarta = (int) $uno('SELECT COUNT(*) FROM productos p JOIN categorias c ON c.id = p.categoria_id WHERE p.activo = 1 AND c.activo = 1');
$vendidos = (int) $uno(
    "SELECT COUNT(DISTINCT p.id) FROM productos p JOIN categorias c ON c.id = p.categoria_id
       JOIN ticket_items ti ON ti.nombre = p.nombre JOIN tickets t ON t.id = ti.ticket_id
      WHERE p.activo = 1 AND c.activo = 1 AND t.estado = 'cerrado' AND ti.estado <> 'cancelado' AND {$rango}"
);
$assert($sinVenta['cifra'] === $enCarta - $vendidos, 'sin venta: carta menos vendidos no cuadra');
$assert(count($sinVenta['detalle']) <= 10, 'sin venta: el desglose pasa de diez renglones');

// El consumo es lo que se descontó por ventas (movimientos), no una receta
// vuelta a explotar.
$s = C::periodo('semana');
$consumo = C::responderEstructurada('consumo', $ingredienteId, null, 'semana');
$neto = max(0.0, (float) $uno(
    "SELECT COALESCE(-SUM(cantidad), 0) FROM movimientos_inventario WHERE ingrediente_id = {$ingredienteId}
        AND tipo IN ('venta', 'cancelacion') AND created_at BETWEEN '{$s['desde']} 00:00:00' AND '{$s['hasta']} 23:59:59'"
));
$unidad = (string) $uno("SELECT unidad FROM ingredientes WHERE id = {$ingredienteId}");
$esperada = in_array($unidad, ['ml', 'g'], true) && $neto >= 1000 ? round($neto / 1000, 2) : round($neto, 2);
$assert(abs((float) str_replace(',', '', (string) $consumo['respuesta']['cifra']) - $esperada) < 0.011, 'consumo: no cuadra con los movimientos de venta');
$huecoConsumo = array_values(array_filter($consumo['plantilla'], static fn ($h) => ($h['campo'] ?? '') === 'entidad'))[0] ?? null;
$assert($huecoConsumo !== null && ($huecoConsumo['intencion'] ?? '') === 'consumo' && $huecoConsumo['catalogo'] === 'ingredientes',
    'consumo: el hueco del ingrediente debe fijar su intención (si no, elegir otro pregunta por la existencia)');

// 2. Frases: lo entendido trae plantilla con huecos; lo que no, sugerencias.
$frases = [
    'lo más vendido esta semana' => 'top',
    '¿qué está por agotarse?' => 'bajo_stock',
    'cuánto vendimos ayer' => 'ventas_total',
    'ticket promedio de ayer' => 'ticket_promedio',
    '¿cuántos comensales llegaron hoy?' => 'ticket_promedio',
    'platillos sin venta este mes' => 'sin_venta',
    'qué no se vendió la semana pasada' => 'sin_venta',
    'ventas por mesero esta semana' => 'meseros',
    'horas pico del mes' => 'horas_pico',
    '¿a qué hora llega más gente?' => 'horas_pico',
    'cancelaciones de ayer' => 'cancelaciones',
];
foreach ($frases as $frase => $tipo) {
    $r = C::responder($frase, 'ventas');
    $forma($r, "frase «{$frase}»");
    $assert($r['entendida'] === true && $r['respuesta']['tipo'] === $tipo, "frase «{$frase}»: esperaba {$tipo}");
}
foreach (['hola', 'gracias', 'xqzwv'] as $frase) {
    $r = C::responder($frase, 'ventas');
    $forma($r, "frase «{$frase}»");
    $assert($r['entendida'] === false, "«{$frase}» no debió entenderse");
    $assert($r['respuesta']['tipo'] === 'sin_resultado', "«{$frase}»: la respuesta debe ser sin_resultado");
    $assert($r['sugerencias'] !== [], "«{$frase}»: sin sugerencias");
    foreach ($r['sugerencias'] as $s) {
        $assert(isset($s['texto'], $s['consulta']['intencion']), "«{$frase}»: sugerencia sin consulta");
    }
}
$vacia = C::responder('', 'ventas');
$assert($vacia['entendida'] === false && $vacia['respuesta'] === null && $vacia['sugerencias'] !== [], 'la frase vacía debe devolver sugerencias');

// 3. Errores de programación y entidades perdidas.
$assert(C::responderEstructurada('nada')['ok'] === false, 'una intención desconocida es un error');
$perdido = C::responderEstructurada('ventas_producto', 99999999, null, 'hoy');
$assert($perdido['ok'] === true && $perdido['entendida'] === false, 'un platillo inexistente se contesta como no entendido');

// 4. Catálogo y capacidades.
$cat = C::catalogo();
$assert($cat['ventas'] !== [] && $cat['ingredientes'] !== [], 'el catálogo viene vacío');
foreach (array_merge($cat['ventas'], $cat['ingredientes']) as $o) {
    $assert(isset($o['valor'], $o['etiqueta'], $o['grupo'], $o['consulta']['intencion']), 'opción de catálogo incompleta');
}
$caps = C::capacidades();
$assert(array_column($caps['intenciones'], 'intencion') === C::INTENCIONES, 'capacidades debe listar todas las intenciones, en orden');
foreach ($caps['intenciones'] as $cap) {
    $assert($cap['titulo'] !== '' && $cap['plantilla'] !== [], "capacidad {$cap['intencion']} incompleta");
    if ($cap['ejemplo'] !== null) {
        $r = C::responderEstructurada($cap['ejemplo']['consulta']['intencion'], $cap['ejemplo']['consulta']['id'], null, $cap['ejemplo']['consulta']['periodo']);
        $assert($r['entendida'] === true, "el ejemplo de {$cap['intencion']} no se entiende");
    }
    // Las variantes que el Asistente arma al azar: todas de su intención,
    // todas entendidas, sin repetir combinación y la primera igual a `ejemplo`.
    $assert(is_array($cap['ejemplos'] ?? null), "capacidad {$cap['intencion']} sin lista de ejemplos");
    $vistas = [];
    foreach ($cap['ejemplos'] as $e) {
        $q = $e['consulta'];
        $assert($q['intencion'] === $cap['intencion'], "una variante de {$cap['intencion']} pregunta por {$q['intencion']}");
        $llave = json_encode($q);
        $assert(!isset($vistas[$llave]), "variante repetida en {$cap['intencion']}: {$llave}");
        $vistas[$llave] = true;
        $r = C::responderEstructurada($q['intencion'], $q['id'], null, $q['periodo']);
        $assert($r['entendida'] === true, "la variante {$llave} de {$cap['intencion']} no se entiende");
    }
    if ($cap['ejemplo'] !== null) {
        $assert($cap['ejemplos'][0]['consulta'] === $cap['ejemplo']['consulta'], "el primer ejemplo de {$cap['intencion']} no es `ejemplo`");
    }
}
$conVariantes = array_filter($caps['intenciones'], static fn (array $c): bool => count($c['ejemplos']) > 1);
$assert(count($conVariantes) >= count(C::INTENCIONES) - 2, 'casi todas las preguntas deben ofrecer más de un ejemplo');
$assert(count($caps['periodos']) === count(C::PERIODOS), 'capacidades debe listar los periodos');

// 5. «Familia» salió del vocabulario visible: es «categoría». Se sigue
// entendiendo de entrada.
$visible = json_encode([$caps, $cat['ventas'][0], array_column($cat['ventas'], 'grupo'), C::responderEstructurada('ventas_categoria', $categoriaId, null, 'mes')], JSON_UNESCAPED_UNICODE);
$assert(stripos($visible, 'familia') === false, 'el vocabulario visible todavía dice «familia»');
$titulos = array_column($caps['intenciones'], 'titulo', 'intencion');
$assert($titulos['ventas_categoria'] === 'Ventas por categoría', 'la capacidad de categoría debe llamarse «Ventas por categoría»');
$assert(in_array('Categorías', array_column($cat['ventas'], 'grupo'), true), 'el catálogo de ventas debe agrupar «Categorías»');
$nombreCategoria = (string) $uno("SELECT nombre FROM categorias WHERE id = {$categoriaId}");
foreach (['ventas de la familia ' . $nombreCategoria . ' este mes', 'ventas de la categoría ' . $nombreCategoria . ' este mes'] as $frase) {
    $i = C::responder($frase, 'ventas')['interpretacion'];
    $assert($i['intencion'] === 'ventas_categoria' && $i['id'] === $categoriaId && $i['periodo'] === 'mes', "«{$frase}» debe ser ventas de esa categoría");
}

// 6. Cada intención nueva tiene título, descripción, plantilla con periodo
// (salvo las de almacén sin fecha) y un ejemplo que se entiende.
foreach (['ticket_promedio', 'sin_venta', 'meseros', 'horas_pico', 'cancelaciones', 'consumo'] as $nueva) {
    $cap = array_values(array_filter($caps['intenciones'], static fn ($c) => $c['intencion'] === $nueva))[0] ?? null;
    $assert($cap !== null && $cap['descripcion'] !== '', "capacidad {$nueva} sin descripción");
    $assert(in_array('periodo', array_column(array_filter($cap['plantilla'], static fn ($h) => $h['tipo'] === 'hueco'), 'campo'), true), "capacidad {$nueva} sin hueco de periodo");
    $assert($cap['ejemplo'] !== null, "capacidad {$nueva} sin ejemplo");
}

echo "OK consultas rápidas: {$assertions} comprobaciones\n";
