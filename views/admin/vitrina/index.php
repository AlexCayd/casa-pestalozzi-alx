<?php
/**
 * Vitrina de la caja (Panadería · Menú). Cómo se vende cada pan en la caja de
 * mostrador: sus opciones —relleno, tamaño, docena— y la pieza ya horneada
 * que descuenta cada venta. El servidor deja los datos en JSON y
 * src/js/admin/vitrina/vitrina.js pinta la lista y el editor; guarda por JSON
 * en una sola transacción (Services\Menu\VitrinaCaja).
 */
require_once __DIR__ . '/../partials/_icons.php';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="admin-page admin-vitrina" data-vitrina data-admin-csrf="<?= $h($adminCsrfToken ?? '') ?>" data-puede-editar="<?= !empty($vitrinaPuedeEditar) ? '1' : '0' ?>">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Menú · Panadería</span>
            <h1 class="admin-page__title">Vitrina de la caja</h1>
            <p class="admin-page__subtitle">
                En la caja cada pan se vende de una pieza ya horneada. Elige qué pieza descuenta y, si el pan tiene opciones —relleno, tamaño, docena—, cuáles salen de otra pieza o cuántas piezas llevan.
            </p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/panaderia/menu">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                Menú
            </a>
            <a class="admin-btn admin-btn--ghost" href="/panaderia/caja"><?= admin_icon('pdv', 16) ?> Abrir la caja</a>
        </div>
    </header>

    <?php if (empty($vitrinaPuedeEditar)) : ?>
        <p class="admin-vitrina__aviso"><?= admin_icon('info', 16) ?> Sólo consulta: editar la vitrina requiere Menú en edición de Panadería.</p>
    <?php endif; ?>

    <noscript><p class="admin-vitrina__aviso">Esta pantalla necesita JavaScript.</p></noscript>

    <script type="application/json" data-vitrina-datos><?= $vitrinaJson ?? '{}' ?></script>
    <div class="admin-vitrina__filtros">
        <label class="admin-vitrina__buscar">
            <span class="admin-vitrina__buscar-icono" aria-hidden="true"><?= admin_icon('buscar', 16) ?></span>
            <span class="admin-visually-hidden">Buscar pan</span>
            <input type="search" data-vitrina-buscar placeholder="Buscar pan o categoría" autocomplete="off">
        </label>
        <div class="admin-vitrina__resumen">
            <p class="admin-vitrina__conteo" data-vitrina-conteo></p>
            <?php /* Encontrar el pan que falta por configurar sin recorrer la lista
                     entera. Botón conmutador: la etiqueta no cambia, el estado
                     lo dice aria-pressed. El JS lo muestra sólo si hay alguno. */ ?>
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--small admin-vitrina__filtro-sin" data-vitrina-sin aria-pressed="false" hidden>
                Sólo sin pieza <span class="admin-num" data-vitrina-sin-n></span>
            </button>
        </div>
        <p class="admin-visually-hidden" data-vitrina-resultados aria-live="polite"></p>
    </div>
    <ol class="admin-vitrina__lista" data-vitrina-lista></ol>
    <template data-vitrina-icono="basura"><?= admin_icon('basura', 16) ?></template>
    <template data-vitrina-icono="mas"><?= admin_icon('mas', 16) ?></template>
    <template data-vitrina-icono="editar"><?= admin_icon('editar', 15) ?></template>
    <template data-vitrina-icono="info"><?= admin_icon('info', 16) ?></template>
    <template data-vitrina-icono="check"><?= admin_icon('check', 16) ?></template>
</section>
