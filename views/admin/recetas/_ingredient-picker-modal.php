<?php
/**
 * Selector de ingredientes y subrecetas.
 *
 * Sustituye al desplegable que vivía dentro de la fila: iba en position:absolute
 * y lo recortaba el overflow:hidden de .admin-card, así que con el catálogo
 * completo no se podía hacer scroll y se salía de la pantalla. Aquí el listado
 * vive en un modal, con pestañas alfabéticas y varias columnas, y hace scroll
 * dentro de su propio diálogo.
 *
 * Lo rellena src/js/admin/recetas/recipe-builder.js a partir del JSON de
 * [data-recipe-options] del formulario. Se incluye una sola vez por página.
 */
?>
<div class="admin-modal admin-ingredient-picker" data-ingredient-picker hidden>
    <div class="admin-modal__backdrop" data-picker-close></div>
    <div class="admin-modal__dialog admin-modal__dialog--xwide" role="dialog" aria-modal="true" aria-labelledby="ingredient-picker-title">
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">Inventario</span>
                <h3 class="admin-modal__title" id="ingredient-picker-title">Elegir ingrediente</h3>
            </div>
            <button type="button" class="admin-modal__close" data-picker-close aria-label="Cerrar">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>

        <div class="admin-ingredient-picker__search">
            <input type="search" data-picker-search autocomplete="off"
                   placeholder="Buscar por nombre…" aria-label="Buscar ingrediente">
        </div>

        <div class="admin-ingredient-picker__tabs" role="tablist" aria-label="Filtrar por inicial" data-picker-tabs></div>

        <div class="admin-ingredient-picker__grid" data-picker-grid></div>
    </div>
</div>
