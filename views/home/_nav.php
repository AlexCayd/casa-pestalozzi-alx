<?php /* Brand + nav-toggle + nav-overlay + rail + FAB */ ?>

<a class="brand-mark" href="#hero" data-magnetic>Casa Pestalozzi<span>Del Valle · México</span></a>

<button class="nav-toggle" id="navToggle" aria-label="Abrir menú" data-magnetic>
  <span class="bar"></span><span class="bar"></span><span class="bar"></span>
</button>

<nav class="nav-overlay" id="navOverlay" aria-label="Navegación principal">
  <div class="nav-overlay__links">
    <a href="#nosotros" data-nav><span class="num">01</span>Nosotros</a>
    <a href="#menu" data-nav><span class="num">02</span>La Carta</a>
    <a href="#maridaje" data-nav><span class="num">03</span>Maridaje</a>
    <a href="#firma" data-nav><span class="num">04</span>Lo de la Casa</a>
    <a href="#chef" data-nav><span class="num">05</span>El Chef</a>
    <a href="#panaderia" data-nav><span class="num">06</span>Panadería</a>
    <a href="#eventos" data-nav><span class="num">07</span>Eventos</a>
    <a href="#reserva" data-nav><span class="num">08</span>Reservar</a>
  </div>
  <div class="nav-overlay__aside">
    <img src="/build/images/banner.webp" alt="Fachada de Casa Pestalozzi de noche" loading="lazy" />
    <div class="nav-overlay__contact">
      <strong>Encuéntranos</strong>
      José Enrique Pestalozzi 1250, CDMX<br />
      56 1481 8297<br />
      <a href="mailto:hola@casapestalozzi.mx">hola@casapestalozzi.mx</a>
    </div>
  </div>
</nav>

<div class="rail" id="rail" aria-hidden="true">
  <a href="#hero" data-rail="hero"><span class="rlabel">Inicio</span><span class="tick"></span></a>
  <a href="#nosotros" data-rail="nosotros"><span class="rlabel">Nosotros</span><span class="tick"></span></a>
  <a href="#menu" data-rail="menu"><span class="rlabel">Carta</span><span class="tick"></span></a>
  <a href="#maridaje" data-rail="maridaje"><span class="rlabel">Maridaje</span><span class="tick"></span></a>
  <a href="#firma" data-rail="firma"><span class="rlabel">La Casa</span><span class="tick"></span></a>
  <a href="#chef" data-rail="chef"><span class="rlabel">Chef</span><span class="tick"></span></a>
  <a href="#panaderia" data-rail="panaderia"><span class="rlabel">Panadería</span><span class="tick"></span></a>
  <a href="#eventos" data-rail="eventos"><span class="rlabel">Eventos</span><span class="tick"></span></a>
  <a href="#reserva" data-rail="reserva"><span class="rlabel">Reservar</span><span class="tick"></span></a>
</div>

<a class="reserve-fab" id="reserveFab" href="#reserva" data-magnetic><span class="dot"></span>Reservar mesa</a>
