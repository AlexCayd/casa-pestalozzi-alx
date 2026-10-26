<?php

/**
 * Corte de caja del POS (GET /api/corte-caja): las cifras de una VENTANA de
 * tiempo —un turno o el día entero— y los turnos que la definen.
 *
 * Tres reglas:
 *
 *  · Un ticket cae en la ventana por el momento del COBRO,
 *    COALESCE(hora_cierre, hora_apertura), y sólo cuentan los cerrados: es el
 *    criterio de Finanzas, así que el día completo de aquí y el corte del día
 *    del panel dan lo mismo. El ranking y las áreas usan el mismo universo para
 *    que las cifras del modal cuadren entre sí.
 *  · La ventana es semiabierta, [desde, hasta): un ticket cobrado a las 15:00
 *    exactas es del turno que empieza a las 15:00, nunca de los dos.
 *  · Un turno cuyo fin es anterior o igual a su inicio cruza la medianoche
 *    (18:00–02:00) y pertenece al día en que EMPIEZA. Los turnos no pueden
 *    solaparse: si lo hicieran, la suma de los turnos ya no sería el día.
 *
 * Las fechas de la ventana salen de DateTimeImmutable (formato fijo), por eso
 * se interpolan en el SQL.
 */

namespace Services\Pos;

use Model\ActiveRecord;

final class CorteCaja
{
    public const MAX_TURNOS = 4;
    private const DIA = 1440;

    /**
     * Valida lo que llega del formulario de Configuración → POS. Una fila sin
     * nombre ni horas es un hueco libre y se ignora: así se borra un turno.
     *
     * @param array<int, mixed> $filas [['nombre' => '', 'inicio' => 'HH:MM', 'fin' => 'HH:MM'], ...]
     * @return array{turnos: list<array{nombre: string, inicio: string, fin: string}>, errores: list<string>}
     */
    public static function normalizarTurnos(array $filas): array
    {
        $turnos = [];
        $errores = [];
        foreach (array_slice(array_values($filas), 0, self::MAX_TURNOS) as $i => $fila) {
            $fila = is_array($fila) ? $fila : [];
            $nombre = trim((string) ($fila['nombre'] ?? ''));
            $inicio = trim((string) ($fila['inicio'] ?? ''));
            $fin = trim((string) ($fila['fin'] ?? ''));
            if ($nombre === '' && $inicio === '' && $fin === '') {
                continue;
            }

            $n = $i + 1;
            if ($nombre === '' || mb_strlen($nombre) > 40) {
                $errores[] = "Turno {$n}: el nombre es obligatorio y de 40 caracteres como máximo.";
                continue;
            }
            if (!self::esHora($inicio) || !self::esHora($fin)) {
                $errores[] = "«{$nombre}»: escribe la hora de inicio y de fin (HH:MM).";
                continue;
            }
            if ($inicio === $fin) {
                $errores[] = "«{$nombre}»: el inicio y el fin no pueden ser la misma hora.";
                continue;
            }
            foreach ($turnos as $previo) {
                if (mb_strtolower($previo['nombre']) === mb_strtolower($nombre)) {
                    $errores[] = "Hay dos turnos llamados «{$nombre}».";
                    continue 2;
                }
                if (self::seSolapan($previo, ['inicio' => $inicio, 'fin' => $fin])) {
                    $errores[] = "«{$nombre}» se cruza con «{$previo['nombre']}».";
                    continue 2;
                }
            }
            $turnos[] = ['nombre' => $nombre, 'inicio' => $inicio, 'fin' => $fin];
        }

        // En el selector del POS se leen en el orden del día.
        usort($turnos, static fn (array $a, array $b): int => strcmp($a['inicio'], $b['inicio']));

        return ['turnos' => $turnos, 'errores' => $errores];
    }

    /**
     * [desde, hasta) de un turno de $fecha, o del día entero si no hay turno.
     *
     * @param array{inicio: string, fin: string}|null $turno
     * @return array{desde: string, hasta: string}
     */
    public static function ventana(string $fecha, ?array $turno): array
    {
        $dia = new \DateTimeImmutable($fecha . ' 00:00:00');
        if ($turno === null) {
            return ['desde' => $dia->format('Y-m-d H:i:s'), 'hasta' => $dia->modify('+1 day')->format('Y-m-d H:i:s')];
        }

        $desde = new \DateTimeImmutable($fecha . ' ' . $turno['inicio'] . ':00');
        $hasta = new \DateTimeImmutable($fecha . ' ' . $turno['fin'] . ':00');
        if ($hasta <= $desde) {
            $hasta = $hasta->modify('+1 day');
        }

        return ['desde' => $desde->format('Y-m-d H:i:s'), 'hasta' => $hasta->format('Y-m-d H:i:s')];
    }

    /**
     * El turno en curso a $ahora y el día al que pertenece (a la 01:00, un
     * turno 18:00–02:00 es de AYER). Sin turno en curso, el día de hoy entero.
     *
     * @return array{indice: int|null, fecha: string}
     */
    public static function turnoVigente(array $turnos, \DateTimeImmutable $ahora): array
    {
        foreach ([$ahora, $ahora->modify('-1 day')] as $dia) {
            $fecha = $dia->format('Y-m-d');
            foreach ($turnos as $i => $turno) {
                $v = self::ventana($fecha, $turno);
                $momento = $ahora->format('Y-m-d H:i:s');
                if ($momento >= $v['desde'] && $momento < $v['hasta']) {
                    return ['indice' => $i, 'fecha' => $fecha];
                }
            }
        }

        return ['indice' => null, 'fecha' => $ahora->format('Y-m-d')];
    }

