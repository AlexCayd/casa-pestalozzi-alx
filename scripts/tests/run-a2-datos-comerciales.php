<?php
declare(strict_types=1);

require __DIR__ . '/soporte-m12.php';

/** @return array{unidad_id:int,categoria_id:int,area_id:int} */
function a2Destino(mysqli $db, string $contexto): array
{
    $fila = $db->execute_query(
        'SELECT u.id unidad_id,c.id categoria_id,a.id area_id
         FROM unidades u
         JOIN categorias c ON c.unidad_id=u.id
         JOIN areas_produccion a ON a.unidad_id=u.id
         WHERE u.slug=? ORDER BY c.id,a.id LIMIT 1',
        [$contexto]
    )->fetch_assoc();
    if (!$fila) {
        throw new RuntimeException("Falta categoría o área para {$contexto}.");
    }
    return array_map('intval', $fila);
}

/** @return list<int> */
function a2IdsDiferentes(mysqli $db, string $campo): array
{
    $igualdad = match ($campo) {
        'nombre' => 'CAST(d.nombre AS BINARY) <=> CAST(v.nombre AS BINARY)',
        'descripcion' => 'CAST(d.descripcion AS BINARY) <=> CAST(v.descripcion AS BINARY)',
        'img' => 'CAST(d.img AS BINARY) <=> CAST(v.img AS BINARY)',
        default => throw new InvalidArgumentException('Campo no permitido.'),
    };
    $filas = $db->query(
        "SELECT v.oferta_id FROM productos_oferta v
         LEFT JOIN oferta_datos_comerciales d ON d.oferta_id=v.oferta_id
         WHERE d.oferta_id IS NULL OR NOT ({$igualdad}) ORDER BY v.oferta_id"
    )->fetch_all(MYSQLI_ASSOC);
    return array_map('intval', array_column($filas, 'oferta_id'));
}

function a2Paridad(mysqli $db, callable $afirmar, string $operacion): void
{
    foreach (['nombre', 'descripcion', 'img'] as $campo) {
        $ids = a2IdsDiferentes($db, $campo);
        $afirmar($ids === [], "{$operacion}: {$campo} difiere de productos_oferta en ofertas " . implode(',', $ids));
    }
    $huerfanas = array_map('intval', array_column($db->query(
        'SELECT d.oferta_id FROM oferta_datos_comerciales d
         LEFT JOIN ofertas_producto o ON o.id=d.oferta_id WHERE o.id IS NULL ORDER BY d.oferta_id'
    )->fetch_all(MYSQLI_ASSOC), 'oferta_id'));
    $afirmar($huerfanas === [], "{$operacion}: filas huérfanas en ofertas " . implode(',', $huerfanas));
}

/** @return array{resultado:array,producto_id:int,oferta_id:int} */
function a2CrearProducto(string $contexto, string $nombre, string $descripcion, ?string $img, array $destino): array
{
    $producto = new Model\Producto();
    $producto->sincronizar([
        'nombre' => $nombre,
        'descripcion' => $descripcion,
        'precio' => '28.50',
        'categoria_id' => $destino['categoria_id'],
        'area_id' => $destino['area_id'],
        'activo' => 1,
    ]);
    $producto->img = $img;
    $resultado = Services\Security\ContextoNegocio::en($contexto, static fn() => $producto->crear());
    $ofertaId = (int) ($resultado['id'] ?? 0);
    $relacion = $ofertaId > 0
        ? Model\ActiveRecord::getDB()->execute_query('SELECT producto_id FROM ofertas_producto WHERE id=?', [$ofertaId])->fetch_row()
        : null;
    return [
        'resultado' => $resultado,
        'producto_id' => (int) ($relacion[0] ?? 0),
        'oferta_id' => $ofertaId,
    ];
}

