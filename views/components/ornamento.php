<?php
/**
 * Adornos vectoriales de la landing.
 *
 * Son la misma familia de dibujo que la mesa del hero: trazo verde de 1.6 que
 * se dibuja al entrar (`pathLength="1"` + stroke-dashoffset), relleno de oro
 * que llega después, y un bucle pequeño mientras están a la vista (una rama que
 * se mece, una llama que tiembla, un brillo que parpadea). La animación vive en
 * src/scss/components/_ornamentos.scss y la dispara ornamentos.js; sin JS el
 * adorno sale dibujado y quieto.
 *
 * Ningún color aquí: el trazo es `currentColor` y el relleno `--orn-relleno`,
 * que _ornamentos.scss resuelve contra los roles del tono de la sección. Un
 * adorno se ve igual de bien en crema, en verde y en la banda de oro sin que la
 * vista tenga que saber dónde cae.
 *
 * Clases del dibujo:
 *   .t  trazo que se dibuja (lleva pathLength="1");
 *   .r  relleno que se funde después del trazo;
 *   .v  grupo con bucle (su keyframe lo elige `data-vida`).
 *
 * Son decorativos: aria-hidden y focusable="false". Lo que dicen ya lo dice el
 * texto de la sección.
 *
 * Se llama como función y no como parcial con variables: se pinta muchas veces
 * por página y así no hay variables locales que limpiar entre includes.
 *
 * Con `$sello = true` el dibujo va dentro de un SELLO: un disco del color de
 * la sección, una corona punteada que gira, un aro que se traza y tres
 * destellos alrededor, con el dibujo grande en el centro. Es el formato de los
 * adornos de sección (`.orn--seccion`), que se montan sobre el corte entre una
 * banda y la siguiente: como iconos sueltos de 5 rem se perdían.
 */

