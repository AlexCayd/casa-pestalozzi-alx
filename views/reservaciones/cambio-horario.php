<?php
$h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formulario = is_array($formulario ?? null) ? $formulario : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modificar reservación · Casa Pestalozzi</title>
    <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
    <link rel="stylesheet" href="/build/css/app.css?v=schedule-change-access-v2">
</head>
<body class="schedule-change-page">
    <!--
        THESIS: una modificación breve y clara para devolver la visita a su nuevo horario.
        OWN-WORLD: negro Casa Pestalozzi, dorado editorial, serif de la landing y controles táctiles.
        STORY: el visitante reconoce su nombre, revisa el cambio y elige una nueva combinación disponible.
        FIRST VIEWPORT: encabezado de marca y formulario centrado; la acción primaria permanece al final del flujo.
        FORM: extensión del flujo público existente con date-picker, time-picker y stepper canónicos.
    -->
    <header class="schedule-change-header">
        <a class="schedule-change-logo" href="/" aria-label="Casa Pestalozzi, inicio">
            <img src="/build/images/logo.svg" alt="">
            <span>Casa Pestalozzi</span>
        </a>
        <span class="schedule-change-header__rule" aria-hidden="true"></span>
        <a class="schedule-change-header__back" href="/reservaciones">Reservaciones</a>
    </header>

    <main class="schedule-change-shell" data-schedule-change-page>
        <?php if (!$formulario) : ?>
            <section class="schedule-change-card schedule-change-card--expired" aria-labelledby="schedule-change-expired-title">
                <p class="schedule-change-eyebrow">Acceso temporal</p>
                <h1 id="schedule-change-expired-title">Este enlace ya no está disponible</h1>
                <p>El acceso pudo haber vencido o la reservación ya no necesita este cambio. Gestiona tu reservación desde el acceso habitual.</p>
                <a class="schedule-change-button" href="/reservaciones">Gestionar mi reservación <span aria-hidden="true">→</span></a>
            </section>
        <?php else : ?>
            <section class="schedule-change-card" aria-labelledby="schedule-change-title">
                <div class="schedule-change-card__intro">
                    <p class="schedule-change-eyebrow">Una actualización para tu visita</p>
                    <h1 id="schedule-change-title">Modifica tu reservación</h1>
                    <p>Necesitamos que ajustes tu visita debido a un cambio en nuestros horarios. Elige una nueva fecha y un horario disponible.</p>
                </div>

                <div class="schedule-change-current" aria-label="Reservación actual">
                    <div>
                        <span>Reservación actual</span>
                        <strong><?php echo $h($formulario['nombre']); ?></strong>
                    </div>
                    <p><?php echo $h($formulario['fecha']); ?> · <?php echo $h($formulario['hora']); ?> · <?php echo (int)$formulario['comensales']; ?> <?php echo (int)$formulario['comensales'] === 1 ? 'persona' : 'personas'; ?></p>
                </div>

                <form class="schedule-change-form" data-schedule-change-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $h($formulario['csrf_token']); ?>" data-change-csrf>

                    <div class="schedule-change-section">
                        <p class="schedule-change-section__title">Nueva fecha</p>
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
                        $inline = true;
                        $inputDataAttributes = ['data-change-date' => true];
                        include __DIR__ . '/../components/reservations/date-picker.php';
                        ?>
                        <p class="schedule-change-field__hint">Selecciona una fecha disponible.</p>
                    </div>

                    <div class="schedule-change-section">
                        <p class="schedule-change-section__title">Horario disponible</p>
                        <?php
                        $rootId = 'schedule-change-time-picker';
                        $inputId = 'schedule-change-time';
                        $displayId = 'schedule-change-time-display';
                        $dropdownId = 'schedule-change-time-options';
                        $name = 'hora';
                        $value = '';
                        $endpoint = '/api/reservaciones/cambio-horario/disponibilidad';
                        $disabled = false;
                        $placeholder = 'Elige una hora';
                        $staticStep = 0;
                        $required = true;
                        $inline = true;
                        $inputDataAttributes = ['data-change-time' => true];
                        include __DIR__ . '/../components/reservations/time-picker.php';
                        ?>
                        <p class="schedule-change-field__hint" data-change-time-hint>Elige una fecha para consultar horarios.</p>
                    </div>

                    <div class="schedule-change-section">
                        <p class="schedule-change-section__title">Comensales</p>
                        <div class="schedule-change-guests" role="group" aria-label="Cantidad de personas">
                            <div class="schedule-change-guest-pills" data-change-guest-pills>
                                <?php for ($personas = 1; $personas <= 6; $personas++) : ?>
                                    <button type="button" class="schedule-change-pill<?php echo (int)$formulario['comensales'] === $personas ? ' is-selected' : ''; ?>" data-change-guest="<?php echo $personas; ?>" aria-pressed="<?php echo (int)$formulario['comensales'] === $personas ? 'true' : 'false'; ?>"><?php echo $personas; ?></button>
                                <?php endfor; ?>
                                <button type="button" class="schedule-change-pill<?php echo (int)$formulario['comensales'] > 6 ? ' is-selected' : ''; ?>" data-change-guest-more aria-expanded="<?php echo (int)$formulario['comensales'] > 6 ? 'true' : 'false'; ?>">Más</button>
                            </div>
                            <div class="schedule-change-stepper" data-change-guest-stepper<?php echo (int)$formulario['comensales'] > 6 ? '' : ' hidden'; ?> role="group" aria-label="Ajustar cantidad de personas">
                                <button type="button" data-change-minus aria-label="Reducir personas">−</button>
                                <output data-change-guests-value><?php echo (int)$formulario['comensales']; ?></output>
                                <button type="button" data-change-plus aria-label="Aumentar personas">+</button>
                            </div>
                            <input type="hidden" name="personas" value="<?php echo (int)$formulario['comensales']; ?>" data-change-guests>
                        </div>
                        <p class="schedule-change-field__hint">Hasta <?php echo (int)\Services\ReservacionConfig::MAX_COMENSALES_PUBLICO; ?> personas en este acceso.</p>
                    </div>

                    <label class="schedule-change-section schedule-change-note" for="schedule-change-note">
                        <span class="schedule-change-section__title">Comentarios <small>(opcional)</small></span>
                        <textarea id="schedule-change-note" name="nota" maxlength="<?php echo (int)\Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" rows="3" data-change-note><?php echo $h($formulario['nota']); ?></textarea>
                    </label>

                    <p class="schedule-change-status" data-change-status role="status" aria-live="polite"></p>
                    <button class="schedule-change-button schedule-change-button--submit" type="submit" data-change-submit>
                        Actualizar reservación <span aria-hidden="true">→</span>
                    </button>
                </form>
            </section>
        <?php endif; ?>
    </main>

    <footer class="schedule-change-footer">Casa Pestalozzi · Tu visita, a su tiempo.</footer>
    <script src="/build/js/bundle.min.js?v=schedule-change-access-v2" defer></script>
</body>
</html>
