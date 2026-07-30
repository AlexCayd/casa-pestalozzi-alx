<?php
$pendientes = is_array($pendientes ?? null) ? $pendientes : [];
$filtros = is_array($filtrosLimpieza ?? null) ? $filtrosLimpieza : [];
$preview = is_array($vistaPreviaLimpieza ?? null) ? $vistaPreviaLimpieza : null;
$resultadoPendientes = is_array($resultadoPendientes ?? null) ? $resultadoPendientes : null;
$resultadoLimpieza = is_array($resultadoLimpieza ?? null) ? $resultadoLimpieza : null;
$fechaActual = (string)($fechaActual ?? date('Y-m-d'));
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$estados = (array)($filtros['estados'] ?? ['no_show', 'expirada', 'pendiente_verificacion']);
?>

<section class="admin-reservations admin-page reservation-development-tools">
    <header class="admin-page__header admin-menu__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Solo APP_ENV=development</span>
            <h2 class="admin-page__title">Herramientas de desarrollo</h2>
            <p class="admin-page__subtitle">Procesos temporales para mantener datos de reservaciones durante el desarrollo.</p>
        </div>
        <a class="admin-btn admin-btn--secondary" href="/admin/reservations">Volver a reservaciones</a>
    </header>

    <?php if ($resultadoPendientes) : ?>
        <div class="admin-alert admin-alert--<?php echo !empty($resultadoPendientes['ok']) ? 'success' : 'error'; ?>">
            <strong><?php echo !empty($resultadoPendientes['ok']) ? 'Proceso terminado' : 'No se pudo procesar'; ?></strong>
            <span>Procesadas: <?php echo (int)($resultadoPendientes['procesadas'] ?? 0); ?>. Omitidas: <?php echo (int)($resultadoPendientes['omitidas'] ?? 0); ?>. Fallidas: <?php echo (int)($resultadoPendientes['fallidas'] ?? 0); ?>.</span>
        </div>
    <?php endif; ?>

    <?php if ($resultadoLimpieza) : ?>
        <div class="admin-alert admin-alert--<?php echo !empty($resultadoLimpieza['ok']) ? 'success' : 'error'; ?>">
            <strong><?php echo !empty($resultadoLimpieza['ok']) ? 'Limpieza terminada' : 'La limpieza se revirtió'; ?></strong>
            <span>Procesadas: <?php echo (int)($resultadoLimpieza['procesadas'] ?? 0); ?>. Omitidas: <?php echo (int)($resultadoLimpieza['omitidas'] ?? 0); ?>. Fallidas: <?php echo (int)($resultadoLimpieza['fallidas'] ?? 0); ?>.</span>
        </div>
    <?php endif; ?>

    <div class="admin-config-grid">
        <article class="admin-card admin-config-card">
            <div class="admin-config-card__head">
                <div>
                    <span class="admin-config-card__eyebrow">Mantenimiento temporal</span>
                    <h3>Procesar pendientes vencidas</h3>
                </div>
                <span class="admin-badge admin-badge--warning"><?php echo (int)($pendientes['total'] ?? 0); ?> encontradas</span>
            </div>
            <p>Cambiará a <code>expirada</code> únicamente las retenciones cuyo vencimiento ya alcanzó la hora de corte.</p>
            <dl class="reservation-detail-list">
                <div><dt>Vista previa</dt><dd><?php echo (int)($pendientes['total'] ?? 0); ?> registros</dd></div>
                <div><dt>Hora de corte</dt><dd><?php echo $h($pendientes['hora_corte'] ?? ''); ?></dd></div>
            </dl>
            <button class="admin-btn admin-btn--secondary" type="button" data-admin-modal-open="process-expired-modal"<?php echo empty($pendientes['total']) ? ' disabled' : ''; ?>>Procesar pendientes vencidas</button>
        </article>

        <article class="admin-card admin-config-card">
            <div class="admin-config-card__head">
                <div>
                    <span class="admin-config-card__eyebrow">Acción destructiva</span>
                    <h3>Limpiar reservaciones de prueba</h3>
                </div>
            </div>
            <form method="POST" action="/admin/reservations/development-tools/cleanup-preview" class="admin-form">
                <div class="admin-form__grid">
                    <label class="admin-field"><span>Desde</span><input type="date" name="fecha_desde" required value="<?php echo $h($filtros['fecha_desde'] ?? $fechaActual); ?>"></label>
                    <label class="admin-field"><span>Hasta</span><input type="date" name="fecha_hasta" required value="<?php echo $h($filtros['fecha_hasta'] ?? $fechaActual); ?>"></label>
                    <label class="admin-field admin-modal__field--wide"><span>Prefijo de prueba opcional</span><input type="text" name="prefijo" maxlength="80" value="<?php echo $h($filtros['prefijo'] ?? ''); ?>" placeholder="TEST-"></label>
                </div>
                <fieldset class="admin-field">
                    <legend>Estados incluidos</legend>
                    <?php foreach (['no_show' => 'No show', 'expirada' => 'Expirada', 'pendiente_verificacion' => 'Pendiente de verificación'] as $value => $label) : ?>
                        <label><input type="checkbox" name="estados[]" value="<?php echo $value; ?>"<?php echo in_array($value, $estados, true) ? ' checked' : ''; ?>> <?php echo $label; ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <label class="admin-switch">
                    <input type="checkbox" name="incluir_pendientes_vigentes" value="1"<?php echo !empty($filtros['incluir_pendientes_vigentes']) ? ' checked' : ''; ?>>
                    <span>Incluir pendientes todavía vigentes (requiere segunda confirmación)</span>
                </label>
                <button class="admin-btn admin-btn--secondary" type="submit">Generar vista previa</button>
            </form>
        </article>
    </div>

    <?php if ($preview && !empty($preview['ok'])) : ?>
        <article class="admin-card reservation-cleanup-preview">
            <div class="admin-config-card__head">
                <div><span class="admin-config-card__eyebrow">Vista previa</span><h3>Resumen de limpieza</h3></div>
                <span class="admin-badge admin-badge--warning"><?php echo (int)$preview['procesables']; ?> eliminables</span>
            </div>
            <table class="admin-table">
                <tbody>
                    <tr><th>Rango</th><td><?php echo $h($preview['filtros']['fecha_desde']); ?> a <?php echo $h($preview['filtros']['fecha_hasta']); ?></td></tr>
                    <tr><th>Estados</th><td><?php echo $h(implode(', ', $preview['filtros']['estados'])); ?></td></tr>
                    <tr><th>Reservaciones</th><td><?php echo (int)$preview['procesables']; ?></td></tr>
                    <tr><th>Omitidas por seguridad</th><td><?php echo (int)$preview['omitidas']; ?></td></tr>
                    <tr><th>Relaciones de mesas</th><td><?php echo (int)$preview['relaciones']['mesas']; ?></td></tr>
                    <tr><th>Verificaciones</th><td><?php echo (int)$preview['relaciones']['verificaciones']; ?></td></tr>
                </tbody>
            </table>
            <p class="admin-alert admin-alert--warning">La eliminación no puede deshacerse. Los registros con evidencia operativa o ticket se omitirán.</p>
            <button class="admin-btn admin-btn--danger" type="button" data-admin-modal-open="cleanup-reservations-modal"<?php echo empty($preview['procesables']) ? ' disabled' : ''; ?>>Continuar con la limpieza</button>
        </article>
    <?php endif; ?>

    <div class="admin-modal" id="process-expired-modal" data-admin-modal hidden>
        <button class="admin-modal__backdrop" type="button" data-admin-modal-close></button>
        <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="process-expired-title" data-admin-modal-dialog>
            <div class="admin-modal__head"><div><span class="admin-modal__eyebrow">Confirmación</span><h2 id="process-expired-title" class="admin-modal__title">Procesar <?php echo (int)($pendientes['total'] ?? 0); ?> pendientes</h2></div><button type="button" class="admin-modal__close" data-admin-modal-close>&times;</button></div>
            <p class="admin-modal__text">Solo se procesarán retenciones vencidas; las pendientes vigentes no cambiarán.</p>
            <form method="POST" action="/admin/reservations/development-tools/process-expired">
                <input type="hidden" name="confirmar" value="1">
                <div class="admin-modal__actions"><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Volver</button><button type="submit" class="admin-btn admin-btn--primary">Procesar</button></div>
            </form>
        </div>
    </div>

    <?php if ($preview && !empty($preview['ok'])) : ?>
        <div class="admin-modal" id="cleanup-reservations-modal" data-admin-modal hidden>
            <button class="admin-modal__backdrop" type="button" data-admin-modal-close></button>
            <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cleanup-reservations-title" data-admin-modal-dialog>
                <div class="admin-modal__head"><div><span class="admin-modal__eyebrow">Acción destructiva</span><h2 id="cleanup-reservations-title" class="admin-modal__title">Eliminar datos de prueba</h2></div><button type="button" class="admin-modal__close" data-admin-modal-close>&times;</button></div>
                <form method="POST" action="/admin/reservations/development-tools/cleanup" class="admin-modal__form">
                    <input type="hidden" name="fecha_desde" value="<?php echo $h($preview['filtros']['fecha_desde']); ?>">
                    <input type="hidden" name="fecha_hasta" value="<?php echo $h($preview['filtros']['fecha_hasta']); ?>">
                    <input type="hidden" name="prefijo" value="<?php echo $h($preview['filtros']['prefijo']); ?>">
                    <?php foreach ($preview['filtros']['estados'] as $value) : ?><input type="hidden" name="estados[]" value="<?php echo $h($value); ?>"><?php endforeach; ?>
                    <?php if (!empty($preview['filtros']['incluir_pendientes_vigentes'])) : ?><input type="hidden" name="incluir_pendientes_vigentes" value="1"><?php endif; ?>
                    <label class="admin-field admin-modal__field--wide"><span>Escribe LIMPIAR RESERVACIONES</span><input type="text" name="confirmacion" required autocomplete="off"></label>
                    <?php if (!empty($preview['filtros']['incluir_pendientes_vigentes'])) : ?>
                        <label class="admin-field admin-modal__field--wide"><span>Escribe LIMPIAR PENDIENTES VIGENTES</span><input type="text" name="confirmacion_pendientes_vigentes" required autocomplete="off"></label>
                    <?php endif; ?>
                    <div class="admin-modal__actions admin-modal__field--wide"><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cancelar</button><button type="submit" class="admin-btn admin-btn--danger">Eliminar definitivamente</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</section>
