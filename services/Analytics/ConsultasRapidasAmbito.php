<?php

/**
 * Consultas rápidas de Panadería y de Global: el motor del Asistente IA en
 * esos dos ámbitos.
 *
 * Es la misma pieza que ConsultasRapidasRestaurante (al final de
 * ConsultasRapidas.php): cada pregunta es una INTENCIÓN con huecos que se
 * eligen de listas —«Ventas de [Croissant ▾] · [Esta semana ▾]»—, y cada
 * intención es una consulta SQL ya armada, preparada y acotada por unidad. No
 * hay texto libre ni modelo de lenguaje. La forma de la respuesta es la misma
 * que la de Restaurante, así que el Asistente la pinta sin saber de qué
 * ámbito viene.
 *
 * Lo que cambia es QUÉ se pregunta, porque cada negocio vive de otra cosa:
 *
 *   - Panadería vende en CAJA, sin mesas ni meseros: café y pan para llevar.
 *     Sus ventas son tickets de caja de su propia unidad, contados como en
 *     Restaurante (cerrados, sin renglones cancelados, fechados por el cobro).
 *     Su caja todavía no registra tickets, así que esas preguntas contestan en
 *     cero hasta que exista; no se esconden porque la consulta ya es la buena.
 *     Además pregunta por sus almacenes y por su menú.
 *   - Global no vende. Lee el CONSOLIDADO de todos los almacenes, como hace
 *     Almacenes con permiso de ver (AlcanceInventario::almacen), desglosado por
 *     negocio, más las transferencias. Una lectura
 *     consolidada nunca concede escritura ni suma ventas de los negocios
 *     (docs/arquitectura.md §3).
 *
 * El ámbito sale SIEMPRE de la ruta (ContextoNegocio::slug), nunca del
 * navegador: quien llama pasa el que ya resolvió la guardia y aquí se vuelve a
 * comprobar.
 */

namespace Services\Analytics;

use Services\Inventory\InventarioError;
use Services\Security\ContextoNegocio;

final class ConsultasRapidasAmbito
{
    public const AMBITOS = ['panaderia', 'global'];

    /** Lo que responde cada ámbito. Cada intención es también el `tipo` de su respuesta. */
    public const INTENCIONES = [
        'panaderia' => [
            'ventas_total', 'ventas_producto', 'ventas_categoria', 'top', 'ticket_promedio', 'sin_venta',
            'horas_pico', 'cancelaciones',
            'stock', 'bajo_stock', 'consumo', 'entradas', 'mermas', 'transferencias',
            'sin_receta',
        ],
        'global' => [
            'stock', 'bajo_stock', 'consumo', 'entradas', 'mermas', 'transferencias',
        ],
    ];

    /**
     * Qué módulo abre cada familia de preguntas: basta uno. Panadería no tiene
     * Análisis ni Tickets habilitados, así que sus ventas las abre el propio
     * Asistente; almacén y menú también se abren con su módulo, como en
     * Restaurante.
     */
    private const MODULOS = [
        'ventas' => ['ia'],
        'almacen' => ['inventario', 'ia'],
        'menu' => ['menu', 'ia'],
    ];

    private const FAMILIA = [
        'ventas_total' => 'ventas', 'ventas_producto' => 'ventas', 'ventas_categoria' => 'ventas', 'top' => 'ventas',
        'ticket_promedio' => 'ventas', 'sin_venta' => 'ventas', 'horas_pico' => 'ventas', 'cancelaciones' => 'ventas',
        'stock' => 'almacen', 'bajo_stock' => 'almacen', 'consumo' => 'almacen', 'entradas' => 'almacen',
        'mermas' => 'almacen', 'transferencias' => 'almacen',
        'sin_receta' => 'menu',
    ];

    /** Preguntas sin periodo: una fotografía de ahora. */
    private const SIN_PERIODO = ['stock', 'bajo_stock', 'transferencias', 'sin_receta'];

    /**
     * Una transferencia abierta es la que todavía pide algo de alguien:
     * autorizarla, enviarla, recibirla o resolver su incidencia. Las demás ya
     * terminaron (docs/modulos/transferencias.md).
     */
    private const TRANSFERENCIA_ABIERTA = [
        'incidencia' => 'Con incidencia',
        'enviada' => 'En tránsito',
        'autorizada' => 'Por enviar',
        'solicitada' => 'Por autorizar',
    ];

    private const TOPE_DESGLOSE = 8;
    private const TOPE_LISTA = 10;

    /** Lo que se dice distinto en cada ámbito. */
    private const TEXTOS = [
        'panaderia' => [
            'nombre' => 'Panadería',
            'todo' => 'toda la panadería',
            'Todo' => 'Toda la panadería',
            'rotulo_ventas' => 'Producto o categoría',
            'grupo_cosas' => 'Productos',
            'elige_ventas' => 'elige un producto',
            'en_almacen' => 'en almacén',
            'almacenes' => 'los almacenes de Panadería',
        ],
        'global' => [
            'nombre' => 'Global',
            'todo' => 'toda la organización',
            'Todo' => 'Toda la organización',
            'rotulo_ventas' => 'Producto o categoría',
            'grupo_cosas' => 'Productos',
            'elige_ventas' => 'elige un producto',
            'en_almacen' => 'en todos los almacenes',
            'almacenes' => 'todos los almacenes de la organización',
        ],
    ];

    private const TITULOS = [
        'ventas_total' => 'Ventas en caja',
        'ventas_producto' => 'Ventas de un producto',
        'ventas_categoria' => 'Ventas por categoría',
        'top' => 'Lo más vendido',
        'ticket_promedio' => 'Ticket promedio',
        'sin_venta' => 'Productos sin venta',
        'horas_pico' => 'Horas de más venta',
        'cancelaciones' => 'Cancelaciones en caja',
        'stock' => 'Existencia de un artículo',
        'bajo_stock' => 'Lo que está por agotarse',
        'consumo' => 'Consumo de un artículo',
        'entradas' => 'Entradas de un artículo',
        'mermas' => 'Mermas',
        'transferencias' => 'Transferencias en curso',
        'sin_receta' => 'Productos sin receta',
    ];

    /** Qué contesta cada pregunta, en una línea: el Asistente la pone bajo la pregunta armada. */
    private const DESCRIPCIONES = [
        'panaderia' => [
            'ventas_total' => 'Importe cobrado en caja en tickets cerrados.',
            'ventas_producto' => 'Piezas vendidas en caja de un producto o una categoría, y su importe.',
            'ventas_categoria' => 'Piezas vendidas en caja de una categoría, con sus productos más vendidos.',
            'top' => 'Los cinco productos con más piezas vendidas en caja.',
            'ticket_promedio' => 'Cuántos tickets de caja se cerraron y cuánto deja cada uno en promedio.',
            'sin_venta' => 'Productos activos del menú que no se vendieron en el periodo.',
            'horas_pico' => 'Las horas en que se cobran más tickets de caja.',
            'cancelaciones' => 'Piezas canceladas en caja y su importe.',
            'stock' => 'Existencia física de un artículo en los almacenes de Panadería, frente a su mínimo.',
            'bajo_stock' => 'Artículos en o por debajo de su mínimo en algún almacén de Panadería.',
            'consumo' => 'Cuánto salió de un artículo por ventas y por mermas.',
            'entradas' => 'Cuánto se recibió de un artículo en entradas de almacén, y su costo.',
            'mermas' => 'Lo que se mermó en el periodo y cuánto costó, por artículo.',
            'transferencias' => 'Transferencias abiertas que salen de los almacenes de Panadería o llegan a ellos.',
            'sin_receta' => 'Productos activos del menú que todavía no tienen receta.',
        ],
        'global' => [
            'stock' => 'Existencia de un artículo en todos los almacenes, desglosada por negocio.',
            'bajo_stock' => 'Artículos en o por debajo de su mínimo en cualquier almacén de la organización.',
            'consumo' => 'Cuánto salió de un artículo por ventas y por mermas en todos los negocios.',
            'entradas' => 'Cuánto se recibió de un artículo en todos los almacenes. Las transferencias internas no cuentan.',
            'mermas' => 'Lo que se mermó en toda la organización y cuánto costó, por negocio.',
            'transferencias' => 'Todas las transferencias de la organización que siguen abiertas.',
        ],
    ];

