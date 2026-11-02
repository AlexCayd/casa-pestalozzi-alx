<?php
/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  MARCA — compartido por la landing del restaurante y la de la │
 * │  panadería. No lee la configuración de ninguna de las dos.    │
 * └───────────────────────────────────────────────────────────────┘
 *
 * El pie de página. Cada landing le pasa sus columnas, su horario y su
 * dirección: el restaurante y la panadería están en calles distintas, y un pie
 * que leyera SitioConfig por dentro firmaría la panadería con «Pestalozzi 1250».
 *
 * Parámetros:
 * - $pieLema: la frase bajo el wordmark.
 * - $pieRedes: lista para views/components/marca/redes.php.
 * - $pieColumnas: [['titulo' => …, 'enlaces' => [['texto', 'href', 'externo' => bool], …]], …].
 * - $pieHorario: null o [['dia' => …, 'hoy' => bool, 'texto' => …,
 *   'excepcion' => ?string, 'habitual' => ?string], …]. Con `excepcion` la
 *   fila enseña el horario especial y debajo el habitual tachado.
 * - $pieHorarioVacio: lo que se lee si no hay horario.
 * - $pieCopia: el renglón de abajo, tras el año.
 * - $piePrivacidad: enseña el botón del aviso de privacidad. Sólo tiene
 *   sentido donde está el diálogo (la portada, que recoge datos al reservar).
 */

$pieLema = (string)($pieLema ?? '');
$pieRedes = is_array($pieRedes ?? null) ? $pieRedes : [];
$pieColumnas = is_array($pieColumnas ?? null) ? $pieColumnas : [];
$pieHorario = is_array($pieHorario ?? null) ? $pieHorario : null;
$pieHorarioVacio = (string)($pieHorarioVacio ?? '');
$pieCopia = (string)($pieCopia ?? 'Casa Pestalozzi · Del Valle, Ciudad de México');
$piePrivacidad = (bool)($piePrivacidad ?? false);
?>

<footer class="foot" data-tono="verde">
  <div class="wrap">
    <div class="foot__top">
      <div class="foot__brand">
        <?php /* ═══ COMPONENTE REUTILIZABLE → views/templates/header-casa-pestalozzi.php
                 Nivel <h3>: encabeza el masthead del pie, como antes. Sin enlace
                 —ya estamos en la portada— y sin subtítulo. */ ?>
        <?php
        $hcpEtiqueta = 'div';
        $hcpNivel = 'h3';
        $hcpHref = '';
        $hcpClase = 'bm';
        include __DIR__ . '/../../templates/header-casa-pestalozzi.php';
        ?>
        <p><?php echo s($pieLema); ?></p>
        <?php
          $redesLista = $pieRedes;
          $redesClase = 'foot__social';
          $redesConNombre = true;
          include __DIR__ . '/redes.php';
        ?>
      </div>
      <div class="foot__cols">
        <?php foreach ($pieColumnas as $pieColumna) : ?>
        <div class="foot__col">
          <h6><?php echo s($pieColumna['titulo'] ?? ''); ?></h6>
          <?php foreach (($pieColumna['enlaces'] ?? []) as $pieEnlace) : ?>
          <a href="<?php echo s($pieEnlace['href'] ?? ''); ?>"<?php echo !empty($pieEnlace['externo']) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo s($pieEnlace['texto'] ?? ''); ?></a>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <div class="foot__col foot__col--horario">
          <h6>Horario</h6>
          <?php if ($pieHorario !== null && $pieHorario !== []) : ?>
            <?php foreach ($pieHorario as $pieFila) : ?>
              <?php
                $pieClase = 'foot__horario';
                if (!empty($pieFila['hoy'])) {
                  $pieClase .= ' is-hoy';
                }
                if (($pieFila['excepcion'] ?? null) !== null) {
                  $pieClase .= ' is-excepcion';
                }
              ?>
              <span class="<?php echo $pieClase; ?>">
                <b><?php echo s($pieFila['dia'] ?? ''); ?><?php if (!empty($pieFila['hoy'])) : ?><small>Hoy</small><?php endif; ?></b>
                <?php if (($pieFila['excepcion'] ?? null) !== null) : ?>
                  <em><?php echo s($pieFila['excepcion']); ?></em>
                  <?php /*
                    El habitual del día, tachado bajo el excepcional, con el
                    rótulo en palabras: sólo tachado, las dos cifras se leían
                    seguidas —"16:00–23:00 08:30–22:00"— y en voz sonaban a dos
                    horarios vigentes, porque `text-decoration: line-through` no
                    se anuncia.

                    La tabla de la sección de reserva de la portada DUPLICA este
                    bloque con clases propias y sólo comparte con él el servicio
                    que resuelve las excepciones. Si cambia uno, cambia el otro.
                  */ ?>
                  <small class="foot__horario-habitual">Habitual: <s><?php echo s($pieFila['habitual'] ?? ''); ?></s></small>
                <?php else : ?>
                  <span><?php echo s($pieFila['texto'] ?? ''); ?></span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          <?php else : ?>
            <span><?php echo s($pieHorarioVacio); ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="foot__bottom">
      <span>© <span id="year"></span> <?php echo s($pieCopia); ?></span>
      <span class="foot__legal">
        <?php if ($piePrivacidad) : ?>
        <button type="button" class="foot__link" data-privacidad-open>Aviso de privacidad</button>
        <span aria-hidden="true">·</span>
        <?php endif; ?>
        <span>Rediseño conceptual</span>
      </span>
    </div>
  </div>
</footer>
<?php
unset(
  $pieLema, $pieRedes, $pieColumnas, $pieHorario, $pieHorarioVacio, $pieCopia,
  $piePrivacidad, $pieColumna, $pieEnlace, $pieFila, $pieClase
);
?>
