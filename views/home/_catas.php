<?php
/**
 * Catas dirigidas.
 *
 * Sustituye a la antigua sección de Maridaje, que eran tres tarjetas fijas sin
 * ninguna acción. Ahora es la agenda real de las próximas catas —viene de BD, y
 * por eso se imprime en el servidor y funciona sin JS— con su formulario de
 * inscripción.
 *
 * Va en tono vino: es la sección más oscura de la página y queda encajada entre
 * Panadería (crema) y Catering (verde) para que las tres se distingan de golpe.
 */

$catasProximas = is_array($catasProximas ?? null) ? $catasProximas : [];
$csrfCatas = (string)($reservationCsrfToken ?? '');

$h = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$mesesCata = [1 => 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
$diasCata = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
?>
<section class="section catas" id="catas" data-tono="cafe" data-screen-label="Catas">
  <div class="wrap">

    <?php /*
      Cabecera a dos columnas: el título ancla a la izquierda —en la misma
      vertical que la ficha de fecha de la agenda— y el texto cae a la derecha,
      alineado a ese mismo borde.

      Eran DOS párrafos, uno al lado del otro: qué es una cata y qué hay el resto
      del año. Decían lo mismo dos veces con distinta letra y el segundo
      arrastraba un eyebrow propio que competía con el 06 del título.

      Fundidos, y en dos líneas: la entradilla sólo tiene que decir de qué va la
      sección. El detalle —mesa larga, botellas, maridaje, cocina en vivo— son
      las cuatro fichas de .catas__dinamica que vienen justo debajo, así que
      enumerarlo aquí era decirlo dos veces y empujar la agenda fuera de pantalla.
    */ ?>
    <div class="catas__head">
      <div class="catas__head-titulo">
        <span class="eyebrow no-rule" data-reveal>06 — La cantina</span>
        <h2 class="catas__title" data-lineas>Catas <em class="accent-italic">dirigidas</em></h2>
      </div>
      <p class="body catas__head-texto" data-reveal>
        Una mesa larga, botellas abiertas y alguien que sabe contarlas. Y sin fecha de por
        medio, la carta de vinos y la coctelería de autor están cada día.
      </p>
    </div>

    <?php /*
      La cantina de todos los días, antes de la agenda. La sección era sólo un
      calendario de fechas: quien no podía ninguna de ellas se iba sin saber que
      el maridaje existe el resto del año. Este bloque se pinta HAYA O NO catas
      publicadas —es lo que sostiene la sección cuando la agenda está vacía— y
      es donde vive la galería de coctelería y vinos. Su texto subió a la
      cabecera; aquí queda la galería.
    */ ?>
    <div class="maridaje">

      <?php /*
        Mosaico escalonado, no una tira: la agenda de abajo ya es una lista de
        filas anchas y repetir esa figura dejaba la sección en un solo ritmo.
      */ ?>
      <div class="maridaje__galeria" data-reveal>
        <div class="mg arco" data-portada data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="Coctelería de autor">
          <img src="/build/images/drink-3.webp" alt="Cóctel de autor servido en barra" loading="lazy" />
        </div>
        <div class="mg" data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="Aperitivo de la casa">
          <img src="/build/images/drink-1.webp" alt="Aperitivo de la casa con hielo y cítrico" loading="lazy" />
        </div>
        <div class="mg" data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="Copa de la carta">
          <img src="/build/images/drink-2.webp" alt="Copa servida de la carta de vinos" loading="lazy" />
        </div>
        <?php /* La otra jamba: la foto de las botellas es vertical, así que le
                 toca la celda alta. Estuvo en la ancha y el recorte se quedaba
                 en una franja de cristal negro sin nada reconocible. */ ?>
        <div class="mg arco" data-portada data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="Cerveza artesanal">
          <img src="/build/images/drink-5.webp" alt="Botellas de cerveza artesanal de la casa" loading="lazy" />
        </div>
        <?php /* Va la última porque es la única apaisada del grupo y le toca la
                 celda ancha que cierra el mosaico. */ ?>
        <div class="mg" data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="La barra">
          <img src="/build/images/drink-4.webp" alt="La barra de Casa Pestalozzi durante el servicio" loading="lazy" />
        </div>
      </div>
    </div>

    <?php /*
      Cómo funciona una cata dirigida. Va en la VOZ de respaldo —versalitas
      sans, como las razones de Catering— y no en serif: en serif competía con
      los títulos de las catas de la agenda y la sección se leía como dos listas
      seguidas de lo mismo.
    */ ?>
    <span class="eyebrow no-rule catas__dinamica-rotulo" data-reveal>Cómo es una cata</span>
    <div class="catas__dinamica">
      <div class="dinamica" data-reveal>
        <span class="dinamica__num" aria-hidden="true">01</span>
        <b>Mesa larga</b>
        <p>Grupos pequeños alrededor de una sola mesa. Se viene solo o acompañado; se sale conociendo a los demás.</p>
      </div>
      <div class="dinamica" data-reveal>
        <span class="dinamica__num" aria-hidden="true">02</span>
        <b>Botellas abiertas</b>
        <p>Una selección con hilo conductor y alguien que sabe contarla: de dónde viene, por qué sabe así y qué buscarle.</p>
      </div>
      <div class="dinamica" data-reveal>
        <span class="dinamica__num" aria-hidden="true">03</span>
        <b>Maridaje incluido</b>
        <p>Cada vino llega con su bocado. No es un aperitivo de cortesía: el plato está pensado para esa copa.</p>
      </div>
      <div class="dinamica" data-reveal>
        <span class="dinamica__num" aria-hidden="true">04</span>
        <b>Cocina en vivo</b>
        <p>El pase trabaja a la vista durante toda la sesión. Se pregunta, se prueba y se repite si hace falta.</p>
      </div>
    </div>

    <?php if (empty($catasProximas)) : ?>
      <?php /* Estado vacío: sin catas publicadas la sección no desaparece, invita a avisar. */ ?>
      <div class="catas__vacio" data-reveal>
        <p>No hay catas con fecha abierta en este momento.</p>
        <a class="btn-line" href="https://wa.me/525614818297" target="_blank" rel="noopener" data-magnetic>
          <span>Avísame de la próxima</span><span class="arrow">↗</span>
        </a>
      </div>
    <?php else : ?>
      <ol class="catas__agenda" data-catas-agenda>
        <?php foreach ($catasProximas as $cata) : ?>
          <?php
          $inicio = $cata['inicio'] ?? null;
          $disponibles = (int)$cata['lugares_disponibles'];
          $abierta = (bool)$cata['abierta'];
          ?>
          <li class="cata <?php echo $abierta ? '' : 'cata--cerrada'; ?>" data-reveal>
            <div class="cata__fecha" aria-hidden="true">
              <span class="cata__dia"><?php echo $inicio ? $inicio->format('d') : '--'; ?></span>
              <span class="cata__mes"><?php echo $inicio ? $h($mesesCata[(int)$inicio->format('n')]) : ''; ?></span>
            </div>

            <div class="cata__cuerpo">
              <h3 class="cata__titulo"><?php echo $h($cata['titulo']); ?></h3>
              <?php if (!empty($cata['descripcion'])) : ?>
                <p class="cata__texto"><?php echo $h($cata['descripcion']); ?></p>
              <?php endif; ?>
              <ul class="cata__meta">
                <?php if ($inicio) : ?>
                  <li><?php echo $h($diasCata[(int)$inicio->format('w')] . ' · ' . $inicio->format('H:i') . ' h'); ?></li>
                <?php endif; ?>
                <li><?php echo (int)$cata['duracion_min']; ?> min</li>
                <li class="cata__cupo">
                  <?php if ($disponibles > 0) : ?>
                    <?php echo $disponibles; ?> <?php echo $disponibles === 1 ? 'lugar' : 'lugares'; ?>
                  <?php else : ?>
                    Sin lugares
                  <?php endif; ?>
                </li>
              </ul>
            </div>

            <div class="cata__accion">
              <span class="cata__precio">
                <?php echo $h('$' . number_format((float)$cata['precio'], 0)); ?>
                <small>por persona</small>
              </span>
              <?php if ($abierta) : ?>
                <button class="btn-line" type="button" data-magnetic
                        data-cata-inscribir
                        data-cata-id="<?php echo (int)$cata['id']; ?>"
                        data-cata-titulo="<?php echo $h($cata['titulo']); ?>"
                        data-cata-max="<?php echo $disponibles; ?>">
                  <span>Inscribirme a Cata</span><span class="arrow">↗</span>
                </button>
              <?php else : ?>
                <span class="cata__agotada">Agotada</span>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>

      <?php /*
        Panel de inscripción: uno solo para toda la agenda. Se despliega bajo el
        botón que lo abrió y guarda la cata elegida en un hidden. Un panel por
        cata multiplicaría el marcado sin ganar nada.
      */ ?>
      <div class="catas__panel" id="cataPanel" data-cata-panel hidden>
        <form class="cata-form" data-cata-form novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $h($csrfCatas); ?>">
          <input type="hidden" name="cata_id" value="" data-cata-form-id>

          <div class="cata-form__head">
            <span class="eyebrow no-rule">Inscripción</span>
            <h3 class="cata-form__titulo" data-cata-form-titulo>Reserva tu lugar</h3>
            <button class="cata-form__cerrar" type="button" data-cata-cerrar aria-label="Cerrar inscripción">×</button>
          </div>

          <div class="cata-form__grid">
            <label class="cata-form__campo">
              <span>Nombre</span>
              <input type="text" name="nombre" maxlength="100" required autocomplete="name">
            </label>

            <label class="cata-form__campo">
              <span>¿Cómo te contactamos?</span>
              <select name="contacto_tipo" data-cata-contacto-tipo>
                <option value="email">Correo electrónico</option>
                <option value="telefono">Teléfono</option>
              </select>
            </label>

            <label class="cata-form__campo">
              <span data-cata-contacto-label>Correo electrónico</span>
              <input type="text" name="contacto" maxlength="150" required
                     autocomplete="email" data-cata-contacto
                     placeholder="tu@correo.com">
            </label>

            <label class="cata-form__campo cata-form__campo--corto">
              <span>Personas</span>
              <input type="number" name="personas" min="1" max="10" value="2" required data-cata-personas>
            </label>
          </div>

          <label class="cata-form__campo">
            <span>Nota (opcional)</span>
            <textarea name="nota" rows="2" maxlength="500"
                      placeholder="Alergias, celebración, algo que debamos saber…"></textarea>
          </label>

          <?php /*
            Campo trampa: invisible para una persona, irresistible para un bot.
            El servidor descarta el envío si llega con algo escrito.
          */ ?>
          <div class="cata-form__trampa" aria-hidden="true">
            <label>No llenar este campo
              <input type="text" name="sitio_web" tabindex="-1" autocomplete="off">
            </label>
          </div>

          <div class="cata-form__pie">
            <p class="cata-form__aviso" data-cata-aviso role="status"></p>
            <button class="btn-line btn-line--solid" type="submit" data-cata-enviar>
              <span>Confirmar inscripción</span><span class="arrow">↗</span>
            </button>
          </div>
        </form>
      </div>
    <?php endif; ?>

  </div>
</section>
<?php
// Los parciales de la home se incluyen una sola vez, pero se limpian las
// variables locales por la misma razón que views/components: si mañana esta
// sección se reutiliza, no debe heredar el estado del include anterior.
unset($catasProximas, $csrfCatas, $h, $mesesCata, $diasCata);
