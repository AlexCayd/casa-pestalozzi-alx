<?php

/**
 * Histórico de tickets del panel (/admin/tickets): las cuatro cifras de la
 * franja de filtros y la página de la lista.
 *
 * Vive aquí y no en AdminController porque son cinco consultas con reglas que
 * no se deducen del SQL, y porque la lista y las cifras tienen que contar
 * EXACTAMENTE lo mismo: cada tarjeta es un filtro, y la cifra que enseña es la
 * promesa de cuántas filas va a dar al pulsarla. Si se calcularan en dos sitios
 * acabarían discrepando.
 *
 * Tres reglas:
 *
 *  · Un ticket cae en el periodo por COALESCE(hora_cierre, hora_apertura), que
 *    es como cuentan Finanzas, Analíticas y las consultas rápidas (el momento
 *    del cobro; los abiertos, por su apertura). La venta es la de Finanzas:
 *    tickets cerrados, sin renglones cancelados. Así el «Cerrados» de aquí y el
 *    ingreso de Finanzas dan la misma cifra para el mismo periodo.
 *  · «Abiertos» NO depende del periodo. Un ticket abierto no es un hecho del
 *    pasado sino una cuenta viva, y la que más importa encontrar es justo la que
 *    se quedó abierta hace dos semanas —un cobro olvidado—, que un periodo de
 *    30 días escondería. La tarjeta lo dice («ahora») y la lista también.
 *  · Las métricas van en su propia consulta, agregada: si se calcularan
 *    recorriendo las filas traídas describirían la página de 20, no el total
 *    (ver «Paginar» en CLAUDE.md).
 *
 * Las fechas llegan de RangoPeriodo, que ya las validó con regex, y por eso se
 * interpolan en el SQL.
 */

namespace Services\Pos;

use Model\ActiveRecord;
use Services\Shared\RangoPeriodo;

final class HistorialTickets
{
    public const POR_PAGINA = 20;

    /** Estados por los que se puede filtrar. '' es «todos». */
    public const ESTADOS = ['abierto', 'cerrado', 'cancelado'];

    /** Más puntos que esto en una mini-tendencia de 64 px no se distinguen. */
    private const PUNTOS_SERIE = 45;

    private const FECHA = 'COALESCE(t.hora_cierre, t.hora_apertura)';

    /** Normaliza el filtro de la URL: cualquier otro valor es «todos». */
    public static function estado($valor): string
    {
        $valor = is_string($valor) ? trim($valor) : '';

        return in_array($valor, self::ESTADOS, true) ? $valor : '';
    }

