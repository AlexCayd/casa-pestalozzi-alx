<?php
/**
 * Catering.
 *
 * Todo el flujo es WhatsApp. Hubo un formulario de cotización que daba de alta
 * la solicitud en una bandeja del panel, y se retiró entero —tabla incluida—:
 * quien cotiza un evento cierra el trato conversando, no llenando siete campos
 * y esperando el correo, y la bandeja acababa siendo una copia peor del hilo de
 * WhatsApp que se abría igualmente. El único CTA que queda es el de la
 * conversación, y sale por el mismo sitio que ya usaba la rejilla de ocasiones.
 *
 * Los cuatro "servicios" con miniatura se sustituyeron por esa rejilla: se
 * leían como formatos de menú, cuando lo que busca quien llega aquí es
 * reconocer SU celebración. Cada ocasión abre WhatsApp con su frase ya escrita,
 * así que el primer mensaje no lo tiene que redactar el visitante.
 *
 * También salió el bloque de testimonios con la foto de equipo: era el tercer
 * CTA de la misma sección.
 *
 * Va en tono verde: entre el café de Catas y el lino de Reservaciones.
 */

use Services\SitioConfig;

$ocasiones = SitioConfig::OCASIONES_EVENTO;

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
        <a class="btn-line btn-line--solid catering__cta"
           href="<?php echo $h(SitioConfig::whatsappEventosUrl(SitioConfig::MENSAJE_EVENTO_GENERICO)); ?>"
           target="_blank" rel="noopener" data-magnetic>
          <span>Cotizar por WhatsApp</span><span class="arrow">↗</span>
        </a>
        <span class="catering__cta-nota">Te respondemos con la cotización en menos de 24 horas.</span>
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
      Rejilla de ocasiones. Cada una es un enlace real a WhatsApp con su mensaje
      ya escrito: en el escritorio el hover lo previsualiza abajo, y en móvil
      —donde no hay hover— el toque abre la conversación directamente. El href
      se emite en el servidor para que funcione sin JS.
    */ ?>
    <div class="eventos" data-reveal data-eventos>
      <span class="eyebrow no-rule eventos__rotulo">Para qué ocasión</span>
      <ul class="eventos__lista">
        <?php foreach ($ocasiones as $ocasion => $mensaje) : ?>
          <li>
            <a class="eventos__item"
               href="<?php echo $h(SitioConfig::whatsappEventosUrl($mensaje)); ?>"
               target="_blank" rel="noopener"
               data-evento-mensaje="<?php echo $h($mensaje); ?>">
              <?php echo $h($ocasion); ?>
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

  </div>
</section>
<?php unset($ocasiones, $h); ?>
