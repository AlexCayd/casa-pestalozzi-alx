<?php

/**
 * Consultas rápidas del panel: una frase en español → una respuesta.
 *
 * «¿Cuántos capuchinos se vendieron ayer?», «licor 43 en almacén», «lo más
 * vendido esta semana», «qué está por agotarse». No es un modelo de lenguaje:
 * es un intérprete de reglas que reconoce el PERIODO, la INTENCIÓN y la COSA
 * (platillo, categoría o ingrediente) y contesta con una consulta concreta. Lo
 * que no entiende lo dice —`entendida: false`— y ofrece lo que más se parece.
 *
 * Hay dos entradas y una sola salida:
 *   - responderEstructurada(): intención + entidad + periodo ya resueltos. Es
 *     la ÚNICA que usa el Asistente: el cliente retiró el texto libre, así que
 *     cada pregunta sale de una plantilla con huecos que se eligen de listas
 *     («Ventas de [Cappuccino ▾] · [Ayer ▾]»), nunca de una frase tecleada.
 *   - responder(): la frase, que se interpreta. Se conserva como contrato del
 *     motor —el texto canónico de cada respuesta tiene que volver a la misma
 *     consulta, y lo prueba run-consultas-rapidas-db.php— y para las preguntas
 *     de texto que quedaron guardadas en el navegador antes del cambio.
 * Las dos acaban en ejecutar(), así que la respuesta trae siempre la misma
 * forma: la `interpretacion` estructurada y la `plantilla` con sus huecos.
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

    /**
     * Lo que el motor sabe responder. Cada intención es también el `tipo` de
     * la respuesta, para que quien pida una consulta estructurada reciba el
     * mismo nombre que manda.
     */
    public const INTENCIONES = [
        'ventas_total', 'ventas_producto', 'ventas_categoria', 'top', 'ticket_promedio', 'sin_venta',
        'meseros', 'horas_pico', 'cancelaciones',
        'stock', 'consumo', 'bajo_stock',
    ];

    /** Periodos que entiende, en el orden en que se ofrecen en su hueco. */
    public const PERIODOS = [
        'hoy' => 'Hoy',
        'ayer' => 'Ayer',
        'antier' => 'Antier',
        'semana' => 'Esta semana',
        'semana_pasada' => 'Semana pasada',
        '7d' => 'Últimos 7 días',
        'mes' => 'Este mes',
        'mes_pasado' => 'Mes pasado',
        '30d' => 'Últimos 30 días',
    ];

    /**
     * Nueve periodos en una lista plana se leen como nueve cosas distintas;
     * en tres grupos de tres se ve que son la misma pregunta a tres escalas.
     */
    private const GRUPO_PERIODO = [
        'hoy' => 'Días', 'ayer' => 'Días', 'antier' => 'Días',
        'semana' => 'Semanas', 'semana_pasada' => 'Semanas', '7d' => 'Semanas',
        'mes' => 'Meses', 'mes_pasado' => 'Meses', '30d' => 'Meses',
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
        // «Familia» era como el panel llamaba a la categoría: se sigue
        // entendiendo de entrada, pero ni ésa ni «categoría» forman parte del
        // nombre que se busca.
        'familia', 'familias', 'categoria', 'categorias', 'seccion',
        // Los verbos del consumo: «cuánta leche USAMOS esta semana».
        'consumo', 'consumio', 'consumimos', 'consumieron', 'gastamos', 'gastaron', 'gastado', 'usamos', 'usaron',
        'usado', 'utilizamos',
    ];

    /**
     * Cortesía y relleno de conversación. No se buscan como nombre —«hola»
     * casaba con «Té en hojas» por la coincidencia aproximada— y una frase
     * hecha SÓLO de ellas es un saludo, no una pregunta: se contesta como no
     * entendida, con lo que sí se puede preguntar. Pesa más desde que el
     * Asistente usa este motor y recibe frases de conversación.
     */
    private const CORTESIA = [
        'hola', 'ola', 'buenas', 'buenos', 'buen', 'tardes', 'noches', 'gracias', 'muchas', 'oye', 'hey', 'ayuda',
        'ayudame', 'adios', 'saludos', 'porfa', 'porfavor', 'favor', 'dime', 'quiero', 'quisiera', 'saber',
        'necesito', 'puedes', 'podrias', 'ok', 'vale', 'perfecto',
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

    /** Rótulos de los huecos: los lee el lector de pantalla delante del valor. */
    private const ROTULO_VENTAS = 'Platillo o categoría';
    private const ROTULO_INGREDIENTE = 'Ingrediente';

    /**
     * Renglones de un desglose. Una lista larga en una tarjeta de respuesta
     * deja de ser una respuesta: lo completo está en el módulo del enlace.
     */
    private const TOPE_DESGLOSE = 8;
    private const TOPE_SIN_VENTA = 10;

    // ── Entradas ────────────────────────────────────────────────

    /**
     * La frase, que se interpreta (ver la cabecera: el Asistente ya no la
     * manda; es el contrato del texto canónico y la vía de lo guardado).
     *
     * @return array<string, mixed>
     */
    public static function responder(string $texto, string $ambito = 'todo', ?string $periodoForzado = null): array
    {
        $texto = trim(mb_substr($texto, 0, 160));
        $ambito = in_array($ambito, self::AMBITOS, true) ? $ambito : 'todo';
        $norma = self::normalizar($texto);

        $periodo = self::periodoDesde($periodoForzado) ?? self::detectarPeriodo($norma);

        $quiereStock = (bool) preg_match('/\b(hay|queda|quedan|almacen|bodega|inventario|stock|existencia|existencias|tenemos)\b/u', $norma);
        $quiereVentas = (bool) preg_match('/\b(vend\w*|venta\w*|pidieron|pidio|salieron|cuantos|cuantas|llevamos)\b/u', $norma);

        $base = ['ok' => true, 'consulta' => $texto, 'periodo' => $periodo, 'alternativas' => []];

        if ($norma === '') {
            // Nada que interpretar: no es un error, es una barra vacía. Quien
            // consuma la API recibe con qué empezar.
            return $base + [
                'entendida' => false,
                'interpretacion' => null,
                'plantilla' => [],
                'respuesta' => null,
                'sugerencias' => self::sugerencias([], $periodo),
            ];
        }

        if (array_diff(explode(' ', $norma), self::CORTESIA, self::VACIAS) === []
            && !$quiereStock && !$quiereVentas) {
            return self::noEntendida(
                '¿Qué quieres consultar?',
                'Pregunta por las ventas de un platillo, de una categoría o del día; por lo más vendido; o por cuánto queda de un ingrediente.',
                $periodo,
                $base
            );
        }

        // Intenciones sin «cosa», de la más específica a la más general:
        // «platillos SIN VENTA» y «ventas por MESERO» también dicen «venta», y
        // si las mirara después el patrón de ventas totales se las quedaría.
        if (preg_match('/\b(sin vent\w*|sin vender|no se vend\w*|no vendimos|no se pidi\w*|nadie pidio)\b/u', $norma)) {
            return self::ejecutar('sin_venta', null, null, $periodo, $base);
        }
        if (preg_match('/\b(meser[oa]s?|vendedor\w*|quien vendio)\b/u', $norma)) {
            return self::ejecutar('meseros', null, null, $periodo, $base);
        }
        if (preg_match('/\b(pico|concurrid\w*|afluencia|a que hora)\b/u', $norma)) {
            return self::ejecutar('horas_pico', null, null, $periodo, $base);
        }
        if (preg_match('/\b(cancelad\w*|cancelacion\w*|cancelaron|cancelo)\b/u', $norma)) {
            return self::ejecutar('cancelaciones', null, null, $periodo, $base);
        }
        if (preg_match('/\b(ticket promedio|promedio|comensales|cubiertos)\b/u', $norma)) {
            return self::ejecutar('ticket_promedio', null, null, $periodo, $base);
        }
        // El consumo SÍ lleva cosa, pero sólo puede ser un ingrediente: se
        // resuelve aquí, antes de que la palabra compita con los platillos.
        if (preg_match('/\b(consum\w*|gast(amos|aron|ado)|usamos|usaron|usado|utiliz\w*)\b/u', $norma)) {
            $termino = self::terminoDeBusqueda($norma);
            $ingredientes = $termino !== '' ? self::coincidencias('ingredientes', $termino) : [];
            if ($ingredientes !== []) {
                return self::ejecutar('consumo', (int) $ingredientes[0]['id'], null, $periodo, $base);
            }
            return self::noEntendida(
                $termino !== '' ? 'No encontré el ingrediente «' . $termino . '»' : '¿El consumo de qué ingrediente?',
                'Elige el ingrediente de la lista del inventario.',
                $periodo,
                $base
            );
        }
        if (preg_match('/\b(mas vendid\w*|top|lo que mas|estrella|mas pedid\w*|favorit\w*)\b/u', $norma)) {
            return self::ejecutar('top', null, null, $periodo, $base);
        }
        if (preg_match('/\b(bajo|baja|agotar\w*|agotad\w*|faltan|falta|minimo|reabastec\w*|comprar|surtir)\b/u', $norma)) {
            return self::ejecutar('bajo_stock', null, null, $periodo, $base);
        }
        if (preg_match('/^(cuanto )?(vendimos|vendi|ventas|venta|facturamos|ingresos)( de| del| en)?( (hoy|ayer|antier|anteayer|esta semana|semana|este mes|mes|ultimos \d+ dias))?$/u', $norma)) {
            return self::ejecutar('ventas_total', null, null, $periodo, $base);
        }

        $termino = self::terminoDeBusqueda($norma);
        if ($termino === '') {
            return self::ejecutar($quiereStock ? 'bajo_stock' : 'ventas_total', null, null, $periodo, $base);
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
        // pregunta de ventas por categoría, salvo que se pregunte por almacén.
        // Gana cuando casa mejor que cualquier platillo.
        $categorias = self::coincidencias('categorias', $termino);
        if ($categorias !== [] && !$quiereStock
            && ($productos === [] || $categorias[0]['puntos'] > $productos[0]['puntos'])) {
            return self::ejecutar('ventas_categoria', (int) $categorias[0]['id'], null, $periodo, $base);
        }

        // Un periodo o una opción del platillo («ayer», «con almendra») sólo
        // tienen sentido en ventas: el almacén no tiene fecha ni variantes. Sin
        // esto, en Inventario «capuchinos con almendra ayer» contestaba la
        // existencia de la leche de almendra.
        if (!$quiereStock && $productos !== []
            && ($opcion !== null || preg_match('/\b(hoy|ayer|antier|anteayer|semana|mes|ultim[oa]s? \d+ dias?)\b/u', $norma))) {
            $quiereVentas = true;
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
            $base['alternativas'] = self::alternativas($ingredientes, $productos, 'stock');
            return self::ejecutar('stock', (int) $ingredientes[0]['id'], null, $periodo, $base);
        }
        if ($productos !== []) {
            $base['alternativas'] = self::alternativas($productos, $ingredientes, 'ventas');
            return self::ejecutar('ventas_producto', (int) $productos[0]['id'], $opcion['id'] ?? null, $periodo, $base);
        }

        // Lo que quedó por debajo del umbral no se contesta, pero se ofrece:
        // «capchno» no llega a Cappuccino con la nota de una respuesta, y aun
        // así es casi seguro lo que se quería decir.
        $parecidos = [];
        foreach (self::coincidencias('productos', $termino, 0.6) as $c) {
            $parecidos[] = ['intencion' => 'ventas_producto', 'id' => (int) $c['id'], 'nombre' => $c['nombre'], 'puntos' => $c['puntos']];
        }
        foreach (self::coincidencias('ingredientes', $termino, 0.6) as $c) {
            $parecidos[] = ['intencion' => 'stock', 'id' => (int) $c['id'], 'nombre' => $c['nombre'], 'puntos' => $c['puntos']];
        }
        usort($parecidos, static fn ($a, $b) => $b['puntos'] <=> $a['puntos']);

        return self::noEntendida(
            'No encontré «' . $termino . '»',
            'No hay un platillo ni un ingrediente que se parezca. Elige uno de la lista o escríbelo como aparece en la carta o en el inventario.',
            $periodo,
            $base,
            array_slice($parecidos, 0, 3)
        );
    }

    /**
     * La consulta ya resuelta: lo que manda un hueco al cambiarlo, un ejemplo
     * o un renglón del desglose. Una intención desconocida sí es un error —la
     * pide código, no una persona—; una entidad que ya no existe no lo es.
     *
     * @return array<string, mixed>
     */
    public static function responderEstructurada(string $intencion, ?int $id = null, ?int $opcion = null, ?string $periodo = null): array
    {
        if (!in_array($intencion, self::INTENCIONES, true)) {
            return ['ok' => false, 'mensaje' => 'No reconozco esa consulta.'];
        }
        $p = self::periodoDesde($periodo) ?? self::periodo('hoy');
        $base = ['ok' => true, 'consulta' => '', 'periodo' => $p, 'alternativas' => []];

        return self::ejecutar($intencion, $id !== null && $id > 0 ? $id : null, $opcion !== null && $opcion > 0 ? $opcion : null, $p, $base);
    }

    /**
     * Las listas con que se pueblan los huecos de entidad: todo lo que se
     * puede elegir, ya con la consulta que dispara cada elección. El cliente
     * no sabe qué intención corresponde a una categoría o a un platillo; se la
     * dice esto.
     *
     * @return array{ventas: list<array<string, mixed>>, ingredientes: list<array<string, mixed>>}
     */
    public static function catalogo(): array
    {
        $db = ActiveRecord::getDB();
        $ventas = [[
            'valor' => 'ventas_total',
            'etiqueta' => 'Todo el restaurante',
            'grupo' => 'General',
            'detalle' => 'Ventas totales',
            'consulta' => ['intencion' => 'ventas_total', 'id' => null, 'opcion' => null],
        ]];

        $res = $db->query("SELECT id, nombre, carta FROM categorias WHERE activo = 1 ORDER BY nombre");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ventas[] = [
                    'valor' => 'ventas_categoria:' . (int) $row['id'],
                    'etiqueta' => (string) $row['nombre'],
                    'grupo' => 'Categorías',
                    'detalle' => $row['carta'] === 'maridaje' ? 'Maridaje' : 'Comida',
                    'consulta' => ['intencion' => 'ventas_categoria', 'id' => (int) $row['id'], 'opcion' => null],
                ];
            }
            $res->free();
        }

        // Sólo platillos activos, como el intérprete: lo retirado de la carta
        // se sigue pudiendo preguntar por frase si aún tiene historial.
        $res = $db->query(
            "SELECT p.id, p.nombre, c.nombre AS categoria
               FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 ORDER BY p.nombre"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ventas[] = [
                    'valor' => 'ventas_producto:' . (int) $row['id'],
                    'etiqueta' => (string) $row['nombre'],
                    'grupo' => 'Platillos',
                    'detalle' => (string) ($row['categoria'] ?? ''),
                    'consulta' => ['intencion' => 'ventas_producto', 'id' => (int) $row['id'], 'opcion' => null],
                ];
            }
            $res->free();
        }

        // Los inactivos van al final y apartados, no fuera: el intérprete los
        // contesta, y su existencia sigue contando en el almacén.
        $ingredientes = [];
        $res = $db->query("SELECT id, nombre, activo FROM ingredientes ORDER BY activo DESC, nombre");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $activo = (int) $row['activo'] === 1;
                $ingredientes[] = [
                    'valor' => 'stock:' . (int) $row['id'],
                    'etiqueta' => (string) $row['nombre'],
                    'grupo' => $activo ? 'Ingredientes' : 'Inactivos',
                    'detalle' => '',
                    'consulta' => ['intencion' => 'stock', 'id' => (int) $row['id'], 'opcion' => null],
                ];
            }
            $res->free();
        }

        return ['ventas' => $ventas, 'ingredientes' => $ingredientes];
    }

    /**
     * Periodos con los que se arman los ejemplos de cada pregunta, en orden de
     * preferencia. Son tramos en los que suele haber datos (casi nunca «hoy»
     * a primera hora): un ejemplo que contesta en cero enseña mal la pregunta.
     */
    private const PERIODOS_DE_EJEMPLO = [
        'ventas_total' => ['hoy', 'ayer', 'semana', 'semana_pasada', 'mes', '30d'],
        'ventas_producto' => ['ayer', 'semana', 'semana_pasada', '7d', 'mes', '30d'],
        'ventas_categoria' => ['mes', 'semana', 'semana_pasada', '30d', '7d'],
        'top' => ['semana', 'semana_pasada', 'mes', '7d', '30d', 'ayer'],
        'ticket_promedio' => ['semana_pasada', 'semana', 'mes', '30d', 'ayer'],
        'sin_venta' => ['30d', '7d', 'semana_pasada', 'mes'],
        'meseros' => ['semana_pasada', 'semana', 'mes', '30d', 'ayer'],
        'horas_pico' => ['semana_pasada', 'semana', 'mes', '30d', '7d'],
        'cancelaciones' => ['30d', 'mes', 'semana_pasada', '7d'],
        'consumo' => ['semana', '7d', '30d', 'mes'],
    ];

    /** Cuántas variantes de ejemplo se ofrecen como mucho por pregunta. */
    private const MAX_EJEMPLOS = 8;

    /**
     * Todo lo que el motor sabe responder, con la forma de su pregunta (la
     * plantilla, con los huecos sin llenar) y ejemplos armados con los datos
     * de la casa. Es lo que el Asistente enseña en su estado vacío y lo que
     * arma en su compositor: la lista entera, no una muestra. Va en el orden
     * de INTENCIONES.
     *
     * `ejemplo` es el primero de `ejemplos`: varias combinaciones REALES de
     * cosa y periodo (los platillos y categorías que más salen, los
     * ingredientes más justos o más consumidos), para que el Asistente arme
     * cada vez uno distinto y la pregunta se entienda por su uso y no por un
     * valor que se repite siempre.
     *
     * @return array{intenciones: list<array<string, mixed>>, periodos: list<array<string, mixed>>}
     */
    public static function capacidades(): array
    {
        $d = self::datosDeEjemplo();
        $hoy = self::periodo('hoy');
        $ayer = self::periodo('ayer');
        $semana = self::periodo('semana');
        $semanaPasada = self::periodo('semana_pasada');
        $mes = self::periodo('mes');
        $dias30 = self::periodo('30d');

        $hVentas = ['tipo' => 'hueco', 'campo' => 'entidad', 'rotulo' => self::ROTULO_VENTAS, 'catalogo' => 'ventas'];
        $hIngrediente = ['tipo' => 'hueco', 'campo' => 'entidad', 'rotulo' => self::ROTULO_INGREDIENTE, 'catalogo' => 'ingredientes'];
        // El consumo elige de la MISMA lista de ingredientes que la existencia,
        // pero cada opción de esa lista trae la consulta de existencia: el
        // hueco declara su intención para que elegir otro ingrediente siga
        // preguntando por el consumo.
        $hConsumo = $hIngrediente + ['intencion' => 'consumo'];
        $hPeriodo = ['tipo' => 'hueco', 'campo' => 'periodo', 'rotulo' => 'Periodo'];
        $t = static fn (string $texto): array => ['tipo' => 'texto', 'texto' => $texto];
        $sinCosa = static fn (string $texto): array => [$t($texto), $t('·'), $hPeriodo];

        $porClave = [
            'ventas_total' => [
                'titulo' => 'Ventas totales',
                'descripcion' => 'Importe cobrado, tickets cerrados y ticket promedio del restaurante en un periodo.',
                'plantilla' => [$t('Ventas de'), $hVentas, $t('·'), $hPeriodo],
                'ejemplo' => self::ejemplo('ventas_total', null, $hoy),
            ],
            'ventas_producto' => [
                'titulo' => 'Ventas de un platillo',
                'descripcion' => 'Unidades, importe y tickets de un platillo en un periodo, con desglose por opción.',
                'plantilla' => [$t('Ventas de'), $hVentas, $t('·'), $hPeriodo],
                'ejemplo' => $d['producto'] ? self::ejemplo('ventas_producto', $d['producto'], $ayer) : null,
            ],
            'ventas_categoria' => [
                'titulo' => 'Ventas por categoría',
                'descripcion' => 'Lo vendido de una categoría de la carta («Cocktails», «Pizzas») y sus platillos más pedidos.',
                'plantilla' => [$t('Ventas de'), $hVentas, $t('·'), $hPeriodo],
                'ejemplo' => $d['categoria'] ? self::ejemplo('ventas_categoria', $d['categoria'], $mes) : null,
            ],
            'top' => [
                'titulo' => 'Lo más vendido',
                'descripcion' => 'Los cinco platillos con más unidades vendidas en un periodo.',
                'plantilla' => $sinCosa('Lo más vendido'),
                'ejemplo' => self::ejemplo('top', null, $semana),
            ],
            'ticket_promedio' => [
                'titulo' => 'Ticket promedio y comensales',
                'descripcion' => 'Cuánto deja cada cuenta y cada comensal, y cuántos comensales llegan por mesa.',
                'plantilla' => $sinCosa('Ticket promedio'),
                'ejemplo' => self::ejemplo('ticket_promedio', null, $semanaPasada),
            ],
            'sin_venta' => [
                'titulo' => 'Platillos sin venta',
                'descripcion' => 'Los platillos de la carta que nadie pidió en un periodo: candidatos a revisar.',
                'plantilla' => $sinCosa('Platillos sin venta'),
                'ejemplo' => self::ejemplo('sin_venta', null, $dias30),
            ],
            'meseros' => [
                'titulo' => 'Ventas por mesero',
                'descripcion' => 'Importe, tickets y propinas de cada mesero en un periodo.',
                'plantilla' => $sinCosa('Ventas por mesero'),
                'ejemplo' => self::ejemplo('meseros', null, $semanaPasada),
            ],
            'horas_pico' => [
                'titulo' => 'Horas pico',
                'descripcion' => 'A qué hora se abren más mesas, hora por hora: para planear el turno.',
                'plantilla' => $sinCosa('Horas pico'),
                'ejemplo' => self::ejemplo('horas_pico', null, $semanaPasada),
            ],
            'cancelaciones' => [
                'titulo' => 'Cancelaciones',
                'descripcion' => 'Platillos cancelados en cuentas cerradas: cuántos, cuánto dinero y cuáles se cancelan más.',
                'plantilla' => $sinCosa('Cancelaciones'),
                'ejemplo' => self::ejemplo('cancelaciones', null, $dias30),
            ],
            'stock' => [
                'titulo' => 'Existencia de un ingrediente',
                'descripcion' => 'Cuánto queda en almacén, contra su mínimo, su valor y para cuántos días alcanza al ritmo de la última semana.',
                'plantilla' => [$hIngrediente, $t('en almacén')],
                'ejemplo' => $d['ingrediente'] ? self::ejemplo('stock', $d['ingrediente'], $hoy) : null,
            ],
            'consumo' => [
                'titulo' => 'Consumo de un ingrediente',
                'descripcion' => 'Lo que salió del almacén por ventas en un periodo, la merma y los platillos que más lo usaron.',
                'plantilla' => [$t('Consumo de'), $hConsumo, $t('·'), $hPeriodo],
                'ejemplo' => $d['consumo'] ? self::ejemplo('consumo', $d['consumo'], $semana) : null,
            ],
            'bajo_stock' => [
                'titulo' => 'Lo que está por agotarse',
                'descripcion' => 'Los ingredientes activos por debajo de su mínimo, los más urgentes primero.',
                'plantilla' => [$t('Ingredientes bajo el mínimo')],
                'ejemplo' => self::ejemplo('bajo_stock', null, $hoy),
            ],
        ];

        // Las variantes: cada cosa candidata con un periodo distinto, en el
        // orden de preferencia. Las preguntas sin cosa varían sólo el periodo;
        // la existencia, sólo el ingrediente (no tiene fecha).
        $entidades = [
            'ventas_producto' => $d['productos'],
            'ventas_categoria' => $d['categorias'],
            'stock' => $d['ingredientes'],
            'consumo' => $d['consumos'],
        ];

        $intenciones = [];
        foreach (self::INTENCIONES as $clave) {
            $fila = ['intencion' => $clave] + $porClave[$clave];
            $ejemplos = self::variantesDeEjemplo(
                $clave,
                $entidades[$clave] ?? null,
                self::PERIODOS_DE_EJEMPLO[$clave] ?? ['hoy']
            );
            if ($fila['ejemplo'] === null && $ejemplos !== []) {
                $fila['ejemplo'] = $ejemplos[0];
            }
            $fila['ejemplos'] = $ejemplos !== [] ? $ejemplos : array_values(array_filter([$fila['ejemplo']]));
            $intenciones[] = $fila;
        }

        return ['intenciones' => $intenciones, 'periodos' => self::opcionesPeriodo()];
    }

    // ── Ejecución ───────────────────────────────────────────────

    /**
     * Resuelve la entidad por id, contesta y arma lo que hace iterable la
     * respuesta: la interpretación y la plantilla con sus huecos.
     */
    private static function ejecutar(string $intencion, ?int $id, ?int $opcionId, array $periodo, array $base): array
    {
        $entidad = null;
        $opcion = null;
        $opciones = [];

        switch ($intencion) {
            case 'ventas_producto':
                $entidad = $id !== null ? self::fila("SELECT id, nombre FROM productos WHERE id = {$id} LIMIT 1") : null;
                if ($entidad === null) {
                    return self::noEntendida('Ese platillo ya no está en la carta', 'Elige otro de la lista.', $periodo, $base);
                }
                $opciones = self::opcionesDe((int) $entidad['id']);
                foreach ($opciones as $o) {
                    if ($opcionId !== null && $o['id'] === $opcionId) {
                        $opcion = $o;
                    }
                }
                $respuesta = self::ventasDe((string) $entidad['nombre'], $periodo, $opcion['nombre'] ?? null);
                break;
            case 'ventas_categoria':
                $entidad = $id !== null ? self::fila("SELECT id, nombre FROM categorias WHERE id = {$id} LIMIT 1") : null;
                if ($entidad === null) {
                    return self::noEntendida('Esa categoría ya no existe', 'Elige otra de la lista.', $periodo, $base);
                }
                $respuesta = self::ventasCategoria((string) $entidad['nombre'], $periodo);
                break;
            case 'stock':
            case 'consumo':
                $entidad = $id !== null ? self::fila("SELECT id, nombre FROM ingredientes WHERE id = {$id} LIMIT 1") : null;
                if ($entidad === null) {
                    return self::noEntendida('Ese ingrediente ya no está en el inventario', 'Elige otro de la lista.', $periodo, $base);
                }
                $respuesta = $intencion === 'stock'
                    ? self::stockDe((int) $entidad['id'])
                    : self::consumoDe((int) $entidad['id'], $periodo);
                break;
            case 'ventas_total':
                $respuesta = self::ventasTotales($periodo);
                break;
            case 'top':
                $respuesta = self::topVendidos($periodo);
                break;
            case 'ticket_promedio':
                $respuesta = self::ticketPromedio($periodo);
                break;
            case 'sin_venta':
                $respuesta = self::sinVenta($periodo);
                break;
            case 'meseros':
                $respuesta = self::meseros($periodo);
                break;
            case 'horas_pico':
                $respuesta = self::horasPico($periodo);
                break;
            case 'cancelaciones':
                $respuesta = self::cancelaciones($periodo);
                break;
            default:
                $intencion = 'bajo_stock';
                $respuesta = self::bajoStock();
        }

        $nombre = $entidad !== null ? (string) $entidad['nombre'] : null;
        $texto = self::textoDe(self::partes($intencion, $nombre, $opcion['nombre'] ?? null, $periodo));

        return array_merge($base, [
            'consulta' => $base['consulta'] !== '' ? $base['consulta'] : $texto,
            'entendida' => true,
            'interpretacion' => [
                'intencion' => $intencion,
                'id' => $entidad !== null ? (int) $entidad['id'] : null,
                'opcion' => $opcion !== null ? $opcion['id'] : null,
                'periodo' => $periodo['clave'],
                'texto' => $texto,
            ],
            'plantilla' => self::plantilla($intencion, $entidad, $opcion, $opciones, $periodo),
            'respuesta' => $respuesta,
            'sugerencias' => [],
        ]);
    }

    /**
     * Lo que no se pudo contestar, dicho como dato y no como error: la
     * plantilla de rescate deja elegir de la lista y las sugerencias dan por
     * dónde seguir.
     */
    private static function noEntendida(string $titulo, string $frase, array $periodo, array $base, array $parecidos = []): array
    {
        $texto = $base['consulta'];

        return array_merge($base, [
            'entendida' => false,
            'interpretacion' => [
                'intencion' => null,
                'id' => null,
                'opcion' => null,
                'periodo' => $periodo['clave'],
                'texto' => $texto,
            ],
            'plantilla' => [
                ['tipo' => 'texto', 'texto' => 'Elige'],
                self::huecoVentas('', 'un platillo'),
                ['tipo' => 'texto', 'texto' => 'o'],
                self::huecoIngrediente('', 'un ingrediente'),
            ],
            'respuesta' => ['tipo' => 'sin_resultado', 'titulo' => $titulo, 'frase' => $frase],
            'sugerencias' => self::sugerencias($parecidos, $periodo),
        ]);
    }

    /**
     * Primero lo que se parece a lo escrito; después, una pregunta de cada
     * clase que sí se sabe contestar.
     *
     * @param list<array{intencion: string, id: int, nombre: string}> $parecidos
     * @return list<array<string, mixed>>
     */
    private static function sugerencias(array $parecidos, array $periodo): array
    {
        $salida = [];
        $vistas = [];
        foreach ($parecidos as $p) {
            $vistas[$p['intencion'] . ':' . $p['id']] = true;
            $partes = self::partes($p['intencion'], $p['nombre'], null, $periodo);
            $salida[] = [
                'motivo' => 'parecido',
                'etiqueta' => $p['nombre'],
                'tipo' => $p['intencion'] === 'stock' ? 'stock' : 'ventas',
                'texto' => self::textoDe($partes),
                'consulta' => ['intencion' => $p['intencion'], 'id' => $p['id'], 'opcion' => null, 'periodo' => $periodo['clave']],
            ];
        }
        $d = self::datosDeEjemplo();
        $ejemplos = array_filter([
            $d['producto'] ? self::ejemplo('ventas_producto', $d['producto'], $periodo) : null,
            $d['ingrediente'] ? self::ejemplo('stock', $d['ingrediente'], $periodo) : null,
            self::ejemplo('top', null, self::periodo('semana')),
        ]);
        foreach ($ejemplos as $e) {
            if (isset($vistas[$e['consulta']['intencion'] . ':' . $e['consulta']['id']])) {
                continue;
            }
            $salida[] = [
                'motivo' => 'ejemplo',
                'etiqueta' => $e['texto'],
                'tipo' => $e['consulta']['intencion'] === 'stock' ? 'stock' : 'ventas',
                'texto' => $e['texto'],
                'consulta' => $e['consulta'],
            ];
        }

        return $salida;
    }

    // ── Plantillas y textos ─────────────────────────────────────

    /**
     * La consulta como frase con huecos. Es la misma pregunta que se hizo,
     * dicha de vuelta, con cada variable lista para cambiarse.
     *
     * @return list<array<string, mixed>>
     */
    private static function plantilla(string $intencion, ?array $entidad, ?array $opcion, array $opciones, array $periodo): array
    {
        $t = static fn (string $texto): array => ['tipo' => 'texto', 'texto' => $texto];
        $id = $entidad !== null ? (int) $entidad['id'] : 0;
        $nombre = $entidad !== null ? (string) $entidad['nombre'] : '';
        // Las preguntas sin cosa: un rótulo y su periodo.
        $sinCosa = static fn (string $texto): array => [$t($texto), $t('·'), self::huecoPeriodo($periodo)];

        switch ($intencion) {
            case 'ventas_producto':
                $partes = [$t('Ventas de'), self::huecoVentas('ventas_producto:' . $id, $nombre)];
                // La opción sólo es hueco si el platillo tiene de dónde elegir.
                if ($opciones !== []) {
                    $partes[] = $t('con');
                    $partes[] = self::huecoOpcion($opcion, $opciones);
                }
                $partes[] = $t('·');
                $partes[] = self::huecoPeriodo($periodo);
                return $partes;
            case 'ventas_categoria':
                return [$t('Ventas de'), self::huecoVentas('ventas_categoria:' . $id, $nombre), $t('·'), self::huecoPeriodo($periodo)];
            case 'ventas_total':
                return [$t('Ventas de'), self::huecoVentas('ventas_total', 'todo el restaurante'), $t('·'), self::huecoPeriodo($periodo)];
            case 'top':
                return $sinCosa('Lo más vendido');
            case 'ticket_promedio':
                return $sinCosa('Ticket promedio');
            case 'sin_venta':
                return $sinCosa('Platillos sin venta');
            case 'meseros':
                return $sinCosa('Ventas por mesero');
            case 'horas_pico':
                return $sinCosa('Horas pico');
            case 'cancelaciones':
                return $sinCosa('Cancelaciones');
            case 'stock':
                return [self::huecoIngrediente('stock:' . $id, $nombre), $t('en almacén')];
            case 'consumo':
                return [$t('Consumo de'), self::huecoIngrediente('stock:' . $id, $nombre, 'consumo'), $t('·'), self::huecoPeriodo($periodo)];
            default:
                return [$t('Ingredientes bajo el mínimo')];
        }
    }

    private static function huecoVentas(string $valor, string $etiqueta): array
    {
        return [
            'tipo' => 'hueco',
            'campo' => 'entidad',
            'rotulo' => self::ROTULO_VENTAS,
            'catalogo' => 'ventas',
            'valor' => $valor,
            'etiqueta' => $etiqueta,
        ];
    }

    /**
     * El valor tiene la forma de las opciones del catálogo (`stock:6`) para que
     * el selector marque la vigente. `$intencion` la fija el hueco cuando no es
     * la de la lista: las opciones de ingredientes traen la consulta de
     * existencia, y elegir otro ingrediente en «Consumo de [Leche ▾]» tiene que
     * seguir preguntando por el consumo.
     */
    private static function huecoIngrediente(string $valor, string $etiqueta, ?string $intencion = null): array
    {
        $hueco = [
            'tipo' => 'hueco',
            'campo' => 'entidad',
            'rotulo' => self::ROTULO_INGREDIENTE,
            'catalogo' => 'ingredientes',
            'valor' => $valor,
            'etiqueta' => $etiqueta,
        ];
        if ($intencion !== null) {
            $hueco['intencion'] = $intencion;
        }

        return $hueco;
    }

    private static function huecoPeriodo(array $periodo): array
    {
        return [
            'tipo' => 'hueco',
            'campo' => 'periodo',
            'rotulo' => 'Periodo',
            'valor' => $periodo['clave'],
            'etiqueta' => $periodo['etiqueta'],
            'opciones' => self::opcionesPeriodo($periodo),
        ];
    }

    /**
     * @param list<array{id: int, grupo: string, nombre: string}> $opciones
     */
    private static function huecoOpcion(?array $elegida, array $opciones): array
    {
        $lista = [['valor' => '', 'etiqueta' => 'Cualquier opción', 'grupo' => null, 'consulta' => ['opcion' => null]]];
        foreach ($opciones as $o) {
            $lista[] = ['valor' => (string) $o['id'], 'etiqueta' => $o['nombre'], 'grupo' => $o['grupo'], 'consulta' => ['opcion' => $o['id']]];
        }

        return [
            'tipo' => 'hueco',
            'campo' => 'opcion',
            'rotulo' => 'Opción',
            'valor' => $elegida !== null ? (string) $elegida['id'] : '',
            'etiqueta' => $elegida !== null ? $elegida['nombre'] : 'cualquier opción',
            'opciones' => $lista,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function opcionesPeriodo(?array $actual = null): array
    {
        $ops = [];
        foreach (self::PERIODOS as $clave => $etiqueta) {
            $ops[] = ['valor' => $clave, 'etiqueta' => $etiqueta, 'grupo' => self::GRUPO_PERIODO[$clave], 'consulta' => ['periodo' => $clave]];
        }
        // «Últimos 14 días» no está en la lista, pero se preguntó así: se
        // conserva para que el hueco no mienta sobre lo que está mostrando.
        if ($actual !== null && !isset(self::PERIODOS[$actual['clave']])) {
            $ops[] = ['valor' => $actual['clave'], 'etiqueta' => $actual['etiqueta'], 'grupo' => 'A medida', 'consulta' => ['periodo' => $actual['clave']]];
        }

        return $ops;
    }

    /**
     * La pregunta escrita como la diría alguien, con las variables marcadas.
     * De aquí sale el texto canónico de cada respuesta —el que el Asistente
     * enseña como pregunta y como título de la conversación—, y el intérprete
     * lo vuelve a entender igual (lo comprueba run-consultas-rapidas-db.php).
     *
     * @return list<array{texto: string, hueco: bool}>
     */
    private static function partes(string $intencion, ?string $nombre, ?string $opcion, array $periodo): array
    {
        // La frase y no la etiqueta: «la semana pasada», «en los últimos 7
        // días». Es como se dice, y el intérprete la entiende igual.
        $cuando = mb_strtolower($periodo['frase']);
        $p = static fn (string $texto, bool $hueco = false): array => ['texto' => $texto, 'hueco' => $hueco];
        $sinCosa = static fn (string $texto): array => [$p($texto . ' '), $p($cuando, true)];

        switch ($intencion) {
            case 'ventas_producto':
                $partes = [$p('Ventas de '), $p((string) $nombre, true)];
                if ($opcion !== null) {
                    $partes[] = $p(' con ');
                    $partes[] = $p($opcion, true);
                }
                $partes[] = $p(' ');
                $partes[] = $p($cuando, true);
                return $partes;
            case 'ventas_categoria':
                return [$p('Ventas de '), $p((string) $nombre, true), $p(' '), $p($cuando, true)];
            case 'ventas_total':
                return [$p('¿Cuánto vendimos '), $p($cuando, true), $p('?')];
            case 'top':
                return $sinCosa('Lo más vendido');
            case 'ticket_promedio':
                return $sinCosa('Ticket promedio');
            case 'sin_venta':
                return $sinCosa('Platillos sin venta');
            case 'meseros':
                return $sinCosa('Ventas por mesero');
            case 'horas_pico':
                return $sinCosa('Horas pico');
            case 'cancelaciones':
                return $sinCosa('Cancelaciones');
            case 'stock':
                return [$p((string) $nombre, true), $p(' en almacén')];
            case 'consumo':
                return [$p('Consumo de '), $p((string) $nombre, true), $p(' '), $p($cuando, true)];
            default:
                return [$p('¿Qué está por agotarse?')];
        }
    }

    private static function textoDe(array $partes): string
    {
        return implode('', array_map(static fn (array $p): string => $p['texto'], $partes));
    }

    /**
     * @param array{id: int, nombre: string}|null $entidad
     */
    private static function ejemplo(string $intencion, ?array $entidad, array $periodo): array
    {
        $partes = self::partes($intencion, $entidad['nombre'] ?? null, null, $periodo);

        return [
            'texto' => self::textoDe($partes),
            'partes' => $partes,
            'consulta' => [
                'intencion' => $intencion,
                'id' => $entidad !== null ? (int) $entidad['id'] : null,
                'opcion' => null,
                'periodo' => $periodo['clave'],
            ],
        ];
    }

    /**
     * Las variantes de ejemplo de una pregunta: la cosa i-ésima con el
     * periodo i-ésimo (dando la vuelta a la lista de periodos), hasta
     * MAX_EJEMPLOS. Con `$cosas === null` la pregunta no lleva cosa y cada
     * variante es un periodo; con una lista vacía (no hay ninguna activa) no
     * hay ejemplo que dar.
     *
     * @param list<array{id: int, nombre: string}>|null $cosas
     * @param list<string> $periodos
     * @return list<array<string, mixed>>
     */
    private static function variantesDeEjemplo(string $intencion, ?array $cosas, array $periodos): array
    {
        $periodos = array_values(array_filter($periodos, static fn (string $p): bool => isset(self::PERIODOS[$p])));
        if ($periodos === []) {
            $periodos = ['hoy'];
        }
        if ($intencion === 'bajo_stock') {
            return [self::ejemplo('bajo_stock', null, self::periodo('hoy'))];
        }

        $ejemplos = [];
        if ($cosas === null) {
            foreach (array_slice($periodos, 0, self::MAX_EJEMPLOS) as $p) {
                $ejemplos[] = self::ejemplo($intencion, null, self::periodo($p));
            }

            return $ejemplos;
        }

        $n = count($periodos);
        foreach (array_slice($cosas, 0, self::MAX_EJEMPLOS) as $i => $cosa) {
            // La existencia es de hoy: no tiene periodo que variar.
            $p = $intencion === 'stock' ? 'hoy' : $periodos[$i % $n];
            $ejemplos[] = self::ejemplo($intencion, $cosa, self::periodo($p));
        }

        return $ejemplos;
    }

    /**
     * El platillo y la categoría que más salen, el ingrediente más justo de
     * stock y el que más salió por ventas —más las listas de candidatos para
     * las variantes de ejemplo—. Sin ventas recientes, cualquiera activo: un
     * ejemplo que no se puede enseñar deja la lista coja.
     *
     * @return array{producto: ?array, categoria: ?array, ingrediente: ?array, consumo: ?array, productos: list<array>, categorias: list<array>, ingredientes: list<array>, consumos: list<array>}
     */
    private static function datosDeEjemplo(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = ['producto' => null, 'categoria' => null, 'ingrediente' => null, 'consumo' => null];
        $listas = ['productos' => [], 'categorias' => [], 'ingredientes' => [], 'consumos' => []];
        $max = self::MAX_EJEMPLOS;
        try {
            $recientes = "t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                    AND COALESCE(t.hora_cierre, t.hora_apertura) >= CURDATE() - INTERVAL 30 DAY";
            $cache['producto'] = self::fila(
                "SELECT p.id, p.nombre FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                   JOIN productos p ON p.nombre = ti.nombre
                  WHERE {$recientes}
                  GROUP BY p.id, p.nombre ORDER BY SUM(ti.cantidad) DESC LIMIT 1"
            ) ?? self::fila("SELECT id, nombre FROM productos WHERE activo = 1 ORDER BY nombre LIMIT 1");
            $cache['categoria'] = self::fila(
                "SELECT c.id, c.nombre FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                   JOIN categorias c ON c.nombre = ti.categoria
                  WHERE {$recientes}
                  GROUP BY c.id, c.nombre ORDER BY SUM(ti.cantidad) DESC LIMIT 1"
            ) ?? self::fila("SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre LIMIT 1");
            $cache['ingrediente'] = self::fila(
                "SELECT id, nombre FROM ingredientes WHERE activo = 1 AND stock_minimo > 0
                  ORDER BY stock / stock_minimo ASC LIMIT 1"
            ) ?? self::fila("SELECT id, nombre FROM ingredientes WHERE activo = 1 ORDER BY nombre LIMIT 1");
            // El consumo sale de los movimientos de venta: el ejemplo es el
            // ingrediente que más se descontó, para que no conteste en cero.
            $cache['consumo'] = self::fila(
                "SELECT i.id, i.nombre FROM movimientos_inventario m JOIN ingredientes i ON i.id = m.ingrediente_id
                  WHERE m.tipo IN ('venta', 'cancelacion') AND m.created_at >= CURDATE() - INTERVAL 30 DAY
                  GROUP BY i.id, i.nombre HAVING -SUM(m.cantidad) > 0 ORDER BY -SUM(m.cantidad) DESC LIMIT 1"
            ) ?? $cache['ingrediente'];

            // Candidatos para las variantes: los mismos criterios, más filas.
            // Sin datos recientes, activos cualesquiera.
            $listas['productos'] = self::filas(
                "SELECT p.id, p.nombre FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                   JOIN productos p ON p.nombre = ti.nombre
                  WHERE {$recientes} AND p.activo = 1
                  GROUP BY p.id, p.nombre ORDER BY SUM(ti.cantidad) DESC LIMIT {$max}"
            ) ?: self::filas("SELECT id, nombre FROM productos WHERE activo = 1 ORDER BY RAND() LIMIT {$max}");
            $listas['categorias'] = self::filas(
                "SELECT c.id, c.nombre FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                   JOIN categorias c ON c.nombre = ti.categoria
                  WHERE {$recientes}
                  GROUP BY c.id, c.nombre ORDER BY SUM(ti.cantidad) DESC LIMIT {$max}"
            ) ?: self::filas("SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY RAND() LIMIT {$max}");
            $listas['ingredientes'] = self::filas(
                "SELECT id, nombre FROM ingredientes WHERE activo = 1 AND stock_minimo > 0
                  ORDER BY stock / stock_minimo ASC LIMIT {$max}"
            ) ?: self::filas("SELECT id, nombre FROM ingredientes WHERE activo = 1 ORDER BY RAND() LIMIT {$max}");
            $listas['consumos'] = self::filas(
                "SELECT i.id, i.nombre FROM movimientos_inventario m JOIN ingredientes i ON i.id = m.ingrediente_id
                  WHERE m.tipo IN ('venta', 'cancelacion') AND m.created_at >= CURDATE() - INTERVAL 30 DAY
                  GROUP BY i.id, i.nombre HAVING -SUM(m.cantidad) > 0 ORDER BY -SUM(m.cantidad) DESC LIMIT {$max}"
            ) ?: $listas['ingredientes'];
        } catch (\Throwable $e) {
            error_log('ConsultasRapidas::datosDeEjemplo - ' . $e->getMessage());
        }
        $normal = static fn (array $fila): array => ['id' => (int) $fila['id'], 'nombre' => (string) $fila['nombre']];
        foreach ($cache as $k => $fila) {
            if ($fila !== null) {
                $cache[$k] = $normal($fila);
            }
        }
        foreach ($listas as $k => $filas) {
            $cache[$k] = array_values(array_map($normal, $filas));
        }

        return $cache;
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
        $actual = self::sumaVentas($nombre, $periodo['desde'], $periodo['hasta'], $filtroOpcion);
        $previo = self::sumaVentas($nombre, $periodo['previo_desde'], $periodo['previo_hasta'], $filtroOpcion);

        $detalle = [];
        $res = $db->query(
            "SELECT COALESCE(NULLIF(ti.variante, ''), '') AS variante, SUM(ti.cantidad) AS u
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE ti.nombre = '{$nombre}' AND t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$filtroOpcion}
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
     * Ventas de una categoría de la carta. ticket_items.categoria es el nombre
     * de la categoría congelado al vender, igual que el del platillo.
     */
    private static function ventasCategoria(string $categoria, array $periodo): array
    {
        $db = ActiveRecord::getDB();
        $cat = $db->real_escape_string($categoria);
        $suma = static function (string $desde, string $hasta) use ($db, $cat): array {
            $res = $db->query(
                "SELECT COALESCE(SUM(ti.cantidad), 0) AS u, COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe
                   FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE ti.categoria = '{$cat}' AND t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                    AND " . self::filtroFecha($desde, $hasta)
            );
            $row = $res ? $res->fetch_assoc() : null;
            return ['unidades' => (int) ($row['u'] ?? 0), 'importe' => (float) ($row['importe'] ?? 0)];
        };
        $actual = $suma($periodo['desde'], $periodo['hasta']);
        $previo = $suma($periodo['previo_desde'], $periodo['previo_hasta']);

        // El id del platillo sale por nombre (UNIQUE): es lo que deja que el
        // renglón del desglose abra la consulta de ese platillo.
        $detalle = [];
        $res = $db->query(
            "SELECT ti.nombre, SUM(ti.cantidad) AS u, MAX(p.id) AS producto_id
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
               LEFT JOIN productos p ON p.nombre = ti.nombre
              WHERE ti.categoria = '{$cat}' AND t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                AND " . self::filtroFecha($periodo['desde'], $periodo['hasta']) . "
              GROUP BY ti.nombre ORDER BY u DESC LIMIT 6"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $detalle[] = self::renglonPlatillo($row);
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

    /**
     * Un renglón de desglose que abre la consulta de su platillo. `consulta`
     * no hereda el periodo a propósito: lo pone quien la dispara, que es quien
     * sabe cuál está mirando. Un nombre vendido que ya no es un producto (se
     * renombró) se queda como texto: sin texto libre no hay frase que mandar.
     */
    private static function renglonPlatillo(array $row): array
    {
        $renglon = [
            'etiqueta' => (string) $row['nombre'],
            'valor' => (int) $row['u'],
        ];
        if (!empty($row['producto_id'])) {
            $renglon['consulta'] = ['intencion' => 'ventas_producto', 'id' => (int) $row['producto_id'], 'opcion' => null];
        }

        return $renglon;
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
            "SELECT ti.nombre, SUM(ti.cantidad) AS u, SUM(ti.precio * ti.cantidad) AS importe, MAX(p.id) AS producto_id
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
               LEFT JOIN productos p ON p.nombre = ti.nombre
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                AND " . self::filtroFecha($periodo['desde'], $periodo['hasta']) . "
              GROUP BY ti.nombre ORDER BY u DESC, importe DESC LIMIT 5"
        );
        $detalle = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $detalle[] = self::renglonPlatillo($row);
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
            "SELECT id, nombre, unidad, stock, stock_minimo FROM ingredientes
              WHERE activo = 1 AND stock <= stock_minimo
              ORDER BY (stock / NULLIF(stock_minimo, 0)) ASC, nombre ASC LIMIT 8"
        );
        $detalle = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $detalle[] = [
                    'etiqueta' => (string) $row['nombre'],
                    'valor' => self::cantidad((float) $row['stock'], (string) $row['unidad']) . ' de ' . self::cantidad((float) $row['stock_minimo'], (string) $row['unidad']),
                    'consulta' => ['intencion' => 'stock', 'id' => (int) $row['id'], 'opcion' => null],
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

    // ── Respuestas: servicio, equipo y almacén ──────────────────
    //
    // Mismo criterio de venta que el resto del panel: tickets CERRADOS, sin
    // renglones cancelados, fechados por COALESCE(hora_cierre, hora_apertura).
    // Un ticket cuenta si tiene al menos un renglón vivo, como en ventas
    // totales: así el ticket promedio de aquí y el de su frase son el mismo.

    /**
     * Cuánto deja cada cuenta y cada comensal, y cuántos comensales llegan
     * por mesa. `comensales` es lo que el mesero capturó al abrir.
     */
    private static function ticketPromedio(array $periodo): array
    {
        $calc = static function (string $desde, string $hasta): array {
            $row = self::fila(
                "SELECT COUNT(*) AS tickets, COALESCE(SUM(x.importe), 0) AS importe, COALESCE(SUM(x.comensales), 0) AS comensales
                   FROM (SELECT t.id, MAX(t.comensales) AS comensales, SUM(ti.precio * ti.cantidad) AS importe
                           FROM tickets t JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.estado <> 'cancelado'
                          WHERE t.estado = 'cerrado' AND " . self::filtroFecha($desde, $hasta) . "
                          GROUP BY t.id) x"
            );
            $tickets = (int) ($row['tickets'] ?? 0);
            $importe = (float) ($row['importe'] ?? 0);

            return [
                'tickets' => $tickets,
                'importe' => $importe,
                'comensales' => (int) ($row['comensales'] ?? 0),
                'promedio' => $tickets > 0 ? $importe / $tickets : 0.0,
            ];
        };
        $a = $calc($periodo['desde'], $periodo['hasta']);
        $p = $calc($periodo['previo_desde'], $periodo['previo_hasta']);
        $cuando = mb_strtolower($periodo['frase']);
        $enlace = ['href' => '/admin/tickets', 'texto' => 'Ver tickets'];

        // Sin cuentas no hay promedio: compararlo con el anterior diría que
        // bajó, y no bajó, no existe.
        if ($a['tickets'] === 0) {
            return ['tipo' => 'ticket_promedio', 'titulo' => 'Ticket promedio', 'frase' => 'No hay tickets cerrados ' . $cuando . '.', 'detalle' => [], 'enlace' => $enlace];
        }
        $porComensal = $a['comensales'] > 0 ? $a['importe'] / $a['comensales'] : 0.0;

        return [
            'tipo' => 'ticket_promedio',
            'titulo' => 'Ticket promedio',
            'cifra' => self::dinero($a['promedio']),
            'unidad' => 'por cuenta',
            'frase' => self::conteo($a['tickets'], 'ticket cerrado', 'tickets cerrados') . ' ' . $cuando
                . ' con ' . self::conteo($a['comensales'], 'comensal', 'comensales') . ': '
                . self::dinero($porComensal) . ' por comensal y ' . self::numero($a['comensales'] / $a['tickets']) . ' por mesa.',
            'comparacion' => $p['tickets'] > 0 ? self::comparacion($a['promedio'], $p['promedio'], $periodo['previo_etiqueta'], true) : null,
            'detalle_titulo' => 'Desglose',
            'detalle' => [
                ['etiqueta' => 'Venta del periodo', 'valor' => self::dinero($a['importe'])],
                ['etiqueta' => 'Tickets cerrados', 'valor' => number_format($a['tickets'])],
                ['etiqueta' => 'Comensales', 'valor' => number_format($a['comensales'])],
                ['etiqueta' => 'Por comensal', 'valor' => self::dinero($porComensal)],
            ],
            'enlace' => $enlace,
        ];
    }

    /**
     * Los platillos de la carta que nadie pidió. «La carta» es lo que el POS
     * ofrece: platillo activo en una categoría activa. Un platillo de una
     * categoría apagada no se podía pedir, así que no es un platillo sin venta.
     */
    private static function sinVenta(array $periodo): array
    {
        $carta = 'FROM productos p JOIN categorias c ON c.id = p.categoria_id WHERE p.activo = 1 AND c.activo = 1';
        $sinVentaEntre = static fn (string $desde, string $hasta): string =>
            " AND NOT EXISTS (SELECT 1 FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                               WHERE ti.nombre = p.nombre AND t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                                 AND " . self::filtroFecha($desde, $hasta) . ')';

        $enCarta = (int) (self::fila("SELECT COUNT(*) AS n {$carta}")['n'] ?? 0);
        $previo = (int) (self::fila("SELECT COUNT(*) AS n {$carta}" . $sinVentaEntre($periodo['previo_desde'], $periodo['previo_hasta']))['n'] ?? 0);
        $filas = self::filas(
            "SELECT p.id, p.nombre, c.nombre AS categoria {$carta}"
            . $sinVentaEntre($periodo['desde'], $periodo['hasta'])
            . ' ORDER BY c.nombre, p.nombre'
        );
        $n = count($filas);
        $cuando = mb_strtolower($periodo['frase']);

        // El renglón abre las ventas del platillo: en este periodo saldrán en
        // cero, pero su comparación dice si antes sí se vendía.
        $detalle = [];
        foreach (array_slice($filas, 0, self::TOPE_SIN_VENTA) as $f) {
            $detalle[] = [
                'etiqueta' => (string) $f['nombre'],
                'valor' => (string) $f['categoria'],
                'consulta' => ['intencion' => 'ventas_producto', 'id' => (int) $f['id'], 'opcion' => null],
            ];
        }

        if ($n === 0) {
            $frase = 'Todos los platillos de la carta se vendieron al menos una vez ' . $cuando . '.';
        } elseif ($n === $enCarta) {
            $frase = 'Ningún platillo de la carta se vendió ' . $cuando . '.';
        } else {
            $frase = 'De los ' . $enCarta . ' platillos de la carta, ' . ($n === 1 ? '1 no se vendió ' : $n . ' no se vendieron ') . $cuando . '.';
        }

        return [
            'tipo' => 'sin_venta',
            'titulo' => 'Platillos sin venta',
            'cifra' => $n,
            'unidad' => $n === 1 ? 'platillo sin venta' : 'platillos sin venta',
            'frase' => $frase,
            'comparacion' => self::comparacion($n, $previo, $periodo['previo_etiqueta'], false, 'menos'),
            'detalle_titulo' => $n === 0 ? null : ($n > self::TOPE_SIN_VENTA ? 'Los primeros ' . self::TOPE_SIN_VENTA . ', por categoría' : 'Por categoría'),
            'detalle' => $detalle,
            'enlace' => ['href' => '/admin/menu', 'texto' => 'Abrir menú'],
        ];
    }

    /**
     * Importe, tickets y propinas por mesero. La propina es del ticket, así
     * que se suma por ticket y no por renglón: unida a los renglones contaba
     * una vez por platillo.
     */
    private static function meseros(array $periodo): array
    {
        $calc = static fn (string $desde, string $hasta): array => self::filas(
            "SELECT x.mesero_id, MAX(u.nombre) AS nombre, COUNT(*) AS tickets,
                    SUM(x.importe) AS importe, SUM(x.propina) AS propina
               FROM (SELECT t.id, t.mesero_id, MAX(t.propina) AS propina, SUM(ti.precio * ti.cantidad) AS importe
                       FROM tickets t JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.estado <> 'cancelado'
                      WHERE t.estado = 'cerrado' AND " . self::filtroFecha($desde, $hasta) . "
                      GROUP BY t.id, t.mesero_id) x
               LEFT JOIN usuarios u ON u.id = x.mesero_id
              GROUP BY x.mesero_id
              ORDER BY importe DESC"
        );
        $filas = $calc($periodo['desde'], $periodo['hasta']);
        $cuando = mb_strtolower($periodo['frase']);
        $enlace = ['href' => '/admin/tickets', 'texto' => 'Ver tickets'];
        if ($filas === []) {
            return ['tipo' => 'meseros', 'titulo' => 'Ventas por mesero', 'frase' => 'No hay tickets cerrados ' . $cuando . '.', 'detalle' => [], 'enlace' => $enlace];
        }

        $previos = [];
        foreach ($calc($periodo['previo_desde'], $periodo['previo_hasta']) as $f) {
            $previos[(string) $f['mesero_id']] = (float) $f['importe'];
        }

        // Las cuentas sin mesero (asignación apagada en el POS, o un mesero
        // que se borró) van a la lista, pero no pueden «encabezar».
        $lider = null;
        $total = 0.0;
        $tickets = 0;
        $detalle = [];
        foreach ($filas as $i => $f) {
            $importe = (float) $f['importe'];
            $total += $importe;
            $tickets += (int) $f['tickets'];
            if ($lider === null && $f['mesero_id'] !== null) {
                $lider = $f;
            }
            if ($i < self::TOPE_DESGLOSE) {
                $detalle[] = [
                    'etiqueta' => $f['mesero_id'] === null ? 'Sin mesero asignado' : (string) $f['nombre'],
                    'valor' => round($importe, 2),
                    'valor_texto' => self::dinero($importe) . ' · ' . self::conteo((int) $f['tickets'], 'ticket', 'tickets'),
                ];
            }
        }
        $entreTodos = 'Entre todos: ' . self::dinero($total) . ' en ' . self::conteo($tickets, 'ticket', 'tickets') . '.';

        if ($lider === null) {
            return [
                'tipo' => 'meseros',
                'titulo' => 'Ventas por mesero',
                'cifra' => self::dinero($total),
                'unidad' => 'sin mesero asignado',
                'frase' => 'Ninguna cuenta cerrada ' . $cuando . ' tiene mesero asignado. ' . $entreTodos,
                'detalle' => [],
                'enlace' => $enlace,
            ];
        }

        $importe = (float) $lider['importe'];

        return [
            'tipo' => 'meseros',
            'titulo' => 'Ventas por mesero',
            'cifra' => self::dinero($importe),
            'unidad' => (string) $lider['nombre'],
            'frase' => $lider['nombre'] . ' encabeza ' . $cuando . ' con ' . self::conteo((int) $lider['tickets'], 'ticket', 'tickets')
                . ' y ' . self::dinero((float) $lider['propina']) . ' de propina. ' . $entreTodos,
            // La cifra es la del primero, así que la comparación también: lo que
            // vendió esa persona en el periodo anterior.
            'comparacion' => self::comparacion($importe, $previos[(string) $lider['mesero_id']] ?? 0.0, $periodo['previo_etiqueta'], true),
            'detalle_titulo' => 'Por importe',
            'detalle' => $detalle,
            'enlace' => $enlace,
        ];
    }

    /**
     * A qué hora se abren las mesas. Por la hora de APERTURA y no la del cobro:
     * la pregunta es cuándo llega la gente, que es lo que decide el turno. El
     * periodo, en cambio, sigue el criterio de venta de todo el panel.
     */
    private static function horasPico(array $periodo): array
    {
        $calc = static function (string $desde, string $hasta): array {
            $porHora = [];
            $filas = self::filas(
                "SELECT HOUR(x.apertura) AS h, COUNT(*) AS tickets, COALESCE(SUM(x.comensales), 0) AS comensales
                   FROM (SELECT t.id, MAX(t.hora_apertura) AS apertura, MAX(t.comensales) AS comensales
                           FROM tickets t JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.estado <> 'cancelado'
                          WHERE t.estado = 'cerrado' AND " . self::filtroFecha($desde, $hasta) . "
                          GROUP BY t.id) x
                  GROUP BY h ORDER BY h"
            );
            foreach ($filas as $f) {
                $porHora[(int) $f['h']] = ['tickets' => (int) $f['tickets'], 'comensales' => (int) $f['comensales']];
            }

            return $porHora;
        };
        // La hora con más tickets; a igualdad, la de más comensales, y si
        // también empatan, la primera del día (el recorrido va en orden).
        $pico = static function (array $porHora): ?int {
            $mejor = null;
            foreach ($porHora as $h => $d) {
                if ($mejor === null
                    || $d['tickets'] > $porHora[$mejor]['tickets']
                    || ($d['tickets'] === $porHora[$mejor]['tickets'] && $d['comensales'] > $porHora[$mejor]['comensales'])) {
                    $mejor = $h;
                }
            }

            return $mejor;
        };
        $hora = static fn (int $h): string => sprintf('%02d:00', $h % 24);

        $a = $calc($periodo['desde'], $periodo['hasta']);
        $enlace = ['href' => '/admin/analytics', 'texto' => 'Ver análisis de ventas'];
        if ($a === []) {
            return ['tipo' => 'horas_pico', 'titulo' => 'Horas pico', 'frase' => 'No hay tickets cerrados ' . mb_strtolower($periodo['frase']) . '.', 'detalle' => [], 'enlace' => $enlace];
        }
        $h = (int) $pico($a);
        $previa = $pico($calc($periodo['previo_desde'], $periodo['previo_hasta']));
        $total = array_sum(array_column($a, 'tickets'));
        $n = $a[$h]['tickets'];

        // El día entero, de la primera a la última hora con mesas, con las
        // horas vacías de en medio: la forma del servicio también es el dato.
        $detalle = [];
        for ($i = min(array_keys($a)); $i <= max(array_keys($a)); $i++) {
            $tickets = $a[$i]['tickets'] ?? 0;
            $detalle[] = [
                'etiqueta' => $hora($i) . '–' . $hora($i + 1),
                'valor' => $tickets,
                'valor_texto' => self::conteo($tickets, 'ticket', 'tickets'),
            ];
        }

        // No hay «más» ni «menos» en una hora: se dice cuál fue la anterior.
        $contra = $periodo['previo_etiqueta'];
        $comparacion = null;
        if ($previa !== null) {
            $comparacion = $previa === $h
                ? ['texto' => 'La misma hora que ' . $contra, 'tendencia' => 'igual', 'lectura' => 'igual']
                : ['texto' => self::mayuscula($contra) . ': ' . $hora($previa), 'tendencia' => 'igual', 'lectura' => 'igual'];
        }

        return [
            'tipo' => 'horas_pico',
            'titulo' => 'Horas pico',
            'cifra' => $hora($h),
            'unidad' => 'hora pico',
            'frase' => self::mayuscula(mb_strtolower($periodo['frase'])) . ', entre las ' . $hora($h) . ' y las ' . $hora($h + 1)
                . ($n === 1 ? ' se abrió 1' : ' se abrieron ' . $n) . ' de ' . self::conteo($total, 'ticket', 'tickets')
                . ' (' . (int) round($n / max(1, $total) * 100) . ' %), con ' . self::conteo($a[$h]['comensales'], 'comensal', 'comensales') . '.',
            'comparacion' => $comparacion,
            'detalle_titulo' => 'Tickets por hora de llegada',
            'detalle' => $detalle,
            'enlace' => $enlace,
        ];
    }

    /**
     * Los platillos cancelados en cuentas cerradas. Un renglón cancelado ya
     * movió inventario y se repuso (Inventario::revertir), así que el dinero
     * que se dice aquí es venta que no ocurrió, no merma.
     */
    private static function cancelaciones(array $periodo): array
    {
        $calc = static function (string $desde, string $hasta): array {
            $row = self::fila(
                "SELECT COALESCE(SUM(CASE WHEN ti.estado = 'cancelado' THEN ti.cantidad END), 0) AS u,
                        COALESCE(SUM(CASE WHEN ti.estado = 'cancelado' THEN ti.precio * ti.cantidad END), 0) AS importe,
                        COUNT(DISTINCT CASE WHEN ti.estado = 'cancelado' THEN ti.ticket_id END) AS tickets,
                        COALESCE(SUM(ti.cantidad), 0) AS pedidos
                   FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND " . self::filtroFecha($desde, $hasta)
            );

            return [
                'u' => (int) ($row['u'] ?? 0),
                'importe' => (float) ($row['importe'] ?? 0),
                'tickets' => (int) ($row['tickets'] ?? 0),
                'pedidos' => (int) ($row['pedidos'] ?? 0),
            ];
        };
        $a = $calc($periodo['desde'], $periodo['hasta']);
        $p = $calc($periodo['previo_desde'], $periodo['previo_hasta']);
        $cuando = mb_strtolower($periodo['frase']);

        $detalle = [];
        $filas = $a['u'] > 0 ? self::filas(
            "SELECT ti.nombre, SUM(ti.cantidad) AS u, MAX(p.id) AS producto_id
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
               LEFT JOIN productos p ON p.nombre = ti.nombre
              WHERE ti.estado = 'cancelado' AND t.estado = 'cerrado' AND " . self::filtroFecha($periodo['desde'], $periodo['hasta']) . "
              GROUP BY ti.nombre ORDER BY u DESC, ti.nombre LIMIT 6"
        ) : [];
        foreach ($filas as $f) {
            $detalle[] = self::renglonPlatillo($f);
        }

        $u = $a['u'];
        $frase = $u === 0
            ? 'No se canceló ningún platillo en las cuentas cerradas ' . $cuando . '.'
            : ($u === 1 ? 'Se canceló 1 platillo' : 'Se cancelaron ' . $u . ' platillos')
                . ' en ' . self::conteo($a['tickets'], 'cuenta cerrada', 'cuentas cerradas') . ' ' . $cuando
                . ', por ' . self::dinero($a['importe']) . ': el ' . self::numero(round($u / max(1, $a['pedidos']) * 100, 1)) . ' % de lo pedido.';

        return [
            'tipo' => 'cancelaciones',
            'titulo' => 'Cancelaciones',
            'cifra' => $u,
            'unidad' => $u === 1 ? 'platillo cancelado' : 'platillos cancelados',
            'frase' => $frase,
            'comparacion' => self::comparacion($u, $p['u'], $periodo['previo_etiqueta'], false, 'menos'),
            'detalle_titulo' => $detalle !== [] ? 'Los más cancelados' : null,
            'detalle' => $detalle,
            'enlace' => ['href' => '/admin/tickets', 'texto' => 'Ver tickets'],
        ];
    }

    /**
     * Lo que salió del almacén por ventas, neto de cancelaciones.
     *
     * Son los movimientos que dejó Inventario::aplicarVenta() al mandar cada
     * comanda: la receta ya explotada —subrecetas y opciones incluidas— por
     * explotarReceta(), en ese momento. No se vuelve a explotar aquí, y es a
     * propósito: una receta que cambie hoy no reescribe lo que salió ayer, y
     * el consumo tiene que cuadrar con la existencia, que se movió con esos
     * mismos números. Es la misma cuenta del «Consumo 7 días» de la ficha de
     * existencia. Por eso el periodo va por la fecha del movimiento (la
     * comanda), no por la del cobro.
     */
    private static function consumoDe(int $ingredienteId, array $periodo): array
    {
        $ing = self::fila("SELECT nombre, unidad, stock FROM ingredientes WHERE id = {$ingredienteId} LIMIT 1");
        if ($ing === null) {
            return ['tipo' => 'sin_resultado', 'titulo' => 'Ingrediente no encontrado', 'frase' => 'Ya no existe en el inventario.'];
        }
        $nombre = (string) $ing['nombre'];
        $unidad = (string) $ing['unidad'];
        $stock = (float) $ing['stock'];
        $porVentas = "'venta', 'cancelacion'";

        $neto = static function (string $tipos, string $desde, string $hasta) use ($ingredienteId): float {
            $row = self::fila(
                "SELECT COALESCE(-SUM(cantidad), 0) AS c FROM movimientos_inventario
                  WHERE ingrediente_id = {$ingredienteId} AND tipo IN ({$tipos}) AND " . self::entreFechas('created_at', $desde, $hasta)
            );

            return max(0.0, (float) ($row['c'] ?? 0));
        };
        $consumo = $neto($porVentas, $periodo['desde'], $periodo['hasta']);
        $previo = $neto($porVentas, $periodo['previo_desde'], $periodo['previo_hasta']);
        $merma = $neto("'merma'", $periodo['desde'], $periodo['hasta']);
        $cuando = mb_strtolower($periodo['frase']);

        $detalle = [];
        if ($consumo > 0) {
            $filas = self::filas(
                "SELECT ti.nombre, -SUM(m.cantidad) AS c, MAX(p.id) AS producto_id
                   FROM movimientos_inventario m
                   JOIN ticket_items ti ON ti.id = m.ticket_item_id
                   LEFT JOIN productos p ON p.nombre = ti.nombre
                  WHERE m.ingrediente_id = {$ingredienteId} AND m.tipo IN ({$porVentas})
                    AND " . self::entreFechas('m.created_at', $periodo['desde'], $periodo['hasta']) . "
                  GROUP BY ti.nombre HAVING c > 0 ORDER BY c DESC, ti.nombre LIMIT 6"
            );
            foreach ($filas as $f) {
                $renglon = [
                    'etiqueta' => (string) $f['nombre'],
                    'valor' => round((float) $f['c'], 3),
                    'valor_texto' => self::cantidad((float) $f['c'], $unidad),
                ];
                if (!empty($f['producto_id'])) {
                    $renglon['consulta'] = ['intencion' => 'ventas_producto', 'id' => (int) $f['producto_id'], 'opcion' => null];
                }
                $detalle[] = $renglon;
            }
        }

        $frase = $consumo <= 0
            ? 'No salió ' . $nombre . ' del almacén por ventas ' . $cuando . '.'
            : ($consumo == 1.0 ? 'Se usó ' : 'Se usaron ') . self::cantidad($consumo, $unidad) . ' de ' . $nombre . ' en ventas ' . $cuando . '.';
        if ($merma > 0) {
            $frase .= ' Además, ' . self::cantidad($merma, $unidad) . ' de merma.';
        }
        $frase .= $stock > 0 ? ' Quedan ' . self::cantidad($stock, $unidad) . ' en almacén.' : ' En almacén está agotado.';
        [$cifra, $unidadMostrada] = self::cifraLegible($consumo, $unidad);

        return [
            'tipo' => 'consumo',
            'titulo' => $nombre,
            'cifra' => $cifra,
            'unidad' => $unidadMostrada . ' en ventas',
            'frase' => $frase,
            // Más consumo no es mejor ni peor: es más venta. Sin color.
            'comparacion' => self::comparacion($consumo, $previo, $periodo['previo_etiqueta'], false, 'neutro', static fn (float $v): string => self::cantidad($v, $unidad)),
            'detalle_titulo' => $detalle !== [] ? 'Por platillo' : null,
            'detalle' => $detalle,
            'enlace' => ['href' => '/admin/inventario', 'texto' => 'Abrir inventario'],
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

    private static function detectarPeriodo(string $norma): array
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

        return self::periodo('hoy');
    }

    /**
     * La clave de un periodo que llega de fuera: una de PERIODOS o «Nd»
     * (de 1 a 365 días). Cualquier otra cosa es null y decide quien llama.
     */
    public static function periodoDesde(?string $clave): ?array
    {
        if ($clave === null || $clave === '') {
            return null;
        }
        if (isset(self::PERIODOS[$clave])) {
            return self::periodo($clave);
        }
        if (preg_match('/^(\d{1,3})d$/', $clave, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 365) {
            return self::periodoDias((int) $m[1]);
        }

        return null;
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
            static fn (string $p): bool => $p !== '' && !in_array($p, self::VACIAS, true) && !in_array($p, self::CORTESIA, true)
        );
        // El sinónimo se busca también en singular: «capuchinos» → «cappuccino».
        $sin = array_map(static function (string $p): string {
            $singular = preg_replace('/(es|s)$/', '', $p) ?? $p;
            return self::SINONIMOS[$p] ?? self::SINONIMOS[$singular] ?? $p;
        }, $palabras);

        return trim(implode(' ', $sin));
    }

    /**
     * Platillos, categorías o ingredientes que se parecen al término, mejor
     * primero.
     *
     * Puntúa por palabra: exacta 1, una contiene a la otra 0.85, singular
     * contra plural 0.9, y si no, la distancia de edición relativa (para los
     * errores de dedo: «capuchino» → «cappuccino»). La nota final es el
     * promedio sobre las palabras del TÉRMINO, así que «licor 43» prefiere
     * «Licor 43» a «Licor de naranja». `$umbral` baja sólo para sugerir: lo
     * que se contesta sigue pidiendo 0.72.
     *
     * @return list<array{id: int, nombre: string, puntos: float, sobrantes: list<string>}>
     */
    private static function coincidencias(string $tabla, string $termino, float $umbral = 0.72): array
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
                if ($mejor >= $umbral) {
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
            if ($puntos >= $umbral) {
                $salida[] = ['id' => (int) $row['id'], 'nombre' => $nombre, 'puntos' => round($puntos, 3), 'sobrantes' => $sobrantes];
            }
        }
        $res->free();
        usort($salida, static fn ($a, $b) => $b['puntos'] <=> $a['puntos'] ?: strcmp($a['nombre'], $b['nombre']));

        return array_slice($salida, 0, 6);
    }

    /**
     * Si alguna palabra sobrante del término es una opción activa del platillo
     * («rojos» → «Roja», «almendra» → «Almendra»), devuelve su id y su nombre.
     * Se comparan raíces: sin -os/-as/-es/-s/-o/-a, para que el género y el
     * número de la frase no importen.
     *
     * @return array{id: int, nombre: string}|null
     */
    private static function opcionMencionada(int $productoId, array $sobrantes): ?array
    {
        if ($sobrantes === []) {
            return null;
        }
        $raiz = static fn (string $p): string => preg_replace('/(os|as|es|s|o|a)$/', '', $p) ?? $p;
        $mejor = null;
        $mejorPuntos = 0.0;
        foreach (self::opcionesDe($productoId) as $o) {
            $palabrasOpcion = explode(' ', self::normalizar($o['nombre']));
            foreach ($sobrantes as $s) {
                foreach ($palabrasOpcion as $po) {
                    $puntos = $raiz($s) !== '' && $raiz($s) === $raiz($po) ? 1.0 : self::parecido($s, $po);
                    if ($puntos > $mejorPuntos && $puntos >= 0.8) {
                        $mejorPuntos = $puntos;
                        $mejor = ['id' => $o['id'], 'nombre' => $o['nombre']];
                    }
                }
            }
        }

        return $mejor;
    }

    /**
     * Las opciones por las que se puede filtrar una venta: activas y no
     * predeterminadas. La predeterminada no viaja en el rótulo congelado
     * (`ticket_items.variante`), así que filtrar por ella daría siempre cero.
     *
     * @return list<array{id: int, grupo: string, nombre: string}>
     */
    private static function opcionesDe(int $productoId): array
    {
        $res = ActiveRecord::getDB()->query(
            "SELECT id, grupo, nombre FROM producto_variantes
              WHERE producto_id = {$productoId} AND activo = 1 AND predeterminada = 0
              ORDER BY orden_grupo, orden, id"
        );
        $salida = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $salida[] = ['id' => (int) $row['id'], 'grupo' => (string) $row['grupo'], 'nombre' => (string) $row['nombre']];
            }
            $res->free();
        }

        return $salida;
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

    /**
     * La otra lectura de lo mismo, ya como consulta: los siguientes parecidos
     * y la palabra leída como la otra cosa («leche» como ingrediente cuando
     * se preguntó por ventas, o al revés).
     */
    private static function alternativas(array $principal, array $otra, string $tipo): array
    {
        $alts = [];
        $intencion = $tipo === 'stock' ? 'stock' : 'ventas_producto';
        foreach (array_slice($principal, 1, 3) as $c) {
            $alts[] = [
                'nombre' => $c['nombre'],
                'tipo' => $tipo,
                'consulta' => ['intencion' => $intencion, 'id' => (int) $c['id'], 'opcion' => null],
            ];
        }
        if ($otra !== []) {
            $c = $otra[0];
            $alts[] = $tipo === 'stock'
                ? ['nombre' => $c['nombre'], 'tipo' => 'ventas', 'consulta' => ['intencion' => 'ventas_producto', 'id' => (int) $c['id'], 'opcion' => null]]
                : ['nombre' => $c['nombre'], 'tipo' => 'stock', 'consulta' => ['intencion' => 'stock', 'id' => (int) $c['id'], 'opcion' => null]];
        }

        return $alts;
    }

    // ── Formato ─────────────────────────────────────────────────

    /** Una fila o null. Sólo con SQL armado aquí: ids ya convertidos a int. */
    private static function fila(string $sql): ?array
    {
        $res = ActiveRecord::getDB()->query($sql);
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return $row ?: null;
    }

    /** Todas las filas, o []. El mismo cuidado que fila(). */
    private static function filas(string $sql): array
    {
        $res = ActiveRecord::getDB()->query($sql);
        if (!$res) {
            return [];
        }
        $salida = [];
        while ($row = $res->fetch_assoc()) {
            $salida[] = $row;
        }
        $res->free();

        return $salida;
    }

    /** «1 ticket», «351 tickets», «1,204 comensales». */
    private static function conteo(int $n, string $uno, string $varios): string
    {
        return number_format($n) . ' ' . ($n === 1 ? $uno : $varios);
    }

    private static function mayuscula(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }

    /**
     * El periodo sobre la fecha de la VENTA: el cobro, o la apertura si el
     * ticket no la tiene. Es el criterio de todo el panel.
     */
    private static function filtroFecha(string $desde, string $hasta): string
    {
        return self::entreFechas('COALESCE(t.hora_cierre, t.hora_apertura)', $desde, $hasta);
    }

    private static function entreFechas(string $columna, string $desde, string $hasta): string
    {
        // Las fechas salen de periodo() (DateTime → Y-m-d) y la columna la
        // escribe este archivo: no traen nada del usuario y se pueden
        // interpolar.
        return "{$columna} >= '{$desde} 00:00:00' AND {$columna} <= '{$hasta} 23:59:59'";
    }

    /**
     * La cifra contra la del periodo anterior de la misma duración.
     *
     * `tendencia` es la DIRECCIÓN (la flecha) y `lectura` lo que significa: más
     * cancelaciones o más platillos sin venta suben, y eso es malo. Por eso
     * `$sentido` dice qué es mejor —'mas', 'menos' o 'neutro' (más consumo de
     * un ingrediente no es ni bueno ni malo)— y el color sale de la lectura.
     * `$formato` escribe la diferencia cuando no es dinero ni un conteo («+1.2
     * l»).
     */
    private static function comparacion(float $actual, float $previo, string $contra, bool $dinero = false, string $sentido = 'mas', ?callable $formato = null): ?array
    {
        if ($actual == 0.0 && $previo == 0.0) {
            return null;
        }
        $dif = $actual - $previo;
        // «frente a el mismo tramo» → «frente al mismo tramo».
        $frente = str_starts_with($contra, 'el ') ? 'frente al ' . substr($contra, 3) : 'frente a ' . $contra;
        $igual = 'Igual que ' . $contra;
        $magnitud = $formato !== null
            ? $formato(abs($dif))
            : ($dinero ? self::dinero(abs($dif)) : (string) (int) round(abs($dif)));
        $texto = $dif == 0.0 ? $igual : ($dif > 0 ? '+' : '−') . $magnitud . ' ' . $frente;
        $tendencia = $dif > 0 ? 'sube' : ($dif < 0 ? 'baja' : 'igual');
        $lectura = $tendencia === 'igual' || $sentido === 'neutro'
            ? 'igual'
            : ((($tendencia === 'sube') === ($sentido === 'mas')) ? 'bien' : 'mal');

        return ['texto' => $texto, 'tendencia' => $tendencia, 'lectura' => $lectura];
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
