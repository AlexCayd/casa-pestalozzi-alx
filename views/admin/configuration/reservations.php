<?php
require_once __DIR__ . '/../partials/_icons.php';

$configuracion = is_array($configuracionReservaciones ?? null) ? $configuracionReservaciones : [];
$activo = !empty($configuracion['recordatorio_dia_anterior_activo']);
$hora = (string)($configuracion['hora_recordatorio'] ?? '18:00');
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$limites = is_array($limitesReservaciones ?? null) ? $limitesReservaciones : [];
$maxMinimo = (int)($limites['minimo'] ?? 7);
$maxTope = (int)($limites['tope'] ?? 16);
$acotarMaximo = static fn(int $valor): int => max($maxMinimo, min($maxTope, $valor));
$maxActual = $acotarMaximo((int)($limites['max_comensales_publico'] ?? 12));
// El que rige AHORA. Sólo difiere del de arriba cuando un guardado falló y la
// vista repinta lo que el admin intentó: el aviso compara contra éste porque
// lo que tiene que contar es qué cambia al guardar, no qué hay ya.
$maxGuardado = $acotarMaximo(\Services\Reservations\ReservacionConfig::maxComensalesPublico());
$whatsapp = (string)($limites['whatsapp_reservas'] ?? '');
$whatsappRespaldo = (string)($limites['whatsapp_respaldo'] ?? '');
$porTamano = is_array($limites['reservaciones_por_tamano'] ?? null) ? $limites['reservaciones_por_tamano'] : [];
$whatsappEfectivo = $whatsapp !== '' ? $whatsapp : $whatsappRespaldo;

/*
 * El aviso cuenta las reservas próximas que CAMBIAN de régimen al guardar: las
 * que quedan entre el máximo vigente y el nuevo. Contaba todas las que pasaban
 * del máximo, así que al abrir la pantalla con una reserva de grupo grande ya
 * existente decía «desde ahora el restaurante las atiende como grupo grande»
 * de algo que no iba a cambiar. Las mismas dos funciones viven en
 * initReservationLimits() (configuration.js): si se toca el texto aquí, se
 * toca allí.
 */
