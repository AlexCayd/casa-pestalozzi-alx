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
 * Los cuatro ultimos son lo UNICO que distingue a las dos piezas que salen
 * de aqui — la carta de comida y la de maridaje—: mismo papel, misma banda,
 * mismas dos columnas. Services\Menu\MenuPdf los resuelve; la plantilla no sabe
 * cual esta imprimiendo, y por eso no hay un solo condicional de carta abajo.
 *
 * Diseno: la carta impresa es la landing en papel, no una pantalla de piso.
 * Antes copiaba el modo oscuro (fondo casi negro y dorado de acento), que ya
 * no existe fuera del POS: quien abre "Ver en PDF" desde la seccion del menu
 * venia de crema, verde y cafe y se encontraba otra marca.
 *
 * Ahora el papel es crema con tinta verde y acento cafe (:root de
 * src/scss/layout/_reset.scss) y la cabecera es una banda de verde de marca
 * con el acento en beige, que es justo lo que hace [data-tono="verde"] — el
 * tono de la seccion que enlaza este PDF.
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
 * Margenes uniformes en los 4 lados via .page.
 */

// Respaldos: la plantilla se incluye desde Services\Menu\MenuPdf, que siempre los
// entrega. Se declaran igual para que un include suelto imprima la carta de
// comida en vez de una banda sin rotulo.
$pdfRotulo = $pdfRotulo ?? 'MENÚ';
$pdfBajada = $pdfBajada ?? 'Una mesa italiana con alma mexicana';
$pdfVacio = $pdfVacio ?? 'No hay platillos registrados en el menú.';
$pdfTitulo = $pdfTitulo ?? 'menu-casa-pestalozzi';

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
 * Icono de Instagram del pie: el MISMO trazo que la landing
 * (views/home/_redes.php), incrustado como data URI en vez de como archivo
 * suelto para que tome el color de $paleta y no lleve un hex cocido dentro de
 * un asset.
 *
 * Se arma con width/height y viewBox coincidentes por lo mismo que $logoEscalado
 * de arriba: en Dompdf la unidad del viewBox es el pixel que se pinta.
 */
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

