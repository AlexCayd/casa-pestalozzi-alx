<?php

namespace Services\Finance;

/**
 * Diagnóstico financiero del periodo: el veredicto en palabras, el estado de
 * resultados en cascada y los signos vitales contra la industria.
 *
 * Existe porque la cabecera de Finanzas eran siete tarjetas con una cifra cada
 * una y el dueño tenía que hacer él solo la cuenta de qué significaban juntas.
 * Aquí se hace esa cuenta —por qué se ganó o se perdió, cuánto hay que vender
 * para no perder, qué indicador se sale de lo sano— y la vista sólo la pinta.
 *
 * No consulta nada: recibe los agregados que AdminFinanzasController ya
 * calculaba. Así se puede probar con números inventados
 * (scripts/tests/run-diagnostico-financiero.php), incluidos los raros: ingresos
 * en cero, periodo vacío, sin gastos fijos, margen bruto negativo.
 *
 * Los textos salen en «partes» ([['t' => 'texto', 'fuerte' => bool, 'href' =>
 * '#…']]) y no en HTML: la vista escapa cada trozo y sólo decide qué va en
 * negrita o enlazado. Ningún texto con números se arma en la vista.
 */
final class DiagnosticoFinanciero
{
    /*
     * Referencias de la industria. Son ORIENTATIVAS y la vista lo dice al pie,
     * con la fuente de cada una:
     *
     * - Costo de insumos 28-35 % de la venta: TouchBistro, «How to Calculate
     *   Food Cost Percentage» («The average food cost percentage for
     *   restaurants ranges from 28% to 35%»).
     * - Merma 5-15 % de lo comprado: LeanPath («5-15% of kitchen food purchases
     *   go to waste»).
     * - Margen neto 3-6 % en servicio completo casual, 5-10 % en alta cocina y
     *   6-10 % en comida rápida; nómina alta por encima del 33 % y costo primo
     *   (insumos + nómina) de 55 % o menos en los restaurantes con mejores
     *   márgenes: TouchBistro, «Restaurant Profit Margin Calculator».
     * - Días de inventario: no hay una cifra publicada que sirva para una casa
     *   con cocina, panadería y barra a la vez. Se usa la práctica habitual
     *   —perecederos para una semana, abarrotes y barra para dos o tres— y se
     *   presenta como tal, no como norma.
     */
    public const INSUMOS_REF = [28.0, 35.0];
    public const INSUMOS_ALTO = 40.0;
    public const MERMA_REF = [5.0, 15.0];
    public const MARGEN_NETO_REF = [3.0, 6.0];
    public const MARGEN_BRUTO_REF = [65.0, 72.0];
    public const NOMINA_ALTA = 33.0;
    public const COSTO_PRIMO_META = 55.0;
    public const DIAS_INVENTARIO_META = 14.0;
    public const DIAS_INVENTARIO_ALTO = 30.0;
    /** Por debajo de esta cobertura del punto de equilibrio ya no es «cerca». */
    public const EQUILIBRIO_CERCA = 85.0;
    /** Parte de lo vendido sin receta costeada a partir de la cual se avisa. */
    public const SIN_COSTO_AVISO = 5.0;

    private const ETIQUETA_GASTO = [
        'renta' => 'Renta',
        'servicios' => 'Servicios',
        'nomina' => 'Nómina',
        'insumos' => 'Insumos',
        'otros' => 'Otros',
    ];

    /**
     * Arma el diagnóstico completo.
     *
     * @param array<string, mixed> $d Agregados del periodo:
     *   ingresos, cogs, merma, gastos (prorrateados), gastosPorCategoria
     *   (prorrateados), dias, tickets, propinas, inventarioValor,
     *   ingresosSinCosto, comparando, periodoAnterior (rótulo) y previo
     *   [ingresos, cogs, merma, tickets].
     * @return array<string, mixed>
     */
    public static function armar(array $d): array
    {
        $n = self::normalizar($d);

        return [
            'veredicto' => self::veredicto($n),
            'cascada' => self::cascada($n),
            'signos' => self::signos($n),
        ];
    }

    // ── Formato ───────────────────────────────────────────────────

    /**
     * Dinero con el signo DELANTE del símbolo y con el menos tipográfico
     * (U+2212): «−$216,739», no «$-216,739». El guion corto se confunde con un
     * separador y, pegado al número, se pierde a cuerpo grande. Un valor que
     * redondea a cero sale sin signo: «−$0» no significa nada.
     */
    public static function dinero(float $valor, int $decimales = 0): string
    {
        $r = round($valor, $decimales);
        if ($r == 0.0) {
            $r = 0.0;
        }

        return ($r < 0 ? '−' : '') . '$' . number_format(abs($r), $decimales);
    }

    /** Diferencia de dinero, siempre con signo: «+$1,139» / «−$24,709». */
    public static function dineroConSigno(float $valor, int $decimales = 0): string
    {
        $r = round($valor, $decimales);
        if ($r == 0.0) {
            return '$' . number_format(0, $decimales);
        }

        return ($r > 0 ? '+' : '−') . '$' . number_format(abs($r), $decimales);
    }

    public static function porcentaje(float $valor, int $decimales = 1): string
    {
        $r = round($valor, $decimales);
        if ($r == 0.0) {
            $r = 0.0;
        }

        return ($r < 0 ? '−' : '') . number_format(abs($r), $decimales) . '%';
    }

    public static function porcentajeConSigno(float $valor, int $decimales = 1): string
    {
        $r = round($valor, $decimales);
        if ($r == 0.0) {
            return number_format(0, $decimales) . '%';
        }

        return ($r > 0 ? '+' : '−') . number_format(abs($r), $decimales) . '%';
    }

