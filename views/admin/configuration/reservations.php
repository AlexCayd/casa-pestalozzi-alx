<?php
$configuracion = is_array($configuracionReservaciones ?? null) ? $configuracionReservaciones : [];
$activo = !empty($configuracion['recordatorio_dia_anterior_activo']);
$hora = (string)($configuracion['hora_recordatorio'] ?? '18:00');
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="reservations">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Reservaciones</h1>
            <p class="admin-page__subtitle">Configura recordatorios automáticos y comunicaciones con clientes.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/configuracion">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                Volver
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="reservation-reminders-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="reservation-reminders-title">Recordatorios de reservaciones</h2>
                <p>Define si el restaurante contactará automáticamente a quienes tienen una reservación al día siguiente.</p>
            </div>
        </div>

        <form class="admin-reservation-settings" method="POST" action="/admin/configuracion/reservaciones" data-reservation-settings>
            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken ?? ''); ?>">

            <?php /*
              El mismo control que el horario semanal (hours.php): una fila con
              su interruptor en la cabecera y la rejilla de las 24 horas debajo,
              a todo el ancho. Un toque en la hora abre «en punto / y media».

              Antes era un campo desplegable metido en la mitad derecha de una
              rejilla de dos columnas: la izquierda quedaba con dos renglones y
              un palmo de vacío debajo, y la hora —lo único que se edita aquí—
              era la pieza más pequeña del panel. Toda la jornada a la vista
              cuesta lo mismo que abrir una lista, y se lee igual que el horario
              del restaurante, que es con lo que se compara.

              Se reutilizan las clases .admin-schedule__* tal cual: son el
              vocabulario de «elegir una hora» del módulo, y duplicarlas con
              otro nombre sólo serviría para que las dos rejillas se separaran.
            */ ?>
            <div class="admin-schedule__row admin-reservation-settings__row<?php echo $activo ? '' : ' is-closed'; ?>" data-reminder-row>
                <div class="admin-schedule__head">
                    <div class="admin-reservation-settings__intro">
                        <h3 class="admin-schedule__day" id="reminder-title">Recordatorio del día anterior</h3>
                        <p>Se envía a las reservaciones confirmadas del día siguiente que tengan un contacto válido.</p>
                    </div>

                    <label class="admin-switch">
                        <input type="checkbox" name="recordatorio_dia_anterior_activo" value="1"
                               aria-label="Enviar recordatorio automático"
                               data-reminder-enabled <?php echo $activo ? 'checked' : ''; ?>>
                        <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                        <span class="admin-switch__label" data-reminder-switch-label><?php echo $activo ? 'Activo' : 'Apagado'; ?></span>
                    </label>
                </div>

                <?php /*
                  El hidden es la fuente de verdad del envío; la rejilla sólo lo
                  escribe. NUNCA se deshabilita: un hidden deshabilitado no viaja
                  en el POST y el backend rechazaría el guardado con «La hora del
                  recordatorio debe usar el formato HH:MM». Apagar el recordatorio
                  no es borrar la hora. Lo mismo hace hours.php con los días
                  cerrados.
                */ ?>
                <div class="admin-field admin-schedule__picker">
                    <span class="admin-field__label" id="reminder-time-label">Hora de envío</span>
                    <input type="hidden" name="hora_recordatorio" value="<?php echo $h($hora); ?>" data-reminder-time>

                    <div class="admin-schedule__hours" role="group"
                         aria-labelledby="reminder-time-label"
                         data-reminder-hours>
                        <?php
                        /* La celda elegida sale marcada desde el servidor: sin
                           esto, hasta que corre el JS —o si no llega— la
                           rejilla no enseñaba ninguna hora y sólo el resumen
                           decía cuál era. */
                        $horaElegida = preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', $hora, $partesHora)
                            ? (int)$partesHora[1]
                            : null;
                        $enMediaHora = $horaElegida !== null && (int)$partesHora[2] !== 0;
                        ?>
                        <?php foreach (range(0, 23) as $horaDelDia) : ?>
                            <?php $esElegida = $horaDelDia === $horaElegida; ?>
                            <button type="button"
                                    class="admin-schedule__hour<?php echo $esElegida ? ' is-edge' . ($enMediaHora ? ' is-half' : '') : ''; ?>"
                                    data-reminder-hour="<?php echo $horaDelDia; ?>"
                                    aria-pressed="<?php echo $esElegida ? 'true' : 'false'; ?>"
                                    aria-haspopup="true"
                                    aria-expanded="false"
                                    title="<?php echo sprintf('%02d:00 o %02d:30', $horaDelDia, $horaDelDia); ?>"
                                    <?php echo $activo ? '' : 'disabled'; ?>>
                                <?php echo sprintf('%02d', $horaDelDia); ?><span class="admin-schedule__hour-min" data-schedule-hour-min><?php echo $esElegida && $enMediaHora ? ':30' : ''; ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <?php /* Resumen y zona horaria en un solo renglón: eran dos
                             ayudas apiladas bajo un campo y ninguna decía la
                             hora elegida con palabras. El primer render lo
                             escribe PHP para no depender del JS. */ ?>
                    <p class="admin-schedule__summary" data-reminder-summary aria-live="polite">
                        <?php echo $activo
                            ? 'Se envía a las ' . $h($hora) . ', hora de Casa Pestalozzi.'
                            : 'Apagado: no se envían recordatorios. Si lo activas, saldrán a las ' . $h($hora) . '.'; ?>
                    </p>
                    <span class="admin-field__error" data-field-error aria-live="polite"></span>
                </div>
            </div>

            <?php /* Estado delante y botón detrás, en el mismo orden que
                     horarios, anuncio y POS: el `margin-left: auto` del estado
                     empuja el grupo a la derecha en las cuatro pantallas de
                     Configuración. Invertido aquí, ésta era la única con el
                     botón a la izquierda. */ ?>
            <div class="admin-config-form-actions">
                <p class="admin-form-status" aria-live="polite"></p>
                <button type="submit" class="admin-btn admin-btn--primary">Guardar cambios</button>
            </div>
        </form>
    </section>
</section>
