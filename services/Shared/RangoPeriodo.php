<?php

namespace Services\Shared;

/**
 * Rango de fechas compartido por los tableros del admin (analíticas, finanzas e
 * inventario).
 *
 * Antes cada módulo resolvía su periodo por su cuenta: analíticas con presets y
 * rango libre, finanzas fija al mes en curso e inventario sin filtro. Con una
 * sola resolución los tres hablan del mismo periodo y pueden compararlo contra
 * el inmediatamente anterior.
 *
 * Las fechas se validan con regex antes de salir de aquí porque los consumidores
 * las interpolan en SQL crudo.
 */
class RangoPeriodo
{
    /** Agrupa una serie diaria completa en días, semanas consecutivas o meses naturales. */
    public static function agruparSerieInforme(array $dias): array
    {
        $n = count($dias);
        $modo = $n <= 7 ? 'día' : ($n <= 93 ? 'semana' : 'mes');
        $grupos = [];
        $indice = 0;
        foreach ($dias as $fecha => $valor) {
            $clave = $modo === 'día' ? $fecha : ($modo === 'semana' ? (string) intdiv($indice, 7) : substr($fecha, 0, 7));
            $indice++;
            if (!isset($grupos[$clave])) $grupos[$clave] = ['inicio' => $fecha, 'fin' => $fecha, 'valor' => 0];
            $grupos[$clave]['fin'] = $fecha;
            $grupos[$clave]['valor'] += $valor;
        }
        $filas = [];
        foreach ($grupos as $grupo) {
            $etiqueta = $grupo['inicio'] === $grupo['fin'] ? $grupo['inicio'] : $grupo['inicio'] . ' a ' . $grupo['fin'];
            $filas[] = [$etiqueta, $grupo['valor']];
        }
        return ['modo' => $modo, 'filas' => $filas];
    }
    private const FORMATO = '/^\d{4}-\d{2}-\d{2}$/';

    /** Presets ofrecidos por el selector, en días. */
    public const PRESETS = [3, 7, 30, 60, 365];

    private const ETIQUETAS = [
        3 => 'Últimos 3 días',
        7 => 'Últimos 7 días',
        30 => 'Últimos 30 días',
        60 => 'Últimos 60 días',
        365 => 'Último año',
    ];

    /**
     * @param array<string, mixed> $query Normalmente $_GET.
     * @return array{
     *     start: string, end: string, preset: int, label: string, dias: int,
     *     comparar: bool, prevStart: string, prevEnd: string, prevLabel: string
     * }
     */
    public static function resolver(array $query, int $presetPorDefecto = 30): array
    {
        $desde = trim((string) ($query['desde'] ?? ''));
        $hasta = trim((string) ($query['hasta'] ?? ''));

        if (preg_match(self::FORMATO, $desde) && preg_match(self::FORMATO, $hasta) && $desde <= $hasta) {
            $rango = [
                'start' => $desde,
                'end' => $hasta,
                'preset' => 0,
                'label' => 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            ];
        } else {
            $preset = (int) ($query['rango'] ?? $presetPorDefecto);
            if (!in_array($preset, self::PRESETS, true)) {
                $preset = $presetPorDefecto;
            }

            $rango = [
                'start' => date('Y-m-d', strtotime('-' . ($preset - 1) . ' days')),
                'end' => date('Y-m-d'),
                'preset' => $preset,
                'label' => self::ETIQUETAS[$preset] ?? ('Últimos ' . $preset . ' días'),
            ];
        }

        // La comparación va siempre. Era un interruptor en el popover del
        // selector de periodo y se retiró: comparar contra el periodo
        // inmediatamente anterior de la misma duración no es un extra, es lo
        // que da sentido a la cifra. El query `comparar=1` que el picker sigue
        // enviando ya no decide nada, y sin él —primera carga, enlace pegado,
        // marcador— el comparativo también se calcula.
        return $rango + self::comparativo($rango['start'], $rango['end']);
    }

