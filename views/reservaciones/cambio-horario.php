<?php
$formulario = is_array($formulario ?? null) ? $formulario : null;
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Casa Pestalozzi · Cambio de reservación</title>
    <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
    <link rel="stylesheet" href="/build/css/app.css?v=schedule-change-access-v1">
</head>
<body class="schedule-change-page">
    <main class="schedule-change-shell" data-schedule-change-page>
        <?php if (!$formulario) : ?>
            <section class="schedule-change-card schedule-change-card--expired" aria-labelledby="schedule-change-expired-title">
                <p class="schedule-change-brand">Casa Pestalozzi</p>
                <h1 id="schedule-change-expired-title">Este enlace ha expirado</h1>
                <p>Por seguridad, el acceso directo para modificar tu reservación sólo está disponible durante un tiempo limitado.</p>
                <a class="schedule-change-button" href="/reservaciones">Gestionar mi reservación</a>
            </section>
        <?php else : ?>
            <!--
              THESIS: un acceso directo y acotado para corregir una reservación afectada.
              OWN-WORLD: superficie editorial clara, controles sobrios y una sola tarea visible.
              STORY: el comensal identifica su reservación, elige un horario y guarda el cambio.
              FIRST VIEWPORT: identidad, contexto actual y formulario completo sin landing ni OTP.
              FORM: formulario independiente de una reservación.
            -->
            <section class="schedule-change-card" aria-labelledby="schedule-change-title">
                <p class="schedule-change-brand">Casa Pestalozzi</p>
                <div class="schedule-change-card__intro">
                    <p class="schedule-change-kicker">Atención requerida</p>
                    <h1 id="schedule-change-title">Modifica tu reservación</h1>
                    <p>Debido a un cambio en nuestros horarios necesitamos que ajustes tu reservación.</p>
                </div>

                <div class="schedule-change-current" aria-label="Reservación actual">
                    <span>Reservación actual</span>
                    <strong><?php echo $h($formulario['fecha']); ?> · <?php echo $h($formulario['hora']); ?> · <?php echo (int)$formulario['comensales']; ?> personas</strong>
                </div>

                <form class="schedule-change-form" data-schedule-change-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $h($formulario['csrf_token']); ?>" data-change-csrf>
                    <label class="schedule-change-field schedule-change-field--readonly">
                        <span>Nombre</span>
                        <input type="text" value="<?php echo $h($formulario['nombre']); ?>" readonly aria-readonly="true">
                    </label>
                    <div class="schedule-change-form__grid">
                        <label class="schedule-change-field">
                            <span for="schedule-change-date">Nueva fecha</span>
                            <input id="schedule-change-date" type="date" name="fecha" min="<?php echo $h($formulario['fecha_actual']); ?>" required data-change-date>
                        </label>
                        <label class="schedule-change-field">
                            <span for="schedule-change-time">Nuevo horario</span>
                            <select id="schedule-change-time" name="hora" required data-change-time disabled>
                                <option value="">Selecciona una fecha</option>
                            </select>
                        </label>
                    </div>
                    <label class="schedule-change-field">
                        <span>Comensales</span>
                        <span class="schedule-change-stepper">
                            <button type="button" aria-label="Reducir comensales" data-change-minus>−</button>
                            <input type="number" name="comensales" min="1" max="<?php echo (int)Services\ReservacionConfig::MAX_COMENSALES_PUBLICO; ?>" value="<?php echo (int)$formulario['comensales']; ?>" required data-change-guests>
                            <button type="button" aria-label="Aumentar comensales" data-change-plus>+</button>
                        </span>
                    </label>
                    <label class="schedule-change-field">
                        <span for="schedule-change-note">Comentarios <small>(opcional)</small></span>
                        <textarea id="schedule-change-note" name="nota" maxlength="<?php echo (int)Services\ReservacionConfig::NOTA_MAX_CARACTERES; ?>" rows="4" data-change-note><?php echo $h($formulario['nota']); ?></textarea>
                    </label>
                    <p class="schedule-change-status" data-change-status role="status" aria-live="polite"></p>
                    <button class="schedule-change-button" type="submit" data-change-submit>Guardar modificación</button>
                </form>
            </section>
        <?php endif; ?>
    </main>
    <script src="/build/js/bundle.min.js?v=schedule-change-access-v1" defer></script>
</body>
</html>
