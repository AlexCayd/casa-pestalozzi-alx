<?php
require __DIR__ . '/informes-db-bootstrap.php';

use Services\Shared\GeneradorPdf as G;
use Services\Shared\RangoPeriodo as R;
use Services\Finance\FinanzasNegocio as F;
use Services\Analytics\AnalisisNegocio as A;
use Services\Reservations\InformeReservaciones as Reservas;
use Services\Pos\HistorialTickets as T;
use Services\Inventory\ConsultasAlmacen;
use Services\Inventory\AlcanceInventario;

$db = pdfTestDb();
$ids = json_decode(file_get_contents(pdfTestTemp() . '/fixture-ids.json'), true);
$u = (int) $ids['units']['restaurante'];
$db->begin_transaction();
try {
    $o = $db->execute_query('SELECT o.*,c.nombre categoria FROM ofertas_producto o JOIN categorias c ON c.id=o.categoria_id WHERE o.unidad_id=? ORDER BY o.id LIMIT 1', [$u])->fetch_assoc();
    $inicio = new DateTimeImmutable('2004-01-01');
    $ventasEsperadas = 0.0;
    for ($i = 0; $i < 93; $i++) {
        $fecha = $inicio->modify('+' . $i . ' days')->format('Y-m-d');
        $precio = round(157.39 + ($i % 9) * 213.17, 2);
        $cantidad = $i % 4 + 1; $ventasEsperadas += $precio * $cantidad;
        $db->execute_query("INSERT INTO tickets(unidad_id,estado,comensales,hora_apertura,hora_cierre) VALUES(?,'cerrado',3,?,?)", [$u, $fecha . ' 12:00:00', $fecha . ' 13:00:00']);
        $tid = (int) $db->insert_id;
        $nombre = ['Café de especialidad y pan de temporada', 'Menú del día con ingredientes de estación', 'Platillo de prueba con un nombre amplio & acentos: piñón y café'][$i % 3];
        $db->execute_query("INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,cantidad,costo_unitario,estado,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'entregado',?)",
            [$u, $tid, $o['id'], $o['producto_id'], $nombre, $precio, $o['categoria'], $o['categoria_id'], $o['area_id'], $cantidad, 50.25, $fecha . ' 12:00:00']);
        if ($i % 5 === 0) $db->execute_query("INSERT INTO tickets(unidad_id,estado,comensales,hora_apertura,hora_cierre) VALUES(?,'cancelado',2,?,?)", [$u, $fecha . ' 11:00:00', $fecha . ' 11:30:00']);
        $estado = ['completada', 'confirmada', 'cancelada', 'no_show', 'en_curso'][$i % 5];
        for ($j = 0; $j <= $i % 3; $j++) $db->execute_query("INSERT INTO reservaciones(unidad_id,nombre,contacto_tipo,contacto,fecha,hora,comensales,origen,request_token,estado) VALUES(?,'Fixture gráfica','email','fixture@example.test',?,'13:00:00',?,'admin',?,?)", [$u, $fecha, $i % 5 + 1, bin2hex(random_bytes(16)), $estado]);
    }
    // Comparación financiera firmada: gastos fijos vigentes causan una pérdida anterior sin ventas.
    $db->execute_query("INSERT INTO gastos_fijos(unidad_id,nombre,monto,categoria) VALUES(?,'Gasto fixture gráfico',12000,'renta')", [$u]);
    foreach (['Almacén de ingredientes secos y preparaciones de temporada', 'Cámara fría para frutas, lácteos y productos delicados', 'Almacén de servicio & reposición diaria'] as $k => $nombre) {
        $db->execute_query('INSERT INTO almacenes(unidad_id,nombre,permitir_negativos) VALUES(?,?,1)', [$u, $nombre]); $w = (int) $db->insert_id;
        for ($i = 0; $i < 5 + $k * 3; $i++) {
            $db->execute_query('INSERT INTO ingredientes(nombre,unidad,costo) VALUES(?,?,2)', ['Artículo gráfico ' . $k . '/' . $i, $i % 2 ? 'kg' : 'pza']); $art = (int) $db->insert_id;
            $db->execute_query('INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo) VALUES(?,?,?,?,?)', [$w, $art, $u, $i % 3 === 0 ? -1.125 : 20, 5]);
        }
    }
    $svc = new ConsultasAlmacen($db, new AlcanceInventario($db, (int)$ids['users']['reader'], 'contrasena'));
    $ahora = new DateTimeImmutable('2026-11-11 12:00:00', new DateTimeZone('America/Mexico_City'));
    $antes = pdfTestSnapshot($db); $cantidadPdf = 0;
    foreach (['normal' => ['2004-01-01', '2004-01-07'], 'vacio' => ['1990-01-01', '1990-01-02'], 'extenso' => ['2004-01-01', '2004-12-31']] as $caso => [$desde, $hasta]) {
        $r = R::resolverInforme(['desde' => $desde, 'hasta' => $hasta]);
        $documentos = ['finanzas' => F::informe($db,$u,$r,$ahora->format('Y-m-d H:i:s')),
            'analisis' => A::informe($db,$u,$r,$ahora->format('Y-m-d H:i:s')),
            'reservaciones' => Reservas::documento($db,$u,$r,$ahora),
            'inventario' => $svc->informe('restaurante',$r,$ahora->format('Y-m-d H:i:s')),
            'tickets' => T::informe($db,$u,$r,$ahora->format('Y-m-d H:i:s'))];
        if ($caso === 'extenso') {
            pdfTestNear($documentos['finanzas']['kpis'][0]['valor'], $ventasEsperadas, 'Ventas representativas FIN');
            pdfTestNear($documentos['analisis']['kpis'][0]['valor'], $ventasEsperadas, 'Ventas representativas ANA');
            pdfTestNear($documentos['tickets']['kpis'][2]['valor'], $ventasEsperadas, 'Ventas representativas TIC');
        }
        foreach ($documentos as $modulo => $doc) {
            $pdf = G::documento($doc); $bytes = $pdf->output(); pdfTestAssert(str_starts_with($bytes,'%PDF-'), 'PDF representativo ' . $modulo);
            file_put_contents(pdfTestTemp() . '/grafica-' . $modulo . '-' . $caso . '.pdf', $bytes); $cantidadPdf++;
        }
    }
    $r = R::resolverInforme(['desde'=>'2004-01-01','hasta'=>'2004-04-02']);
    $doc = A::informe($db,$u,$r,$ahora->format('Y-m-d H:i:s'));
    pdfTestAssert(count($doc['secciones'][0]['filas']) === 14, 'Faltan los 14 tramos variados');
    pdfTestNear(array_sum(array_column($doc['secciones'][0]['filas'],1)), $ventasEsperadas, 'Paridad de los 14 tramos');
    file_put_contents(pdfTestTemp() . '/grafica-analisis-14tramos.pdf', G::documento($doc)->output()); $cantidadPdf++;
    $provisional = Reservas::documento($db,$u,R::resolverInforme(['desde'=>'2099-01-01','hasta'=>'2099-01-07']),$ahora);
    pdfTestAssert(str_contains($provisional['titulo'],'Provisional'), 'Provisionalidad perdida');
    file_put_contents(pdfTestTemp() . '/grafica-reservaciones-provisional.pdf', G::documento($provisional)->output()); $cantidadPdf++;
    pdfTestAssert($antes === pdfTestSnapshot($db), 'La generación representativa altera hechos');
    echo 'GRÁFICAS representativas PASS ' . $cantidadPdf . ' PDF: 93 ventas con centavos,14 tramos,5 normales/vacíos/366d,3 almacenes amplios,negativos,PII sintética,paridad FIN/ANA/TIC y no mutación.' . PHP_EOL;
} finally { $db->rollback(); $db->close(); }