    /**
     * Rango que mira HACIA DELANTE: por defecto [hoy, hoy + N - 1].
     *
     * `resolver()` es retrospectivo porque sus consumidores (analíticas,
     * finanzas, inventario) miden lo que ya pasó. Las pantallas de planeación
     * de reservaciones miran lo que viene, y con el rango por defecto de
     * `resolver()` abrirían sobre el último mes —vacío de todo lo que hay que
     * planear—. Es un método aparte y no un parámetro de `resolver()` para no
     * tocar a ninguno de los tres consumidores que ya lo usan.
     *
     * Sin comparativo: «el periodo anterior» de algo que aún no ocurre no es
     * una cifra con la que comparar. Sin presets: el selector se usa con
     * $rangeShowPresets = false, porque los presets dicen «Últimos N días».
     *
     * `$hoy` llega del llamador (normalmente ReservacionConfig::fechaActual())
     * porque «hoy» depende de la zona horaria del negocio, que este servicio
     * compartido no conoce. `$maxDias` recorta el fin cuando el consumidor no
     * puede con más: así la etiqueta del selector dice el rango que de verdad
     * se pinta y no el que se pidió.
     *
     * @param array<string, mixed> $query Normalmente $_GET.
     * @return array{
     *     start: string, end: string, preset: int, label: string, dias: int,
     *     comparar: bool, porDefecto: bool, recortado: bool
     * }
     */
    public static function resolverProspectivo(
        array $query,
        string $hoy,
        int $diasPorDefecto = 30,
        ?int $maxDias = null
    ): array {
        if (!preg_match(self::FORMATO, $hoy)) {
            $hoy = date('Y-m-d');
        }
        $diasPorDefecto = max(1, $diasPorDefecto);
        $desde = trim((string) ($query['desde'] ?? ''));
        $hasta = trim((string) ($query['hasta'] ?? ''));
        $porDefecto = !(self::fechaReal($desde) && self::fechaReal($hasta) && $desde <= $hasta);

        if ($porDefecto) {
            $desde = $hoy;
            $hasta = date('Y-m-d', strtotime($hoy . ' +' . ($diasPorDefecto - 1) . ' days'));
        }

        $recortado = false;
        if ($maxDias !== null && $maxDias > 0 && self::dias($desde, $hasta) > $maxDias) {
            $hasta = date('Y-m-d', strtotime($desde . ' +' . ($maxDias - 1) . ' days'));
            $recortado = true;
        }

        return [
            'start' => $desde,
            'end' => $hasta,
            'preset' => 0,
            'label' => $porDefecto && !$recortado
                ? 'Próximos ' . $diasPorDefecto . ' días'
                : 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            'dias' => self::dias($desde, $hasta),
            'comparar' => false,
            'porDefecto' => $porDefecto,
            'recortado' => $recortado,
        ];
    }

    /** Formato Y-m-d y además una fecha que existe (no 2026-02-31). */
    private static function fechaReal(string $fecha): bool
    {
        if (!preg_match(self::FORMATO, $fecha)) {
            return false;
        }
        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

        return checkdate($mes, $dia, $anio);
    }

