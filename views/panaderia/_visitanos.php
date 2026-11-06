<?php
/**
 * PANADERÍA · Visítanos.
 *
 * La composición es la de «Cómo llegar» de la portada del restaurante
 * (views/components/marca/ubicacion.php): datos a un lado y la lámina de papel
 * con el plano al otro. Los datos son de PanaderiaConfig y el plano, las calles
 * de la panadería.
 *
 * El plano es un SVG a escala aproximada de la esquina de San Borja con
 * Pestalozzi y División del Norte, que se cruzan en aspa justo en la puerta.
 * El cruce está en el CENTRO del viewBox (200, 170) y el SVG cubre la lámina
 * con `slice`: así el cruce cae en el centro de la lámina mida lo que mida, y
 * el pin (anclado por el punto, en 50 % / 50 %) cae siempre en la esquina. Los
 * rótulos se quedan lejos de los bordes porque `slice` recorta lo que sobra.
 * Los colores salen de _visitanos.scss; aquí no hay ninguno.
 */

use Services\Panaderia\PanaderiaConfig;

$vHoy = null;
foreach ($semana as $vDia) {
  if ($vDia['hoy']) {
    $vHoy = $vDia;
  }
}

$ubiEyebrow = 'Vieni a trovarci';
$ubiTitulo = 'Ven por tu';
$ubiTituloAcento = 'pan';
$ubiLineas = [
  ['etiqueta' => 'Dirección', 'texto' => PanaderiaConfig::direccion(), 'href' => PanaderiaConfig::mapsUrl(), 'externo' => true],
  ['etiqueta' => 'Teléfono', 'texto' => PanaderiaConfig::telefonoVisible(), 'href' => 'tel:' . PanaderiaConfig::telefonoTel()],
  ['etiqueta' => 'Hoy, ' . mb_strtolower((string) ($vHoy['nombre'] ?? '')), 'texto' => ($vHoy['abre'] ?? null) === null
    ? 'No abrimos'
    : 'De ' . PanaderiaConfig::hora($vHoy['abre']) . ' a ' . PanaderiaConfig::hora($vHoy['cierra'])],
];
$ubiRedes = PanaderiaConfig::redes();

// Calles: [x1, y1, x2, y2, ancho]. Se prolongan más allá del viewBox para que
// el recorte de `slice` nunca deje ver un extremo.
$vCalles = [
  [17.5, -80, -57.5, 420, 6],    // Nicolás San Juan
  [125.5, -80, 50.5, 420, 9],    // Heriberto Frías
  [237.5, -80, 162.5, 420, 11],  // Pestalozzi
  [349.5, -80, 274.5, 420, 9],   // Pitágoras
  [461.5, -80, 386.5, 420, 6],   // Anaxágoras
  [-80, 71.6, 480, 172.4, 6],    // calle menor
  [-80, 23.6, 480, 124.4, 16],   // Eugenia
  [-80, 119.6, 480, 220.4, 11],  // San Borja
  [-80, 211.6, 480, 312.4, 8],   // Miguel Serrano
  [-80, 303.6, 480, 404.4, 6],   // calle menor
  [137.5, -80, 262.5, 420, 20],  // División del Norte
];
// Rótulos: [texto, x, y, ángulo]. 10.2° es la inclinación de las calles de
// oriente a poniente; 98.5°, la de las de norte a sur; 76°, la de la avenida.
$vRotulos = [
  ['Eugenia', 140, 63.2, 10.2],
  ['San Borja', 145, 160.1, 10.2],
  ['M. Serrano', 132, 249.8, 10.2],
  ['Pestalozzi', 215.75, 65, 98.53],
  ['H. Frías', 99.25, 95, 98.53],
  ['Pitágoras', 299.25, 255, 98.53],
  ['Div. del Norte', 230, 270, 75.96],
];

$ubiPlano = '<svg class="pan-plano" viewBox="0 0 400 340" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">'
  . '<rect class="pan-plano__manzanas" x="-100" y="-100" width="600" height="540"/>'
  . '<rect class="pan-plano__jardin" x="117" y="196" width="26" height="18" rx="1.5" transform="rotate(10.2 130 205)"/>';
foreach ($vCalles as [$x1, $y1, $x2, $y2, $w]) {
  $ubiPlano .= sprintf('<line class="pan-plano__acera" x1="%s" y1="%s" x2="%s" y2="%s" stroke-width="%s"/>', $x1, $y1, $x2, $y2, $w + 2);
}
foreach ($vCalles as [$x1, $y1, $x2, $y2, $w]) {
  $ubiPlano .= sprintf('<line class="pan-plano__calle" x1="%s" y1="%s" x2="%s" y2="%s" stroke-width="%s"/>', $x1, $y1, $x2, $y2, $w);
}
$ubiPlano .= '<line class="pan-plano__camellon" x1="137.5" y1="-80" x2="262.5" y2="420"/>';
foreach ($vRotulos as [$texto, $x, $y, $angulo]) {
  $ubiPlano .= sprintf(
    '<text class="pan-plano__rotulo" x="%s" y="%s" dy=".35em" text-anchor="middle" transform="rotate(%s %s %s)">%s</text>',
    $x, $y, $angulo, $x, $y, s($texto)
  );
}
$ubiPlano .= '</svg>';

$ubiPin = ['nombre' => 'Casa Pestalozzi', 'detalle' => 'Panadería · San Borja 1026', 'x' => '50%', 'y' => '50%', 'ancla' => 'punto'];
$ubiMetro = 'Eugenia';
$ubiMapaUrl = PanaderiaConfig::mapsUrl();
$ubiCta = 'Cómo llegar';
?>
<section class="section location pan-visitanos" id="visitanos" data-tono="verde" data-screen-label="Cómo llegar">
<?php include __DIR__ . '/../components/marca/ubicacion.php'; ?>
</section>
<?php unset($vHoy, $vDia, $vCalles, $vRotulos, $x1, $y1, $x2, $y2, $w, $texto, $x, $y, $angulo); ?>
