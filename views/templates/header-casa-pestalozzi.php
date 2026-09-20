<?php
/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  COMPONENTE REUTILIZABLE — el wordmark de Casa Pestalozzi.    │
 * │  No copies este marcado: inclúyelo.                           │
 * │  Estilos: src/scss/shared/_header-casa-pestalozzi.scss        │
 * └───────────────────────────────────────────────────────────────┘
 *
 * Lo usan la landing (_nav.php dos veces y _footer.php), el login (dos veces),
 * la barra lateral del panel, la 404 y el PDF del menú. Cada uno le pasa su propia clase por hcpClase y ajusta desde
 * su hoja; el componente no sabe quién lo incluye.
 *
 * Variables esperadas, todas opcionales:
 * - hcpEtiqueta: elemento contenedor. 'header' (por omisión), 'div' o 'span'.
 * - hcpNivel: elemento del nombre. 'p' (por omisión), 'h1'..'h3', 'span', 'div'.
 *   Una sola página no debe emitir dos <h1>: el nivel se pide, no se asume.
 * - hcpHref: destino del enlace. Cadena vacía → el nombre se emite sin enlace.
 * - hcpSubtitulo: renglón bajo el nombre (p. ej. 'Del Valle · México'). '' lo omite.
 * - hcpDosLineas: bool. Apila CASA sobre PESTALOZZI.
 * - hcpClase: clases extra para el contenedor.
 * - hcpId: id del contenedor.
 * - hcpAtributos: mapa de atributos extra del contenedor ('data-magnetic' => '', ...).
 * - hcpEnlaceAtributos: lo mismo para el <a> del nombre. Separado del anterior
 *   porque un manejador que lea el href tiene que ir en el <a>, no fuera.
 *
 * Uso:
 *   <?php $hcpNivel = 'h1'; $hcpSubtitulo = 'Del Valle · México';
 *         include __DIR__ . '/../templates/header-casa-pestalozzi.php'; ?>
 */

$hcpEtiquetasContenedor = ['header', 'div', 'span'];
$hcpNiveles = ['p', 'h1', 'h2', 'h3', 'span', 'div'];

$hcpEtiqueta = strtolower(trim((string)($hcpEtiqueta ?? 'header')));
$hcpNivel = strtolower(trim((string)($hcpNivel ?? 'p')));
$hcpHref = trim((string)($hcpHref ?? '/'));
$hcpSubtitulo = trim((string)($hcpSubtitulo ?? ''));
$hcpDosLineas = (bool)($hcpDosLineas ?? false);
$hcpClase = trim((string)($hcpClase ?? ''));
$hcpId = trim((string)($hcpId ?? ''));
$hcpAtributos = is_array($hcpAtributos ?? null) ? $hcpAtributos : [];
$hcpEnlaceAtributos = is_array($hcpEnlaceAtributos ?? null) ? $hcpEnlaceAtributos : [];

// Listas cerradas: el nombre del elemento entra sin escapar en el marcado.
if (!in_array($hcpEtiqueta, $hcpEtiquetasContenedor, true)) {
    $hcpEtiqueta = 'header';
}
if (!in_array($hcpNivel, $hcpNiveles, true)) {
    $hcpNivel = 'p';
}

$hcpEscape = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$hcpClases = 'hcp-header';
if ($hcpDosLineas) {
    $hcpClases .= ' hcp-header--apilado';
}
if ($hcpClase !== '') {
    $hcpClases .= ' ' . $hcpClase;
}

$hcpAtributosHtml = static function (array $mapa) use ($hcpEscape) {
    $html = '';
    foreach ($mapa as $atributo => $valor) {
        $atributo = trim((string)$atributo);
        if ($atributo === '') {
            continue;
        }
        // Un valor vacío emite el atributo pelado (data-nav), como en el resto del marcado.
        $html .= $valor === '' || $valor === true
            ? ' ' . $hcpEscape($atributo)
            : ' ' . $hcpEscape($atributo) . '="' . $hcpEscape($valor) . '"';
    }

    return $html;
};

$hcpExtra = $hcpAtributosHtml($hcpAtributos);
$hcpEnlaceExtra = $hcpAtributosHtml($hcpEnlaceAtributos);
?>
<<?php echo $hcpEtiqueta; ?> class="<?php echo $hcpEscape($hcpClases); ?>"<?php echo $hcpId !== '' ? ' id="' . $hcpEscape($hcpId) . '"' : ''; ?><?php echo $hcpExtra; ?>>
    <<?php echo $hcpNivel; ?> class="hcp-header__nombre">
        <?php if ($hcpHref !== '') : ?>
        <a class="hcp-header__enlace" href="<?php echo $hcpEscape($hcpHref); ?>" aria-label="Casa Pestalozzi"<?php echo $hcpEnlaceExtra; ?>>
        <?php endif; ?>
            <?php /* Dos palabras, dos cajas: apilarlas es cosa de la clase del
                     contenedor y no de un <br>, que quedaría también cuando el
                     nombre va en una sola línea. */ ?>
            <span class="hcp-header__palabra">CASA</span>
            <span class="hcp-header__palabra">PESTALOZZI</span>
        <?php if ($hcpHref !== '') : ?>
        </a>
        <?php endif; ?>
    </<?php echo $hcpNivel; ?>>
    <?php if ($hcpSubtitulo !== '') : ?>
    <span class="hcp-header__subtitulo"><?php echo $hcpEscape($hcpSubtitulo); ?></span>
    <?php endif; ?>
</<?php echo $hcpEtiqueta; ?>>
<?php
// El parcial puede incluirse varias veces por página con parámetros distintos;
// sin esto el segundo include hereda lo que dejó el primero.
unset(
    $hcpEtiqueta, $hcpNivel, $hcpHref, $hcpSubtitulo, $hcpDosLineas, $hcpClase,
    $hcpId, $hcpAtributos, $hcpEnlaceAtributos, $hcpEtiquetasContenedor,
    $hcpNiveles, $hcpEscape, $hcpAtributosHtml, $hcpClases, $hcpExtra,
    $hcpEnlaceExtra
);
?>