    /**
     * Las cifras de la franja. Todo lo del periodo trae su comparativo contra
     * el periodo anterior de la misma duración; «abiertos» trae, en vez de
     * eso, cuántos vienen de días anteriores y la antigüedad del más viejo.
     *
     * @param array<string, mixed> $rango Lo que devuelve RangoPeriodo::resolver().
     * @return array<string, array<string, mixed>>
     */
    public static function resumen(array $rango): array
    {
        $db = ActiveRecord::getDB();
        [$ini, $fin, $prevIni] = self::limites($rango);
        $fecha = self::FECHA;
        $dias = max(1, (int) ($rango['dias'] ?? RangoPeriodo::dias($rango['start'], $rango['end'])));

        $conteos = ['todos' => 0, 'cerrados' => 0, 'cancelados' => 0, 'todos_prev' => 0, 'cerrados_prev' => 0, 'cancelados_prev' => 0];
        $res = $db->query(
            "SELECT
                COALESCE(SUM(f >= '{$ini}'), 0) AS todos,
                COALESCE(SUM(f >= '{$ini}' AND estado = 'cerrado'), 0) AS cerrados,
                COALESCE(SUM(f >= '{$ini}' AND estado = 'cancelado'), 0) AS cancelados,
                COALESCE(SUM(f < '{$ini}'), 0) AS todos_prev,
                COALESCE(SUM(f < '{$ini}' AND estado = 'cerrado'), 0) AS cerrados_prev,
                COALESCE(SUM(f < '{$ini}' AND estado = 'cancelado'), 0) AS cancelados_prev
               FROM (SELECT t.estado, {$fecha} AS f
                       FROM tickets t
                      WHERE {$fecha} >= '{$prevIni}' AND {$fecha} < '{$fin}') x"
        );
        if ($res && ($fila = $res->fetch_assoc())) {
            foreach ($conteos as $clave => $_) {
                $conteos[$clave] = (int) $fila[$clave];
            }
        }

        $venta = 0.0;
        $ventaPrev = 0.0;
        $res = $db->query(
            "SELECT
                COALESCE(SUM(CASE WHEN {$fecha} >= '{$ini}' THEN ti.precio * ti.cantidad END), 0) AS venta,
                COALESCE(SUM(CASE WHEN {$fecha} < '{$ini}' THEN ti.precio * ti.cantidad END), 0) AS venta_prev
               FROM ticket_items ti
               JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                AND {$fecha} >= '{$prevIni}' AND {$fecha} < '{$fin}'"
        );
        if ($res && ($fila = $res->fetch_assoc())) {
            $venta = (float) $fila['venta'];
            $ventaPrev = (float) $fila['venta_prev'];
        }

        // Dos series diarias del periodo: cuántos tickets (la tendencia de
        // «Todos») y cuánto se vendió (la de «Cerrados»).
        $porDia = [];
        $res = $db->query(
            "SELECT DATE({$fecha}) AS dia, COUNT(*) AS n
               FROM tickets t
              WHERE {$fecha} >= '{$ini}' AND {$fecha} < '{$fin}'
              GROUP BY dia"
        );
        while ($res && ($fila = $res->fetch_assoc())) {
            $porDia[$fila['dia']] = (float) $fila['n'];
        }

        $ventaPorDia = [];
        $res = $db->query(
            "SELECT DATE({$fecha}) AS dia, SUM(ti.precio * ti.cantidad) AS v
               FROM ticket_items ti
               JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                AND {$fecha} >= '{$ini}' AND {$fecha} < '{$fin}'
              GROUP BY dia"
        );
        while ($res && ($fila = $res->fetch_assoc())) {
            $ventaPorDia[$fila['dia']] = (float) $fila['v'];
        }

        // Abiertos: sin fecha. CURDATE() y no date() de PHP: «de días
        // anteriores» se mide con el mismo reloj con el que se guardó la
        // apertura (la sesión de MySQL va en la hora del restaurante).
        // Dos medidas de antigüedad: minutos para lo de hoy («hace 3 h») y días
        // de CALENDARIO para lo anterior, que es como lo cuenta la fila de la
        // lista; con minutos/1440 un ticket del día 7 visto el 19 a mediodía
        // decía «hace 11 días» en la tarjeta y «hace 12» en su fila.
        $abiertos = ['n' => 0, 'atrasados' => 0, 'mas_antiguo' => null, 'minutos' => 0, 'dias' => 0];
        $res = $db->query(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(t.hora_apertura < CURDATE()), 0) AS atrasados,
                    MIN(t.hora_apertura) AS mas_antiguo,
                    COALESCE(TIMESTAMPDIFF(MINUTE, MIN(t.hora_apertura), NOW()), 0) AS minutos,
                    COALESCE(DATEDIFF(CURDATE(), DATE(MIN(t.hora_apertura))), 0) AS dias
               FROM tickets t
              WHERE t.estado = 'abierto'"
        );
        if ($res && ($fila = $res->fetch_assoc())) {
            $abiertos = [
                'n' => (int) $fila['n'],
                'atrasados' => (int) $fila['atrasados'],
                'mas_antiguo' => $fila['mas_antiguo'],
                'minutos' => max(0, (int) $fila['minutos']),
                'dias' => max(0, (int) $fila['dias']),
            ];
        }

        $cerrados = $conteos['cerrados'];

