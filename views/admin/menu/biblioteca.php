<?php
require_once __DIR__.'/../partials/_icons.php';
$esc=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<section class="admin-page admin-home">
    <header class="admin-page__header"><div class="admin-page__intro"><h1 class="admin-page__title">Menús PDF</h1><p class="admin-page__subtitle">Cartas disponibles de tus negocios autorizados. Los precios y las ofertas se administran en cada negocio.</p></div></header>
    <?php if(!$documentos): ?><p class="admin-empty">No hay documentos disponibles con tus permisos actuales.</p><?php else: ?><div class="admin-access-grid"><?php $tarjetas=$documentos;include __DIR__.'/../contextos/_accesos.php';unset($tarjetas); ?></div><?php endif; ?>
</section>
