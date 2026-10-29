<?php

namespace Services\Menu;

use Services\Security\ContextoNegocio;

use Model\CategoriasMenu;
use Model\Producto;

/**
 * Lectura de la carta desde la fuente única (`productos`).
 *
 * Un solo lugar arma el agrupado por categoría que consumen la landing
 * (GET /menu), el PDF y el punto de venta. Antes la carta pública hacía N+1
 * consultas (una por categoría) y el PDF ordenaba al revés porque usaba
 * ActiveRecord::all(), que es ORDER BY id DESC.
 *
 * No se usa consultarSQL: ActiveRecord::crearObjeto() descarta cualquier
 * columna que no sea propiedad declarada del modelo, así que `c.nombre AS
 * cat_nombre` se perdería. Con mysqli directo se controla la forma.
 */
class Carta
{
    /**
     * Carta pública agrupada por categoría.
     *
     * Forma que consumen src/js/modules/menu.js y views/home/_menu.php:
     *   [{ id, label, img, dishes: [{ n, d, p, img }] }]
     *
     * Hay dos `img` y no se funden aquí a propósito: la de la categoría es la
     * foto por defecto de la sección y la del platillo (`productos.img`) es su
     * foto propia, `null` si no tiene. Qué se enseña cuando falta la propia lo
     * decide la landing; resolverlo en el servidor borraría la diferencia entre
     * «este platillo tiene foto» y «está tomando prestada la de su sección».
     *
     * $carta acota a una de las dos piezas impresas (`categorias.carta`) y
     * por defecto trae la de comida, que es lo que ya pintaban la landing y
     * el PDF antes de que existiera la de maridaje: quien no pide nada sigue
     * recibiendo lo mismo. Pasar null trae las dos — nadie lo hace hoy, pero
     * es lo que evita que un consumidor futuro invente su propio SQL.
     */
    public static function publica(?string $carta = CategoriasMenu::CARTA_COMIDA): array
    {
        if (ContextoNegocio::nuevo()) return CartaMultinegocio::publica($carta);
        $out = [];
        $db = Producto::getDB();
        $filtroCarta = '';

        if ($carta !== null) {
            // Un valor fuera del ENUM no se ignora: devolvería la carta entera
            // en una ruta que promete una sola pieza.
            $filtroCarta = " AND c.carta = '"
                . Producto::escaparString(CategoriasMenu::normalizarCarta($carta)) . "'";
        }

        $res = $db->query(
            "SELECT c.id AS cat_id, c.nombre AS cat_nombre, c.img AS cat_img,
                    p.id, p.nombre, p.descripcion, p.precio, p.img
               FROM productos p
               JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 AND c.activo = 1" . $filtroCarta . "
              ORDER BY c.id ASC, p.id ASC"
        );

        if (!$res) {
            return [];
        }

        while ($row = $res->fetch_assoc()) {
            $cid = (int) $row['cat_id'];

            if (!isset($out[$cid])) {
                $out[$cid] = [
                    'id'     => $cid,
                    'label'  => $row['cat_nombre'],
                    'img'    => $row['cat_img'] ? '/' . ltrim($row['cat_img'], '/') : null,
                    'dishes' => [],
                ];
            }

            $out[$cid]['dishes'][] = [
                'n'    => $row['nombre'],
                'd'    => (string) $row['descripcion'],
                // (float) para que json_encode emita 240 y no "240.00":
                // menu.js concatena '$' + d.p directamente.
                'p'    => (float) $row['precio'],
                'img'  => $row['img'] ? '/' . ltrim($row['img'], '/') : null,
            ];
        }

        $res->free();

        return array_values($out);
    }