    /**
     * Ventas, propinas, métodos, los cinco más pedidos y las áreas de la
     * ventana. Mismas consultas que tenía el controlador, con el rango en
     * lugar de CURDATE().
     */
    public static function resumen(string $desde, string $hasta): array
    {
        $db = ActiveRecord::getDB();
        $enVentana = "t.estado = 'cerrado'
            AND COALESCE(t.hora_cierre, t.hora_apertura) >= '{$desde}'
            AND COALESCE(t.hora_cierre, t.hora_apertura) < '{$hasta}'";

        $numTickets = 0;
        $ventas = 0.0;
        $propinas = 0.0;
        $efectivo = 0.0;
        $tarjeta = 0.0;

        $res = $db->query(
            "SELECT t.metodo_pago, COALESCE(t.propina, 0) AS propina,
                    COALESCE((SELECT SUM(ti.precio * ti.cantidad)
                              FROM ticket_items ti
                              WHERE ti.ticket_id = t.id AND ti.estado <> 'cancelado'), 0) AS total
               FROM tickets t
              WHERE {$enVentana}"
        );
        while ($res && ($row = $res->fetch_assoc())) {
            $numTickets++;
            $total = (float) $row['total'];
            $propina = (float) $row['propina'];
            $ventas += $total;
            $propinas += $propina;
            // El dinero recibido (consumo + propina) se atribuye al método. Los
            // tickets divididos se detallan abajo con ticket_pagos.
            if ($row['metodo_pago'] === 'efectivo') {
                $efectivo += $total + $propina;
            } elseif ($row['metodo_pago'] === 'tarjeta') {
                $tarjeta += $total + $propina;
            }
        }

        $res = $db->query(
            "SELECT tp.metodo_pago, COALESCE(SUM(tp.monto), 0) AS monto
               FROM ticket_pagos tp
               JOIN tickets t ON t.id = tp.ticket_id
              WHERE {$enVentana} AND t.metodo_pago = 'dividido'
              GROUP BY tp.metodo_pago"
        );
        while ($res && ($row = $res->fetch_assoc())) {
            if ($row['metodo_pago'] === 'efectivo') {
                $efectivo += (float) $row['monto'];
            } elseif ($row['metodo_pago'] === 'tarjeta') {
                $tarjeta += (float) $row['monto'];
            }
        }

        // Cinco y no seis: se lee de un vistazo en la tablet y el sexto puesto
        // no cambia ninguna decisión del turno.
        $top = [];
        $res = $db->query(
            "SELECT ti.nombre, SUM(ti.cantidad) AS unidades, SUM(ti.precio * ti.cantidad) AS importe
               FROM ticket_items ti
               JOIN tickets t ON t.id = ti.ticket_id
              WHERE {$enVentana} AND ti.estado <> 'cancelado'
              GROUP BY ti.nombre
              ORDER BY unidades DESC, importe DESC
              LIMIT 5"
        );
        while ($res && ($row = $res->fetch_assoc())) {
            $top[] = ['nombre' => $row['nombre'], 'unidades' => (int) $row['unidades'], 'importe' => (float) $row['importe']];
        }

        $areas = [];
        $res = $db->query(
            "SELECT COALESCE(ap.nombre, 'Sin área') AS area, COALESCE(SUM(ti.precio * ti.cantidad), 0) AS importe
               FROM ticket_items ti
               JOIN tickets t ON t.id = ti.ticket_id
               LEFT JOIN areas_produccion ap ON ap.id = ti.area_id
              WHERE {$enVentana} AND ti.estado <> 'cancelado'
              GROUP BY ap.nombre
              ORDER BY importe DESC"
        );
        while ($res && ($row = $res->fetch_assoc())) {
            $areas[] = ['area' => $row['area'], 'importe' => (float) $row['importe']];
        }

        return [
            'resumen' => [
                'tickets' => $numTickets,
                'ventas' => round($ventas, 2),
                'propinas' => round($propinas, 2),
                'total' => round($ventas + $propinas, 2),
                'promedio' => round($numTickets > 0 ? $ventas / $numTickets : 0.0, 2),
            ],
            'metodos' => ['efectivo' => round($efectivo, 2), 'tarjeta' => round($tarjeta, 2)],
            'top' => $top,
            'areas' => $areas,
        ];
    }

    private static function esHora(string $valor): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $valor) === 1;
    }

    private static function minutos(string $hora): int
    {
        return (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
    }

    /** Sobre una línea de dos días: el turno de hoy contra el de hoy, ayer y mañana. */
    private static function seSolapan(array $a, array $b): bool
    {
        $intervalo = static function (array $t): array {
            $ini = self::minutos($t['inicio']);
            $fin = self::minutos($t['fin']);
            return [$ini, $fin <= $ini ? $fin + self::DIA : $fin];
        };
        [$a1, $a2] = $intervalo($a);
        [$b1, $b2] = $intervalo($b);
        foreach ([-self::DIA, 0, self::DIA] as $corrimiento) {
            if ($a1 < $b2 + $corrimiento && $b1 + $corrimiento < $a2) {
                return true;
            }
        }

        return false;
    }
}
