<section class="admin-area admin-area--show admin-page" data-admin-area>
    <header class="admin-page__header admin-area__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Produccion</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($area['nombre'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="admin-page__subtitle">Items enviados, en preparacion y listos para esta estacion.</p>
        </div>

        <div class="admin-toolbar admin-area__toolbar">
            <span class="admin-area__live" id="area-refresh-info">
                <span class="admin-area__live-dot" aria-hidden="true"></span>
                Sin actualizar
            </span>
            <a class="admin-btn admin-btn--secondary admin-btn--small" href="/admin/area">Resumen</a>
        </div>
    </header>

    <nav class="admin-area-tabs" aria-label="Cambiar area de produccion">
        <?php foreach ($areas as $slug => $tabArea): ?>
            <a
                class="admin-area-tabs__link <?php echo $slug === $activeArea ? 'is-active' : ''; ?>"
                href="<?php echo htmlspecialchars($tabArea['path'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($tabArea['label'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="admin-area-board" aria-live="polite">
        <section class="admin-area-col admin-area-col--enviados">
            <header class="admin-area-col__head">
                <span>Enviado</span>
                <strong id="count-enviados">0</strong>
            </header>
            <div class="admin-area-col__items" id="list-enviados">
                <div class="admin-area-empty"><span>Sin pedidos</span></div>
            </div>
        </section>

        <section class="admin-area-col admin-area-col--prep">
            <header class="admin-area-col__head">
                <span>En preparacion</span>
                <strong id="count-prep">0</strong>
            </header>
            <div class="admin-area-col__items" id="list-prep">
                <div class="admin-area-empty"><span>Sin pedidos</span></div>
            </div>
        </section>

        <section class="admin-area-col admin-area-col--listo">
            <header class="admin-area-col__head">
                <span>Listo</span>
                <strong id="count-listo">0</strong>
            </header>
            <div class="admin-area-col__items" id="list-listo">
                <div class="admin-area-empty"><span>Sin pedidos</span></div>
            </div>
        </section>
    </div>
</section>

<script>
    window.CP_ADMIN_AREA = {
        id: <?php echo (int) $area['id']; ?>,
        nombre: '<?php echo htmlspecialchars($area['nombre'], ENT_QUOTES, 'UTF-8'); ?>',
        color: '<?php echo htmlspecialchars($area['color'], ENT_QUOTES, 'UTF-8'); ?>'
    };
</script>
