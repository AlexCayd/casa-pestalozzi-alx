<section class="admin-page">
    <header class="admin-page__header"><h1 class="admin-page__title">No se pudo completar la operación</h1></header>
    <p role="alert"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></p>
    <a class="admin-btn admin-btn--secondary" href="<?= htmlspecialchars($volver, ENT_QUOTES, 'UTF-8') ?>">Volver al catálogo de este contexto</a>
</section>