    // ── Entrada ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function normalizar(array $d): array
    {
        $previo = is_array($d['previo'] ?? null) ? $d['previo'] : [];
        $n = [
            'I' => max(0.0, (float) ($d['ingresos'] ?? 0)),
            'C' => max(0.0, (float) ($d['cogs'] ?? 0)),
            'M' => max(0.0, (float) ($d['merma'] ?? 0)),
            'G' => max(0.0, (float) ($d['gastos'] ?? 0)),
            'dias' => max(1, (int) ($d['dias'] ?? 1)),
            'tickets' => max(0, (int) ($d['tickets'] ?? 0)),
            'propinas' => max(0.0, (float) ($d['propinas'] ?? 0)),
            'inventario' => max(0.0, (float) ($d['inventarioValor'] ?? 0)),
            'sinCosto' => max(0.0, (float) ($d['ingresosSinCosto'] ?? 0)),
            'comparando' => !empty($d['comparando']),
            'etiquetaAnterior' => (string) ($d['periodoAnterior'] ?? ''),
            'gastosCat' => [],
            'pI' => max(0.0, (float) ($previo['ingresos'] ?? 0)),
            'pC' => max(0.0, (float) ($previo['cogs'] ?? 0)),
            'pM' => max(0.0, (float) ($previo['merma'] ?? 0)),
            'pTickets' => max(0, (int) ($previo['tickets'] ?? 0)),
        ];

        foreach ((array) ($d['gastosPorCategoria'] ?? []) as $cat => $monto) {
            if ((float) $monto > 0) {
                $n['gastosCat'][(string) $cat] = (float) $monto;
            }
        }
        arsort($n['gastosCat']);

        $n['B'] = $n['I'] - $n['C'] - $n['M'];
        $n['N'] = $n['B'] - $n['G'];
        // Los gastos fijos no guardan fecha: el periodo anterior repite el
        // mismo prorrateo, así que su utilidad neta se calcula con el mismo G.
        $n['pB'] = $n['pI'] - $n['pC'] - $n['pM'];
        $n['pN'] = $n['pB'] - $n['G'];
        // Hay con qué comparar sólo si el periodo anterior VENDIÓ. Contra un
        // periodo sin ventas cada renglón salía «+$31,832 contra el periodo
        // anterior», que es repetir la cifra con otro nombre; y su resultado
        // es sólo el gasto fijo, así que «perdiste menos» tampoco dice nada.
        $n['hayPrevio'] = $n['comparando'] && $n['pI'] > 0;

        $n['margenBruto'] = $n['I'] > 0 ? $n['B'] / $n['I'] * 100 : null;
        $n['margenNeto'] = $n['I'] > 0 ? $n['N'] / $n['I'] * 100 : null;
        $n['insumosPct'] = $n['I'] > 0 ? $n['C'] / $n['I'] * 100 : null;
        $n['sinCostoPct'] = $n['I'] > 0 ? min(100.0, $n['sinCosto'] / $n['I'] * 100) : null;
        $consumo = $n['C'] + $n['M'];
        $n['consumo'] = $consumo;
        $n['mermaPct'] = $consumo > 0 ? $n['M'] / $consumo * 100 : null;

        $n['ventaDia'] = $n['I'] / $n['dias'];
        $n['gastoDia'] = $n['G'] / $n['dias'];
        $n['ticketPromedio'] = $n['tickets'] > 0 ? $n['I'] / $n['tickets'] : null;
        $n['equilibrioDia'] = ($n['G'] > 0 && $n['margenBruto'] !== null && $n['margenBruto'] > 0)
            ? $n['gastoDia'] / ($n['margenBruto'] / 100)
            : null;
        $n['cobertura'] = ($n['equilibrioDia'] !== null && $n['equilibrioDia'] > 0)
            ? $n['ventaDia'] / $n['equilibrioDia'] * 100
            : null;
        $n['ticketsEquilibrio'] = ($n['equilibrioDia'] !== null && $n['ticketPromedio'] !== null && $n['ticketPromedio'] > 0)
            ? (int) ceil($n['equilibrioDia'] / $n['ticketPromedio'])
            : null;

        // Días de inventario: cuántos días durarían las existencias de HOY al
        // ritmo de consumo del periodo (vendido + mermado). El denominador es
        // puntual porque no hay histórico de stock; la vista lo advierte.
        $n['diasInventario'] = ($n['inventario'] > 0 && $consumo > 0)
            ? $n['inventario'] / ($consumo / $n['dias'])
            : null;
        $n['rotacion'] = ($n['inventario'] > 0) ? $consumo / $n['inventario'] : null;

        $n['nomina'] = (float) ($n['gastosCat']['nomina'] ?? 0);

        return $n;
    }

