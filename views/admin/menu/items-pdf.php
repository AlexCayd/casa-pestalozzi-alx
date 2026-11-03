<?php
/**
 * Plantilla HTML que Dompdf convierte a PDF.
 * Recibe:
 *   - $categorias: array de grupos; cada uno es
 *                  ['nombre' => string, 'platillos' => Menu[]].
 *   - $generado:   string con la fecha/hora de generacion (no se muestra)
 *   - $fontsDir:   ruta absoluta (con / ) a public/build/fonts para @font-face
 *   - $logoRuta:   ruta absoluta al logo.svg de la casa, para el pie
 *   - $pdfRotulo:  rotulo de la banda, en caja alta (MENU / MARIDAJE)
 *   - $pdfBajada:  la linea bajo el wordmark
 *   - $pdfVacio:   aviso cuando la carta no tiene ni un registro
 *   - $pdfTitulo:  <title> del documento
 *
 *   - $pdfCierre:  la despedida al pie de la ultima seccion
 *   - $pdfAvisos:  ['inicio' => [...], 'final' => [...]], los avisos del
 *                  restaurante (Configuracion -> Avisos de la carta); cada uno
 *                  ['titulo' => string, 'html' => string]. El html ya viene
 *                  escapado de Services\Configuration\AvisosCarta::html():
 *                  aqui se imprime tal cual y el titulo se escapa.
 *
 * Los cinco ultimos son lo UNICO que distingue a las dos piezas que salen
 * de aqui — la carta de comida y la de maridaje—: mismo papel, misma banda,
 * mismas dos columnas. Services\Menu\MenuPdf los resuelve; la plantilla no sabe
 * cual esta imprimiendo, y por eso no hay un solo condicional de carta abajo.
 *
 * Diseno: la carta impresa es la landing en papel, no una pantalla de piso.
 * Antes copiaba el modo oscuro (fondo casi negro y dorado de acento), que ya
 * no existe fuera del POS: quien abre "Ver en PDF" desde la seccion del menu
 * venia de crema y verde y se encontraba otra marca.
 *
 * Ahora el papel es crema con tinta verde y filetes de oro (:root de
 * src/scss/layout/_reset.scss) y la cabecera es una banda de verde de marca
 * con el acento en oro claro, que es justo lo que hace [data-tono="verde"]
 * — el tono de la seccion que enlaza este PDF.
 *
 * Titulos con serifas: la landing usa The Seasons y TT Wellingtons, pero no
 * hay .ttf de escritorio de las dos en el repo y Dompdf no lee .woff2, asi
 * que aqui siguen Playfair Display y Montserrat hasta que lleguen.
 *
 * El isologo NO se escribe con la fuente: Dompdf no aplica el kerning de
 * KudosKaps y el wordmark salia desarmado («CA SA PEST A L O Z Z I»). Va como
 * trazos (_isologo.svg, generado por scripts/isologo-pdf.py desde el .otf),
 * tenido con la paleta como el sello del pie.
 *
 * El papel es papel: textura de algodon en todas las hojas, lino en la banda
 * de la portada (el mantel de la casa) y un marco de doble filete de oro que
 * enmarca cada hoja como una carta impresa. Las texturas las genera
 * Services\Menu\TexturaPdf con el color de la paleta vigente; aqui solo se
 * reparten en losetas (ver $losetas).
 *
 * Margenes uniformes en los 4 lados via .page.
 */

// Respaldos: la plantilla se incluye desde Services\Menu\MenuPdf, que siempre los
// entrega. Se declaran igual para que un include suelto imprima la carta de
// comida en vez de una banda sin rotulo.
$pdfRotulo = $pdfRotulo ?? 'MENÚ';
$pdfBajada = $pdfBajada ?? 'Una mesa italiana con alma mexicana';
$pdfVacio = $pdfVacio ?? 'No hay platillos registrados en el menú.';
$pdfTitulo = $pdfTitulo ?? 'menu-casa-pestalozzi';
$pdfCierre = $pdfCierre ?? 'Buon appetito';
$pdfAvisos = $pdfAvisos ?? ['inicio' => [], 'final' => []];
$avisosInicio = $pdfAvisos['inicio'] ?? [];
$avisosFinal = $pdfAvisos['final'] ?? [];

/**
 * La paleta llega resuelta de Services\Configuration\PaletaLanding::paraPdf().
 *
 * Dompdf no entiende custom properties ni color-mix, asi que los roles de la
 * capa 3 vienen ya mezclados contra el fondo de su ambito: cada valor es lo que
 * computa el navegador en la landing, no un color elegido a ojo. Antes era una
 * tabla de hex escrita aqui a mano; salio al servicio cuando la paleta se hizo
 * editable desde Configuracion, para que la carta impresa siga a la landing.
 *
 * Claves: verde, oro, beige, crema (capa 1); bg, txt, txt_strong, txt_mute,
 * txt_faint, accent, line_soft, line_strong (papel crema, :root); v_bg, v_txt,
 * v_txt_mute, v_accent (la banda verde, [data-tono="verde"]).
 */
$paleta = \Services\Configuration\PaletaLanding::paraPdf();

/**
 * Logotipo de la casa para el pie, reescalado y tintado desde el logo.svg
 * original — una sola fuente de verdad: si cambia la marca, cambia el PDF.
 *
 * Reescribe las COORDENADAS de los trazos en vez de declarar un tamano, porque
 * php-svg-lib (el motor SVG de Dompdf) dibuja los trazos en unidades crudas del
 * viewBox e ignora width, height y el CSS. El logo.svg de la casa mide 2993
 * unidades, asi que salia a 82 mm pasara lo que pasara: con el tamano en mm o
 * en px se pintaba gigante, y con el viewBox reducido o un transform:scale se
 * reservaba la caja y no se pintaba nada. Escaladas las coordenadas, la unidad
 * cruda YA ES el pixel de destino y las dos medidas coinciden.
 *
 * Solo es seguro porque los 42 trazos usan M, m, l, c y z: todos sus numeros
 * son coordenadas o incrementos y escalan igual. Un arco (A) traeria banderas y
 * un angulo que NO deben multiplicarse; si el logo cambia y aparece uno, esto
 * hay que rehacerlo.
 */
