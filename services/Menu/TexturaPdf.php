<?php

namespace Services\Menu;

/**
 * Las dos texturas de la carta impresa (views/admin/menu/items-pdf.php):
 * papel de algodón para las hojas y lino para la banda verde de la portada,
 * que es el mantel de la casa.
 *
 * Se GENERAN y no son archivos del repo por una razón: el fondo de cada una es
 * un color de la paleta, y la paleta se edita desde Configuración
 * (PaletaLanding). Una foto de papel con su color cocido dejaría de coincidir
 * con el crema en cuanto alguien lo cambiara.
 *
 * Tres decisiones que vienen de cómo pinta Dompdf, y que no se ven aquí:
 *
 *   1 · JPEG y no PNG. El grano es ruido, y el ruido en PNG no comprime: una
 *       hoja entera pesaba más que la carta completa. En JPEG la loseta de
 *       papel sale en unas decenas de KB.
 *   2 · Losetas `<img>` y no `background-image`. Dompdf compone cualquier
 *       fondo como un PNG del tamaño de la caja a 96 ppp: pierde la
 *       resolución y vuelve el punto 1. Un <img> con su tamaño en el CSS va
 *       tal cual, y la misma loseta repetida se incrusta UNA vez.
 *   3 · Losetas sin costura. Todo lo que se dibuja se repite en módulo del
 *       lado (el ruido de baja frecuencia se interpola sobre una rejilla
 *       cerrada y las fibras que cruzan un canto se dibujan también en el
 *       opuesto), así que las losetas casan sin línea.
 *
 * El azar va sembrado: dos cartas impresas el mismo día son la misma carta, y
 * la caché en disco (por color y versión del dibujo) no se invalida sola.
 */
final class TexturaPdf
{
    /** Sube si cambia el dibujo: la caché vieja deja de coincidir. */
    private const VERSION = 3;

    /**
     * Lado de la loseta de papel, en píxeles de la imagen. Grande a propósito:
     * con 480 la hoja llevaba veinte copias y el moteado se leía en rejilla.
     */
    public const LADO_PAPEL = 760;

    /** Lado de la loseta de lino. Más chica: el tejido es regular. */
    public const LADO_LINO = 240;

    private const CALIDAD_JPEG = 84;

    /**
     * Papel de algodón: grano fino, un moteado muy suave de baja frecuencia,
     * fibras cortas y alguna mota. Nada que compita con la letra: la
     * variación total del tono es de un par de puntos.
     */
    public static function papel(string $hex): string
    {
        return self::enCache('papel', $hex, static function (array $base): string {
            return self::dibujarPapel($base, self::LADO_PAPEL);
        });
    }

    /**
     * Lino: hilos de una fila y de una columna que se cruzan por encima y por
     * debajo (de ahí el damero de la trama), con algún engrosamiento suelto
     * —los «nudos» del lino— para que no se lea como una rejilla digital.
     */
    public static function lino(string $hex): string
    {
        return self::enCache('lino', $hex, static function (array $base): string {
            return self::dibujarLino($base, self::LADO_LINO);
        });
    }