    // ── 1 · Veredicto ─────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function veredicto(array $n): array
    {
        $I = $n['I'];
        $G = $n['G'];
        $N = $n['N'];
        $B = $n['B'];

        $v = [
            'tono' => 'neutral',
            'estado' => '',
            'antes' => '',
            'cifra' => '',
            'despues' => '',
            'razon' => [],
            'palanca' => null,
            // Rótulo de la palanca: casi siempre es el punto de equilibrio,
            // pero sin ventas lo útil es decir qué revisar.
            'palancaRotulo' => 'Punto de equilibrio',
            'comparativo' => null,
        ];

        // Sin ventas ni consumo: no hay diagnóstico que hacer sobre la
        // operación, sólo decir que los fijos siguen corriendo.
        if ($I <= 0 && $n['tickets'] === 0) {
            $v['estado'] = 'Sin ventas';
            $v['antes'] = 'No hubo ventas cerradas en este periodo';
            if ($G > 0) {
                $v['tono'] = 'bad';
                $v['razon'] = [
                    self::t('Los gastos fijos siguen corriendo: '),
                    self::t(self::dinero($G), true),
                    self::t(' en ' . self::dias($n['dias']) . ', que hoy son pérdida.'),
                ];
            } else {
                $v['razon'] = [self::t('Tampoco hay gastos fijos registrados, así que todavía no hay nada que diagnosticar.')];
            }
            $v['palancaRotulo'] = 'Qué revisar';
            $v['palanca'] = [
                self::t('Si el restaurante abrió, revisa que el punto de venta esté cerrando los tickets: '),
                self::t('una mesa sin cerrar no cuenta como venta.'),
            ];
            $v['comparativo'] = self::comparativoTexto($n);

            return $v;
        }

        if (abs($N) < 0.5) {
            $v['estado'] = 'En equilibrio';
            $v['antes'] = 'Este periodo quedaste a mano';
            $v['razon'] = [self::t('Lo que vendiste alcanzó justo para insumos, merma y gastos fijos.')];
        } elseif ($N < 0) {
            $v['tono'] = 'bad';
            $v['estado'] = 'En pérdida';
            $v['antes'] = 'Este periodo perdiste';
            $v['cifra'] = self::dinero(abs($N));

            if ($B <= 0) {
                // El problema está antes de la renta: cada venta cuesta más de
                // lo que se cobra.
                $v['razon'] = [
                    self::t('Lo que costaron los insumos y la merma ('),
                    self::t(self::dinero($n['C'] + $n['M']), true),
                    self::t(') ya supera lo que vendiste ('),
                    self::t(self::dinero($I), true),
                    self::t($G > 0 ? '), antes de pagar renta y nómina.' : ').'),
                ];
            } elseif ($G > $I) {
                $veces = $G / $I;
                $v['razon'] = $veces >= 1.95
                    ? [
                        self::t('Los gastos fijos son '),
                        self::t(number_format($veces, 1) . ' veces', true),
                        self::t(' lo que vendiste: ' . self::dinero($G) . ' contra ' . self::dinero($I) . '.'),
                    ]
                    : [
                        self::t('Los gastos fijos ('),
                        self::t(self::dinero($G), true),
                        self::t(') superan todo lo que vendiste ('),
                        self::t(self::dinero($I), true),
                        self::t(').'),
                    ];
            } else {
                $v['razon'] = [
                    self::t('Los gastos fijos ('),
                    self::t(self::dinero($G), true),
                    self::t(') se comen toda la utilidad bruta ('),
                    self::t(self::dinero($B), true),
                    self::t(') y faltan ' . self::dinero(abs($N)) . '.'),
                ];
            }
        } else {
            $v['tono'] = 'good';
            $v['estado'] = 'Con ganancia';
            $v['antes'] = 'Este periodo ganaste';
            $v['cifra'] = self::dinero($N);
            $mn = (float) $n['margenNeto'];
            [$refMin, $refMax] = self::MARGEN_NETO_REF;
            $comparacion = $mn > $refMax
                ? 'más que los $' . (int) $refMin . ' a $' . (int) $refMax . ' que deja un restaurante típico de servicio completo.'
                : ($mn >= $refMin
                    ? 'dentro de los $' . (int) $refMin . ' a $' . (int) $refMax . ' que deja un restaurante típico de servicio completo.'
                    : 'menos que los $' . (int) $refMin . ' a $' . (int) $refMax . ' que deja un restaurante típico de servicio completo.');
            $v['razon'] = [
                self::t('De cada $100 que vendiste te quedaron '),
                self::t('$' . number_format($mn, 2), true),
                self::t(', ' . $comparacion),
            ];
        }

        $v['palanca'] = self::palancaTexto($n);
        $v['comparativo'] = self::comparativoTexto($n);

        return $v;
    }

    /** La frase del punto de equilibrio: lo que hay que vender para no perder. */
    private static function palancaTexto(array $n): ?array
    {
        if ($n['G'] <= 0) {
            return [
                self::t('No hay gastos fijos registrados, así que esta cuenta no descuenta renta ni nómina. '),
                self::t('Regístralos', false, '#gastos-fijos'),
                self::t(' para saber cuánto necesitas vender.'),
            ];
        }
        if ($n['margenBruto'] === null) {
            return null;
        }
        if ($n['margenBruto'] <= 0) {
            return [
                self::t('Con el margen bruto en negativo ninguna venta cubre los gastos fijos: '),
                self::t('vender más sólo agranda la pérdida', true),
                self::t(' hasta corregir costos o precios.'),
            ];
        }

        $eq = (float) $n['equilibrioDia'];
        $cob = (float) $n['cobertura'];
        if ($cob >= 100) {
            return [
                self::t('Vendes '),
                self::t(self::dinero($n['ventaDia']) . ' al día', true),
                self::t(', un ' . self::porcentaje($cob - 100, 0) . ' por encima de tu punto de equilibrio ('),
                self::t(self::dinero($eq) . ' al día'),
                self::t(').'),
            ];
        }

        $partes = [
            self::t('Para no perder necesitas vender '),
            self::t(self::dinero($eq) . ' al día', true),
        ];
        if ($n['ticketsEquilibrio'] !== null) {
            $partes[] = self::t(
                ' —unos ' . number_format($n['ticketsEquilibrio']) . ' tickets con tu ticket promedio de '
                . self::dinero((float) $n['ticketPromedio']) . '—'
            );
        }
        // «En promedio», no «hoy»: es la venta diaria del periodo, y junto a la
        // fila «Hoy» de los cortes se leía como la venta de este día.
        $partes[] = self::t('; en promedio vendes ');
        $partes[] = self::t(self::dinero($n['ventaDia']) . ' al día', true);
        $partes[] = self::t('.');

        return $partes;
    }

    /** Contra el periodo anterior: primero la venta, después el resultado. */
    private static function comparativoTexto(array $n): ?array
    {
        if (!$n['comparando']) {
            return null;
        }
        $donde = $n['etiquetaAnterior'] !== '' ? ' (' . self::minuscula($n['etiquetaAnterior']) . ')' : '';
        if (!$n['hayPrevio']) {
            return [self::t('El periodo anterior' . $donde . ' no tiene ventas con las que comparar.')];
        }

        $partes = [];
        $dv = ($n['I'] - $n['pI']) / $n['pI'] * 100;
        if (abs($dv) < 0.5) {
            $partes[] = self::t('Vendiste lo mismo que en el periodo anterior' . $donde);
        } else {
            $partes[] = self::t('Vendiste ');
            $partes[] = self::t(self::porcentaje(abs($dv), 0) . ($dv > 0 ? ' más' : ' menos'), true);
            $partes[] = self::t(' que en el periodo anterior' . $donde);
        }

        $N = $n['N'];
        $pN = $n['pN'];
        $dif = $N - $pN;
        if (abs($dif) < 0.5) {
            $partes[] = self::t(' y el resultado fue el mismo.');
        } elseif ($N < 0 && $pN < 0) {
            $partes[] = self::t(' y perdiste ');
            $partes[] = self::t(self::dinero(abs($dif)) . ($dif > 0 ? ' menos' : ' más'), true);
            $partes[] = self::t('.');
        } elseif ($N >= 0 && $pN >= 0) {
            $partes[] = self::t(' y ganaste ');
            $partes[] = self::t(self::dinero(abs($dif)) . ($dif > 0 ? ' más' : ' menos'), true);
            $partes[] = self::t('.');
        } elseif ($N >= 0) {
            $partes[] = self::t(': pasaste de perder ' . self::dinero(abs($pN)) . ' a ');
            $partes[] = self::t('ganar ' . self::dinero($N), true);
            $partes[] = self::t('.');
        } else {
            $partes[] = self::t(': pasaste de ganar ' . self::dinero($pN) . ' a ');
            $partes[] = self::t('perder ' . self::dinero(abs($N)), true);
            $partes[] = self::t('.');
        }

        return $partes;
    }

