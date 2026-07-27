<?php
/**
 * Plantilla HTML que Dompdf convierte a PDF.
 * Recibe:
 *   - $categorias: array de grupos; cada uno es
 *                  ['nombre' => string, 'platillos' => Menu[]].
 *   - $generado:   string con la fecha/hora de generacion (no se muestra)
 *   - $fontsDir:   ruta absoluta (con / ) a public/build/fonts para @font-face
 *
 * Diseno: paleta del modo oscuro del sitio (:root en app.css). Menu agrupado
 * por categoria (nombre en dorado con la fuente por defecto) y, dentro de cada
 * categoria, platillos en 2 columnas por hoja usando tablas sin borde (el
 * metodo de layout mas fiable en Dompdf). Titulos con serifas (Playfair
 * Display) y precios en dorado. Margenes uniformes en los 4 lados via .page.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
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

        /* Paleta tomada de :root (modo oscuro) en public/build/css/app.css */
        /* En Dompdf, un background-color en html/body pinta el lienzo de cada
           hoja de borde a borde PERO anula los margenes de @page. Por eso el
           @page va sin margen y los margenes uniformes se logran de otra forma:
           - laterales: padding horizontal de .page (se respeta en cada hoja).
           - superior/inferior: filas espaciadoras en <thead>/<tfoot>, que
             Dompdf REPITE al inicio y final de cada pagina, dando un margen
             constante estilo documento de Word en todas las hojas. */
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            font-family: "Montserrat", sans-serif;
            color: #ede9df;           /* --txt */
            background-color: #101213; /* --ink-2 */
            font-size: 11px;
        }

        /* Margen lateral (constante en cada hoja) */
        .page {
            padding: 0 42px;
        }

        /* Espaciadores que se repiten por hoja = margen superior/inferior */
        .menu-table thead .v-space td { height: 46px; padding: 0; border: none; }
        .menu-table tfoot .v-space td { height: 38px; padding: 0; border: none; }

        .pdf-header {
            background: #15181a;       /* --surface */
            padding: 18px 22px;
            margin-bottom: 18px;
            border-bottom: 3px solid #cca352;   /* --gold */
        }
        .pdf-header h1 {
            font-family: "KudosKaps", "Playfair Display", serif;
            /* KudosKaps solo existe en peso normal; sin esto el <h1> pide bold
               por defecto y Dompdf sustituye por una serif (Times-Bold). */
            font-weight: normal;
            color: #cca352;           /* --gold */
            font-size: 34px;
            letter-spacing: 1px;
        }
        .pdf-header .sub {
            font-family: "Montserrat", sans-serif;
            font-weight: 300;
            color: rgba(237, 233, 223, 0.58);  /* --txt-mute */
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 4px;
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
        /* Celda del titulo (ocupa las 2 columnas, solo en la 1a hoja) */
        .header-cell { border: none; padding: 0 14px 4px; }

        /* Encabezado de categoria: fuente por defecto del documento (Montserrat)
           en dorado, ocupando las 2 columnas. */
        .cat-row { page-break-inside: avoid; }
        .cat-name {
            border: none;
            font-family: "Montserrat", sans-serif;
            font-weight: bold;
            color: #cca352;           /* --gold */
            font-size: 13px;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 16px 14px 6px;
            border-bottom: 1px solid rgba(204, 163, 82, 0.35);  /* --line reforzado */
        }
        /* La primera categoria no necesita tanto aire arriba (va pegada al titulo) */
        .cat-row.first .cat-name { padding-top: 6px; }

        .cell-inner {
            border-bottom: 1px solid rgba(204, 163, 82, 0.18);  /* --line */
            padding-bottom: 10px;
        }

        /* Tabla interna sin borde: nombre a la izquierda, precio a la derecha */
        .dish-line {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .dish-line td { border: none; vertical-align: baseline; }
        .dish-name {
            font-family: "Playfair Display", serif;   /* serifas para titulos */
            font-size: 15px;
            color: #fff9e4;           /* --beige */
        }
        .dish-price {
            font-family: "Playfair Display", serif;
            font-size: 14px;
            color: #cca352;           /* --gold (--accent) */
            text-align: right;
            white-space: nowrap;
        }
        .dish-desc {
            font-family: "Montserrat", sans-serif;
            font-weight: 300;
            color: rgba(237, 233, 223, 0.58);  /* --txt-mute */
            font-size: 10.5px;
            line-height: 1.45;
            margin-top: 4px;
        }
        .empty {
            padding: 30px;
            text-align: center;
            color: rgba(237, 233, 223, 0.32);  /* --txt-faint */
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="page">
        <?php if (empty($categorias)) : ?>
            <p class="empty">No hay platillos registrados en el menú.</p>
        <?php else : ?>
            <table class="menu-table">
                <!-- Espaciador repetido: margen superior en cada hoja -->
                <thead><tr class="v-space"><td colspan="2"></td></tr></thead>
                <!-- Espaciador repetido: margen inferior en cada hoja -->
                <tfoot><tr class="v-space"><td colspan="2"></td></tr></tfoot>
                <tbody>
                    <!-- Titulo dentro del cuerpo: aparece una sola vez, debajo
                         del espaciador superior de la 1a hoja -->
                    <tr>
                        <td class="header-cell" colspan="2">
                            <div class="pdf-header">
                                <!-- Sin acento en "Menu": la fuente KudosKaps no
                                     define la U acentuada y cambiaria de tipografia. -->
                                <h1>Menu — Casa Pestalozzi</h1>
                                <p class="sub">Cocina Mediterránea con corazón mexicano</p>
                            </div>
                        </td>
                    </tr>
                    <?php foreach ($categorias as $i => $categoria) : ?>
                        <!-- Nombre de la categoria (2 columnas, dorado) -->
                        <tr class="cat-row<?php echo $i === 0 ? ' first' : ''; ?>">
                            <td class="cat-name" colspan="2"><?php echo htmlspecialchars($categoria['nombre'] ?? ''); ?></td>
                        </tr>
                        <?php foreach (array_chunk($categoria['platillos'], 2) as $fila) : ?>
                            <tr class="menu-row">
                                <?php foreach ($fila as $platillo) : ?>
                                    <td>
                                        <div class="cell-inner">
                                            <table class="dish-line">
                                                <tr>
                                                    <td class="dish-name"><?php echo htmlspecialchars($platillo->nombre ?? ''); ?></td>
                                                    <td class="dish-price">$<?php echo number_format((float) ($platillo->precio ?? 0), 2); ?></td>
                                                </tr>
                                            </table>
                                            <p class="dish-desc"><?php echo htmlspecialchars($platillo->descripcion ?? ''); ?></p>
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
</body>
</html>
