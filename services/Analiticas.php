<?php

/**
 * Analíticas diagnósticas de Nivel 1 (ver database/ANALITICAS.md §3).
 * A diferencia de los agregados descriptivos de AdminController::construirAnalytics
 * ("qué pasó"), estas explican POR QUÉ y sugieren QUÉ HACER:
 *
 *   §3.1 Ingeniería de menú  — matriz Kasavana-Smith (popularidad × margen).
 *   §3.2 RevPASH             — ingreso por asiento disponible por hora.
 *   §3.3 Varianza inventario — consumo teórico (recetas × ventas) vs. real.
 *   §3.4 Reglas de asociación — qué se pide junto con qué, corregido por lift.
 *
 * Todo se calcula al vuelo desde la BD, filtrado por el mismo rango de fechas
 * del dashboard. Cada método degrada a vacío si faltan datos; nunca lanza.
 */

namespace Services;

use Model\Ticket;

class Analiticas
{
    /** Ensambla las cuatro analíticas de Nivel 1 para la vista de análisis. */
    public static function nivel1(string $start, string $end): array
    {
        // Blindaje: solo se aceptan fechas YYYY-MM-DD (el llamador ya valida,
        // pero estas cadenas se interpolan en SQL, así que se re-verifican).
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($re, $start) || !preg_match($re, $end)) {
            $end = date('Y-m-d');
            $start = date('Y-m-d', strtotime('-29 days'));
        }