$logoEscalado = function (string $ruta, float $altoPx, string $color, string $regla = 'evenodd'): string {
    if (!is_readable($ruta)) {
        return '';
    }

    $svg = (string) file_get_contents($ruta);

    if (!preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $svg, $vb)
        || !preg_match_all('/\sd="([^"]*)"/', $svg, $trazos)) {
        return '';
    }

    $k = $altoPx / (float) $vb[2];
    $anchoPx = round((float) $vb[1] * $k, 2);
    $d = '';

    foreach ($trazos[1] as $trazo) {
        $d .= '<path d="' . preg_replace_callback(
            '/-?\d*\.?\d+/',
            static fn (array $n): string => rtrim(rtrim(number_format((float) $n[0] * $k, 4, '.', ''), '0'), '.'),
            $trazo
        ) . '"/>';
    }

    return 'data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="' . $anchoPx . '" height="' . $altoPx . '"'
        . ' viewBox="0 0 ' . $anchoPx . ' ' . $altoPx . '"'
        . ' fill="' . $color . '" fill-rule="' . $regla . '">' . $d . '</svg>'
    );
};

// El isologo de la portada: CASA sobre PESTALOZZI, en la tinta clara de la
// banda verde. 74 px de alto dan unos 280 de ancho, el que tiene en el hero a
// escala de hoja.
$isologo = $logoEscalado(__DIR__ . '/_isologo.svg', 74, $paleta['v_txt'], 'nonzero');
// El sello entre los dos filetes de oro, como el medallon del isologo del hero.
$selloFilete = $logoEscalado($logoRuta ?? '', 26, $paleta['v_accent']);
// El mismo sello al cierre de la carta, en el oro de los filetes del papel.
$selloCierre = $logoEscalado($logoRuta ?? '', 26, $paleta['oro']);

// El rombo del filete de cada seccion, dibujado: como caracter (U+25C6) salia
// diminuto y por debajo de la linea, porque Montserrat no lo trae y Dompdf lo
// tomaba de otra cara con otra linea base.
$rombo = 'data:image/svg+xml;base64,' . base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8">'
    . '<path fill="' . $paleta['oro'] . '" d="M4 0 L8 4 L4 8 L0 4 Z"/></svg>'
);

// $240 y no $240.00: los centavos sólo cuando los hay. Es lo que hace la carta
// de la landing (menu.js) y lo que se lee en una carta impresa.
$precio = static function ($valor): string {
    $n = (float) $valor;
    return '$' . number_format($n, floor($n) == $n ? 0 : 2);
};

// 22px es donde el sello todavia se lee: por debajo, el monograma se empasta
// contra el oval y queda una mancha.
$logoCasa = $logoEscalado($logoRuta ?? '', 22, $paleta['oro']);


/**
 * Medidas de la hoja A4 en px de Dompdf (96 ppp: 1px = 0.75pt). Las capas
 * fijas se miden contra el PAPEL y no contra la caja de contenido, porque son
 * lo unico que tiene que llegar a los cuatro cantos.
 */
$hojaAncho = 794;
$hojaAlto = 1123;

/**
 * Reparte una textura en losetas absolutas que cubren $ancho x $alto.
 *
 * Cada loseta se pinta a la MITAD de su tamano en pixeles (192 ppp, el doble
 * de lo que Dompdf daria a un background-image) y 1px mas ancha y alta que su
 * paso: los visores de PDF dejan una linea de fondo entre dos imagenes que
 * solo se tocan, y el solape la tapa. La misma data URI se incrusta una sola
 * vez aunque se repita en todas las hojas.
 */
$losetas = static function (string $uri, int $ancho, int $alto, int $paso): string {
    if ($uri === '') {
        return '';
    }
    $html = '';
    for ($y = 0; $y < $alto; $y += $paso) {
        for ($x = 0; $x < $ancho; $x += $paso) {
            $html .= '<img class="loseta" src="' . $uri . '" alt="" style="left:' . $x . 'px;top:' . $y
                . 'px;width:' . ($paso + 1) . 'px;height:' . ($paso + 1) . 'px">';
        }
    }
    return $html;
};

$papel = \Services\Menu\TexturaPdf::papel($paleta['bg']);
$lino = \Services\Menu\TexturaPdf::lino($paleta['v_bg']);
$pasoPapel = intdiv(\Services\Menu\TexturaPdf::LADO_PAPEL, 2);
$pasoLino = intdiv(\Services\Menu\TexturaPdf::LADO_LINO, 2);

// Alto fijo de la banda de portada, del canto al filete doble: el lino se
// reparte en losetas y necesita saber cuanto cubrir. Lo que hay dentro
// (rotulo, isologo, filete y frase) queda con el mismo aire arriba —hasta el
// marco— que abajo; si se anade una linea, este numero sube con ella. Dompdf
// aplica `height` a la caja de CONTENIDO aunque el reset pida border-box, asi
// que el CSS le resta el relleno superior y el filete.
$bandaAlto = 250;
$bandaRelleno = 48;
$bandaFilete = 3;

/**
 * La rama de olivo del hero (views/home/_hero.php, .hero__rama), en trazo de
 * oro claro sobre el lino. Va a los lados de CASA, en el hueco que la palabra
 * deja sobre PESTALOZZI, como en la portada de la web.
 *
 * Aqui SI vale un transform: php-svg-lib aplica el atributo `transform` de un
 * <g> (lo que ignora es el viewBox y el tamano del <svg>, ver $logoEscalado).
 * Asi que el lienzo se declara ya al tamano de destino y el dibujo, en sus
 * unidades del hero, se escala dentro; la derecha es la misma rama en espejo.
 */
