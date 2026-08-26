<?php
/**
 * Catering.
 *
 * Cambios respecto a la antigua sección de Eventos: el CTA sube al encabezado
 * —antes quedaba enterrado al final, después de dos testimonios—, deja de
 * mandar a WhatsApp y abre el formulario de cotización que da de alta la
 * solicitud en el panel.
 *
 * Los cuatro "servicios" con miniatura se sustituyeron por la rejilla de tipos
 * de evento: se leían como formatos de menú, cuando lo que busca quien llega
 * aquí es reconocer SU celebración. Cada tipo abre WhatsApp con su frase ya
 * escrita, así que el primer mensaje no lo tiene que redactar el visitante.
 *
 * También salió el bloque de testimonios con la foto de equipo: era el tercer
 * CTA de la misma sección y empujaba el formulario fuera de pantalla.
 *
 * Va en tono verde: entre el café de Catas y el lino de Reservaciones.
 */

use Services\SitioConfig;

$csrfCatering = (string)($reservationCsrfToken ?? '');
$tiposEvento = \Model\CateringSolicitud::TIPOS_EVENTO;

$h = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
?>
<section class="section catering" id="catering" data-tono="verde" data-screen-label="Catering">
  <div class="wrap">

    <div class="catering__head">
      <div>
        <span class="eyebrow" data-reveal>07 — Su misura</span>
        <h2 class="catering__title" data-lineas>Catering que <em class="accent-italic">marca la diferencia</em></h2>
      </div>
      <div class="catering__intro" data-reveal>
        <p class="body">
          Creamos experiencias gastronómicas donde la elegancia, el sabor y la atención al
          detalle llevan cada celebración a su máxima expresión.
        </p>
        <button class="btn-line btn-line--solid catering__cta" type="button" data-magnetic data-catering-abrir>
          <span>Cotizar evento</span><span class="arrow">↗</span>
        </button>
        <span class="catering__cta-nota">Respuesta con la cotización en menos de 24 horas.</span>
      </div>
    </div>

    <?php /*
      Banda de portadas entre la cabecera y las ocasiones: la prueba visual va
      justo antes de que el visitante elija su celebración, no al final. Cuatro
      retratos, que es el formato en el que llegan las fotos de evento y el que
      no compite con la rejilla de texto de abajo.
    */ ?>
    <div class="catering__fotos" data-reveal>
      <div class="cf arco" data-portada data-parallax-img data-zoom data-zoom-cat="Catering" data-zoom-name="Evento en sala">
        <img src="/build/images/catering-evento.webp" alt="Evento servido en la sala de Casa Pestalozzi" loading="lazy" />
      </div>
      <div class="cf" data-portada data-parallax-img data-zoom data-zoom-cat="Catering" data-zoom-name="Montaje">
        <img src="/build/images/catering-montaje.webp" alt="Montaje de mesa para un evento privado" loading="lazy" />
      </div>
      <div class="cf" data-portada data-parallax-img data-zoom data-zoom-cat="Catering" data-zoom-name="Mesa vestida">
        <img src="/build/images/catering-mesa.webp" alt="Mesa vestida con la vajilla de la casa" loading="lazy" />
      </div>
      <div class="cf arco" data-portada data-parallax-img data-zoom data-zoom-cat="Catering" data-zoom-name="Banquete">
        <img src="/build/images/catering-banquete.webp" alt="Banquete servido para un grupo grande" loading="lazy" />
      </div>
    </div>

    <?php /*
      Rejilla de tipos de evento. Cada uno es un enlace real a WhatsApp con su
      mensaje ya escrito: en el escritorio el hover lo previsualiza abajo, y en
      móvil —donde no hay hover— el toque abre la conversación directamente. El
      href se emite en el servidor para que funcione sin JS.

      "Otro" no entra en la rejilla: es la salida del <select> del formulario,
      no una celebración que alguien vaya a reconocer como suya.
    */ ?>
    <div class="eventos" data-reveal data-eventos>
      <span class="eyebrow no-rule eventos__rotulo">Para qué ocasión</span>
      <ul class="eventos__lista">
        <?php foreach ($tiposEvento as $tipo => $mensaje) : ?>
          <?php if ($tipo === 'Otro') { continue; } ?>
          <li>
            <a class="eventos__item"
               href="<?php echo $h(SitioConfig::whatsappEventosUrl($mensaje)); ?>"
               target="_blank" rel="noopener"
               data-evento-mensaje="<?php echo $h($mensaje); ?>">
              <?php echo $h($tipo); ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="eventos__mensaje" role="status" data-eventos-mensaje>
        <span class="eventos__mensaje-guia">Pasa por una ocasión y te preparamos el mensaje.</span>
      </p>
    </div>

    <?php /*
      Los argumentos van en OTRA voz que las ocasiones de arriba: aquéllas son
      serif porque son lo accionable —cada una abre una conversación—, y éstas
      versalitas sans porque son respaldo. Con las dos en serif crema la sección
      se leía como una sola lista de veintidós cosas.
    */ ?>
    <span class="eyebrow no-rule reasons__rotulo" data-reveal>Por qué nosotros</span>
    <div class="reasons">
      <div class="reason" data-reveal><b>Identidad</b><p>Fusión entre técnica, ingredientes frescos y creatividad.</p></div>
      <div class="reason" data-reveal><b>Presentación</b><p>Cada platillo es una obra visual que refleja buen gusto.</p></div>
      <div class="reason" data-reveal><b>Puntualidad</b><p>Coordinación precisa y ejecución sin fallas.</p></div>
      <div class="reason" data-reveal><b>A tu medida</b><p>Diseñamos el menú perfecto para tu evento.</p></div>
    </div>

    <?php /*
      Formulario de cotización. Vive entre las razones y los testimonios: quien
      ya se convenció no tiene que seguir bajando, y quien duda encuentra las
      citas justo debajo.
    */ ?>
    <div class="catering__panel" id="cateringPanel" data-catering-panel hidden>
      <form class="catering-form" data-catering-form novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrfCatering); ?>">

        <div class="catering-form__head">
          <span class="eyebrow no-rule">Cotización</span>
          <h3 class="catering-form__titulo">Cuéntanos de tu evento</h3>
          <button class="catering-form__cerrar" type="button" data-catering-cerrar aria-label="Cerrar cotización">×</button>
        </div>

        <div class="catering-form__grid">
          <label class="catering-form__campo">
            <span>Nombre</span>
            <input type="text" name="nombre" maxlength="100" required autocomplete="name">
          </label>

          <label class="catering-form__campo">
            <span>¿Cómo te contactamos?</span>
            <select name="contacto_tipo" data-catering-contacto-tipo>
              <option value="email">Correo electrónico</option>
              <option value="telefono">Teléfono</option>
            </select>
          </label>

          <label class="catering-form__campo">
            <span data-catering-contacto-label>Correo electrónico</span>
            <input type="text" name="contacto" maxlength="150" required
                   autocomplete="email" data-catering-contacto
                   placeholder="tu@correo.com">
          </label>

          <label class="catering-form__campo">
            <span>Tipo de evento</span>
            <select name="tipo_evento" required>
              <?php foreach (array_keys($tiposEvento) as $tipo) : ?>
                <option value="<?php echo $h($tipo); ?>"><?php echo $h($tipo); ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="catering-form__campo">
            <span>Fecha del evento <small>(opcional)</small></span>
            <input type="date" name="fecha_evento">
          </label>

          <label class="catering-form__campo">
            <span>Invitados <small>(opcional)</small></span>
            <input type="number" name="invitados" min="1" max="2000" placeholder="80">
          </label>

          <label class="catering-form__campo">
            <span>Presupuesto estimado <small>(opcional)</small></span>
            <input type="text" name="presupuesto" maxlength="60" placeholder="$50,000 – $80,000">
          </label>
        </div>

        <label class="catering-form__campo">
          <span>Cuéntanos más <small>(opcional)</small></span>
          <textarea name="mensaje" rows="3" maxlength="1500"
                    placeholder="Estilo del evento, restricciones alimentarias, si necesitas montaje…"></textarea>
        </label>

        <?php /* Campo trampa: el servidor descarta el envío si llega relleno. */ ?>
        <div class="catering-form__trampa" aria-hidden="true">
          <label>No llenar este campo
            <input type="text" name="sitio_web" tabindex="-1" autocomplete="off">
          </label>
        </div>

        <div class="catering-form__pie">
          <p class="catering-form__aviso" data-catering-aviso role="status"></p>
          <button class="btn-line btn-line--solid" type="submit" data-catering-enviar>
            <span>Enviar solicitud</span><span class="arrow">↗</span>
          </button>
        </div>
      </form>
    </div>

  </div>
</section>
<?php unset($csrfCatering, $tiposEvento, $h); ?>
