<?php
/**
 * Plantilla HTML que Dompdf convierte a PDF — Carta Casa Pestalozzi.
 * Recibe:
 *   - $gruposOrdenados: array de ['nombre' => string, 'platillos' => Menu[]]
 *   - $platillos: array plano de objetos Menu (para el conteo total)
 *   - $generado:  string con la fecha/hora de generacion
 *   - $fontsDir:  ruta absoluta (con / ) a public/build/fonts para @font-face
 *
 * Diseno editorial premium: portada con marca, secciones por categoria con
 * filete dorado, platillos en 2 columnas con lider punteado nombre—precio.
 * Layout con tablas sin borde (el metodo mas fiable en Dompdf), paleta de marca
 * (public/build/css/app.css), titulos Playfair Display, cuerpo Crimson Text.
 */
$grupos = $gruposOrdenados ?? [];
$totalPlatillos = is_array($platillos ?? null) ? count($platillos) : 0;
$anio = date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Menu</title>
    <style>
        @font-face {
            font-family: "KudosKaps";
            src: url("<?php echo $fontsDir; ?>/KudosKapsOneNF.ttf") format("truetype");
            font-weight: normal;
        }
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
            font-family: "Crimson Text";
            src: url("<?php echo $fontsDir; ?>/CrimsonText-Regular.ttf") format("truetype");
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

        /* Paleta de marca (—-gold #cca352, --terra #7c3d1d, --ink-2 #101213,
           --beige #fff9e4, --sage #5f7d56, --gold-deep #a07e36) */
        @page { margin: 42px 46px 52px; }
        * { margin: 0; padding: 0; }

        body {
            font-family: "Crimson Text", serif;
            color: #201914;
            background: #fffdf6;
            font-size: 11px;
        }

        /* ── Portada / cabecera de marca ─────────────────────── */
        .cover {
            text-align: center;
            padding: 10px 0 24px;
            border-bottom: 2px solid #cca352;
            margin-bottom: 14px;
        }
        .cover__rule {
            font-family: "Montserrat", sans-serif;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 6px;
            text-transform: uppercase;
            color: #a07e36;
        }
        .cover__brand {
            font-family: "KudosKaps", "Playfair Display", serif;
            font-size: 56px;
            color: #7c3d1d;
            letter-spacing: 3px;
            line-height: 1.0;
            margin: 12px 0 10px;
        }
        .cover__brand span { color: #cca352; }
        .cover__tagline {
            font-family: "Montserrat", sans-serif;
            font-size: 8.5px;
            font-weight: 300;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #5f7d56;
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

        /* ── Seccion por categoria ───────────────────────────── */
        .section { page-break-inside: auto; padding-bottom: 12px; }
        .section-head {
            page-break-inside: avoid;
            page-break-after: avoid;
            padding: 22px 0 14px;
        }
        /* Titulo centrado sobre filete dorado de ancho completo */
        .section-title-wrap {
            text-align: center;
            border-bottom: 1.5px solid #cca352;
            padding-bottom: 6px;
        }
        .section-title {
            font-family: "Playfair Display", serif;
            font-size: 20px;
            color: #7c3d1d;
            letter-spacing: 0.5px;
        }

        /* Tabla de 2 columnas (sin borde) para alinear los platillos */
        .menu-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .menu-table td {
            width: 50%;
            vertical-align: top;
            border: none;
            padding: 15px 22px 16px;
        }
        .menu-row { page-break-inside: avoid; }

        .cell-inner { padding-bottom: 2px; }

        /* Linea nombre — lider punteado — precio */
        .dish-line {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .dish-line td { border: none; vertical-align: bottom; padding: 0; }
        .dish-name {
            font-family: "Playfair Display", serif;
            font-size: 13.5px;
            color: #201914;
            white-space: nowrap;
        }
        .dish-leader {
            border-bottom: 1px dotted #c9b787;
            width: 100%;
        }
        .dish-price {
            font-family: "Montserrat", sans-serif;
            font-weight: bold;
            font-size: 12px;
            color: #a07e36;
            text-align: right;
            white-space: nowrap;
            padding-left: 6px;
        }
        .dish-desc {
            font-family: "Crimson Text", serif;
            color: #6a5f52;
            font-size: 10.5px;
            line-height: 1.5;
            margin-top: 5px;
            padding-right: 18px;
        }

        /* ── Pie de pagina ───────────────────────────────────── */
        .pdf-footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 2px solid #cca352;
            font-family: "Montserrat", sans-serif;
            text-align: center;
        }
        .pdf-footer__brand {
            font-family: "KudosKaps", "Playfair Display", serif;
            font-size: 15px;
            color: #7c3d1d;
            letter-spacing: 2px;
        }
        .pdf-footer__meta {
            font-size: 7.5px;
            font-weight: 300;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #a07e36;
            margin-top: 3px;
        }
        .empty {
            padding: 40px;
            text-align: center;
            color: rgba(32, 25, 20, 0.5);
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="cover">
        <div class="cover__rule">Restaurante</div>
        <h1 class="cover__brand">Casa <span>Pestalozzi</span></h1>
        <div class="cover__tagline">Mediterráneo · Del Valle · CDMX</div>
    </div>

    <?php if (empty($grupos)) : ?>
        <p class="empty">No hay platillos registrados en el menú.</p>
    <?php else : ?>
        <?php foreach ($grupos as $grupo) : ?>
            <?php if (empty($grupo['platillos'])) { continue; } ?>
            <div class="section">
                <div class="section-head">
                    <div class="section-title-wrap">
                        <span class="section-title"><?php echo htmlspecialchars($grupo['nombre']); ?></span>
                    </div>
                </div>
                <table class="menu-table">
                    <?php foreach (array_chunk($grupo['platillos'], 2) as $fila) : ?>
                        <tr class="menu-row">
                            <?php foreach ($fila as $platillo) : ?>
                                <td>
                                    <div class="cell-inner">
                                        <table class="dish-line">
                                            <tr>
                                                <td class="dish-name"><?php echo htmlspecialchars($platillo->nombre ?? ''); ?></td>
                                                <td class="dish-leader">&nbsp;</td>
                                                <td class="dish-price">$<?php echo number_format((float) ($platillo->precio ?? 0), 2); ?></td>
                                            </tr>
                                        </table>
                                        <?php if (!empty($platillo->descripcion)) : ?>
                                            <p class="dish-desc"><?php echo htmlspecialchars($platillo->descripcion); ?></p>
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
            </div>
        <?php endforeach; ?>

        <div class="pdf-footer">
            <div class="pdf-footer__brand">Casa Pestalozzi</div>
        </div>
    <?php endif; ?>
</body>
</html>
