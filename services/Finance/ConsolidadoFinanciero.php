<?php

declare(strict_types=1);

/**
 * Finanzas de Global: el estado de los dos negocios para el dueño.
 *
 * Global no vende ni tiene gastos propios: sólo LEE. Lo que entrega son tres
 * alturas de lectura, de lo urgente a lo estructural:
 *
 *   1. El pulso de HOY de cada negocio (cobrado, tickets, cuentas abiertas),
 *      contra el mismo día de la semana pasada a esta hora.
 *   2. El periodo elegido, con cada negocio lado a lado: el mismo estado de
 *      resultados y el mismo veredicto que ve cada negocio en su pantalla,
 *      porque sale de FinanzasNegocio::resumen() con su giro.
 *   3. El total de la organización y lo que pide atención en cada negocio.
 *
 * Reglas del consolidado (arquitectura §3 y §8):
 *   - Se suman ventas y costos de los negocios; nada más. Una transferencia
 *     entre almacenes no es venta ni gasto, y aquí no se lee ninguna.
 *   - El inventario consolidado sí incluye los almacenes de Global: es
 *     capital de la organización aunque no lo venda nadie todavía.
 *   - Los márgenes del total salen de los totales, nunca del promedio de los
 *     márgenes de cada negocio, que pesaría igual a uno que vende diez veces
 *     menos.
 *
 * El permiso es «Finanzas» en Global (ver), la misma regla de lectura
 * consolidada que Almacenes. No concede escritura en ningún negocio.
 */

namespace Services\Finance;

use Services\Security\ContextoNegocio;
use Services\Security\Contextos;

final class ConsolidadoFinanciero
{
    /**
     * @param array<string, mixed> $rango RangoPeriodo::resolver()
     * @return array<string, mixed>
     */
    public static function armar(array $rango, ?\DateTimeImmutable $ahora = null): array
    {
        ContextoNegocio::exigir('finanzas', 'ver', 'global');
        $db = ContextoNegocio::db();

        $negocios = [];
        foreach (FinanzasNegocio::NEGOCIOS as $slug) {
            $unidad = ContextoNegocio::unidad($slug);
            $resumen = FinanzasNegocio::resumen($db, $slug, $unidad, $rango);
            $negocios[$slug] = [
                'slug' => $slug,
                'nombre' => Contextos::NOMBRES[$slug],
                'hoy' => FinanzasNegocio::pulsoHoy($db, $unidad, $ahora),
                'periodo' => $resumen,
                'veredicto' => $resumen['diagnostico']['veredicto'],
                'alertas' => self::alertas($resumen['diagnostico']['signos']),
            ];
        }

        return [
            'negocios' => $negocios,
            'hoy' => self::totalHoy($negocios),
            'total' => self::total($db, $negocios),
        ];
    }

    /**
     * Lo que pide atención en un negocio: los signos vitales fuera de lo sano
     * (`bad` primero, luego `warn`), con su frase y su acción. Lo neutral o lo
     * sano no es una alerta.
     *
     * @param list<array<string, mixed>> $signos
     * @return list<array<string, mixed>>
     */
    private static function alertas(array $signos): array
    {
        $alertas = array_values(array_filter($signos, static fn (array $s): bool => in_array($s['estado'] ?? '', ['bad', 'warn'], true)));
        usort($alertas, static fn (array $a, array $b): int => ($a['estado'] === 'bad' ? 0 : 1) <=> ($b['estado'] === 'bad' ? 0 : 1));

        return array_map(static fn (array $s): array => [
            'clave' => $s['clave'],
            'rotulo' => $s['rotulo'],
            'estado' => $s['estado'],
            'estadoTexto' => $s['estadoTexto'],
            'valor' => trim($s['valor'] . ' ' . ($s['unidad'] ?? '')),
            'frase' => $s['frase'] ?? [],
        ], $alertas);
    }

    /** @return array{ventas: float, tickets: int, abiertas: int, enCurso: float, ventasSemanaPasada: float} */
    private static function totalHoy(array $negocios): array
    {
        $t = ['ventas' => 0.0, 'tickets' => 0, 'abiertas' => 0, 'enCurso' => 0.0, 'ventasSemanaPasada' => 0.0];
        foreach ($negocios as $n) {
            foreach ($t as $k => $_) {
                $t[$k] += $n['hoy'][$k];
            }
        }

        return $t;
    }

    /**
     * El estado de resultados de la organización y la parte de cada negocio
     * en la venta y en la utilidad.
     *
     * @return array<string, mixed>
     */
    private static function total(\mysqli $db, array $negocios): array
    {
        $campos = ['ingresos', 'tickets', 'cogs', 'merma', 'utilidadBruta', 'gastos', 'utilidadNeta', 'inventarioValor'];
        $t = array_fill_keys($campos, 0.0);
        $previo = ['ingresos' => 0.0, 'tickets' => 0.0, 'cogs' => 0.0, 'merma' => 0.0, 'utilidadBruta' => 0.0, 'utilidadNeta' => 0.0];
        foreach ($negocios as $n) {
            foreach ($campos as $c) {
                $t[$c] += (float) $n['periodo'][$c];
            }
            foreach ($previo as $c => $_) {
                $previo[$c] += (float) $n['periodo']['previo'][$c];
            }
        }
        $t['tickets'] = (int) $t['tickets'];
        $t['inventarioGlobal'] = FinanzasNegocio::inventarioValor($db, ContextoNegocio::unidad('global'));
        $t['inventarioTotal'] = $t['inventarioValor'] + $t['inventarioGlobal'];
        $t['margenBruto'] = $t['ingresos'] > 0 ? $t['utilidadBruta'] / $t['ingresos'] * 100 : null;
        $t['margenNeto'] = $t['ingresos'] > 0 ? $t['utilidadNeta'] / $t['ingresos'] * 100 : null;
        $t['insumosPct'] = $t['ingresos'] > 0 ? $t['cogs'] / $t['ingresos'] * 100 : null;
        $t['ticketPromedio'] = $t['tickets'] > 0 ? $t['ingresos'] / $t['tickets'] : null;
        $previo['tickets'] = (int) $previo['tickets'];
        $previo['ticketPromedio'] = $previo['tickets'] > 0 ? $previo['ingresos'] / $previo['tickets'] : null;
        $t['previo'] = $previo;

        // Participación en la venta. En la utilidad sólo cuando las dos son
        // positivas: con una pérdida, «el 130 % de la utilidad» no se lee.
        $t['participacion'] = [];
        $todasPositivas = array_reduce($negocios, static fn (bool $ok, array $n): bool => $ok && $n['periodo']['utilidadNeta'] > 0, true);
        foreach ($negocios as $slug => $n) {
            $t['participacion'][$slug] = [
                'ventas' => $t['ingresos'] > 0 ? $n['periodo']['ingresos'] / $t['ingresos'] * 100 : null,
                'utilidad' => $todasPositivas && $t['utilidadNeta'] > 0 ? $n['periodo']['utilidadNeta'] / $t['utilidadNeta'] * 100 : null,
            ];
        }

        return $t;
    }
}