    /**
     * Periodo con el que nace el ejemplo de cada pregunta: un tramo en el que
     * suele haber datos. Un ejemplo que contesta en cero enseña mal la pregunta.
     */
    private const PERIODO_DE_EJEMPLO = [
        'ventas_total' => 'hoy', 'ventas_producto' => 'semana', 'ventas_categoria' => 'mes', 'top' => 'semana',
        'ticket_promedio' => 'semana', 'sin_venta' => '30d', 'horas_pico' => 'semana', 'cancelaciones' => '30d',
        'consumo' => '30d', 'entradas' => '30d', 'mermas' => '30d',
    ];

    private const MAX_EJEMPLOS = 4;

    // ── Entradas ────────────────────────────────────────────────

    /**
     * Las listas de los huecos de entidad: `ventas` (el total, las categorías y
     * los productos de Panadería) e `ingredientes` (los artículos que se pueden
     * preguntar). Global no vende, así que no tiene lista de ventas.
     *
     * @return array{ventas: list<array<string, mixed>>, ingredientes: list<array<string, mixed>>}
     */
    public static function catalogo(string $ctx): array
    {
        self::ambito($ctx);
        if (!self::permite($ctx, array_merge(self::MODULOS['ventas'], self::MODULOS['almacen'], self::MODULOS['menu']))) {
            throw new InventarioError('PERMISO_DENEGADO', 'No tienes permiso para consultar estos datos de ' . self::TEXTOS[$ctx]['nombre'] . '.');
        }
        $db = ContextoNegocio::db();
        $t = self::TEXTOS[$ctx];

        $ventas = [];
        if ($ctx === 'panaderia' && self::permite($ctx, self::MODULOS['ventas'])) {
            $u = ContextoNegocio::unidad($ctx);
            $ventas[] = [
                'valor' => 'ventas_total',
                'etiqueta' => $t['Todo'],
                'grupo' => 'General',
                'detalle' => 'Ventas en caja',
                'consulta' => ['intencion' => 'ventas_total', 'id' => null, 'opcion' => null],
            ];
            foreach (self::filas($db, 'SELECT id, nombre FROM categorias WHERE unidad_id = ? AND activo = 1 ORDER BY orden, nombre', [$u]) as $r) {
                $ventas[] = [
                    'valor' => 'ventas_categoria:' . (int) $r['id'],
                    'etiqueta' => (string) $r['nombre'],
                    'grupo' => 'Categorías',
                    'detalle' => '',
                    'consulta' => ['intencion' => 'ventas_categoria', 'id' => (int) $r['id'], 'opcion' => null],
                ];
            }
            $sql = 'SELECT o.id AS oferta_id, po.nombre, c.nombre AS categoria
                      FROM ofertas_producto o
                      JOIN productos_oferta po ON po.oferta_id = o.id
                      JOIN categorias c ON c.id = o.categoria_id AND c.unidad_id = o.unidad_id
                     WHERE o.unidad_id = ? AND o.activo = 1 AND c.activo = 1
                     ORDER BY o.orden, po.nombre';
            foreach (self::filas($db, $sql, [$u]) as $r) {
                $ventas[] = [
                    'valor' => 'ventas_producto:' . (int) $r['oferta_id'],
                    'etiqueta' => (string) $r['nombre'],
                    'grupo' => $t['grupo_cosas'],
                    'detalle' => (string) $r['categoria'],
                    'consulta' => ['intencion' => 'ventas_producto', 'id' => (int) $r['oferta_id'], 'opcion' => null],
                ];
            }
        }

        $articulos = [];
        if (self::permite($ctx, self::MODULOS['almacen'])) {
            // Global administra el catálogo maestro: se pregunta por cualquier
            // artículo, aunque no esté asociado a ningún almacén (la respuesta
            // lo dice). Panadería sólo por los que viven en sus almacenes.
            if ($ctx === 'global') {
                $sql = 'SELECT id, nombre, unidad, activo FROM ingredientes ORDER BY activo DESC, nombre';
                $args = [];
            } else {
                [$en, $args] = self::en(self::alcance($ctx));
                $sql = "SELECT DISTINCT i.id, i.nombre, i.unidad, i.activo
                          FROM almacen_articulos aa
                          JOIN almacenes a ON a.id = aa.almacen_id AND a.unidad_id = aa.unidad_id
                          JOIN ingredientes i ON i.id = aa.articulo_id
                         WHERE aa.unidad_id IN ({$en})
                         ORDER BY i.activo DESC, i.nombre";
            }
            foreach (self::filas($db, $sql, $args) as $r) {
                $articulos[] = [
                    'valor' => 'stock:' . (int) $r['id'],
                    'etiqueta' => (string) $r['nombre'],
                    'grupo' => (int) $r['activo'] === 1 ? 'Artículos' : 'Inactivos',
                    'detalle' => (string) $r['unidad'],
                    'consulta' => ['intencion' => 'stock', 'id' => (int) $r['id'], 'opcion' => null],
                ];
            }
        }

        return ['ventas' => $ventas, 'ingredientes' => $articulos];
    }

    /**
     * Todo lo que el ámbito sabe responder, con la plantilla de cada pregunta
     * y ejemplos armados con datos reales, más los periodos del hueco de
     * periodo. Una familia sin permiso no se ofrece.
     *
     * @return array{intenciones: list<array<string, mixed>>, periodos: list<array<string, mixed>>}
     */
    public static function capacidades(string $ctx): array
    {
        self::ambito($ctx);
        $permitidas = array_values(array_filter(
            self::INTENCIONES[$ctx],
            static fn (string $i): bool => self::permite($ctx, self::MODULOS[self::FAMILIA[$i]])
        ));
        if ($permitidas === []) {
            throw new InventarioError('PERMISO_DENEGADO', 'No tienes permiso para consultar estos datos de ' . self::TEXTOS[$ctx]['nombre'] . '.');
        }

        $catalogo = self::catalogo($ctx);
        $intenciones = [];
        foreach ($permitidas as $i) {
            $ejemplos = self::ejemplos($ctx, $i, $catalogo);
            $intenciones[] = [
                'intencion' => $i,
                'titulo' => self::TITULOS[$i],
                'descripcion' => self::DESCRIPCIONES[$ctx][$i],
                'plantilla' => self::plantilla($ctx, $i, null, null, []),
                'ejemplo' => $ejemplos[0] ?? null,
                'ejemplos' => $ejemplos,
            ];
        }

        return ['intenciones' => $intenciones, 'periodos' => self::opcionesPeriodo()];
    }

    /**
     * Una pregunta ya resuelta: intención, entidad, opción y periodo. Lo que no
     * existe en el ámbito —un producto de otro negocio, un artículo que no vive
     * en sus almacenes— se contesta como no encontrado, sin revelar nada.
     *
     * @return array<string, mixed>
     */
    public static function estructurada(string $ctx, string $intencion, ?int $id = null, ?int $opcion = null, ?string $periodo = null): array
    {
        self::ambito($ctx);
        if (!in_array($intencion, self::INTENCIONES[$ctx], true)) {
            return ['ok' => false, 'mensaje' => 'No reconozco esa consulta.'];
        }
        if (!self::permite($ctx, self::MODULOS[self::FAMILIA[$intencion]])) {
            throw new InventarioError('PERMISO_DENEGADO', 'No tienes permiso para consultar estos datos de ' . self::TEXTOS[$ctx]['nombre'] . '.');
        }
        $id = $id !== null && $id > 0 ? $id : null;
        $opcion = $opcion !== null && $opcion > 0 && $intencion === 'ventas_producto' ? $opcion : null;
        $p = ConsultasRapidas::periodoDesde($periodo) ?? ConsultasRapidas::periodo('hoy');

        $db = ContextoNegocio::db();
        $r = match (self::FAMILIA[$intencion]) {
            'ventas' => self::ventas($db, $ctx, $intencion, $id, $opcion, $p),
            'almacen' => self::almacen($db, $ctx, $intencion, $id, $p),
            default => self::menu($db, $ctx, $intencion),
        };
        if ($r === null) {
            return self::noEncontrado($ctx, $p);
        }

        $plantilla = self::plantilla($ctx, $intencion, $r['entidad'] ?? null, $r['opcion'] ?? null, $p);

        return [
            'ok' => true,
            'consulta' => (string) $r['respuesta']['titulo'],
            'periodo' => $p,
            'alternativas' => [],
            'entendida' => true,
            'interpretacion' => [
                'intencion' => $intencion,
                'id' => $id,
                'opcion' => $opcion,
                'periodo' => $p['clave'],
                'texto' => self::textoDe($plantilla),
            ],
            'plantilla' => $plantilla,
            'respuesta' => $r['respuesta'],
            'sugerencias' => [],
        ];
    }