$reservasQueCambian = static function (int $desde, int $hasta) use ($porTamano): int {
    $bajo = min($desde, $hasta);
    $alto = max($desde, $hasta);
    $total = 0;
    foreach ($porTamano as $tamano => $cuantas) {
        if ((int)$tamano > $bajo && (int)$tamano <= $alto) {
            $total += (int)$cuantas;
        }
    }
    return $total;
};
$textoAvisoCambio = static function (int $cambian, bool $baja): string {
    if ($baja) {
        return $cambian === 1
            ? 'Al guardar, 1 reservación próxima queda por encima del máximo. No se cancela: el restaurante la atiende como grupo grande.'
            : "Al guardar, {$cambian} reservaciones próximas quedan por encima del máximo. No se cancelan: el restaurante las atiende como grupo grande.";
    }
    return $cambian === 1
        ? 'Al guardar, 1 reservación próxima deja de contar como grupo grande.'
        : "Al guardar, {$cambian} reservaciones próximas dejan de contar como grupo grande.";
};
$cambianAlGuardar = $maxActual === $maxGuardado ? 0 : $reservasQueCambian($maxGuardado, $maxActual);
$avisoCambio = $cambianAlGuardar > 0 ? $textoAvisoCambio($cambianAlGuardar, $maxActual < $maxGuardado) : '';
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="reservations">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Reservaciones</h1>
            <p class="admin-page__subtitle">Hasta qué tamaño de grupo se reserva en línea, a qué WhatsApp escriben los grupos grandes y los recordatorios automáticos.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/configuracion">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                Volver
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <?php /* Panel y formulario propios, como los dos del POS: los límites y el
             recordatorio comparten fila en `configuracion_reservaciones` pero se
             guardan con sentencias distintas, y cada una nombra sólo sus
             columnas. */ ?>
    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="reservation-limits-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="reservation-limits-title">Reserva en línea</h2>
                <p>Cuántas personas puede reservar un cliente sin hablar con el restaurante, y a qué WhatsApp escriben los grupos más grandes.</p>
            </div>
        </div>

        <form class="admin-reservation-limits" method="POST" action="/admin/configuracion/reservaciones/limites"
              data-reservation-limits
              data-saved-max="<?php echo $maxGuardado; ?>"
              data-future-sizes="<?php echo $h(json_encode((object)$porTamano)); ?>"
              novalidate>
            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken ?? ''); ?>">

            <?php /* Los dos campos cuentan una sola historia —el número que
                     parte a los clientes en dos y el WhatsApp al que van los
                     que quedan por encima— y se ponen lado a lado cuando el
                     panel tiene ancho para ello (container query en
                     configuration.scss). */ ?>
            <div class="admin-reservation-limits__fields">
                <div class="admin-field admin-reservation-limits__field">
                    <label class="admin-field__label" for="max-comensales-publico">Máximo de comensales por reserva</label>

                    <?php /* El input es un número real (teclado, flechas, envío
                             y `required` nativos); los botones sólo lo empujan.
                             En el tope llevan aria-disabled y no `disabled`:
                             deshabilitar el botón que tiene el foco lo tira al
                             <body> y quien va con teclado pierde su sitio. */ ?>
                    <div class="admin-stepper" data-stepper>
                        <button type="button" class="admin-stepper__btn" data-stepper-step="-1"
                                aria-label="Una persona menos" aria-controls="max-comensales-publico"
                                <?php echo $maxActual <= $maxMinimo ? 'aria-disabled="true"' : ''; ?>>
                            <?php echo admin_icon('menos', 18); ?>
                        </button>
                        <input id="max-comensales-publico"
                               class="admin-stepper__input"
                               type="number"
                               name="max_comensales_publico"
                               min="<?php echo $maxMinimo; ?>"
                               max="<?php echo $maxTope; ?>"
                               step="1"
                               inputmode="numeric"
                               value="<?php echo $maxActual; ?>"
                               required
                               aria-describedby="max-comensales-error max-comensales-resumen max-comensales-rango"
                               data-limits-max>
                        <button type="button" class="admin-stepper__btn" data-stepper-step="1"
                                aria-label="Una persona más" aria-controls="max-comensales-publico"
                                <?php echo $maxActual >= $maxTope ? 'aria-disabled="true"' : ''; ?>>
                            <?php echo admin_icon('mas', 18); ?>
                        </button>
                    </div>
                    <span class="admin-field__error" id="max-comensales-error" data-field-error aria-live="polite"></span>

                    <?php /* Los dos regímenes, uno debajo del otro, como las dos
                             tarjetas de estado del POS: el número solo no dice
                             que por encima de él la página deja de reservar. Las
                             cifras las reescribe el JS al mover el stepper.

                             El segundo se NOMBRA «grupo grande» porque es el
                             término con el que el aviso de abajo cuenta las
                             reservas que cambian de régimen —y el del buzón y
                             la ayuda del WhatsApp—: sin él, el aviso hablaba de
                             una categoría que la pantalla no definía. */ ?>
                    <dl class="admin-reservation-limits__regimes" id="max-comensales-resumen">
                        <div class="admin-reservation-limits__regime">
                            <dt>Hasta <span class="admin-num" data-limits-max-label><?php echo $maxActual; ?></span></dt>
                            <dd>Reservan en la página por su cuenta.</dd>
                        </div>
                        <div class="admin-reservation-limits__regime">
                            <dt><span class="admin-num" data-limits-over-label><?php echo $maxActual + 1; ?></span> o más</dt>
                            <dd>Grupo grande: el formulario se bloquea y les ofrece llamar o escribir por WhatsApp.</dd>
                        </div>
                    </dl>

                    <p class="admin-reservation-limits__notice" data-limits-notice<?php echo $avisoCambio !== '' ? '' : ' hidden'; ?>>
                        <?php echo admin_icon('info', 16, 'admin-reservation-limits__notice-icon'); ?>
                        <span data-limits-notice-text><?php echo $h($avisoCambio); ?></span>
                    </p>

                    <?php /* Prosa, así que las cifras van en la cara del texto: la
                             mono es para lo que se compara en columna, y aquí
                             sólo las dos primeras de cinco salían en mono. */ ?>
                    <p class="admin-field__hint" id="max-comensales-rango">
                        Entre <?php echo $maxMinimo; ?> y <?php echo $maxTope; ?>. Cada reserva de 9 personas o más aparta el exterior completo (Mesas 8 a 11, 16 asientos); por eso el tope es 16.
                    </p>

                    <?php /* Lo único que oye un lector de pantalla al pulsar
                             «más» o «menos»: el foco se queda en el botón y el
                             número cambia en otro elemento. Corto a propósito
                             —la explicación ya está en la descripción del
                             campo— y vacío al cargar, para no anunciar nada que
                             nadie ha pedido. */ ?>
                    <p class="admin-visually-hidden" aria-live="polite" data-limits-live></p>
                </div>

                <div class="admin-field admin-reservation-limits__field">
                    <label class="admin-field__label" for="whatsapp-reservas">WhatsApp del restaurante</label>
                    <input id="whatsapp-reservas"
                           class="admin-reservation-limits__whatsapp"
                           type="tel"
                           name="whatsapp_reservas"
                           inputmode="numeric"
                           autocomplete="off"
                           maxlength="20"
                           placeholder="<?php echo $h($whatsappRespaldo); ?>"
                           value="<?php echo $h($whatsapp); ?>"
                           aria-describedby="whatsapp-reservas-error whatsapp-reservas-ayuda"
                           data-limits-whatsapp
                           data-whatsapp-fallback="<?php echo $h($whatsappRespaldo); ?>">
                    <span class="admin-field__error" id="whatsapp-reservas-error" data-field-error aria-live="polite"></span>
                    <?php /* Espacios y guiones no se piden quitar: el servidor y
                             el JS se quedan sólo con los dígitos, y la vista
                             previa de abajo enseña cuáles. */ ?>
                    <p class="admin-field__hint" id="whatsapp-reservas-ayuda">
                        Con la lada del país (52 en México). Lo usan los grupos grandes y la agenda de catas.
                        Si lo dejas vacío, se usa el <span class="admin-num"><?php echo $h($whatsappRespaldo); ?></span>.
                    </p>
                    <?php /* El texto va en un solo tramo para que el subrayado
                             sea uno: el enlace es flex y cada hijo se subrayaba
                             por separado. */ ?>
                    <a class="admin-reservation-limits__preview"
                       href="https://wa.me/<?php echo $h($whatsappEfectivo); ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       data-limits-preview>
                        <span class="admin-reservation-limits__preview-text">Probar el enlace <span class="admin-num" data-limits-preview-number>wa.me/<?php echo $h($whatsappEfectivo); ?></span></span>
                        <?php echo admin_icon('externo', 14); ?>
                        <span class="admin-visually-hidden">(se abre en otra pestaña)</span>
                    </a>
                </div>
            </div>

            <div class="admin-config-form-actions">
                <p class="admin-form-status" aria-live="polite"></p>
                <button type="submit" class="admin-btn admin-btn--primary">Guardar cambios</button>
            </div>
        </form>
    </section>

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
