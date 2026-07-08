<section class="admin-area admin-area--index admin-page">
    <header class="admin-page__header admin-area__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Producción</span>
            <h2 class="admin-page__title">Áreas de producción</h2>
            <p class="admin-page__subtitle">Selecciona una estación para abrir su tablero KDS con los items enviados, en preparación y listos.</p>
        </div>
    </header>

    <div class="admin-area__grid admin-grid">
        <?php foreach ($areas as $slug => $area): ?>
            <a
                class="admin-area-card admin-card"
                style="--area-accent: <?php echo htmlspecialchars($area['color'], ENT_QUOTES, 'UTF-8'); ?>"
                href="<?php echo htmlspecialchars($area['path'], ENT_QUOTES, 'UTF-8'); ?>"
                target="_blank"
                rel="noopener"
            >
                <span class="admin-area-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 7h16"/><path d="M7 7v10a3 3 0 0 0 3 3h4a3 3 0 0 0 3-3V7"/><path d="M9 3v4"/><path d="M15 3v4"/><path d="M9 12h6"/>
                    </svg>
                </span>

                <div class="admin-area-card__body">
                    <span class="admin-area-card__eyebrow">Tablero KDS</span>
                    <h3><?php echo htmlspecialchars($area['label'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($area['nombre'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>

                <span class="admin-area-card__cta">
                    <span>Abrir tablero</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M7 17 17 7"/><path d="M7 7h10v10"/>
                    </svg>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
