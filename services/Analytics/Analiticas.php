<?php

/**
 * Analíticas diagnósticas de Nivel 1 (ver database/ANALITICAS.md §3).
 * A diferencia de los agregados descriptivos de AdminController::construirAnalytics
 * ("qué pasó"), estas explican POR QUÉ y sugieren QUÉ HACER:
 *
 *   §3.1 Ingeniería de menú  — matriz Kasavana-Smith (popularidad × margen).
 *   §3.2 RevPASH             — mapa de calor hora × día del ingreso por asiento.
 *   §3.4 Reglas de asociación — qué se pide junto con qué, corregido por lift.
 *
 * La §3.3 (varianza de inventario) se retiró del panel: queda documentada en
 * ANALITICAS.md como propuesta, sin implementación.
 *
 * Todo se calcula al vuelo desde la BD, filtrado por el mismo rango de fechas
 * del dashboard. Cada método degrada a vacío si faltan datos; nunca lanza.
 */

namespace Services\Analytics;

use Model\Ticket;
use Services\Inventory\Inventario;
use Services\Security\ContextoNegocio;

class Analiticas
{
    /**
     * Columnas del mapa de calor del RevPASH (§3.2), en orden de semana
     * laboral. La clave es el día tal como lo numera PHP date('w') y MySQL
     * DAYOFWEEK()-1 (0 = domingo), por eso el domingo va al final.
     */
    private const DIAS_SEMANA = [
        1 => ['Lun', 'lunes'],
        2 => ['Mar', 'martes'],
        3 => ['Mié', 'miércoles'],
        4 => ['Jue', 'jueves'],
        5 => ['Vie', 'viernes'],
        6 => ['Sáb', 'sábado'],
        0 => ['Dom', 'domingo'],
    ];

