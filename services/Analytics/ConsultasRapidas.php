<?php

/**
 * Consultas rápidas del panel: una frase en español → una respuesta.
 *
 * «¿Cuántos capuchinos se vendieron ayer?», «licor 43 en almacén», «lo más
 * vendido esta semana», «qué está por agotarse». No es un modelo de lenguaje:
 * es un intérprete de reglas que reconoce el PERIODO, la INTENCIÓN y la COSA
 * (platillo o ingrediente) y contesta con una consulta concreta. Lo que no
 * entiende lo dice, y ofrece lo que más se parece.
 *
 * Las ventas se cuentan como en el resto del panel: tickets cerrados, sin
 * renglones cancelados, fechados por COALESCE(hora_cierre, hora_apertura)
 * —el momento del cobro—. El nombre del platillo une por ticket_items.nombre,
 * como el COGS y las analíticas; la variante se desglosa por su rótulo
 * congelado, que no cambia aunque se renombre la opción.
 */

namespace Services\Analytics;

use Model\ActiveRecord;

final class ConsultasRapidas
{
    public const AMBITOS = ['ventas', 'productos', 'todo'];

    /** Periodos que entiende, en el orden en que se ofrecen como píldoras. */
    public const PERIODOS = [
        'hoy' => 'Hoy',
        'ayer' => 'Ayer',
        'semana' => 'Esta semana',
        '7d' => 'Últimos 7 días',
        'mes' => 'Este mes',
        '30d' => 'Últimos 30 días',
    ];

    /** Franjas del día: [primera hora, última hora, cómo se dice]. */
    private const FRANJAS = [
        'manana' => [0, 12, 'en la mañana'],
        'tarde' => [13, 18, 'en la tarde'],
        'noche' => [19, 23, 'en la noche'],
    ];

    /** Día de la semana como se escribe ya normalizado → [DAYOFWEEK - 1, cómo se dice]. */
    private const DIAS = [
        'domingo' => [0, 'los domingos'], 'lunes' => [1, 'los lunes'], 'martes' => [2, 'los martes'],
        'miercoles' => [3, 'los miércoles'], 'jueves' => [4, 'los jueves'], 'viernes' => [5, 'los viernes'],
        'sabado' => [6, 'los sábados'],
    ];

    /** Palabras que no aportan nada a la búsqueda de la cosa. */
    private const VACIAS = [
        'cuantos', 'cuantas', 'cuanto', 'cuanta', 'se', 'vendieron', 'vendio', 'vendimos', 'vendi', 'venta', 'ventas',
        'vende', 'vendido', 'vendidos', 'pidieron', 'pidio', 'salieron', 'salio', 'hay', 'queda', 'quedan', 'tenemos',
        'tengo', 'en', 'el', 'la', 'los', 'las', 'de', 'del', 'al', 'a', 'un', 'una', 'unos', 'unas', 'y', 'o', 'que',
        'qué', 'me', 'nos', 'por', 'para', 'con', 'sin', 'es', 'son', 'fue', 'fueron', 'hoy', 'ayer', 'antier',
        'anteayer', 'semana', 'mes', 'pasada', 'pasado', 'este', 'esta', 'ultimos', 'ultimas', 'dias', 'dia',
        'almacen', 'bodega', 'inventario', 'stock', 'existencia', 'existencias', 'cantidad', 'total', 'llevamos',
        'van', 'va', 'lleva', 'ordenes', 'orden', 'pedidos', 'pedido', 'platillos', 'platillo', 'producto',
        'productos', 'ingrediente', 'ingredientes', 'mi', 'mis', 'su', 'sus', 'cuantas', 'unidades', 'piezas',
    ];