m12Pruebas(static function (mysqli $db, string $root, callable $afirmar): void {
    Multinegocio\SqlArchivo::ejecutar($db, $root . '/database/actualizaciones/a1-datos-comerciales-oferta.sql');
    a2Paridad($db, $afirmar, 'Backfill A1 inicial');

    $superadminId = (int) $_SESSION['id'];
    $sufijo = bin2hex(random_bytes(5));
    $rest = a2Destino($db, 'restaurante');
    $pan = a2Destino($db, 'panaderia');
    $maxProductoInicial = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM productos')->fetch_row()[0];
    $maxOfertaInicial = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM ofertas_producto')->fetch_row()[0];
    $relacionesIniciales = array_map(static fn(array $fila): array => [
        'id' => (int) $fila['id'], 'producto_id' => (int) $fila['producto_id'],
    ], $db->query(
        'SELECT id,producto_id FROM ofertas_producto WHERE id<= ' . $maxOfertaInicial . ' ORDER BY id'
    )->fetch_all(MYSQLI_ASSOC));
    $ticketsIniciales = $db->query(
        'SELECT ti.id,ti.ticket_id,ti.unidad_id,ti.oferta_id,o.producto_id
         FROM ticket_items ti JOIN ofertas_producto o ON o.id=ti.oferta_id AND o.unidad_id=ti.unidad_id ORDER BY ti.id'
    )->fetch_all(MYSQLI_ASSOC);

    // Alta en ambos negocios materializa la oferta antes del commit de cada alta.
    $altaRest = a2CrearProducto('restaurante', 'A2 Restaurante ' . $sufijo, 'Descripción original Restaurante', 'build/images/a2-rest-' . $sufijo . '.webp', $rest);
    $afirmar(!empty($altaRest['resultado']['resultado']) && $altaRest['producto_id'] > 0 && $altaRest['oferta_id'] > 0, 'Crear producto y oferta en Restaurante.');
    a2Paridad($db, $afirmar, 'Alta Restaurante');

    $altaPan = a2CrearProducto('panaderia', 'A2 Panadería ' . $sufijo, 'Descripción original Panadería', null, $pan);
    $afirmar(!empty($altaPan['resultado']['resultado']) && $altaPan['producto_id'] > 0 && $altaPan['oferta_id'] > 0, 'Crear producto y oferta en Panadería con imagen NULL.');
    a2Paridad($db, $afirmar, 'Alta Panadería');

    $asociacion = Services\Menu\Ofertas::crear($altaRest['producto_id'], 'panaderia', [
        'categoria_id' => $pan['categoria_id'], 'area_id' => $pan['area_id'], 'precio' => '28.50', 'activo' => 1,
    ]);
    $ofertaAsociada = (int) ($asociacion['oferta_id'] ?? 0);
    $afirmar(!empty($asociacion['confirmado']) && $ofertaAsociada > 0, 'Asociar identidad existente a Panadería.');
    $relacion = $db->execute_query('SELECT producto_id,unidad_id FROM ofertas_producto WHERE id=?', [$ofertaAsociada])->fetch_assoc();
    $afirmar($relacion !== null && (int) $relacion['producto_id'] === $altaRest['producto_id'] && (int) $relacion['unidad_id'] === $pan['unidad_id'], 'La oferta asociada conserva producto_id y su negocio.');
    a2Paridad($db, $afirmar, 'Asociación Restaurante-Panadería');

    // Edición local de una oferta que todavía no tiene publicación M1.2.
    $edicionNoPublicada = Services\Security\ContextoNegocio::en('panaderia', static function () use ($altaPan): bool {
        $producto = Model\Producto::find($altaPan['oferta_id']);
        if (!$producto) return false;
        $producto->nombre = 'A2 Panadería editada ' . bin2hex(random_bytes(3));
        $producto->descripcion = 'Descripción editada antes de publicar';
        $producto->img = 'build/images/a2-pan-editada.webp';
        return (bool) $producto->actualizar();
    });
    $afirmar($edicionNoPublicada, 'Modificar datos comerciales de una oferta no publicada.');
    a2Paridad($db, $afirmar, 'Edición no publicada');

    // Las dos rutas de identidad compartida actualizan las ofertas de ambos negocios.
    $edicionCompartida = Services\Security\ContextoNegocio::en('restaurante', static function () use ($altaRest): bool {
        $producto = Model\Producto::find($altaRest['oferta_id']);
        if (!$producto) return false;
        $producto->nombre = 'A2 Identidad compartida ' . bin2hex(random_bytes(3));
        $producto->descripcion = 'Cambio compartido desde el editor local';
        $producto->img = 'build/images/a2-compartida.webp';
        $producto->confirmar_identidad = true;
        return (bool) $producto->actualizar();
    });
    $afirmar($edicionCompartida, 'Actualizar identidad compartida desde ProductoMultinegocio.');
    a2Paridad($db, $afirmar, 'Identidad compartida por ProductoMultinegocio');

    $identidad = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\CatalogoComercial::identidad($altaRest['producto_id']));
    $cambioCatalogo = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\CatalogoComercial::actualizar(
        $altaRest['producto_id'],
        ['nombre' => 'A2 Identidad Global ' . bin2hex(random_bytes(3)), 'descripcion' => 'Cambio compartido desde Global', 'img' => null],
        Services\Menu\CatalogoComercial::huella($identidad),
        true
    ));
    $afirmar(!empty($cambioCatalogo['confirmado']), 'Actualizar identidad compartida desde CatalogoComercial.');
    a2Paridad($db, $afirmar, 'Identidad compartida por CatalogoComercial');

    // Publicación crea dos ofertas y sincroniza todas dentro de la publicación atómica.
    $datosCasa = [
        'nombre' => 'A2 Producto de la casa ' . $sufijo,
        'descripcion' => 'Descripción oficial publicada A2',
        'img' => 'build/images/a2-casa-' . $sufijo . '.webp',
        'precio' => '35.00', 'activo' => 1, 'tipo' => 'compartido',
        'reglas' => ['nombre' => true, 'descripcion' => true, 'img' => true, 'precio' => true, 'activo' => true],
        'negocios' => [
            'restaurante' => ['categoria_id' => $rest['categoria_id'], 'area_id' => $rest['area_id'], 'precio' => '35.00', 'activo' => 1],
            'panaderia' => ['categoria_id' => $pan['categoria_id'], 'area_id' => $pan['area_id'], 'precio' => '35.00', 'activo' => 1],
        ],
    ];
    $claveCrear = 'a2_crear_' . $sufijo . bin2hex(random_bytes(4));
    $creacionCasa = Services\Menu\ProductosCasa::crear($datosCasa, $claveCrear);
    $productoCasaId = (int) ($creacionCasa['producto_id'] ?? 0);
    $afirmar(!empty($creacionCasa['ok']) && $productoCasaId > 0, 'Crear borrador de producto de la casa.');
    $previa = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\ProductosCasa::vistaPrevia($productoCasaId));
    $clavePublicar = 'a2_publicar_' . $sufijo . bin2hex(random_bytes(4));
    $publicacion = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\ProductosCasa::publicar(
        $productoCasaId,
        (int) $previa['configuracion']['revision'],
        $previa['huella'],
        $clavePublicar,
        true
    ));
    $afirmar(!empty($publicacion['ok']) && !empty($publicacion['publicacion_id']), 'Publicar producto de la casa.');
    $ofertasCasa = $db->execute_query('SELECT id,unidad_id FROM ofertas_producto WHERE producto_id=? ORDER BY unidad_id', [$productoCasaId])->fetch_all(MYSQLI_ASSOC);
    $afirmar(count($ofertasCasa) === 2 && array_map('intval', array_column($ofertasCasa, 'unidad_id')) === [$rest['unidad_id'], $pan['unidad_id']], 'La publicación creó y conservó ambas ofertas.');
    a2Paridad($db, $afirmar, 'Publicación multi-negocio');

    $reintento = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\ProductosCasa::publicar(
        $productoCasaId,
        (int) $previa['configuracion']['revision'],
        $previa['huella'],
        $clavePublicar,
        true
    ));
    $conteoOfertasCasa = (int) $db->execute_query('SELECT COUNT(*) FROM ofertas_producto WHERE producto_id=?', [$productoCasaId])->fetch_row()[0];
    $afirmar(!empty($reintento['ok']) && (int) $reintento['publicacion_id'] === (int) $publicacion['publicacion_id'] && $conteoOfertasCasa === 2, 'Reintento de publicación reutiliza la misma publicación y ofertas.');
    a2Paridad($db, $afirmar, 'Reintento de publicación');

    $ofertaCasaPan = (int) $ofertasCasa[1]['id'];
    $personalizacion = Services\Menu\ProductosCasa::guardarLocal('panaderia', $ofertaCasaPan, [
        'nombre' => 'A2 nombre personalizado',
        'descripcion' => 'A2 descripción personalizada',
        'img' => null,
    ]);
    $efectivoPersonalizado = $db->execute_query('SELECT nombre,descripcion,img FROM productos_oferta WHERE oferta_id=?', [$ofertaCasaPan])->fetch_assoc();
    $afirmar(!empty($personalizacion['ok']) && $efectivoPersonalizado === [
        'nombre' => 'A2 nombre personalizado', 'descripcion' => 'A2 descripción personalizada', 'img' => null,
    ], 'Personalizar nombre, descripción e imagen NULL.');
    a2Paridad($db, $afirmar, 'Personalización local con imagen NULL');

    $logsAntesReintentoLocal = (int) $db->execute_query('SELECT COUNT(*) FROM producto_cambios_locales WHERE oferta_id=?', [$ofertaCasaPan])->fetch_row()[0];
    $reintentoLocal = Services\Menu\ProductosCasa::guardarLocal('panaderia', $ofertaCasaPan, [
        'nombre' => 'A2 nombre personalizado',
        'descripcion' => 'A2 descripción personalizada',
        'img' => null,
    ]);
    $logsDespuesReintentoLocal = (int) $db->execute_query('SELECT COUNT(*) FROM producto_cambios_locales WHERE oferta_id=?', [$ofertaCasaPan])->fetch_row()[0];
    $afirmar(!empty($reintentoLocal['ok']) && $logsDespuesReintentoLocal === $logsAntesReintentoLocal, 'Reintento de personalización no duplica cambios locales.');
    a2Paridad($db, $afirmar, 'Reintento de personalización');

    $restauracion = Services\Menu\ProductosCasa::guardarLocal('panaderia', $ofertaCasaPan, [], ['nombre', 'descripcion', 'img']);
    $efectivoRestaurado = $db->execute_query('SELECT nombre,descripcion,img FROM productos_oferta WHERE oferta_id=?', [$ofertaCasaPan])->fetch_assoc();
    $afirmar(!empty($restauracion['ok']) && $efectivoRestaurado === [
        'nombre' => $datosCasa['nombre'], 'descripcion' => $datosCasa['descripcion'], 'img' => $datosCasa['img'],
    ], 'Restaurar personalizaciones vuelve a los valores oficiales.');
    a2Paridad($db, $afirmar, 'Restauración de personalizaciones');

    $restauracionReintentada = Services\Menu\ProductosCasa::guardarLocal('panaderia', $ofertaCasaPan, [], ['nombre', 'descripcion', 'img']);
    $afirmar(!empty($restauracionReintentada['ok']), 'Reintentar la restauración es seguro.');
    a2Paridad($db, $afirmar, 'Reintento de restauración');

    // Fallar en la segunda oferta confirma rollback integral de una publicación multi-negocio.
    $configuracionCasa = Services\Menu\ProductosCasa::configuracion($productoCasaId);
    $borradorRevisado = array_replace($datosCasa, [
        'nombre' => 'A2 publicación que debe revertirse',
        'descripcion' => 'No debe quedar en ninguna oferta',
    ]);
    $guardadoBorrador = Services\Menu\ProductosCasa::guardar($productoCasaId, $borradorRevisado, (int) $configuracionCasa['revision']);
    $afirmar(!empty($guardadoBorrador['ok']), 'Preparar una segunda revisión antes de publicación atómica.');
    $previaFallida = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\ProductosCasa::vistaPrevia($productoCasaId));
    $snapshotOfertasCasa = $db->execute_query(
        'SELECT id,producto_id,precio,activo FROM ofertas_producto WHERE producto_id=? ORDER BY id',
        [$productoCasaId]
    )->fetch_all(MYSQLI_ASSOC);
    $snapshotDatosCasa = $db->execute_query(
        'SELECT oferta_id,nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id IN (?,?) ORDER BY oferta_id',
        [(int) $ofertasCasa[0]['id'], (int) $ofertasCasa[1]['id']]
    )->fetch_all(MYSQLI_ASSOC);
    $publicacionesAntesFallo = (int) $db->execute_query('SELECT COUNT(*) FROM producto_publicaciones WHERE producto_id=?', [$productoCasaId])->fetch_row()[0];
    $ofertaQueFallaPublicacion = max((int) $ofertasCasa[0]['id'], (int) $ofertasCasa[1]['id']);
    $db->query("CREATE TRIGGER trg_a2_fallo_publicacion BEFORE UPDATE ON oferta_datos_comerciales FOR EACH ROW BEGIN IF NEW.oferta_id={$ofertaQueFallaPublicacion} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A2: fallo de publicación inducido'; END IF; END");
    try {
        $publicacionParcial = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\ProductosCasa::publicar(
            $productoCasaId,
            (int) $previaFallida['configuracion']['revision'],
            $previaFallida['huella'],
            'a2_publicar_fallo_' . $sufijo . bin2hex(random_bytes(4)),
            true
        ));
    } finally {
        $db->query('DROP TRIGGER IF EXISTS trg_a2_fallo_publicacion');
    }
    $snapshotOfertasCasaFinal = $db->execute_query(
        'SELECT id,producto_id,precio,activo FROM ofertas_producto WHERE producto_id=? ORDER BY id',
        [$productoCasaId]
    )->fetch_all(MYSQLI_ASSOC);
    $snapshotDatosCasaFinal = $db->execute_query(
        'SELECT oferta_id,nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id IN (?,?) ORDER BY oferta_id',
        [(int) $ofertasCasa[0]['id'], (int) $ofertasCasa[1]['id']]
    )->fetch_all(MYSQLI_ASSOC);
    $publicacionesDespuesFallo = (int) $db->execute_query('SELECT COUNT(*) FROM producto_publicaciones WHERE producto_id=?', [$productoCasaId])->fetch_row()[0];
    $oficialDespuesFallo = (int) Services\Menu\ProductosCasa::configuracion($productoCasaId)['publicacion_id'];
    $afirmar(empty($publicacionParcial['ok'])
        && $snapshotOfertasCasaFinal === $snapshotOfertasCasa
        && $snapshotDatosCasaFinal === $snapshotDatosCasa
        && $publicacionesDespuesFallo === $publicacionesAntesFallo
        && $oficialDespuesFallo === (int) $publicacion['publicacion_id'],
        'Un fallo al sincronizar la segunda oferta revierte toda la publicación multi-negocio.');
    a2Paridad($db, $afirmar, 'Rollback de publicación multi-negocio');

    // Una denegación previa a la escritura tampoco debe cambiar la materialización.
    $lector = FixtureAutorizacion3B::usuario($db, 'a2_lector', ['restaurante' => ['permisos' => ['menu' => 'ver']]]);
    $productosAntesDenegacion = (int) $db->query('SELECT COUNT(*) FROM productos')->fetch_row()[0];
    $ofertasAntesDenegacion = (int) $db->query('SELECT COUNT(*) FROM ofertas_producto')->fetch_row()[0];
    $_SESSION['id'] = $lector['id'];
    $denegada = false;
    try {
        Services\Menu\Ofertas::crear($altaRest['producto_id'], 'restaurante', [
            'categoria_id' => $rest['categoria_id'], 'area_id' => $rest['area_id'], 'precio' => '18.00',
        ]);
    } catch (Throwable) {
        $denegada = true;
    } finally {
        $_SESSION['id'] = $superadminId;
    }
    $afirmar($denegada
        && (int) $db->query('SELECT COUNT(*) FROM productos')->fetch_row()[0] === $productosAntesDenegacion
        && (int) $db->query('SELECT COUNT(*) FROM ofertas_producto')->fetch_row()[0] === $ofertasAntesDenegacion,
        'Una operación rechazada por permisos no escribe ofertas.');
    a2Paridad($db, $afirmar, 'Operación denegada por permisos');

    // El trigger provoca un fallo real de sincronización después de UPDATE productos.
    $identidadAntesFallo = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\CatalogoComercial::identidad($altaRest['producto_id']));
    $ofertasCompartidasAntesFallo = $db->execute_query(
        'SELECT oferta_id,nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id IN (?,?) ORDER BY oferta_id',
        [$altaRest['oferta_id'], $ofertaAsociada]
    )->fetch_all(MYSQLI_ASSOC);
    $ofertaQueFalla = max($altaRest['oferta_id'], $ofertaAsociada);
    $db->query("CREATE TRIGGER trg_a2_fallo_sincronizacion BEFORE UPDATE ON oferta_datos_comerciales FOR EACH ROW BEGIN IF NEW.oferta_id={$ofertaQueFalla} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A2: fallo de sincronización inducido'; END IF; END");
    $falloSync = false;
    try {
        $resultadoFallido = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\CatalogoComercial::actualizar(
            $altaRest['producto_id'],
            ['nombre' => 'Este nombre debe revertirse', 'descripcion' => 'Esta descripción debe revertirse', 'img' => 'build/images/a2-no-guardar.webp'],
            Services\Menu\CatalogoComercial::huella($identidadAntesFallo),
            true
        ));
        $falloSync = empty($resultadoFallido['ok']);
    } finally {
        $db->query('DROP TRIGGER IF EXISTS trg_a2_fallo_sincronizacion');
    }
    $identidadTrasFallo = Services\Security\ContextoNegocio::en('global', static fn() => Services\Menu\CatalogoComercial::identidad($altaRest['producto_id']));
    $ofertasCompartidasTrasFallo = $db->execute_query(
        'SELECT oferta_id,nombre,descripcion,img FROM oferta_datos_comerciales WHERE oferta_id IN (?,?) ORDER BY oferta_id',
        [$altaRest['oferta_id'], $ofertaAsociada]
    )->fetch_all(MYSQLI_ASSOC);
    $afirmar($falloSync && $identidadTrasFallo === $identidadAntesFallo, 'El error de sincronización revierte la identidad comercial.');
    $afirmar($ofertasCompartidasTrasFallo === $ofertasCompartidasAntesFallo, 'Un fallo al sincronizar la segunda oferta revierte también la primera actualización local.');
    a2Paridad($db, $afirmar, 'Rollback ante fallo de sincronización');

    // Ninguna escritura A2 reasigna IDs ni altera enlaces históricos existentes.
    $relacionesFinales = array_map(static fn(array $fila): array => [
        'id' => (int) $fila['id'], 'producto_id' => (int) $fila['producto_id'],
    ], $db->execute_query(
        'SELECT id,producto_id FROM ofertas_producto WHERE id<=? ORDER BY id',
        [$maxOfertaInicial]
    )->fetch_all(MYSQLI_ASSOC));
    $afirmar($relacionesFinales === $relacionesIniciales,
        'producto_id y oferta_id de ofertas preexistentes permanecen intactos: ' . json_encode(['antes' => count($relacionesIniciales), 'después' => count($relacionesFinales), 'filas_finales' => $relacionesFinales], JSON_UNESCAPED_UNICODE));
    $productosIniciales = $db->execute_query('SELECT id FROM productos WHERE id<=? ORDER BY id', [$maxProductoInicial])->fetch_all(MYSQLI_ASSOC);
    $idsProductosIniciales = array_map('intval', array_column($productosIniciales, 'id'));
    $idsProductosFinales = array_map('intval', array_column($db->execute_query('SELECT id FROM productos WHERE id<=? ORDER BY id', [$maxProductoInicial])->fetch_all(MYSQLI_ASSOC), 'id'));
    $afirmar($idsProductosFinales === $idsProductosIniciales, 'Los identificadores preexistentes de productos permanecen intactos.');
    $ticketsFinales = $db->query(
        'SELECT ti.id,ti.ticket_id,ti.unidad_id,ti.oferta_id,o.producto_id
         FROM ticket_items ti JOIN ofertas_producto o ON o.id=ti.oferta_id AND o.unidad_id=ti.unidad_id ORDER BY ti.id'
    )->fetch_all(MYSQLI_ASSOC);
    $afirmar($ticketsFinales === $ticketsIniciales, 'Las relaciones históricas de tickets conservan oferta_id y producto_id.');

    foreach ([
        [$altaRest['oferta_id'], $altaRest['producto_id']],
        [$altaPan['oferta_id'], $altaPan['producto_id']],
        [$ofertaAsociada, $altaRest['producto_id']],
        [(int) $ofertasCasa[0]['id'], $productoCasaId],
        [(int) $ofertasCasa[1]['id'], $productoCasaId],
    ] as [$ofertaId, $productoId]) {
        $actual = $db->execute_query('SELECT producto_id FROM ofertas_producto WHERE id=?', [$ofertaId])->fetch_row();
        $afirmar($actual !== null && (int) $actual[0] === $productoId, "La oferta {$ofertaId} conserva su relación al producto {$productoId}.");
    }
    a2Paridad($db, $afirmar, 'Verificación final global');
}, 'A2 datos comerciales');