    // ── 2 · Cascada ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function cascada(array $n): array
    {
        $I = $n['I'];
        $C = $n['C'];
        $M = $n['M'];
        $B = $n['B'];
        $G = $n['G'];
        $N = $n['N'];

        // Tramo [desde, hasta] de cada barra, en pesos, y el acumulado del que
        // arranca (para el conector con la fila anterior).
        $tramos = [
            'ingresos' => [0.0, $I],
            'insumos' => [$I - $C, $I],
            'merma' => [$I - $C - $M, $I - $C],
            'bruta' => [0.0, $B],
            'fijos' => [$B - $G, $B],
            'neta' => [0.0, $N],
        ];
        $enlaces = ['ingresos' => null, 'insumos' => $I, 'merma' => $I - $C, 'bruta' => $B, 'fijos' => $B, 'neta' => $N];

        $pI = $n['pI'];
        $tramosPrev = [
            'ingresos' => [0.0, $pI],
            'insumos' => [$pI - $n['pC'], $pI],
            'merma' => [$pI - $n['pC'] - $n['pM'], $pI - $n['pC']],
            'bruta' => [0.0, $n['pB']],
            'fijos' => [$n['pB'] - $G, $n['pB']],
            'neta' => [0.0, $n['pN']],
        ];

        // El eje incluye el cero y, si hay con qué comparar, el periodo
        // anterior: así las barras fantasma caben sin cambiar de escala al
        // encender la comparación.
        $valores = [0.0];
        foreach ($tramos as $t) {
            $valores[] = $t[0];
            $valores[] = $t[1];
        }
        if ($n['hayPrevio']) {
            foreach ($tramosPrev as $t) {
                $valores[] = $t[0];
                $valores[] = $t[1];
            }
        }
        $lo = min($valores);
        $hi = max($valores);
        $rango = $hi - $lo;
        $pos = static fn (float $v): float => $rango > 0 ? round(($v - $lo) / $rango * 100, 3) : 0.0;

        $previos = [
            'ingresos' => $pI,
            'insumos' => $n['pC'],
            'merma' => $n['pM'],
            'bruta' => $n['pB'],
            'fijos' => $G,
            'neta' => $n['pN'],
        ];
        $montos = [
            'ingresos' => $I,
            'insumos' => -$C,
            'merma' => -$M,
            'bruta' => $B,
            'fijos' => -$G,
            'neta' => $N,
        ];
        $magnitudes = ['ingresos' => $I, 'insumos' => $C, 'merma' => $M, 'bruta' => $B, 'fijos' => $G, 'neta' => $N];
        // Subir es bueno salvo en los costos. Los fijos no se comparan.
        $subirEsBueno = ['ingresos' => true, 'insumos' => false, 'merma' => false, 'bruta' => true, 'fijos' => null, 'neta' => true];

        $definicion = self::definicionPasos($n);
        $pasos = [];
        $i = 0;
        foreach ($definicion as $clave => $def) {
            [$desde, $hasta] = $tramos[$clave];
            $ini = $pos(min($desde, $hasta));
            $fin = $pos(max($desde, $hasta));
            [$pDesde, $pHasta] = $tramosPrev[$clave];

            $tipo = $def['tipo'];
            $tono = $tipo;
            if ($tipo === 'total') {
                $tono = $magnitudes[$clave] < 0 ? 'negativo' : ($clave === 'neta' && $magnitudes[$clave] > 0 ? 'positivo' : 'total');
            }

            // De qué lado crece la barra al entrar: las restas, desde el
            // acumulado que se va comiendo; los totales, desde el cero.
            $origen = 'left';
            if ($tipo === 'resta' || ($tipo !== 'resta' && $hasta < $desde)) {
                $origen = 'right';
            }

            $cambio = null;
            if ($n['hayPrevio'] && $subirEsBueno[$clave] !== null) {
                $actual = $magnitudes[$clave];
                $antes = $previos[$clave];
                $dif = $actual - $antes;
                $pct = $antes > 0 ? $dif / $antes * 100 : null;
                // En la neta y en lo que cruza el cero, el porcentaje engaña
                // (de −$100 a −$50 es «−50%» y es una mejora): va en pesos.
                $usarPct = $pct !== null && $clave !== 'neta' && $actual >= 0;
                $cambio = [
                    'texto' => abs($dif) < 0.5
                        ? 'igual'
                        : ($usarPct ? self::porcentajeConSigno($pct) : self::dineroConSigno($dif)),
                    'sube' => $dif >= 0,
                    'bueno' => abs($dif) < 0.5 ? null : (($dif > 0) === $subirEsBueno[$clave]),
                    'antes' => self::dinero($clave === 'insumos' || $clave === 'merma' || $clave === 'fijos' ? -$antes : $antes, 2),
                    'diferencia' => self::dineroConSigno($dif, 2),
                    'pct' => $pct !== null ? self::porcentajeConSigno($pct) : null,
                ];
            }

            $pasos[] = $def + [
                'clave' => $clave,
                'indice' => $i,
                'monto' => $montos[$clave],
                'montoTexto' => self::dinero($montos[$clave], 2),
                'pctIngreso' => $I > 0
                    ? self::porcentaje(abs($magnitudes[$clave]) / $I * 100 * ($tipo === 'total' && $magnitudes[$clave] < 0 ? -1 : 1))
                    : null,
                // La segunda línea de cifras. En los ingresos, «100% del
                // ingreso» no dice nada: ahí va cuántos tickets lo hicieron.
                'secundario' => $clave === 'ingresos'
                    ? ($n['tickets'] === 1 ? '1 ticket' : number_format($n['tickets']) . ' tickets')
                    : ($I > 0
                        ? self::porcentaje(abs($magnitudes[$clave]) / $I * 100 * ($tipo === 'total' && $magnitudes[$clave] < 0 ? -1 : 1)) . ' del ingreso'
                        : '—'),
                'tono' => $tono,
                'ini' => $ini,
                'fin' => $fin,
                'vacio' => abs($magnitudes[$clave]) < 0.005,
                'origen' => $origen,
                'enlace' => $enlaces[$clave] === null ? null : $pos((float) $enlaces[$clave]),
                'iniPrev' => $pos(min($pDesde, $pHasta)),
                'finPrev' => $pos(max($pDesde, $pHasta)),
                'vacioPrev' => !$n['hayPrevio'] || abs($pHasta - $pDesde) < 0.005,
                'cambio' => $cambio,
            ];
            $i++;
        }

        return [
            'pasos' => $pasos,
            'cero' => $pos(0.0),
            'hayNegativo' => $lo < 0,
            'vacia' => $rango <= 0,
            'hayPrevio' => $n['hayPrevio'],
            'comparando' => $n['comparando'],
            'etiquetaAnterior' => $n['etiquetaAnterior'],
            'seleccion' => self::pasoPorDefecto($n),
        ];
    }

