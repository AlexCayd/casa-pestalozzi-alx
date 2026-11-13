<?php
declare(strict_types=1);

namespace Services\Shared;

/** Geometría documental estática. No consulta datos ni calcula indicadores. */
final class GraficaPdf
{
    private const COLORES = ['#516779', '#969fa7'];

    public static function validar(array $seccion): void
    {
        $g = $seccion['grafica'];
        $formatos = array_values($seccion['formatos'] ?? []);
        if (!is_array($g) || !in_array($g['tipo'] ?? null, ['lineas', 'columnas', 'barras'], true)
            || !is_string($g['unidad'] ?? null) || !is_array($g['series'] ?? null)
            || !array_is_list($g['series']) || count($g['series']) < 1 || count($g['series']) > 2
            || !is_array($g['detalle'] ?? []) || !array_is_list($g['detalle'] ?? [])) {
            throw new \InvalidArgumentException('Configuración de gráfica inválida.');
        }
        foreach (array_merge($g['series'], $g['detalle'] ?? []) as $indice) {
            if (!is_int($indice) || $indice < 1 || !isset($formatos[$indice]) || $formatos[$indice] === 'texto') {
                throw new \InvalidArgumentException('Columna de gráfica inválida.');
            }
        }
        if ($formatos[0] !== 'texto' || ($g['tipo'] !== 'barras' && count($g['series']) !== 1)
            || count(array_unique($g['series'])) !== count($g['series'])) {
            throw new \InvalidArgumentException('Estructura de gráfica inválida.');
        }
        $tipo = $formatos[$g['series'][0]];
        foreach ($g['series'] as $indice) if ($formatos[$indice] !== $tipo) {
            throw new \InvalidArgumentException('Series con unidades incompatibles.');
        }
    }

    /** Partición visual: nunca divide ni recalcula el total de un tramo. */
    public static function alcanceTemporal(array $seccion, string $corteDia): array
    {
        $observadas = []; $restantes = []; $completas = [];
        foreach ($seccion['filas'] as $fila) {
            $fila = array_values($fila);
            $conFecha = preg_match('/\A(\d{4}-\d{2}-\d{2})(?: a (\d{4}-\d{2}-\d{2}))?\z/', (string) $fila[0], $fechas);
            $inicio = $conFecha ? $fechas[1] : null;
            $fin = $conFecha ? ($fechas[2] ?? $inicio) : null;
            if ($fin !== null && $fin < $corteDia) {
                $alcance = 'Observado'; $observadas[] = $fila;
            } elseif ($inicio !== null && $inicio > $corteDia) {
                $alcance = $fila[1] === null ? 'No disponible; no observado'
                    : ($fila[1] == 0 ? 'Sin actividad registrada; no observado' : 'Registrado futuro; no observado');
            } elseif ($inicio !== null) {
                $alcance = 'Parcial al corte; total registrado del tramo';
            } else { $alcance = 'Alcance temporal no determinado'; }
            $completa = [$fila[0], $fila[1], $alcance];
            $completas[] = $completa;
            if ($alcance !== 'Observado') $restantes[] = $completa;
        }
        $observada = $seccion; $observada['filas'] = $observadas;
        $tabla = $seccion; unset($tabla['grafica']);
        if (!$restantes) return ['observada' => $observada, 'tabla' => $tabla, 'restante' => null];
        $tabla['columnas'] = ['Periodo', 'Ventas registradas', 'Alcance'];
        $tabla['formatos'] = ['texto', 'moneda', 'texto'];
        $tabla['anchos'] = [28, 24, 48];
        $tabla['filas'] = $completas;
        $restante = $tabla; $restante['filas'] = $restantes;
        $restante['titulo'] = 'Tramos parciales y futuros';
        return ['observada' => $observada, 'tabla' => $tabla, 'restante' => $restante];
    }

