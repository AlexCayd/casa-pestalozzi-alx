<?php

/**
 * Sugerencias de venta sugerida del POS, generadas por el flujo de n8n.
 *
 * Al abrir la mesa el POS pide sugerencias -> se manda el contexto del ticket
 * al webhook de n8n -> n8n devuelve productos rankeados con su argumento de
 * venta -> se resuelven contra la tabla productos (fuente de verdad de precio
 * y area de produccion) y se pintan.
 *
 * Nada se persiste: la sugerencia se calcula al vuelo a partir de datos que ya
 * existen (tickets pasados del cliente y tickets con items parecidos). Para no
 * repetirse, el POS excluye lo que la mesa ya pidio mas lo que lleva visto en
 * la sesion del modal, que manda en cada llamada.
 */

namespace Services;

use Model\Ticket;

class Sugerencias
{
    /** Fases de la comida que detecta el flujo. */
    private const ETAPAS = ['ENTRADAS', 'DESARROLLO', 'CIERRE'];

    /** El flujo numera la etapa (1/2/3) ademas de nombrarla. */
    private const ETAPAS_NUM = [1 => 'ENTRADAS', 2 => 'DESARROLLO', 3 => 'CIERRE'];

    private const ETAPA_DEFAULT = 'DESARROLLO';

    /** Tope de sugerencias que se piden por ticket (la 1a se muestra, el resto es cola). */
    private const MAX_SUGERENCIAS = 5;

    /**
     * Relleno cuando el flujo ya no tiene platillos que proponer: el mesero
     * siempre debe tener algo que ofrecer. Estas categorias solo existen en
     * 'productos' — el motor de n8n parte de 'menu', que no las incluye, asi
     * que las bebidas nunca pueden salir de ahi.
     */
    private const CATEGORIAS_BEBIDA = ['Café & Bebidas', 'Jugos & Smoothies'];

    private const ARGUMENTO_BEBIDA = 'Para acompañar lo que ya está en la mesa.';

    private const TIMEOUT = 15;

    /** URL del webhook de n8n que genera las sugerencias (configurable por env). */
    public static function webhookUrl(): string
    {
        $url = $_ENV['N8N_WEBHOOK_SUGERENCIAS_URL'] ?? getenv('N8N_WEBHOOK_SUGERENCIAS_URL');
        return is_string($url) ? trim($url) : '';
    }

    /**
     * Sugerencias listas para pintar en el POS.
     *
     * @param int[] $vistos producto_id que el modal ya mostró en esta sesión.
     *                      Sin tabla de logs, esta es la unica memoria de lo ya
     *                      ofrecido: al reabrir la mesa se pierde y vuelve a
     *                      salir la misma tarjeta (salvo lo ya pedido, que se
     *                      excluye siempre desde ticket_items).
     * @return array ['etapa' => string, 'sugerencias' => array]
     * @throws \RuntimeException si el webhook no responde o el ticket no sirve.
     */
    public static function paraTicket(int $ticketId, array $vistos = []): array
    {
        $webhook = self::webhookUrl();
        if ($webhook === '') {
            throw new \RuntimeException('sin_config');
        }

        $contexto = self::contexto($ticketId, $vistos);
        if ($contexto === null) {
            throw new \RuntimeException('ticket_invalido');
        }

        $respuesta = self::llamarWebhook($webhook, $contexto);
        [$etapa, $crudas] = self::normalizar($respuesta);

        // n8n manda producto_id; el nombre, precio y area salen de la BD para
        // que la comanda se rutee al area correcta aunque el modelo alucine.
        $productos = self::resolverProductos(array_column($crudas, 'producto_id'));

        // Red de seguridad por si el flujo ignora 'excluir'.
        $excluidos = $contexto['excluir'];

        $sugerencias = [];
        foreach ($crudas as $cruda) {
            $producto = $productos[$cruda['producto_id']] ?? null;
            if ($producto === null || in_array($cruda['producto_id'], $excluidos, true)) {
                continue;
            }

            $sugerencias[] = [
                'producto_id' => (int) $producto['id'],
                'n'           => $producto['nombre'],
                'p'           => (float) $producto['precio'],
                'categoria'   => $producto['categoria'],
                'area_id'     => (int) $producto['area_id'],
                'area'        => $producto['area_slug'],
                'areaNombre'  => $producto['area_nombre'],
                'argumento'   => $cruda['argumento'],
                'score'       => $cruda['score'],
            ];
        }

        // Se acabaron los platillos prioritarios: antes de dejar al mesero sin
        // nada, se ofrecen bebidas.
        if (empty($sugerencias)) {
            $sugerencias = self::bebidas($ticketId, $excluidos);
        }

        return ['etapa' => $etapa, 'sugerencias' => $sugerencias];
    }

