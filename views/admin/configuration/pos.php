<?php
$configuracionPos = is_array($configuracionPos ?? null) ? $configuracionPos : [];
$meseroEditable = !empty($configuracionPos['mesero_editable']);
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="pos">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">POS</h1>
            <p class="admin-page__subtitle">Define cómo se comporta el punto de venta al abrir una mesa.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/configuracion">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Volver
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="pos-form-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="pos-form-title">Asignación de mesero</h2>
                <p>Controla el campo «Mesero» del modal que se abre al tocar una mesa libre o al iniciar el servicio de una reservación.</p>
            </div>
        </div>

        <form class="admin-pos-form" method="POST" action="/admin/configuracion/pos">
            <label class="admin-switch">
                <input type="checkbox" name="mesero_editable" value="1" <?php echo $meseroEditable ? 'checked' : ''; ?>>
                <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label">Permitir elegir el mesero manualmente</span>
            </label>

            <div class="admin-pos-setting__states">
                <div class="admin-pos-state<?php echo $meseroEditable ? ' is-current' : ''; ?>">
                    <span class="admin-pos-state__tag">Activado</span>
                    <p>
                        El campo queda <strong>editable</strong>. Llega preseleccionado el mesero de la
                        sesión y se puede cambiar por cualquier otro de la lista, o dejarlo sin asignar.
                        Útil cuando varios meseros comparten la misma tablet.
                    </p>
                </div>
                <div class="admin-pos-state<?php echo $meseroEditable ? '' : ' is-current'; ?>">
                    <span class="admin-pos-state__tag">Desactivado</span>
                    <p>
                        El campo queda <strong>bloqueado</strong> con el mesero de la sesión: el ticket
                        se registra siempre a nombre de quien tiene la cuenta abierta. Evita que una
                        venta se asigne a otro por descuido cuando cada quien trae su propio equipo.
                    </p>
                </div>
            </div>

            <p class="admin-pos-form__note">
                El bloqueo se aplica también en el servidor, no solo en pantalla. Si quien está en
                sesión no es un mesero asignable —un administrador o un cajero, que no aparecen en la
                lista— el campo se deja editable de todos modos: no hay ningún valor con el que
                bloquearlo.
            </p>

            <div class="admin-config-form-actions">
                <p class="admin-form-status" aria-live="polite"></p>
                <button type="submit" class="admin-btn admin-btn--primary">Guardar cambios</button>
            </div>
        </form>
    </section>
</section>