        return [
            'todos' => [
                'n' => $conteos['todos'],
                'prev' => $conteos['todos_prev'],
                'delta' => RangoPeriodo::delta((float) $conteos['todos'], (float) $conteos['todos_prev']),
                'porDia' => $conteos['todos'] / $dias,
                'serie' => self::serie($porDia, $rango['start'], $rango['end']),
            ],
            'abierto' => $abiertos,
            'cerrado' => [
                'n' => $cerrados,
                'venta' => $venta,
                'ventaPrev' => $ventaPrev,
                'delta' => RangoPeriodo::delta($venta, $ventaPrev),
                // Mismo cociente que Finanzas: venta entre tickets cerrados.
                'promedio' => $cerrados > 0 ? $venta / $cerrados : 0.0,
                'serie' => self::serie($ventaPorDia, $rango['start'], $rango['end']),
            ],
            'cancelado' => [
                'n' => $conteos['cancelados'],
                'prev' => $conteos['cancelados_prev'],
                'delta' => RangoPeriodo::delta((float) $conteos['cancelados'], (float) $conteos['cancelados_prev']),
                'tasa' => $conteos['todos'] > 0 ? $conteos['cancelados'] / $conteos['todos'] * 100 : 0.0,
            ],
        ];
    }

    /**
     * Cuántas filas da un filtro. Sale del resumen —no de otra consulta— para
     * que la cifra de la tarjeta y el total de la paginación no puedan
     * discrepar.
     *
     * @param array<string, array<string, mixed>> $resumen
     */
    public static function totalFiltrado(array $resumen, string $estado): int
    {
        switch ($estado) {
            case 'abierto':
                return (int) $resumen['abierto']['n'];
            case 'cerrado':
                return (int) $resumen['cerrado']['n'];
            case 'cancelado':
                return (int) $resumen['cancelado']['n'];
            default:
                return (int) $resumen['todos']['n'];
        }
    }

    /**
     * Una página de la lista, del más reciente al más antiguo por apertura.
     *
     * @param array<string, mixed> $rango
     * @return array<int, array<string, mixed>>
     */
    public static function pagina(array $rango, string $estado, int $pagina): array
    {
        $db = ActiveRecord::getDB();
        [$ini, $fin] = self::limites($rango);
        $fecha = self::FECHA;

        // El mismo predicado que cuenta el resumen, estado por estado.
        if ($estado === 'abierto') {
            $donde = "t.estado = 'abierto'";
        } else {
            $donde = "{$fecha} >= '{$ini}' AND {$fecha} < '{$fin}'";
            if ($estado !== '') {
                $donde .= " AND t.estado = '{$estado}'";
            }
        }

        $limite = self::POR_PAGINA;
        $offset = max(0, ($pagina - 1) * $limite);
        $filas = [];

        $res = $db->query(
            "SELECT t.id, t.nombre, t.comensales, t.estado, t.metodo_pago, t.propina,
                    t.hora_apertura, t.hora_cierre,
                    DATEDIFF(CURDATE(), DATE(t.hora_apertura)) AS dias_abierto,
                    m.numero AS mesa_numero, m.nombre AS mesa_nombre,
                    COALESCE(SUM(ti.precio * ti.cantidad), 0) AS total,
                    COUNT(ti.id) AS num_items
               FROM tickets t
               LEFT JOIN ticket_mesas tm ON tm.ticket_id = t.id AND tm.orden = 1
               LEFT JOIN mesas m ON m.id = tm.mesa_id
               LEFT JOIN ticket_items ti ON ti.ticket_id = t.id AND ti.estado <> 'cancelado'
              WHERE {$donde}
              GROUP BY t.id
              ORDER BY t.hora_apertura DESC, t.id DESC
              LIMIT {$limite} OFFSET {$offset}"
        );
        while ($res && ($fila = $res->fetch_assoc())) {
            $filas[] = $fila;
        }

        return $filas;
    }

    /**
     * [inicio del periodo, fin exclusivo del periodo, inicio del anterior] en
     * DATETIME. Fin EXCLUSIVO (el día siguiente a las 00:00) y no `<= 23:59:59`:
     * un cobro a las 23:59:59.5 no se cae del periodo.
     *
     * @param array<string, mixed> $rango
     * @return array{0: string, 1: string, 2: string}
     */
    private static function limites(array $rango): array
    {
        $inicio = (string) $rango['start'];
        $fin = date('Y-m-d', strtotime((string) $rango['end'] . ' +1 day'));
        $prevInicio = (string) ($rango['prevStart'] ?? $inicio);

        return [$inicio . ' 00:00:00', $fin . ' 00:00:00', $prevInicio . ' 00:00:00'];
    }

    /**
     * Serie diaria del periodo con los días vacíos en cero —un día sin tickets
     * es un dato, no un hueco que la línea deba saltarse— y agrupada en tramos
     * de varios días cuando el periodo es largo (un año son 365 puntos).
     *
     * @param array<string, float> $porDia
     * @return array<int, float>
     */
    private static function serie(array $porDia, string $desde, string $hasta): array
    {
        $dias = RangoPeriodo::dias($desde, $hasta);
        $tramo = max(1, (int) ceil($dias / self::PUNTOS_SERIE));
        $serie = [];

        for ($i = 0; $i < $dias; $i++) {
            // Por calendario y no sumando 86 400 s: un día de cambio de horario
            // no mide eso, y la serie se correría un día.
            $dia = date('Y-m-d', strtotime($desde . ' +' . $i . ' day'));
            $indice = intdiv($i, $tramo);
            $serie[$indice] = ($serie[$indice] ?? 0.0) + ($porDia[$dia] ?? 0.0);
        }

        return array_values($serie);
    }
}
