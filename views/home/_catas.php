<?php
/**
 * Catas dirigidas.
 *
 * Sustituye a la antigua sección de Maridaje, que eran tres tarjetas fijas sin
 * ninguna acción. Ahora es la agenda real de las próximas catas: viene de BD y
 * se imprime en el servidor, así que funciona sin JS.
 *
 * Tuvo un formulario de inscripción con cupo, y el lugar se apartaba aquí. Se
 * retiró entero —tabla incluida— y ahora cada cata abre WhatsApp con la fecha
 * ya escrita: quien apartaba lugar por teléfono no entraba en el contador, así
 * que el «quedan tres lugares» de la portada era falso la mitad del tiempo.
 *
 * Se publica la agenda COMPLETA de lo que no ha ocurrido todavía. Una cata sin
 * cupo no desaparece: se pinta marcada y cambia de invitación —en vez de
 * apartar lugar, se pide aviso si se libera uno—. Saber que la próxima se llenó
 * dice bastante más que no ver nada, y deja la conversación abierta. Lo único
 * que sale de la portada es lo que ya pasó.
 *
 * Va en tono café: queda encajada entre Panadería (crema) y Catering (verde)
 * para que las tres se distingan de golpe.
 */

use Services\ReservacionConfig;

$catasProximas = is_array($catasProximas ?? null) ? $catasProximas : [];

$h = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$mesesCata = [1 => 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
$mesesLargos = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
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
        <p>Todavía no hay ninguna cata programada.</p>
        <a class="btn-line" data-magnetic target="_blank" rel="noopener"
           href="<?php echo $h(ReservacionConfig::whatsappUrl('Hola! Avísenme cuándo es la próxima cata dirigida.')); ?>">
          <span>Avísame de la próxima</span><span class="arrow">↗</span>
        </a>
      </div>
    <?php else : ?>
      <ol class="catas__agenda" data-catas-agenda>
        <?php foreach ($catasProximas as $cata) : ?>
          <?php
          $inicio = $cata['inicio'] ?? null;
          $hayCupo = !empty($cata['disponible']);
          // El mensaje lleva la fecha en letra: quien lo recibe del otro lado
          // sabe de qué sesión se habla sin abrir la agenda.
          $cuando = $inicio
            ? ' del ' . (int)$inicio->format('j') . ' de ' . $mesesLargos[(int)$inicio->format('n')]
            : '';
          $mensajeCata = 'Hola! Quiero apartar lugar en la cata «' . $cata['titulo'] . '»' . $cuando . '.';
          ?>
          <li class="cata<?php echo $hayCupo ? '' : ' cata--sin-cupo'; ?>" data-reveal>
            <div class="cata__fecha" aria-hidden="true">
              <span class="cata__dia"><?php echo $inicio ? $inicio->format('d') : '--'; ?></span>
              <span class="cata__mes"><?php echo $inicio ? $h($mesesCata[(int)$inicio->format('n')]) : ''; ?></span>
            </div>

            <div class="cata__cuerpo">
              <h3 class="cata__titulo">
                <?php echo $h($cata['titulo']); ?>
                <?php if (!$hayCupo) : ?>
                  <span class="cata__sello">Sin cupo</span>
                <?php endif; ?>
              </h3>
              <?php if (!empty($cata['descripcion'])) : ?>
                <p class="cata__texto"><?php echo $h($cata['descripcion']); ?></p>
              <?php endif; ?>
              <ul class="cata__meta">
                <?php if ($inicio) : ?>
                  <li><?php echo $h($diasCata[(int)$inicio->format('w')] . ' · ' . $inicio->format('H:i') . ' h'); ?></li>
                <?php endif; ?>
                <li><?php echo (int)$cata['duracion_min']; ?> min</li>
              </ul>
            </div>

            <div class="cata__accion">
              <span class="cata__precio">
                <?php echo $h('$' . number_format((float)$cata['precio'], 0)); ?>
                <small>por persona</small>
              </span>
              <?php /* Sin cupo no se ofrece nada: la cata queda bloqueada. El
                       sello del titular y el atenuado de .cata--sin-cupo ya lo
                       dicen, y un segundo botón sólo invitaba a escribir por
                       algo que no se puede dar. */ ?>
              <?php if ($hayCupo) : ?>
                <a class="btn-line" data-magnetic
                   target="_blank" rel="noopener"
                   href="<?php echo $h(ReservacionConfig::whatsappUrl($mensajeCata)); ?>">
                  <span>Apartar lugar</span><span class="arrow">↗</span>
                </a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

  </div>
</section>
<?php
// Los parciales de la home se incluyen una sola vez, pero se limpian las
// variables locales por la misma razón que views/components: si mañana esta
// sección se reutiliza, no debe heredar el estado del include anterior.
unset($catasProximas, $h, $mesesCata, $mesesLargos, $diasCata, $cata, $inicio, $cuando, $mensajeCata, $hayCupo);