    /** Ensambla las analíticas de Nivel 1 para la vista de análisis. */
    public static function nivel1(string $start, string $end): array
    {
        if (ContextoNegocio::nuevo()) {
            self::exigirAnalytics();
            if (ContextoNegocio::slug() !== 'restaurante') throw new \RuntimeException('Las analíticas solo están disponibles para Restaurante.');
        }
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
            'porHora'     => self::ventasPorHora($start, $end),
            'asociacion'  => self::reglasAsociacion($start, $end),
        ];
    }

    private const TOP_POR_HORA = 3;

    /**
     * §3.5 — Qué se vende a cada hora: TODAS las ventas cerradas de las fechas
     * elegidas, sin separar por canal ni por estación. Fecha y hora son las del
     * PEDIDO (ticket_items.created_at), no la apertura del ticket: el postre de
     * una mesa abierta a las 20:10 se vende a las 21:00, y el día elegido es el
     * día en que se pidió.
     *
     * Pública porque el panel la vuelve a pedir al cambiar las fechas
     * (GET /admin/api/analytics/venta-por-hora).
     */
    public static function ventasPorHora(string $start, string $end): array
    {
        // Las fechas se interpolan: sólo YYYY-MM-DD.
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($re, $start) || !preg_match($re, $end)) {
            return [];
        }
        try {
            if (ContextoNegocio::nuevo()) self::exigirAnalytics();
            $db = Ticket::getDB();
            if (!$db) {
                return [];
            }
            $res = $db->query(
                "SELECT HOUR(ti.created_at) AS h, ti.nombre, SUM(ti.cantidad) AS u
                   FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                    " . (ContextoNegocio::nuevo() ? "AND t.unidad_id=" . ContextoNegocio::unidad('restaurante') . " AND ti.unidad_id=t.unidad_id " : "") . "
                    AND ti.created_at >= '{$start} 00:00:00' AND ti.created_at <= '{$end} 23:59:59'
                  GROUP BY h, ti.nombre"
            );

            return self::agregarPorHora($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
        } catch (\Throwable $e) {
            error_log('Analiticas::ventasPorHora - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Una fila por hora con venta: el total de unidades y los tres platillos
     * que más salieron. Pura, para probarla sin base.
     *
     * @param list<array{h: int|string, nombre: string, u: int|string}> $hechos
     * @return list<array{hora: string, total: int, top: list<array{n: string, u: int}>}>
     */
    public static function agregarPorHora(array $hechos): array
    {
        $unidades = [];   // [h][nombre] => unidades
        foreach ($hechos as $f) {
            $unidades[(int) $f['h']][$f['nombre']] = ($unidades[(int) $f['h']][$f['nombre']] ?? 0) + (int) $f['u'];
        }
        ksort($unidades);

        $filas = [];
        foreach ($unidades as $h => $porPlatillo) {
            arsort($porPlatillo);
            $top = array_slice($porPlatillo, 0, self::TOP_POR_HORA, true);
            $filas[] = [
                'hora'  => sprintf('%02d:00', $h),
                'total' => array_sum($porPlatillo),
                'top'   => array_map(static fn ($n, $u) => ['n' => (string) $n, 'u' => $u], array_keys($top), $top),
            ];
        }

        return $filas;
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
            if (ContextoNegocio::nuevo()) return self::ingenieriaMenuRestaurante($start,$end,$vacio);
            $db = Ticket::getDB();
            if (!$db) {
                return $vacio;
            }
            $fTk = "AND t.hora_apertura >= '{$start} 00:00:00' AND t.hora_apertura <= '{$end} 23:59:59'";

            $res = $db->query(
                "SELECT p.id, p.nombre, p.precio, c.nombre AS categoria,
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

    private static function ingenieriaMenuRestaurante(string $start,string $end,array $vacio): array
    {
        self::exigirAnalytics();
        if (ContextoNegocio::slug()!=='restaurante') throw new \RuntimeException('Las analíticas solo están disponibles para Restaurante.');
        $db=Ticket::getDB(); $unidad=ContextoNegocio::unidad('restaurante');
        $s=$db->prepare("SELECT ti.oferta_id,ti.categoria_id,MAX(ti.nombre) nombre,MAX(ti.categoria) categoria,
             SUM(ti.cantidad) unidades,SUM(ti.precio*ti.cantidad)/NULLIF(SUM(ti.cantidad),0) precio,
             SUM(ti.costo_unitario*ti.cantidad)/NULLIF(SUM(ti.cantidad),0) costo,
             SUM(CASE WHEN ti.costo_unitario IS NULL THEN ti.cantidad ELSE 0 END) costo_desconocido
             FROM ticket_items ti JOIN tickets t ON t.id=ti.ticket_id AND t.unidad_id=ti.unidad_id
             WHERE ti.unidad_id=? AND t.estado='cerrado' AND ti.estado<>'cancelado'
             AND t.hora_apertura>=? AND t.hora_apertura<=? GROUP BY ti.oferta_id,ti.categoria_id HAVING unidades>0");
        $s->execute([$unidad,$start.' 00:00:00',$end.' 23:59:59']); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
        $prods=[];$total=0;$cats=[];
        foreach($rows as $r){if((float)$r['costo_desconocido']>0 || $r['costo']===null)continue;$u=(int)$r['unidades'];$precio=(float)$r['precio'];$costo=(float)$r['costo'];$cat=(string)($r['categoria']??'');$prods[]=['nombre'=>$r['nombre'],'categoria'=>$cat,'unidades'=>$u,'precio'=>$precio,'costo'=>$costo,'margen'=>$precio-$costo];$total+=$u;$cats[$cat]??=[0,0];$cats[$cat][0]+=$u;$cats[$cat][1]++;}
        if(!$total)return $vacio;
        $margenProm=array_sum(array_map(static fn($p)=>$p['margen']*$p['unidades'],$prods))/$total;
        $resumen=['estrella'=>0,'vaca'=>0,'incognita'=>0,'perro'=>0];$items=[];$scatter=[];
        foreach($prods as $p){$cat=$p['categoria'];$pop=$p['unidades']>=.7*($cats[$cat][0]/max(1,$cats[$cat][1]));$alto=$p['margen']>=$margenProm;$clase=$pop?($alto?'estrella':'vaca'):($alto?'incognita':'perro');$label=['estrella'=>'Estrella','vaca'=>'Vaca','incognita'=>'Incógnita','perro'=>'Perro'][$clase];$resumen[$clase]++;$pct=$p['unidades']/$total*100;$margenPct=$p['precio']>0?$p['margen']/$p['precio']*100:0;$items[]=['nombre'=>$p['nombre'],'categoria'=>$cat,'unidades'=>$p['unidades'],'popularidad'=>round($pct,1),'precio'=>round($p['precio'],2),'costo'=>round($p['costo'],2),'margen'=>round($p['margen'],2),'margenPct'=>round($margenPct,1),'clase'=>$clase,'claseLabel'=>$label];$scatter[]=['x'=>round($pct,2),'y'=>round($p['margen'],2),'label'=>$p['nombre'],'clase'=>$clase];}
        $orden=['estrella'=>0,'vaca'=>1,'incognita'=>2,'perro'=>3];usort($items,static fn($a,$b)=>$orden[$a['clase']]<=>$orden[$b['clase']] ?: $b['unidades']<=>$a['unidades']);
        return ['items'=>$items,'resumen'=>$resumen,'cortes'=>['margen'=>round($margenProm,2)],'scatter'=>$scatter];
    }

    private static function exigirAnalytics(): void
    {
        if (ContextoNegocio::nuevo()) {
            if (ContextoNegocio::slug()!=='restaurante') throw new \RuntimeException('Las analíticas solo están disponibles para Restaurante.');
            ContextoNegocio::exigir('analytics','ver','restaurante');
        }
    }

    /**
     * §3.2 — RevPASH como mapa de calor hora × día de la semana.
     *   RevPASH(día, hora) = ingreso(día, hora) / (asientos × días_operados(día, hora))
     *
     * Se reporta por celda (no por hora agregada) porque las dos preguntas de
     * operación —¿qué franjas rinden? ¿dónde hay huecos?— dependen del día:
     * un viernes a las 21:00 y un martes a las 21:00 son la misma hora y
     * negocios distintos. Agregar las siete columnas escondía justo eso.
     *
     * Numerador: ticket_items de tickets cerrados DEL SALÓN, agrupados por día
     * de la semana y HORA de apertura del ticket. Barras, Llevar y Caja quedan
     * fuera: no ocupan un asiento del comedor, y sumar su venta sobre los
     * asientos del salón inflaba el rendimiento por asiento justo en las franjas
     * de más barra (ver condicionTicketDeSalon()).
     * Denominador: asientos del salón (Mesa::capacidadReservableTotal(), la
     * misma cuenta que el «N/44» del POS) × nº de fechas del rango que cayeron en ese
     * día de la semana y en las que el restaurante estuvo abierto en esa franja
     * (horarios_operacion menos excepciones). Ese conteo normaliza que en 30
     * días haya cuatro viernes y cinco sábados.
     *
     * Filas: solo las horas con actividad o con horario abierto en algún día.
     * Columnas: los siete días, para que un día cerrado se lea como columna
     * vacía y no desaparezca del mapa.
     */
    private static function revpash(string $start, string $end): array
    {
        $vacio = ['horas' => [], 'dias' => [], 'celdas' => [], 'max' => 0.0, 'asientos' => 0,
            'mejor' => null, 'peor' => null, 'porDia' => [], 'promedioDia' => 0.0];

        try {
            if (ContextoNegocio::nuevo()) self::exigirAnalytics();
            $db = Ticket::getDB();
            if (!$db) {
                return $vacio;
            }
            // Rango y salón van juntos en las tres consultas: el respaldo de
            // días y horas operados también tiene que contar sólo venta de
            // salón, o un día con sólo barra pasaría por día operado.
            $fTk = "AND t.hora_apertura >= '{$start} 00:00:00' AND t.hora_apertura <= '{$end} 23:59:59'"
                . ' AND ' . self::condicionTicketDeSalon('t');
            if (ContextoNegocio::nuevo()) $fTk .= " AND t.unidad_id=" . ContextoNegocio::unidad('restaurante');

            // Asientos: los de las mesas del salón activas y reservables, la
            // misma definición que el «N/44» del POS. Antes era una constante
            // de 44 escrita a mano —la tercera copia de ese número—; ahora
            // desactivar una mesa baja el denominador sin tocar código. No hay
            // historial de capacidad: el cambio reescala también los periodos
            // pasados.
            if (ContextoNegocio::nuevo()) {
                $s=$db->prepare("SELECT COALESCE(SUM(capacidad),0) n FROM mesas WHERE unidad_id=? AND activo=1 AND reservable=1 AND tipo='mesa'");
                $s->execute([ContextoNegocio::unidad('restaurante')]); $asientos=(int)($s->get_result()->fetch_assoc()['n']??0);
            } else $asientos = \Model\Mesa::capacidadReservableTotal();
            if ($asientos <= 0) {
                return $vacio;
            }

            // Ingreso por (día de la semana, hora). DAYOFWEEK() da 1=domingo,
            // así que se resta 1 para alinearlo con date('w') y DIAS_SEMANA.
            $ingreso = [];   // [dow][hora] => float
            $res = $db->query(
                "SELECT (DAYOFWEEK(t.hora_apertura) - 1) AS dow,
                        HOUR(t.hora_apertura) AS h,
                        SUM(ti.precio * ti.cantidad) AS total
                   FROM ticket_items ti
                   JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk}
                  GROUP BY dow, h"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $ingreso[(int) $r['dow']][(int) $r['h']] = (float) $r['total'];
                }
            }

            // Días operados por (día de la semana, hora), según horarios y excepciones.
            $avail = self::diasOperadosPorDiaHora($db, $start, $end);

            // Fallback por si no hay horarios definidos o si hubo ventas fuera
            // del horario declarado: fechas distintas con venta en ese día.
            $observados = [];   // [dow] => int
            $res = $db->query(
                "SELECT (DAYOFWEEK(t.hora_apertura) - 1) AS dow,
                        COUNT(DISTINCT DATE(t.hora_apertura)) AS d
                   FROM tickets t
                  WHERE t.estado = 'cerrado' {$fTk}
                  GROUP BY dow"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $observados[(int) $r['dow']] = (int) $r['d'];
                }
            }

            // Horas realmente operadas por día de la semana: pares (fecha, hora)
            // distintos con venta. Es el fallback del resumen diario cuando un
            // día no tiene horario declarado; ahí no sirve contar fechas, hace
            // falta contar horas, que es lo que ese denominador multiplica.
            $horasConVenta = [];   // [dow] => int
            $res = $db->query(
                "SELECT dow, COUNT(*) AS h
                   FROM (SELECT (DAYOFWEEK(t.hora_apertura) - 1) AS dow,
                                DATE(t.hora_apertura) AS f,
                                HOUR(t.hora_apertura) AS hh
                           FROM tickets t
                          WHERE t.estado = 'cerrado' {$fTk}
                          GROUP BY dow, f, hh) AS franjas
                  GROUP BY dow"
            );
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $horasConVenta[(int) $r['dow']] = (int) $r['h'];
                }
            }

            // Filas visibles: horas con venta en algún día o con horario abierto.
            $horas = [];
            for ($h = 0; $h < 24; $h++) {
                $usada = false;
                foreach (array_keys(self::DIAS_SEMANA) as $dow) {
                    if (($ingreso[$dow][$h] ?? 0) > 0 || ($avail[$dow][$h] ?? 0) > 0) {
                        $usada = true;
                        break;
                    }
                }
                if ($usada) {
                    $horas[] = $h;
                }
            }

            $dias = [];
            foreach (self::DIAS_SEMANA as $dow => [$corto, $largo]) {
                $dias[] = ['dow' => $dow, 'corto' => $corto, 'largo' => $largo];
            }

            $celdas = [];
            $etiquetas = [];
            $max = 0.0;
            $extremos = ['abierto' => [], 'fuera' => []];

            foreach ($horas as $h) {
                $etiquetas[] = sprintf('%02d:00', $h);
                $fila = [];
                foreach (self::DIAS_SEMANA as $dow => [$corto, $largo]) {
                    $ing = $ingreso[$dow][$h] ?? 0.0;
                    $d = $avail[$dow][$h] ?? 0;
                    $abierto = $d > 0;

                    if ($d <= 0) {
                        // Vendió fuera del horario declarado: se normaliza con
                        // los días observados para no dividir entre cero. La
                        // celda queda marcada como cerrada (base distinta).
                        $d = $observados[$dow] ?? 0;
                    }

                    $valor = ($d > 0 && $ing > 0) ? $ing / ($asientos * $d) : 0.0;

                    $fila[] = [
                        'v'       => round($valor, 2),
                        'ing'     => round($ing, 2),
                        'dias'    => $d,
                        'abierto' => $abierto,
                    ];

                    if ($ing > 0 && $valor > 0) {
                        // El máximo sí incluye las celdas fuera de horario: es
                        // la referencia del color y ninguna celda debe salirse
                        // de la escala.
                        $max = max($max, $valor);

                        $ref = [
                            'hora'  => sprintf('%02d:00', $h),
                            'dia'   => $corto,
                            'largo' => $largo,
                            'valor' => round($valor, 2),
                        ];
                        // Mejor y peor franja son consejo operativo, así que
                        // solo compiten las celdas que el negocio realmente
                        // opera: las de fuera de horario dividen entre días con
                        // venta y no entre días abiertos, y recomendar una
                        // franja cerrada no accionaría nada. Se guardan aparte
                        // por si no hubiera ninguna celda abierta con venta.
                        $destino = $abierto ? 'abierto' : 'fuera';
                        if (!isset($extremos[$destino]['mejor']) || $valor > $extremos[$destino]['mejor']['valor']) {
                            $extremos[$destino]['mejor'] = $ref;
                        }
                        if (!isset($extremos[$destino]['peor']) || $valor < $extremos[$destino]['peor']['valor']) {
                            $extremos[$destino]['peor'] = $ref;
                        }
                    }
                }
                $celdas[] = $fila;
            }

            // Si no hubo ni una celda dentro de horario con venta, se reportan
            // las de fuera antes que dejar el pie de la gráfica en blanco.
            $ref = !empty($extremos['abierto']) ? $extremos['abierto'] : $extremos['fuera'];

            [$porDia, $promedioDia] = self::revpashPorDia(
                $asientos, $ingreso, $avail, $horasConVenta
            );

            return [
                'horas'       => $etiquetas,
                'dias'        => $dias,
                'celdas'      => $celdas,
                'max'         => round($max, 2),
                'asientos'    => $asientos,
                'mejor'       => $ref['mejor'] ?? null,
                'peor'        => $ref['peor'] ?? null,
                'porDia'      => $porDia,
                'promedioDia' => $promedioDia,
            ];
        } catch (\Throwable $e) {
            error_log('Analiticas::revpash - ' . $e->getMessage());
            return $vacio;
        }
    }

    /**
     * Un ticket es «de salón» si alguna de sus mesas es una mesa del comedor
     * (tipo 'mesa'). Uno sin filas en ticket_mesas también cuenta: son
     * históricos de antes de esa tabla y en su época sólo existía el salón.
     * Lo que queda fuera son los tickets que viven sólo en barras, Llevar o
     * Caja, que no ocupan asiento del comedor.
     *
     * El alias se interpola en SQL, así que sólo se aceptan identificadores.
     */
    private static function condicionTicketDeSalon(string $alias): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $alias)) {
            throw new \InvalidArgumentException('Alias de ticket inválido.');
        }

        $scope = ContextoNegocio::nuevo() ? " AND tm_salon.unidad_id={$alias}.unidad_id AND m_salon.unidad_id={$alias}.unidad_id" : '';
        $scopeSin = ContextoNegocio::nuevo() ? " AND tm_sin.unidad_id={$alias}.unidad_id" : '';
        return "(EXISTS (SELECT 1 FROM ticket_mesas tm_salon
                           JOIN mesas m_salon ON m_salon.id = tm_salon.mesa_id
                          WHERE tm_salon.ticket_id = {$alias}.id AND m_salon.tipo = 'mesa' {$scope})
                 OR NOT EXISTS (SELECT 1 FROM ticket_mesas tm_sin WHERE tm_sin.ticket_id = {$alias}.id {$scopeSin}))";
    }

    /**
     * §3.2 (bis) — RevPASH tomando el día completo como unidad:
     *   RevPASH(día) = ingreso(día) / (asientos × horas abiertas ese día)
     *
     * NO es el promedio de la columna del mapa de calor. El mapa divide cada
     * celda entre las veces que ESA franja estuvo abierta, así que una hora con
     * media hora de servicio pesa lo mismo que una hora llena; aquí el
     * denominador son todas las horas-asiento que el restaurante ofreció ese
     * día, de modo que las horas muertas también cuentan. Responde a otra
     * pregunta: no "¿qué franja rinde?" sino "¿cuánto rindió el comedor ese
     * día, de apertura a cierre?".
     *
     * Las horas se acumulan sobre todas las fechas del rango que cayeron en ese
     * día de la semana: cuatro lunes con turno de 8 horas dan 32.
     *
     * @param array $ingreso       [dow][hora] => ingreso
     * @param array $avail         [dow][hora] => fechas abiertas en esa franja
     * @param array $horasConVenta [dow] => franjas (fecha, hora) con venta
     * @return array{0: array, 1: float} filas por día y promedio del periodo
     */
    private static function revpashPorDia(
        int $asientos,
        array $ingreso,
        array $avail,
        array $horasConVenta
    ): array {
        $filas = [];
        $ingTotal = 0.0;
        $horasTotal = 0;

        foreach (self::DIAS_SEMANA as $dow => [$corto, $largo]) {
            $ing = array_sum($ingreso[$dow] ?? []);
            $horas = array_sum($avail[$dow] ?? []);
            $abierto = $horas > 0;

            if (!$abierto) {
                // Sin horario declarado (o vendiendo un día marcado como
                // cerrado): se normaliza con las horas que sí tuvieron venta,
                // igual que hace el mapa con los días observados.
                $horas = $horasConVenta[$dow] ?? 0;
            }

            $valor = ($horas > 0 && $ing > 0) ? $ing / ($asientos * $horas) : 0.0;

            $filas[] = [
                'dow'     => $dow,
                'corto'   => $corto,
                'largo'   => $largo,
                'valor'   => round($valor, 2),
                'ingreso' => round($ing, 2),
                'horas'   => $horas,
                'abierto' => $abierto,
            ];

            $ingTotal += $ing;
            $horasTotal += $horas;
        }

        // Promedio del periodo: se recalcula sobre los totales, no promediando
        // los siete días. Un domingo de dos horas no puede pesar lo mismo que
        // un sábado de doce.
        $promedio = ($horasTotal > 0 && $ingTotal > 0)
            ? $ingTotal / ($asientos * $horasTotal)
            : 0.0;

        return [$filas, round($promedio, 2)];
    }

    /**
     * Para cada par (día de la semana, hora), cuenta cuántas fechas del rango
     * cayeron en ese día con el restaurante abierto en esa franja. Combina el
     * horario semanal (horarios_operacion) con las excepciones por fecha
     * (excepciones_operacion).
     *
     * No asume nada sobre el horario configurado: acepta medias horas, turnos
     * que cruzan la medianoche y días marcados como abiertos sin horas
     * capturadas. Lo único que da por sentado es que la tabla es la verdad;
     * cuando un registro no alcanza para decidir, esa fecha no suma horas y el
     * llamador cae a los días con venta observados.
     *
     * Devuelve [] si no hay ningún horario definido.
     */
    private static function diasOperadosPorDiaHora($db, string $start, string $end): array
    {
        // Se conservan las horas tal cual las guarda MySQL ('HH:MM:SS'): el
        // recorte a la hora entera se hacía aquí y perdía los medios turnos.
        $semanal = [];   // dia_semana => [abierto, apertura, cierre]
        $whereUnidad = ContextoNegocio::nuevo() ? ' WHERE unidad_id=' . ContextoNegocio::unidad('restaurante') : '';
        $andUnidad = ContextoNegocio::nuevo() ? ' AND unidad_id=' . ContextoNegocio::unidad('restaurante') : '';
        $res = $db->query("SELECT dia_semana, abierto, hora_apertura, hora_cierre FROM horarios_operacion{$whereUnidad}");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $semanal[(int) $r['dia_semana']] = [
                    (int) $r['abierto'] === 1,
                    $r['hora_apertura'],
                    $r['hora_cierre'],
                ];
            }
        }
        if (empty($semanal)) {
            return [];   // sin horarios: el llamador usa días observados
        }

        $excep = [];   // 'Y-m-d' => [tipo, apertura, cierre]
        $res = $db->query(
            "SELECT fecha, tipo, hora_apertura, hora_cierre
               FROM excepciones_operacion
              WHERE activo = 1 {$andUnidad} AND fecha >= '{$start}' AND fecha <= '{$end}'"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $excep[$r['fecha']] = [$r['tipo'], $r['hora_apertura'], $r['hora_cierre']];
            }
        }

        $avail = [];
        for ($d = 0; $d < 7; $d++) {
            $avail[$d] = array_fill(0, 24, 0);
        }

        $cursor = strtotime($start);
        $limite = strtotime($end);
        while ($cursor <= $limite) {
            $fecha = date('Y-m-d', $cursor);
            $dow = (int) date('w', $cursor);   // 0=Dom … 6=Sáb
            $ap = null;
            $ci = null;

            $exc = $excep[$fecha] ?? null;
            if ($exc !== null && $exc[0] === 'cerrado') {
                $cursor = strtotime('+1 day', $cursor);
                continue;                       // ese día no abrió: cero horas
            }

            if ($exc !== null && $exc[1] !== null && $exc[2] !== null) {
                // Horario especial completo: manda sobre el semanal.
                $ap = $exc[1];
                $ci = $exc[2];
            } elseif (isset($semanal[$dow]) && $semanal[$dow][0]) {
                // Incluye el caso de una excepción 'horario_especial' sin horas
                // capturadas: el registro dice que hubo cambio pero no cuál, y
                // el horario normal del día es la mejor aproximación disponible
                // (antes esa fecha se descartaba entera).
                $ap = $semanal[$dow][1];
                $ci = $semanal[$dow][2];
            }

            foreach (self::horasAbiertas($ap, $ci) as $h) {
                $avail[$dow][$h]++;
            }

            $cursor = strtotime('+1 day', $cursor);
        }

        return $avail;
    }

    /**
     * Horas de reloj (0-23) que toca un turno de apertura a cierre.
     *
     * Cuenta una hora si el local estuvo abierto en ella aunque sea un minuto,
     * porque el RevPASH agrupa los tickets por HOUR(hora_apertura): con cierre
     * a las 22:30 hay ventas en la hora 22 y esa franja tiene que aparecer como
     * abierta. Al revés, abrir 08:30 sí cuenta la hora 8 completa; es la misma
     * aproximación que ya hace el numerador al redondear el ticket a su hora.
     *
     * Soporta turnos que cruzan la medianoche (18:00 → 02:00): antes la
     * condición `cierre > apertura` los descartaba en silencio y el día entero
     * quedaba como cerrado.
     *
     * Devuelve [] si el horario no es utilizable (nulo, ilegible o de duración
     * cero); esas fechas caen al conteo por días observados del llamador.
     */
    private static function horasAbiertas(?string $apertura, ?string $cierre): array
    {
        $ap = self::aMinutos($apertura);
        $ci = self::aMinutos($cierre);
        if ($ap === null || $ci === null || $ap === $ci) {
            return [];
        }

        // Si el cierre no es posterior a la apertura, el turno pasa de medianoche.
        $duracion = $ci > $ap ? $ci - $ap : (1440 - $ap) + $ci;

        $primera = intdiv($ap, 60);
        $horas = [];
        // Arranca en negativo por los minutos de la primera hora que ya habían
        // transcurrido al abrir (08:30 → -30), para no contar una hora de más.
        $cubierto = -($ap - $primera * 60);
        for ($i = 0; $cubierto < $duracion; $i++) {
            $horas[] = ($primera + $i) % 24;
            $cubierto += 60;
        }

        return $horas;
    }

    /** Convierte 'HH:MM[:SS]' a minutos desde medianoche; null si no es válida. */
    private static function aMinutos(?string $hora): ?int
    {
        if ($hora === null || !preg_match('/^(\d{1,2}):(\d{2})/', $hora, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }
        return $h * 60 + $min;
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
            if (ContextoNegocio::nuevo()) self::exigirAnalytics();
            $db = Ticket::getDB();
            if (!$db) {
                return $vacio;
            }
            $fTk = "AND t.hora_apertura >= '{$start} 00:00:00' AND t.hora_apertura <= '{$end} 23:59:59'";

            // Canastas: conjunto de platillos distintos por ticket cerrado.
            $res = $db->query(
                "SELECT ti.ticket_id, " . (ContextoNegocio::nuevo() ? 'ti.oferta_id item_id, MAX(ti.nombre) nombre' : 'ti.nombre, NULL item_id') . "
                   FROM ticket_items ti
                   JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$fTk} " . (ContextoNegocio::nuevo() ? " AND t.unidad_id=" . ContextoNegocio::unidad('restaurante') . " AND ti.unidad_id=t.unidad_id" : "") . "
                  GROUP BY ti.ticket_id, " . (ContextoNegocio::nuevo() ? 'ti.oferta_id' : 'ti.nombre')
            );
            if (!$res) {
                return $vacio;
            }

            $canastas = [];
            $nombresOferta = [];
            while ($r = $res->fetch_assoc()) {
                $itemId = ContextoNegocio::nuevo() ? (string)$r['item_id'] : (string)$r['nombre'];
                $canastas[$r['ticket_id']][] = $itemId;
                if (ContextoNegocio::nuevo()) $nombresOferta[$itemId] = (string)$r['nombre'];
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
                    'a'           => ContextoNegocio::nuevo() ? ($nombresOferta[$a] ?? $a) : $a,
                    'b'           => ContextoNegocio::nuevo() ? ($nombresOferta[$b] ?? $b) : $b,
                    'oferta_a'    => ContextoNegocio::nuevo() ? (int)$a : null,
                    'oferta_b'    => ContextoNegocio::nuevo() ? (int)$b : null,
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
