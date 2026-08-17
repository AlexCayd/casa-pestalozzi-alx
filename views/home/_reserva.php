<?php
/**
 * Muestra el formulario publico de reservaciones y el aviso para grupos grandes.
 */

$contactoReservas = \Services\ReservacionConfig::contactoPublico();
$horariosOperacion = is_array($horariosOperacion ?? null) ? $horariosOperacion : [];
$proximasExcepcionesOperacion = is_array($proximasExcepcionesOperacion ?? null) ? $proximasExcepcionesOperacion : [];
$horariosOperacionDisponibles = (bool)($horariosOperacionDisponibles ?? false);
$mesesCortos = [
  1 => 'ENE',
  2 => 'FEB',
  3 => 'MAR',
  4 => 'ABR',
  5 => 'MAY',
  6 => 'JUN',
  7 => 'JUL',
  8 => 'AGO',
  9 => 'SEP',
  10 => 'OCT',
  11 => 'NOV',
  12 => 'DIC',
];
$hoyReserva = \DateTimeImmutable::createFromFormat(
  '!Y-m-d',
  \Services\ReservacionConfig::fechaActual(),
  \Services\ReservacionConfig::timezone()
);
$hoyDiaSemana = (int)$hoyReserva->format('w');

// Las excepciones se leen sobre la tabla de horario, no en una tarjeta aparte:
// el visitante conecta "el jueves cierran" con la fila del jueves. Solo caben
// las de los próximos siete días, que son las únicas con una fila que marcar;
// el resto se resume en una línea. El mapeo día→fecha se resuelve aquí, en la
// zona horaria del restaurante, porque el reloj del visitante puede ser otro.
$excepcionesPorDia = [];
$excepcionesPosteriores = [];
if ($horariosOperacionDisponibles) {
  $ventanaSemana = [];
  for ($i = 0; $i < 7; $i++) {
    $ventanaSemana[$hoyReserva->modify('+' . $i . ' days')->format('Y-m-d')] = $i;
  }
  foreach ($proximasExcepcionesOperacion as $excepcion) {
    $fechaExcepcion = (string)($excepcion['fecha'] ?? '');
    if (isset($ventanaSemana[$fechaExcepcion])) {
      $diaExcepcion = (int)$hoyReserva
        ->modify('+' . $ventanaSemana[$fechaExcepcion] . ' days')
        ->format('w');
      // Una sola excepción por fecha (uq_excepciones_operacion_fecha) y siete
      // fechas distintas: no hay colisión posible dentro de la ventana.
      $excepcionesPorDia[$diaExcepcion] = $excepcion;
      continue;
    }
    $excepcionesPosteriores[] = $excepcion;
  }
}