    /** Cómo se escribe en el piso → cómo se llama en la carta. */
    private const SINONIMOS = [
        'capuchino' => 'cappuccino',
        'capuccino' => 'cappuccino',
        'capucino' => 'cappuccino',
        'expreso' => 'espresso',
        'expresso' => 'espresso',
        'late' => 'latte',
        'hamburguesas' => 'hamburguesa',
        'chilaquil' => 'chilaquiles',
        'ribeye' => 'rib eye',
        'negroni' => 'pestalozzi negroni',
        'gintonic' => 'gin tonic',
        'spritz' => 'aperol spritz',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function responder(string $texto, string $ambito = 'todo', ?string $periodoForzado = null): array
    {
        $texto = trim(mb_substr($texto, 0, 160));
        $ambito = in_array($ambito, self::AMBITOS, true) ? $ambito : 'todo';
        $norma = self::normalizar($texto);

        // Con un día de la semana y sin periodo, «hoy» casi nunca es ese día:
        // se mira un tramo que tenga varios.
        $horario = self::horario($norma);
        $periodo = $periodoForzado !== null && isset(self::PERIODOS[$periodoForzado])
            ? self::periodo($periodoForzado)
            : self::detectarPeriodo($norma, $horario !== null && $horario['dow'] !== null ? '8s' : 'hoy');
        if ($horario !== null) {
            $periodo['frase'] .= ' ' . $horario['frase'];
            $periodo['hora_sql'] = ' AND ' . $horario['sql'];
            $periodo['hora_q'] = ' ' . $horario['frase'];
        }
        // El total no se separa por hora (decisión de alcance): decirlo, en vez
        // de contestar el total del periodo como si fuera el de esa franja.
        $total = static fn (): array => $horario === null ? self::ventasTotales($periodo) : [
            'tipo' => 'sin_resultado',
            'titulo' => 'El total no se separa por hora',
            'frase' => 'Por hora se consulta lo más vendido y las ventas de un platillo; por ejemplo, «lo más vendido en la noche».',
        ];

        $quiereStock = (bool) preg_match('/\b(hay|queda|quedan|almacen|bodega|inventario|stock|existencia|existencias|tenemos)\b/u', $norma);
        $quiereVentas = (bool) preg_match('/\b(vend\w*|venta\w*|pidieron|pidio|salieron|cuantos|cuantas|llevamos)\b/u', $norma);

        $base = ['ok' => true, 'consulta' => $texto, 'periodo' => $periodo, 'alternativas' => []];

        if ($norma === '') {
            return $base + ['respuesta' => null];
        }

        // Intenciones sin «cosa».
        if (preg_match('/\b(mas vendid\w*|top|lo que mas|estrella|mas pedid\w*|favorit\w*)\b/u', $norma)) {
            return $base + ['respuesta' => self::topVendidos($periodo)];
        }
        if (preg_match('/\b(bajo|baja|agotar\w*|agotad\w*|faltan|falta|minimo|reabastec\w*|comprar|surtir)\b/u', $norma)) {
            return $base + ['respuesta' => self::bajoStock()];
        }
        if (preg_match('/\b(ticket promedio|promedio)\b/u', $norma)
            || preg_match('/^(cuanto )?(vendimos|vendi|ventas|venta|facturamos|ingresos)( de| del| en)?( (hoy|ayer|antier|anteayer|esta semana|semana|este mes|mes|ultimos \d+ dias))?$/u', $norma)) {
            return $base + ['respuesta' => $total()];
        }

        $termino = self::terminoDeBusqueda($horario['resto'] ?? $norma);
        if ($termino === '') {
            return $base + ['respuesta' => $quiereStock ? self::bajoStock() : $total()];
        }

        $productos = self::coincidencias('productos', $termino);
        $ingredientes = self::coincidencias('ingredientes', $termino);

        // Si lo que sobra del término es una opción del platillo («capuchinos
        // con ALMENDRA»), la frase habla de ese platillo y no del ingrediente
        // que comparte la palabra: la coincidencia del platillo se refuerza.
        $opcion = null;
        if ($productos !== []) {
            $opcion = self::opcionMencionada((int) $productos[0]['id'], $productos[0]['sobrantes'] ?? []);
            if ($opcion !== null) {
                $productos[0]['puntos'] += 0.25;
            }
        }

        // Una CATEGORÍA de la carta («pizzas», «cervezas», «cocktails») es una
        // pregunta de ventas por familia, salvo que se pregunte por almacén.
        // Gana cuando casa mejor que cualquier platillo.
        $categorias = self::coincidencias('categorias', $termino);
        if ($categorias !== [] && !$quiereStock
            && ($productos === [] || $categorias[0]['puntos'] > $productos[0]['puntos'])) {
            return $base + ['respuesta' => self::ventasCategoria((string) $categorias[0]['nombre'], $periodo)];
        }

        // Qué se pregunta: lo dice el verbo; si no lo dice, el ámbito de la
        // pantalla desempata, y si tampoco, gana la coincidencia más fuerte.
        $prefiereStock = $quiereStock || (!$quiereVentas && $ambito === 'productos' && $ingredientes !== []);
        if (!$quiereStock && !$quiereVentas && $ambito !== 'productos' && $productos !== [] && $ingredientes !== []) {
            $prefiereStock = $ingredientes[0]['puntos'] > $productos[0]['puntos'] + 0.15;
        }
        if ($prefiereStock && $ingredientes === [] && $productos !== []) {
            $prefiereStock = false;
        }
        if (!$prefiereStock && $productos === [] && $ingredientes !== []) {
            $prefiereStock = true;
        }

        if ($prefiereStock && $ingredientes !== []) {
            $elegido = $ingredientes[0];
            $base['alternativas'] = self::alternativas($ingredientes, $productos, 'stock', $periodo);
            return $base + ['respuesta' => self::stockDe((int) $elegido['id'])];
        }
        if ($productos !== []) {
            $elegido = $productos[0];
            $base['alternativas'] = self::alternativas($productos, $ingredientes, 'ventas', $periodo);
            return $base + ['respuesta' => self::ventasDe((string) $elegido['nombre'], $periodo, $opcion)];
        }

        return $base + ['respuesta' => [
            'tipo' => 'sin_resultado',
            'titulo' => 'No encontré «' . $termino . '»',
            'frase' => 'No hay un platillo ni un ingrediente que se parezca. Prueba con el nombre como aparece en la carta o en el inventario.',
        ]];
    }

    /**
     * Preguntas de ejemplo para la barra, armadas con los datos de la casa:
     * el platillo que más sale y el ingrediente más justo de stock. Un ejemplo
     * con un nombre que no existe enseñaría a preguntar mal.
     *
     * @return list<string>
     */
    public static function ejemplos(string $ambito): array
    {
        $db = ActiveRecord::getDB();
        $top = null;
        $ingrediente = null;
        try {
            $res = $db->query(
                "SELECT ti.nombre FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                    AND COALESCE(t.hora_cierre, t.hora_apertura) >= CURDATE() - INTERVAL 30 DAY
                  GROUP BY ti.nombre ORDER BY SUM(ti.cantidad) DESC LIMIT 1"
            );
            $top = $res ? ($res->fetch_row()[0] ?? null) : null;
            $res = $db->query(
                "SELECT nombre FROM ingredientes WHERE activo = 1 AND stock_minimo > 0
                  ORDER BY stock / stock_minimo ASC LIMIT 1"
            );
            $ingrediente = $res ? ($res->fetch_row()[0] ?? null) : null;
        } catch (\Throwable $e) {
            error_log('ConsultasRapidas::ejemplos - ' . $e->getMessage());
        }

        $ventas = array_values(array_filter([
            // Sin «¿cuántos …?»: el nombre de la carta no avisa de su género ni
            // de su número, y «¿Cuántos Cappuccino…?» enseña a hablar raro.
            $top !== null ? 'Ventas de ' . $top . ' ayer' : null,
            'Lo más vendido esta semana en la mañana',
            '¿Cuánto vendimos hoy?',
            $top !== null ? $top . ' últimos 30 días' : null,
        ]));
        $productos = array_values(array_filter([
            $ingrediente !== null ? $ingrediente . ' en almacén' : null,
            '¿Qué está por agotarse?',
            $top !== null ? $top . ' esta semana' : null,
            'Lo más vendido este mes',
        ]));

        return $ambito === 'productos' ? $productos : $ventas;
    }

    // ── Respuestas ──────────────────────────────────────────────

    private static function ventasDe(string $producto, array $periodo, ?string $opcion = null): array
    {
        $db = ActiveRecord::getDB();
        $nombre = $db->real_escape_string($producto);
        // Filtro por opción sobre el rótulo congelado: «Almendra · Grande»
        // cuenta como venta con almendra. Se compara por palabra completa
        // dentro del rótulo, no por subcadena.
        $filtroOpcion = '';
        if ($opcion !== null) {
            $o = $db->real_escape_string($opcion);
            $filtroOpcion = " AND CONCAT(' · ', COALESCE(ti.variante, ''), ' · ') LIKE '% · {$o} · %'";
        }
        $actual = self::sumaVentas($nombre, $periodo['desde'], $periodo['hasta'], $filtroOpcion . $periodo['hora_sql']);
        $previo = self::sumaVentas($nombre, $periodo['previo_desde'], $periodo['previo_hasta'], $filtroOpcion . $periodo['hora_sql']);

        $detalle = [];
        $res = $db->query(
            "SELECT COALESCE(NULLIF(ti.variante, ''), '') AS variante, SUM(ti.cantidad) AS u
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE ti.nombre = '{$nombre}' AND t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$filtroOpcion}{$periodo['hora_sql']}
                AND " . self::filtroFecha($periodo['desde'], $periodo['hasta']) . "
              GROUP BY variante ORDER BY u DESC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $detalle[] = [
                    'etiqueta' => $row['variante'] !== '' ? $row['variante'] : 'Como en la carta',
                    'valor' => (int) $row['u'],
                ];
            }
            $res->free();
        }
        // Un desglose de una sola fila «Como en la carta» no dice nada.
        if (count($detalle) === 1 && $detalle[0]['etiqueta'] === 'Como en la carta') {
            $detalle = [];
        }

