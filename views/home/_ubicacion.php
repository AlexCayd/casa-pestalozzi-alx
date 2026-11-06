<?php
/**
 * Ubicación del restaurante.
 *
 * La sección, los datos y el plano son de aquí; la composición (datos a un
 * lado, lámina de papel al otro, pin, chapa del Metro y «Cómo llegar») es el
 * componente de marca views/components/marca/ubicacion.php, que también usa la
 * panadería.
 *
 * El plano es ilustración CSS: divs vacíos colocados en porcentajes, sin
 * librería ni tiles (components/_ubicacion-plano.scss).
 */

use Services\Configuration\SitioConfig;

$ubiEyebrow = 'Venite a trovarci';
$ubiTitulo = 'En el corazón';
$ubiTituloAcento = 'de la Del Valle';
$ubiLineas = [
  ['etiqueta' => 'Dirección', 'texto' => SitioConfig::direccion()],
  ['etiqueta' => 'Teléfono', 'texto' => SitioConfig::telefonoVisible(), 'href' => 'tel:' . SitioConfig::telefonoTel()],
  ['etiqueta' => 'Correo', 'texto' => SitioConfig::correo(), 'href' => 'mailto:' . SitioConfig::correo()],
];
$ubiRedes = SitioConfig::redes();
$ubiPlano = <<<'HTML'
<div class="map2__park"><span class="map2__parklabel">Parque<br />Arboledas</span></div>
      <div class="map2__block b1"></div>
      <div class="map2__block b2"></div>
      <div class="map2__block b3"></div>
      <div class="map2__road road--frias"><span>Heriberto Frías</span></div>
      <div class="map2__road road--pilares"><span>Pilares</span></div>
      <div class="map2__road road--uni"><span>Av. Universidad</span></div>
HTML;
$ubiPin = ['nombre' => 'Casa Pestalozzi', 'detalle' => 'Pestalozzi 1250'];
$ubiMetro = 'División del Norte';
$ubiMapaUrl = SitioConfig::mapsUrl();
$ubiCta = 'Cómo llegar';
?>
<section class="section location" id="ubicacion" data-tono="verde" data-screen-label="Ubicación">
<?php include __DIR__ . '/../components/marca/ubicacion.php'; ?>
</section>