        return [
            'ingenieria'  => self::ingenieriaMenu($start, $end),
            'revpash'     => self::revpash($start, $end),
            'varianza'    => self::varianzaInventario($start, $end),
            'asociacion'  => self::reglasAsociacion($start, $end),
        ];
    }

    /**
     * 3.1 — Ingeniería de menú (clasificación Kasavana-Smith).
     * Cruza popularidad (unidades vendidas, corte al 70 % del promedio de su
     * categoría) con margen de contribución unitario real (precio − costo de
     * receta, corte en el margen promedio ponderado del menú).
     */
    private static function ingenieriaMenu(string $start, string $end): array
    {
        $vacio = ['items' => [], 'resumen' => ['estrella' => 0, 'vaca' => 0, 'incognita' => 0, 'perro' => 0],
            'cortes' => ['margen' => 0.0], 'scatter' => []];

        try {
            $db = Ticket::getDB();
            if (!$db) {
                return $vacio;
            }
            $fTk = "AND t.hora_apertura >= '{$start} 00:00:00' AND t.hora_apertura <= '{$end} 23:59:59'";

            $res = $db->query(
                "SELECT p.id, p.nombre, p.precio, p.tag, c.nombre AS categoria,
                        SUM(ti.cantidad) AS unidades
                   FROM ticket_items ti
                   JOIN tickets t    ON t.id = ti.ticket_id
                   JOIN productos p  ON p.nombre = ti.nombre
                   JOIN categorias c ON c.id = p.categoria_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}
                  GROUP BY p.id
                  HAVING unidades > 0"
            );
            if (!$res || $res->num_rows === 0) {
                return $vacio;
            }

            $prods = [];
            $totalUnidades = 0;
            $catUnidades = [];   // categoria => [suma_unidades, num_productos]
            while ($r = $res->fetch_assoc()) {
                $id = (int) $r['id'];
                $unidades = (int) $r['unidades'];
                $precio = (float) $r['precio'];
                $costo = Inventario::costoDeProducto($id);
                $margen = $precio - $costo;
                $prods[] = [
                    'nombre'    => $r['nombre'],
                    'categoria' => $r['categoria'],
                    'tag'       => $r['tag'],
                    'unidades'  => $unidades,
                    'precio'    => $precio,
                    'costo'     => $costo,
                    'margen'    => $margen,
                ];
                $totalUnidades += $unidades;
                $cat = $r['categoria'];
                if (!isset($catUnidades[$cat])) {
                    $catUnidades[$cat] = [0, 0];
                }
                $catUnidades[$cat][0] += $unidades;
                $catUnidades[$cat][1] += 1;
            }

            if ($totalUnidades === 0) {
                return $vacio;
            }

            // Corte de margen: margen de contribución promedio ponderado por
            // unidades vendidas (el "average CM" clásico de Kasavana-Smith).
            $sumaMargenPond = 0.0;
            foreach ($prods as $p) {
                $sumaMargenPond += $p['margen'] * $p['unidades'];
            }
            $corteMargen = $sumaMargenPond / $totalUnidades;

            $resumen = ['estrella' => 0, 'vaca' => 0, 'incognita' => 0, 'perro' => 0];
            $scatter = [];
            $items = [];
            foreach ($prods as $p) {
                $cat = $p['categoria'];
                $catProm = $catUnidades[$cat][1] > 0
                    ? $catUnidades[$cat][0] / $catUnidades[$cat][1]
                    : 0;
                $cortePopular = 0.70 * $catProm;      // regla estándar 70 %
                $popular = $p['unidades'] >= $cortePopular;
                $margenAlto = $p['margen'] >= $corteMargen;

                if ($popular && $margenAlto) {
                    $clase = 'estrella'; $label = 'Estrella';
                } elseif ($popular && !$margenAlto) {
                    $clase = 'vaca'; $label = 'Vaca';
                } elseif (!$popular && $margenAlto) {
                    $clase = 'incognita'; $label = 'Incógnita';
                } else {
                    $clase = 'perro'; $label = 'Perro';
                }
                $resumen[$clase]++;

                $popPct = $totalUnidades > 0 ? ($p['unidades'] / $totalUnidades) * 100 : 0;
                $margenPct = $p['precio'] > 0 ? ($p['margen'] / $p['precio']) * 100 : 0;

                $fila = [
                    'nombre'     => $p['nombre'],
                    'categoria'  => $cat,
                    'tag'        => $p['tag'],
                    'unidades'   => $p['unidades'],
                    'popularidad' => round($popPct, 1),
                    'precio'     => round($p['precio'], 2),
                    'costo'      => round($p['costo'], 2),
                    'margen'     => round($p['margen'], 2),
                    'margenPct'  => round($margenPct, 1),
                    'clase'      => $clase,
                    'claseLabel' => $label,
                ];
                $items[] = $fila;
                $scatter[] = [
                    'x'     => round($popPct, 2),
                    'y'     => round($p['margen'], 2),
                    'label' => $p['nombre'],
                    'clase' => $clase,
                ];
            }

            // Orden: estrellas primero, luego por unidades desc.
            $orden = ['estrella' => 0, 'vaca' => 1, 'incognita' => 2, 'perro' => 3];
            usort($items, function ($a, $b) use ($orden) {
                if ($a['clase'] !== $b['clase']) {
                    return $orden[$a['clase']] <=> $orden[$b['clase']];
                }
                return $b['unidades'] <=> $a['unidades'];
            });

            return [
                'items'   => $items,
                'resumen' => $resumen,
                'cortes'  => ['margen' => round($corteMargen, 2)],
                'scatter' => $scatter,
            ];
        } catch (\Throwable $e) {
            error_log('Analiticas::ingenieriaMenu - ' . $e->getMessage());
            return $vacio;
        }
    }

    /**
     * §3.2 — RevPASH (ingreso por asiento disponible por hora).
     *   RevPASH_hora = ingreso_de_la_hora / (asientos × días_operados_esa_hora)
     * Numerador: ticket_items de tickets cerrados agrupados por HORA de apertura.
     * Denominador: SUM(capacidad de mesas activas) × nº de días del rango en que
     * el restaurante estuvo abierto en esa hora (horarios_operacion menos
     * excepciones). Si no hay horarios definidos, se cae a los días observados.
     */
    private static function revpash(string $start, string $end): array
    {
        $vacio = ['labels' => [], 'values' => [], 'ingresos' => [], 'asientos' => 0, 'mejor' => null, 'peor' => null];

        try {
            $db = Ticket::getDB();
            if (!$db) {
                return $vacio;
            }
            $fTk = "AND t.hora_apertura >= '{$start} 00:00:00' AND t.hora_apertura <= '{$end} 23:59:59'";

            // Asientos disponibles (capacidad instalada).
            $asientos = 0;
            $res = $db->query("SELECT COALESCE(SUM(capacidad), 0) AS s FROM mesas WHERE activo = 1");
            if ($res && ($r = $res->fetch_assoc())) {
                $asientos = (int) $r['s'];
            }
            if ($asientos === 0) {
                return $vacio;
            }

            // Ingreso por hora del día (0-23).
            $ingresoHora = array_fill(0, 24, 0.0);
            $res = $db->query(
                "SELECT HOUR(t.hora_apertura) AS h, SUM(ti.precio * ti.cantidad) AS total
                   FROM ticket_items ti
                   JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}
                  GROUP BY h"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $ingresoHora[(int) $r['h']] = (float) $r['total'];
                }
            }

            // Días operados por hora, según horarios_operacion / excepciones.
            $availPorHora = self::diasOperadosPorHora($db, $start, $end);

            // Fallback: si no hay horarios definidos, usar los días con ventas.
            $diasObservados = 0;
            $res = $db->query(
                "SELECT COUNT(DISTINCT DATE(t.hora_apertura)) AS d
                   FROM tickets t WHERE t.estado = 'cerrado' {$fTk}"
            );
            if ($res && ($r = $res->fetch_assoc())) {
                $diasObservados = (int) $r['d'];
            }
            if ($diasObservados === 0) {
                $diasObservados = 1;
            }

            // Horas a mostrar: unión de las que tuvieron venta y las abiertas.
            $horas = [];
            for ($h = 0; $h < 24; $h++) {
                if ($ingresoHora[$h] > 0 || ($availPorHora[$h] ?? 0) > 0) {
                    $horas[] = $h;
                }
            }
            sort($horas);

            $labels = [];
            $values = [];
            $ingresos = [];
            $mejor = null;
            $peor = null;
            foreach ($horas as $h) {
                $dias = $availPorHora[$h] ?? 0;
                if ($dias <= 0) {
                    $dias = $diasObservados;   // vendió fuera del horario declarado
                }
                $revpash = $ingresoHora[$h] / ($asientos * $dias);
                $labels[] = sprintf('%02d:00', $h);
                $values[] = round($revpash, 2);
                $ingresos[] = round($ingresoHora[$h], 2);

                if ($ingresoHora[$h] > 0) {
                    if ($mejor === null || $revpash > $mejor['valor']) {
                        $mejor = ['hora' => sprintf('%02d:00', $h), 'valor' => round($revpash, 2)];
                    }
                    if ($peor === null || $revpash < $peor['valor']) {
                        $peor = ['hora' => sprintf('%02d:00', $h), 'valor' => round($revpash, 2)];
                    }
                }
            }

            return [
                'labels'   => $labels,
                'values'   => $values,
                'ingresos' => $ingresos,
                'asientos' => $asientos,
                'mejor'    => $mejor,
                'peor'     => $peor,
            ];
        } catch (\Throwable $e) {
            error_log('Analiticas::revpash - ' . $e->getMessage());
            return $vacio;
        }
    }

    /**
     * Para cada hora del día (0-23), cuenta cuántos días del rango el
     * restaurante estuvo abierto en esa franja. Combina el horario semanal
     * (horarios_operacion) con las excepciones por fecha (excepciones_operacion).
     * Devuelve [] si no hay horarios definidos (el llamador cae a otro método).
     */
    private static function diasOperadosPorHora($db, string $start, string $end): array
    {
        $semanal = [];   // dia_semana => [abierto, apHora, ciHora]
        $res = $db->query("SELECT dia_semana, abierto, hora_apertura, hora_cierre FROM horarios_operacion");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $semanal[(int) $r['dia_semana']] = [
                    (int) $r['abierto'],
                    $r['hora_apertura'] !== null ? (int) substr($r['hora_apertura'], 0, 2) : null,
                    $r['hora_cierre'] !== null ? (int) substr($r['hora_cierre'], 0, 2) : null,
                ];
            }
        }
        if (empty($semanal)) {
            return [];   // sin horarios: el llamador usa días observados
        }

        $excep = [];   // 'Y-m-d' => ['cerrado'|'especial', apHora, ciHora]
        $res = $db->query(
            "SELECT fecha, tipo, hora_apertura, hora_cierre
               FROM excepciones_operacion
              WHERE activo = 1 AND fecha >= '{$start}' AND fecha <= '{$end}'"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $excep[$r['fecha']] = [
                    $r['tipo'],
                    $r['hora_apertura'] !== null ? (int) substr($r['hora_apertura'], 0, 2) : null,
                    $r['hora_cierre'] !== null ? (int) substr($r['hora_cierre'], 0, 2) : null,
                ];
            }
        }

        $avail = array_fill(0, 24, 0);
        $cursor = strtotime($start);
        $limite = strtotime($end);
        while ($cursor <= $limite) {
            $fecha = date('Y-m-d', $cursor);
            $dow = (int) date('w', $cursor);   // 0=Dom … 6=Sáb
            $ap = null; $ci = null; $abierto = false;

            if (isset($excep[$fecha])) {
                [$tipo, $exAp, $exCi] = $excep[$fecha];
                if ($tipo === 'cerrado') {
                    $cursor = strtotime('+1 day', $cursor);
                    continue;
                }
                $abierto = true; $ap = $exAp; $ci = $exCi;
            } elseif (isset($semanal[$dow]) && $semanal[$dow][0] === 1) {
                $abierto = true; $ap = $semanal[$dow][1]; $ci = $semanal[$dow][2];
            }

            if ($abierto && $ap !== null && $ci !== null && $ci > $ap) {
                for ($h = $ap; $h < $ci; $h++) {
                    $avail[$h]++;
                }
            }
            $cursor = strtotime('+1 day', $cursor);
        }

        return $avail;
    }

    /**
     * §3.3 — Varianza de inventario: consumo teórico vs. real.
     *   Teórico: recetas explotadas × unidades vendidas en el rango.
     *   Real:    teórico + merma registrada (ajustes negativos de
     *            movimientos_inventario, que es donde se cuantifica la fuga que
     *            el descuento automático por receta no ve).
     * La varianza, valorizada con ingredientes.costo, es la merma en pesos.
     */
    private static function varianzaInventario(string $start, string $end): array
    {
        $vacio = ['items' => [], 'totalMerma' => 0.0, 'totalTeorico' => 0.0];

        try {
            $db = Ticket::getDB();
            if (!$db) {
                return $vacio;
            }
            $fTk = "AND t.hora_apertura >= '{$start} 00:00:00' AND t.hora_apertura <= '{$end} 23:59:59'";

            // Consumo teórico: por cada producto vendido, explotar su receta.
            $res = $db->query(
                "SELECT p.id, SUM(ti.cantidad) AS unidades
                   FROM ticket_items ti
                   JOIN tickets t   ON t.id = ti.ticket_id
                   JOIN productos p ON p.nombre = ti.nombre
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}
                  GROUP BY p.id"
            );
            $teorico = [];   // ingrediente_id => cantidad teórica consumida
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $unidades = (float) $r['unidades'];
                    $receta = Inventario::recetaDeProducto((int) $r['id']);
                    foreach ($receta as $ingId => $qtyUnit) {
                        $teorico[$ingId] = ($teorico[$ingId] ?? 0.0) + $qtyUnit * $unidades;
                    }
                }
            }

            // Merma registrada: ajustes negativos del inventario en el rango.
            $merma = [];   // ingrediente_id => cantidad de merma (positiva)
            $res = $db->query(
                "SELECT ingrediente_id, SUM(cantidad) AS s
                   FROM movimientos_inventario
                  WHERE tipo = 'ajuste'
                    AND created_at >= '{$start} 00:00:00' AND created_at <= '{$end} 23:59:59'
                  GROUP BY ingrediente_id"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $s = (float) $r['s'];
                    if ($s < 0) {
                        $merma[(int) $r['ingrediente_id']] = -$s;
                    }
                }
            }

            $ids = array_unique(array_merge(array_keys($teorico), array_keys($merma)));
            if (empty($ids)) {
                return $vacio;
            }

            // Datos del ingrediente (nombre, unidad, costo).
            $meta = [];
            $inClause = implode(',', array_map('intval', $ids));
            $res = $db->query("SELECT id, nombre, unidad, costo FROM ingredientes WHERE id IN ({$inClause})");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $meta[(int) $r['id']] = [
                        'nombre' => $r['nombre'],
                        'unidad' => $r['unidad'],
                        'costo'  => (float) $r['costo'],
                    ];
                }
            }

            $items = [];
            $totalMerma = 0.0;
            $totalTeorico = 0.0;
            foreach ($ids as $ingId) {
                $ingId = (int) $ingId;
                $info = $meta[$ingId] ?? ['nombre' => 'Ingrediente ' . $ingId, 'unidad' => '', 'costo' => 0.0];
                $teoQty = $teorico[$ingId] ?? 0.0;
                $merQty = $merma[$ingId] ?? 0.0;
                $teoValor = $teoQty * $info['costo'];
                $merValor = $merQty * $info['costo'];
                $totalTeorico += $teoValor;
                $totalMerma += $merValor;

                $items[] = [
                    'ingrediente' => $info['nombre'],
                    'unidad'      => $info['unidad'],
                    'teoricoQty'  => round($teoQty, 2),
                    'teoricoValor' => round($teoValor, 2),
                    'mermaQty'    => round($merQty, 2),
                    'mermaValor'  => round($merValor, 2),
                    'realValor'   => round($teoValor + $merValor, 2),
                ];
            }

            // Ranking descendente por $ de merma (lo accionable).
            usort($items, fn($a, $b) => $b['mermaValor'] <=> $a['mermaValor']);

            return [
                'items'        => $items,
                'totalMerma'   => round($totalMerma, 2),
                'totalTeorico' => round($totalTeorico, 2),
            ];
        } catch (\Throwable $e) {
            error_log('Analiticas::varianzaInventario - ' . $e->getMessage());
            return $vacio;
        }
    }

    /**
     * §3.4 — Reglas de asociación con lift.
     * Agrupa los ticket_items por ticket y calcula, para cada par de platillos:
     *   soporte(A,B)   = tickets con A y B / tickets totales
     *   confianza(A→B) = tickets con A y B / tickets con A
     *   lift(A,B)      = soporte(A,B) / (soporte(A) × soporte(B))
     * El lift corrige por popularidad base: un lift > 1 significa que el par se
     * pide junto MÁS de lo que el azar predice (afinidad real, no solo dos
     * platillos populares que coinciden por volumen).
     */
    private static function reglasAsociacion(string $start, string $end): array
    {
        $vacio = ['items' => [], 'tickets' => 0];

        try {
            $db = Ticket::getDB();
            if (!$db) {
                return $vacio;
            }
            $fTk = "AND t.hora_apertura >= '{$start} 00:00:00' AND t.hora_apertura <= '{$end} 23:59:59'";

            // Canastas: conjunto de platillos distintos por ticket cerrado.
            $res = $db->query(
                "SELECT ti.ticket_id, ti.nombre
                   FROM ticket_items ti
                   JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}
                  GROUP BY ti.ticket_id, ti.nombre"
            );
            if (!$res) {
                return $vacio;
            }

            $canastas = [];
            while ($r = $res->fetch_assoc()) {
                $canastas[$r['ticket_id']][] = $r['nombre'];
            }
            $total = count($canastas);
            if ($total < 2) {
                return ['items' => [], 'tickets' => $total];
            }

            $single = [];
            $pares = [];
            foreach ($canastas as $items) {
                $items = array_values(array_unique($items));
                foreach ($items as $n) {
                    $single[$n] = ($single[$n] ?? 0) + 1;
                }
                $count = count($items);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a = $items[$i];
                        $b = $items[$j];
                        if (strcmp($a, $b) > 0) {
                            [$a, $b] = [$b, $a];
                        }
                        $key = $a . "\x1f" . $b;
                        $pares[$key] = ($pares[$key] ?? 0) + 1;
                    }
                }
            }

            // Umbral mínimo de coocurrencia: escala con el volumen para no
            // reportar pares que coincidieron una sola vez por azar.
            $minCo = $total >= 40 ? 3 : 2;

            $filas = [];
            foreach ($pares as $key => $co) {
                if ($co < $minCo) {
                    continue;
                }
                [$a, $b] = explode("\x1f", $key);
                $ca = $single[$a] ?? 0;
                $cb = $single[$b] ?? 0;
                if ($ca === 0 || $cb === 0) {
                    continue;
                }
                $soporte = $co / $total;
                $lift = ($co * $total) / ($ca * $cb);
                if ($lift <= 1.0) {
                    continue;   // solo afinidades por encima del azar
                }
                // Confianza en la dirección más fuerte (menor base).
                $confianza = $co / min($ca, $cb);

                $filas[] = [
                    'a'           => $a,
                    'b'           => $b,
                    'coocurrencias' => $co,
                    'soportePct'  => round($soporte * 100, 1),
                    'confianzaPct' => round($confianza * 100, 1),
                    'lift'        => round($lift, 2),
                ];
            }

            usort($filas, fn($x, $y) => $y['lift'] <=> $x['lift']);
            $filas = array_slice($filas, 0, 12);

            return [
                'items'   => $filas,
                'tickets' => $total,
            ];
        } catch (\Throwable $e) {
            error_log('Analiticas::reglasAsociacion - ' . $e->getMessage());
            return $vacio;
        }
    }
}
