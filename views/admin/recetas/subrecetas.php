<?php
    require_once __DIR__ . '/../partials/_icons.php';

    $subrecetas = isset($subrecetas) && is_iterable($subrecetas) ? $subrecetas : [];
    $conteosSub = is_array($conteosSub ?? null) ? $conteosSub : [];
    $esc = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    $cant = static fn ($valor): string => str_replace('-', '−', rtrim(rtrim(number_format((float) $valor, 3, '.', ','), '0'), '.'));

    /*
     * Estado de la existencia de una subreceta por lote. Sólo lo que falta
     * lleva marca, como en Existencias de Inventario: con una pastilla en
     * cada fila, la de «bajo el mínimo» dejaría de destacar. El negativo se
     * nombra aparte porque dice otra cosa: se vendió más de lo que se registró
     * como producido.
     */
    $estadoDe = static function ($sub): array {
        $stock = (float) $sub->stock;
        $min = (float) $sub->stock_minimo;
        if ($stock < 0) {
            return ['danger', 'En negativo'];
        }
        if ($stock <= 0) {
            return ['danger', 'Agotada'];
        }
        if ($min > 0 && $stock <= $min) {
            return ['warning', 'Bajo el mínimo'];
        }
        return ['', ''];
    };

    $porProducir = 0;
    foreach ($subrecetas as $sub) {
        if ((int) $sub->por_lote === 1 && (int) $sub->activo === 1 && $estadoDe($sub)[0] !== '') {
            $porProducir++;
        }
    }
?>
<section class="admin-recetas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Productos</span>
            <h2 class="admin-page__title">Subrecetas</h2>
            <p class="admin-page__subtitle">Preparaciones intermedias (salsas, bases, mezclas). Las que se hacen por lote se producen por adelantado: producir saca sus ingredientes del almacén, y vender un platillo descuenta sólo la subreceta.</p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/recetas">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                Volver
            </a>
            <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/recetas/subrecetas/create">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                <span>Nueva subreceta</span>
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-panel admin-card">
        <?php if (empty($subrecetas)) : ?>
            <p class="admin-empty">No hay subrecetas registradas.</p>
        <?php else : ?>
            <?php if ($porProducir > 0) : ?>
                <?php /* La cifra la dice la pastilla; la frase sólo explica el criterio,
                         con el mismo vocabulario que la columna de existencia. */ ?>
                <p class="admin-recetas__aviso">
                    <span class="admin-badge admin-badge--warning"><?php echo $porProducir; ?> por producir</span>
                    <?php echo $porProducir === 1 ? 'Está bajo su mínimo, agotada o en negativo.' : 'Están bajo su mínimo, agotadas o en negativo.'; ?>
                </p>
            <?php endif; ?>
            <div class="admin-table-wrap" data-scrollable>
                <table class="admin-table admin-recetas__subrecetas">
                    <thead>
                        <tr>
                            <th scope="col">Subreceta</th>
                            <th scope="col" class="admin-recetas__col-num">Existencia</th>
                            <?php /* «Rinde» y no «Un lote»: la columna también la llevan
                                     las de al momento, que no se hacen por lote. */ ?>
                            <th scope="col" class="admin-recetas__col-num">Rinde</th>
                            <th scope="col">Ingredientes</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subrecetas as $sub) : ?>
                            <?php
                                $n = (int) ($conteosSub[(int) $sub->id] ?? 0);
                                $porLote = (int) $sub->por_lote === 1;
                                $unidad = (string) $sub->unidad;
                                // Una inactiva no se produce ni cuenta en el aviso: su «Agotada»
                                // en rojo sería una alarma sin nada que hacer con ella.
                                [$tono, $etiqueta] = $porLote && (int) $sub->activo === 1 ? $estadoDe($sub) : ['', ''];
                            ?>
                            <tr data-row-href="/admin/recetas/subrecetas/edit?id=<?php echo (int) $sub->id; ?>">
                                <td>
                                    <span class="admin-recetas__nombre"><?php echo $esc($sub->nombre); ?></span>
                                    <span class="admin-table__cell-sub"><?php echo $porLote ? 'Por lote' : 'Al momento'; ?></span>
                                </td>
                                <td class="admin-recetas__col-num">
                                    <?php if ($porLote) : ?>
                                        <?php /* El estado DEBAJO de la cifra: a su lado empujaba
                                                 cada número según el ancho de su pastilla y la
                                                 columna dejaba de leerse en vertical. */ ?>
                                        <span class="admin-recetas__cifra-estado">
                                            <span class="admin-num"><?php echo $cant($sub->stock); ?> <?php echo $esc($unidad); ?></span>
                                            <?php if ($etiqueta !== '') : ?>
                                                <span class="admin-badge admin-badge--<?php echo $tono; ?>"><?php echo $esc($etiqueta); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php else : ?>
                                        <?php /* Al momento no hay existencia que mirar: la venta
                                                descuenta sus ingredientes. */ ?>
                                        <span class="admin-table__cell-sub">Sin existencia propia</span>
                                    <?php endif; ?>
                                </td>
                                <td class="admin-recetas__col-num">
                                    <span class="admin-num"><?php echo $cant($sub->rendimiento); ?> <?php echo $esc($unidad); ?></span>
                                </td>
                                <td>
                                    <?php if ($n > 0) : ?>
                                        <span class="admin-badge admin-badge--neutral"><?php echo $n; ?></span>
                                    <?php else : ?>
                                        <span class="admin-badge admin-badge--warning">Sin ingredientes</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="admin-badge admin-badge--<?php echo $sub->activo ? 'neutral' : 'danger'; ?>"><?php echo $sub->activo ? 'Activa' : 'Inactiva'; ?></span></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <?php if ($porLote && $sub->activo) : ?>
                                            <a class="admin-btn admin-btn--secondary admin-btn--small"
                                               href="/admin/recetas/subrecetas/producir?id=<?php echo (int) $sub->id; ?>"
                                               aria-label="Producir <?php echo $esc($sub->nombre); ?>">
                                                <?php echo admin_icon('mas', 15, 'admin-btn__icon'); ?>
                                                Producir
                                            </a>
                                        <?php endif; ?>
                                        <a class="admin-icon-button admin-icon-button--edit" href="/admin/recetas/subrecetas/edit?id=<?php echo (int) $sub->id; ?>" title="Editar" aria-label="Editar <?php echo $esc($sub->nombre); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                        <form method="POST" action="/admin/recetas/subrecetas/delete"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar subreceta"
                                              data-confirm-title="¿Eliminar «<?php echo $esc($sub->nombre); ?>»?"
                                              data-confirm-require="<?php echo $esc($sub->nombre); ?>"
                                              data-confirm-description="Los platillos que la incluyen perderán ese componente y su costo."
                                              data-confirm-consequence="También se pierden su existencia y sus lotes. Lo que sus lotes sacaron del almacén sigue descontado. Esta acción no se puede deshacer.">
                                            <input type="hidden" name="id" value="<?php echo (int) $sub->id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger" title="Eliminar" aria-label="Eliminar <?php echo $esc($sub->nombre); ?>">
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
