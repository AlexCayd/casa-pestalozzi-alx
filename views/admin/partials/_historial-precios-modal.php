<?php
/**
 * Modal del histórico de precios, compartido por la ficha de ingrediente y la
 * de platillo. Uno solo por página: lo abre cualquier [data-historial-precios]
 * y el contenido llega por /admin/api/historial-precios.
 */
?>
<div class="admin-modal" id="historial-precios-modal" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <div class="admin-modal__dialog admin-modal__dialog--wide" role="dialog" aria-modal="true"
         aria-labelledby="historial-precios-title" tabindex="-1" data-admin-modal-dialog>
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">Histórico</span>
                <h2 class="admin-modal__title" id="historial-precios-title">Cambios de precio</h2>
                <p class="admin-modal__text" data-historial-subtitulo>—</p>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <?php /* data-scrollable lo registra motion.js para que Lenis le deje la
                 rueda: es un contenedor con scroll propio dentro del modal. */ ?>
        <div class="admin-historial" data-historial-cuerpo data-scrollable>
            <p class="admin-field__hint">Cargando…</p>
        </div>

        <div class="admin-modal__actions">
            <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cerrar</button>
        </div>
    </div>
</div>
