<?php
/**
 * Plantilla HTML que Dompdf convierte a PDF.
 * Recibe:
 *   - $platillos: array de objetos Menu (todo el menu registrado)
 *   - $generado:  string con la fecha/hora de generacion (no se muestra)
 *   - $fontsDir:  ruta absoluta (con / ) a public/build/fonts para @font-face
 *
 * Diseno: menu en 2 columnas por hoja usando tablas sin borde (el metodo de
 * layout mas fiable en Dompdf), paleta del index (public/build/css/app.css),
 * titulos en fuente con serifas (Playfair Display) y el resto en Montserrat.
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

        /* Paleta tomada de :root en public/build/css/app.css */
        @page { margin: 26px 30px; }
        * { margin: 0; padding: 0; }

        body {
            font-family: "Montserrat", sans-serif;
            color: #101213;          /* --ink-2 */
            background: #fff9e4;      /* --beige */
            font-size: 11px;
        }

        .pdf-header {
            background: #101213;      /* --ink-2 */
            padding: 18px 22px;
            margin-bottom: 18px;
            border-bottom: 3px solid #cca352;   /* --gold */
        }
        .pdf-header h1 {
            font-family: "Playfair Display", serif;
            color: #cca352;          /* --gold */
            font-size: 26px;
            letter-spacing: 1px;
        }

        /* Tabla de 2 columnas (sin borde) para alinear el menu */
        .menu-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .menu-table > tr > td,
        .menu-table > tbody > tr > td {
            width: 50%;
            vertical-align: top;
            border: none;
            padding: 8px 14px 12px;
        }
        .menu-row { page-break-inside: avoid; }   /* la fila no se parte entre hojas */

        .cell-inner {
            border-bottom: 1px solid rgba(204, 163, 82, 0.45);  /* --gold suave */
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
            color: #7c3d1d;          /* --terra */
        }
        .dish-price {
            font-family: "Montserrat", sans-serif;
            font-weight: bold;
            font-size: 13px;
            color: #a07e36;          /* --gold-deep */
            text-align: right;
            white-space: nowrap;
        }
        .dish-desc {
            font-family: "Montserrat", sans-serif;
            font-weight: 300;
            color: #15181a;          /* --surface */
            font-size: 10.5px;
            line-height: 1.45;
            margin-top: 4px;
        }
        .dish-tag {
            display: inline-block;
            margin-top: 7px;
            background: #5f7d56;      /* --sage */
            color: #fff9e4;          /* --beige */
            font-size: 8.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 9px;
            border-radius: 9px;
        }
        .dish-tag--empty {
            display: inline-block;
            margin-top: 7px;
            color: rgba(16, 18, 19, 0.4);
            font-style: italic;
            font-size: 9px;
        }

        .pdf-footer {
            margin-top: 14px;
            padding: 8px 16px 0;
            border-top: 1px solid rgba(204, 163, 82, 0.3);
            font-family: "Montserrat", sans-serif;
            font-weight: 300;
            font-size: 9px;
            color: #7c3d1d;
            text-align: right;
        }
        .empty {
            padding: 30px;
            text-align: center;
            color: rgba(16, 18, 19, 0.5);
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="pdf-header">
        <h1>Menú — Casa Pestalozzi</h1>
    </div>

    <?php if (empty($platillos)) : ?>
        <p class="empty">No hay platillos registrados en el menú.</p>
    <?php else : ?>
        <table class="menu-table">
            <?php foreach (array_chunk($platillos, 2) as $fila) : ?>
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
                                <?php if (!empty($platillo->tag)) : ?>
                                    <span class="dish-tag"><?php echo htmlspecialchars($platillo->tag); ?></span>
                                <?php else : ?>
                                    <span class="dish-tag--empty">Sin tag</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endforeach; ?>
                    <?php if (count($fila) === 1) : ?>
                        <td></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>

        <p class="pdf-footer">Total de platillos: <?php echo count($platillos); ?></p>
    <?php endif; ?>
</body>
</html>
