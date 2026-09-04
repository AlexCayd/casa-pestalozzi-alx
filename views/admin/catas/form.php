<?php
/**
 * Alta y edición de una cata. El mismo formulario sirve para ambas: lo que
 * cambia es la etiqueta del botón y si viaja el id.
 *
 * Se quedó en seis campos. Perdió el cupo —ya no hay lugares que contar— y la
 * ruta de imagen, que llevaba tiempo sin pintarse en ninguna parte; el estado de
 * cinco valores es hoy el mismo interruptor que la lista, y va al final, después
 * de lo que se publica, porque es lo último que se decide.
 *
 * En dos bloques y no en una rejilla suelta: arriba lo que el visitante LEE,
 * abajo cuándo y cuánto. Con los seis campos en la misma rejilla el título
 * quedaba a la misma altura visual que la duración en minutos.
 */

$accion = (string)($accion ?? 'Guardar');
$adminCsrfToken = (string)($adminCsrfToken ?? \Services\AdminCsrfService::token());

$e = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$esEdicion = !empty($cata->id);
$disponible = !empty($cata->disponible);
?>
<section class="admin-catas admin-catas--form admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Experiencias</span>
            <h2 class="admin-page__title"><?php echo $e($title ?? 'Cata'); ?></h2>
            <p class="admin-page__subtitle">
                Lo que escribas aquí es lo que se lee en la sección de catas de la landing.
            </p>
        </div>
        <a class="admin-btn admin-btn--secondary" href="/admin/catas">Volver a la agenda</a>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <form class="admin-catas__form" method="POST"
          action="/admin/catas/<?php echo $esEdicion ? 'editar' : 'crear'; ?>">
        <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
        <?php if ($esEdicion) : ?>
            <input type="hidden" name="id" value="<?php echo (int)$cata->id; ?>">
        <?php endif; ?>

        <section class="admin-panel admin-card">
            <div class="admin-catas__form-head">
                <h3>Qué se anuncia</h3>
                <p>El título y la descripción son el texto público de la cata.</p>
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="titulo">Título</label>
                <input class="admin-catas__input" type="text" id="titulo" name="titulo" maxlength="120" required
                       placeholder="Cata de tintos mexicanos"
                       value="<?php echo $e($cata->titulo ?? ''); ?>">
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="descripcion">Descripción</label>
                <textarea class="admin-catas__textarea" id="descripcion" name="descripcion" rows="4"
                          placeholder="Qué se prueba, quién la dirige, qué incluye…"><?php echo $e($cata->descripcion ?? ''); ?></textarea>
                <span class="admin-field__hint">Se lee en la landing debajo del título. Puede quedar vacía.</span>
            </div>
        </section>

        <section class="admin-panel admin-card">
            <div class="admin-catas__form-head">
                <h3>Cuándo y cuánto</h3>
                <p>La fecha ordena la agenda pública; las pasadas dejan de mostrarse solas.</p>
            </div>

            <div class="admin-catas__grid">
                <div class="admin-field">
                    <label class="admin-field__label" for="fecha">Fecha</label>
                    <input class="admin-catas__input" type="date" id="fecha" name="fecha" required
                           value="<?php echo $e($cata->fecha ?? ''); ?>">
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="hora">Hora de inicio</label>
                    <input class="admin-catas__input" type="time" id="hora" name="hora" required
                           value="<?php echo $e($cata->hora ?? '19:00'); ?>">
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="duracion_min">Duración</label>
                    <div class="admin-catas__unidad">
                        <input class="admin-catas__input" type="number" id="duracion_min" name="duracion_min"
                               min="15" max="600" step="15" required
                               value="<?php echo (int)($cata->duracion_min ?? 90); ?>">
                        <span class="admin-catas__unidad-sufijo">min</span>
                    </div>
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="precio">Precio por persona</label>
                    <div class="admin-catas__unidad">
                        <span class="admin-catas__unidad-prefijo">$</span>
                        <input class="admin-catas__input" type="number" id="precio" name="precio"
                               min="0" step="0.01" required
                               value="<?php echo $e(number_format((float)($cata->precio ?? 0), 2, '.', '')); ?>">
                    </div>
                </div>
            </div>
        </section>

        <section class="admin-panel admin-card admin-catas__publicacion">
            <div class="admin-catas__form-head">
                <h3>Cupo</h3>
                <p>
                    Con el interruptor encendido la cata admite gente por WhatsApp. Apagado se
                    marca como llena. En los dos casos <strong>sigue anunciada en la landing</strong>
                    mientras la fecha no haya pasado: lo que decide si se ve es el calendario,
                    no este interruptor.
                </p>
            </div>

            <label class="admin-switch" for="disponible">
                <input type="checkbox" id="disponible" name="disponible" value="1" <?php echo $disponible ? 'checked' : ''; ?>>
                <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                <span class="admin-switch__label" data-cata-switch-label><?php echo $disponible ? 'Con cupo' : 'Sin cupo'; ?></span>
            </label>
        </section>

        <div class="admin-catas__form-actions admin-actions">
            <a class="admin-btn admin-btn--ghost" href="/admin/catas">Cancelar</a>
            <button class="admin-btn admin-btn--primary" type="submit"><?php echo $e($accion); ?></button>
        </div>
    </form>
</section>