$iconoInstagram = 'data:image/svg+xml;base64,' . base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">'
    . '<path fill="' . $paleta['txt_mute'] . '" fill-rule="evenodd" clip-rule="evenodd" d="M3 8a5 5 0 0 1 5-5h8a5 5 0 0 1 5 5v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm5-3a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H8Zm7.597 2.214a1 1 0 0 1 1-1h.01a1 1 0 1 1 0 2h-.01a1 1 0 0 1-1-1ZM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm-5 3a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z"/>'
    . '</svg>'
);
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

        /* Margen superior de cada hoja: Dompdf repite el <thead> y le reserva
           el alto arriba de cada pagina. El de <tfoot> se retiro porque no
           reservaba nada (ver el bloque de margenes de arriba). */
        .menu-table thead .v-space td { height: 46px; padding: 0; border: none; }

        /* Banda de verde de marca: el mismo tono de la seccion que enlaza el
           PDF. Sobre el, el acento es el beige — cafe sobre verde no separa.

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
            background: <?php echo $paleta['v_bg']; ?>;
            padding: 46px 56px 40px;
            margin-bottom: -46px;
            border-bottom: 3px solid <?php echo $paleta['v_accent']; ?>;
            text-align: center;
        }
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
        .pdf-header__isologo { height: 74px; }
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
            padding: 8px 14px 12px;
        }
        .menu-row { page-break-inside: avoid; }   /* la fila no se parte entre hojas */
        /* Encabezado de categoria: versalitas sans en el cafe de acento,
           ocupando las 2 columnas. El filete va en --line-strong porque marca
           jerarquia, no separacion.

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
            padding: 22px 14px 10px;
        }
        .cat-row.first .cat-name { padding-top: 8px; }
        .cat-name__texto {
            font-family: "Montserrat", sans-serif;
            font-weight: bold;
            color: <?php echo $paleta['txt_strong']; ?>;
            font-size: 12px;
            letter-spacing: 3px;
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
        .dish-name {
            font-family: "Playfair Display", serif;
            font-size: 14px;
            line-height: 1.25;
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
            color: <?php echo $paleta['accent']; ?>;
            text-align: right;
            white-space: nowrap;
            width: 1%;
            vertical-align: bottom;
        }
        /* Regular y no Light: impresa a 10px la Light se deshilacha, y mas con
           la luz de un comedor de noche. El manual guarda la light para UN
           parrafo destacado, no para el cuerpo de la carta. */
        .dish-desc {
            font-family: "Montserrat", sans-serif;
            font-weight: normal;
            color: <?php echo $paleta['txt_mute']; ?>;
            font-size: 10px;
            line-height: 1.45;
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
           la banda que @page reservo. El `height` la llena entera y el
           `background` la pinta: el fondo de <body> tampoco llega ahi —termina
           donde termina la caja de contenido— y sin el la hoja salia con una
           franja blanca al pie.

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
            background: <?php echo $paleta['bg']; ?>;
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
        .foot-marca .ig {
            width: 8px;
            height: 8px;
            /* baseline y no middle: con el sello de 22px mandando en la linea,
               middle centraba el icono en una caja mucho mas alta que el texto y
               quedaba flotando. Dompdf ignora vertical-align en longitudes, asi
               que la unica palanca es la palabra clave. */
            vertical-align: baseline;
            margin-right: 2px;
        }
        /* Linea 2 · avisos. Es letra chica de verdad: va en --txt-faint, el
           texto mas tenue que la casa admite sin bajar de AA. */
        .foot-legal {
            font-size: 7.2px;
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
    </style>
</head>
<body>
    <?php /* La cabecera va antes de .page y fuera de la tabla: es lo que la
             deja sangrar de canto a canto. Tambien se imprime cuando no hay
             platillos — una hoja con la marca y un aviso, no un aviso suelto. */ ?>
    <div class="pdf-header">
        <p class="pdf-header__rotulo"><?php echo htmlspecialchars($pdfRotulo); ?></p>
        <?php if ($isologo !== '') : ?>
            <img class="pdf-header__isologo" src="<?php echo $isologo; ?>" alt="Casa Pestalozzi">
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
        <?php else : ?>
            <table class="menu-table">
                <!-- Espaciador repetido: margen superior en cada hoja -->
                <thead><tr class="v-space"><td colspan="2"></td></tr></thead>
                <tbody>
                    <?php foreach ($categorias as $i => $categoria) : ?>
                        <!-- Nombre de la categoria (2 columnas, cafe de acento) -->
                        <tr class="cat-row<?php echo $i === 0 ? ' first' : ''; ?>">
                            <td class="cat-name" colspan="2">
                                <span class="cat-name__texto"><?php echo htmlspecialchars($categoria['nombre'] ?? ''); ?></span>
                                <table class="cat-filete"><tr><td class="linea"></td><td class="rombo"><img src="<?php echo $rombo; ?>" alt=""></td><td class="linea"></td></tr></table>
                            </td>
                        </tr>
                        <?php foreach (array_chunk($categoria['platillos'], 2) as $fila) : ?>
                            <tr class="menu-row">
                                <?php foreach ($fila as $platillo) : ?>
                                    <?php
                                    $desc = trim((string) ($platillo->descripcion ?? ''));
                                    $nombre = (string) ($platillo->nombre ?? '');
                                    // 28 caracteres caben en un renglon de la
                                    // columna con su precio al lado.
                                    $corto = mb_strlen($nombre) <= 28;
                                    ?>
                                    <td>
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
                                    <td></td>
                                <?php endif; ?>
                            </tr>
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
        <p class="foot-marca">casapestalozzi.com<?php if ($logoCasa !== '') : ?> <span class="sep">-</span> <img class="logo" src="<?php echo $logoCasa; ?>" alt="Casa Pestalozzi"> <span class="sep">-</span><?php endif; ?> <img class="ig" src="<?php echo $iconoInstagram; ?>" alt="">@casapestalozzi</p>
        <p class="foot-legal">*La ingesta de productos crudos es responsabilidad del consumidor &nbsp; *Algunos de nuestros productos pueden contener alérgenos. Notifique si presenta duda</p>
        <p class="foot-dir">José Enrique Pestalozzi 1250, Col. Del Valle, Benito Juárez</p>
        <p class="foot-tel">55-5604-5603 &nbsp; 56-1481-8297</p>
    </div>
</body>
</html>