$rama = static function (bool $espejo, string $trazo, string $hoja, string $fruto): string {
    $escala = 0.5;
    $ancho = 140 * $escala;
    $alto = 56 * $escala;
    $hojaD = 'M0 0 C5 -6 15 -7 21 0 C15 7 5 6 0 0 Z';
    $hojas = [[116, 32, -148], [98, 33, 150], [78, 32, -150], [58, 30, 152], [38, 27, -155]];
    $g = '<path d="M136 30 C104 34 66 32 6 22" fill="none" stroke="' . $trazo . '" stroke-width="2" stroke-linecap="round"/>';
    foreach ($hojas as [$x, $y, $giro]) {
        $g .= '<g transform="translate(' . $x . ' ' . $y . ') rotate(' . $giro . ')"><path d="' . $hojaD
            . '" fill="' . $hoja . '" stroke="' . $trazo . '" stroke-width="1.6" stroke-linejoin="round"/></g>';
    }
    $g .= '<g transform="translate(8 22) rotate(-172)"><path d="M0 0 C4 -5 12 -5 17 0 C12 5 4 5 0 0 Z" fill="'
        . $hoja . '" stroke="' . $trazo . '" stroke-width="1.6" stroke-linejoin="round"/></g>';
    $g .= '<path d="M70 33 C70 37 69 39 68 41" fill="none" stroke="' . $trazo . '" stroke-width="1.4" stroke-linecap="round"/>';
    $g .= '<ellipse cx="67" cy="45" rx="3.4" ry="4.6" fill="' . $fruto . '" stroke="' . $trazo . '" stroke-width="1.4"/>';

    $transformacion = $espejo
        ? 'matrix(' . -$escala . ' 0 0 ' . $escala . ' ' . $ancho . ' 0)'
        : 'scale(' . $escala . ')';

    return 'data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="' . $ancho . '" height="' . $alto . '" viewBox="0 0 '
        . $ancho . ' ' . $alto . '"><g transform="' . $transformacion . '">' . $g . '</g></svg>'
    );
};
$ramaIzq = $rama(false, $paleta['v_accent'], $paleta['v_bg'], $paleta['oro']);
$ramaDer = $rama(true, $paleta['v_accent'], $paleta['v_bg'], $paleta['oro']);