    /**
     * El paso que se abre explicado al entrar: la CAUSA del veredicto, no
     * siempre la última fila. Si se pierde por los fijos, se explican los
     * fijos; si se pierde antes de la renta, los insumos.
     */
    private static function pasoPorDefecto(array $n): string
    {
        if ($n['I'] <= 0) {
            return $n['G'] > 0 ? 'fijos' : 'ingresos';
        }
        if ($n['B'] <= 0) {
            return 'insumos';
        }
        if ($n['N'] < 0) {
            return 'fijos';
        }

        return 'neta';
    }

    /**
     * Textos de cada escalón: rótulo de la fila y la explicación del detalle.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function definicionPasos(array $n): array
    {
        $I = $n['I'];
        $hayVentas = $I > 0;
        $pct = static fn (float $v): string => self::porcentaje($v);

        // ── Ingresos
        $ingresos = [
            'rotulo' => 'Ingresos por ventas',
            'op' => '',
            'tipo' => 'base',
            'papel' => 'Punto de partida',
            'lead' => $n['tickets'] > 0
                ? 'Lo que cobraste por platillos y bebidas en ' . number_format($n['tickets']) . ' tickets cerrados.'
                : 'No hay tickets cerrados en este periodo.',
            'texto' => [self::t('Las propinas (' . self::dinero($n['propinas'], 2) . ') no cuentan aquí: son del personal.')],
            'hechos' => [
                ['Venta diaria', self::dinero($n['ventaDia'])],
                ['Ticket promedio', $n['ticketPromedio'] !== null ? self::dinero($n['ticketPromedio'], 2) : '—'],
                ['Tickets al día', number_format($n['tickets'] / $n['dias'], 1)],
                ['Días del periodo', (string) $n['dias']],
            ],
            'acciones' => [['Ver los tickets', '/admin/tickets']],
            'nodos' => ['Ingresos'],
        ];

        // ── Costo de insumos
        [$refMin, $refMax] = self::INSUMOS_REF;
        $insumosTexto = [];
        if ($hayVentas) {
            $p = (float) $n['insumosPct'];
            if ($n['sinCostoPct'] !== null && $n['sinCostoPct'] > self::SIN_COSTO_AVISO) {
                $insumosTexto[] = self::t('Ojo: ' . $pct($n['sinCostoPct']) . ' de lo vendido no tiene receta con costo y cuenta como $0, así que este porcentaje sale ');
                $insumosTexto[] = self::t('más bajo de lo real', true);
                $insumosTexto[] = self::t('.');
            } elseif ($p < $refMin) {
                $insumosTexto[] = self::t('Por debajo de la referencia: buena señal si las recetas están completas y los costos al día.');
            } elseif ($p <= $refMax) {
                $insumosTexto[] = self::t('Dentro de la referencia del sector.');
            } elseif ($p <= self::INSUMOS_ALTO) {
                $insumosTexto[] = self::t('Por encima de la referencia: revisa precio o porción de los platillos con menos margen.');
            } else {
                $insumosTexto[] = self::t('Muy por encima de la referencia: cada venta deja poco para pagar renta y nómina.');
            }
        }
        $insumos = [
            'rotulo' => 'Costo de insumos',
            'op' => '−',
            'tipo' => 'resta',
            'papel' => 'Se resta del ingreso',
            'lead' => $hayVentas
                ? 'Lo que costaron, según las recetas, los platillos que vendiste: de cada $100, $'
                    . number_format((float) $n['insumosPct'], 2) . '. La referencia del sector es de $'
                    . (int) $refMin . ' a $' . (int) $refMax . '.'
                : 'Lo que costaron, según las recetas, los platillos vendidos. Sin ventas, no hay costo que medir.',
            'texto' => $insumosTexto,
            'hechos' => [
                ['% del ingreso', $hayVentas ? $pct((float) $n['insumosPct']) : '—'],
                ['Referencia', (int) $refMin . '–' . (int) $refMax . '%'],
                ['Vendido sin costo', self::dinero($n['sinCosto'])],
            ],
            'acciones' => [['Revisar recetas', '/admin/recetas'], ['Margen por producto', '#rentabilidad']],
            'nodos' => ['Costo de insumos'],
        ];

        // ── Merma
        [$mMin, $mMax] = self::MERMA_REF;
        $mermaTexto = [];
        if ($n['M'] <= 0) {
            $mermaLead = 'No se registró merma en este periodo.';
            $mermaTexto[] = self::t('Si en cocina o barra se tira algo, anótalo en Inventario: lo que no se registra no cuenta.');
        } else {
            $mp = (float) $n['mermaPct'];
            $mermaLead = 'Insumos que se pagaron y se tiraron sin venderse: ' . $pct($mp)
                . ' de todo lo que salió del almacén. En cocinas profesionales se pierde de ' . (int) $mMin
                . '% a ' . (int) $mMax . '% de lo que se compra.';
            if ($mp < $mMin) {
                $mermaTexto[] = self::t('Por debajo de lo habitual. Sólo cuenta lo que se registra: si nadie la anota, la cifra se queda corta.');
            } elseif ($mp <= $mMax) {
                $mermaTexto[] = self::t('En la media del sector: todavía hay margen para recortarla.');
            } else {
                $mermaTexto[] = self::t('Por encima de lo habitual: revisa en Inventario qué ingredientes se tiran más.');
            }
        }
        $merma = [
            'rotulo' => 'Merma',
            'op' => '−',
            'tipo' => 'resta',
            'papel' => 'Se resta del ingreso',
            'lead' => $mermaLead,
            'texto' => $mermaTexto,
            'hechos' => [
                ['% de lo consumido', $n['mermaPct'] !== null ? $pct((float) $n['mermaPct']) : '—'],
                ['% del ingreso', $hayVentas ? $pct($n['M'] / $I * 100) : '—'],
                ['Referencia', (int) $mMin . '–' . (int) $mMax . '%'],
            ],
            'acciones' => [['Ver inventario', '/admin/inventario']],
            'nodos' => ['Merma'],
        ];

        // ── Utilidad bruta
        [$bMin, $bMax] = self::MARGEN_BRUTO_REF;
        $brutaTexto = [];
        if ($hayVentas) {
            $mb = (float) $n['margenBruto'];
            if ($mb <= 0) {
                $brutaTexto[] = self::t('Negativa: los insumos y la merma cuestan más de lo que se cobra. Ningún volumen de venta lo arregla; hay que tocar precios o costos.');
            } elseif ($mb < $bMin - 5) {
                $brutaTexto[] = self::t('Por debajo de lo habitual: queda poco de cada venta para pagar los gastos fijos.');
            } else {
                $brutaTexto[] = self::t($mb > $bMax ? 'Por encima de lo habitual.' : 'Dentro de lo habitual.');
            }
        }
        $bruta = [
            'rotulo' => 'Utilidad bruta',
            'op' => '=',
            'tipo' => 'total',
            'papel' => 'Resultado parcial',
            'lead' => $hayVentas
                ? 'Lo que queda de cada venta después de pagar lo que se usó para prepararla: de cada $100, $'
                    . number_format((float) $n['margenBruto'], 2) . '. Con los insumos en la referencia, lo habitual es de $'
                    . (int) $bMin . ' a $' . (int) $bMax . '.'
                : 'Lo que queda de las ventas tras pagar insumos y merma.',
            'texto' => $brutaTexto,
            'hechos' => [
                ['Margen bruto', $hayVentas ? $pct((float) $n['margenBruto']) : '—'],
                ['Referencia', (int) $bMin . '–' . (int) $bMax . '%'],
            ],
            'acciones' => [],
            'nodos' => ['Utilidad bruta'],
        ];

        // ── Gastos fijos
        $G = $n['G'];
        $fijosTexto = [];
        if ($G <= 0) {
            $fijosLead = 'No hay gastos fijos activos: la utilidad no descuenta renta, nómina ni servicios.';
        } else {
            $fijosLead = 'Renta, nómina, servicios y demás costos mensuales, prorrateados a '
                . self::dias($n['dias']) . '.'
                . ($hayVentas ? ' Equivalen al ' . $pct($G / $I * 100) . ' de lo que vendiste.' : '');
            if ($hayVentas && $n['nomina'] > 0) {
                $nomPct = $n['nomina'] / $I * 100;
                $primo = ($n['C'] + $n['nomina']) / $I * 100;
                $fijosTexto[] = self::t('La nómina sola es el ');
                $fijosTexto[] = self::t($pct($nomPct) . ' de lo vendido', true);
                $fijosTexto[] = self::t(
                    $nomPct > self::NOMINA_ALTA
                        ? ' (el sector la considera alta arriba del ' . (int) self::NOMINA_ALTA . '%). '
                        : ' (dentro de lo que el sector considera sano, hasta ' . (int) self::NOMINA_ALTA . '%). '
                );
                $fijosTexto[] = self::t('Insumos más nómina suman el ' . $pct($primo)
                    . '; los restaurantes con mejores márgenes lo mantienen en ' . (int) self::COSTO_PRIMO_META . '% o menos.');
            }
        }
        $reparto = [];
        foreach ($n['gastosCat'] as $cat => $monto) {
            $reparto[] = [
                'nombre' => self::ETIQUETA_GASTO[$cat] ?? ucfirst((string) $cat),
                'monto' => self::dinero($monto),
                'pct' => $G > 0 ? round($monto / $G * 100, 1) : 0.0,
                'pctTexto' => $G > 0 ? self::porcentaje($monto / $G * 100, 0) : '0%',
            ];
        }
        $fijos = [
            'rotulo' => 'Gastos fijos',
            'op' => '−',
            'tipo' => 'resta',
            'papel' => 'Se resta de la utilidad bruta',
            'lead' => $fijosLead,
            'texto' => $fijosTexto,
            'hechos' => [
                // Al mes es la cifra que el dueño reconoce —es la que captura—;
                // al día es la que hay que ganarle a la caja.
                ['Al mes', self::dinero($G * 30 / $n['dias'])],
                ['Al día', self::dinero($n['gastoDia'])],
                ['% del ingreso', $hayVentas ? $pct($G / $I * 100) : '—'],
            ],
            'reparto' => $reparto,
            'nota' => 'No se comparan entre periodos: los gastos fijos no guardan fecha y el periodo anterior repetiría el mismo prorrateo.',
            'acciones' => [['Editar gastos fijos', '#gastos-fijos']],
            'nodos' => array_map(static fn (array $r): string => $r['nombre'], $reparto),
        ];

        // ── Utilidad neta
        $N = $n['N'];
        [$nMin, $nMax] = self::MARGEN_NETO_REF;
        $netaTexto = [self::t(
            'Un restaurante de servicio completo suele quedarse con $' . (int) $nMin . ' a $' . (int) $nMax
            . ' de cada $100; uno de comida rápida o de alta cocina, con $5 a $10.'
        )];
        $neta = [
            'rotulo' => 'Utilidad neta',
            'op' => '=',
            'tipo' => 'total',
            'papel' => 'Resultado del periodo',
            'lead' => $N < 0
                ? 'Lo que faltó para cubrir insumos, merma y gastos fijos'
                    . ($hayVentas ? ': equivale al ' . $pct(abs((float) $n['margenNeto'])) . ' de lo vendido.' : '.')
                : 'Lo que queda después de pagar insumos, merma y gastos fijos'
                    . ($hayVentas ? ': de cada $100 vendidos, $' . number_format((float) $n['margenNeto'], 2) . '.' : '.'),
            'texto' => $netaTexto,
            'hechos' => [
                ['Margen neto', $hayVentas ? $pct((float) $n['margenNeto']) : '—'],
                ['Referencia', (int) $nMin . '–' . (int) $nMax . '%'],
            ],
            'nota' => 'Es una estimación: el costo de insumos sale de las recetas, no de las compras, y no incluye impuestos.',
            'acciones' => [],
            'nodos' => [$N < 0 ? 'Faltante' : 'Utilidad neta'],
        ];

        return [
            'ingresos' => $ingresos,
            'insumos' => $insumos,
            'merma' => $merma,
            'bruta' => $bruta,
            'fijos' => $fijos,
            'neta' => $neta,
        ];
    }

    // ── 3 · Signos vitales ────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function signos(array $n): array
    {
        return [
            self::signoEquilibrio($n),
            self::signoInsumos($n),
            self::signoMerma($n),
            self::signoInventario($n),
        ];
    }

    private static function signoEquilibrio(array $n): array
    {
        $s = [
            'clave' => 'equilibrio',
            'rotulo' => 'Punto de equilibrio',
            'valor' => '—',
            'unidad' => '',
            'estado' => 'neutral',
            'estadoTexto' => 'Sin datos',
            'frase' => [],
            'referencia' => 'Gastos fijos del día entre el margen bruto.',
            'medidor' => null,
            'accion' => ['Revisar gastos fijos', '#gastos-fijos'],
        ];

        if ($n['G'] <= 0) {
            $s['estadoTexto'] = 'Sin gastos fijos';
            $s['frase'] = [self::t('Registra renta, nómina y servicios para saber cuánto necesitas vender.')];

            return $s;
        }
        if ($n['margenBruto'] === null) {
            $s['frase'] = [self::t('Sin ventas no hay margen del que partir.')];

            return $s;
        }
        if ($n['margenBruto'] <= 0) {
            $s['estado'] = 'bad';
            $s['estadoTexto'] = 'Inalcanzable';
            $s['frase'] = [self::t('Con el margen bruto en negativo, ninguna venta alcanza a cubrir los gastos fijos.')];

            return $s;
        }

        $eq = (float) $n['equilibrioDia'];
        $vd = $n['ventaDia'];
        $cob = (float) $n['cobertura'];
        $s['valor'] = self::dinero($eq);
        $s['unidad'] = 'al día';
        if ($cob >= 100) {
            $s['estado'] = 'good';
            $s['estadoTexto'] = 'Cubierto';
            $s['frase'] = [
                self::t('Vendes '),
                self::t(self::dinero($vd) . ' al día', true),
                self::t(': ' . self::porcentaje($cob - 100, 0) . ' por encima de lo necesario.'),
            ];
        } else {
            $s['estado'] = $cob >= self::EQUILIBRIO_CERCA ? 'warn' : 'bad';
            $s['estadoTexto'] = $cob >= self::EQUILIBRIO_CERCA ? 'Cerca' : 'Por debajo';
            $s['frase'] = [
                self::t('En promedio vendes '),
                self::t(self::dinero($vd) . ' al día', true),
                self::t(': cubres el ' . self::porcentaje($cob, 0) . ' de lo necesario'
                    . ($n['ticketsEquilibrio'] !== null
                        ? '; harían falta unos ' . number_format($n['ticketsEquilibrio']) . ' tickets al día.'
                        : '.')),
            ];
        }

        // Progreso: la escala llega un cuarto más allá del equilibrio para que
        // la marca de «equilibrio» nunca quede pegada al canto.
        $max = max($eq * 1.25, $vd * 1.05);
        $s['medidor'] = [
            'tipo' => 'progreso',
            'valor' => round(min($vd, $max) / $max * 100, 2),
            'desborda' => false,
            'meta' => round($eq / $max * 100, 2),
            'metaRotulo' => 'equilibrio',
        ];

        return $s;
    }

    private static function signoInsumos(array $n): array
    {
        [$refMin, $refMax] = self::INSUMOS_REF;
        $s = [
            'clave' => 'insumos',
            'rotulo' => 'Costo de insumos',
            'valor' => '—',
            'unidad' => '',
            'estado' => 'neutral',
            'estadoTexto' => 'Sin ventas',
            'frase' => [self::t('Sin ventas no hay costo que comparar.')],
            'referencia' => 'Referencia del sector: ' . (int) $refMin . '–' . (int) $refMax . '% de la venta.',
            'medidor' => null,
            'accion' => ['Revisar recetas', '/admin/recetas'],
        ];
        if ($n['insumosPct'] === null) {
            return $s;
        }

        $p = (float) $n['insumosPct'];
        $s['valor'] = self::porcentaje($p);
        $s['unidad'] = 'de la venta';
        $s['frase'] = [
            self::t('De cada $100 que vendes, '),
            self::t('$' . number_format($p, 2) . ' son insumos', true),
            self::t('. Margen bruto: ' . self::porcentaje((float) $n['margenBruto']) . '.'),
        ];

        if ($n['sinCostoPct'] !== null && $n['sinCostoPct'] > self::SIN_COSTO_AVISO) {
            $s['estado'] = 'warn';
            $s['estadoTexto'] = 'Incompleto';
            $s['frase'][] = self::t(' ' . self::porcentaje((float) $n['sinCostoPct'], 0) . ' de lo vendido no tiene receta con costo.');
        } elseif ($p <= $refMax) {
            $s['estado'] = 'good';
            $s['estadoTexto'] = $p < $refMin ? 'Bajo' : 'En rango';
        } elseif ($p <= self::INSUMOS_ALTO) {
            $s['estado'] = 'warn';
            $s['estadoTexto'] = 'Alto';
        } else {
            $s['estado'] = 'bad';
            $s['estadoTexto'] = 'Muy alto';
        }

        $max = 60.0;
        $s['medidor'] = [
            'tipo' => 'rango',
            'valor' => round(min($p, $max) / $max * 100, 2),
            'desborda' => $p > $max,
            'banda' => [round($refMin / $max * 100, 2), round($refMax / $max * 100, 2)],
            'bandaRotulo' => (int) $refMin . '–' . (int) $refMax . '%',
        ];

        return $s;
    }

    private static function signoMerma(array $n): array
    {
        [$mMin, $mMax] = self::MERMA_REF;
        $s = [
            'clave' => 'merma',
            'rotulo' => 'Merma',
            'valor' => '—',
            'unidad' => '',
            'estado' => 'neutral',
            'estadoTexto' => 'Sin consumo',
            'frase' => [self::t('No salió nada del almacén en este periodo.')],
            'referencia' => 'En cocinas profesionales se tira del ' . (int) $mMin . ' al ' . (int) $mMax . '% de lo comprado.',
            'medidor' => null,
            'accion' => ['Ver inventario', '/admin/inventario'],
        ];
        if ($n['mermaPct'] === null) {
            return $s;
        }

        $p = (float) $n['mermaPct'];
        $s['valor'] = self::porcentaje($p);
        $s['unidad'] = 'de lo consumido';
        $s['frase'] = $n['M'] > 0
            ? [
                self::t(self::dinero($n['M']), true),
                self::t(' tirados de ' . self::dinero($n['consumo']) . ' que salieron del almacén.'),
            ]
            : [self::t('No se registró merma. Si algo se tira y no se anota, no cuenta.')];

        // «La» merma: el estado concuerda con el rótulo que tiene encima. Una
        // merma en cero no es «baja»: casi siempre es que nadie la registró, y
        // pintarla de verde premiaría no anotarla.
        if ($n['M'] <= 0) {
            $s['estado'] = 'neutral';
            $s['estadoTexto'] = 'Sin registro';
        } elseif ($p < $mMin) {
            $s['estado'] = 'good';
            $s['estadoTexto'] = 'Baja';
        } elseif ($p <= $mMax) {
            $s['estado'] = 'warn';
            $s['estadoTexto'] = 'En la media';
        } else {
            $s['estado'] = 'bad';
            $s['estadoTexto'] = 'Alta';
        }

        $max = 25.0;
        $s['medidor'] = [
            'tipo' => 'rango',
            'valor' => round(min($p, $max) / $max * 100, 2),
            'desborda' => $p > $max,
            'banda' => [round($mMin / $max * 100, 2), round($mMax / $max * 100, 2)],
            'bandaRotulo' => 'lo habitual',
        ];

        return $s;
    }

    private static function signoInventario(array $n): array
    {
        $meta = self::DIAS_INVENTARIO_META;
        $s = [
            'clave' => 'inventario',
            'rotulo' => 'Días de inventario',
            'valor' => '—',
            'unidad' => '',
            'estado' => 'neutral',
            'estadoTexto' => 'Sin datos',
            'frase' => [],
            'referencia' => 'Práctica del sector: perecederos para una semana; con barra y abarrotes, hasta dos.',
            'medidor' => null,
            'accion' => ['Ver inventario', '/admin/inventario'],
        ];
        if ($n['inventario'] <= 0) {
            $s['estadoTexto'] = 'Sin existencias';
            $s['frase'] = [self::t('No hay inventario valorizado: registra existencias y costos en Inventario.')];

            return $s;
        }
        if ($n['diasInventario'] === null) {
            $s['estadoTexto'] = 'Sin consumo';
            $s['frase'] = [self::t('Hay ' . self::dinero($n['inventario']) . ' en el almacén y nada salió en este periodo.')];

            return $s;
        }

        $dias = (float) $n['diasInventario'];
        $s['valor'] = number_format($dias, 0);
        $s['unidad'] = abs(round($dias)) == 1.0 ? 'día' : 'días';
        $s['frase'] = [
            self::t('Lo que hay hoy en el almacén ('),
            self::t(self::dinero($n['inventario']), true),
            self::t(') alcanza para ' . number_format($dias, 0) . ' ' . $s['unidad'] . ' al ritmo de este periodo'
                . ($n['rotacion'] !== null ? ' (rotación ' . number_format((float) $n['rotacion'], 2) . '×).' : '.')),
        ];
        if ($dias <= $meta) {
            $s['estado'] = 'good';
            $s['estadoTexto'] = 'Sano';
        } elseif ($dias <= self::DIAS_INVENTARIO_ALTO) {
            $s['estado'] = 'warn';
            $s['estadoTexto'] = 'Lento';
        } else {
            $s['estado'] = 'bad';
            $s['estadoTexto'] = 'Dinero parado';
        }

        $max = 60.0;
        $s['medidor'] = [
            'tipo' => 'rango',
            'valor' => round(min($dias, $max) / $max * 100, 2),
            'desborda' => $dias > $max,
            'banda' => [0.0, round($meta / $max * 100, 2)],
            'bandaRotulo' => 'hasta ' . (int) $meta . ' días',
        ];

        return $s;
    }

    // ── Utilidades ────────────────────────────────────────────────

    /** Una parte de texto: ['t' => …, 'fuerte' => bool, 'href' => ?string]. */
    private static function t(string $texto, bool $fuerte = false, ?string $href = null): array
    {
        return ['t' => $texto, 'fuerte' => $fuerte, 'href' => $href];
    }

    private static function dias(int $dias): string
    {
        return $dias === 1 ? 'un día' : $dias . ' días';
    }

    /** «Del 21/08/2026 al 19/09/2026» → «del 21/08/2026 al 19/09/2026». */
    private static function minuscula(string $texto): string
    {
        return mb_strtolower(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
