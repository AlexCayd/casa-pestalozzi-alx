<?php
declare(strict_types=1);

/**
 * Regresiones aisladas de texto canónico y reportes locales de Restaurante.
 * El runner de 3D.3 la invoca tras crear su base desechable y las dos ofertas.
 */
function probarReportes3D3(mysqli $db, int $rest, int $pan, array $offerRest, array $offerPan, int &$count): void
{
    $assert = static function (bool $condition, string $message) use (&$count): void {
        if (!$condition) throw new RuntimeException($message);
        $count++;
    };
    $insert = static function (string $sql, string $types, array $values) use ($db): int {
        $stmt = $db->prepare($sql);
        if ($types !== '') {
            $refs = [$types];
            foreach ($values as &$value) $refs[] = &$value;
            unset($value);
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();
        return $id;
    };
    $date = date('Y-m-d');
    $suffix = bin2hex(random_bytes(5));
    $actor = \FixtureRestaurante3D::usuario($db, 'reportes-' . $suffix, [
        'analytics' => 'ver', 'ia' => 'ver', 'finanzas' => 'ver', 'inventario' => 'ver',
    ]);
    \restaurante3d3Login((int)$actor['id']);

    // El runner ya vendió su oferta sin costo conocido. Este caso requiere
    // otra oferta: mezclar ambas líneas excluiría correctamente toda la
    // oferta de ingeniería al no conocerse su costo histórico completo.
    $historicalName='Fixture costo histórico '.$suffix;
    $historicalProduct=$insert('INSERT INTO productos(nombre,descripcion) VALUES (?,?)','ss',[$historicalName,'Fixture independiente de snapshots históricos']);
    $historicalOffer=$insert('INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo) VALUES (?,?,?,17.25,?,1)','iiii',[$historicalProduct,$rest,(int)$offerRest['categoria_id'],(int)$offerRest['area_id']]);
    $offerRest=array_merge($offerRest,['id'=>$historicalOffer,'producto_id'=>$historicalProduct,'nombre'=>$historicalName]);

    $ticketRest = $insert("INSERT INTO tickets(unidad_id,unidad_tipo,comensales,nombre,mesero_id,estado,hora_apertura,hora_cierre,closed_at) VALUES (?,'negocio',2,?,?,'cerrado',?,?,?)", 'isisss', [$rest, 'Fixture reportes Rest ' . $suffix, (int)$actor['id'], $date . ' 09:00:00', $date . ' 18:10:00', $date . ' 18:10:00']);
    $ticketPan = $insert("INSERT INTO tickets(unidad_id,unidad_tipo,comensales,nombre,mesero_id,estado,hora_apertura,hora_cierre,closed_at) VALUES (?,'negocio',2,?,?,'cerrado',?,?,?)", 'isisss', [$pan, 'Fixture reportes Pan ' . $suffix, (int)$actor['id'], $date . ' 09:00:00', $date . ' 18:10:00', $date . ' 18:10:00']);
    $lineRest = $insert('INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,cantidad,costo_unitario,estado,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,3,4.2500,\'entregado\',?)', 'iiiisssiiss', [$rest, $ticketRest, (int)$offerRest['id'], (int)$offerRest['producto_id'], (string)$offerRest['nombre'], '17.25', (string)$offerRest['categoria'], (int)$offerRest['categoria_id'], (int)$offerRest['area_id'], (string)$offerRest['area_nombre'], $date . ' 10:00:00']);
    $insert('INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,cantidad,costo_unitario,estado,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,NULL,\'cancelado\',?)', 'iiiisssiiss', [$rest, $ticketRest, (int)$offerRest['id'], (int)$offerRest['producto_id'], (string)$offerRest['nombre'], '17.25', (string)$offerRest['categoria'], (int)$offerRest['categoria_id'], (int)$offerRest['area_id'], (string)$offerRest['area_nombre'], $date . ' 10:15:00']);
    $insert('INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,cantidad,costo_unitario,estado,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,2,NULL,\'entregado\',?)', 'iiiisssiiss', [$pan, $ticketPan, (int)$offerPan['id'], (int)$offerPan['producto_id'], (string)$offerPan['nombre'], '17.25', (string)$offerPan['categoria'], (int)$offerPan['categoria_id'], (int)$offerPan['area_id'], (string)$offerPan['area_nombre'], $date . ' 10:00:00']);

    $unknownOfferStmt = $db->prepare('SELECT o.id,o.producto_id,p.nombre,o.precio,c.nombre categoria,o.categoria_id,o.area_id,a.nombre area_nombre FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id JOIN areas_produccion a ON a.id=o.area_id AND a.unidad_id=o.unidad_id WHERE o.unidad_id=? AND o.activo=1 AND o.id<>? LIMIT 1');
    $unknownOfferStmt->execute([$rest, (int)$offerRest['id']]);
    $unknownOffer = $unknownOfferStmt->get_result()->fetch_assoc();
    if (!$unknownOffer) throw new RuntimeException('El fixture de reportes requiere otra oferta Restaurante para costo ausente.');
    $unknownLine = $insert('INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,cantidad,costo_unitario,estado,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,20,NULL,\'entregado\',?)', 'iiiisssiiss', [$rest, $ticketRest, (int)$unknownOffer['id'], (int)$unknownOffer['producto_id'], (string)$unknownOffer['nombre'], (string)$unknownOffer['precio'], (string)$unknownOffer['categoria'], (int)$unknownOffer['categoria_id'], (int)$unknownOffer['area_id'], (string)$unknownOffer['area_nombre'], $date . ' 15:00:00']);

    $query = static fn(string $intent, ?int $id = null, ?string $period = 'hoy'): array => \Services\Security\ContextoNegocio::en('restaurante', static fn() => \Services\Analytics\ConsultasRapidas::responderEstructurada($intent, $id, null, $period));
    $all = $query('ventas_total');
    $allJson = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $sqlTotal = $db->prepare("SELECT COALESCE(SUM(ti.precio*ti.cantidad),0) FROM tickets t JOIN ticket_items ti ON ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id WHERE t.unidad_id=? AND t.estado='cerrado' AND ti.estado<>'cancelado' AND t.hora_cierre>=? AND t.hora_cierre<=?");
    $sqlTotal->execute([$rest, $date . ' 00:00:00', $date . ' 23:59:59']);
    $expectedTotal = (float)$sqlTotal->get_result()->fetch_row()[0];
    $assert(($all['ok'] ?? false) === true && abs((float)str_replace([',', '$'], '', (string)($all['respuesta']['cifra'] ?? '0')) - $expectedTotal) < 0.01,
        'Ventas totales coinciden con el agregado de ticket_items limitado a Restaurante.');
    $assert(!str_contains($allJson, (string)$offerPan['nombre']), 'Ventas totales no exponen la oferta Panadería.');

    $category = $query('ventas_categoria', (int)$offerRest['categoria_id']);
    $categoryJson = json_encode($category, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $assert(($category['respuesta']['tipo'] ?? '') === 'ventas_categoria' && str_contains($categoryJson, (string)$offerRest['nombre']), 'Ventas por categoría conservan el desglose por oferta.');
    $assert(!str_contains($categoryJson, (string)$offerPan['nombre']), 'Ventas por categoría no mezclan ofertas Panadería.');

    $top = \Services\Security\ContextoNegocio::en('restaurante', static fn() => \Services\Analytics\ConsultasRapidas::responder('lo más vendido a las 10'));
    $topJson = json_encode($top, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $assert(($top['interpretacion']['intencion'] ?? '') === 'top' && str_contains($topJson, (string)$offerRest['nombre']), 'El texto canónico conserva intención top y filtro de las 10.');
    $assert(!str_contains($topJson, (string)$offerPan['nombre']), 'Top por hora limita sus JOIN al Restaurante.');
    $assert(!str_contains($topJson, (string)$unknownOffer['nombre']), 'Filtro de las 10 excluye la oferta con 20 unidades pedida a las 15.');
    $assert(isset($top['periodo']['horario']['desde'], $top['periodo']['horario']['hasta']), 'La interpretación devuelve la hora reconocida para round-trip canónico.');
    $roundTrip = \Services\Security\ContextoNegocio::en('restaurante', static fn() => \Services\Analytics\ConsultasRapidas::responder((string)$top['interpretacion']['texto']));
    $assert(($roundTrip['interpretacion']['intencion'] ?? '') === 'top'
        && ($roundTrip['interpretacion']['periodo'] ?? '') === ($top['interpretacion']['periodo'] ?? null)
        && ($roundTrip['periodo']['horario']['desde'] ?? null) === 10,
        'El texto canónico de respuesta vuelve a la intención, periodo y filtro horario originales.');

    $noSales = $query('sin_venta');
    $noSalesJson = json_encode($noSales, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $assert(($noSales['respuesta']['tipo'] ?? '') === 'sin_venta', 'La consulta de ofertas sin venta permanece disponible.');
    $assert(!str_contains($noSalesJson, (string)$offerPan['nombre']), 'Ofertas sin venta excluye el catálogo Panadería.');

    $waiters = $query('meseros');
    $waitersJson = json_encode($waiters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $waiterNameResult = $db->query('SELECT nombre FROM usuarios WHERE id=' . (int)$actor['id'])->fetch_row();
    $assert(($waiters['respuesta']['tipo'] ?? '') === 'meseros' && str_contains($waitersJson, (string)($waiterNameResult[0] ?? '')),
        'Ventas por mesero muestra el actor local con permiso de lectura.');
    $assert(!str_contains($waitersJson, (string)$offerPan['nombre']), 'Ventas por mesero excluye líneas ajenas.');

    $cancelled = $query('cancelaciones');
    $cancelledJson = json_encode($cancelled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $assert(($cancelled['respuesta']['tipo'] ?? '') === 'cancelaciones' && str_contains($cancelledJson, (string)$offerRest['nombre']), 'Cancelaciones reporta el snapshot de la oferta local.');
    $assert(!str_contains($cancelledJson, (string)$offerPan['nombre']), 'Cancelaciones no lee la unidad Panadería.');

    $stockName = 'Fixture consumo Rest ' . $suffix;
    $stockItem = $insert('INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?,\'g\',1.2500,1)', 's', [$stockName]);
    $warehouse = $insert('INSERT INTO almacenes(unidad_id,nombre) VALUES (?,?)', 'is', [$rest, 'Fixture almacén Rest ' . $suffix]);
    $warehousePan = $insert('INSERT INTO almacenes(unidad_id,nombre) VALUES (?,?)', 'is', [$pan, 'Fixture almacén Pan ' . $suffix]);
    $db->query("INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo) VALUES ($warehouse,$stockItem,$rest,8,2)");
    $db->query("INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo) VALUES ($warehousePan,$stockItem,$pan,100,1)");
    $opId = $insert("INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (?,'ajuste','usuario',?,'qa3d3',?,?,?)", 'iisss', [$rest, (int)$actor['id'], 'stock-' . $suffix, hash('sha256', $suffix), 'stock-' . $suffix]);
    $opPan = $insert("INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia) VALUES (?,'ajuste','usuario',?,'qa3d3',?,?,?)", 'iisss', [$pan, (int)$actor['id'], 'stock-pan-' . $suffix, hash('sha256', $suffix . '-pan'), 'stock-pan-' . $suffix]);
    $insert("INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,costo_unitario,fecha) VALUES (?, ?, ?, ?, 'entrada', 8, 1.2500, ?)", 'iiiis', [$opId, $rest, $warehouse, $stockItem, $date . ' 09:00:00']);
    $insert("INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,costo_unitario,fecha) VALUES (?, ?, ?, ?, 'merma', -1, NULL, ?)", 'iiiis', [$opId, $rest, $warehouse, $stockItem, $date . ' 09:30:00']);
    $insert("INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,costo_unitario,fecha) VALUES (?, ?, ?, ?, 'merma', -6, 1.2500, ?)", 'iiiis', [$opPan, $pan, $warehousePan, $stockItem, $date . ' 09:30:00']);
    $db->query("UPDATE almacen_articulos SET saldo=7 WHERE almacen_id=$warehouse AND articulo_id=$stockItem AND unidad_id=$rest");
    $stock = $query('stock', $stockItem);
    $stockJson = json_encode($stock, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $assert(($stock['respuesta']['tipo'] ?? '') === 'stock' && str_contains($stockJson, $stockName)
        && abs((float)($stock['respuesta']['cifra'] ?? 0) - 7.0) < 0.001, 'Existencia consulta saldo físico local y excluye las 100 unidades de Panadería.');
    $assert(!str_contains($stockJson, (string)$offerPan['nombre']), 'Existencias no consultan otras unidades.');
    $summaryMethod = new ReflectionMethod(\Controllers\AdminInventarioController::class, 'resumenNuevo');
    $summaryMethod->setAccessible(true);
    $summaryBefore = \Services\Security\ContextoNegocio::en('restaurante', static fn() => $summaryMethod->invoke(null, $date, $date));
    $saleMovement = $insert("INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,costo_unitario,fecha) VALUES (?, ?, ?, ?, 'venta', -3, 1.2500, ?)", 'iiiis', [$opId, $rest, $warehouse, $stockItem, $date . ' 10:00:00']);
    $insert("INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,costo_unitario,movimiento_origen_id,fecha) VALUES (?, ?, ?, ?, 'cancelacion', 3, 1.2500, ?, ?)", 'iiiiis', [$opId, $rest, $warehouse, $stockItem, $saleMovement, $date . ' 10:05:00']);
    $consumption = $query('consumo', $stockItem);
    $assert(($consumption['respuesta']['tipo'] ?? '') === 'consumo' && abs((float)($consumption['respuesta']['cifra'] ?? -1)) < 0.001,
        'Consumo de ventas resta la cancelación original y conserva cero neto aunque exista merma.');
    $assert(str_contains((string)($consumption['respuesta']['frase'] ?? ''), '1.000 g de merma')
        && !str_contains((string)($consumption['respuesta']['frase'] ?? ''), '6.000'),
        'Merma se informa aparte y excluye las pérdidas de Panadería.');
    $summaryAfter = \Services\Security\ContextoNegocio::en('restaurante', static fn() => $summaryMethod->invoke(null, $date, $date));
    $assert(abs((float)$summaryBefore[0]['valor'] - (float)$summaryAfter[0]['valor']) < 0.0001,
        'Resumen de inventario compensa el costo de la venta cancelada con el movimiento original.');
    $assert($summaryAfter[0]['mermaValor'] === $summaryBefore[0]['mermaValor'],
        'La cancelación no cambia la merma ni imputa un costo cuando su snapshot es NULL.');

    $warehouseOverflow = $insert('INSERT INTO almacenes(unidad_id,nombre) VALUES (?,?)', 'is', [$rest, 'Fixture almacén sobrestock ' . $suffix]);
    $db->query("INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo) VALUES ($warehouseOverflow,$stockItem,$rest,100,1)");
    $db->query("UPDATE almacen_articulos SET saldo=0 WHERE almacen_id=$warehouse AND articulo_id=$stockItem AND unidad_id=$rest");
    $lowStock = $query('bajo_stock');
    $lowStockJson = json_encode($lowStock, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $assert(str_contains($lowStockJson, $stockName),
        'Almacén sin existencias conserva la alerta aunque otro almacén local tenga sobrestock.');
    $physicalStock = $query('stock', $stockItem);
    $assert(($physicalStock['respuesta']['estado'] ?? '') === 'bajo',
        'Detalle de stock usa la alerta física por asociación, sin ocultarla con el saldo agregado.');
    $db->query("UPDATE almacen_articulos SET saldo=7 WHERE almacen_id=$warehouse AND articulo_id=$stockItem AND unidad_id=$rest");

    $recipeId = $insert("INSERT INTO recetas(oferta_id,unidad_id,unidad_tipo,version,activo) VALUES (?,?,'negocio',1,1)", 'ii', [(int)$offerRest['id'], $rest]);
    $insert('INSERT INTO receta_componentes(receta_id,unidad_id,articulo_id,cantidad) VALUES (?,?,?,10)', 'iii', [$recipeId, $rest, $stockItem]);
    $db->query('UPDATE ingredientes SET costo=99.0000 WHERE id=' . $stockItem);
    $engineering = \Services\Security\ContextoNegocio::en('restaurante', static fn() => \Services\Analytics\Analiticas::nivel1($date, $date));
    $frozen = null;
    foreach (($engineering['ingenieria']['items'] ?? []) as $row) {
        if ((string)($row['nombre'] ?? '') === (string)$offerRest['nombre']) $frozen = $row;
    }
    $assert($frozen !== null && abs((float)$frozen['costo'] - 4.25) < 0.0001,
        'Analítica conserva costo_unitario capturado en ticket_items aunque cambie el costo actual del ingrediente.');
    $engineeringJson = json_encode($engineering['ingenieria']['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $assert(!str_contains($engineeringJson, (string)$unknownOffer['nombre']), 'Analítica omite la línea cuyo costo snapshot está ausente en vez de imputar costo actual.');
    $financeMethod = new ReflectionMethod(\Controllers\AdminFinanzasController::class, 'agregadosRestaurante');
    $financeMethod->setAccessible(true);
    $finance = \Services\Security\ContextoNegocio::en('restaurante', static fn() => $financeMethod->invoke(null, $db, $rest, $date, $date));
    $assert((float)($finance['sinCosto'] ?? -1) >= (float)$unknownOffer['precio'],
        'Finanzas conserva importe separado de venta sin costo histórico conocido.');
    $assert(abs((float)($finance['cogs'] ?? -1) - 12.75) < 0.01,
        'COGS suma solo costos históricos presentes y no vuelve a calcular receta actual.');

    $editor = \FixtureRestaurante3D::usuario($db,'proveedores-'.$suffix,['inventario'=>'editar']);
    \restaurante3d3Login((int)$editor['id']);
    $localProvider=$insert('INSERT INTO proveedores(unidad_id,nombre,activo) VALUES (?,?,1)','is',[$rest,'Proveedor Rest '.$suffix]);
    $foreignProvider=$insert('INSERT INTO proveedores(unidad_id,nombre,activo) VALUES (?,?,1)','is',[$pan,'Proveedor Pan '.$suffix]);
    $assign=static fn(array $rows): bool => \Services\Security\ContextoNegocio::en('restaurante',static fn()=>\Services\Inventory\Proveedores::asignarAIngrediente($stockItem,$rows));
    $assert($assign([['proveedor_id'=>$localProvider,'costo'=>'2.5000','preferente'=>true]]),'Asignación local informa éxito después de confirmar la transacción.');
    $before=$db->query("SELECT * FROM ingrediente_proveedores WHERE unidad_id=$rest AND ingrediente_id=$stockItem")->fetch_all(MYSQLI_ASSOC);
    $assert(!$assign([['proveedor_id'=>$foreignProvider,'costo'=>'3.0000']]),'Proveedor ajeno se rechaza y no informa éxito después del rollback.');
    $after=$db->query("SELECT * FROM ingrediente_proveedores WHERE unidad_id=$rest AND ingrediente_id=$stockItem")->fetch_all(MYSQLI_ASSOC);
    $assert($before===$after,'La asignación inválida conserva íntegra la lista anterior.');
}