// Rombos de las esquinas del marco: los mismos del filete de seccion.
$romboMarco = 'data:image/svg+xml;base64,' . base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 9 9">'
    . '<path fill="' . $paleta['oro'] . '" d="M4.5 0 L9 4.5 L4.5 9 L0 4.5 Z"/></svg>'
);
// Una fila por aviso. El titulo se escapa aqui; el cuerpo llega ya escapado
// y traducido de AvisosCarta::html(). `$ultimo` marca el ultimo de su lugar:
// el del inicio deja aire a la primera seccion y el del final no se separa
// de la despedida (ver .aviso-row.ultimo).
$avisoFila = static function (array $aviso, string $lugar, bool $primero, bool $ultimo = false) use ($rombo): string {
    return '<tr class="aviso-row aviso-row--' . $lugar . ($primero ? ' first' : '') . ($ultimo ? ' ultimo' : '') . '"><td colspan="2">'
        . '<div class="aviso">'
        . '<p class="aviso__rotulo">AVISO</p>'
        . '<p class="aviso__titulo">' . htmlspecialchars((string) ($aviso['titulo'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<table class="cat-filete aviso__filete"><tr><td class="linea"></td><td class="rombo"><img src="' . $rombo . '" alt=""></td><td class="linea"></td></tr></table>'
        . '<div class="aviso__cuerpo">' . (string) ($aviso['html'] ?? '') . '</div>'
        . '</div></td></tr>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pdfTitulo); ?></title>
    <style>
        @font-face {
            font-family: "Playfair Display";
            src: url("<?php echo $fontsDir; ?>/PlayfairDisplay-Regular.ttf") format("truetype");
            font-weight: normal;
        }
        /* Fuente del logotipo de marca. Se usa la version .ttf (contornos
           TrueType) porque Dompdf no incrusta el .otf original (contornos CFF)
           y caeria a la fuente serif por defecto. */
        @font-face {
            font-family: "KudosKaps";
            src: url("<?php echo $fontsDir; ?>/KudosKapsOneNF.ttf") format("truetype");
            font-weight: normal;
        }
        @font-face {
            font-family: "Montserrat";
            src: url("<?php echo $fontsDir; ?>/Montserrat-Regular.ttf") format("truetype");
            font-weight: normal;
        }
        @font-face {
            font-family: "Montserrat";
            src: url("<?php echo $fontsDir; ?>/Montserrat-Light.ttf") format("truetype");
            font-weight: 300;
        }
        @font-face {
            font-family: "Montserrat";
            src: url("<?php echo $fontsDir; ?>/Montserrat-Bold.ttf") format("truetype");
            font-weight: bold;
        }
        @font-face {
            font-family: "Montserrat";
            src: url("<?php echo $fontsDir; ?>/Montserrat-Italic.ttf") format("truetype");
            font-style: italic;
        }

        /* Margenes de hoja. Aqui habia dos creencias equivocadas que costaron
           medio dia, asi que van escritas:

           1 · NO es el background-color de html/body lo que anula @page. Es el
               reset: en Dompdf el selector universal alcanza TAMBIEN la caja de
               @page, asi que `* { margin: 0 }` le borraba el margen a la hoja y
               ningun @page posterior lo recuperaba (declararlo despues tampoco
               sirve: el universal siempre gana). Por eso el reset ya no toca
               margin y lo pone elemento por elemento.
           2 · Dompdf NO repite el <tfoot>. Repite el <thead> y ademas le
               reserva la altura arriba de cada hoja —de ahi sale el margen
               superior—, pero del tfoot no reservaba nada: el contenido corria
               hasta el canto inferior del papel. Nunca hubo margen inferior; no
               se notaba porque no habia nada impreso ahi abajo.

           Resultado: el margen inferior lo pone @page (136px, el hueco del pie
           fijo), los laterales el padding de .page y el superior el espaciador
           de <thead>. El @page va sin margen arriba ni a los lados a proposito:
           es lo que deja sangrar la banda de la cabecera. */
        @page { margin: 0 0 136px 0; }
        * { padding: 0; box-sizing: border-box; }
        h1, h2, h3, h4, p, table, ul, ol, li { margin: 0; }

        html, body {
            font-family: "Montserrat", sans-serif;
            color: <?php echo $paleta['txt']; ?>;
            background-color: <?php echo $paleta['bg']; ?>;
            font-size: 11px;
        }

        /* Margen lateral (constante en cada hoja) */
        .page {
            padding: 0 42px;
        }

        /* El papel: una capa fija con la textura en losetas, debajo de todo.
           Fija porque es el unico mecanismo de Dompdf que repite algo en cada
           hoja sin meterlo en el flujo; z-index negativo para que se pinte
           antes que el contenido (con `auto`, un elemento posicionado va
           DESPUES del flujo y taparia la carta). Se mide contra el papel
           entero: arriba y a la izquierda la caja de contenido empieza en el
           canto (el @page no tiene margen ahi), y hacia abajo basta con que
           sobre — lo que cae fuera de la hoja no se imprime. */
        .pdf-papel {
            position: fixed;
            left: 0;
            top: 0;
            width: <?php echo $hojaAncho; ?>px;
            height: <?php echo $hojaAlto; ?>px;
            z-index: -2;
        }
        .loseta {
            position: absolute;
            display: block;
        }

        /* El marco: doble filete de oro a 18px del canto, con un rombo en cada
           esquina, en todas las hojas. Es lo que convierte la hoja en una carta
           impresa y no en un documento. En la portada cruza la banda verde, y
           por encima de ella se lee como el ribete de un mantel; para eso el
           z-index explicito: con `auto` Dompdf pintaba los elementos fijos
           antes que la banda (que es relativa) y el lino lo tapaba. Ningun
           texto llega a el: las columnas arrancan a 56px y el pie termina
           antes. */
        .pdf-marco {
            position: fixed;
            z-index: 10;
            left: 18px;
            top: 18px;
            width: <?php echo $hojaAncho - 36; ?>px;
            height: <?php echo $hojaAlto - 36; ?>px;
            border: 1px solid <?php echo $paleta['oro']; ?>;
        }
        .pdf-marco__interior {
            position: absolute;
            left: 3px;
            top: 3px;
            width: <?php echo $hojaAncho - 36 - 8; ?>px;
            height: <?php echo $hojaAlto - 36 - 8; ?>px;
            border: 0.5px solid <?php echo $paleta['line_soft']; ?>;
        }
        .pdf-marco__rombo {
            position: absolute;
            width: 9px;
            height: 9px;
        }

        /* Margen superior de cada hoja: Dompdf repite el <thead> y le reserva
           el alto arriba de cada pagina. El de <tfoot> se retiro porque no
           reservaba nada (ver el bloque de margenes de arriba). */
        .menu-table thead .v-space td { height: 46px; padding: 0; border: none; }

        /* Banda de verde de marca: el mismo tono de la seccion que enlaza el
           PDF. Sobre el, el acento es el oro claro (v_accent): el oro pleno
           sobre verde no llega a AA como texto.

           Va a sangre por los tres cantos que toca, y por eso vive FUERA de
           .page y fuera de la tabla, como hija directa de <body>: asi ocupa el
           ancho de la hoja sin pelearse con el padding de .page ni con el
           espaciador de <thead>. Se intento primero dentro de la celda con
           margenes negativos y Dompdf solo aplica una parte del tiro
           vertical — dejaba una franja de crema de 37px sobre la banda.

           El margin-bottom negativo cancela el espaciador de <thead> en la
           hoja 1: ese espaciador es el margen superior que Dompdf repite en
           TODAS las hojas, y aqui sobra — el aire de la cabecera ya lo pone la
           propia banda, asi que sin cancelarlo quedaba un hueco de casi 100px
           entre el rotulo y la primera categoria. Solo afecta a la hoja 1; de
           la 2 en adelante no hay cabecera y el espaciador sigue intacto.

           El aire vertical (58 arriba, 52 abajo) es lo que le da presencia:
           deja el rotulo con un margen parecido por los tres cantos, asi que
           la banda se lee como portada y no como una tira de color. */
        .pdf-header {
            position: relative;
            height: <?php echo $bandaAlto - $bandaRelleno - $bandaFilete; ?>px;
            overflow: hidden;
            background: <?php echo $paleta['v_bg']; ?>;
            padding: <?php echo $bandaRelleno; ?>px 56px 0;
            margin-bottom: -46px;
            border-bottom: <?php echo $bandaFilete; ?>px double <?php echo $paleta['v_accent']; ?>;
            text-align: center;
        }
        /* El lino, detras del contenido de la banda. Va primero en el marcado
           y con z-index negativo: asi Dompdf lo pinta antes que el rotulo y el
           isologo (ver .pdf-papel). El overflow de la banda recorta las
           losetas que sobran. */
        .pdf-header__lino {
            position: absolute;
            left: 0;
            top: 0;
            width: <?php echo $hojaAncho; ?>px;
            height: <?php echo $bandaAlto; ?>px;
            z-index: -1;
        }
        /* El isologo con sus ramas: una caja del tamano exacto del isologo
           (74px de alto, 282 de ancho) para colgar de ella las ramas en el
           hueco que CASA deja a cada lado sobre PESTALOZZI. */
        .pdf-isologo {
            position: relative;
            width: 282px;
            height: 74px;
            margin: 0 auto;
        }
        /* -3px y no mas abajo: la aceituna cuelga bajo la rama, y con el
           interlineado apretado del isologo caia a 5px de la E de PESTALOZZI
           y se leia como su tilde («PÉSTALOZZI»). Asi cuelga a la altura de la
           base de CASA y la rama queda a media asta de la palabra. */
        .pdf-isologo__rama {
            position: absolute;
            top: -3px;
            width: 70px;
            height: 28px;
        }
        .pdf-isologo__rama--izq { left: 2px; }
        .pdf-isologo__rama--der { left: 210px; }
        /* Composicion centrada, como el isologo del hero: el rotulo de la
           pieza arriba, el nombre de la casa, el filete de oro partido por el
           sello y la frase. Antes iba todo a la izquierda y el wordmark, en la
           fuente, salia desarmado. */
        .pdf-header__rotulo {
            font-family: "Montserrat", sans-serif;
            font-weight: bold;
            color: <?php echo $paleta['v_accent']; ?>;
            font-size: 9px;
            letter-spacing: 5px;
            margin-bottom: 16px;
        }
        .pdf-header__isologo { display: block; height: 74px; }
        /* Respaldo si el SVG no se pudiera leer: el nombre en la serif. */
        .pdf-header__nombre {
            font-family: "Playfair Display", serif;
            color: <?php echo $paleta['v_txt']; ?>;
            font-size: 30px;
            letter-spacing: 3px;
        }
        .pdf-filete {
            width: 300px;
            margin: 10px auto 0;
            border-collapse: collapse;
        }
        .pdf-filete td { padding: 0; vertical-align: middle; }
        .pdf-filete .linea { border-top: 1px solid <?php echo $paleta['v_accent']; ?>; }
        .pdf-filete .sello { width: 40px; text-align: center; }
        .pdf-filete .sello img { height: 26px; }
        .pdf-header .sub {
            font-family: "Playfair Display", serif;
            color: <?php echo $paleta['v_txt']; ?>;
            font-size: 14px;
            margin-top: 12px;
        }

        /* Tabla de 2 columnas (sin borde) para alinear el menu */
        .menu-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .menu-table tbody .menu-row > td {
            width: 50%;
            vertical-align: top;
            border: none;
            padding: 7px 14px 10px;
        }
        .menu-row { page-break-inside: avoid; }   /* la fila no se parte entre hojas */
        /* Filete entre las dos columnas, como en las cartas a dos columnas: se
           corta solo en cada titulo de seccion, que ocupa las dos. */
        .menu-table tbody .menu-row > td.col-der {
            border-left: 0.5px solid <?php echo $paleta['line_soft']; ?>;
        }
        /* Encabezado de categoria, ocupando las 2 columnas.

           Las DOS reglas de salto hacen falta y no son la misma:
           page-break-inside impide que el rotulo se parta por dentro, que es
           poco probable con una linea; page-break-after es la que impide que se
           quede solo al pie de una hoja con sus platillos en la siguiente. Sin
           la segunda, "PLATOS FUERTES" cerraba la hoja 3 y "JUGOS & SMOOTHIES"
           la 5, cada uno anunciando algo que no estaba a la vista. */
        .cat-row { page-break-inside: avoid; page-break-after: avoid; }
        /* Encabezado de seccion centrado, como los titulos de la landing: el
           nombre en versalitas y debajo el filete con el rombo de oro (el del
           adorno «filete» de la carta en la web). */
        .cat-name {
            border: none;
            text-align: center;
            padding: 20px 14px 8px;
        }
        .cat-row.first .cat-name { padding-top: 8px; }
        /* La seccion en la serif, en versales espaciadas: con el mismo cuerpo
           y la misma sans que el pie, «DESAYUNOS» pesaba lo que una nota. Ahora
           la jerarquia es seccion (serif 17, versales) > platillo (serif 14,
           caja baja) > descripcion (sans 10), sin un solo cambio de color. */
        .cat-name__texto {
            font-family: "Playfair Display", serif;
            font-weight: normal;
            color: <?php echo $paleta['txt_strong']; ?>;
            font-size: 17px;
            letter-spacing: 3.5px;
            text-transform: uppercase;
        }
        .cat-filete {
            width: 150px;
            margin: 7px auto 0;
            border-collapse: collapse;
        }
        .cat-filete td { padding: 0; vertical-align: middle; }
        .cat-filete .linea { border-top: 1px solid <?php echo $paleta['oro']; ?>; }
        .cat-filete .rombo {
            width: 18px;
            text-align: center;
            line-height: 0;
        }
        .cat-filete .rombo img { width: 8px; height: 8px; vertical-align: middle; }

        /* Un platillo: nombre, puntos guia y precio en un renglon; la
           descripcion debajo. Sin filete entre platillos: los puntos guia ya
           ordenan la columna, como en una carta impresa. */
        .cell-inner { padding-bottom: 4px; }
        .dish-line {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .dish-line td { border: none; padding: 0; }
        /* 0.84 da 1.23 de verdad: la caja de Playfair mide 1.47 em en Dompdf
           (ver .dish-desc). Con 1.25 un nombre de dos renglones salia a 1.83,
           mas suelto que su propia descripcion, y «Camarones» se leia como
           otro platillo. El precio lleva el mismo: con interlineados
           distintos, el `bottom` de las dos celdas no deja las bases a nivel. */
        .dish-name {
            font-family: "Playfair Display", serif;
            font-size: 14px;
            line-height: 0.84;
            color: <?php echo $paleta['txt_strong']; ?>;
            vertical-align: bottom;
        }
        /* Nombre corto: se ciñe a su texto y los puntos arrancan justo detras.
           Uno largo puede partir en dos renglones, y entonces los puntos tienen
           un ancho fijo y caen a la altura del ultimo. */
        .dish-name--corto { white-space: nowrap; width: 1%; }
        .dish-puntos {
            vertical-align: bottom;
            padding: 0 6px 4px !important;
        }
        .dish-puntos div { border-bottom: 1px dotted <?php echo $paleta['line_strong']; ?>; height: 1px; }
        .dish-puntos--fijo { width: 36px; }
        .dish-price {
            font-family: "Playfair Display", serif;
            font-size: 14px;
            line-height: 0.84;
            color: <?php echo $paleta['accent']; ?>;
            text-align: right;
            white-space: nowrap;
            width: 1%;
            vertical-align: bottom;
        }
        /* Regular y no Light: impresa a 10px la Light se deshilacha, y mas con
           la luz de un comedor de noche. El manual guarda la light para UN
           parrafo destacado, no para el cuerpo de la carta.

           El interlineado NO es el que se lee: Dompdf multiplica line-height
           por el alto de la CAJA de la fuente, no por el cuerpo
           (FrameDecorator\Text::get_margin_height), y la de Montserrat mide
           1.34 em. Con 1.45 salia a 1.94 —la descripcion a doble espacio,
           despegada de su platillo y una hoja de mas al final—; 1.12 da 1.5
           de verdad. Al cambiar de cara, medir el paso de renglon en el PDF. */
        .dish-desc {
            font-family: "Montserrat", sans-serif;
            font-weight: normal;
            color: <?php echo $paleta['txt_mute']; ?>;
            font-size: 10px;
            line-height: 1.12;
            margin-top: 3px;
            padding-right: 40px;
        }
        /* Pie de pagina repetido en TODAS las hojas.

           position:fixed es el unico mecanismo de Dompdf que repite un bloque
           por hoja sin meterlo en el flujo. A cambio no empuja nada, asi que el
           hueco se lo reserva el margen inferior de @page; sin el, la ultima
           fila de platillos de cada hoja se imprimia encima de la leyenda.

           Y de ahi salen las tres rarezas de este bloque, que son la misma:
           en Dompdf un elemento fijo se posiciona contra la CAJA DE CONTENIDO,
           no contra el papel. Con bottom:26px el pie subia 136px y volvia a
           montarse sobre los platillos; con bottom:-136px baja justo a ocupar
           la banda que @page reservo. El fondo de <body> no llega ahi
           —termina donde termina la caja de contenido—; lo cubre la capa de
           papel (.pdf-papel), que mide la hoja entera, y por eso el pie va
           sin fondo propio: uno liso taparia la textura en esa franja.

           El filete no va en esta caja sino en el primer renglon, y los 18px de
           padding superior son los que lo separan del contenido: pegado al
           borde de la banda, una fila de platillos que terminara justo ahi lo
           habria tocado. Los 56px laterales son los mismos con los que arrancan
           las columnas (42 de .page + 14 de las celdas), asi que el filete cae
           a plomo con las reglas de categoria.

           Va centrado, al reves que el resto del documento: es lo que lo
           separa de las dos columnas de platillos y lo hace leer como pie y no
           como una categoria mas. */
        .pdf-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -136px;
            height: 136px;
            text-align: center;
            padding: 18px 56px 0;
        }
        /* Regular por la misma razon que .dish-desc: a 7-8.5px la Light no
           aguanta la impresion. */
        .pdf-footer p {
            font-family: "Montserrat", sans-serif;
            font-weight: normal;
            color: <?php echo $paleta['txt_mute']; ?>;
        }
        /* Linea 1 · dominio, marca y red */
        .foot-marca {
            border-top: 1px solid <?php echo $paleta['line_soft']; ?>;
            padding-top: 9px;
            font-size: 8.5px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
        }
        .foot-marca .sep { color: <?php echo $paleta['line_strong']; ?>; }
        /* El sello de la casa. Manda el alto de este renglon, asi que los
           separadores y el dominio se alinean a su centro. */
        .foot-marca .logo {
            height: 22px;
            vertical-align: middle;
            margin: 0 1px;
        }
        /* Linea 2 · avisos. Es letra chica de verdad: va en --txt-faint, el
           texto mas tenue que la casa admite sin bajar de AA. */
        .foot-legal {
            /* 8px (6pt) y no 7.2: por debajo de 6pt la letra chica deja de
               leerse impresa, y es justo la que avisa de alergenos. */
            font-size: 8px;
            line-height: 1.5;
            color: <?php echo $paleta['txt_faint']; ?>;
            margin-top: 5px;
        }
        /* Lineas 3 y 4 · domicilio y telefonos */
        .foot-dir {
            font-size: 8px;
            letter-spacing: 0.6px;
            margin-top: 5px;
        }
        .foot-tel {
            font-size: 8px;
            letter-spacing: 1px;
            margin-top: 2px;
        }

        /* El margin-bottom negativo de la banda cancela el espaciador del
           <thead>, y sin platillos no hay tabla que lo ponga: el aviso subia
           46px y se imprimia ENCIMA del verde, ilegible. Se los devuelve el
           padding superior de aqui — el unico caso donde el aviso es todo el
           contenido de la hoja. */
        .empty {
            padding: 76px 30px 30px;
            text-align: center;
            color: <?php echo $paleta['txt_faint']; ?>;
            font-style: normal;
        }

        /* La despedida, al pie de la ultima seccion: el sello de la casa y una
           linea en la voz italiana de los rotulos de la landing. Cierra la
           carta en vez de dejar la ultima hoja en un platillo suelto. */
        .cierre-row { page-break-inside: avoid; }
        /* La ultima fila de platillos no se separa del cierre: sin esto, una
           carta justa de hojas dejaba la despedida sola en una hoja nueva. Es
           la misma regla que mantiene un titulo de seccion con sus platillos. */
        .menu-row--ultima { page-break-after: avoid; }
        .cierre-row td {
            text-align: center;
            padding: 34px 14px 8px;
            border: none;
        }
        .menu-table tbody .menu-row > td.celda-cierre {
            text-align: center;
            vertical-align: middle;
        }
        /* El mismo filete partido por el sello que la portada, en oro sobre el
           papel: la carta se cierra con el gesto con que se abrio. */
        .cierre-filete {
            width: 220px;
            margin: 0 auto;
            border-collapse: collapse;
        }
        .cierre-filete td { padding: 0; border: none; vertical-align: middle; }
        .cierre-filete .linea { border-top: 1px solid <?php echo $paleta['oro']; ?>; }
        .cierre-filete .sello { width: 40px; text-align: center; }
        .cierre-filete .sello img { height: 26px; }
        .pdf-cierre__texto {
            font-family: "Playfair Display", serif;
            color: <?php echo $paleta['txt_strong']; ?>;
            font-size: 16px;
            letter-spacing: 1px;
            margin-top: 10px;
        }

        /* Avisos del restaurante (Configuracion -> Avisos de la carta).

           Hablan con la gramatica de un titulo de seccion, un escalon mas
           abajo: rotulo en versales espaciadas (el de la banda), el titulo en
           la serif y DEBAJO el filete de oro con el rombo, mas corto que el de
           las secciones (84 contra 150px). Asi se leen como parte de la carta y
           a la vez por debajo de «DESAYUNOS».

           Sin filetes largos. La primera version abria cada aviso con un filete
           de canto a canto y lo cerraba con otro tenue, y en la hoja se
           apilaban: bajo la banda, el filete del aviso era un tercer renglon de
           la portada; entre dos avisos, cierre y apertura se leian como una
           escalera de rayas; al pie de una hoja, el cierre caia a 24px del
           filete del pie y hacia doble raya. Ahora a un aviso lo separa el
           aire, como a una seccion de otra.

           Medida: 60% de la columna (unos 400px, ~72 caracteres de Montserrat
           a 10px). Al 78% salian renglones de 100 caracteres, demasiado para
           un texto centrado.

           Una fila por aviso con page-break-inside: un aviso no se parte entre
           dos hojas. Los del inicio van entre la portada y la primera seccion;
           los del final, entre la ultima fila y la despedida, que sigue siendo
           lo ultimo de la carta.

           Fuentes: la serif (Playfair) solo tiene Regular, asi que titulo y
           subtitulo van en normal (pedirle bold hacia que Dompdf cayera a
           Times); las negritas son Montserrat, que si trae Bold. */
        .aviso-row { page-break-inside: avoid; }
        /* El ultimo del final no se queda sin la despedida: es la misma regla
           que mantiene la ultima fila de platillos con el cierre. */
        .aviso-row--final.ultimo { page-break-after: avoid; }
        .menu-table tbody .aviso-row > td {
            border: none;
            padding: 30px 14px 0;
        }
        /* El primero, a 22px del doble filete de la banda: el mismo aire que
           deja la primera seccion cuando no hay avisos. */
        .menu-table tbody .aviso-row.first > td { padding-top: 22px; }
        /* El ultimo del inicio deja a la primera seccion el aire que una
           seccion deja a otra; sin el, «DESAYUNOS» se pegaba al aviso. */
        .menu-table tbody .aviso-row--inicio.ultimo > td { padding-bottom: 14px; }
        .menu-table tbody .aviso-row--final > td { padding-top: 34px; }
        .aviso {
            width: 60%;
            margin: 0 auto;
            text-align: center;
        }
        .aviso__rotulo {
            font-family: "Montserrat", sans-serif;
            font-weight: bold;
            color: <?php echo $paleta['accent']; ?>;
            font-size: 7.5px;
            letter-spacing: 3.5px;
        }
        .aviso__titulo {
            font-family: "Playfair Display", serif;
            font-weight: normal;
            color: <?php echo $paleta['txt_strong']; ?>;
            font-size: 15px;
            line-height: 0.95;
            margin-top: 6px;
        }
        .aviso__filete { width: 84px; margin: 8px auto 0; }
        .aviso__cuerpo { margin-top: 4px; }
        .aviso__h2 {
            font-family: "Playfair Display", serif;
            font-weight: normal;
            color: <?php echo $paleta['txt_strong']; ?>;
            font-size: 12px;
            line-height: 0.95;
            margin-top: 8px;
        }
        /* El mismo cuerpo e interlineado que la descripcion de un platillo
           (ver .dish-desc: 1.12 en Dompdf es 1.5 de verdad). */
        .aviso__p {
            font-family: "Montserrat", sans-serif;
            font-weight: normal;
            color: <?php echo $paleta['txt_mute']; ?>;
            font-size: 10px;
            line-height: 1.12;
            margin-top: 5px;
        }
        .aviso__b {
            font-family: "Montserrat", sans-serif;
            font-weight: bold;
            color: <?php echo $paleta['txt_strong']; ?>;
        }
        /* Carta vacia: los avisos van bajo el mensaje, sin la tabla de dos
           columnas que los acomoda cuando hay platillos. */
        .avisos-sueltos { width: 100%; border-collapse: collapse; }
        .avisos-sueltos .aviso-row > td { padding: 30px 14px 0; border: none; }
    </style>
</head>
<body>
    <?php /* El papel va primero: con su z-index negativo se pinta antes que
             todo lo demas en cada hoja. */ ?>
    <?php if ($papel !== '') : ?>
        <div class="pdf-papel"><?php echo $losetas($papel, $hojaAncho, $hojaAlto, $pasoPapel); ?></div>
    <?php endif; ?>
    <?php /* La cabecera va antes de .page y fuera de la tabla: es lo que la
             deja sangrar de canto a canto. Tambien se imprime cuando no hay
             platillos — una hoja con la marca y un aviso, no un aviso suelto. */ ?>
    <div class="pdf-header">
        <?php if ($lino !== '') : ?>
            <div class="pdf-header__lino"><?php echo $losetas($lino, $hojaAncho, $bandaAlto, $pasoLino); ?></div>
        <?php endif; ?>
        <p class="pdf-header__rotulo"><?php echo htmlspecialchars($pdfRotulo); ?></p>
        <?php if ($isologo !== '') : ?>
            <div class="pdf-isologo">
                <img class="pdf-header__isologo" src="<?php echo $isologo; ?>" alt="Casa Pestalozzi">
                <img class="pdf-isologo__rama pdf-isologo__rama--izq" src="<?php echo $ramaIzq; ?>" alt="">
                <img class="pdf-isologo__rama pdf-isologo__rama--der" src="<?php echo $ramaDer; ?>" alt="">
            </div>
        <?php else : ?>
            <h1 class="pdf-header__nombre">CASA PESTALOZZI</h1>
        <?php endif; ?>
        <table class="pdf-filete">
            <tr>
                <td class="linea"></td>
                <?php if ($selloFilete !== '') : ?>
                    <td class="sello"><img src="<?php echo $selloFilete; ?>" alt=""></td>
                    <td class="linea"></td>
                <?php endif; ?>
            </tr>
        </table>
        <p class="sub"><?php echo htmlspecialchars($pdfBajada); ?></p>
    </div>
    <div class="page">
        <?php if (empty($categorias)) : ?>
            <p class="empty"><?php echo htmlspecialchars($pdfVacio); ?></p>
            <?php if ($avisosInicio !== [] || $avisosFinal !== []) : ?>
                <table class="avisos-sueltos"><tbody>
                    <?php foreach (array_merge($avisosInicio, $avisosFinal) as $aviso) : ?>
                        <?php echo $avisoFila($aviso, 'inicio', false); ?>
                    <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        <?php else : ?>
            <?php
            // Con avisos al final, la despedida va en su propio renglon
            // despues de ellos: en la celda vacia de la ultima fila quedaria
            // antes de los avisos y ya no cerraria la carta.
            $cierreHtml = '<table class="cierre-filete"><tr><td class="linea"></td>'
                . ($selloCierre !== '' ? '<td class="sello"><img src="' . $selloCierre . '" alt=""></td><td class="linea"></td>' : '')
                . '</tr></table><p class="pdf-cierre__texto">' . htmlspecialchars($pdfCierre) . '</p>';
            ?>
            <table class="menu-table">
                <!-- Espaciador repetido: margen superior en cada hoja -->
                <thead><tr class="v-space"><td colspan="2"></td></tr></thead>
                <tbody>
                    <?php foreach ($avisosInicio as $a => $aviso) : ?>
                        <?php echo $avisoFila($aviso, 'inicio', $a === 0, $a === count($avisosInicio) - 1); ?>
                    <?php endforeach; ?>
                    <?php foreach ($categorias as $i => $categoria) : ?>
                        <!-- Nombre de la categoria (2 columnas) -->
                        <tr class="cat-row<?php echo $i === 0 && $avisosInicio === [] ? ' first' : ''; ?>">
                            <td class="cat-name" colspan="2">
                                <span class="cat-name__texto"><?php echo htmlspecialchars($categoria['nombre'] ?? ''); ?></span>
                                <table class="cat-filete"><tr><td class="linea"></td><td class="rombo"><img src="<?php echo $rombo; ?>" alt=""></td><td class="linea"></td></tr></table>
                            </td>
                        </tr>
                        <?php $filas = array_chunk($categoria['platillos'], 2); ?>
                        <?php foreach ($filas as $f => $fila) : ?>
                            <?php
                            $esUltima = $i === count($categorias) - 1 && $f === count($filas) - 1;
                            // Si la ultima fila trae un solo platillo, la despedida
                            // ocupa la celda vacia de al lado y no suma altura: un
                            // renglon propio bastaba para mandar a una hoja nueva
                            // el ultimo platillo de una carta justa.
                            $cierreEnCelda = $esUltima && count($fila) === 1 && $avisosFinal === [];
                            ?>
                            <tr class="menu-row<?php echo $esUltima && !$cierreEnCelda ? ' menu-row--ultima' : ''; ?>">
                                <?php foreach ($fila as $columna => $platillo) : ?>
                                    <?php
                                    $desc = trim((string) ($platillo->descripcion ?? ''));
                                    $nombre = (string) ($platillo->nombre ?? '');
                                    // 28 caracteres caben en un renglon de la
                                    // columna con su precio al lado.
                                    $corto = mb_strlen($nombre) <= 28;
                                    ?>
                                    <td<?php echo $columna === 1 ? ' class="col-der"' : ''; ?>>
                                        <div class="cell-inner">
                                            <table class="dish-line">
                                                <tr>
                                                    <td class="dish-name<?php echo $corto ? ' dish-name--corto' : ''; ?>"><?php echo htmlspecialchars($nombre); ?></td>
                                                    <td class="dish-puntos<?php echo $corto ? '' : ' dish-puntos--fijo'; ?>"><div></div></td>
                                                    <td class="dish-price"><?php echo htmlspecialchars($precio($platillo->precio ?? 0)); ?></td>
                                                </tr>
                                            </table>
                                            <?php /* Ya no hay platillo sin descripción —las bebidas
                                                     tambien la llevan desde dml_operativo.sql—, pero
                                                     productos.descripcion sigue siendo NULL-able para
                                                     aceptar catalogos historicos: si llega vacia se
                                                     omite el parrafo en vez de imprimir el hueco. */ ?>
                                            <?php if ($desc !== '') : ?>
                                                <p class="dish-desc"><?php echo htmlspecialchars($desc); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                                <?php if (count($fila) === 1) : ?>
                                    <td class="col-der<?php echo $cierreEnCelda ? ' celda-cierre' : ''; ?>"><?php echo $cierreEnCelda ? $cierreHtml : ''; ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php if ($esUltima && !$cierreEnCelda) : ?>
                                <?php foreach ($avisosFinal as $af => $aviso) : ?>
                                    <?php echo $avisoFila($aviso, 'final', false, $af === count($avisosFinal) - 1); ?>
                                <?php endforeach; ?>
                                <tr class="cierre-row"><td colspan="2"><?php echo $cierreHtml; ?></td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php /* Cuatro renglones, uno por cada salto de linea de la leyenda:
             marca, avisos, domicilio y telefonos. */ ?>
    <div class="pdf-footer">
        <?php /* Si el logo.svg no se pudiera leer, cae el segmento entero con
                 sus dos guiones: un renglon con dos separadores vacios se ve
                 peor que uno sin sello. */ ?>
        <p class="foot-marca">casapestalozzi.com<?php if ($logoCasa !== '') : ?> <span class="sep">-</span> <img class="logo" src="<?php echo $logoCasa; ?>" alt="Casa Pestalozzi"> <span class="sep">-</span><?php endif; ?> @casapestalozzi</p>
        <p class="foot-legal">*La ingesta de productos crudos es responsabilidad del consumidor &nbsp; *Algunos de nuestros productos pueden contener alérgenos. Notifique si presenta duda</p>
        <p class="foot-dir">José Enrique Pestalozzi 1250, Col. Del Valle, Benito Juárez</p>
        <p class="foot-tel">55-5604-5603 &nbsp; 56-1481-8297</p>
    </div>
    <?php /* El marco, al final y con z-index 10 (ver .pdf-marco): en la
             portada tiene que quedar encima del lino de la banda. */ ?>
    <div class="pdf-marco">
        <div class="pdf-marco__interior"></div>
        <img class="pdf-marco__rombo" src="<?php echo $romboMarco; ?>" alt="" style="left:-5px;top:-5px">
        <img class="pdf-marco__rombo" src="<?php echo $romboMarco; ?>" alt="" style="right:-5px;top:-5px">
        <img class="pdf-marco__rombo" src="<?php echo $romboMarco; ?>" alt="" style="left:-5px;bottom:-5px">
        <img class="pdf-marco__rombo" src="<?php echo $romboMarco; ?>" alt="" style="right:-5px;bottom:-5px">
    </div>
</body>
</html>
