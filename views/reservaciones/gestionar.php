<?php
use Services\ReservacionConfig;

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formulario = is_array($formulario ?? null) ? $formulario : null;
$sourceType = (string)($formulario['source_type'] ?? 'schedule_change');
$isReminder = $sourceType === 'reminder_next_day';
$canModify = !empty($formulario['can_modify']);
$canCancel = !empty($formulario['can_cancel']);
$fechaObjeto = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($formulario['fecha'] ?? ''), ReservacionConfig::timezone());
$mesesCortos = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$fechaCorta = $fechaObjeto
    ? $fechaObjeto->format('j') . ' ' . ($mesesCortos[(int)$fechaObjeto->format('n')] ?? $fechaObjeto->format('m')) . ' ' . $fechaObjeto->format('Y')
    : (string)($formulario['fecha'] ?? '');
$personasActuales = (int)($formulario['comensales'] ?? 0);
$pageTitle = $isReminder ? 'Gestiona tu reservación' : 'Elige un nuevo horario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($pageTitle); ?> · Casa Pestalozzi</title>
    <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
    <link rel="stylesheet" href="/build/css/app.css?v=reservation-management-v1">
</head>
<body class="schedule-change-page reserva">
    <header class="schedule-change-header">
        <a class="schedule-change-logo" href="/" aria-label="Casa Pestalozzi, inicio">
            <span class="schedule-change-logo__mark" aria-hidden="true"></span>
            <span>Casa Pestalozzi</span>
        </a>
        <span class="schedule-change-header__rule" aria-hidden="true"></span>
        <a class="schedule-change-header__back" href="/reservaciones">Reservaciones</a>
    </header>

    <main
        class="schedule-change-shell"
        data-schedule-change-page
        data-management-source="<?php echo $h($sourceType); ?>"
    >
        <?php if (!$formulario) : ?>
            <section class="schedule-change-card schedule-change-card--expired" aria-labelledby="management-expired-title">
                <p class="schedule-change-eyebrow">Gestionar reservación</p>
                <h1 id="management-expired-title">Este enlace venció</h1>
                <p>El acceso ya no está disponible. Puedes gestionar tu reservación desde el acceso habitual.</p>
                <a class="btn-line" href="/reservaciones">Gestionar reservación <span aria-hidden="true">→</span></a>
            </section>
        <?php else : ?>
            <input type="hidden" value="<?php echo $h($formulario['csrf_token']); ?>" data-management-csrf>
            <section
                class="schedule-change-card<?php echo !$canModify ? ' schedule-change-card--summary-only' : ''; ?>"
                aria-labelledby="reservation-management-title"
                data-schedule-change-card
                data-change-name="<?php echo $h($formulario['nombre']); ?>"
            >
                <div class="schedule-change-context">
                    <div class="schedule-change-card__intro">
                        <p class="schedule-change-eyebrow"><?php echo $isReminder ? 'Tu reservación es mañana' : 'Cambio de horario'; ?></p>
                        <h1 id="reservation-management-title"><?php echo $isReminder ? 'Gestiona tu reservación' : 'Elige un nuevo horario'; ?></h1>
                    </div>

                    <div class="schedule-change-current" aria-label="Resumen de reservación">
                        <p class="schedule-change-current__label">Reservación actual</p>
                        <p class="schedule-change-current__date"><?php echo $h($fechaCorta); ?></p>
                        <p class="schedule-change-current__details"><?php echo $h(substr((string)$formulario['hora'], 0, 5)); ?> · <?php echo $personasActuales; ?> <?php echo $personasActuales === 1 ? 'persona' : 'personas'; ?></p>
                        <div class="schedule-change-current__name">
                            <span>A nombre de</span>
                            <strong><?php echo $h($formulario['nombre']); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="schedule-change-editor">
                    <?php if ($canModify) : ?>
                        <p class="schedule-change-editor__eyebrow"><?php echo $isReminder ? 'Modificar reservación' : 'Nueva visita'; ?></p>
                        <form class="schedule-change-form" data-schedule-change-form data-max-guests="<?php echo (int)ReservacionConfig::MAX_COMENSALES_PUBLICO; ?>" novalidate>
                            <div class="field reservation-field reservation-field--date">
                                <span class="reservation-field__label">Fecha</span>
                                <?php
                                $rootId = 'schedule-change-date-picker';
                                $inputId = 'schedule-change-date';
                                $displayId = 'schedule-change-date-display';
                                $calendarId = 'schedule-change-calendar';
                                $name = 'fecha';
                                $value = '';
                                $min = $formulario['fecha_actual'];
                                $maxDate = '';
                                $today = $formulario['fecha_actual'];
                                $disabled = false;
                                $enabledWeekdays = range(0, 6);
                                $allowPast = false;
                                $required = true;
                                $inline = false;
                                $inputDataAttributes = ['data-change-date' => true];
                                include __DIR__ . '/../components/reservations/date-picker.php';
                                ?>
                            </div>

                            <div class="field reservation-field reservation-field--guests">
                                <span class="reservation-field__label">Personas</span>
                                <div class="pills reservation-guests reservation-guests--tabs" data-change-guest-pills<?php echo $personasActuales > 6 ? ' hidden' : ''; ?> role="group" aria-label="Cantidad de personas">
                                    <?php for ($personas = 1; $personas <= 6; $personas++) : ?>
                                        <button type="button" class="pill<?php echo $personasActuales === $personas ? ' sel' : ''; ?>" data-g="<?php echo $personas; ?>" aria-pressed="<?php echo $personasActuales === $personas ? 'true' : 'false'; ?>"><?php echo $personas; ?></button>
                                    <?php endfor; ?>
                                    <button type="button" class="pill<?php echo $personasActuales > 6 ? ' sel' : ''; ?>" data-g="7" data-change-guest-more aria-controls="schedule-change-guest-stepper" aria-expanded="<?php echo $personasActuales > 6 ? 'true' : 'false'; ?>">+</button>
                                </div>
                                <div class="guests-stepper" id="schedule-change-guest-stepper" data-change-guest-stepper<?php echo $personasActuales > 6 ? '' : ' hidden'; ?> role="group" aria-label="Ajustar cantidad de personas">
                                    <button class="step-btn" type="button" data-change-minus aria-label="Reducir personas" title="Reducir personas"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg></button>
                                    <output class="step-val" data-change-guests-value><?php echo $personasActuales; ?></output>
                                    <button class="step-btn" type="button" data-change-plus aria-label="Aumentar personas" title="Aumentar personas"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
                                </div>
                                <input type="hidden" name="personas" value="<?php echo $personasActuales; ?>" data-change-guests>
                                <span class="reservation-field__help">Hasta <?php echo (int)ReservacionConfig::MAX_COMENSALES_PUBLICO; ?> personas en este acceso.</span>
                            </div>

                            <div class="field reservation-field reservation-field--time">
                                <span class="reservation-field__label">Hora</span>
                                <?php
                                $rootId = 'schedule-change-time-picker';
                                $inputId = 'schedule-change-time';
                                $displayId = 'schedule-change-time-display';
                                $dropdownId = 'schedule-change-time-options';
                                $name = 'hora';
                                $value = '';
                                $endpoint = '/api/reservaciones/gestionar/disponibilidad';
                                $disabled = false;
                                $placeholder = 'Elige una hora';
                                $staticStep = 0;
                                $required = true;
                                $displayAriaDescribedby = 'schedule-change-time-hint';
                                $inline = false;
                                $inputDataAttributes = ['data-change-time' => true];
                                include __DIR__ . '/../components/reservations/time-picker.php';
                                ?>
                                <p class="reservation-field__help" id="schedule-change-time-hint" data-change-time-hint>Selecciona una fecha para ver horarios.</p>
                            </div>

                            <label class="field reservation-field" for="schedule-change-note">
                                <span class="reservation-field__label">Indicaciones <small>(opcional)</small></span>
                                <textarea class="reservation-control reservation-control--textarea" id="schedule-change-note" name="nota" maxlength="<?php echo (int)ReservacionConfig::NOTA_MAX_CARACTERES; ?>" rows="2" data-change-note><?php echo $h($formulario['nota']); ?></textarea>
                            </label>

                            <div class="schedule-change-form__action">
                                <p class="schedule-change-status" data-change-status role="status" aria-live="polite"></p>
                                <button class="btn-line schedule-change-submit" type="submit" data-change-submit>
                                    <?php echo $isReminder ? 'Modificar reservación' : 'Confirmar nuevo horario'; ?> <span aria-hidden="true">→</span>
                                </button>
                            </div>
                        </form>
                    <?php elseif ($isReminder && $personasActuales > ReservacionConfig::MAX_COMENSALES_PUBLICO) : ?>
                        <div class="schedule-change-limited" role="note">
                            <h2>La modificación requiere atención personal</h2>
                            <p>Para cambiar el horario de un grupo de más de 12 personas, contáctanos.</p>
                        </div>
                    <?php else : ?>
                        <div class="schedule-change-limited" role="note">
                            <h2>La modificación ya no está disponible</h2>
                            <p>Aún puedes cancelar la reservación si esa acción continúa habilitada.</p>
                        </div>
                    <?php endif; ?>

                    <?php if ($canCancel) : ?>
                        <div class="schedule-change-cancel-zone">
                            <button type="button" class="schedule-change-cancel" data-management-cancel-open>Cancelar reservación</button>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($canCancel) : ?>
                <div class="schedule-change-modal" data-management-cancel-modal hidden>
                    <button class="schedule-change-modal__backdrop" type="button" data-management-cancel-close aria-label="Cerrar confirmación"></button>
                    <section class="schedule-change-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cancel-reservation-title" aria-describedby="cancel-reservation-copy" tabindex="-1">
                        <h2 id="cancel-reservation-title">¿Cancelar esta reservación?</h2>
                        <p id="cancel-reservation-copy">Esta acción cancelará tu reservación. Si quieres conservarla, vuelve al resumen.</p>
                        <p class="schedule-change-status" data-cancel-status role="status" aria-live="polite"></p>
                        <div class="schedule-change-modal__actions">
                            <button type="button" class="btn-line" data-management-cancel-close>Volver</button>
                            <button type="button" class="schedule-change-danger" data-management-cancel-confirm>Cancelar reservación</button>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <footer class="schedule-change-footer">Casa Pestalozzi · Tu visita, a su tiempo.</footer>
    <script src="/build/js/bundle.min.js?v=reservation-management-v1" defer></script>
</body>
</html>
