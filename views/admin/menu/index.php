<section class="admin-menu admin-menu--hub admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Catalogo operativo</span>
            <h2 class="admin-page__title">Gestion de menu</h2>
            <p class="admin-page__subtitle">Administra por separado las categorias visibles y los platillos disponibles del menu publico.</p>
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
                <span class="admin-menu__eyebrow admin-page__eyebrow">Seccion</span>
                <h3>Categorias del menu</h3>
                <p>Organiza las familias que aparecen en la carta publica y en la administracion del catalogo.</p>
            </div>

            <div class="admin-menu__hub-meta">
                <strong><?php echo (int) ($totalCategorias ?? 0); ?></strong>
                <span>categorias registradas</span>
            </div>

            <div class="admin-menu__actions admin-actions">
                <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/menu/categories">Ver categorias</a>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/categories/create">Nueva categoria</a>
            </div>
        </article>

        <article class="admin-menu__hub-card admin-card">
            <div>
                <span class="admin-menu__eyebrow admin-page__eyebrow">Catalogo</span>
                <h3>Platillos</h3>
                <p>Gestiona nombres, descripciones, precios, etiquetas, estado visible y categoria asignada.</p>
            </div>

            <div class="admin-menu__hub-meta">
                <strong><?php echo (int) ($totalMenu ?? 0); ?></strong>
                <span>platillos registrados</span>
            </div>

            <div class="admin-menu__actions admin-actions">
                <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/menu/items">Ver platillos</a>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/menu/items/create">Nuevo platillo</a>
            </div>
        </article>
    </div>
</section>