    /**
     * Especiales de temporada para la vitrina de la landing
     * (views/home/_firma.php): los platillos activos de las categorías con
     * carta = 'temporada', en una lista plana y en el orden de alta.
     *   [{ n, d, p, cat, img, propia }]
     *
     * Plana porque la vitrina no agrupa: todos son «especial de la semana».
     *
     * La foto es opcional en el panel —los especiales cambian cada semana y no
     * siempre hay foto del platillo—, y aquí sí se resuelve el respaldo, al
     * revés que en publica(): la vitrina sólo sabe pintar tarjetas de
     * fotografía, así que sin foto propia el especial toma la de su categoría
     * (deploy.sql siembra una para «Especiales de temporada», y se cambia en
     * Menú → Categorías). `propia` dice cuál de las dos es, por si alguien
     * necesita distinguirlas.
     *
     * Un especial sin foto propia NI de categoría se queda fuera: una tarjeta
     * de vitrina sin imagen es un marco vacío con el nombre en tinta clara
     * sobre papel claro. El formulario del platillo lo avisa al guardarlo.
     */
    public static function especiales(): array
    {
        if (ContextoNegocio::nuevo()) return CartaMultinegocio::especiales();
        $out = [];

        foreach (self::publica(CategoriasMenu::CARTA_TEMPORADA) as $categoria) {
            foreach ($categoria['dishes'] as $dish) {
                $img = $dish['img'] ?: $categoria['img'];
                if ($img === null) {
                    continue;
                }

                $out[] = [
                    'n'      => $dish['n'],
                    'd'      => $dish['d'],
                    'p'      => $dish['p'],
                    // La categoría del platillo: es lo que la vitrina pinta en
                    // la línea chica sobre el nombre, como hacía el arreglo fijo.
                    'cat'    => $categoria['label'],
                    'img'    => $img,
                    'propia' => $dish['img'] !== null,
                ];
            }
        }

        return $out;
    }

    /**
     * Carta para el punto de venta.
     *
     * Se omiten descripción e imagen (el POS no las pinta) y el área viaja como
     * slug, que es lo que addToComanda resuelve contra window.CP_AREAS:
     *   [{ id, label, dishes: [{ id, n, p, area, g? }] }]
     *
     * `g` sólo aparece en los platillos con variantes (Variantes::paraPos()):
     * [{ n: grupo, req: bool, o: [{ id, n, d: delta de precio, def }] }].
     *
     * No filtra por `categorias.carta` a propósito: la división en dos cartas
     * es de papel, no de operación — el mesero cobra la copa y el plato en el
     * mismo ticket, así que el POS los ve juntos.
     */
    public static function paraPos(): array
    {
        if (ContextoNegocio::nuevo()) return CartaMultinegocio::paraPos();
        $out = [];
        $db = Producto::getDB();
        $res = $db->query(
            "SELECT c.id AS cat_id, c.nombre AS cat_nombre,
                    p.id, p.nombre, p.precio, ap.slug AS area_slug
               FROM productos p
               JOIN categorias c        ON c.id  = p.categoria_id
               JOIN areas_produccion ap ON ap.id = p.area_id
              WHERE p.activo = 1 AND c.activo = 1
              ORDER BY c.id ASC, p.id ASC"
        );

        if (!$res) {
            return [];
        }

        // Tolerante: una base sin las tablas de variantes sigue abriendo el POS.
        try {
            $variantes = Variantes::paraPos();
        } catch (\Throwable $e) {
            error_log('Carta::paraPos - variantes no disponibles: ' . $e->getMessage());
            $variantes = [];
        }

        while ($row = $res->fetch_assoc()) {
            $cid = (int) $row['cat_id'];

            if (!isset($out[$cid])) {
                $out[$cid] = ['id' => $cid, 'label' => $row['cat_nombre'], 'dishes' => []];
            }

            $pid = (int) $row['id'];
            $dish = [
                'id'   => $pid,
                'n'    => $row['nombre'],
                'p'    => (float) $row['precio'],
                'area' => $row['area_slug'],
            ];
            if (!empty($variantes[$pid])) {
                $dish['g'] = $variantes[$pid];
            }
            $out[$cid]['dishes'][] = $dish;
        }

        $res->free();

        return array_values($out);
    }

    /**
     * Áreas de producción como slug => {id, label, color}.
     * Reemplaza al literal window.CP_AREAS de src/js/data/menu-data.js.
     */
    public static function areasPos(): array
    {
        if (ContextoNegocio::nuevo()) return CartaMultinegocio::areasPos();
        $out = [];
        $db = Producto::getDB();
        $res = $db->query("SELECT id, nombre, slug, color FROM areas_produccion ORDER BY id ASC");

        if (!$res) {
            return [];
        }

        while ($row = $res->fetch_assoc()) {
            $out[$row['slug']] = [
                'id'    => (int) $row['id'],
                'label' => $row['nombre'],
                'color' => $row['color'],
            ];
        }

        $res->free();

        return $out;
    }
}



