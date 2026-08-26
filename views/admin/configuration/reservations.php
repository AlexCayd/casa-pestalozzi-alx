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

            <div class="admin-reservation-settings__section">
                <div>
                    <h3>Recordatorio del día anterior</h3>
                    <p>Se enviará un recordatorio a las reservaciones confirmadas del día siguiente que tengan un contacto válido.</p>
                </div>

                <label class="admin-switch">
                    <input type="checkbox" name="recordatorio_dia_anterior_activo" value="1" data-reminder-enabled <?php echo $activo ? 'checked' : ''; ?>>
                    <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                    <span class="admin-switch__label">Enviar recordatorio automático</span>
                </label>

                <label class="admin-field admin-reservation-settings__time">
                    <span class="admin-field__label">Hora de envío</span>
                    <input type="time" name="hora_recordatorio" value="<?php echo $h($hora); ?>" required data-reminder-time <?php echo $activo ? '' : 'disabled'; ?>>
                    <span class="admin-field__help">Usa la zona horaria configurada para Casa Pestalozzi.</span>
                </label>
                <input type="hidden" name="hora_recordatorio" value="<?php echo $h($hora); ?>" data-reminder-time-fallback <?php echo $activo ? 'disabled' : ''; ?>>
            </div>

            <div class="admin-config-form-actions">
                <p class="admin-form-status" aria-live="polite"></p>
                <button type="submit" class="admin-btn admin-btn--primary">Guardar cambios</button>
            </div>
        </form>
    </section>
</section>
