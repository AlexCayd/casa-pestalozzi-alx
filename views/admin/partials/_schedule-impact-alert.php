<?php if (($pendingScheduleImpactReservations ?? 0) > 0) : ?>
    <aside class="admin-schedule-impact-alert" role="status" aria-live="polite">
        <div class="admin-schedule-impact-alert__copy">
            <strong><?php echo (int)$pendingScheduleImpactReservations; ?> <?php echo (int)$pendingScheduleImpactReservations === 1 ? 'reservación requiere' : 'reservaciones requieren'; ?> seguimiento</strong>
            <span>por cambios de horario.</span>
        </div>
        <a class="admin-schedule-impact-alert__action" href="/admin/configuracion/horarios<?php echo !empty($pendingScheduleImpactId) ? '?impacto_id=' . (int)$pendingScheduleImpactId : ''; ?>">Resolver</a>
    </aside>
<?php endif; ?>