/** Catálogo que proyecta ofertas; cada lectura administrativa/POS incluye su unidad. */
class CartaMultinegocio
{
    /** Público: siempre Restaurante, sin depender de sesión ni del contexto de ruta. */
    public static function publica(?string $carta = CategoriasMenu::CARTA_COMIDA): array
    {
        $unidad = self::unidadRestaurante();
        $db = Producto::getDB(); $filtro = '';
        if ($carta !== null) $filtro = " AND c.carta='" . Producto::escaparString(CategoriasMenu::normalizarCarta($carta)) . "'";
        $res = $db->query("SELECT c.id cat_id,c.nombre cat_nombre,c.img cat_img,o.id oferta_id,o.producto_id,p.nombre,p.descripcion,o.precio,p.img FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id WHERE o.unidad_id={$unidad} AND o.activo=1 AND c.activo=1{$filtro} ORDER BY c.orden,c.id,o.orden,o.id");
        if (!$res) return [];
        $out=[];
        while ($row=$res->fetch_assoc()) {
            $cid=(int)$row['cat_id'];
            if (!isset($out[$cid])) $out[$cid]=['id'=>$cid,'label'=>$row['cat_nombre'],'img'=>$row['cat_img']?'/'.ltrim($row['cat_img'],'/'):null,'dishes'=>[]];
            $out[$cid]['dishes'][]=['n'=>$row['nombre'],'d'=>(string)$row['descripcion'],'p'=>(float)$row['precio'],'img'=>$row['img']?'/'.ltrim($row['img'],'/'):null];
        }
        $res->free(); return array_values($out);
    }

    public static function especiales(): array
    {
        $out=[];
        foreach (self::publica(CategoriasMenu::CARTA_TEMPORADA) as $categoria) foreach ($categoria['dishes'] as $dish) {
            $img=$dish['img']?:$categoria['img']; if ($img===null) continue;
            $out[]=['n'=>$dish['n'],'d'=>$dish['d'],'p'=>$dish['p'],'cat'=>$categoria['label'],'img'=>$img,'propia'=>$dish['img']!==null];
        }
        return $out;
    }

    /** Menú de venta del negocio activo, sin filtro de carta; id y variantes son IDs de oferta. */
    public static function paraPos(): array
    {
        ContextoNegocio::exigir('pdv','operar',ContextoNegocio::slug());
        $ctx=ContextoNegocio::slug(); $unidad=ContextoNegocio::vendedora($ctx);
        $res=Producto::getDB()->query("SELECT c.id cat_id,c.nombre cat_nombre,o.id oferta_id,p.nombre,o.precio,ap.slug area_slug FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id JOIN areas_produccion ap ON ap.id=o.area_id AND ap.unidad_id=o.unidad_id WHERE o.unidad_id={$unidad} AND o.activo=1 AND c.activo=1 ORDER BY c.orden,c.id,o.orden,o.id");
        if (!$res) return [];
        try { $variantes=Variantes::paraPos(); } catch (\Throwable $e) { error_log('Carta::paraPos - variantes no disponibles: '.$e->getMessage()); $variantes=[]; }
        $out=[];
        while ($row=$res->fetch_assoc()) {
            $cid=(int)$row['cat_id']; if (!isset($out[$cid])) $out[$cid]=['id'=>$cid,'label'=>$row['cat_nombre'],'dishes'=>[]];
            $oid=(int)$row['oferta_id']; $dish=['id'=>$oid,'oferta_id'=>$oid,'n'=>$row['nombre'],'p'=>(float)$row['precio'],'area'=>$row['area_slug']];
            if (!empty($variantes[$oid])) $dish['g']=$variantes[$oid];
            $out[$cid]['dishes'][]=$dish;
        }
        $res->free(); return array_values($out);
    }

    public static function areasPos(): array
    {
        ContextoNegocio::exigir('pdv','operar',ContextoNegocio::slug());
        $unidad=ContextoNegocio::vendedora(ContextoNegocio::slug()); $out=[];
        $res=Producto::getDB()->query('SELECT id,nombre,slug,color FROM areas_produccion WHERE unidad_id='.$unidad.' ORDER BY orden,id');
        if (!$res) return [];
        while ($r=$res->fetch_assoc()) $out[$r['slug']]=['id'=>(int)$r['id'],'label'=>$r['nombre'],'color'=>$r['color']];
        $res->free(); return $out;
    }

    private static function unidadRestaurante(): int
    {
        $db=Producto::getDB(); $stmt=$db->prepare("SELECT id FROM unidades WHERE slug='restaurante' LIMIT 1");
        if (!$stmt) return 0;
        $stmt->execute(); $row=$stmt->get_result()->fetch_row(); $stmt->close();
        return $row ? (int)$row[0] : 0;
    }
}
