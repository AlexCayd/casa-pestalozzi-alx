<div class="admin-modal" id="admin-problem-modal" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <div
        class="admin-modal__dialog admin-modal__dialog--wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="admin-problem-title"
        aria-describedby="admin-problem-description"
        tabindex="-1"
        data-admin-modal-dialog
    >
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">Soporte</span>
                <h2 class="admin-modal__title" id="admin-problem-title">Reportar un problema</h2>
                <p class="admin-modal__text" id="admin-problem-description">Cuéntanos qué ocurrió. La ruta actual se agregará automáticamente sin incluir parámetros.</p>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <form class="admin-modal__form" data-problem-report-form novalidate>
            <input type="hidden" name="ruta_origen" data-problem-route>

            <label class="admin-field admin-modal__field--wide">
                <span class="admin-field__label">Título</span>
                <input type="text" name="titulo" maxlength="120" required autocomplete="off">
            </label>

            <label class="admin-field">
                <span class="admin-field__label">Módulo</span>
                <input type="text" name="modulo" maxlength="80" required placeholder="Ej. Reservaciones">
            </label>

            <label class="admin-field">
                <span class="admin-field__label">Navegador</span>
                <select name="navegador" required data-problem-browser>
                    <option value="chrome">Chrome</option>
                    <option value="edge">Edge</option>
                    <option value="firefox">Firefox</option>
                    <option value="safari">Safari</option>
                    <option value="otro">Otro</option>
                </select>
            </label>

            <label class="admin-field admin-modal__field--wide" data-problem-browser-other hidden>
                <span class="admin-field__label">Otro navegador</span>
                <input type="text" name="navegador_otro" maxlength="80" autocomplete="off">
            </label>

            <label class="admin-field admin-modal__field--wide">
                <span class="admin-field__label">Descripción</span>
                <textarea name="descripcion" rows="5" maxlength="1200" required placeholder="Describe los pasos y lo que esperabas que sucediera."></textarea>
            </label>

            <p class="admin-modal__notice admin-modal__field--wide">El envío se habilitará cuando el servicio de reportes esté conectado.</p>
            <p class="admin-form-status admin-modal__field--wide" aria-live="polite" data-problem-status></p>

            <div class="admin-modal__actions admin-modal__field--wide">
                <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cancelar</button>
                <button type="button" class="admin-btn admin-btn--primary" data-problem-validate>Revisar reporte</button>
            </div>
        </form>
    </div>
</div>