        $u = $actual['unidades'];
        $plural = self::pluralizar($producto, $u);
        $conOpcion = $opcion !== null ? ' con la opción «' . $opcion . '»' : '';

        return [
            'tipo' => 'ventas_producto',
            'titulo' => $producto . ($opcion !== null ? ' · ' . $opcion : ''),
            'cifra' => $u,
            'unidad' => $u === 1 ? 'vendido' : 'vendidos',
            // En cero no se pluraliza ni se arriesga el género («ningún
            // margarita»): se dice que no hubo ventas.
            'frase' => $u === 0
                ? 'No hubo ventas de ' . $producto . $conOpcion . ' ' . mb_strtolower($periodo['frase']) . '.'
                : ($u === 1 ? 'Se vendió ' : 'Se vendieron ') . $u . ' ' . $plural . $conOpcion . ' ' . mb_strtolower($periodo['frase']) . ', por ' . self::dinero($actual['importe']) . ' en ' . $actual['tickets'] . ($actual['tickets'] === 1 ? ' ticket.' : ' tickets.'),
            'comparacion' => self::comparacion($u, $previo['unidades'], $periodo['previo_etiqueta']),
            'detalle_titulo' => $detalle !== [] ? 'Por opción' : null,
            'detalle' => $detalle,
            'enlace' => ['href' => '/admin/analytics', 'texto' => 'Ver análisis de ventas'],
        ];
    }

    /**
     * Ventas de una familia de la carta. ticket_items.categoria es el nombre
     * de la categoría congelado al vender, igual que el del platillo.
     */
    private static function ventasCategoria(string $categoria, array $periodo): array
    {
        $db = ActiveRecord::getDB();
        $cat = $db->real_escape_string($categoria);
        $suma = static function (string $desde, string $hasta) use ($db, $cat, $periodo): array {
            $res = $db->query(
                "SELECT COALESCE(SUM(ti.cantidad), 0) AS u, COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe
                   FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE ti.categoria = '{$cat}' AND t.estado = 'cerrado' AND ti.estado <> 'cancelado'{$periodo['hora_sql']}
                    AND " . self::filtroFecha($desde, $hasta)
            );
            $row = $res ? $res->fetch_assoc() : null;
            return ['unidades' => (int) ($row['u'] ?? 0), 'importe' => (float) ($row['importe'] ?? 0)];
        };
        $actual = $suma($periodo['desde'], $periodo['hasta']);
        $previo = $suma($periodo['previo_desde'], $periodo['previo_hasta']);

        $detalle = [];
        $res = $db->query(
            "SELECT ti.nombre, SUM(ti.cantidad) AS u
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE ti.categoria = '{$cat}' AND t.estado = 'cerrado' AND ti.estado <> 'cancelado'{$periodo['hora_sql']}
                AND " . self::filtroFecha($periodo['desde'], $periodo['hasta']) . "
              GROUP BY ti.nombre ORDER BY u DESC LIMIT 6"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $detalle[] = ['etiqueta' => (string) $row['nombre'], 'valor' => (int) $row['u'], 'q' => (string) $row['nombre'] . ' ' . mb_strtolower($periodo['etiqueta']) . $periodo['hora_q']];
            }
            $res->free();
        }
        $u = $actual['unidades'];

        return [
            'tipo' => 'ventas_categoria',
            'titulo' => $categoria,
            'cifra' => $u,
            'unidad' => $u === 1 ? 'vendido' : 'vendidos',
            'frase' => $u === 0
                ? 'No hubo ventas de ' . $categoria . ' ' . mb_strtolower($periodo['frase']) . '.'
                : 'En ' . $categoria . ($u === 1 ? ' se vendió 1 ' : ' se vendieron ' . $u . ' ') . mb_strtolower($periodo['frase']) . ', por ' . self::dinero($actual['importe']) . '.',
            'comparacion' => self::comparacion($u, $previo['unidades'], $periodo['previo_etiqueta']),
            'detalle_titulo' => $detalle !== [] ? 'Por platillo' : null,
            'detalle' => $detalle,
            'enlace' => ['href' => '/admin/analytics', 'texto' => 'Ver análisis de ventas'],
        ];
    }

    private static function sumaVentas(string $nombreEsc, string $desde, string $hasta, string $filtroOpcion = ''): array
    {
        $res = ActiveRecord::getDB()->query(
            "SELECT COALESCE(SUM(ti.cantidad), 0) AS u, COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe,
                    COUNT(DISTINCT ti.ticket_id) AS tickets
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE ti.nombre = '{$nombreEsc}' AND t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$filtroOpcion}
                AND " . self::filtroFecha($desde, $hasta)
        );
        $row = $res ? $res->fetch_assoc() : null;

        return [
            'unidades' => (int) ($row['u'] ?? 0),
            'importe' => (float) ($row['importe'] ?? 0),
            'tickets' => (int) ($row['tickets'] ?? 0),
        ];
    }

    private static function stockDe(int $ingredienteId): array
    {
        $db = ActiveRecord::getDB();
        $res = $db->query("SELECT id, nombre, unidad, stock, stock_minimo, costo, activo FROM ingredientes WHERE id = {$ingredienteId} LIMIT 1");
        $ing = $res ? $res->fetch_assoc() : null;
        if (!$ing) {
            return ['tipo' => 'sin_resultado', 'titulo' => 'Ingrediente no encontrado', 'frase' => 'Ya no existe en el inventario.'];
        }

        $stock = (float) $ing['stock'];
        $minimo = (float) $ing['stock_minimo'];
        $unidad = (string) $ing['unidad'];

        // Consumo por ventas de los últimos 7 días (neto de cancelaciones):
        // lo que alcanza al ritmo de la última semana.
        $res = $db->query(
            "SELECT COALESCE(-SUM(cantidad), 0) FROM movimientos_inventario
              WHERE ingrediente_id = {$ingredienteId} AND tipo IN ('venta', 'cancelacion')
                AND created_at >= NOW() - INTERVAL 7 DAY"
        );
        $consumo7 = max(0.0, (float) ($res ? $res->fetch_row()[0] : 0));
        $porDia = $consumo7 / 7;
        $dias = $porDia > 0 ? (int) floor($stock / $porDia) : null;

        $estado = $stock <= 0 ? 'agotado' : ($stock <= $minimo ? 'bajo' : 'ok');
        $frase = match ($estado) {
            'agotado' => 'Está agotado. El mínimo es ' . self::cantidad($minimo, $unidad) . '.',
            'bajo' => 'Está por debajo del mínimo (' . self::cantidad($minimo, $unidad) . '): conviene reabastecer.',
            default => 'Por encima del mínimo de ' . self::cantidad($minimo, $unidad) . '.',
        };
        if ($dias !== null && $stock > 0) {
            $frase .= ' Al ritmo de la última semana alcanza para ' . ($dias === 0 ? 'menos de un día' : ($dias === 1 ? 'un día' : $dias . ' días')) . '.';
        } elseif ($stock > 0) {
            $frase .= ' No hubo ventas que lo usaran en los últimos 7 días.';
        }

        [$cifra, $unidadMostrada] = self::cifraLegible($stock, $unidad);

        return [
            'tipo' => 'stock',
            'titulo' => (string) $ing['nombre'],
            'cifra' => $cifra,
            'unidad' => $unidadMostrada . ' en almacén',
            'estado' => $estado,
            'frase' => $frase,
            'detalle_titulo' => 'Ficha',
            'detalle' => array_values(array_filter([
                ['etiqueta' => 'Mínimo', 'valor' => self::cantidad($minimo, $unidad)],
                ['etiqueta' => 'Valor en almacén', 'valor' => self::dinero(max(0, $stock) * (float) $ing['costo'])],
                ['etiqueta' => 'Consumo 7 días', 'valor' => self::cantidad($consumo7, $unidad)],
                (int) $ing['activo'] === 1 ? null : ['etiqueta' => 'Estado', 'valor' => 'Inactivo'],
            ])),
            'enlace' => ['href' => '/admin/inventario', 'texto' => 'Abrir inventario'],
        ];
    }

    private static function topVendidos(array $periodo): array
    {
        $res = ActiveRecord::getDB()->query(
            "SELECT ti.nombre, SUM(ti.cantidad) AS u, SUM(ti.precio * ti.cantidad) AS importe
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'{$periodo['hora_sql']}
                AND " . self::filtroFecha($periodo['desde'], $periodo['hasta']) . "
              GROUP BY ti.nombre ORDER BY u DESC, importe DESC LIMIT 5"
        );
        $detalle = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $detalle[] = ['etiqueta' => (string) $row['nombre'], 'valor' => (int) $row['u'], 'q' => (string) $row['nombre']];
            }
            $res->free();
        }
        if ($detalle === []) {
            return ['tipo' => 'top', 'titulo' => 'Lo más vendido', 'frase' => 'No hay ventas cerradas ' . mb_strtolower($periodo['frase']) . '.', 'detalle' => []];
        }

        return [
            'tipo' => 'top',
            'titulo' => 'Lo más vendido',
            'cifra' => $detalle[0]['valor'],
            'unidad' => self::pluralizar($detalle[0]['etiqueta'], $detalle[0]['valor']),
            'frase' => $detalle[0]['etiqueta'] . ' encabeza ' . mb_strtolower($periodo['frase']) . '.',
            'detalle_titulo' => 'Top 5',
            'detalle' => $detalle,
            'enlace' => ['href' => '/admin/analytics', 'texto' => 'Ver análisis de ventas'],
        ];
    }

    private static function bajoStock(): array
    {
        $res = ActiveRecord::getDB()->query(
            "SELECT nombre, unidad, stock, stock_minimo FROM ingredientes
              WHERE activo = 1 AND stock <= stock_minimo
              ORDER BY (stock / NULLIF(stock_minimo, 0)) ASC, nombre ASC LIMIT 8"
        );
        $detalle = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $detalle[] = [
                    'etiqueta' => (string) $row['nombre'],
                    'valor' => self::cantidad((float) $row['stock'], (string) $row['unidad']) . ' de ' . self::cantidad((float) $row['stock_minimo'], (string) $row['unidad']),
                    'q' => (string) $row['nombre'] . ' en almacén',
                ];
            }
            $res->free();
        }
        $n = count($detalle);

        return [
            'tipo' => 'bajo_stock',
            'titulo' => 'Por debajo del mínimo',
            'cifra' => $n,
            'unidad' => $n === 1 ? 'ingrediente' : 'ingredientes',
            'estado' => $n > 0 ? 'bajo' : 'ok',
            'frase' => $n === 0 ? 'Todo el inventario activo está por encima de su mínimo.' : 'Los que más urgen van primero.',
            'detalle_titulo' => $n > 0 ? 'Existencia de mínimo' : null,
            'detalle' => $detalle,
            'enlace' => ['href' => '/admin/inventario', 'texto' => 'Abrir inventario'],
        ];
    }

    private static function ventasTotales(array $periodo): array
    {
        $calc = static function (string $desde, string $hasta): array {
            $res = ActiveRecord::getDB()->query(
                "SELECT COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe, COUNT(DISTINCT t.id) AS tickets
                   FROM tickets t JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.estado <> 'cancelado'
                  WHERE t.estado = 'cerrado' AND " . self::filtroFecha($desde, $hasta)
            );
            $row = $res ? $res->fetch_assoc() : null;
            return ['importe' => (float) ($row['importe'] ?? 0), 'tickets' => (int) ($row['tickets'] ?? 0)];
        };
        $a = $calc($periodo['desde'], $periodo['hasta']);
        $p = $calc($periodo['previo_desde'], $periodo['previo_hasta']);
        $promedio = $a['tickets'] > 0 ? $a['importe'] / $a['tickets'] : 0;

        return [
            'tipo' => 'ventas_total',
            'titulo' => 'Ventas ' . mb_strtolower($periodo['frase']),
            'cifra' => self::dinero($a['importe']),
            'unidad' => '',
            'frase' => $a['tickets'] === 0
                ? 'No hay tickets cerrados ' . mb_strtolower($periodo['frase']) . '.'
                : $a['tickets'] . ($a['tickets'] === 1 ? ' ticket cerrado' : ' tickets cerrados') . ', con un promedio de ' . self::dinero($promedio) . ' por cuenta.',
            'comparacion' => self::comparacion($a['importe'], $p['importe'], $periodo['previo_etiqueta'], true),
            'detalle' => [],
            'enlace' => ['href' => '/admin/tickets', 'texto' => 'Ver tickets'],
        ];
    }

    // ── Interpretación ──────────────────────────────────────────

    public static function normalizar(string $texto): string
    {
        $t = mb_strtolower($texto, 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '¿' => ' ', '?' => ' ', '¡' => ' ', '!' => ' ']);
        $t = preg_replace('/[^a-z0-9 ]+/u', ' ', $t) ?? '';

        return trim(preg_replace('/\s+/', ' ', $t) ?? '');
    }

    private static function detectarPeriodo(string $norma, string $porDefecto = 'hoy'): array
    {
        if (preg_match('/\bultim[oa]s? (\d{1,3}) dias?\b/u', $norma, $m)) {
            return self::periodoDias(max(1, min(365, (int) $m[1])));
        }
        $mapa = [
            '/\b(antier|anteayer|antes de ayer)\b/u' => 'antier',
            '/\bayer\b/u' => 'ayer',
            '/\bsemana pasada\b/u' => 'semana_pasada',
            '/\bmes pasado\b/u' => 'mes_pasado',
            '/\b(esta semana|semana)\b/u' => 'semana',
            '/\b(este mes|mes)\b/u' => 'mes',
            '/\bhoy\b/u' => 'hoy',
        ];
        foreach ($mapa as $patron => $clave) {
            if (preg_match($patron, $norma)) {
                return self::periodo($clave);
            }
        }

        return self::periodo($porDefecto);
    }

    /**
     * Hora («a las 9», «a las 9 de la noche»), franja («en la mañana», «las
     * noches») y día de la semana («los sábados») de la pregunta, o null.
     * La hora es la del PEDIDO (ticket_items.created_at), como en §3.5 de
     * analíticas; el periodo sigue mandando sobre qué fechas entran.
     *
     * @return array{desde: ?int, hasta: ?int, dow: ?int, frase: string, sql: string, resto: string}|null
     */
    public static function horario(string $norma): ?array
    {
        $desde = $hasta = $dow = null;
        $frases = [];
        $resto = $norma;

        if (preg_match('/\b(?:el |los )?(domingo|lunes|martes|miercoles|jueves|viernes|sabado)s?\b/u', $resto, $m)) {
            [$dow, $frases[]] = self::DIAS[$m[1]];
            $resto = str_replace($m[0], ' ', $resto);
        }
        if (preg_match('/\ba las? (\d{1,2})(?: \d{2})?(?: (am|pm|de la (?:manana|tarde|noche)))?\b/u', $resto, $m) && (int) $m[1] <= 23) {
            $h = (int) $m[1];
            $tarde = isset($m[2]) && in_array($m[2], ['pm', 'de la tarde', 'de la noche'], true);
            // shortcut: sin «am/pm», de 1 a 7 se lee como de la tarde porque la
            // casa abre a las 8; si algún día abre de madrugada, hay que pedirlo.
            if ($h < 12 && ($tarde || (!isset($m[2]) && $h >= 1 && $h <= 7))) {
                $h += 12;
            }
            $desde = $hasta = $h;
            $frases[] = ($h === 1 ? 'a la ' : 'a las ') . $h . ':00';
            $resto = str_replace($m[0], ' ', $resto);
        } elseif (preg_match('/\b(?:(?:en|por|de) )?las? (manana|tarde|noche)s?\b/u', $resto, $m)) {
            [$desde, $hasta, $frases[]] = self::FRANJAS[$m[1]];
            $resto = str_replace($m[0], ' ', $resto);
        }

        if ($desde === null && $dow === null) {
            return null;
        }

        $sql = [];
        if ($desde !== null) {
            $sql[] = "HOUR(ti.created_at) BETWEEN {$desde} AND {$hasta}";
        }
        if ($dow !== null) {
            $sql[] = 'DAYOFWEEK(ti.created_at) = ' . ($dow + 1);
        }

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'dow' => $dow,
            'frase' => implode(' ', $frases),
            'sql' => implode(' AND ', $sql),
            'resto' => trim(preg_replace('/\s+/', ' ', $resto) ?? $resto),
        ];
    }

    /**
     * @return array{clave: string, etiqueta: string, frase: string, desde: string, hasta: string, previo_desde: string, previo_hasta: string, previo_etiqueta: string}
     */
    public static function periodo(string $clave): array
    {
        $hoy = new \DateTimeImmutable('today');
        $f = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d');
        switch ($clave) {
            case 'ayer':
                $d = $hoy->modify('-1 day');
                return self::armar('ayer', 'Ayer', 'Ayer', $f($d), $f($d), $f($d->modify('-1 day')), $f($d->modify('-1 day')), 'antier');
            case 'antier':
                $d = $hoy->modify('-2 day');
                return self::armar('antier', 'Antier', 'Antier', $f($d), $f($d), $f($d->modify('-1 day')), $f($d->modify('-1 day')), 'el día anterior');
            case 'semana':
                $ini = $hoy->modify('monday this week');
                $dias = (int) $ini->diff($hoy)->days;
                return self::armar('semana', 'Esta semana', 'Esta semana', $f($ini), $f($hoy), $f($ini->modify('-7 day')), $f($ini->modify('-7 day')->modify("+{$dias} day")), 'el mismo tramo de la semana pasada');
            case 'semana_pasada':
                $ini = $hoy->modify('monday this week')->modify('-7 day');
                return self::armar('semana_pasada', 'Semana pasada', 'La semana pasada', $f($ini), $f($ini->modify('+6 day')), $f($ini->modify('-7 day')), $f($ini->modify('-1 day')), 'la semana anterior');
            case 'mes':
                $ini = $hoy->modify('first day of this month');
                $dias = (int) $ini->diff($hoy)->days;
                $prevIni = $ini->modify('-1 month');
                return self::armar('mes', 'Este mes', 'Este mes', $f($ini), $f($hoy), $f($prevIni), $f($prevIni->modify("+{$dias} day")), 'el mismo tramo del mes pasado');
            case 'mes_pasado':
                $ini = $hoy->modify('first day of last month');
                return self::armar('mes_pasado', 'Mes pasado', 'El mes pasado', $f($ini), $f($ini->modify('last day of this month')), $f($ini->modify('-1 month')), $f($ini->modify('-1 day')), 'el mes anterior');
            case '8s':
                $ini = $hoy->modify('-55 day');
                return self::armar('8s', 'Últimas 8 semanas', 'En las últimas 8 semanas', $f($ini), $f($hoy), $f($ini->modify('-56 day')), $f($ini->modify('-1 day')), 'las 8 semanas anteriores');
            case '7d':
                return self::periodoDias(7);
            case '30d':
                return self::periodoDias(30);
            default:
                return self::armar('hoy', 'Hoy', 'Hoy', $f($hoy), $f($hoy), $f($hoy->modify('-7 day')), $f($hoy->modify('-7 day')), 'el mismo día de la semana pasada');
        }
    }

    private static function periodoDias(int $n): array
    {
        $hoy = new \DateTimeImmutable('today');
        $ini = $hoy->modify('-' . ($n - 1) . ' day');
        $f = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d');
        $clave = $n === 7 ? '7d' : ($n === 30 ? '30d' : $n . 'd');

        return self::armar($clave, "Últimos {$n} días", "En los últimos {$n} días", $f($ini), $f($hoy), $f($ini->modify("-{$n} day")), $f($ini->modify('-1 day')), "los {$n} días anteriores");
    }

    private static function armar(string $clave, string $etiqueta, string $frase, string $desde, string $hasta, string $pDesde, string $pHasta, string $pEtiqueta): array
    {
        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'frase' => $frase,
            'desde' => $desde,
            'hasta' => $hasta,
            'previo_desde' => $pDesde,
            'previo_hasta' => $pHasta,
            'previo_etiqueta' => $pEtiqueta,
            // Filtro de hora de la pregunta; vacío salvo que responder() lo ponga.
            'hora_sql' => '',
            'hora_q' => '',
        ];
    }

    private static function terminoDeBusqueda(string $norma): string
    {
        // El periodo se quita ENTERO antes de filtrar palabras: si no, de
        // «últimos 7 días» quedaría el 7 como si fuera parte del nombre. Los
        // demás números sí cuentan («Licor 43»).
        $sinPeriodo = preg_replace('/\bultim[oa]s? \d+ dias?\b/u', ' ', $norma) ?? $norma;
        $palabras = array_filter(
            explode(' ', $sinPeriodo),
            static fn (string $p): bool => $p !== '' && !in_array($p, self::VACIAS, true)
        );
        // El sinónimo se busca también en singular: «capuchinos» → «cappuccino».
        $sin = array_map(static function (string $p): string {
            $singular = preg_replace('/(es|s)$/', '', $p) ?? $p;
            return self::SINONIMOS[$p] ?? self::SINONIMOS[$singular] ?? $p;
        }, $palabras);

        return trim(implode(' ', $sin));
    }

    /**
     * Platillos o ingredientes que se parecen al término, mejor primero.
     *
     * Puntúa por palabra: exacta 1, una contiene a la otra 0.85, singular
     * contra plural 0.9, y si no, la distancia de edición relativa (para los
     * errores de dedo: «capuchino» → «cappuccino»). La nota final es el
     * promedio sobre las palabras del TÉRMINO, así que «licor 43» prefiere
     * «Licor 43» a «Licor de naranja».
     *
     * @return list<array{id: int, nombre: string, puntos: float}>
     */
    private static function coincidencias(string $tabla, string $termino): array
    {
        $db = ActiveRecord::getDB();
        $sql = match ($tabla) {
            'productos' => 'SELECT id, nombre FROM productos WHERE activo = 1',
            'categorias' => 'SELECT id, nombre FROM categorias WHERE activo = 1',
            default => 'SELECT id, nombre FROM ingredientes',
        };
        $res = $db->query($sql);
        if (!$res) {
            return [];
        }
        $palabrasTermino = array_values(array_filter(explode(' ', $termino)));
        $salida = [];
        while ($row = $res->fetch_assoc()) {
            $nombre = (string) $row['nombre'];
            $normNombre = self::normalizar($nombre);
            $palabrasNombre = array_values(array_filter(explode(' ', $normNombre), static fn ($p) => !in_array($p, ['de', 'del', 'la', 'el', 'a', 'al', 'con', 'y', 'en', 'los', 'las'], true)));
            if ($palabrasNombre === []) {
                continue;
            }
            // Se puntúa con las palabras que SÍ casan; las demás se guardan
            // como sobrantes (pueden ser una opción: «chilaquiles rojos») y
            // restan un poco. Hace falta que case al menos la mitad.
            $suma = 0.0;
            $casan = 0;
            $sobrantes = [];
            foreach ($palabrasTermino as $pt) {
                $mejor = 0.0;
                foreach ($palabrasNombre as $pn) {
                    $mejor = max($mejor, self::parecido($pt, $pn));
                }
                if ($mejor >= 0.72) {
                    $suma += $mejor;
                    $casan++;
                } else {
                    $sobrantes[] = $pt;
                }
            }
            if ($casan === 0) {
                continue;
            }
            $puntos = $suma / $casan - count($sobrantes) * 0.06;
            // Penaliza un poco los nombres largos que sólo cubren parte.
            $puntos -= max(0, count($palabrasNombre) - count($palabrasTermino)) * 0.03;
            if (str_contains($normNombre, $termino)) {
                $puntos = max($puntos, 0.95);
            }
            if ($normNombre === $termino) {
                $puntos = 1.2;
            }
            if ($puntos >= 0.72) {
                $salida[] = ['id' => (int) $row['id'], 'nombre' => $nombre, 'puntos' => round($puntos, 3), 'sobrantes' => $sobrantes];
            }
        }
        $res->free();
        usort($salida, static fn ($a, $b) => $b['puntos'] <=> $a['puntos'] ?: strcmp($a['nombre'], $b['nombre']));

        return array_slice($salida, 0, 6);
    }

    /**
     * Si alguna palabra sobrante del término es una opción activa del platillo
     * («rojos» → «Roja», «almendra» → «Almendra»), devuelve su nombre. Se
     * comparan raíces: sin -os/-as/-es/-s/-o/-a, para que el género y el número
     * de la frase no importen.
     */
    private static function opcionMencionada(int $productoId, array $sobrantes): ?string
    {
        if ($sobrantes === []) {
            return null;
        }
        $res = ActiveRecord::getDB()->query(
            "SELECT nombre FROM producto_variantes WHERE producto_id = {$productoId} AND activo = 1 AND predeterminada = 0"
        );
        if (!$res) {
            return null;
        }
        $raiz = static fn (string $p): string => preg_replace('/(os|as|es|s|o|a)$/', '', $p) ?? $p;
        $mejor = null;
        $mejorPuntos = 0.0;
        while ($row = $res->fetch_assoc()) {
            $palabrasOpcion = explode(' ', self::normalizar((string) $row['nombre']));
            foreach ($sobrantes as $s) {
                foreach ($palabrasOpcion as $po) {
                    $puntos = $raiz($s) !== '' && $raiz($s) === $raiz($po) ? 1.0 : self::parecido($s, $po);
                    if ($puntos > $mejorPuntos && $puntos >= 0.8) {
                        $mejorPuntos = $puntos;
                        $mejor = (string) $row['nombre'];
                    }
                }
            }
        }
        $res->free();

        return $mejor;
    }

    private static function parecido(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $sa = preg_replace('/(es|s)$/', '', $a) ?? $a;
        $sb = preg_replace('/(es|s)$/', '', $b) ?? $b;
        if ($sa !== '' && $sa === $sb) {
            return 0.95;
        }
        // El prefijo sólo vale entre palabras de verdad: con una inicial suelta
        // («C.P.» → «c», «p») cualquier término que empezara por esa letra
        // salía como coincidencia.
        if (min(strlen($a), strlen($b)) >= 3 && (str_starts_with($b, $a) || str_starts_with($a, $b))) {
            return 0.85;
        }
        if (min(strlen($a), strlen($b)) < 4) {
            return 0.0;
        }
        // Errores de dedo: lo mejor de la distancia de edición y de las letras
        // en común («capuchno» → «cappuccino» sale por la segunda).
        $edicion = 1 - levenshtein($sa, $sb) / max(strlen($sa), strlen($sb), 1);
        similar_text($sa, $sb, $comun);

        return max(0.0, $edicion, ($comun / 100) - 0.02);
    }

    private static function alternativas(array $principal, array $otra, string $tipo, array $periodo): array
    {
        $alts = [];
        foreach (array_slice($principal, 1, 3) as $c) {
            $alts[] = ['nombre' => $c['nombre'], 'q' => $tipo === 'stock' ? $c['nombre'] . ' en almacén' : $c['nombre'] . ' ' . mb_strtolower($periodo['etiqueta']) . $periodo['hora_q'], 'tipo' => $tipo];
        }
        // La otra lectura de la misma palabra: «leche» como ingrediente
        // cuando se preguntó por ventas, o al revés.
        if ($otra !== []) {
            $c = $otra[0];
            $alts[] = $tipo === 'stock'
                ? ['nombre' => $c['nombre'], 'q' => $c['nombre'] . ' vendidos ' . mb_strtolower($periodo['etiqueta']) . $periodo['hora_q'], 'tipo' => 'ventas']
                : ['nombre' => $c['nombre'], 'q' => $c['nombre'] . ' en almacén', 'tipo' => 'stock'];
        }

        return $alts;
    }

    // ── Formato ─────────────────────────────────────────────────

    private static function filtroFecha(string $desde, string $hasta): string
    {
        // Las fechas salen de periodo() (DateTime → Y-m-d): no traen nada del
        // usuario y se pueden interpolar.
        return "COALESCE(t.hora_cierre, t.hora_apertura) >= '{$desde} 00:00:00'
                AND COALESCE(t.hora_cierre, t.hora_apertura) <= '{$hasta} 23:59:59'";
    }

    private static function comparacion(float $actual, float $previo, string $contra, bool $dinero = false): ?array
    {
        if ($actual == 0.0 && $previo == 0.0) {
            return null;
        }
        $dif = $actual - $previo;
        // «frente a el mismo tramo» → «frente al mismo tramo».
        $frente = str_starts_with($contra, 'el ') ? 'frente al ' . substr($contra, 3) : 'frente a ' . $contra;
        $igual = str_starts_with($contra, 'el ') ? 'Igual que el ' . substr($contra, 3) : 'Igual que ' . $contra;
        $texto = $dif == 0.0
            ? $igual
            : ($dif > 0 ? '+' : '−') . ($dinero ? self::dinero(abs($dif)) : (string) (int) abs($dif)) . ' ' . $frente;

        return ['texto' => $texto, 'tendencia' => $dif > 0 ? 'sube' : ($dif < 0 ? 'baja' : 'igual')];
    }

    /**
     * «9 cappuccinos», pero «11 órdenes de Hamburguesa de la Casa»: el plural
     * de un nombre compuesto de carta no se puede adivinar («gins tonic»), y
     * «órdenes de» se lee natural en el piso.
     */
    private static function pluralizar(string $nombre, int $n): string
    {
        $base = mb_strtolower($nombre);
        if (str_contains(trim($nombre), ' ')) {
            return ($n === 1 ? 'orden de ' : 'órdenes de ') . $nombre;
        }
        if ($n === 1 || preg_match('/(s|x)$/u', $base)) {
            return $base;
        }

        return preg_match('/[aeiou]$/u', $base) ? $base . 's' : $base . 'es';
    }

    private static function dinero(float $v): string
    {
        return '$' . number_format($v, 2);
    }

    /** 1400 ml → «1.4 l»; 450 g → «450 g»; 3 pza → «3 pzas». */
    private static function cifraLegible(float $valor, string $unidad): array
    {
        if ($unidad === 'ml' && abs($valor) >= 1000) {
            return [self::numero($valor / 1000), 'l'];
        }
        if ($unidad === 'g' && abs($valor) >= 1000) {
            return [self::numero($valor / 1000), 'kg'];
        }
        if ($unidad === 'pza') {
            return [self::numero($valor), abs($valor) === 1.0 ? 'pieza' : 'piezas'];
        }

        return [self::numero($valor), $unidad];
    }

    private static function cantidad(float $valor, string $unidad): string
    {
        [$cifra, $u] = self::cifraLegible($valor, $unidad);

        return $cifra . ' ' . $u;
    }

    private static function numero(float $v): string
    {
        $redondo = round($v, 2);

        return rtrim(rtrim(number_format($redondo, 2, '.', ','), '0'), '.');
    }
}
