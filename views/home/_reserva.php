<?php /* Reserva */ ?>
<section class="section reserva" id="reserva" data-screen-label="Reservar">
  <div class="wrap reserva__grid">
    <div class="reserva__intro">
      <span class="eyebrow" data-reveal>08 — Reservaciones</span>
      <h2 class="reserva__title" data-reveal>Reserva <em class="accent-italic">tu mesa</em></h2>
      <p class="body" data-reveal>Déjate sorprender por nuestros sabores en un espacio íntimo, con atención al detalle y servicio personalizado.</p>
      <div class="reserva__hours" data-reveal>
        <h5>Horario</h5>
        <div class="row" data-day="1"><span>Lunes</span><span>8:30 — 15:00</span></div>
        <div class="row" data-day="2"><span>Martes</span><span>8:30 — 22:00</span></div>
        <div class="row" data-day="3"><span>Miércoles</span><span>8:30 — 22:00</span></div>
        <div class="row" data-day="4"><span>Jueves</span><span>8:30 — 22:00</span></div>
        <div class="row" data-day="5"><span>Viernes</span><span>8:30 — 22:00</span></div>
        <div class="row" data-day="6"><span>Sábado</span><span>8:30 — 22:00</span></div>
        <div class="row" data-day="0"><span>Domingo</span><span>8:30 — 19:00</span></div>
      </div>
    </div>

    <div class="reserva__form-wrap" data-reveal>
      <form class="form" id="reservaForm" novalidate>
        <div class="form__row">
          <div class="field"><label>Nombre</label><input type="text" name="nombre" placeholder="Tu nombre" required /></div>
          <div class="field"><label>Correo electrónico</label><input type="email" name="email" placeholder="tu@correo.com" required /></div>
        </div>
        <div class="form__row">
          <div class="field">
            <label>Fecha</label>
            <div class="date-picker-wrap" id="datePicker">
              <input type="text" class="date-display" id="dateDisplay" placeholder="dd / mm / aaaa" readonly />
              <input type="hidden" name="fecha" id="fechaHidden" />
              <div class="cp-calendar" id="cpCalendar" aria-hidden="true">
                <div class="cpc-head">
                  <button class="cpc-nav cpc-prev" type="button" aria-label="Mes anterior">‹</button>
                  <span class="cpc-label"></span>
                  <button class="cpc-nav cpc-next" type="button" aria-label="Mes siguiente">›</button>
                </div>
                <div class="cpc-weekdays">
                  <span>do</span><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sá</span>
                </div>
                <div class="cpc-grid"></div>
              </div>
            </div>
          </div>
          <div class="field">
            <label>Hora</label>
            <div class="hour-picker-wrap" id="hourPicker">
              <input type="text" class="hour-display" id="hourDisplay" placeholder="Elige una hora" readonly />
              <input type="hidden" name="hora" id="horaHidden" />
              <div class="hour-dropdown" id="hourDropdown" aria-hidden="true"></div>
            </div>
          </div>
        </div>
        <div class="field">
          <label>Comensales</label>
          <div class="pills" id="guestPills">
            <button type="button" class="pill" data-g="1">1</button>
            <button type="button" class="pill sel" data-g="2">2</button>
            <button type="button" class="pill" data-g="3">3</button>
            <button type="button" class="pill" data-g="4">4</button>
            <button type="button" class="pill" data-g="5">5</button>
            <button type="button" class="pill" data-g="6+">6+</button>
          </div>
          <div class="guests-extra" id="guestsExtra">
            <div class="guests-stepper">
              <button type="button" class="step-btn" id="guestsMinus" aria-label="Reducir">−</button>
              <span class="step-val" id="guestsVal">6</span>
              <button type="button" class="step-btn" id="guestsPlus" aria-label="Aumentar">+</button>
              <input type="hidden" id="guestsNum" value="6" />
            </div>
          </div>
        </div>
        <div class="field"><label>Ocasión (opcional)</label><textarea name="nota" placeholder="Cumpleaños, aniversario, alergias..."></textarea></div>
        <div class="form__submit">
          <button type="submit" class="btn-line" data-magnetic><span>Confirmar reserva</span><span class="arrow">↗</span></button>
          <span class="form__msg" id="formMsg"></span>
        </div>
      </form>
      <div class="reserva__confirm" id="reservaConfirm">
        <div class="mark">✓</div>
        <h3>¡Mesa reservada!</h3>
        <p id="confirmText">Te esperamos. Pronto recibirás más detalles en tu correo.</p>
      </div>
    </div>
  </div>
</section>
