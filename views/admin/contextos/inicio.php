<?php
require_once __DIR__.'/../partials/_icons.php';
$esc=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<section class="admin-context-page admin-home admin-page">
    <header class="admin-page__header"><div class="admin-page__intro"><h1 class="admin-page__title">Inicio · <?= $esc($nombreInicio) ?></h1><p class="admin-page__subtitle">Elige una tarea para empezar. Los accesos corresponden a tus permisos actuales.</p></div></header>
    <?php foreach ($seccionesInicio as $seccion) : ?>
    <section class="admin-home__section" aria-label="<?= $esc($seccion['titulo']) ?>">
        <h2><?= $esc($seccion['titulo']) ?></h2>
        <?php if (!$seccion['tarjetas']) : ?><p class="admin-empty"><?= $esc($seccion['vacio']) ?></p><?php else : ?>
        <div class="admin-access-grid"><?php $tarjetas=$seccion['tarjetas']; include __DIR__.'/_accesos.php'; unset($tarjetas); ?></div>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
    <?php if ($inicioContexto==='panaderia') : ?><p class="admin-field__hint">POS, recetas administrativas y producción se habilitarán en una etapa posterior.</p><?php endif; ?>
</section>
