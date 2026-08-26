<?php /* Brand + nav-toggle + nav-overlay + rail + FAB */ ?>

<a class="brand-mark" href="#hero" data-magnetic>Casa Pestalozzi<span>Del Valle · México</span></a>

<button class="nav-toggle" id="navToggle" aria-label="Abrir menú" data-magnetic>
  <span class="bar"></span><span class="bar"></span><span class="bar"></span>
</button>

<nav class="nav-overlay" id="navOverlay" data-tono="verde" aria-label="Navegación principal">
  <?php /*
    Los enlaces dicen la VOZ ITALIANA del eyebrow de cada sección, que es como
    se rotulan hoy en la página. Al reordenar o renombrar hay que sincronizar
    tres sitios: el número del eyebrow de la sección, esta lista y el rail.
  */ ?>
  <div class="nav-overlay__links">
    <a href="#nosotros" data-nav><span class="num">01</span>La nostra storia</a>
    <a href="#menu" data-nav><span class="num">02</span>Il menù</a>
    <a href="#firma" data-nav><span class="num">03</span>Le specialità</a>
    <a href="#chef" data-nav><span class="num">04</span>La cucina</a>
    <a href="#panaderia" data-nav><span class="num">05</span>Il panificio</a>
    <a href="#catas" data-nav><span class="num">06</span>La cantina</a>
    <a href="#catering" data-nav><span class="num">07</span>Su misura</a>
    <a href="#reserva" data-nav><span class="num">08</span>Il tavolo</a>
  </div>
  <div class="nav-overlay__aside">
    <img src="/build/images/navegacion.webp" alt="Mesa servida en el comedor de Casa Pestalozzi" loading="lazy" />
    <div class="nav-overlay__contact">
      <?php /*
        La marca, otra vez. La de arriba es fija en z-index 200 y el overlay va
        en 250 con fondo opaco: con el menú abierto queda tapada, y subirla por
        encima del panel la pondría también sobre el aviso de privacidad (210).
        Así que la copia vive dentro, encabezando la ficha de contacto: firma el
        pie de la columna en vez de repetir el sitio que ocupa cerrada.
      */ ?>
      <a class="brand-mark brand-mark--overlay" href="#hero" data-nav>Casa Pestalozzi<span>Del Valle · México</span></a>
      <strong>Encuéntranos</strong>
      José Enrique Pestalozzi 1250, CDMX<br />
      56 1481 8297<br />
      <a href="mailto:hola@casapestalozzi.mx">hola@casapestalozzi.mx</a>
    </div>
  </div>
</nav>

<?php /*
  Índice de secciones. Va en ESPAÑOL y no en la voz italiana del overlay: es un
  indicador de posición que se lee de reojo mientras se baja, no el menú.

  Iba con aria-hidden="true" heredado de cuando las etiquetas estaban ocultas y
  sólo se veían las rayitas. Son enlaces de navegación reales y ahora se leen
  todos, así que no hay motivo para esconderlos del lector de pantalla.
*/ ?>
<nav class="rail" id="rail" aria-label="Índice de secciones">
  <a href="#hero" data-rail="hero"><span class="rlabel">Inicio</span><span class="tick"></span></a>
  <a href="#nosotros" data-rail="nosotros"><span class="rlabel">Nosotros</span><span class="tick"></span></a>
  <a href="#menu" data-rail="menu"><span class="rlabel">Carta</span><span class="tick"></span></a>
  <a href="#firma" data-rail="firma"><span class="rlabel">Especiales</span><span class="tick"></span></a>
  <a href="#chef" data-rail="chef"><span class="rlabel">Chef</span><span class="tick"></span></a>
  <a href="#panaderia" data-rail="panaderia"><span class="rlabel">Panadería</span><span class="tick"></span></a>
  <a href="#catas" data-rail="catas"><span class="rlabel">Catas</span><span class="tick"></span></a>
  <a href="#catering" data-rail="catering"><span class="rlabel">Catering</span><span class="tick"></span></a>
  <a href="#reserva" data-rail="reserva"><span class="rlabel">Reservar</span><span class="tick"></span></a>
  <a href="#ubicacion" data-rail="ubicacion"><span class="rlabel">Dónde</span><span class="tick"></span></a>
</nav>
