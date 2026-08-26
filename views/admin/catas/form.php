<?php
/**
 * Alta y edición de una cata. El mismo formulario sirve para ambas: lo que
 * cambia es la etiqueta del botón y si viaja el id.
 */

$estados = $estados ?? [];
$accion = (string)($accion ?? 'Guardar');
$adminCsrfToken = (string)($adminCsrfToken ?? \Services\AdminCsrfService::token());

$e = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$etiquetaEstado = [
    'borrador'  => 'Borrador — no se ve en la landing',
    'publicada' => 'Publicada — visible y admitiendo inscripciones',
    'agotada'   => 'Agotada — visible pero cerrada',
    'realizada' => 'Realizada — ya ocurrió',
    'cancelada' => 'Cancelada',
];

$esEdicion = !empty($cata->id);
?>
<section class="admin-catas admin-catas--form admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Experiencias</span>
            <h2 class="admin-page__title"><?php echo $e($title ?? 'Cata'); ?></h2>
            <p class="admin-page__subtitle">
                El cupo se cuenta en personas, no en inscripciones: alguien que reserva para
                cuatro ocupa cuatro lugares.
            </p>
        </div>
        <a class="admin-btn admin-btn--secondary" href="/admin/catas">Volver</a>
    </header>

    <section class="admin-panel admin-card">
        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <form class="admin-catas__form" method="POST"
              action="/admin/catas/<?php echo $esEdicion ? 'editar' : 'crear'; ?>">
            <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
            <?php if ($esEdicion) : ?>
                <input type="hidden" name="id" value="<?php echo (int)$cata->id; ?>">
            <?php endif; ?>

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
                <span class="admin-field__hint">Es el texto que se lee en la landing debajo del título.</span>
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
                    <label class="admin-field__label" for="duracion_min">Duración (minutos)</label>
                    <input class="admin-catas__input" type="number" id="duracion_min" name="duracion_min"
                           min="15" max="600" step="15" required
                           value="<?php echo (int)($cata->duracion_min ?? 90); ?>">
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="cupo">Cupo (personas)</label>
                    <input class="admin-catas__input" type="number" id="cupo" name="cupo"
                           min="1" max="200" required
                           value="<?php echo (int)($cata->cupo ?? 12); ?>">
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="precio">Precio por persona</label>
                    <input class="admin-catas__input" type="number" id="precio" name="precio"
                           min="0" step="0.01" required
                           value="<?php echo $e(number_format((float)($cata->precio ?? 0), 2, '.', '')); ?>">
                </div>

                <div class="admin-field">
                    <label class="admin-field__label" for="estado">Estado</label>
                    <select class="admin-catas__input" id="estado" name="estado" required>
                        <?php foreach ($estados as $estado) : ?>
                            <option value="<?php echo $e($estado); ?>"
                                <?php echo ($cata->estado ?? 'borrador') === $estado ? 'selected' : ''; ?>>
                                <?php echo $e($etiquetaEstado[$estado] ?? ucfirst($estado)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="admin-field">
                <label class="admin-field__label" for="imagen">Imagen</label>
                <input class="admin-catas__input" type="text" id="imagen" name="imagen" maxlength="180"
                       placeholder="/build/images/maridaje-1.webp"
                       value="<?php echo $e($cata->imagen ?? ''); ?>">
                <span class="admin-field__hint">
                    Ruta de una imagen ya subida a <code>/build/images</code>. Si se deja vacío se
                    usa la imagen por defecto de la sección.
                </span>
            </div>

            <div class="admin-catas__form-actions admin-actions">
                <a class="admin-btn admin-btn--ghost" href="/admin/catas">Cancelar</a>
                <button class="admin-btn admin-btn--primary" type="submit"><?php echo $e($accion); ?></button>
            </div>
        </form>
    </section>
</section>