    // ── Ventas en caja (Panadería) ──────────────────────────────

    /**
     * Las ventas se cuentan como en el resto del panel: tickets cerrados, sin
     * renglones cancelados, fechados por COALESCE(hora_cierre, hora_apertura),
     * que es el momento del cobro. Es la regla de ConsultasRapidasRestaurante;
     * si cambia allí, cambia aquí.
     */
    private const VENTA = "t.unidad_id = ? AND t.estado = 'cerrado' AND ti.estado <> 'cancelado'
        AND COALESCE(t.hora_cierre, t.hora_apertura) >= ? AND COALESCE(t.hora_cierre, t.hora_apertura) <= ?";

    private const DE_TICKETS = 'FROM tickets t JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.unidad_id = t.unidad_id';

    /** @return array<string, mixed>|null */
    private static function ventas(\mysqli $db, string $ctx, string $int, ?int $id, ?int $opcion, array $p): ?array
    {
        $u = ContextoNegocio::unidad($ctx);
        [$desde, $hasta] = self::limites($p['desde'], $p['hasta']);
        $args = [$u, $desde, $hasta];
        $entidad = null;
        $variante = null;
        $detalle = [];
        $detalleTitulo = null;

        switch ($int) {
            case 'ventas_total':
            case 'ticket_promedio':
                $r = self::fila($db, 'SELECT COUNT(DISTINCT t.id) AS tickets, COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe '
                    . self::DE_TICKETS . ' WHERE ' . self::VENTA, $args);
                $tickets = (int) ($r['tickets'] ?? 0);
                $importe = (float) ($r['importe'] ?? 0);
                if ($int === 'ventas_total') {
                    $respuesta = [
                        'titulo' => 'Ventas ' . mb_strtolower($p['frase']),
                        'cifra' => self::dinero($importe),
                        'unidad' => '',
                        'frase' => $tickets > 0
                            ? 'Importe cobrado en caja en ' . self::conteo($tickets, 'ticket', 'tickets') . '.'
                            : 'No hay ventas en caja registradas para el periodo.',
                    ];
                } else {
                    $promedio = $tickets > 0 ? $importe / $tickets : 0.0;
                    $respuesta = [
                        'titulo' => 'Ticket promedio',
                        'cifra' => self::dinero($promedio),
                        'unidad' => 'por ticket',
                        'frase' => $tickets > 0
                            ? self::conteo($tickets, 'ticket de caja cerrado', 'tickets de caja cerrados') . ' por ' . self::dinero($importe) . '.'
                            : 'No hay tickets de caja cerrados en el periodo.',
                    ];
                }
                break;

            case 'ventas_producto':
                if ($id === null) return null;
                $producto = self::fila($db, 'SELECT po.nombre FROM ofertas_producto o JOIN productos_oferta po ON po.oferta_id = o.id WHERE o.id = ? AND o.unidad_id = ?', [$id, $u]);
                if ($producto === null) return null;
                $entidad = ['valor' => 'ventas_producto:' . $id, 'etiqueta' => (string) $producto['nombre']];
                $filtroOpcion = '';
                if ($opcion !== null) {
                    $variante = self::fila($db, 'SELECT id, nombre FROM producto_variantes WHERE id = ? AND oferta_id = ? AND unidad_id = ? AND activo = 1 AND predeterminada = 0', [$opcion, $id, $u]);
                    if ($variante === null) return null;
                    $filtroOpcion = ' AND FIND_IN_SET(?, ti.variante_ids)';
                }
                $argsProducto = array_merge($args, [$id], $opcion !== null ? [$opcion] : []);
                $r = self::fila($db, 'SELECT COALESCE(SUM(ti.cantidad), 0) AS piezas, COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe '
                    . self::DE_TICKETS . ' WHERE ' . self::VENTA . ' AND ti.oferta_id = ?' . $filtroOpcion, $argsProducto);
                $piezas = (int) ($r['piezas'] ?? 0);
                $importe = (float) ($r['importe'] ?? 0);
                // El desglose por opción sólo dice algo si hubo más de una
                // forma de pedirlo; «como en el menú» a secas no es desglose.
                if ($opcion === null && $piezas > 0) {
                    $porOpcion = self::filas($db, "SELECT COALESCE(NULLIF(ti.variante, ''), '') AS variante, SUM(ti.cantidad) AS piezas "
                        . self::DE_TICKETS . ' WHERE ' . self::VENTA . ' AND ti.oferta_id = ? GROUP BY variante ORDER BY piezas DESC', array_merge($args, [$id]));
                    if (!(count($porOpcion) === 1 && $porOpcion[0]['variante'] === '')) {
                        foreach (array_slice($porOpcion, 0, self::TOPE_DESGLOSE) as $o) {
                            $detalle[] = self::renglon($o['variante'] !== '' ? (string) $o['variante'] : 'Como en el menú', (float) $o['piezas'], self::conteo((int) $o['piezas'], 'pieza', 'piezas'));
                        }
                        $detalleTitulo = 'Por opción';
                    }
                }
                $respuesta = [
                    'titulo' => 'Ventas de ' . $producto['nombre'] . ($variante !== null ? ' · ' . $variante['nombre'] : ''),
                    'cifra' => self::numero($piezas),
                    'unidad' => $piezas === 1 ? 'pieza vendida' : 'piezas vendidas',
                    'frase' => $piezas > 0 ? 'Vendidas en caja por ' . self::dinero($importe) . '.' : 'No hay ventas en caja de este producto en el periodo.',
                ];
                break;

            case 'ventas_categoria':
                if ($id === null) return null;
                $categoria = self::fila($db, 'SELECT nombre FROM categorias WHERE id = ? AND unidad_id = ?', [$id, $u]);
                if ($categoria === null) return null;
                $entidad = ['valor' => 'ventas_categoria:' . $id, 'etiqueta' => (string) $categoria['nombre']];
                $r = self::fila($db, 'SELECT COALESCE(SUM(ti.cantidad), 0) AS piezas, COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe '
                    . self::DE_TICKETS . ' WHERE ' . self::VENTA . ' AND ti.categoria_id = ?', array_merge($args, [$id]));
                $piezas = (int) ($r['piezas'] ?? 0);
                $importe = (float) ($r['importe'] ?? 0);
                $productos = self::filas($db, 'SELECT ti.oferta_id, MAX(ti.nombre) AS nombre, SUM(ti.cantidad) AS piezas '
                    . self::DE_TICKETS . ' WHERE ' . self::VENTA . ' AND ti.categoria_id = ? GROUP BY ti.oferta_id ORDER BY piezas DESC LIMIT ' . self::TOPE_DESGLOSE, array_merge($args, [$id]));
                foreach ($productos as $o) {
                    $detalle[] = self::renglon((string) $o['nombre'], (float) $o['piezas'], self::conteo((int) $o['piezas'], 'pieza', 'piezas'), self::irAVentas((int) $o['oferta_id']));
                }
                $detalleTitulo = $detalle !== [] ? 'Por producto' : null;
                $respuesta = [
                    'titulo' => 'Ventas de ' . $categoria['nombre'],
                    'cifra' => self::numero($piezas),
                    'unidad' => $piezas === 1 ? 'pieza vendida' : 'piezas vendidas',
                    'frase' => $piezas > 0 ? 'Vendidas en caja por ' . self::dinero($importe) . '.' : 'No hay ventas en caja de esta categoría en el periodo.',
                ];
                break;

            case 'top':
                $filas = self::filas($db, 'SELECT ti.oferta_id, MAX(ti.nombre) AS nombre, SUM(ti.cantidad) AS piezas, SUM(ti.precio * ti.cantidad) AS importe '
                    . self::DE_TICKETS . ' WHERE ' . self::VENTA . ' GROUP BY ti.oferta_id ORDER BY piezas DESC, importe DESC LIMIT 5', $args);
                foreach ($filas as $o) {
                    $detalle[] = self::renglon((string) $o['nombre'], (float) $o['piezas'], self::conteo((int) $o['piezas'], 'pieza', 'piezas') . ' · ' . self::dinero((float) $o['importe']), self::irAVentas((int) $o['oferta_id']));
                }
                $detalleTitulo = $detalle !== [] ? 'Top 5' : null;
                $respuesta = [
                    'titulo' => 'Lo más vendido',
                    'cifra' => $filas !== [] ? self::numero((int) $filas[0]['piezas']) : '0',
                    'unidad' => $filas !== [] ? 'piezas de ' . $filas[0]['nombre'] : 'piezas',
                    'frase' => $filas !== [] ? $filas[0]['nombre'] . ' encabeza el periodo.' : 'No hay ventas en caja registradas para el periodo.',
                ];
                break;

            case 'sin_venta':
                $sinVenta = "FROM ofertas_producto o
                      JOIN productos_oferta po ON po.oferta_id = o.id
                      JOIN categorias c ON c.id = o.categoria_id AND c.unidad_id = o.unidad_id
                     WHERE o.unidad_id = ? AND o.activo = 1 AND c.activo = 1
                       AND NOT EXISTS (SELECT 1 " . self::DE_TICKETS . ' WHERE ' . self::VENTA . ' AND ti.oferta_id = o.id)';
                $argsSinVenta = array_merge([$u], $args);
                $total = (int) (self::fila($db, 'SELECT COUNT(*) AS n ' . $sinVenta, $argsSinVenta)['n'] ?? 0);
                foreach (self::filas($db, 'SELECT o.id, po.nombre, c.nombre AS categoria ' . $sinVenta . ' ORDER BY c.orden, po.nombre LIMIT ' . self::TOPE_LISTA, $argsSinVenta) as $o) {
                    $detalle[] = self::renglon((string) $o['nombre'], null, (string) $o['categoria']);
                }
                $respuesta = [
                    'titulo' => 'Productos sin venta',
                    'cifra' => self::numero($total),
                    'unidad' => $total === 1 ? 'producto' : 'productos',
                    'frase' => $total > 0
                        ? 'Activos en el menú y sin ninguna venta en caja en el periodo.' . ($total > self::TOPE_LISTA ? ' Van los primeros ' . self::TOPE_LISTA . '.' : '')
                        : 'Todos los productos activos tuvieron alguna venta.',
                ];
                break;

            case 'horas_pico':
                $filas = self::filas($db, "SELECT HOUR(t.hora_apertura) AS hora, COUNT(*) AS tickets, COALESCE(SUM(x.total), 0) AS importe
                      FROM tickets t
                      LEFT JOIN (SELECT ticket_id, unidad_id, SUM(precio * cantidad) AS total FROM ticket_items
                                  WHERE unidad_id = ? AND estado <> 'cancelado' GROUP BY ticket_id, unidad_id) x
                             ON x.ticket_id = t.id AND x.unidad_id = t.unidad_id
                     WHERE t.unidad_id = ? AND t.estado = 'cerrado' AND t.hora_apertura >= ? AND t.hora_apertura <= ?
                     GROUP BY hora ORDER BY tickets DESC, hora LIMIT " . self::TOPE_DESGLOSE, [$u, $u, $desde, $hasta]);
                foreach ($filas as $h) {
                    $detalle[] = self::renglon(sprintf('%02d:00', (int) $h['hora']), (float) $h['tickets'], self::conteo((int) $h['tickets'], 'ticket', 'tickets') . ' · ' . self::dinero((float) $h['importe']));
                }
                $detalleTitulo = $detalle !== [] ? 'Por hora' : null;
                $respuesta = [
                    'titulo' => 'Horas de más venta',
                    'cifra' => $filas !== [] ? sprintf('%02d:00', (int) $filas[0]['hora']) : '—',
                    'unidad' => $filas !== [] ? self::conteo((int) $filas[0]['tickets'], 'ticket', 'tickets') : '',
                    'frase' => $filas !== [] ? 'La hora con más tickets de caja cobrados.' : 'No hay tickets de caja cerrados en el periodo.',
                ];
                break;

            default: // cancelaciones
                $filas = self::filas($db, "SELECT ti.oferta_id, MAX(ti.nombre) AS nombre, SUM(ti.cantidad) AS piezas, SUM(ti.precio * ti.cantidad) AS importe
                      FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id AND t.unidad_id = ti.unidad_id
                     WHERE t.unidad_id = ? AND t.estado = 'cerrado' AND ti.estado = 'cancelado'
                       AND COALESCE(t.hora_cierre, t.hora_apertura) >= ? AND COALESCE(t.hora_cierre, t.hora_apertura) <= ?
                     GROUP BY ti.oferta_id ORDER BY importe DESC", $args);
                $piezas = array_sum(array_map(static fn (array $o): int => (int) $o['piezas'], $filas));
                $importe = array_sum(array_map(static fn (array $o): float => (float) $o['importe'], $filas));
                foreach (array_slice($filas, 0, self::TOPE_DESGLOSE) as $o) {
                    $detalle[] = self::renglon((string) $o['nombre'], (float) $o['piezas'], self::conteo((int) $o['piezas'], 'pieza', 'piezas') . ' · ' . self::dinero((float) $o['importe']), self::irAVentas((int) $o['oferta_id']));
                }
                $detalleTitulo = $detalle !== [] ? 'Los más cancelados' : null;
                $respuesta = [
                    'titulo' => 'Cancelaciones en caja',
                    'cifra' => self::numero($piezas),
                    'unidad' => $piezas === 1 ? 'pieza cancelada' : 'piezas canceladas',
                    'frase' => $piezas > 0 ? 'Canceladas por ' . self::dinero($importe) . '.' : 'No hay cancelaciones en caja en el periodo.',
                ];
        }

        $comparacion = self::compararVentas($db, $u, $int, $id, $opcion, $p);

        return [
            'entidad' => $entidad,
            'opcion' => $variante !== null ? ['valor' => (string) $variante['id'], 'etiqueta' => (string) $variante['nombre']] : null,
            // Panadería no tiene módulo de análisis: la tarjeta no enlaza a
            // ninguna parte en vez de mandar al de Restaurante.
            'respuesta' => ['tipo' => $int] + $respuesta + [
                'comparacion' => $comparacion,
                'detalle_titulo' => $detalleTitulo,
                'detalle' => $detalle,
            ],
        ];
    }

    /** La misma cifra del periodo anterior, para decir si subió o bajó. */
    private static function compararVentas(\mysqli $db, int $u, string $int, ?int $id, ?int $opcion, array $p): ?array
    {
        $medir = static function (string $d, string $h) use ($db, $u, $int, $id, $opcion): ?float {
            [$desde, $hasta] = self::limites($d, $h);
            $args = [$u, $desde, $hasta];
            switch ($int) {
                case 'ventas_total':
                case 'ticket_promedio':
                    $r = self::fila($db, 'SELECT COUNT(DISTINCT t.id) AS n, COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe '
                        . self::DE_TICKETS . ' WHERE ' . self::VENTA, $args);
                    $n = (int) ($r['n'] ?? 0);
                    $importe = (float) ($r['importe'] ?? 0);
                    return $int === 'ticket_promedio' ? ($n > 0 ? $importe / $n : null) : $importe;
                case 'ventas_producto':
                case 'ventas_categoria':
                    $columna = $int === 'ventas_producto' ? 'ti.oferta_id' : 'ti.categoria_id';
                    $extra = $opcion !== null ? ' AND FIND_IN_SET(?, ti.variante_ids)' : '';
                    $r = self::fila($db, 'SELECT COALESCE(SUM(ti.cantidad), 0) AS v ' . self::DE_TICKETS . ' WHERE ' . self::VENTA . " AND {$columna} = ?" . $extra,
                        array_merge($args, [$id], $opcion !== null ? [$opcion] : []));
                    return (float) ($r['v'] ?? 0);
                case 'cancelaciones':
                    $r = self::fila($db, "SELECT COALESCE(SUM(ti.cantidad), 0) AS v FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id AND t.unidad_id = ti.unidad_id
                        WHERE t.unidad_id = ? AND t.estado = 'cerrado' AND ti.estado = 'cancelado'
                          AND COALESCE(t.hora_cierre, t.hora_apertura) >= ? AND COALESCE(t.hora_cierre, t.hora_apertura) <= ?", $args);
                    return (float) ($r['v'] ?? 0);
                default:
                    return null;
            }
        };
        $actual = $medir($p['desde'], $p['hasta']);
        $previo = $medir($p['previo_desde'], $p['previo_hasta']);
        if ($actual === null || $previo === null) {
            return null;
        }

        return self::comparacion($actual, $previo, (string) $p['previo_etiqueta'],
            in_array($int, ['ventas_total', 'ticket_promedio'], true) ? 'dinero' : 'piezas',
            $int === 'cancelaciones' ? 'menos' : 'mas');
    }

    // ── Almacenes ───────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private static function almacen(\mysqli $db, string $ctx, string $int, ?int $id, array $p): ?array
    {
        $alcance = self::alcance($ctx);
        [$en, $argsEn] = self::en($alcance);
        $global = $ctx === 'global';
        $t = self::TEXTOS[$ctx];
        $entidad = null;
        $detalle = [];
        $detalleTitulo = null;
        $comparacion = null;
        [$desde, $hasta] = self::limites($p['desde'], $p['hasta']);

        // La cosa de las preguntas de un artículo tiene que existir en el
        // ámbito: en Panadería, vivir en alguno de sus almacenes; en Global,
        // existir en el catálogo maestro.
        $articulo = null;
        if (in_array($int, ['stock', 'consumo', 'entradas'], true)) {
            if ($id === null) return null;
            $articulo = $global
                ? self::fila($db, 'SELECT id, nombre, unidad FROM ingredientes WHERE id = ?', [$id])
                : self::fila($db, "SELECT i.id, i.nombre, i.unidad FROM ingredientes i
                    WHERE i.id = ? AND EXISTS (SELECT 1 FROM almacen_articulos aa WHERE aa.articulo_id = i.id AND aa.unidad_id IN ({$en}))", array_merge([$id], $argsEn));
            if ($articulo === null) return null;
            $entidad = ['valor' => 'stock:' . $id, 'etiqueta' => (string) $articulo['nombre']];
        }
        $medida = (string) ($articulo['unidad'] ?? '');

        switch ($int) {
            case 'stock':
                $filas = self::filas($db, "SELECT a.nombre AS almacen, u.nombre AS negocio, aa.saldo, aa.minimo
                      FROM almacen_articulos aa
                      JOIN almacenes a ON a.id = aa.almacen_id AND a.unidad_id = aa.unidad_id
                      JOIN unidades u ON u.id = aa.unidad_id
                     WHERE aa.articulo_id = ? AND aa.unidad_id IN ({$en}) AND aa.activo = 1 AND a.activo = 1
                     ORDER BY u.id, a.nombre", array_merge([$id], $argsEn));
                $saldo = array_sum(array_map(static fn (array $f): float => (float) $f['saldo'], $filas));
                $minimo = array_sum(array_map(static fn (array $f): float => (float) $f['minimo'], $filas));
                // Un almacén justo no se compensa con el sobrante de otro: la
                // alerta es por almacén, como en Almacenes.
                $bajos = array_values(array_filter($filas, static fn (array $f): bool => (float) $f['saldo'] <= (float) $f['minimo']));
                foreach (array_slice($filas, 0, self::TOPE_DESGLOSE) as $f) {
                    $detalle[] = self::renglon(self::dondeAlmacen($f, $global), (float) $f['saldo'],
                        self::cantidad((float) $f['saldo'], $medida) . ' · mínimo ' . self::cantidad((float) $f['minimo'], $medida));
                }
                $detalleTitulo = $detalle !== [] ? 'Por almacén' : null;
                if ($filas === []) {
                    $frase = $global ? 'No está asociado a ningún almacén activo.' : 'No está asociado a ningún almacén activo de Panadería.';
                    $estado = null;
                } elseif ($saldo <= 0) {
                    $frase = 'Está agotado. Mínimo: ' . self::cantidad($minimo, $medida) . '.';
                    $estado = 'agotado';
                } elseif ($bajos !== []) {
                    $frase = count($bajos) === 1
                        ? self::dondeAlmacen($bajos[0], $global) . ' está en o por debajo de su mínimo.'
                        : self::conteo(count($bajos), 'almacén está', 'almacenes están') . ' en o por debajo de su mínimo.';
                    $estado = 'bajo';
                } else {
                    $frase = 'Por encima del mínimo de ' . self::cantidad($minimo, $medida) . '.';
                    $estado = 'ok';
                }
                $respuesta = [
                    'titulo' => (string) $articulo['nombre'],
                    'cifra' => self::numero($saldo, 3),
                    'unidad' => $medida . ' ' . $t['en_almacen'],
                    'frase' => $frase,
                ] + ($estado !== null ? ['estado' => $estado] : []);
                break;

            case 'bajo_stock':
                // Panadería suma sus almacenes por artículo; Global además lo
                // separa por negocio, para decir dónde falta.
                $porNegocio = $global ? ', aa.unidad_id' : '';
                $filas = self::filas($db, "SELECT i.id, i.nombre, i.unidad, u.nombre AS negocio, SUM(aa.saldo) AS saldo, SUM(aa.minimo) AS minimo
                      FROM almacen_articulos aa
                      JOIN almacenes a ON a.id = aa.almacen_id AND a.unidad_id = aa.unidad_id
                      JOIN ingredientes i ON i.id = aa.articulo_id
                      JOIN unidades u ON u.id = aa.unidad_id
                     WHERE aa.unidad_id IN ({$en}) AND aa.activo = 1 AND a.activo = 1
                     GROUP BY i.id, i.nombre, i.unidad{$porNegocio}, u.nombre
                    HAVING MAX(aa.saldo <= aa.minimo) = 1
                     ORDER BY SUM(aa.saldo) / NULLIF(SUM(aa.minimo), 0), i.nombre", $argsEn);
                foreach (array_slice($filas, 0, self::TOPE_DESGLOSE) as $f) {
                    $detalle[] = self::renglon((string) $f['nombre'] . ($global ? ' · ' . $f['negocio'] : ''), (float) $f['saldo'],
                        self::cantidad((float) $f['saldo'], (string) $f['unidad']) . ' · mínimo ' . self::cantidad((float) $f['minimo'], (string) $f['unidad']),
                        ['intencion' => 'stock', 'id' => (int) $f['id'], 'opcion' => null]);
                }
                $detalleTitulo = $detalle !== [] ? 'Bajo el mínimo' : null;
                $n = count($filas);
                $respuesta = [
                    'titulo' => 'Lo que está por agotarse',
                    'cifra' => self::numero($n),
                    'unidad' => $global ? ($n === 1 ? 'alerta' : 'alertas') : ($n === 1 ? 'artículo' : 'artículos'),
                    'frase' => $n > 0
                        ? 'En o por debajo de su mínimo en ' . $t['almacenes'] . '.' . ($n > self::TOPE_DESGLOSE ? ' Van los ' . self::TOPE_DESGLOSE . ' más justos.' : '')
                        : 'Ningún artículo está por debajo de su mínimo.',
                ];
                break;

            case 'consumo':
                // Ventas netas de cancelaciones y, aparte, la merma: la misma
                // lectura que el consumo de Restaurante.
                $filas = self::filas($db, "SELECT u.nombre AS negocio, m.tipo, SUM(-m.delta) AS cantidad
                      FROM movimientos_inventario m JOIN unidades u ON u.id = m.unidad_id
                     WHERE m.unidad_id IN ({$en}) AND m.articulo_id = ? AND m.tipo IN ('venta', 'cancelacion', 'merma')
                       AND m.fecha >= ? AND m.fecha <= ?
                     GROUP BY u.id, u.nombre, m.tipo ORDER BY u.id", array_merge($argsEn, [$id, $desde, $hasta]));
                $porNegocio = [];
                foreach ($filas as $f) {
                    $negocio = (string) $f['negocio'];
                    $porNegocio[$negocio] ??= ['ventas' => 0.0, 'merma' => 0.0];
                    $porNegocio[$negocio][$f['tipo'] === 'merma' ? 'merma' : 'ventas'] += (float) $f['cantidad'];
                }
                $ventas = max(0.0, array_sum(array_column($porNegocio, 'ventas')));
                $merma = max(0.0, array_sum(array_column($porNegocio, 'merma')));
                if ($global) {
                    foreach ($porNegocio as $negocio => $c) {
                        $detalle[] = self::renglon($negocio, max(0.0, $c['ventas']),
                            self::cantidad(max(0.0, $c['ventas']), $medida) . ($c['merma'] > 0 ? ' · merma ' . self::cantidad($c['merma'], $medida) : ''));
                    }
                    $detalleTitulo = $detalle !== [] ? 'Por negocio' : null;
                }
                $respuesta = [
                    'titulo' => 'Consumo de ' . $articulo['nombre'],
                    'cifra' => self::numero($ventas, 3),
                    'unidad' => $medida,
                    'frase' => ($ventas > 0 ? 'Por ventas, netas de cancelaciones.' : 'Sin consumo por ventas en el periodo.')
                        . ($merma > 0 ? ' Además, ' . self::cantidad($merma, $medida) . ' de merma.' : ''),
                ];
                $comparacion = self::compararMovimiento($db, $en, $argsEn, $id, "'venta', 'cancelacion'", -1, $p, 'neutro', $medida);
                break;

            case 'entradas':
                // Sólo `entrada`: lo que llega de fuera. Una transferencia es
                // salida de un almacén y entrada de otro de la misma casa, y
                // contarla aquí sumaría dos veces lo mismo en el consolidado.
                $filas = self::filas($db, "SELECT a.nombre AS almacen, u.nombre AS negocio, SUM(m.delta) AS cantidad,
                           SUM(m.delta * COALESCE(m.costo_unitario, 0)) AS costo, COUNT(DISTINCT m.operacion_id) AS operaciones
                      FROM movimientos_inventario m
                      JOIN almacenes a ON a.id = m.almacen_id AND a.unidad_id = m.unidad_id
                      JOIN unidades u ON u.id = m.unidad_id
                     WHERE m.unidad_id IN ({$en}) AND m.articulo_id = ? AND m.tipo = 'entrada' AND m.fecha >= ? AND m.fecha <= ?
                     GROUP BY m.almacen_id, a.nombre, u.id, u.nombre ORDER BY cantidad DESC", array_merge($argsEn, [$id, $desde, $hasta]));
                $cantidad = array_sum(array_map(static fn (array $f): float => (float) $f['cantidad'], $filas));
                $costo = array_sum(array_map(static fn (array $f): float => (float) $f['costo'], $filas));
                $operaciones = array_sum(array_map(static fn (array $f): int => (int) $f['operaciones'], $filas));
                foreach (array_slice($filas, 0, self::TOPE_DESGLOSE) as $f) {
                    $detalle[] = self::renglon(self::dondeAlmacen($f, $global), (float) $f['cantidad'],
                        self::cantidad((float) $f['cantidad'], $medida) . ' · ' . self::dinero((float) $f['costo']));
                }
                $detalleTitulo = count($filas) > 1 || $global ? ($detalle !== [] ? 'Por almacén' : null) : null;
                $respuesta = [
                    'titulo' => 'Entradas de ' . $articulo['nombre'],
                    'cifra' => self::numero($cantidad, 3),
                    'unidad' => $medida,
                    'frase' => $cantidad > 0
                        ? 'Recibido en ' . self::conteo($operaciones, 'entrada', 'entradas') . ', por ' . self::dinero($costo) . ' de costo.'
                        : 'No hubo entradas de este artículo en el periodo.',
                ];
                $comparacion = self::compararMovimiento($db, $en, $argsEn, $id, "'entrada'", 1, $p, 'neutro', $medida);
                break;

            case 'mermas':
                $porNegocio = $global ? ', m.unidad_id' : '';
                $filas = self::filas($db, "SELECT i.id, i.nombre, i.unidad, u.nombre AS negocio, SUM(-m.delta) AS cantidad,
                           SUM(-m.delta * COALESCE(m.costo_unitario, 0)) AS costo
                      FROM movimientos_inventario m
                      JOIN ingredientes i ON i.id = m.articulo_id
                      JOIN unidades u ON u.id = m.unidad_id
                     WHERE m.unidad_id IN ({$en}) AND m.tipo = 'merma' AND m.fecha >= ? AND m.fecha <= ?
                     GROUP BY i.id, i.nombre, i.unidad{$porNegocio}, u.nombre ORDER BY costo DESC, cantidad DESC", array_merge($argsEn, [$desde, $hasta]));
                $costo = array_sum(array_map(static fn (array $f): float => (float) $f['costo'], $filas));
                foreach (array_slice($filas, 0, self::TOPE_DESGLOSE) as $f) {
                    $detalle[] = self::renglon((string) $f['nombre'] . ($global ? ' · ' . $f['negocio'] : ''), (float) $f['costo'],
                        self::cantidad((float) $f['cantidad'], (string) $f['unidad']) . ' · ' . self::dinero((float) $f['costo']),
                        ['intencion' => 'stock', 'id' => (int) $f['id'], 'opcion' => null]);
                }
                $detalleTitulo = $detalle !== [] ? 'Lo que más costó' : null;
                $articulos = count(array_unique(array_map(static fn (array $f): int => (int) $f['id'], $filas)));
                $respuesta = [
                    'titulo' => 'Mermas',
                    'cifra' => self::dinero($costo),
                    'unidad' => 'de costo',
                    'frase' => $filas !== []
                        ? self::conteo($articulos, 'artículo mermado', 'artículos mermados') . ' en ' . $t['almacenes'] . '.'
                        : 'No hubo mermas en el periodo.',
                ];
                $actual = $costo;
                [$pd, $ph] = self::limites($p['previo_desde'], $p['previo_hasta']);
                $previo = (float) (self::fila($db, "SELECT COALESCE(SUM(-delta * COALESCE(costo_unitario, 0)), 0) AS v FROM movimientos_inventario
                    WHERE unidad_id IN ({$en}) AND tipo = 'merma' AND fecha >= ? AND fecha <= ?", array_merge($argsEn, [$pd, $ph]))['v'] ?? 0);
                $comparacion = self::comparacion($actual, $previo, (string) $p['previo_etiqueta'], 'dinero', 'menos');
                break;

            default: // transferencias
                $estados = array_keys(self::TRANSFERENCIA_ABIERTA);
                [$enEstados, $argsEstados] = self::en($estados);
                $filas = self::filas($db, "SELECT t.id, t.estado, ao.nombre AS origen, uo.slug AS origen_slug, uo.nombre AS origen_negocio,
                           ad.nombre AS destino, ud.slug AS destino_slug, ud.nombre AS destino_negocio, COUNT(l.id) AS lineas
                      FROM transferencias t
                      JOIN almacenes ao ON ao.id = t.almacen_origen_id AND ao.unidad_id = t.origen_unidad_id
                      JOIN unidades uo ON uo.id = t.origen_unidad_id
                      JOIN almacenes ad ON ad.id = t.almacen_destino_id AND ad.unidad_id = t.destino_unidad_id
                      JOIN unidades ud ON ud.id = t.destino_unidad_id
                      LEFT JOIN transferencia_lineas l ON l.transferencia_id = t.id
                     WHERE t.estado IN ({$enEstados}) AND (t.origen_unidad_id IN ({$en}) OR t.destino_unidad_id IN ({$en}))
                     GROUP BY t.id, t.estado, ao.nombre, uo.slug, uo.nombre, ad.nombre, ud.slug, ud.nombre, t.creada_at
                     ORDER BY FIELD(t.estado, {$enEstados}), t.creada_at", array_merge($argsEstados, $argsEn, $argsEn, $argsEstados));
                $porEstado = array_count_values(array_map(static fn (array $f): string => (string) $f['estado'], $filas));
                // Del almacén propio basta el nombre; el del otro lado lleva su
                // negocio, que es lo que dice de dónde viene o a dónde va.
                $lado = static fn (string $almacen, string $slug, string $negocio): string => $global || $slug !== $ctx ? $almacen . ' (' . $negocio . ')' : $almacen;
                foreach (array_slice($filas, 0, self::TOPE_DESGLOSE) as $f) {
                    $detalle[] = self::renglon(
                        '#' . (int) $f['id'] . ' ' . $lado((string) $f['origen'], (string) $f['origen_slug'], (string) $f['origen_negocio'])
                            . ' → ' . $lado((string) $f['destino'], (string) $f['destino_slug'], (string) $f['destino_negocio']),
                        null,
                        self::TRANSFERENCIA_ABIERTA[$f['estado']] . ' · ' . self::conteo((int) $f['lineas'], 'artículo', 'artículos')
                    );
                }
                $resumen = [];
                foreach (self::TRANSFERENCIA_ABIERTA as $estado => $etiqueta) {
                    if (!empty($porEstado[$estado])) $resumen[] = $porEstado[$estado] . ' ' . mb_strtolower($etiqueta);
                }
                $n = count($filas);
                $respuesta = [
                    'titulo' => 'Transferencias en curso',
                    'cifra' => self::numero($n),
                    'unidad' => $n === 1 ? 'abierta' : 'abiertas',
                    'frase' => $n > 0 ? self::mayuscula(implode(', ', $resumen)) . '.' : 'No hay transferencias abiertas.',
                ];
                $detalleTitulo = $detalle !== [] ? 'Abiertas' : null;
        }

        $base = $global ? '/admin/global' : '/admin/' . $ctx;

        return [
            'entidad' => $entidad,
            'respuesta' => ['tipo' => $int] + $respuesta + [
                'comparacion' => $comparacion,
                'detalle_titulo' => $detalleTitulo,
                'detalle' => $detalle,
                'enlace' => $int === 'transferencias'
                    ? ['href' => $base . '/almacenes?vista=transferencias', 'texto' => 'Abrir transferencias']
                    : ['href' => $base . '/almacenes', 'texto' => 'Abrir almacenes'],
            ],
        ];
    }

    /**
     * Comparación de un movimiento contra el periodo anterior. `$signo` pone
     * en positivo lo que sale (venta, -1) o lo que entra (entrada, +1).
     */
    private static function compararMovimiento(\mysqli $db, string $en, array $argsEn, int $id, string $tipos, int $signo, array $p, string $sentido, string $medida): ?array
    {
        $medir = static function (string $d, string $h) use ($db, $en, $argsEn, $id, $tipos, $signo): float {
            [$desde, $hasta] = self::limites($d, $h);
            $r = self::fila($db, "SELECT COALESCE(SUM(delta), 0) * {$signo} AS v FROM movimientos_inventario
                WHERE unidad_id IN ({$en}) AND articulo_id = ? AND tipo IN ({$tipos}) AND fecha >= ? AND fecha <= ?",
                array_merge($argsEn, [$id, $desde, $hasta]));
            return max(0.0, (float) ($r['v'] ?? 0));
        };

        return self::comparacion($medir($p['desde'], $p['hasta']), $medir($p['previo_desde'], $p['previo_hasta']),
            (string) $p['previo_etiqueta'], 'cantidad', $sentido, $medida);
    }

    // ── Menú de Panadería ───────────────────────────────────────

    /** @return array<string, mixed>|null */
    private static function menu(\mysqli $db, string $ctx, string $int): ?array
    {
        $detalle = [];
        if ($int === 'sin_receta') {
            $u = ContextoNegocio::unidad($ctx);
            $sinReceta = 'FROM ofertas_producto o
                  JOIN productos_oferta po ON po.oferta_id = o.id
                  JOIN categorias c ON c.id = o.categoria_id AND c.unidad_id = o.unidad_id
                 WHERE o.unidad_id = ? AND o.activo = 1
                   AND NOT EXISTS (SELECT 1 FROM recetas r WHERE r.oferta_id = o.id AND r.unidad_id = o.unidad_id AND r.activo = 1)';
            $total = (int) (self::fila($db, 'SELECT COUNT(*) AS n ' . $sinReceta, [$u])['n'] ?? 0);
            foreach (self::filas($db, 'SELECT po.nombre, c.nombre AS categoria ' . $sinReceta . ' ORDER BY c.orden, po.nombre LIMIT ' . self::TOPE_LISTA, [$u]) as $f) {
                $detalle[] = self::renglon((string) $f['nombre'], null, (string) $f['categoria']);
            }

            return ['respuesta' => [
                'tipo' => 'sin_receta',
                'titulo' => 'Productos sin receta',
                'cifra' => self::numero($total),
                'unidad' => $total === 1 ? 'producto' : 'productos',
                'frase' => $total > 0
                    ? 'Activos en el menú y sin receta: su venta no descontará almacén.' . ($total > self::TOPE_LISTA ? ' Van los primeros ' . self::TOPE_LISTA . '.' : '')
                    : 'Todos los productos activos tienen receta.',
                'comparacion' => null,
                'detalle_titulo' => $detalle !== [] ? 'Sin receta' : null,
                'detalle' => $detalle,
                'enlace' => ['href' => '/admin/panaderia/menu', 'texto' => 'Abrir menú'],
            ]];
        }

        return null;
    }

    // ── Plantilla y ejemplos ────────────────────────────────────

    /**
     * La pregunta con sus huecos. Sin `$periodo` (vacío) es la forma de la
     * pregunta para capacidades(); con él, la de una respuesta, con cada hueco
     * lleno y sus opciones.
     *
     * @param array{valor: string, etiqueta: string}|null $entidad
     * @param array{valor: string, etiqueta: string}|null $opcion
     * @return list<array<string, mixed>>
     */
    private static function plantilla(string $ctx, string $int, ?array $entidad, ?array $opcion, array $periodo): array
    {
        $t = self::TEXTOS[$ctx];
        $respuesta = $periodo !== [];
        $texto = static fn (string $s): array => ['tipo' => 'texto', 'texto' => $s];
        $sep = $texto('·');
        $hp = ['tipo' => 'hueco', 'campo' => 'periodo', 'rotulo' => 'Periodo'];
        if ($respuesta) {
            $hp += ['valor' => $periodo['clave'], 'etiqueta' => $periodo['etiqueta'], 'opciones' => self::opcionesPeriodo()];
        }
        $hueco = static function (string $catalogo, string $rotulo, ?array $lleno, ?string $fija = null) use ($respuesta): array {
            $h = ['tipo' => 'hueco', 'campo' => 'entidad', 'rotulo' => $rotulo, 'catalogo' => $catalogo];
            if ($fija !== null) $h['intencion'] = $fija;
            if ($respuesta) $h += ['valor' => (string) ($lleno['valor'] ?? ''), 'etiqueta' => (string) ($lleno['etiqueta'] ?? '')];
            return $h;
        };
        $articulo = static fn (?string $fija = null): array => $hueco('ingredientes', 'Artículo', $entidad, $fija);

        switch ($int) {
            case 'ventas_total':
                return [$texto('Ventas de'), $hueco('ventas', $t['rotulo_ventas'], ['valor' => 'ventas_total', 'etiqueta' => $t['todo']]), $sep, $hp];
            case 'ventas_producto':
            case 'ventas_categoria':
                $partes = [$texto('Ventas de'), $hueco('ventas', $t['rotulo_ventas'], $entidad)];
                if ($respuesta && $int === 'ventas_producto' && $entidad !== null) {
                    $opciones = self::opcionesDe((int) substr($entidad['valor'], strlen('ventas_producto:')));
                    if ($opciones !== []) {
                        $partes[] = $texto('con');
                        $partes[] = ['tipo' => 'hueco', 'campo' => 'opcion', 'rotulo' => 'Opción',
                            'valor' => (string) ($opcion['valor'] ?? ''), 'etiqueta' => (string) ($opcion['etiqueta'] ?? 'cualquier opción'), 'opciones' => $opciones];
                    }
                }
                return array_merge($partes, [$sep, $hp]);
            case 'stock':
                return [$articulo(), $texto($t['en_almacen'])];
            case 'consumo':
                return [$texto('Consumo de'), $articulo('consumo'), $sep, $hp];
            case 'entradas':
                return [$texto('Entradas de'), $articulo('entradas'), $sep, $hp];
            default:
                return in_array($int, self::SIN_PERIODO, true)
                    ? [$texto(self::TITULOS[$int])]
                    : [$texto(self::TITULOS[$int]), $sep, $hp];
        }
    }

    /** Las opciones elegibles de un producto (sin la predeterminada, que es «como en el menú»). */
    private static function opcionesDe(int $ofertaId): array
    {
        if ($ofertaId < 1) return [];
        $filas = self::filas(ContextoNegocio::db(),
            'SELECT id, grupo, nombre FROM producto_variantes WHERE oferta_id = ? AND unidad_id = ? AND activo = 1 AND predeterminada = 0 ORDER BY orden_grupo, orden, id',
            [$ofertaId, ContextoNegocio::unidad('panaderia')]);

        return array_map(static fn (array $r): array => [
            'valor' => (string) $r['id'],
            'etiqueta' => (string) $r['nombre'],
            'grupo' => (string) $r['grupo'],
            'consulta' => ['intencion' => 'ventas_producto', 'id' => $ofertaId, 'opcion' => (int) $r['id']],
        ], $filas);
    }

    /**
     * Ejemplos reales de una pregunta, con la forma que lee AdminIaController
     * (la consulta y las partes, la cosa como primera parte hueca). Las
     * preguntas de una cosa toman las primeras de su lista; las demás, un
     * periodo en el que suele haber datos.
     *
     * @return list<array{consulta: array<string, mixed>, partes: list<array{texto: string, hueco: bool}>}>
     */
    private static function ejemplos(string $ctx, string $int, array $catalogo): array
    {
        $periodo = in_array($int, self::SIN_PERIODO, true) ? null : (self::PERIODO_DE_EJEMPLO[$int] ?? 'semana');
        $consulta = static fn (?int $id): array => ['intencion' => $int, 'id' => $id, 'opcion' => null, 'periodo' => $periodo];

        $lista = match ($int) {
            'ventas_producto' => array_filter($catalogo['ventas'], static fn (array $o): bool => str_starts_with($o['valor'], 'ventas_producto:')),
            'ventas_categoria' => array_filter($catalogo['ventas'], static fn (array $o): bool => str_starts_with($o['valor'], 'ventas_categoria:')),
            'stock', 'consumo', 'entradas' => array_filter($catalogo['ingredientes'], static fn (array $o): bool => $o['grupo'] !== 'Inactivos'),
            default => null,
        };
        if ($lista === null) {
            return [['consulta' => $consulta(null), 'partes' => []]];
        }

        $ejemplos = [];
        foreach (array_slice(array_values($lista), 0, self::MAX_EJEMPLOS) as $o) {
            $ejemplos[] = [
                'consulta' => $consulta((int) $o['consulta']['id']),
                'partes' => [['texto' => (string) $o['etiqueta'], 'hueco' => true]],
            ];
        }

        return $ejemplos;
    }

    /** Las opciones del hueco de periodo, agrupadas por escala como en Restaurante. */
    private static function opcionesPeriodo(): array
    {
        $grupos = ['hoy' => 'Días', 'ayer' => 'Días', 'antier' => 'Días', 'semana' => 'Semanas', 'semana_pasada' => 'Semanas',
            '7d' => 'Semanas', 'mes' => 'Meses', 'mes_pasado' => 'Meses', '30d' => 'Meses'];
        $opciones = [];
        foreach (ConsultasRapidas::PERIODOS as $clave => $etiqueta) {
            $opciones[] = ['valor' => $clave, 'etiqueta' => $etiqueta, 'grupo' => $grupos[$clave] ?? 'Periodo', 'consulta' => ['periodo' => $clave]];
        }

        return $opciones;
    }

    /** El texto canónico: la plantilla dicha en una línea, con los huecos llenos. */
    private static function textoDe(array $plantilla): string
    {
        return implode(' ', array_map(static fn (array $p): string => $p['tipo'] === 'hueco' ? (string) $p['etiqueta'] : (string) $p['texto'], $plantilla));
    }

    /** @return array<string, mixed> */
    private static function noEncontrado(string $ctx, array $p): array
    {
        return [
            'ok' => true,
            'consulta' => '',
            'periodo' => $p,
            'alternativas' => [],
            'entendida' => false,
            'interpretacion' => ['intencion' => null, 'id' => null, 'opcion' => null, 'periodo' => $p['clave'], 'texto' => ''],
            'plantilla' => [],
            'respuesta' => ['tipo' => 'sin_resultado', 'titulo' => 'No encontré el registro', 'frase' => 'Elige un elemento de la lista de ' . self::TEXTOS[$ctx]['nombre'] . '.'],
            'sugerencias' => [],
        ];
    }

    // ── Alcance y permisos ──────────────────────────────────────

    /**
     * El ámbito pedido tiene que ser el de la ruta: un controlador no puede
     * consultar Global desde una página de Panadería ni al revés.
     */
    private static function ambito(string $ctx): void
    {
        if (!in_array($ctx, self::AMBITOS, true) || ContextoNegocio::slug() !== $ctx) {
            throw new InventarioError('AMBITO_NO_DISPONIBLE', 'Las consultas no están disponibles en este ámbito.');
        }
    }

    private static function permite(string $ctx, array $modulos): bool
    {
        foreach ($modulos as $modulo) {
            try {
                ContextoNegocio::exigir($modulo, 'ver', $ctx);
                return true;
            } catch (InventarioError $e) {
                if ($e->codigo !== 'PERMISO_DENEGADO') throw $e;
            }
        }

        return false;
    }

    /**
     * Las unidades que lee el ámbito: Panadería, la suya; Global, todas, que
     * es la lectura consolidada de Almacenes.
     *
     * @return list<int>
     */
    private static function alcance(string $ctx): array
    {
        if ($ctx !== 'global') {
            return [ContextoNegocio::unidad($ctx)];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], self::filas(ContextoNegocio::db(), 'SELECT id FROM unidades ORDER BY id', []));
        if ($ids === []) {
            throw new InventarioError('AMBITO_INVALIDO', 'No se encontraron las unidades de la organización.');
        }

        return $ids;
    }

    /**
     * Los marcadores de un IN y sus valores. Los valores siempre viajan como
     * parámetros: nada del alcance se pega al SQL.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private static function en(array $valores): array
    {
        return [implode(', ', array_fill(0, count($valores), '?')), array_values($valores)];
    }

    // ── SQL y formato ───────────────────────────────────────────

    private static function filas(\mysqli $db, string $sql, array $args): array
    {
        $s = $db->prepare($sql);
        $s->execute($args);

        return $s->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private static function fila(\mysqli $db, string $sql, array $args): ?array
    {
        return self::filas($db, $sql, $args)[0] ?? null;
    }

    /** @return array{0: string, 1: string} */
    private static function limites(string $desde, string $hasta): array
    {
        return [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
    }

    /** Dónde está un almacén: en Global, con su negocio delante. */
    private static function dondeAlmacen(array $f, bool $global): string
    {
        return $global ? $f['negocio'] . ' · ' . $f['almacen'] : (string) $f['almacen'];
    }

    /** @return array{etiqueta: string, valor: float|string, valor_texto: string, consulta?: array<string, mixed>} */
    private static function renglon(string $etiqueta, ?float $valor, string $valorTexto, ?array $consulta = null): array
    {
        // Sin valor numérico no hay barra: el renglón es una palabra (la
        // categoría, el estado), no una magnitud que comparar.
        $r = ['etiqueta' => $etiqueta, 'valor' => $valor ?? $valorTexto, 'valor_texto' => $valorTexto];
        if ($consulta !== null) $r['consulta'] = $consulta;

        return $r;
    }

    private static function irAVentas(int $ofertaId): array
    {
        return ['intencion' => 'ventas_producto', 'id' => $ofertaId, 'opcion' => null];
    }

    /**
     * @param 'dinero'|'piezas'|'cantidad' $forma
     * @param 'mas'|'menos'|'neutro' $sentido  qué dirección es buena
     */
    private static function comparacion(float $actual, float $previo, string $contra, string $forma, string $sentido, string $medida = ''): ?array
    {
        if ($actual == 0.0 && $previo == 0.0) {
            return null;
        }
        $dif = $actual - $previo;
        $magnitud = match ($forma) {
            'dinero' => self::dinero(abs($dif)),
            'cantidad' => self::cantidad(abs($dif), $medida),
            default => self::numero(abs($dif)),
        };
        $tendencia = $dif > 0 ? 'sube' : ($dif < 0 ? 'baja' : 'igual');
        $lectura = $tendencia === 'igual' || $sentido === 'neutro' ? 'igual' : ((($tendencia === 'sube') === ($sentido === 'mas')) ? 'bien' : 'mal');
        $frente = str_starts_with($contra, 'el ') ? 'frente al ' . substr($contra, 3) : 'frente a ' . $contra;

        return [
            'texto' => $dif == 0.0 ? 'Igual que ' . $contra : ($dif > 0 ? '+' : '−') . $magnitud . ' ' . $frente,
            'tendencia' => $tendencia,
            'lectura' => $lectura,
        ];
    }

    private static function conteo(int $n, string $uno, string $varios): string
    {
        return self::numero($n) . ' ' . ($n === 1 ? $uno : $varios);
    }

    private static function dinero(float $v): string
    {
        return '$' . number_format($v, 2);
    }

    private static function cantidad(float $v, string $medida): string
    {
        return trim(self::numero($v, 3) . ' ' . $medida);
    }

    /** Sin ceros sobrantes: 2.500 se lee 2.5 y 12.000, 12. */
    private static function numero(float|int $v, int $decimales = 0): string
    {
        $texto = number_format((float) $v, $decimales, '.', ',');

        return $decimales > 0 ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }

    private static function mayuscula(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
