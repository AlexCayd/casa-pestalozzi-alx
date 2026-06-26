<section class="admin-area admin-area--index admin-page">
    <header class="admin-page__header admin-area__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Produccion</span>
            <h2 class="admin-page__title">Areas de produccion</h2>
            <p class="admin-page__subtitle">Selecciona una estacion para revisar items enviados, en preparacion y listos.</p>
        </div>
    </header>

    <div class="admin-area__grid admin-grid">
        <?php foreach ($areas as $slug => $area): ?>
            <article class="admin-area-card admin-card" style="--area-accent: <?php echo htmlspecialchars($area['color'], ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <span class="admin-area-card__eyebrow">KDS</span>
                    <h3><?php echo htmlspecialchars($area['label'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($area['nombre'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>

                <a class="admin-btn admin-btn--secondary admin-area-card__button" href="<?php echo htmlspecialchars($area['path'], ENT_QUOTES, 'UTF-8'); ?>">
                    Abrir tablero
                </a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
