<?php
$configuracionPos = is_array($configuracionPos ?? null) ? $configuracionPos : [];
$meseroEditable = !empty($configuracionPos['mesero_editable']);
$impresionActiva = !empty($configuracionPos['impresion_activa']);
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="pos">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">POS</h1>
            <p class="admin-page__subtitle">Define cómo se comporta el punto de venta al abrir una mesa y si el servicio de impresión está enviando a las estaciones.</p>
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

    <?php /* El interruptor del servicio de impresión. Vivía en /admin/printers
             porque quien descubre que no sale el papel entra por ahí; se trajo
             aquí porque es un ajuste del POS —comparte fila con el de arriba en
             `configuracion_pos`— y no una propiedad de ninguna estación.

             Panel y formulario propios y no un segundo campo del de arriba: se
             guardan con sentencias distintas y el diálogo que pide confirmar la
             pausa no debe saltar al cambiar lo del mesero. */ ?>
    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="pos-impresion-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="pos-impresion-title">Servicio de impresión</h2>
                <p>
                    Interruptor global del envío a las impresoras térmicas. No da de baja ninguna
                    estación: las de <a href="/admin/printers">Estaciones de impresión</a> conservan
                    su rol, su área y su destino.
                </p>
            </div>
        </div>

        <form class="admin-pos-form" method="POST" action="/admin/configuracion/pos/impresion"
              data-impresion-form
              <?php /* El diálogo se declara siempre, no sólo cuando el servicio
                       está encendido: admin.js engancha su manejador al arrancar
                       sobre los formularios que ya llevan el atributo, así que
                       quitarlo después no lo desengancha. Quién decide si hay
                       que preguntar es configuration.js, mirando la casilla en
                       el momento del envío. */ ?>
              data-confirm-delete
              data-confirm-variant="warning"
              data-confirm-eyebrow="Servicio de impresión"
              data-confirm-title="¿Pausar la impresión?"
              data-confirm-description="Las comandas dejarán de salir en papel. El personal de cocina y barra tendrá que trabajar sólo con el tablero de producción."
              data-confirm-consequence="Se puede reanudar desde aquí en cualquier momento."
              data-confirm-primary="Pausar impresión">
            <label class="admin-switch">
                <input type="checkbox" name="impresion_activa" value="1" <?php echo $impresionActiva ? 'checked' : ''; ?>>
                <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label">Enviar comandas y cuentas a las impresoras</span>
            </label>

            <div class="admin-pos-setting__states">
                <div class="admin-pos-state<?php echo $impresionActiva ? ' is-current' : ''; ?>">
                    <span class="admin-pos-state__tag">Activado</span>
                    <p>
                        Cada comanda enviada desde el punto de venta y cada cuenta de cobro se mandan
                        a la impresora que les toca. Si una estación no está encendida o no responde,
                        el envío la <strong>espera dos segundos</strong> antes de rendirse — y una
                        orden con platillos de varias áreas espera ese tiempo por cada una.
                    </p>
                </div>
                <div class="admin-pos-state<?php echo $impresionActiva ? '' : ' is-current'; ?>">
                    <span class="admin-pos-state__tag">Desactivado</span>
                    <p>
                        <strong>No se envía nada a las impresoras.</strong> Los pedidos se guardan,
                        descuentan inventario y llegan al tablero de producción como siempre; lo
                        único que no ocurre es la impresión en papel. Úsalo mientras el hardware no
                        esté conectado, para que el punto de venta deje de esperarlas.
                    </p>
                </div>
            </div>

            <p class="admin-pos-form__note">
                «Imprimir prueba» sigue funcionando con el servicio pausado: es con lo que se
                comprueba una estación antes de reanudar. Y mientras esté pausado, las impresoras
                activas aparecen como «Pausada» en su listado — dejarlas rotuladas «Activa» sería
                la mentira que se va a leer cuando alguien venga a averiguar por qué no sale el papel.
            </p>

            <div class="admin-config-form-actions">
                <p class="admin-form-status" aria-live="polite"></p>
                <button type="submit" class="admin-btn admin-btn--primary">Guardar cambios</button>
            </div>
        </form>
    </section>
</section>
