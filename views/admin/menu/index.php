<section class="admin-menu admin-menu--hub admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Catálogo operativo</span>
            <h2 class="admin-page__title">Gestión de menú</h2>
            <p class="admin-page__subtitle">Administra por separado las categorías visibles y los platillos disponibles del menú público.</p>
        </div>
    </header>

    <?php foreach (($alertas ?? []) as $tipo => $mensajes) : ?>
        <?php foreach ($mensajes as $mensaje) : ?>
            <div class="admin-menu__flash admin-menu__flash--<?php echo htmlspecialchars($tipo); ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="admin-menu__hub-grid admin-grid">
        <article class="admin-menu__hub-card admin-card">
            <div>
                <span class="admin-menu__eyebrow admin-page__eyebrow">Sección</span>
                <h3>Categorías del menú</h3>
                <p>Organiza las familias que aparecen en la carta pública y en la administración del catálogo.</p>
            </div>

            <div class="admin-menu__hub-meta">
                <strong><?php echo (int) ($totalCategorias ?? 0); ?></strong>
                <span>categorías registradas</span>
            </div>

            <div class="admin-menu__actions admin-actions">
                <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/menu/categories">Ver categorías</a>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-create-button" href="/admin/menu/categories/create" title="Nueva categoría" aria-label="Nueva categoría">
                    <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 5v14"/>
                        <path d="M5 12h14"/>
                    </svg>
                    <span>Nueva categoría</span>
                </a>
            </div>
        </article>

        <article class="admin-menu__hub-card admin-card">
            <div>
                <span class="admin-menu__eyebrow admin-page__eyebrow">Catálogo</span>
                <h3>Platillos</h3>
                <p>Gestiona nombres, descripciones, precios, etiquetas, estado visible y categoría asignada.</p>
            </div>

            <div class="admin-menu__hub-meta">
                <strong><?php echo (int) ($totalMenu ?? 0); ?></strong>
                <span>platillos registrados</span>
            </div>

            <div class="admin-menu__actions admin-actions">
                <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/menu/items">Ver platillos</a>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-create-button" href="/admin/menu/items/create" title="Nuevo platillo" aria-label="Nuevo platillo">
                    <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 5v14"/>
                        <path d="M5 12h14"/>
                    </svg>
                    <span>Nuevo platillo</span>
                </a>
            </div>
        </article>
    </div>
</section>