    /**
     * Devuelve la textura como data URI, de la caché si ya existe.
     *
     * @param callable(array{0:int,1:int,2:int}):string $dibujar
     */
    private static function enCache(string $tipo, string $hex, callable $dibujar): string
    {
        $base = self::rgb($hex);
        // Sin GD o con un color ilegible la carta sale en papel liso: el
        // fondo de <body> ya trae el color de la paleta.
        if ($base === null || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return '';
        }

        $clave = $tipo . '-' . self::VERSION . '-' . implode('-', $base);
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'casa-pestalozzi-texturas';
        $ruta = $dir . DIRECTORY_SEPARATOR . $clave . '.jpg';

        $jpeg = is_readable($ruta) ? (string) @file_get_contents($ruta) : '';
        if ($jpeg === '') {
            $jpeg = $dibujar($base);
            if ($jpeg !== '') {
                // La caché es una cortesía: si el directorio no se puede
                // escribir, la textura se dibuja en cada impresión y ya.
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                @file_put_contents($ruta, $jpeg, LOCK_EX);
            }
        }

        return $jpeg === '' ? '' : 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    /** @param array{0:int,1:int,2:int} $base */
    private static function dibujarPapel(array $base, int $lado): string
    {
        mt_srand(1250);
        $img = imagecreatetruecolor($lado, $lado);

        // Moteado: dos octavas de ruido de valor sobre rejillas cerradas (6 y
        // 22 celdas por lado), así el patrón vuelve a empezar en el canto.
        $grueso = self::rejilla(6);
        $medio = self::rejilla(22);

        for ($y = 0; $y < $lado; $y++) {
            for ($x = 0; $x < $lado; $x++) {
                $u = $x / $lado;
                $v = $y / $lado;
                $moteado = 0.72 * self::ruido($grueso, 6, $u, $v) + 0.28 * self::ruido($medio, 22, $u, $v);
                // Grano triangular (suma de dos uniformes): menos picos que
                // uno gaussiano de la misma amplitud.
                $grano = (mt_rand() / mt_getrandmax()) + (mt_rand() / mt_getrandmax()) - 1;
                $k = 1 + 0.018 * $moteado + 0.03 * $grano;
                imagesetpixel($img, $x, $y, self::color($img, $base, $k));
            }
        }

        imagealphablending($img, true);

        // Fibras: trazos cortos y apenas curvos, unos más oscuros y otros más
        // claros que el papel, muy transparentes.
        for ($i = 0; $i < 420; $i++) {
            $oscura = mt_rand(0, 100) < 64;
            $k = $oscura ? 0.86 : 1.05;
            $alfa = mt_rand(86, 108); // 0 opaco · 127 transparente
            $color = self::colorAlfa($img, $base, $k, $alfa);
            $puntos = self::fibra($lado);
            self::trazoEnModulo($img, $puntos, $color, $lado);
        }

        // Motas: alguna inclusión del algodón, más oscura y diminuta.
        for ($i = 0; $i < 60; $i++) {
            $color = self::colorAlfa($img, $base, 0.8, mt_rand(84, 104));
            $x = mt_rand(0, $lado - 1);
            $y = mt_rand(0, $lado - 1);
            $r = mt_rand(0, 100) < 70 ? 1 : 2;
            foreach ([[0, 0], [$lado, 0], [0, $lado], [$lado, $lado], [-$lado, 0], [0, -$lado]] as [$ox, $oy]) {
                imagefilledellipse($img, $x + $ox, $y + $oy, $r, $r, $color);
            }
        }

        return self::jpeg($img);
    }

    /** @param array{0:int,1:int,2:int} $base */
    private static function dibujarLino(array $base, int $lado): string
    {
        mt_srand(1956);
        $img = imagecreatetruecolor($lado, $lado);

        // Cada hilo mide HILO píxeles: con uno solo el JPEG aplanaba la trama
        // y la banda se leía como un verde liso con rayas.
        $hilo = 2;
        $hilos = intdiv($lado, $hilo);

        // Un valor por hilo: filas (trama) y columnas (urdimbre).
        $filas = [];
        $columnas = [];
        for ($i = 0; $i < $hilos; $i++) {
            $filas[$i] = self::gauss();
            $columnas[$i] = self::gauss();
        }

        // Nudos: tramos de una fila más gruesos o más claros, que en el lino
        // de verdad son lo que delata que es tela.
        $nudos = array_fill(0, $hilos, []);
        for ($i = 0; $i < 30; $i++) {
            $nudos[mt_rand(0, $hilos - 1)][] = [
                'x' => mt_rand(0, $lado - 1),
                'largo' => mt_rand(14, 60),
                'valor' => (mt_rand(0, 1) ? 1 : -1) * (1.4 + mt_rand(0, 100) / 100),
            ];
        }

        $moteado = self::rejilla(5);

        for ($y = 0; $y < $lado; $y++) {
            $fila = intdiv($y, $hilo);
            for ($x = 0; $x < $lado; $x++) {
                $columna = intdiv($x, $hilo);
                // El damero decide qué hilo queda encima en cada cruce, y el
                // canto del hilo de encima cae un poco en sombra: es lo que da
                // relieve a la trama.
                $tramaEncima = (($columna + $fila) & 1) === 0;
                $encima = $tramaEncima ? $filas[$fila] : $columnas[$columna];
                $canto = ($tramaEncima ? $y : $x) % $hilo === $hilo - 1 ? -1 : 0.4;
                $nudo = 0.0;
                foreach ($nudos[$fila] as $n) {
                    $d = ($x - $n['x'] + $lado) % $lado;
                    if ($d < $n['largo']) {
                        $nudo += $n['valor'] * sin(M_PI * $d / $n['largo']);
                    }
                }
                $grano = (mt_rand() / mt_getrandmax()) * 2 - 1;
                $k = 1
                    + 0.05 * $encima
                    + 0.022 * $canto
                    + 0.04 * $nudo
                    + 0.016 * $grano
                    + 0.025 * self::ruido($moteado, 5, $x / $lado, $y / $lado);
                imagesetpixel($img, $x, $y, self::color($img, $base, $k));
            }
        }

        return self::jpeg($img);
    }

    /** @return array<int, array<int, float>> */
    private static function rejilla(int $celdas): array
    {
        $g = [];
        for ($j = 0; $j < $celdas; $j++) {
            for ($i = 0; $i < $celdas; $i++) {
                $g[$j][$i] = (mt_rand() / mt_getrandmax()) * 2 - 1;
            }
        }
        return $g;
    }

    /**
     * Ruido de valor con interpolación suave, cerrado sobre sí mismo: la
     * celda que sigue a la última es la primera.
     *
     * @param array<int, array<int, float>> $g
     */
    private static function ruido(array $g, int $celdas, float $u, float $v): float
    {
        $fx = $u * $celdas;
        $fy = $v * $celdas;
        $x0 = (int) floor($fx);
        $y0 = (int) floor($fy);
        $tx = $fx - $x0;
        $ty = $fy - $y0;
        $tx = $tx * $tx * (3 - 2 * $tx);
        $ty = $ty * $ty * (3 - 2 * $ty);
        $x0 %= $celdas;
        $y0 %= $celdas;
        $x1 = ($x0 + 1) % $celdas;
        $y1 = ($y0 + 1) % $celdas;
        $a = $g[$y0][$x0] + ($g[$y0][$x1] - $g[$y0][$x0]) * $tx;
        $b = $g[$y1][$x0] + ($g[$y1][$x1] - $g[$y1][$x0]) * $tx;
        return $a + ($b - $a) * $ty;
    }

    /** @return list<array{0:float,1:float}> */
    private static function fibra(int $lado): array
    {
        $x = mt_rand(0, $lado - 1);
        $y = mt_rand(0, $lado - 1);
        $angulo = mt_rand(0, 628) / 100;
        $giro = (mt_rand(-100, 100) / 100) * 0.06;
        $largo = mt_rand(10, 44);
        $puntos = [[(float) $x, (float) $y]];
        for ($i = 0; $i < $largo; $i += 2) {
            $angulo += $giro;
            $x += 2 * cos($angulo);
            $y += 2 * sin($angulo);
            $puntos[] = [$x, $y];
        }
        return $puntos;
    }

    /**
     * Dibuja el trazo y sus copias desplazadas un lado en cada eje: la parte
     * que se sale por un canto entra por el opuesto.
     *
     * @param list<array{0:float,1:float}> $puntos
     */
    private static function trazoEnModulo(\GdImage $img, array $puntos, int $color, int $lado): void
    {
        foreach ([-$lado, 0, $lado] as $ox) {
            foreach ([-$lado, 0, $lado] as $oy) {
                for ($i = 1, $n = count($puntos); $i < $n; $i++) {
                    imageline(
                        $img,
                        (int) round($puntos[$i - 1][0] + $ox),
                        (int) round($puntos[$i - 1][1] + $oy),
                        (int) round($puntos[$i][0] + $ox),
                        (int) round($puntos[$i][1] + $oy),
                        $color
                    );
                }
            }
        }
    }

    private static function gauss(): float
    {
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();
        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v) * 0.6;
    }

    /** @param array{0:int,1:int,2:int} $base */
    private static function color(\GdImage $img, array $base, float $k): int
    {
        return imagecolorallocate(
            $img,
            self::canal($base[0] * $k),
            self::canal($base[1] * $k),
            self::canal($base[2] * $k)
        );
    }

    /** @param array{0:int,1:int,2:int} $base */
    private static function colorAlfa(\GdImage $img, array $base, float $k, int $alfa): int
    {
        return imagecolorallocatealpha(
            $img,
            self::canal($base[0] * $k),
            self::canal($base[1] * $k),
            self::canal($base[2] * $k),
            max(0, min(127, $alfa))
        );
    }

    private static function canal(float $valor): int
    {
        return (int) max(0, min(255, round($valor)));
    }

    private static function jpeg(\GdImage $img): string
    {
        ob_start();
        imagejpeg($img, null, self::CALIDAD_JPEG);
        return (string) ob_get_clean();
    }

    /** @return array{0:int,1:int,2:int}|null */
    private static function rgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return null;
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