    /** Resuelve la entrada documental sin corregir silenciosamente fechas inválidas. */
    public static function resolverInforme(array $query, ?string $hoy = null): array
    {
        $hoy = $hoy ?? date('Y-m-d');
        if (!self::fechaInformeValida($hoy)) {
            throw new \InvalidArgumentException('La fecha actual no es válida.');
        }
        $tieneDesde = array_key_exists('desde', $query);
        $tieneHasta = array_key_exists('hasta', $query);
        if (array_key_exists('rango', $query) && ((!is_string($query['rango']) && !is_int($query['rango']))
            || !preg_match('/\A(3|7|30|60|365)\z/', (string) $query['rango']))) {
            throw new \InvalidArgumentException('El rango seleccionado no es válido.');
        }
        if ($tieneDesde || $tieneHasta) {
            if (!$tieneDesde || !$tieneHasta || !is_string($query['desde']) || !is_string($query['hasta'])) {
                throw new \InvalidArgumentException('Indique ambas fechas del periodo.');
            }
            $desde = $query['desde'];
            $hasta = $query['hasta'];
            if (!self::fechaInformeValida($desde) || !self::fechaInformeValida($hasta) || $desde > $hasta) {
                throw new \InvalidArgumentException('El periodo no contiene fechas válidas y ordenadas.');
            }
            $preset = 0;
        } else {
            $valor = $query['rango'] ?? '30';
            if ((!is_string($valor) && !is_int($valor)) || !preg_match('/\A(3|7|30|60|365)\z/', (string) $valor)) {
                throw new \InvalidArgumentException('El rango seleccionado no es válido.');
            }
            $preset = (int) $valor;
            $hasta = $hoy;
            $desde = (new \DateTimeImmutable($hoy))->modify('-' . ($preset - 1) . ' days')->format('Y-m-d');
        }
        $inicio = new \DateTimeImmutable($desde);
        $fin = new \DateTimeImmutable($hasta);
        $dias = (int) $inicio->diff($fin)->days + 1;
        if ($dias > 366) {
            throw new \InvalidArgumentException('El periodo no puede exceder 366 días.');
        }
        $anteriorFin = $inicio->modify('-1 day');
        $anteriorInicio = $anteriorFin->modify('-' . ($dias - 1) . ' days');
        if (!self::fechaInformeValida($anteriorInicio->format('Y-m-d'))) {
            throw new \InvalidArgumentException('El periodo anterior está fuera de los límites admitidos.');
        }
        return [
            'start' => $desde, 'end' => $hasta, 'preset' => $preset,
            'label' => 'Del ' . $inicio->format('d/m/Y') . ' al ' . $fin->format('d/m/Y'),
            'dias' => $dias, 'comparar' => true,
            'prevStart' => $anteriorInicio->format('Y-m-d'), 'prevEnd' => $anteriorFin->format('Y-m-d'),
            'prevLabel' => 'Del ' . $anteriorInicio->format('d/m/Y') . ' al ' . $anteriorFin->format('d/m/Y'),
        ];
    }

    private static function fechaInformeValida(string $fecha): bool
    {
        return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $fecha) === 1
            && self::fechaReal($fecha) && $fecha >= '1000-01-01' && $fecha <= '9998-12-31';
    }

    /**
     * Periodo anterior de la misma duración, pegado al inicio del actual. Es la
     * comparación que evita confundir "vendimos más" con "el periodo era más
     * largo".
     *
     * @return array{comparar: bool, dias: int, prevStart: string, prevEnd: string, prevLabel: string}
     */
    private static function comparativo(string $start, string $end, bool $comparar = true): array
    {
        $dias = self::dias($start, $end);
        $prevEnd = date('Y-m-d', strtotime($start . ' -1 day'));
        $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($dias - 1) . ' days'));

        return [
            'comparar' => $comparar,
            'dias' => $dias,
            'prevStart' => $prevStart,
            'prevEnd' => $prevEnd,
            'prevLabel' => 'Del ' . date('d/m/Y', strtotime($prevStart))
                . ' al ' . date('d/m/Y', strtotime($prevEnd)),
        ];
    }

    /** Días que abarca el rango, ambos extremos incluidos. Nunca menos de 1. */
    public static function dias(string $start, string $end): int
    {
        $inicio = strtotime($start);
        $fin = strtotime($end);
        if ($inicio === false || $fin === false || $fin < $inicio) {
            return 1;
        }

        return (int) floor(($fin - $inicio) / 86400) + 1;
    }

    /**
     * Variación porcentual entre dos magnitudes del mismo tipo. Devuelve null
     * cuando la base es cero: un "+∞ %" no le dice nada a nadie.
     */
    public static function delta(float $actual, float $previo): ?float
    {
        if ($previo <= 0.0) {
            return null;
        }

        return round((($actual - $previo) / $previo) * 100, 1);
    }
}
