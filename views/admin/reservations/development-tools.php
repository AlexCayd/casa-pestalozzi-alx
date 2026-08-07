<?php
$pendientes = is_array($pendientes ?? null) ? $pendientes : [];
$resultadoPendientes = is_array($resultadoPendientes ?? null) ? $resultadoPendientes : null;
?>

<section class="admin-reservations admin-page reservation-development-tools">
    <header class="admin-page__header admin-menu__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Solo APP_ENV=development</span>
            <h2 class="admin-page__title">Herramientas de desarrollo</h2>
            <p class="admin-page__subtitle">Procesos no destructivos para mantener el estado temporal de reservaciones durante el desarrollo.</p>
        </div>
        <a class="admin-btn admin-btn--secondary" href="/admin/reservations">Volver a reservaciones</a>
    </header>

    <?php if ($resultadoPendientes) : ?>
        <div class="admin-alert admin-alert--<?php echo !empty($resultadoPendientes['ok']) ? 'success' : 'error'; ?>">
            <strong><?php echo !empty($resultadoPendientes['ok']) ? 'Proceso terminado' : 'No se pudo procesar'; ?></strong>
            <span>Procesadas: <?php echo (int)($resultadoPendientes['procesadas'] ?? 0); ?>. Omitidas: <?php echo (int)($resultadoPendientes['omitidas'] ?? 0); ?>. Fallidas: <?php echo (int)($resultadoPendientes['fallidas'] ?? 0); ?>.</span>
        </div>
    <?php endif; ?>

    <article class="admin-card admin-config-card">
        <div class="admin-config-card__head">
            <div>
                <span class="admin-config-card__eyebrow">Mantenimiento temporal</span>
                <h3>Procesar pendientes vencidas</h3>
            </div>
            <span class="admin-badge admin-badge--warning"><?php echo (int)($pendientes['total'] ?? 0); ?> encontradas</span>
        </div>
        <p>Cambia a <code>expirada</code> únicamente las retenciones cuyo vencimiento ya alcanzó la hora de corte. No elimina filas ni relaciones.</p>
        <dl class="reservation-detail-list">
            <div><dt>Vista previa</dt><dd><?php echo (int)($pendientes['total'] ?? 0); ?> registros</dd></div>
            <div><dt>Hora de corte</dt><dd><?php echo htmlspecialchars((string)($pendientes['hora_corte'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
        </dl>
        <button class="admin-btn admin-btn--secondary" type="button" data-admin-modal-open="process-expired-modal"<?php echo empty($pendientes['total']) ? ' disabled' : ''; ?>>Procesar pendientes vencidas</button>
    </article>

    <div class="admin-modal" id="process-expired-modal" data-admin-modal hidden>
        <button class="admin-modal__backdrop" type="button" data-admin-modal-close></button>
        <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="process-expired-title" data-admin-modal-dialog>
            <div class="admin-modal__head">
                <div><span class="admin-modal__eyebrow">Confirmación</span><h2 id="process-expired-title" class="admin-modal__title">Procesar <?php echo (int)($pendientes['total'] ?? 0); ?> pendientes</h2></div>
                <button type="button" class="admin-modal__close" data-admin-modal-close>&times;</button>
            </div>
            <p class="admin-modal__text">Solo se procesarán retenciones vencidas; las pendientes vigentes no cambiarán.</p>
            <form method="POST" action="/admin/reservations/development-tools/process-expired">
                <input type="hidden" name="admin_csrf" value="<?php echo htmlspecialchars((string)($adminCsrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="confirmar" value="1">
                <div class="admin-modal__actions">
                    <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Volver</button>
                    <button type="submit" class="admin-btn admin-btn--primary">Procesar</button>
                </div>
            </form>
        </div>
    </div>
</section>