if (!function_exists('ornamento')) {
    /**
     * @param string $nombre olivo|trigo|uvas|copa|albahaca|destellos|cubiertos|vela|ruta|guirnalda|filete
     * @param string $clase  clases extra (la posición la decide quien lo usa)
     * @param bool   $sello  envolver el dibujo en el sello de sección
     */
    function ornamento(string $nombre, string $clase = '', bool $sello = false): string
    {
        // Los clipPath necesitan un id único en el documento: el mismo adorno
        // puede salir dos veces en la página.
        static $serie = 0;
        $serie++;
        $id = 'orn-' . $serie;

        $dibujos = [
            // Rama de olivo: el gesto que ya hace la mesa del hero.
            'olivo' => ['0 0 160 90', '
              <g class="v" data-vida="mecer" style="transform-origin:10px 72px">
                <path class="t" pathLength="1" d="M10 72 C40 62 82 42 150 20"/>
                <g transform="translate(34 63) rotate(-62)"><path class="t r" pathLength="1" d="M0 0 C6 -7 18 -8 26 0 C18 8 6 7 0 0 Z"/></g>
                <g transform="translate(50 56) rotate(38)"><path class="t r" pathLength="1" d="M0 0 C5 -6 16 -7 23 0 C16 7 5 6 0 0 Z"/></g>
                <g transform="translate(74 47) rotate(-56)"><path class="t r" pathLength="1" d="M0 0 C6 -7 18 -8 26 0 C18 8 6 7 0 0 Z"/></g>
                <g transform="translate(92 40) rotate(32)"><path class="t r" pathLength="1" d="M0 0 C5 -6 16 -7 23 0 C16 7 5 6 0 0 Z"/></g>
                <g transform="translate(114 32) rotate(-50)"><path class="t r" pathLength="1" d="M0 0 C6 -7 18 -8 26 0 C18 8 6 7 0 0 Z"/></g>
                <g transform="translate(131 26) rotate(26)"><path class="t r" pathLength="1" d="M0 0 C5 -6 16 -7 22 0 C16 7 5 6 0 0 Z"/></g>
                <g transform="translate(150 20) rotate(-18)"><path class="t r" pathLength="1" d="M0 0 C5 -6 15 -6 20 0 C15 6 5 6 0 0 Z"/></g>
                <path class="t" pathLength="1" d="M62 52 C62 58 61 61 60 64"/>
                <ellipse class="t r r--fuerte" pathLength="1" cx="59" cy="70" rx="4.6" ry="6.4"/>
                <path class="t" pathLength="1" d="M103 36 C104 42 104 45 103 48"/>
                <ellipse class="t r r--fuerte" pathLength="1" cx="103" cy="54" rx="4.4" ry="6"/>
              </g>'],

            // Espiga de trigo: Panadería.
            'trigo' => ['0 0 70 150', '
              <g class="v" data-vida="mecer" style="transform-origin:35px 148px">
                <path class="t" pathLength="1" d="M35 148 C34 112 36 72 35 26"/>
                <path class="t r" pathLength="1" d="M35 24 C30 16 31 8 35 2 C39 8 40 16 35 24 Z"/>
                ' . implode('', array_map(static function (int $y): string {
                    return '<path class="t r" pathLength="1" d="M35 ' . $y . ' C36 ' . ($y - 8) . ' 42 ' . ($y - 13) . ' 48 ' . ($y - 15) . ' C46 ' . ($y - 8) . ' 41 ' . ($y - 2) . ' 35 ' . $y . ' Z"/>'
                         . '<path class="t r" pathLength="1" d="M35 ' . ($y + 6) . ' C34 ' . ($y - 2) . ' 28 ' . ($y - 7) . ' 22 ' . ($y - 9) . ' C24 ' . ($y - 2) . ' 29 ' . ($y + 4) . ' 35 ' . ($y + 6) . ' Z"/>'
                         . '<path class="t" pathLength="1" d="M47 ' . ($y - 15) . ' L55 ' . ($y - 34) . ' M23 ' . ($y - 9) . ' L15 ' . ($y - 28) . '"/>';
                }, [40, 54, 68, 82])) . '
              </g>'],

            // Racimo con su hoja y su zarcillo: la cantina.
            'uvas' => ['0 0 116 132', '
              <path class="t" pathLength="1" d="M60 36 C60 24 62 16 70 8"/>
              <g class="v" data-vida="zarcillo" style="transform-origin:62px 26px">
                <path class="t" pathLength="1" d="M62 26 C74 22 84 28 80 38 C77 45 69 42 71 36 C72 33 75 33 76 35"/>
              </g>
              <g class="v" data-vida="hoja" style="transform-origin:58px 30px">
                <path class="t r" pathLength="1" d="M58 30 C46 12 24 10 12 22 C20 24 22 30 18 36 C28 36 32 40 30 46 C40 42 50 38 58 30 Z"/>
                <path class="t" pathLength="1" d="M58 30 C44 28 30 30 18 36 M44 30 C40 24 32 20 24 20"/>
              </g>
              <g class="v" data-vida="racimo" style="transform-origin:60px 36px">
                ' . implode('', array_map(static function (array $p): string {
                    return '<circle class="t r r--fuerte" pathLength="1" cx="' . $p[0] . '" cy="' . $p[1] . '" r="7.6"/>';
                }, [[36, 46], [52, 46], [68, 46], [84, 46], [44, 60], [60, 60], [76, 60], [52, 74], [68, 74], [60, 88]])) . '
                <path class="t" pathLength="1" d="M31 43 A5 5 0 0 1 35 40 M47 43 A5 5 0 0 1 51 40 M63 43 A5 5 0 0 1 67 40"/>
              </g>'],

            // Copa con el vino moviéndose dentro.
            'copa' => ['0 0 80 140', '
              <defs><clipPath id="' . $id . '-c"><path d="M18 14 C16 50 28 70 40 72 C52 70 64 50 62 14 Z"/></clipPath></defs>
              <g clip-path="url(#' . $id . '-c)">
                <g class="v" data-vida="vino" style="transform-origin:40px 44px">
                  <path class="r r--fuerte" d="M4 40 C22 46 52 34 76 40 V90 H4 Z"/>
                </g>
              </g>
              <path class="t" pathLength="1" d="M18 14 C16 50 28 70 40 72 C52 70 64 50 62 14 Z"/>
              <path class="t" pathLength="1" d="M40 72 V116 M22 122 C28 116 52 116 58 122 C52 126 28 126 22 122 Z"/>
              <path class="t" pathLength="1" d="M25 22 C24 34 26 44 30 52"/>
              <g transform="translate(70 18)"><g class="v" data-vida="brillo"><path class="r r--fuerte" d="M0 -8 C1 -2 2 -1 8 0 C2 1 1 2 0 8 C-1 2 -2 1 -8 0 C-2 -1 -1 -2 0 -8 Z"/></g></g>'],

            // Una carta de la casa: tarjeta de pie con su cinta, el medallón,
            // un filete y cuatro platillos con puntos guía hasta el precio.
            'carta' => ['0 0 104 132', '
              <g class="v" data-vida="mecer" style="transform-origin:52px 128px">
                <path class="t r" pathLength="1" d="M20 8 H84 A5 5 0 0 1 89 13 V123 A5 5 0 0 1 84 128 H20 A5 5 0 0 1 15 123 V13 A5 5 0 0 1 20 8 Z"/>
                <path class="t" pathLength="1" d="M22 15 H82 V121 H22 Z" stroke-width="0.8"/>
                <g class="v" data-vida="hoja" style="transform-origin:72px 8px"><path class="t r r--fuerte" pathLength="1" d="M68 8 V26 L72 22 L76 26 V8"/></g>
                <ellipse class="t r r--fuerte" pathLength="1" cx="52" cy="30" rx="6" ry="7.6"/>
                <path class="t" pathLength="1" d="M33 44 H46 M58 44 H71"/>
                <path class="t r r--fuerte" pathLength="1" d="M52 40.5 L55.5 44 L52 47.5 L48.5 44 Z"/>
                ' . implode('', array_map(static function (int $y): string {
                    return '<path class="t" pathLength="1" d="M29 ' . $y . ' H50"/>'
                         . '<path class="t t--puntos" pathLength="1" d="M53 ' . $y . ' H67"/>'
                         . '<path class="t" pathLength="1" d="M70 ' . $y . ' H75"/>'
                         . '<path class="t" pathLength="1" d="M29 ' . ($y + 5) . ' H58" stroke-width="0.9"/>';
                }, [58, 74, 90, 106])) . '
              </g>'],

            // Albahaca: la carta.
            'albahaca' => ['0 0 100 112', '
              <path class="t" pathLength="1" d="M50 110 C50 84 52 54 50 22"/>
              <g transform="translate(50 84)"><g class="v" data-vida="hoja"><g transform="rotate(-28)"><path class="t r" pathLength="1" d="M0 0 C8 -15 30 -15 38 0 C30 13 8 13 0 0 Z"/><path class="t" pathLength="1" d="M3 0 H33"/></g></g></g>
              <g transform="translate(50 84)"><g class="v" data-vida="hoja" style="animation-delay:-1.8s"><g transform="scale(-1 1) rotate(-28)"><path class="t r" pathLength="1" d="M0 0 C8 -15 30 -15 38 0 C30 13 8 13 0 0 Z"/><path class="t" pathLength="1" d="M3 0 H33"/></g></g></g>
              <g transform="translate(51 56)"><g class="v" data-vida="hoja" style="animation-delay:-.9s"><g transform="rotate(-40)"><path class="t r" pathLength="1" d="M0 0 C6 -11 22 -11 28 0 C22 10 6 10 0 0 Z"/><path class="t" pathLength="1" d="M3 0 H24"/></g></g></g>
              <g transform="translate(50 56)"><g class="v" data-vida="hoja" style="animation-delay:-2.6s"><g transform="scale(-1 1) rotate(-40)"><path class="t r" pathLength="1" d="M0 0 C6 -11 22 -11 28 0 C22 10 6 10 0 0 Z"/><path class="t" pathLength="1" d="M3 0 H24"/></g></g></g>
              <path class="t r" pathLength="1" d="M50 24 C44 18 44 10 50 4 C56 10 56 18 50 24 Z"/>'],

            // Tres destellos: lo de temporada.
            'destellos' => ['0 0 100 80', '
              <g transform="translate(34 40)"><g class="v" data-vida="brillo"><path class="t r r--fuerte" pathLength="1" d="M0 -22 C2.6 -5.7 5.7 -2.6 22 0 C5.7 2.6 2.6 5.7 0 22 C-2.6 5.7 -5.7 2.6 -22 0 C-5.7 -2.6 -2.6 -5.7 0 -22 Z"/></g></g>
              <g transform="translate(74 20)"><g class="v" data-vida="brillo" style="animation-delay:-1.1s"><path class="t r" pathLength="1" d="M0 -11 C1.3 -2.9 2.9 -1.3 11 0 C2.9 1.3 1.3 2.9 0 11 C-1.3 2.9 -2.9 1.3 -11 0 C-2.9 -1.3 -1.3 -2.9 0 -11 Z"/></g></g>
              <g transform="translate(80 60)"><g class="v" data-vida="brillo" style="animation-delay:-2.3s"><path class="t r" pathLength="1" d="M0 -8 C1 -2 2 -1 8 0 C2 1 1 2 0 8 C-1 2 -2 1 -8 0 C-2 -1 -1 -2 0 -8 Z"/></g></g>
              <circle class="r r--fuerte" cx="62" cy="40" r="1.8"/><circle class="r r--fuerte" cx="10" cy="64" r="1.4"/>'],

            // Tenedor y cuchillo cruzados: la cocina.
            'cubiertos' => ['0 0 96 120', '
              <g transform="translate(48 62) rotate(-20)">
                <path class="t" pathLength="1" d="M0 52 V-6"/>
                <path class="t" pathLength="1" d="M-8 -50 V-22 C-8 -10 8 -10 8 -22 V-50"/>
                <path class="t" pathLength="1" d="M-2.7 -50 V-24 M2.7 -50 V-24"/>
              </g>
              <g transform="translate(48 62) rotate(20)">
                <path class="t" pathLength="1" d="M0 52 V-4"/>
                <path class="t r" pathLength="1" d="M0 -4 C-3 -4 -7 -10 -7 -24 C-7 -38 -2 -48 3 -52 V-4 Z"/>
              </g>
              <g transform="translate(82 22)"><g class="v" data-vida="brillo"><path class="r r--fuerte" d="M0 -9 C1.2 -2.3 2.3 -1.2 9 0 C2.3 1.2 1.2 2.3 0 9 C-1.2 2.3 -2.3 1.2 -9 0 C-2.3 -1.2 -1.2 -2.3 0 -9 Z"/></g></g>'],

            // Vela encendida: la mesa reservada.
            'vela' => ['0 0 64 124', '
              <g class="v" data-vida="halo" style="transform-origin:32px 30px"><circle class="r r--halo" cx="32" cy="30" r="18"/></g>
              <g class="v" data-vida="llama" style="transform-origin:32px 44px">
                <path class="t r r--fuerte" pathLength="1" d="M32 16 C39 27 41 34 32 44 C23 34 25 27 32 16 Z"/>
              </g>
              <path class="t" pathLength="1" d="M32 50 V44"/>
              <path class="t r" pathLength="1" d="M21 50 H43 V112 H21 Z"/>
              <path class="t" pathLength="1" d="M21 57 C25 57 25 66 29 66 C32 66 31 59 34 59"/>
              <path class="t" pathLength="1" d="M10 112 H54 M15 112 C16 119 48 119 49 112"/>'],

            // Ruta punteada hasta el alfiler: cómo llegar.
            'ruta' => ['0 0 124 104', '
              <path class="t t--ruta" pathLength="1" d="M8 92 C30 74 42 96 62 76 C76 62 70 46 88 40"/>
              <g class="v" data-vida="alfiler" style="transform-origin:96px 40px">
                <path class="t r r--fuerte" pathLength="1" d="M96 40 C86 28 84 22 84 16 A12 12 0 1 1 108 16 C108 22 106 28 96 40 Z"/>
                <circle class="t" pathLength="1" cx="96" cy="16" r="4.4"/>
              </g>
              <circle class="r" cx="8" cy="92" r="3.4"/>'],

            // Guirnalda de banderines: las celebraciones.
            'guirnalda' => ['0 0 200 72', '
              <path class="t" pathLength="1" d="M4 10 Q100 44 196 10"/>
              ' . implode('', array_map(static function (int $k): string {
                    $t = $k / 7;
                    $x = round(4 + 192 * $t, 1);
                    $y = round(10 + 68 * $t * (1 - $t), 1);
                    $relleno = $k % 2 ? ' r r--fuerte' : ' r';
                    return '<g transform="translate(' . $x . ' ' . $y . ')"><g class="v" data-vida="banderin" style="animation-delay:-' . ($k * 0.45) . 's"><path class="t' . $relleno . '" pathLength="1" d="M-8 0 L8 0 L0 17 Z"/></g></g>';
                }, range(1, 6))) . ''],

            // Filete con hoja central: separa sin pesar.
            'filete' => ['0 0 240 24', '
              <path class="t" pathLength="1" d="M96 12 H8"/>
              <path class="t" pathLength="1" d="M144 12 H232"/>
              <path class="t r r--fuerte" pathLength="1" d="M120 4 L127 12 L120 20 L113 12 Z"/>
              <path class="t r" pathLength="1" d="M110 12 C106 6 100 5 96 8 C100 13 105 14 110 12 Z"/>
              <path class="t r" pathLength="1" d="M130 12 C134 6 140 5 144 8 C140 13 135 14 130 12 Z"/>'],
        ];

        if (!isset($dibujos[$nombre])) {
            // Un nombre desconocido no pinta nada: un hueco se ve en la primera
            // pasada, un adorno equivocado dura años.
            return '';
        }

        [$viewBox, $cuerpo] = $dibujos[$nombre];
        $clases = trim('orn orn--' . $nombre . ($sello ? ' orn--sello' : '') . ' ' . $clase);
        $abre = '<svg class="' . htmlspecialchars($clases, ENT_QUOTES, 'UTF-8') . '"';
        $trazo = ' fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false"';

        if (!$sello) {
            return $abre . ' viewBox="' . $viewBox . '" stroke-width="1.6"' . $trazo . '>' . $cuerpo . '</svg>';
        }

        // El dibujo se encaja en un cuadrado de 120 dentro del disco de 200, y
        // el trazo se compensa por la escala: sin eso, un dibujo de viewBox
        // grande salía con líneas de medio píxel dentro del sello.
        [, , $vw, $vh] = array_map('floatval', explode(' ', $viewBox));
        $escala = min(120 / $vw, 120 / $vh);
        $w = round($vw * $escala, 2);
        $h = round($vh * $escala, 2);
        $x = round(100 - $w / 2, 2);
        $y = round(100 - $h / 2, 2);
        $grosor = round(2.4 / $escala, 2);
        $destello = static function (int $cx, int $cy, float $t, string $retraso): string {
            $a = round($t * 0.26, 2);
            return '<g transform="translate(' . $cx . ' ' . $cy . ')"><g class="v" data-vida="brillo" style="animation-delay:' . $retraso . '">'
                . '<path class="r r--fuerte" d="M0 -' . $t . ' C' . $a . ' -' . $a . ' ' . $a . ' -' . $a . ' ' . $t . ' 0 C' . $a . ' ' . $a . ' ' . $a . ' ' . $a . ' 0 ' . $t
                . ' C-' . $a . ' ' . $a . ' -' . $a . ' ' . $a . ' -' . $t . ' 0 C-' . $a . ' -' . $a . ' -' . $a . ' -' . $a . ' 0 -' . $t . ' Z"/></g></g>';
        };

        return $abre . ' viewBox="-12 -12 224 224" stroke-width="1.6"' . $trazo . '>'
            . '<circle class="orn-disco" cx="100" cy="100" r="86"/>'
            . '<g class="v" data-vida="corona" style="transform-origin:100px 100px"><circle class="orn-corona" cx="100" cy="100" r="97" pathLength="1"/></g>'
            . '<circle class="t orn-aro" pathLength="1" cx="100" cy="100" r="78"/>'
            . $destello(14, 46, 9, '0s') . $destello(190, 150, 7, '-1.3s') . $destello(168, 14, 5, '-2.2s')
            . '<svg x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" viewBox="' . $viewBox . '"'
            . ' overflow="visible" stroke-width="' . $grosor . '">' . $cuerpo . '</svg>'
            . '</svg>';
    }
}
