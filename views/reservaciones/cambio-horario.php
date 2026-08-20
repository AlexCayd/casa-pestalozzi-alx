<?php
use Services\ReservacionConfig;

$h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formulario = is_array($formulario ?? null) ? $formulario : null;
$fechaActual = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($formulario['fecha'] ?? ''), ReservacionConfig::timezone());
$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fechaActualLegible = $fechaActual
    ? $fechaActual->format('j') . ' de ' . ($meses[(int)$fechaActual->format('n')] ?? $fechaActual->format('m')) . ' de ' . $fechaActual->format('Y')
    : (string)($formulario['fecha'] ?? '');
$personasActuales = (int)($formulario['comensales'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elige un nuevo horario · Casa Pestalozzi</title>
    <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
    <link rel="stylesheet" href="/build/css/app.css?v=schedule-change-access-v3">
</head>
<body class="schedule-change-page reserva">
    <!--
        THESIS: una modificación breve y clara para devolver la visita a su nuevo horario.
        OWN-WORLD: negro Casa Pestalozzi, dorado editorial, serif de la landing y controles táctiles.
        STORY: el visitante reconoce su nombre, revisa el cambio y elige una nueva combinación disponible.
        FIRST VIEWPORT: encabezado de marca y formulario centrado; la acción primaria permanece al final del flujo.
        FORM: extensión del flujo público existente con date-picker, time-picker y controles canónicos.
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
                <p class="schedule-change-eyebrow">Cambio de horario</p>
                <h1 id="schedule-change-expired-title">Este enlace venció</h1>
                <p>Puedes gestionar tu reservación desde el acceso habitual.</p>
                <a class="btn-line" href="/reservaciones">Gestionar reservación <span aria-hidden="true">→</span></a>
            </section>
        <?php else : ?>
            <section class="schedule-change-card" aria-labelledby="schedule-change-title">
                <div class="schedule-change-card__intro">
                    <p class="schedule-change-eyebrow">Cambio de horario</p>
                    <h1 id="schedule-change-title">Elige un nuevo horario</h1>
                    <p>Tu reservación sigue confirmada. Selecciona una nueva fecha y hora para reprogramarla.</p>
                </div>

                <div class="schedule-change-current" aria-label="Reservación actual">
                    <div>
                        <span>Reservación actual</span>
                        <strong><?php echo $h($fechaActualLegible); ?> · <?php echo $h(substr((string)$formulario['hora'], 0, 5)); ?> · <?php echo $personasActuales; ?> <?php echo $personasActuales === 1 ? 'persona' : 'personas'; ?></strong>
                        <p><?php echo $h($formulario['nombre']); ?></p>
                    </div>
                </div>

                <form class="schedule-change-form" data-schedule-change-form data-max-guests="<?php echo (int)ReservacionConfig::MAX_COMENSALES_PUBLICO; ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $h($formulario['csrf_token']); ?>" data-change-csrf>

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
                        $inline = true;
                        $inputDataAttributes = ['data-change-date' => true];
                        include __DIR__ . '/../components/reservations/date-picker.php';
                        ?>
                    </div>

                    <div class="field reservation-field reservation-field--guests">
                        <span class="reservation-field__label">Personas</span>
                        <div class="pills reservation-guests reservation-guests--tabs" data-change-guest-pills role="group" aria-label="Cantidad de personas">
                            <?php for ($personas = 1; $personas <= 6; $personas++) : ?>
                                <button type="button" class="pill<?php echo $personasActuales === $personas ? ' sel' : ''; ?>" data-g="<?php echo $personas; ?>" aria-pressed="<?php echo $personasActuales === $personas ? 'true' : 'false'; ?>"><?php echo $personas; ?></button>
                            <?php endfor; ?>
                            <button type="button" class="pill<?php echo $personasActuales > 6 ? ' sel' : ''; ?>" data-g="7" data-change-guest-more aria-controls="schedule-change-guest-stepper" aria-expanded="<?php echo $personasActuales > 6 ? 'true' : 'false'; ?>">+</button>
                        </div>
                        <div class="guests-stepper" id="schedule-change-guest-stepper" data-change-guest-stepper<?php echo $personasActuales > 6 ? '' : ' hidden'; ?> role="group" aria-label="Ajustar cantidad de personas">
                            <button class="step-btn" type="button" data-change-minus aria-label="Reducir personas">−</button>
                            <output class="step-val" data-change-guests-value><?php echo $personasActuales; ?></output>
                            <button class="step-btn" type="button" data-change-plus aria-label="Aumentar personas">+</button>
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
                        $endpoint = '/api/reservaciones/cambio-horario/disponibilidad';
                        $disabled = false;
                        $placeholder = 'Elige una hora';
                        $staticStep = 0;
                        $required = true;
                        $inline = true;
                        $inputDataAttributes = ['data-change-time' => true];
                        include __DIR__ . '/../components/reservations/time-picker.php';
                        ?>
                        <p class="reservation-field__help" data-change-time-hint>Primero elige una fecha.</p>
                    </div>

                    <label class="field reservation-field" for="schedule-change-note">
                        <span class="reservation-field__label">Indicaciones <small>(opcional)</small></span>
                        <textarea class="reservation-control reservation-control--textarea" id="schedule-change-note" name="nota" maxlength="<?php echo (int)ReservacionConfig::NOTA_MAX_CARACTERES; ?>" rows="3" data-change-note><?php echo $h($formulario['nota']); ?></textarea>
                    </label>

                    <p class="schedule-change-status" data-change-status role="status" aria-live="polite"></p>
                    <button class="btn-line schedule-change-submit" type="submit" data-change-submit>
                        Confirmar nuevo horario <span aria-hidden="true">→</span>
                    </button>
                </form>
            </section>
        <?php endif; ?>
    </main>

    <footer class="schedule-change-footer">Casa Pestalozzi · Tu visita, a su tiempo.</footer>
    <script src="/build/js/bundle.min.js?v=schedule-change-access-v3" defer></script>
</body>
</html>