/** Devuelve "12 AGO" para la insignia de la fila y la línea de excepciones lejanas. */
$formatoFechaExcepcion = static function (string $fechaIso) use ($mesesCortos): string {
  $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaIso);
  if (!$fecha instanceof \DateTimeImmutable) {
    return $fechaIso;
  }
  return $fecha->format('j') . ' ' . ($mesesCortos[(int)$fecha->format('n')] ?? '');
};
?>
<section class="section reserva" id="reserva" data-screen-label="Reservar" aria-labelledby="reservation-section-title"
  data-reservation-csrf="<?php echo s($reservationCsrfToken ?? ''); ?>">
  <div class="wrap reserva__grid">
    <div class="reserva__intro">
      <span class="eyebrow" data-reveal>08 — Reservaciones</span>
      <h2 class="reserva__title" id="reservation-section-title" data-reveal>Reserva <em class="accent-italic">tu mesa</em></h2>
      <p class="body" data-reveal>Déjate sorprender por nuestros sabores en un espacio íntimo, con atención al detalle y servicio personalizado.</p>
    </div>
    <div class="reserva__hours" data-reveal>
      <h3>Horario habitual</h3>
        <?php if ($horariosOperacionDisponibles) : ?>
          <?php foreach ($horariosOperacion as $horario) : ?>
            <?php
              $diaSemana = (int)($horario['dia_semana'] ?? -1);
              $esHoy = $diaSemana === $hoyDiaSemana;
              $excepcionDia = $excepcionesPorDia[$diaSemana] ?? null;
              $esHorarioEspecial = $excepcionDia !== null
                && ($excepcionDia['tipo'] ?? '') === 'horario_especial';
              $claseFila = 'row';
              if ($esHoy) {
                $claseFila .= ' today';
              }
              if ($excepcionDia !== null) {
                $claseFila .= ' is-exception is-exception--' . ($esHorarioEspecial ? 'special' : 'closed');
              }
            ?>
            <div class="<?php echo $claseFila; ?>" data-day="<?php echo $diaSemana; ?>"
              <?php echo $excepcionDia !== null ? 'data-exception-date="' . s((string)$excepcionDia['fecha']) . '"' : ''; ?>>
              <span class="reserva__hours-day">
                <?php echo s($horario['nombre'] ?? ''); ?>
                <?php if ($esHoy) : ?><small class="reserva__hours-today">Hoy</small><?php endif; ?>
              </span>
              <span class="reserva__hours-value">
                <?php if ($excepcionDia !== null) : ?>
                  <strong class="reserva__hours-exception">
                    <?php if ($esHorarioEspecial) : ?>
                      <?php echo s(($excepcionDia['hora_apertura'] ?? '') . '–' . ($excepcionDia['hora_cierre'] ?? '')); ?>
                    <?php else : ?>
                      Cerrado
                    <?php endif; ?>
                  </strong>
                <?php elseif (!empty($horario['abierto'])) : ?>
                  <?php echo s(($horario['hora_apertura'] ?? '') . '–' . ($horario['hora_cierre'] ?? '')); ?>
                <?php else : ?>
                  Cerrado
                <?php endif; ?>
              </span>
              <?php if ($excepcionDia !== null) : ?>
                <span class="reserva__hours-badge">
                  <?php echo s($esHorarioEspecial ? 'Horario especial' : 'Cierre especial'); ?>
                  <?php echo s($formatoFechaExcepcion((string)$excepcionDia['fecha'])); ?>
                </span>
                <?php if (!empty($excepcionDia['motivo'])) : ?>
                  <small class="reserva__hours-reason"><?php echo s($excepcionDia['motivo']); ?></small>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($excepcionesPosteriores !== []) : ?>
            <p class="reserva__hours-upcoming">
              <span>Más adelante:</span>
              <?php
                $resumenPosteriores = array_map(
                  static function (array $excepcion) use ($formatoFechaExcepcion): string {
                    return $formatoFechaExcepcion((string)($excepcion['fecha'] ?? ''))
                      . ' · ' . (($excepcion['tipo'] ?? '') === 'horario_especial'
                        ? ($excepcion['hora_apertura'] ?? '') . '–' . ($excepcion['hora_cierre'] ?? '')
                        : 'cerrado');
                  },
                  $excepcionesPosteriores
                );
              ?>
              <?php echo s(implode(' · ', $resumenPosteriores)); ?>
            </p>
          <?php endif; ?>
        <?php else : ?>
          <p class="reserva__hours-unavailable">Consulta la disponibilidad al seleccionar tu fecha.</p>
        <?php endif; ?>
    </div>

    <div class="reserva__form-wrap" data-reveal>
      <div class="reservation-flow">
        <div class="reservation-access__tabs" role="tablist" aria-label="Elige una tarea de reservación">
        <button type="button" class="reservation-access__tab is-active" id="reservation-tab-new"
          role="tab" aria-selected="true" aria-controls="reservation-panel-new" data-reservation-tab="new">
          <span class="reservation-access__tab-title">Reservar una mesa</span>
        </button>
        <button type="button" class="reservation-access__tab" id="reservation-tab-manage"
          role="tab" aria-selected="false" aria-controls="reservation-panel-manage" data-reservation-tab="manage">
          <span class="reservation-access__tab-title">Gestionar reservación</span>
        </button>
        </div>
      </div>
      <div id="reservation-panel-new" role="tabpanel" aria-labelledby="reservation-tab-new" data-reservation-panel="new">
      <p class="reservation-panel__lead">Elige fecha, hora y comensales para comenzar.</p>
      <div class="reservation-progress__mobile" aria-live="polite">
        <div>
          <span class="reservation-progress__mobile-kicker">Paso <strong data-progress-current>1</strong> de 4</span>
          <strong class="reservation-progress__mobile-name" data-progress-name>Tu visita</strong>
        </div>
        <span class="reservation-progress__mobile-track" aria-hidden="true"><span data-progress-bar></span></span>
      </div>
      <nav class="reservation-progress" aria-label="Progreso de la reservación">
          <button type="button" class="reservation-progress__step is-current" data-reservation-step-target="1"
           aria-current="step" aria-label="Tu visita">
           <span class="reservation-progress__number">01</span>
           <span class="reservation-progress__label reservation-progress__label--wide">Tu visita</span>
           <span class="reservation-progress__label reservation-progress__label--compact" aria-hidden="true">Visita</span>
         </button>
         <span class="reservation-progress__line" data-reservation-progress-line="1" aria-hidden="true"></span>
         <button type="button" class="reservation-progress__step" data-reservation-step-target="2"
           aria-disabled="true" aria-label="Tus datos" disabled>
           <span class="reservation-progress__number">02</span>
           <span class="reservation-progress__label reservation-progress__label--wide">Tus datos</span>
           <span class="reservation-progress__label reservation-progress__label--compact" aria-hidden="true">Datos</span>
         </button>
         <span class="reservation-progress__line" data-reservation-progress-line="2" aria-hidden="true"></span>
         <button type="button" class="reservation-progress__step" data-reservation-step-target="3"
           aria-disabled="true" aria-label="Detalles" disabled>
           <span class="reservation-progress__number">03</span>
           <span class="reservation-progress__label reservation-progress__label--wide">Detalles</span>
           <span class="reservation-progress__label reservation-progress__label--compact" aria-hidden="true">Notas</span>
         </button>
         <span class="reservation-progress__line" data-reservation-progress-line="3" aria-hidden="true"></span>
         <button type="button" class="reservation-progress__step" data-reservation-step-target="4"
           aria-disabled="true" aria-label="Revisar" disabled>
           <span class="reservation-progress__number">04</span>
           <span class="reservation-progress__label reservation-progress__label--wide">Revisar</span>
           <span class="reservation-progress__label reservation-progress__label--compact" aria-hidden="true">Revisar</span>
         </button>
      </nav>
      <form
        class="form reservation-form"
        id="reservaForm"
        data-schedules-endpoint="/api/reservaciones/disponibilidad"
        data-max-guests="<?php echo (int)$contactoReservas['max_comensales']; ?>"
        data-restaurant-phone="<?php echo s($contactoReservas['telefono_visible']); ?>"
        data-restaurant-tel="<?php echo s($contactoReservas['telefono_tel']); ?>"
        data-restaurant-whatsapp="<?php echo s($contactoReservas['whatsapp_url']); ?>"
        novalidate
      >
        <input type="hidden" name="request_token" value="<?php echo s($reservationRequestToken ?? ''); ?>">
        <div class="reservation-stage-viewport">
        <section class="reservation-step reservation-step--visit is-active" data-reservation-step="1"
          aria-labelledby="reservation-visit-title">
          <div class="reservation-step__head">
            <span class="section-index" aria-hidden="true">01</span>
            <h3 id="reservation-visit-title" tabindex="-1">Selecciona tu visita</h3>
          </div>
          <p class="reservation-step__intro">Fecha, número de personas y horario.</p>
          <div class="reservation-visit-grid">
          <div class="field reservation-field reservation-field--date">
            <span class="reservation-field__label" id="dateLabel">Fecha</span>
            <?php
              $rootId = 'datePicker';
              $inputId = 'fechaHidden';
              $displayId = 'dateDisplay';
              $calendarId = 'cpCalendar';
              $name = 'fecha';
              $value = '';
              $min = \Services\ReservacionConfig::fechaActual();
              $maxDate = \Services\ReservacionConfig::ahora()
                ->modify('+' . \Services\ReservacionConfig::HORIZONTE_MAXIMO_DIAS . ' days')
                ->format('Y-m-d');
              $disabled = false;
              // Calendario siempre visible: elegir fecha no debería costar un
              // clic de apertura ni esconder el mes completo.
              $inline = true;
              // Las excepciones pueden abrir un día semanalmente cerrado; el backend resuelve cada fecha.
              $enabledWeekdays = range(0, 6);
              $displayAriaDescribedby = 'dateError';
              $displayAriaInvalid = false;
              include __DIR__ . '/../components/reservations/date-picker.php';
            ?>
            <span class="field__msg reservation-field__error" id="dateError" data-field-error="fecha"></span>
          </div>
          <div class="field reservation-field reservation-field--guests">
            <span class="reservation-field__label" id="guestsLabel">Comensales</span>
            <div class="pills reservation-guests reservation-guests--tabs" id="guestPills" role="group" aria-labelledby="guestsLabel">
              <button type="button" class="pill" data-g="1" aria-pressed="false">1</button>
              <button type="button" class="pill sel" data-g="2" aria-pressed="true">2</button>
              <button type="button" class="pill" data-g="3" aria-pressed="false">3</button>
              <button type="button" class="pill" data-g="4" aria-pressed="false">4</button>
              <button type="button" class="pill" data-g="5" aria-pressed="false">5</button>
              <button type="button" class="pill" data-g="6" aria-pressed="false">6</button>
              <button type="button" class="pill" data-g="7" aria-pressed="false"
                aria-controls="guestsExtra">+</button>
            </div>
            <div class="guests-extra" id="guestsExtra" hidden>
              <div class="guests-stepper" role="group" aria-label="Cantidad de comensales; después de 12 se activa la atención directa">
                <button type="button" class="step-btn" id="guestsMinus" aria-label="Reducir comensales">−</button>
                <span class="step-val" id="guestsVal" aria-live="polite">7</span>
                <button type="button" class="step-btn" id="guestsPlus" aria-label="Aumentar comensales">+</button>
                <input type="number" id="guestsNum" min="7" max="<?php echo (int)$contactoReservas['max_comensales']; ?>" value="7" aria-hidden="true" tabindex="-1" />
              </div>
            </div>
            <span class="field__msg reservation-field__error" data-field-error="comensales"></span>
          </div>
          <?php // Oculto hasta que haya fecha: sin ella la rejilla sólo puede
                // mostrar un placeholder, y ocupa el ancho de un campo real.
                // form.js lo revela desde updateInterface(). ?>
          <div class="field reservation-field reservation-field--time" data-reservation-time-field hidden>
            <span class="reservation-field__label" id="hourLabel">Horario</span>
            <?php
              $rootId = 'hourPicker';
              $inputId = 'horaHidden';
              $displayId = 'hourDisplay';
              $dropdownId = 'hourDropdown';
              $name = 'hora';
              $value = '';
              $endpoint = '/api/reservaciones/disponibilidad';
              $disabled = false;
              // Rejilla de horarios a la vista: son pocos y comparar es el paso.
              $inline = true;
              $displayAriaDescribedby = 'hourStatus';
              $displayAriaInvalid = false;
              include __DIR__ . '/../components/reservations/time-picker.php';
            ?>
            <span class="field__msg reservation-field__error" id="hourStatus" data-field-error="hora" role="status" aria-live="polite"></span>
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
          <aside class="reserva__selection-summary reservation-summary reservation-summary--compact" data-reservation-selection-summary hidden aria-live="polite">
            <div class="reservation-summary__head">
              <div>
                <span class="reservation-summary__eyebrow">Tu reservación</span>
                <strong data-selection-status>Completa tu selección</strong>
              </div>
              <button type="button" class="reservation-summary__edit" data-reservation-edit="1">Editar</button>
            </div>
            <div class="reservation-summary__details" data-selection-details>
              <div><small>Fecha y hora</small><strong data-selection-primary></strong></div>
              <div><small>Comensales</small><span data-selection-secondary></span></div>
            </div>
            <div class="reservation-summary__large-party" data-large-party-info hidden>
              <p>Las reservaciones para más de 12 personas se gestionan directamente con el restaurante.</p>
              <div class="reservation-summary__contact-actions">
                <a href="tel:<?php echo s($contactoReservas['telefono_tel']); ?>">
                  Llamar al <?php echo s($contactoReservas['telefono_visible']); ?>
                </a>
                <a href="<?php echo s($contactoReservas['whatsapp_url']); ?>" target="_blank" rel="noopener noreferrer">
                  Contactar por WhatsApp
                </a>
              </div>
              <small>Reduce el número de comensales para volver al flujo de reservación en línea.</small>
            </div>
          </aside>
          <div class="reservation-stage-actions reservation-stage-actions--single">
            <button type="button" class="btn-line" data-reservation-next disabled>
              <span>Continuar</span><span class="arrow">→</span>
            </button>
          </div>
        </section>

        <section class="reservation-step reserva__identity" data-reservation-step="2" hidden
          aria-labelledby="reservation-identity-title">
          <div class="reservation-step__head">
            <span class="section-index" aria-hidden="true">02</span>
            <h3 id="reservation-identity-title" tabindex="-1">¿A nombre de quién reservamos?</h3>
          </div>
          <p class="reservation-step__intro">Usaremos estos datos únicamente para confirmar tu visita.</p>
          <div class="reservation-data-grid">
            <div class="field reservation-field reservation-field--name">
              <label class="reservation-field__label" for="reservationName">Nombre</label>
              <input id="reservationName" class="reservation-control reservation-control--text" type="text" name="nombre" placeholder="Tu nombre" autocomplete="name" aria-describedby="nameError" required />
              <span class="field__msg reservation-field__error" id="nameError" data-field-error="nombre"></span>
            </div>
            <div class="reservation-contact-row">
              <div class="field reservation-field" data-new-reservation-contact>
                <fieldset class="reservation-access__types reservation-access__types--compact">
                  <legend>¿Cómo quieres recibir la confirmación?</legend>
                  <label><input type="radio" name="tipo_contacto" value="email" checked><span>Correo</span></label>
                  <label><input type="radio" name="tipo_contacto" value="telefono"><span>Teléfono</span></label>
                </fieldset>
              </div>
              <div class="field reservation-field reservation-field--contact" data-new-reservation-contact-input>
                <label class="reservation-field__label" for="reservationContact" data-contact-label>Correo electrónico</label>
                <?php /* El afijo +52 es fijo: el servidor exige E.164 y pedirlo a mano sobraba. */ ?>
                <div class="reservation-control-affix" data-contact-affix-wrap>
                  <span class="reservation-control__prefix" data-contact-prefix aria-hidden="true" hidden>+52</span>
                  <input id="reservationContact" class="reservation-control reservation-control--text" type="email" name="contacto" placeholder="cliente@ejemplo.com" autocomplete="email" aria-describedby="reservationContactHelp contactError" />
                </div>
                <small class="reservation-field__help" id="reservationContactHelp" data-new-contact-help>Te enviaremos aquí el código de confirmación.</small>
                <span class="field__msg reservation-field__error" id="contactError" data-field-error="contacto"></span>
              </div>
            </div>
          </div>
          <small class="reservation-field__help reservation-contact-verified-help" data-new-contact-verified-help hidden>
            Utilizaremos el contacto que ya verificaste para esta reservación.
            <button type="button" class="reservation-access__link" data-new-contact-change>Cambiar contacto</button>
          </small>
          <div class="reservation-stage-actions">
            <button type="button" class="btn-line btn-line--secondary reservation-stage-back" data-reservation-back>
              <span class="arrow">←</span><span>Atrás</span>
            </button>
            <button type="button" class="btn-line" data-reservation-next disabled>
              <span>Continuar</span><span class="arrow">→</span>
            </button>
          </div>
        </section>

        <section class="reservation-step reservation-step--optional" data-reservation-step="3" hidden
          aria-labelledby="reservation-details-title">
          <div class="reservation-step__head">
            <span class="section-index" aria-hidden="true">03</span>
            <h3 id="reservation-details-title" tabindex="-1">¿Necesitamos considerar algo?</h3>
          </div>
          <div class="field reservation-field">
            <label class="reservation-details-label" for="reservationOccasion">Indicaciones para tu visita <span>(opcional)</span></label>
            <textarea id="reservationOccasion" class="reservation-control reservation-control--textarea" name="nota" maxlength="<?php echo \Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" placeholder="Celebración, ubicación preferida, accesibilidad u otra indicación para tu visita…" aria-describedby="occasionError"></textarea>
            <span class="field__msg reservation-field__error" id="occasionError" data-field-error="nota"></span>
          </div>
          <div class="reservation-stage-actions">
            <button type="button" class="btn-line btn-line--secondary reservation-stage-back" data-reservation-back>
              <span class="arrow">←</span><span>Atrás</span>
            </button>
            <button type="button" class="btn-line" data-reservation-next>
              <span>Continuar</span><span class="arrow">→</span>
            </button>
          </div>
        </section>

        <section class="reservation-step reservation-step--review" data-reservation-step="4" hidden
          aria-labelledby="reservation-review-title">
          <div class="reservation-step__head">
            <span class="section-index" aria-hidden="true">04</span>
            <h3 id="reservation-review-title" tabindex="-1">Confirma los detalles</h3>
          </div>
          <div class="reservation-review__intro">
            <span>Confirma tu reservación</span>
          </div>
          <div class="reservation-review" data-reservation-review aria-live="polite">
            <section class="reservation-review__group">
              <div>
                <span class="reservation-review__label">Tu visita</span>
                <strong data-review-date>Selecciona fecha y hora</strong>
                <span data-review-guests>2 personas</span>
              </div>
              <button type="button" data-reservation-edit="1">Editar visita</button>
            </section>
            <section class="reservation-review__group">
              <div>
                <span class="reservation-review__label">Tus datos</span>
                <strong data-review-name>—</strong>
                <span data-review-method>Confirmación por correo</span>
                <span data-review-contact>—</span>
              </div>
              <button type="button" data-reservation-edit="2">Editar datos</button>
            </section>
            <section class="reservation-review__group" data-review-details-group hidden>
              <div>
                <span class="reservation-review__label">Indicaciones</span>
                <span data-review-details></span>
              </div>
              <button type="button" data-reservation-edit="3">Editar detalles</button>
            </section>
          </div>
          <div class="reservation-stage-actions">
            <button type="button" class="btn-line btn-line--secondary reservation-stage-back" data-reservation-back>
              <span class="arrow">←</span><span>Atrás</span>
            </button>
            <div class="reservation-submit">
              <p data-new-reservation-submit-help>Te enviaremos un código temporal para confirmar tu reservación.</p>
              <button type="submit" class="btn-line" data-magnetic disabled><span data-new-reservation-submit-label>Confirmar reservación</span><span class="arrow">→</span></button>
              <?php /* El aviso se menciona donde el visitante entrega los datos,
                       no sólo en el pie: aquí es donde la pregunta "¿qué van a
                       hacer con esto?" se le puede pasar por la cabeza. */ ?>
              <p class="reservation-privacy">
                Al confirmar, aceptas que usemos tus datos para gestionar esta reservación.
                <button type="button" class="reservation-privacy__link" data-privacidad-open>Aviso de privacidad</button>
              </p>
            </div>
            <span class="form__msg" id="formMsg" role="status" aria-live="polite"></span>
          </div>
        </section>
        </div>
      </form>
      <section class="reservation-access__form reserva__otp-step" data-new-reservation-otp hidden aria-live="polite">
        <div class="reservation-otp__head">
          <span class="eyebrow no-rule">Confirmación segura</span>
          <h3>Verifica tu contacto</h3>
          <p class="reservation-otp__contact">Código enviado a <strong data-new-reservation-contact></strong>.</p>
          <p class="reservation-otp__explanation">Este código valida tu medio de contacto. La reservación quedará confirmada después de validarlo.</p>
          <p class="reservation-otp__countdown" data-new-reservation-countdown></p>
        </div>
        <div class="field">
          <label for="new-reservation-otp">Código de seis dígitos</label>
          <input id="new-reservation-otp" type="text" inputmode="numeric" autocomplete="one-time-code"
            pattern="[0-9]{6}" maxlength="6" placeholder="000000" data-new-reservation-otp-input aria-describedby="new-reservation-otp-error">
          <span class="field__msg reservation-field__error" id="new-reservation-otp-error" data-new-reservation-otp-error></span>
        </div>
        <div class="form__submit reservation-otp__actions">
          <button type="button" class="btn-line" data-new-reservation-verify><span>Confirmar reservación</span><span class="arrow">→</span></button>
          <button type="button" class="reservation-access__link" data-new-reservation-resend>Reenviar código</button>
          <?php /* Sin esto, un contacto mal escrito deja al visitante sin salida. */ ?>
          <button type="button" class="reservation-access__link" data-new-reservation-change-contact>Cambiar contacto</button>
        </div>
        <p class="reservation-access__message" data-new-reservation-otp-message role="status" aria-live="polite"></p>
      </section>
      <div class="reserva__confirm" id="reservaConfirm" aria-live="polite">
        <div class="reserva__confirm-header">
          <span class="reserva__confirm-mark" aria-hidden="true">✓</span>
          <div>
            <span class="reservation-confirmation__status">Reservación confirmada</span>
            <h3>¡Mesa reservada!</h3>
            <p id="confirmText">Te esperamos en Casa Pestalozzi.</p>
          </div>
        </div>
        <div class="reserva__confirm-details">
          <div><small>Fecha</small><strong data-confirm-date>—</strong></div>
          <div><small>Hora</small><strong data-confirm-time>—</strong></div>
          <div><small>Comensales</small><strong data-confirm-guests>—</strong></div>
          <div class="reserva__confirm-contact"><small>Nombre</small><strong data-confirm-name>—</strong></div>
          <div class="reserva__confirm-contact"><small>Contacto</small><strong data-confirm-contact>—</strong></div>
        </div>
      </div>
      </div>

      <div class="reservation-access" id="reservation-panel-manage" role="tabpanel"
        aria-labelledby="reservation-tab-manage" data-reservation-panel="manage" hidden>
        <div data-contact-access>
          <div class="reservation-access__head">
            <span class="eyebrow">Gestión de reservaciones</span>
            <h3 data-contact-access-title>Verifica tu contacto</h3>
          </div>
          <p class="reservation-access__lead" data-contact-access-description>
            Te enviaremos un código temporal para consultar y gestionar tus reservaciones.
          </p>

          <form class="reservation-access__form" data-contact-request-form novalidate>
            <fieldset class="reservation-access__types">
              <legend>¿Cómo deseas identificarte?</legend>
              <label><input type="radio" name="tipo" value="email" checked><span>Correo electrónico</span></label>
              <label><input type="radio" name="tipo" value="telefono"><span>Teléfono</span></label>
            </fieldset>
            <div class="field">
              <label for="reservation-contact" data-manage-contact-label>Correo electrónico</label>
              <input id="reservation-contact" name="contacto" type="email" autocomplete="email"
                placeholder="cliente@ejemplo.com" required data-contact-input aria-describedby="reservation-manage-help">
              <small class="reservation-access__help" data-contact-help>
                Usaremos este correo para enviarte un código de acceso.
              </small>
            </div>
            <div class="form__submit">
              <button type="submit" class="btn-line"><span>Solicitar código</span><span class="arrow">→</span></button>
            </div>
          </form>

          <form class="reservation-access__form reservation-access__verify" data-contact-verify-form hidden novalidate>
            <div class="reservation-access__verify-copy">
              <span class="eyebrow no-rule">Contacto en proceso</span>
              <h4>Verifica tu contacto</h4>
              <p>El código valida el medio que elegiste para acceder a tus reservaciones.</p>
            </div>
            <p class="reservation-access__masked">Código enviado a <strong data-contact-masked></strong>.</p>
            <div class="field">
              <label for="reservation-otp">Código de seis dígitos</label>
              <input id="reservation-otp" name="codigo" type="text" inputmode="numeric"
                autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"
                placeholder="000000" required data-otp-input aria-describedby="reservation-manage-otp-error">
              <span class="field__msg reservation-field__error" id="reservation-manage-otp-error" data-contact-otp-error></span>
            </div>
            <div class="form__submit">
              <button type="submit" class="btn-line"><span>Verificar</span><span class="arrow">→</span></button>
              <button type="button" class="reservation-access__link" data-contact-resend>Reenviar código</button>
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
            <button type="button" class="reservation-access__link" data-contact-logout
              aria-label="Salir de gestión. Finaliza el acceso a las reservaciones asociadas a este contacto"
              title="Finaliza el acceso a las reservaciones asociadas a este contacto">Salir de gestión</button>
          </div>
          <p class="reservation-portal__lead">
            Puedes consultar tus reservaciones y gestionar los cambios disponibles durante esta sesión.
          </p>
          <p class="reservation-portal__summary" data-reservation-summary></p>
          <div class="reservation-portal__list" data-reservation-list></div>
          <p class="reservation-portal__limit" data-reservation-limit></p>
        </section>
      </div>
    </div>
  </div>

  <template data-reservation-editor-template>
    <form class="reservation-card__editor" novalidate>
      <div class="field reservation-field">
        <label class="reservation-field__label" data-editor-name-label>Nombre</label>
        <input class="reservation-control reservation-control--text" name="nombre" type="text" required readonly aria-readonly="true">
      </div>
      <div class="field reservation-field">
        <label class="reservation-field__label" data-editor-date-label for="reservationEditorDateDisplay">Fecha</label>
        <?php
          $rootId = 'reservationEditorDatePicker';
          $inputId = 'reservationEditorDate';
          $displayId = 'reservationEditorDateDisplay';
          $calendarId = 'reservationEditorCalendar';
          $name = 'fecha';
          $value = '';
          $min = \Services\ReservacionConfig::fechaActual();
          $maxDate = \Services\ReservacionConfig::ahora()
            ->modify('+' . \Services\ReservacionConfig::HORIZONTE_MAXIMO_DIAS . ' days')
            ->format('Y-m-d');
          $disabled = false;
          $enabledWeekdays = range(0, 6);
          include __DIR__ . '/../components/reservations/date-picker.php';
        ?>
      </div>
      <div class="field reservation-field">
        <label class="reservation-field__label" data-editor-time-label for="reservationEditorTimeDisplay">Hora</label>
        <?php
          $rootId = 'reservationEditorTimePicker';
          $inputId = 'reservationEditorTime';
          $displayId = 'reservationEditorTimeDisplay';
          $dropdownId = 'reservationEditorTimeDropdown';
          $name = 'hora';
          $value = '';
          $endpoint = '/api/reservaciones/disponibilidad';
          $disabled = false;
          include __DIR__ . '/../components/reservations/time-picker.php';
        ?>
        <span class="field__msg reservation-field__error" data-editor-time-status role="status" aria-live="polite"></span>
      </div>
      <div class="field reservation-field reservation-field--guests reservation-editor-guests">
        <label class="reservation-field__label" data-editor-people-label>Personas</label>
        <div class="guests-extra show" data-editor-guests-extra>
          <div class="guests-stepper" role="group" aria-label="Cantidad de comensales">
            <button type="button" class="step-btn" data-editor-guests-minus aria-label="Reducir comensales">−</button>
            <span class="step-val" data-editor-guests-value aria-live="polite">2</span>
            <button type="button" class="step-btn" data-editor-guests-plus aria-label="Aumentar comensales">+</button>
            <input class="reservation-editor-guests-input" name="personas" type="number" min="1" max="<?php echo (int)$contactoReservas['max_comensales']; ?>" value="2" required aria-hidden="true" tabindex="-1">
          </div>
        </div>
      </div>
      <div class="field reservation-field reservation-card__editor-notes">
        <label class="reservation-field__label" data-editor-notes-label>Indicaciones para tu visita</label>
        <textarea class="reservation-control reservation-control--textarea" name="notas" maxlength="<?php echo \Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" placeholder="Celebración, ubicación preferida, accesibilidad u otra indicación para tu visita…"></textarea>
      </div>
      <div class="reservation-card__editor-actions">
        <button type="submit" class="btn-line"><span>Aceptar</span></button>
        <button type="button" class="reservation-access__link" data-editor-cancel>Cancelar</button>
      </div>
      <p class="reservation-access__message" data-editor-message role="status" aria-live="polite"></p>
      <div class="reservation-card__editor-comparison-host" data-editor-comparison-host></div>
    </form>
  </template>
</section>