    /**
     * Bebidas que esta mesa todavia no tiene ni ha visto, ordenadas por lo que
     * mas se pide en la casa.
     *
     * @param int[] $excluidos
     */
    private static function bebidas(int $ticketId, array $excluidos): array
    {
        $categorias = "'" . implode("','", array_map(
            fn($c) => Ticket::escaparString($c),
            self::CATEGORIAS_BEBIDA
        )) . "'";

        $noRepetir = '';
        if (!empty($excluidos)) {
            $noRepetir = ' AND p.id NOT IN (' . implode(',', array_map('intval', $excluidos)) . ')';
        }

        $limite = self::MAX_SUGERENCIAS;
        $res = Ticket::ejecutarSQL(
            "SELECT p.id, p.nombre, p.categoria, p.precio, p.area_id,
                    ap.nombre AS area_nombre, ap.slug AS area_slug,
                    COUNT(hist.id) AS veces_pedida
               FROM productos p
               JOIN areas_produccion ap ON ap.id = p.area_id
               LEFT JOIN ticket_items hist
                      ON hist.nombre = p.nombre AND hist.estado <> 'cancelado'
              WHERE p.activo = 1
                AND p.categoria IN ({$categorias}){$noRepetir}
                AND p.nombre NOT IN (
                    SELECT nombre FROM ticket_items
                     WHERE ticket_id = {$ticketId} AND estado <> 'cancelado'
                )
              GROUP BY p.id, p.nombre, p.categoria, p.precio, p.area_id, ap.nombre, ap.slug
              ORDER BY veces_pedida DESC, p.precio DESC
              LIMIT {$limite}"
        );

        $bebidas = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $bebidas[] = [
                    'producto_id' => (int) $row['id'],
                    'n'           => $row['nombre'],
                    'p'           => (float) $row['precio'],
                    'categoria'   => $row['categoria'],
                    'area_id'     => (int) $row['area_id'],
                    'area'        => $row['area_slug'],
                    'areaNombre'  => $row['area_nombre'],
                    'argumento'   => self::ARGUMENTO_BEBIDA,
                    'score'       => 0.0,
                ];
            }
        }

        return $bebidas;
    }

    /**
     * Contexto del ticket abierto que se manda a n8n, o null si no aplica.
     *
     * @param int[] $vistos producto_id ya mostrados en la sesion del modal.
     */
    private static function contexto(int $ticketId, array $vistos = []): ?array
    {
        $res = Ticket::ejecutarSQL(
            "SELECT t.id, t.comensales, t.hora_apertura, m.nombre AS mesa
               FROM tickets t
               JOIN ticket_mesas tm ON tm.ticket_id = t.id AND tm.orden = 1
               JOIN mesas m ON m.id = tm.mesa_id
              WHERE t.id = {$ticketId} AND t.estado = 'abierto'
              LIMIT 1"
        );
        $ticket = $res ? $res->fetch_assoc() : null;
        if (!$ticket) {
            return null;
        }

        $items = [];
        $res = Ticket::ejecutarSQL(
            "SELECT nombre, categoria, precio, cantidad, comensal
               FROM ticket_items
              WHERE ticket_id = {$ticketId} AND estado <> 'cancelado'
              ORDER BY created_at ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $items[] = [
                    'nombre'    => $row['nombre'],
                    'categoria' => $row['categoria'],
                    'precio'    => (float) $row['precio'],
                    'cantidad'  => (int) $row['cantidad'],
                    'comensal'  => $row['comensal'] !== null ? (int) $row['comensal'] : null,
                ];
            }
        }

        $abierto = strtotime((string) $ticket['hora_apertura']);

        return [
            'ticket_id'       => (int) $ticket['id'],
            'mesa'            => $ticket['mesa'],
            'comensales'      => (int) $ticket['comensales'],
            'hora_apertura'   => $ticket['hora_apertura'],
            'minutos_abierto' => $abierto ? (int) floor((time() - $abierto) / 60) : 0,
            'max'             => self::MAX_SUGERENCIAS,
            'items'           => $items,
            // Lo que el flujo NO debe volver a proponer para esta mesa. El
            // motor tiene que rankear alrededor de estos ids: si los ignora,
            // repite lo mismo y el POS se queda sin nada que mostrar.
            'excluir'         => self::excluidos($ticketId, $vistos),
        ];
    }

    /**
     * producto_id que el flujo no debe repetir en esta mesa: lo que ya pidio
     * (se deduce de ticket_items uniendo por nombre con productos, igual que
     * hace el motor) mas lo que el modal ya mostro en esta sesion.
     *
     * @param int[] $vistos
     * @return int[]
     */
    private static function excluidos(int $ticketId, array $vistos = []): array
    {
        $ids = [];

        $res = Ticket::ejecutarSQL(
            "SELECT DISTINCT p.id
               FROM ticket_items ti
               JOIN productos p ON p.nombre = ti.nombre
              WHERE ti.ticket_id = {$ticketId} AND ti.estado <> 'cancelado'"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int) $row['id'];
            }
        }

        foreach ($vistos as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Llama al webhook con el contexto del ticket. A diferencia del flujo de
     * areas de mejora (que responde async), aqui n8n contesta las sugerencias
     * en el mismo request porque el mesero las necesita en pantalla.
     *
     * El webhook de n8n suele registrarse como GET: se intenta POST (que manda
     * el contexto completo) y, si el metodo no esta registrado, se reintenta
     * con GET llevando lo esencial en la query. En GET el flujo arma el resto
     * del contexto con sus propios joins a partir del ticket_id.
     *
     * @throws \RuntimeException si la llamada falla.
     */
    private static function llamarWebhook(string $url, array $contexto): array
    {
        [$cuerpo, $codigo, $error] = self::peticion($url, 'POST', $contexto);

        if ($codigo === 404 || $codigo === 405) {
            [$cuerpo, $codigo, $error] = self::peticion(self::urlConContexto($url, $contexto), 'GET', null);
        }

        if ($cuerpo === false || $error !== '') {
            throw new \RuntimeException('webhook_inalcanzable: ' . $error);
        }
        if ($codigo < 200 || $codigo >= 300) {
            throw new \RuntimeException('webhook_http_' . $codigo);
        }

        $data = json_decode((string) $cuerpo, true);
        if (!is_array($data)) {
            // Un 200 con cuerpo vacio suele ser un nodo del flujo que reventó:
            // n8n cierra el webhook sin pasar por "Respond to Webhook".
            $muestra = trim((string) $cuerpo);
            throw new \RuntimeException('webhook_json_invalido: ' . ($muestra === ''
                ? '(respuesta vacía; revisa las ejecuciones del flujo en n8n)'
                : mb_substr($muestra, 0, 200)));
        }

        return $data;
    }

    /** Ejecuta la peticion al webhook y devuelve [cuerpo, codigoHttp, textoError]. */
    private static function peticion(string $url, string $metodo, ?array $cuerpo): array
    {
        $ch = curl_init($url);
        $headers = [];

        // Mismo secreto compartido que valida el POST entrante de n8n.
        $secret = AreasMejora::secret();
        if ($secret !== '') {
            $headers[] = 'X-N8N-Secret: ' . $secret;
        }

        $opciones = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CUSTOMREQUEST  => $metodo,
        ];
        if ($cuerpo !== null) {
            $opciones[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        if (!empty($headers)) {
            $opciones[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $opciones);

        $respuesta = curl_exec($ch);
        $codigo    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);

        return [$respuesta, $codigo, $error];
    }

    /** URL con el contexto escalar en la query, para el webhook registrado como GET. */
    private static function urlConContexto(string $url, array $contexto): string
    {
        $query = http_build_query([
            'ticket_id'       => $contexto['ticket_id'],
            'mesa'            => $contexto['mesa'],
            'comensales'      => $contexto['comensales'],
            'minutos_abierto' => $contexto['minutos_abierto'],
            'max'             => $contexto['max'],
            'excluir'         => implode(',', $contexto['excluir']),
        ]);

        return $url . (strpos($url, '?') === false ? '?' : '&') . $query;
    }

    /**
     * Normaliza la respuesta de n8n aceptando las formas y alias con los que
     * suele llegar el JSON del modelo.
     *
     * @return array [etapa, [ ['producto_id' => int, 'score' => float, 'argumento' => ?string] ]]
     */
    private static function normalizar(array $respuesta): array
    {
        // La etapa viaja junto a las sugerencias, dentro del mismo envoltorio.
        $payload = self::desenvolver($respuesta);

        // etapa_nombre antes que etapa: el flujo manda la etapa como numero.
        $llaves = ['etapa_comida', 'etapa_nombre', 'etapa', 'fase'];
        $etapa  = self::primerValor($payload, $llaves);
        if ($etapa === '') {
            $etapa = self::primerValor($respuesta, $llaves);
        }
        $etapa = strtoupper(trim($etapa));
        if (ctype_digit($etapa) && isset(self::ETAPAS_NUM[(int) $etapa])) {
            $etapa = self::ETAPAS_NUM[(int) $etapa];
        }
        if (!in_array($etapa, self::ETAPAS, true)) {
            $etapa = self::ETAPA_DEFAULT;
        }

        $limpias = [];
        foreach (self::listaSugerencias($payload) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productoId = (int) self::primerValor($item, ['producto_id', 'id_producto', 'id']);
            if ($productoId <= 0 || isset($limpias[$productoId])) {
                continue;
            }

            $argumento = trim((string) self::primerValor($item, ['argumento_mesero', 'argumento', 'texto', 'mensaje']));

            $limpias[$productoId] = [
                'producto_id' => $productoId,
                'score'       => round((float) self::primerValor($item, ['score_calculado', 'score', 'puntuacion', 'puntaje_algoritmico', 'puntaje']), 2),
                'argumento'   => $argumento !== '' ? mb_substr($argumento, 0, 255) : null,
            ];

            if (count($limpias) >= self::MAX_SUGERENCIAS) {
                break;
            }
        }

        return [$etapa, array_values($limpias)];
    }

    /**
     * Baja por los envoltorios con los que n8n devuelve el JSON del modelo
     * ("data"/"text"/"output" como texto o ya decodificado, o [{ json: {...} }])
     * hasta el objeto que trae la etapa y las sugerencias juntas.
     */
    private static function desenvolver(array $payload): array
    {
        // Los datos ya estan en este nivel
        if (isset($payload['sugerencias']) || isset($payload['suggestions'])
            || isset($payload['sugerencia_principal'])) {
            return $payload;
        }

        foreach (['data', 'text', 'output'] as $clave) {
            if (!isset($payload[$clave])) {
                continue;
            }
            $valor = $payload[$clave];
            if (is_string($valor)) {
                $valor = json_decode($valor, true);
            }
            if (is_array($valor)) {
                return self::desenvolver($valor);
            }
        }

        // n8n suele mandar [{ json: {...} }] o [{ sugerencias: [...] }]
        if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
            if (isset($payload[0]['json']) && is_array($payload[0]['json'])) {
                return self::desenvolver($payload[0]['json']);
            }
            if (isset($payload[0]['sugerencias']) || isset($payload[0]['data'])) {
                return self::desenvolver($payload[0]);
            }
        }

        return $payload;
    }

    /** Arreglo de sugerencias dentro del payload ya desenvuelto. */
    private static function listaSugerencias(array $payload): array
    {
        foreach (['sugerencias', 'suggestions'] as $clave) {
            if (isset($payload[$clave]) && is_array($payload[$clave])) {
                return $payload[$clave];
            }
        }

        // El flujo separa la ganadora del resto: para el POS es una sola lista
        // rankeada (la primera se muestra, las demas son la cola del swap).
        if (isset($payload['sugerencia_principal']) && is_array($payload['sugerencia_principal'])) {
            $lista = [$payload['sugerencia_principal']];
            foreach (['opciones_respaldo', 'respaldos'] as $clave) {
                if (isset($payload[$clave]) && is_array($payload[$clave])) {
                    $lista = array_merge($lista, $payload[$clave]);
                }
            }
            return $lista;
        }

        // Arreglo plano de objetos: [ {..}, {..} ]
        if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
            return $payload;
        }

        return [];
    }

    /**
     * Productos activos indexados por id, con su area de produccion.
     *
     * @param int[] $ids
     */
    private static function resolverProductos(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $lista = implode(',', $ids);
        $res = Ticket::ejecutarSQL(
            "SELECT p.id, p.nombre, p.categoria, p.precio, p.area_id,
                    ap.nombre AS area_nombre, ap.slug AS area_slug
               FROM productos p
               JOIN areas_produccion ap ON ap.id = p.area_id
              WHERE p.id IN ({$lista}) AND p.activo = 1"
        );

        $productos = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $productos[(int) $row['id']] = $row;
            }
        }

        return $productos;
    }

    /** Devuelve el primer valor escalar presente entre varias llaves candidatas. */
    private static function primerValor(array $item, array $llaves): string
    {
        foreach ($llaves as $llave) {
            if (isset($item[$llave]) && is_scalar($item[$llave])) {
                return trim((string) $item[$llave]);
            }
        }
        return '';
    }
}