    /** @return array{imagenes:list<string>,mensaje:string,tabla:bool} */
    public static function preparar(array $seccion, callable $formato, string $fuenteGrafica = 'Montserrat'): array
    {
        self::validar($seccion);
        $g = $seccion['grafica'];
        $filas = array_map('array_values', array_values($seccion['filas']));
        $valores = [];
        foreach ($filas as $fila) foreach ($g['series'] as $i) if ($fila[$i] !== null) $valores[] = (float) $fila[$i];
        $magnitud = $valores ? max(array_map('abs', $valores)) : 0.0;
        $mensaje = !$filas || !$valores ? 'Sin datos disponibles para representar.'
            : ($magnitud === 0.0 ? 'Sin actividad distinta de cero; se conservan los valores exactos.' : '');
        if (!$mensaje && $g['tipo'] !== 'barras' && count(array_filter($filas, static fn ($f) => $f[$g['series'][0]] !== null)) < 2) {
            $mensaje = 'Un solo tramo observado no permite mostrar una tendencia.';
        }
        if (!$mensaje && ($magnitud > 1.0e12 || count($filas) > 14 && $g['tipo'] !== 'barras')) {
            $mensaje = 'Se muestran los valores exactos en tabla para preservar la legibilidad de esta escala.';
        }
        if (!$mensaje && $magnitud < 1.0e-7) $mensaje = 'Magnitudes inferiores a la precisión documental; se conserva la tabla sin exagerar su escala.';
        foreach ($filas as $fila) if (mb_strlen((string) $fila[0]) > 160) {
            $mensaje = 'Etiquetas extensas: se conserva el detalle completo en tabla.';
        }
        if ($mensaje) return ['imagenes' => [], 'mensaje' => $mensaje, 'tabla' => true];
        $min = min(0.0, min($valores)); $max = max(0.0, max($valores));
        $marcas = self::escala($min, $max, array_values($seccion['formatos'])[$g['series'][0]] === 'entero');
        $limite = 5;
        if ($g['tipo'] !== 'barras') {
            $longitud = 0;
            foreach ($filas as $fila) if ($fila[$g['series'][0]] !== null) {
                $longitud = max($longitud, mb_strlen(self::valorPunto($fila[$g['series'][0]], array_values($seccion['formatos'])[$g['series'][0]], $formato)));
            }
            $limite = max(2, min(7, (int) floor(580 / max(72, $longitud * 6 + 14))));
        }
        $cantidadLotes = (int) ceil(count($filas) / $limite);
        $lotes = array_chunk($filas, (int) ceil(count($filas) / $cantidadLotes));
        if ($g['tipo'] !== 'barras') foreach ($lotes as $lote) {
            if (!array_filter($lote, static fn ($fila) => $fila[$g['series'][0]] !== null && $fila[$g['series'][0]] != 0)) {
                return ['imagenes' => [], 'mensaje' => 'Para evitar un panel sin magnitudes registradas, la serie completa se presenta en tabla; se conservan ceros y ausencias.', 'tabla' => true];
            }
        }
        if ($g['tipo'] === 'lineas') foreach ($lotes as $lote) {
            if (count(array_filter($lote, static fn ($f) => $f[$g['series'][0]] !== null)) < 2) {
                return ['imagenes' => [], 'mensaje' => 'Observaciones insuficientes para trazar una tendencia legible sin conectar ausencias.', 'tabla' => true];
            }
        }
        $imagenes = [];
        // Todas las páginas mantienen la misma escala: no se omiten categorías.
        foreach ($lotes as $lote) {
            $svg = $g['tipo'] === 'barras'
                ? self::barras($lote, $seccion, $marcas, $formato)
                : self::temporal($lote, $seccion, $marcas, $formato);
            $svg = str_replace('font-family="Montserrat"', 'font-family="' . self::esc($fuenteGrafica) . '"', $svg);
            $imagenes[] = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
        return ['imagenes' => $imagenes, 'mensaje' => '', 'tabla' => false];
    }

    private static function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function n(float $n): string { return number_format($n, 4, '.', ''); }
    private static function texto(float $x, float $y, string $s, string $ancla = 'start', int $tam = 13, string $color = '#34393e'): string
    {
        return '<text x="' . self::n($x) . '" y="' . self::n($y) . '" text-anchor="' . $ancla
            . '" font-family="Montserrat" font-size="' . $tam . '" fill="' . $color . '">' . self::esc($s) . '</text>';
    }
    private static function linea(float $x1, float $y1, float $x2, float $y2, string $color = '#d7dadd', int $ancho = 1): string
    {
        return '<line x1="' . self::n($x1) . '" y1="' . self::n($y1) . '" x2="' . self::n($x2) . '" y2="' . self::n($y2) . '" stroke="' . $color . '" stroke-width="' . $ancho . '"/>';
    }
    private static function inicio(float $alto): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="700" height="' . self::n($alto) . '" viewBox="0 0 700 ' . self::n($alto) . '"><rect width="700" height="' . self::n($alto) . '" fill="#ffffff"/>';
    }
    private static function tick(float $v): string
    {
        return abs($v) >= 1000000 ? number_format($v / 1000000, 2, '.', ',') . ' M'
            : rtrim(rtrim(number_format($v, 8, '.', ','), '0'), '.');
    }
    private static function escala(float $min, float $max, bool $entera): array
    {
        $objetivo = ($max - $min) / 4;
        $potencia = 10 ** floor(log10($objetivo));
        $paso = $potencia * 10;
        foreach ([1, 2, 5, 10] as $factor) if ($potencia * $factor >= $objetivo) { $paso = $potencia * $factor; break; }
        if ($entera) $paso = max(1, $paso);
        $inicio = floor($min / $paso); $fin = ceil($max / $paso); $marcas = [];
        for ($i = $inicio; $i <= $fin; $i++) $marcas[] = $i * $paso;
        return $marcas;
    }
    private static function rotulos(string $s, int $ancho): array
    {
        $lineas = []; $actual = '';
        foreach (preg_split('/\s+/u', $s) ?: [] as $palabra) {
            foreach (mb_str_split($palabra, $ancho) as $trozo) {
                if (mb_strlen($actual . ' ' . $trozo) > $ancho && $actual !== '') { $lineas[] = $actual; $actual = ''; }
                $actual = trim($actual . ' ' . $trozo);
            }
        }
        if ($actual !== '') $lineas[] = $actual;
        return $lineas ?: [''];
    }
    private static function valorPunto($valor, string $tipo, callable $formato): string
    {
        // La unidad se declara en el eje; no se abrevia ni redondea el dato del punto.
        return str_replace([' MXN', '$', ' %'], '', $formato($valor, $tipo));
    }
    private static function barras(array $filas, array $s, array $marcas, callable $formato): string
    {
        $min = $marcas[0]; $max = $marcas[count($marcas) - 1];
        $g = $s['grafica']; $columnas = array_values($s['columnas']); $formatos = array_values($s['formatos']);
        $x = static fn (float $v): float => 250 + (($v - $min) / ($max - $min)) * 430;
        $altoFilas = [];
        foreach ($filas as $f) $altoFilas[] = max(52, count(self::rotulos((string) $f[0], 30)) * 15 + count($g['detalle'] ?? []) * 15 + 22, count($g['series']) * 25 + count($g['detalle'] ?? []) * 15 + 12);
        $alto = 86 + array_sum($altoFilas); $svg = self::inicio($alto);
        $svg .= self::texto(250, 15, $g['unidad'] . ' · origen cero; escala común');
        foreach ($g['series'] as $j => $i) {
            $svg .= '<rect x="' . (250 + $j * 210) . '" y="25" width="10" height="10" fill="' . self::COLORES[$j] . '"/>';
            foreach (self::rotulos($columnas[$i], 28) as $k => $rotulo) $svg .= self::texto(265 + $j * 210, 35 + $k * 13, $rotulo, 'start', 12);
        }
        foreach ($marcas as $v) {
            $svg .= self::linea($x($v), 60, $x($v), $alto - 12);
            $svg .= self::texto($x($v), 54, self::tick($v), 'middle', 12);
        }
        $svg .= self::linea($x(0), 60, $x(0), $alto - 12, '#516779', 2);
        $y = 72;
        foreach ($filas as $k => $fila) {
            foreach (self::rotulos((string) $fila[0], 30) as $j => $rotulo) $svg .= self::texto(4, $y + 14 + $j * 15, $rotulo);
            foreach ($g['series'] as $j => $i) {
                $v = $fila[$i]; $barY = $y + $j * 25;
                $etiqueta = $formato($v, $formatos[$i]);
                $svg .= self::texto(250, $barY + 10, $etiqueta, 'start', 12);
                if ($v !== null && $v != 0) $svg .= '<rect x="' . self::n(min($x(0), $x((float) $v))) . '" y="' . self::n($barY + 14) . '" width="' . self::n(abs($x((float) $v) - $x(0))) . '" height="9" fill="' . self::COLORES[$j] . '"/>';
            }
            $detalleY = $y + $altoFilas[$k] - 8;
            foreach ($g['detalle'] ?? [] as $j => $i) {
                $svg .= self::texto(4, $detalleY - (count($g['detalle']) - 1 - $j) * 14,
                    $columnas[$i] . ': ' . ($fila[$i] === null ? ($formatos[$i] === 'porcentaje' ? 'Sin base comparable' : 'No disponible') : $formato($fila[$i], $formatos[$i], in_array($columnas[$i], ['Diferencia', 'Variación'], true))), 'start', 12);
            }
            $y += $altoFilas[$k]; $svg .= self::linea(0, $y - 3, 690, $y - 3);
        }
        return $svg . '</svg>';
    }
    private static function temporal(array $filas, array $s, array $marcas, callable $formato): string
    {
        $min = $marcas[0]; $max = $marcas[count($marcas) - 1];
        $g = $s['grafica']; $indice = $g['series'][0]; $tipo = array_values($s['formatos'])[$indice];
        $y = static fn (float $v): float => 158 - (($v - $min) / ($max - $min)) * 115;
        $agrupada = count(array_filter($filas, static fn ($fila) => str_contains((string) $fila[0], ' a '))) > 0;
        $paso = 580 / count($filas); $svg = self::inicio($agrupada ? 248 : 198);
        $svg .= self::texto(75, 15, $g['unidad'] . ' · valores exactos ' . ($g['tipo'] === 'lineas' ? 'junto a cada punto' : 'sobre cada barra'), 'start', 12);
        foreach ($marcas as $v) {
            $svg .= self::linea(75, $y($v), 675, $y($v));
            $svg .= self::texto(67, $y($v) + 4, self::tick($v), 'end', 12);
        }
        $svg .= self::linea(75, 43, 75, 158, '#516779');
        $svg .= self::linea(75, $y(0), 675, $y(0), '#516779', 2);
        $anterior = null;
        foreach ($filas as $i => $fila) {
            $x = 85 + ($i + .5) * $paso; $v = $fila[$indice];
            if ($v !== null) {
                if ($g['tipo'] === 'lineas') {
                    if ($anterior !== null) $svg .= self::linea($anterior[0], $anterior[1], $x, $y((float) $v), '#516779', 2);
                    $svg .= '<circle cx="' . self::n($x) . '" cy="' . self::n($y((float) $v)) . '" r="3" fill="#516779"/>';
                    $anterior = [$x, $y((float) $v)];
                } elseif ($v != 0) {
                    $svg .= '<rect x="' . self::n($x - min(14, $paso / 3)) . '" y="' . self::n(min($y(0), $y((float) $v))) . '" width="' . self::n(min(28, $paso * 2 / 3)) . '" height="' . self::n(abs($y((float) $v) - $y(0))) . '" fill="#516779"/>';
                }
                $svg .= self::texto($x, $y((float) $v) - 9, self::valorPunto($v, $tipo, $formato), 'middle', 12);
            } else { $anterior = null; $svg .= self::texto($x, 42, 'N/D', 'middle', 12); }
            $fechas = explode(' a ', (string) $fila[0]);
            foreach ($fechas as $j => $fecha) {
                $rotulo = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $fecha) ? substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 2, 2) : $fecha;
                $svg .= self::texto($x, 181 + $j * 15, ($j ? 'a ' : '') . $rotulo, 'middle', 12);
            }
        }
        if ($agrupada) {
            $svg .= self::texto(75, 225, 'Tramos cronológicos · totales, no promedios diarios', 'start', 12);
            $svg .= self::texto(75, 241, 'Los extremos pueden cubrir menos días; la separación representa orden, no duración.', 'start', 12);
        }
        return $svg . '</svg>';
    }
}
