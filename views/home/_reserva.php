<?php
/**
 * Muestra el formulario publico de reservaciones y el aviso para grupos grandes.
 */

$contactoReservas = \Services\ReservacionConfig::contactoPublico();
$horariosOperacion = is_array($horariosOperacion ?? null) ? $horariosOperacion : [];
$proximasExcepcionesOperacion = is_array($proximasExcepcionesOperacion ?? null) ? $proximasExcepcionesOperacion : [];
$horariosOperacionDisponibles = (bool)($horariosOperacionDisponibles ?? false);
$limiteExcepcionesVisibles = 2;
$meses = [
  1 => 'enero',
  2 => 'febrero',
  3 => 'marzo',
  4 => 'abril',
  5 => 'mayo',
  6 => 'junio',
  7 => 'julio',
  8 => 'agosto',
  9 => 'septiembre',
  10 => 'octubre',
  11 => 'noviembre',
  12 => 'diciembre',
];
?>
<section class="section reserva" id="reserva" data-screen-label="Reservar">
  <div class="wrap reserva__grid">
    <div class="reserva__intro">
      <span class="eyebrow" data-reveal>08 — Reservaciones</span>
      <h2 class="reserva__title" data-reveal>Reserva <em class="accent-italic">tu mesa</em></h2>
      <p class="body" data-reveal>Déjate sorprender por nuestros sabores en un espacio íntimo, con atención al detalle y servicio personalizado.</p>
      <div class="reserva__hours" data-reveal>
        <h5>Horario habitual</h5>
        <?php if ($horariosOperacionDisponibles) : ?>
          <?php foreach ($horariosOperacion as $horario) : ?>
            <div class="row" data-day="<?php echo (int)($horario['dia_semana'] ?? 0); ?>">
              <span><?php echo s($horario['nombre'] ?? ''); ?></span>
              <span>
                <?php if (!empty($horario['abierto'])) : ?>
                  <?php echo s(($horario['hora_apertura'] ?? '') . '–' . ($horario['hora_cierre'] ?? '')); ?>
                <?php else : ?>
                  Cerrado
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        <?php else : ?>
          <p class="reserva__hours-unavailable">Consulta la disponibilidad al seleccionar tu fecha.</p>
        <?php endif; ?>

        <?php if ($horariosOperacionDisponibles && $proximasExcepcionesOperacion !== []) : ?>
          <section class="reserva__schedule-changes" data-schedule-changes aria-labelledby="upcoming-exceptions-title">
            <div class="reserva__schedule-changes-head">
              <h3 id="upcoming-exceptions-title">Próximas excepciones</h3>
              <?php if (count($proximasExcepcionesOperacion) > $limiteExcepcionesVisibles) : ?>
                <button type="button" class="reserva__schedule-toggle" data-schedule-toggle aria-expanded="false" aria-controls="upcoming-exceptions-list">
                  Ver más
                </button>
              <?php endif; ?>
            </div>
            <div class="reserva__schedule-changes-grid" id="upcoming-exceptions-list">
              <?php foreach ($proximasExcepcionesOperacion as $indice => $excepcion) : ?>
                <?php
                  $fechaExcepcion = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($excepcion['fecha'] ?? ''));
                  $fechaVisible = $fechaExcepcion instanceof \DateTimeImmutable
                    ? (int)$fechaExcepcion->format('j') . ' de ' . ($meses[(int)$fechaExcepcion->format('n')] ?? '')
                    : '';
                  $esHorarioEspecial = ($excepcion['tipo'] ?? '') === 'horario_especial';
                  $horarioVisible = $esHorarioEspecial
                    ? ($excepcion['hora_apertura'] ?? '') . '–' . ($excepcion['hora_cierre'] ?? '')
                    : 'Cerrado todo el día';
                  $esAdicional = $indice >= $limiteExcepcionesVisibles;
                ?>
                <article
                  class="reserva__schedule-change reserva__schedule-change--<?php echo $esHorarioEspecial ? 'special' : 'closed'; ?>"
                  <?php echo $esAdicional ? 'data-schedule-extra hidden' : ''; ?>
                >
                  <span><?php echo s($esHorarioEspecial ? 'Horario especial' : 'Cierre especial'); ?></span>
                  <p><strong><?php echo s($fechaVisible); ?></strong><span aria-hidden="true">—</span><?php echo s($horarioVisible); ?></p>
                  <small>Reemplaza el horario habitual de esa fecha.</small>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      </div>
    </div>

    <div class="reserva__form-wrap" data-reveal>
      <div class="reservation-access__tabs" role="tablist" aria-label="Opciones de reservación">
        <button type="button" class="reservation-access__tab is-active" id="reservation-tab-new"
          role="tab" aria-selected="true" aria-controls="reservation-panel-new" data-reservation-tab="new">
          Nueva reservación
        </button>
        <button type="button" class="reservation-access__tab" id="reservation-tab-manage"
          role="tab" aria-selected="false" aria-controls="reservation-panel-manage" data-reservation-tab="manage">
          Gestionar mis reservaciones
        </button>
      </div>
      <div id="reservation-panel-new" role="tabpanel" aria-labelledby="reservation-tab-new" data-reservation-panel="new">
      <form
        class="form"
        id="reservaForm"
        data-schedules-endpoint="/api/reservaciones/disponibilidad"
        data-max-guests="<?php echo (int)$contactoReservas['max_comensales']; ?>"
        novalidate
      >
        <input type="hidden" name="request_token" value="<?php echo s($reservationRequestToken ?? ''); ?>">
        <div class="form__row reserva__identity" data-new-reservation-identity hidden>
          <div class="field">
            <label>Nombre</label>
            <input type="text" name="nombre" placeholder="Tu nombre" required />
            <span class="field__msg" data-field-error="nombre"></span>
          </div>
          <div class="field" data-new-reservation-contact>
            <label>Contacto a verificar</label>
            <div class="reservation-access__types reservation-access__types--compact">
              <label><input type="radio" name="tipo_contacto" value="email" checked><span>Correo</span></label>
              <label><input type="radio" name="tipo_contacto" value="telefono"><span>Teléfono</span></label>
            </div>
            <input type="email" name="contacto" placeholder="tu@correo.com" autocomplete="email" />
            <span class="field__msg" data-field-error="contacto"></span>
          </div>
        </div>
        <aside class="reserva__special-schedule" data-special-schedule hidden aria-live="polite">
          <span class="reserva__special-schedule-label" data-special-schedule-label>Horario especial</span>
          <strong data-special-schedule-date></strong>
          <span class="reserva__special-schedule-hours" data-special-schedule-hours></span>
          <p data-special-schedule-reason hidden></p>
          <small data-special-schedule-note>El horario habitual no aplica en esta fecha.</small>
          <small class="reserva__special-schedule-reference">Referencia habitual: <span data-special-schedule-regular></span></small>
        </aside>
        <div class="form__row">
          <div class="field">
            <label>Fecha</label>
            <?php
              $rootId = 'datePicker';
              $inputId = 'fechaHidden';
              $displayId = 'dateDisplay';
              $calendarId = 'cpCalendar';
              $name = 'fecha';
              $value = '';
              $min = \Services\ReservacionConfig::fechaActual();
              $disabled = false;
              // Las excepciones pueden abrir un día semanalmente cerrado; el backend resuelve cada fecha.
              $enabledWeekdays = range(0, 6);
              include __DIR__ . '/../components/reservations/date-picker.php';
            ?>
            <span class="field__msg" data-field-error="fecha"></span>
          </div>
          <div class="field">
            <label>Hora</label>
            <?php
              $rootId = 'hourPicker';
              $inputId = 'horaHidden';
              $displayId = 'hourDisplay';
              $dropdownId = 'hourDropdown';
              $name = 'hora';
              $value = '';
              $endpoint = '/api/reservaciones/disponibilidad';
              $disabled = false;
              include __DIR__ . '/../components/reservations/time-picker.php';
            ?>
            <span class="field__msg" id="hourStatus" data-field-error="hora"></span>
          </div>
        </div>
        <div class="reserva__selection-summary" data-reservation-selection-summary hidden aria-live="polite"></div>
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
              <input type="number" id="guestsNum" min="6" max="<?php echo (int)$contactoReservas['max_comensales']; ?>" value="6" aria-hidden="true" tabindex="-1" />
            </div>
          </div>
          <span class="field__msg" data-field-error="comensales"></span>
          <div class="large-party" id="largeParty" aria-live="polite" hidden>
            <div class="large-party__mark" aria-hidden="true">i</div>
            <div class="large-party__body">
              <h3>Tu grupo es mayor a 12 personas?</h3>
              <p>Para grupos grandes, contacta directamente al restaurante para revisar disponibilidad y preparar una atencion adecuada.</p>
            </div>
            <div class="large-party__links">
              <a href="tel:<?php echo s($contactoReservas['telefono_tel']); ?>"><?php echo s($contactoReservas['telefono_visible']); ?></a>
              <a href="<?php echo s($contactoReservas['whatsapp_url']); ?>" target="_blank" rel="noopener">WhatsApp</a>
            </div>
          </div>
        </div>
        <div class="field">
          <label>Ocasión (opcional)</label>
          <textarea name="nota" maxlength="<?php echo \Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" placeholder="Cumpleaños, aniversario, alergias..."></textarea>
          <span class="field__msg" data-field-error="nota"></span>
        </div>
        <div class="form__submit">
          <button type="submit" class="btn-line" data-magnetic><span>Confirmar reserva</span><span class="arrow">↗</span></button>
          <span class="form__msg" id="formMsg"></span>
        </div>
      </form>
      <section class="reservation-access__form reserva__otp-step" data-new-reservation-otp hidden aria-live="polite">
        <span class="eyebrow">Mesas retenidas</span>
        <h3>Verifica tu contacto</h3>
        <p data-new-reservation-countdown></p>
        <div class="field">
          <label for="new-reservation-otp">Código de seis dígitos</label>
          <input id="new-reservation-otp" type="text" inputmode="numeric" autocomplete="one-time-code"
            pattern="[0-9]{6}" maxlength="6" placeholder="000000" data-new-reservation-otp-input>
        </div>
        <div class="reservation-access__preview" data-new-reservation-preview hidden></div>
        <div class="form__submit">
          <button type="button" class="btn-line" data-new-reservation-verify><span>Confirmar reservación</span><span class="arrow">→</span></button>
          <button type="button" class="reservation-access__link" data-new-reservation-resend>Reenviar código</button>
        </div>
        <p class="reservation-access__message" data-new-reservation-otp-message></p>
      </section>
      <div class="reserva__confirm" id="reservaConfirm">
        <div class="mark">✓</div>
        <h3>¡Mesa reservada!</h3>
        <p id="confirmText">Te esperamos. Pronto recibirás más detalles en tu correo.</p>
      </div>
      </div>

      <div class="reservation-access" id="reservation-panel-manage" role="tabpanel"
        aria-labelledby="reservation-tab-manage" data-reservation-panel="manage" hidden>
        <div data-contact-access>
          <p class="reservation-access__lead">
            Verifica tu correo o teléfono para consultar tus reservaciones. No necesitas contraseña.
          </p>

          <form class="reservation-access__form" data-contact-request-form novalidate>
            <fieldset class="reservation-access__types">
              <legend>¿Cómo deseas identificarte?</legend>
              <label><input type="radio" name="tipo" value="email" checked><span>Correo electrónico</span></label>
              <label><input type="radio" name="tipo" value="telefono"><span>Teléfono</span></label>
            </fieldset>
            <div class="field">
              <label for="reservation-contact">Contacto</label>
              <input id="reservation-contact" name="contacto" type="email" autocomplete="email"
                placeholder="cliente@ejemplo.com" required data-contact-input>
              <small class="reservation-access__help" data-contact-help>
                Usaremos el correo en minúsculas y sin espacios externos.
              </small>
            </div>
            <div class="form__submit">
              <button type="submit" class="btn-line"><span>Solicitar código</span><span class="arrow">→</span></button>
            </div>
          </form>

          <form class="reservation-access__form" data-contact-verify-form hidden novalidate>
            <div class="field">
              <label for="reservation-otp">Código de seis dígitos</label>
              <input id="reservation-otp" name="codigo" type="text" inputmode="numeric"
                autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"
                placeholder="000000" required data-otp-input>
            </div>
            <div class="reservation-access__preview" data-otp-preview hidden></div>
            <div class="form__submit">
              <button type="submit" class="btn-line"><span>Verificar</span><span class="arrow">→</span></button>
              <button type="button" class="reservation-access__link" data-contact-restart>Cambiar contacto</button>
            </div>
          </form>

          <p class="reservation-access__message" data-contact-message role="status" aria-live="polite"></p>
        </div>

        <section class="reservation-portal" data-reservation-portal hidden aria-live="polite">
          <div class="reservation-portal__head">
            <div>
              <span class="eyebrow">Contacto verificado</span>
              <h3>Mis reservaciones</h3>
            </div>
            <button type="button" class="reservation-access__link" data-contact-logout>Cerrar sesión</button>
          </div>
          <p class="reservation-portal__summary" data-reservation-summary></p>
          <div class="reservation-portal__list" data-reservation-list></div>
          <p class="reservation-portal__limit" data-reservation-limit></p>
        </section>
      </div>
    </div>
  </div>
</section>
