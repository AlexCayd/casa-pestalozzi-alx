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
 * Va en la banda de oro: queda encajada entre la insegna verde que la anuncia
 * y Catering (verde), y es la banda clara que separa los dos verdes.
 */

use Services\Reservations\ReservacionConfig;

$catasProximas = is_array($catasProximas ?? null) ? $catasProximas : [];
$cartaMaridaje = is_array($cartaMaridaje ?? null) ? $cartaMaridaje : [];
$precioMaridaje = static function ($p): string {
  $n = (float)$p;
  return '$' . number_format($n, floor($n) == $n ? 0 : 2);
};

$h = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$mesesCata = [1 => 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
$mesesLargos = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$diasCata = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
?>
<section class="section catas" id="catas" data-tono="oro" data-screen-label="Catas">
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
    <div class="maridaje" id="maridaje">

      <?php /*
        Mosaico escalonado, no una tira: la agenda de abajo ya es una lista de
        filas anchas y repetir esa figura dejaba la sección en un solo ritmo.
      */ ?>
      <div class="maridaje__galeria" data-reveal>
        <div class="mg arco" data-portada data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="Coctelería de autor">
          <img src="/build/images/drink-3.webp"<?php echo srcsetImagen('/build/images/drink-3.webp'); ?> decoding="async" alt="Cóctel de autor servido en barra" loading="lazy" />
        </div>
        <div class="mg" data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="Aperitivo de la casa">
          <img src="/build/images/drink-1.webp"<?php echo srcsetImagen('/build/images/drink-1.webp'); ?> decoding="async" alt="Aperitivo de la casa con hielo y cítrico" loading="lazy" />
        </div>
        <div class="mg" data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="Copa de la carta">
          <img src="/build/images/drink-2.webp"<?php echo srcsetImagen('/build/images/drink-2.webp'); ?> decoding="async" alt="Copa servida de la carta de vinos" loading="lazy" />
        </div>
        <?php /* La otra jamba: la foto de las botellas es vertical, así que le
                 toca la celda alta. Estuvo en la ancha y el recorte se quedaba
                 en una franja de cristal negro sin nada reconocible. */ ?>
        <div class="mg arco" data-portada data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="Cerveza artesanal">
          <img src="/build/images/drink-5.webp"<?php echo srcsetImagen('/build/images/drink-5.webp'); ?> decoding="async" alt="Botellas de cerveza artesanal de la casa" loading="lazy" />
        </div>
        <?php /* Va la última porque es la única apaisada del grupo y le toca la
                 celda ancha que cierra el mosaico. */ ?>
        <div class="mg" data-parallax-img data-zoom data-zoom-cat="Maridaje" data-zoom-name="La barra">
          <img src="/build/images/drink-4.webp"<?php echo srcsetImagen('/build/images/drink-4.webp'); ?> decoding="async" alt="La barra de Casa Pestalozzi durante el servicio" loading="lazy" />
        </div>
      </div>

      <?php /*
        La carta de maridaje, aquí y no en la sección de Menú: es lo que se busca
        al ver las copas. Compacta a propósito —una pestaña por sección de la
        barra y, dentro, TODAS sus bebidas con su precio, sin recortar— para que
        la sección siga siendo de catas.

        Sin JS se ven todas las secciones una debajo de otra, cada una con su
        título. maridaje.js convierte los títulos en pestañas (con flechas,
        Inicio y Fin) y deja visible sólo una lista.

        El botón del PDF lleva la copa y va en el relleno de esta banda, que es
        verde: la carta de comida, en Menú, lleva cubiertos y va en oro. Antes
        eran dos píldoras gemelas, una debajo de la otra.
      */ ?>
      <div class="maridaje__carta" data-maridaje data-reveal>
        <div class="maridaje__cabeza">
          <div>
            <h3 class="maridaje__titulo">La carta de <em class="accent-italic">maridaje</em></h3>
            <p class="maridaje__bajada">Vinos, coctelería y barra, todos los días.</p>
          </div>
          <a class="btn-line btn-line--solid btn-line--maridaje" href="/maridaje/pdf" target="_blank" rel="noopener" data-magnetic>
            <svg class="btn-line__icono" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 3h10c0 5-1.5 9-5 9s-5-4-5-9ZM12 12v8M8 21h8M7.4 7h9.2"/></svg>
            <span>Carta de maridaje en PDF</span>
          </a>
        </div>

        <?php if (!empty($cartaMaridaje)) : ?>
          <div class="maridaje__pestanas" role="tablist" aria-label="Secciones de la carta de maridaje" hidden>
            <?php foreach ($cartaMaridaje as $mi => $mcat) : ?>
              <button type="button" class="maridaje__pestana" role="tab"
                      id="mrd-tab-<?php echo (int)$mi; ?>" aria-controls="mrd-panel-<?php echo (int)$mi; ?>"
                      aria-selected="<?php echo $mi === 0 ? 'true' : 'false'; ?>"
                      tabindex="<?php echo $mi === 0 ? '0' : '-1'; ?>">
                <?php echo $h($mcat['label']); ?>
              </button>
            <?php endforeach; ?>
          </div>

          <?php foreach ($cartaMaridaje as $mi => $mcat) : ?>
            <?php $mPlatos = is_array($mcat['dishes'] ?? null) ? $mcat['dishes'] : []; ?>
            <div class="maridaje__panel" id="mrd-panel-<?php echo (int)$mi; ?>" role="tabpanel" aria-labelledby="mrd-tab-<?php echo (int)$mi; ?>">
              <h4 class="maridaje__panel-titulo"><?php echo $h($mcat['label']); ?></h4>
              <ul class="maridaje__lista">
                <?php foreach ($mPlatos as $mplato) : ?>
                  <li>
                    <span class="maridaje__nombre"><?php echo $h($mplato['n']); ?></span>
                    <span class="maridaje__puntos" aria-hidden="true"></span>
                    <span class="maridaje__precio"><?php echo $h($precioMaridaje($mplato['p'])); ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
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
              <?php /* El sello son DOS cajas a propósito: la de fuera habla con la
                       letra del título (mide su altura de mayúsculas) y la de
                       dentro es la pastilla, con la suya. Así cada una se
                       centra con su propia fuente, sea la real o la de
                       respaldo (ver .cata__sello en _catas.scss). */ ?>
              <h3 class="cata__titulo">
                <?php echo $h($cata['titulo']); ?>
                <?php if (!$hayCupo) : ?>
                  <span class="cata__sello"><span class="cata__sello-pastilla">Sin cupo</span></span>
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
unset($catasProximas, $cartaMaridaje, $precioMaridaje, $mi, $mcat, $mPlatos, $mplato, $h, $mesesCata, $mesesLargos, $diasCata, $cata, $inicio, $cuando, $mensajeCata, $hayCupo);
