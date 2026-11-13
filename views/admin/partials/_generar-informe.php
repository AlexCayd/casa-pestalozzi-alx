<?php
/** El controlador prepara $informeHref o $informeAviso para el periodo visible. */
?>
<?php if (!empty($informeHref)): ?>
    <a class="admin-btn admin-btn--secondary" href="<?= htmlspecialchars($informeHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" aria-label="Generar informe PDF (se abre en una pestaña nueva)">Generar informe</a>
<?php elseif (!empty($informeAviso)): ?>
    <span class="admin-btn admin-btn--disabled" aria-disabled="true">Generar informe</span>
    <span><?= htmlspecialchars($informeAviso, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
<?php endif; ?>
