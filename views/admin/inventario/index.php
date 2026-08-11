<?php
    $ingredientes = isset($ingredientes) && is_iterable($ingredientes) ? $ingredientes : [];
    $ingredientesBajos = isset($ingredientesBajos) && is_iterable($ingredientesBajos) ? $ingredientesBajos : [];
    $totalIngredientes = (int) ($totalIngredientes ?? 0);
    $bajoStock = (int) ($bajoStock ?? 0);
    $rango = is_array($rango ?? null) ? $rango : [];
    $valorInventario = (float) ($valorInventario ?? 0);
    $consumo = is_array($consumo ?? null) ? $consumo : [];
    $consumo += ['valor' => 0.0, 'movimientos' => 0, 'ajustes' => 0, 'entradas' => 0, 'mermaValor' => 0.0, 'mermas' => 0];
    $consumoPrev = is_array($consumoPrev ?? null) ? $consumoPrev : [];
    $consumoPrev += ['valor' => 0.0, 'mermaValor' => 0.0];
    $comparando = (bool) ($comparando ?? false);
    $deltaConsumo = isset($deltaConsumo) ? $deltaConsumo : null;
    $deltaMerma = isset($deltaMerma) ? $deltaMerma : null;
    $motivosMerma = isset($motivosMerma) && is_array($motivosMerma) ? $motivosMerma : [];
    $mermas = isset($mermas) && is_iterable($mermas) ? $mermas : [];
    $mermaPorMotivo = is_array($mermaPorMotivo ?? null) ? $mermaPorMotivo : [];
    $fmt = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $money = static fn ($v): string => '$' . number_format((float) $v, 2);
    // El badge de variación lo arma views/admin/partials/_stat-card.php.
?>
<section class="admin-inventario admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Inventario</span>
            <h2 class="admin-page__title">Inventario de ingredientes</h2>
            <p class="admin-page__subtitle">Controla las existencias de cada ingrediente. Las ventas descuentan el stock automáticamente según la receta de cada producto.</p>
        </div>
        <?php /* Igual que en Finanzas: el selector de periodo se queda solo en el
                 encabezado y las acciones bajan a su propia barra. */ ?>
        <div class="admin-actions">
            <?php include __DIR__ . '/../partials/_range-picker.php'; ?>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <div class="admin-toolbar admin-inventario__toolbar">
        <a class="admin-btn admin-btn--secondary" href="/admin/recetas">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg>
            Ver recetas
        </a>
        <a class="admin-btn admin-btn--secondary" href="#inventario-merma">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
            Registrar merma
        </a>
        <a class="admin-btn admin-btn--primary admin-create-button" href="/admin/inventario/create">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
            <span>Nuevo ingrediente</span>
        </a>
    </div>

    <div class="admin-stat-strip">
        <?php
            $statLabel = 'Ingredientes';
            $statValue = (string) $totalIngredientes;
            $statSub = 'En catálogo, activos e inactivos';
            include __DIR__ . '/../partials/_stat-card.php';

            // Sin comparativo: bajo stock y valor del inventario se miden sobre
            // las existencias de HOY, y no hay histórico de stock del que sacar
            // las de hace un mes.
            $statLabel = 'Bajo stock';
            $statValue = (string) $bajoStock;
            $statSub = $bajoStock > 0 ? 'Por debajo de su mínimo · surtir pronto' : 'Todo por encima del mínimo';
            $statTone = $bajoStock > 0 ? 'alert' : '';
            include __DIR__ . '/../partials/_stat-card.php';

            $statLabel = 'Valor del inventario';
            $statValue = $money($valorInventario);
            $statSub = 'Existencias de hoy × costo unitario';
            include __DIR__ . '/../partials/_stat-card.php';

            // Valorizado con el costo ACTUAL del ingrediente: las salidas por
            // venta no guardan el costo del momento.
            $statLabel = 'Consumo del periodo';
            $statValue = $money($consumo['valor']);
            $statSub = (int) $consumo['movimientos'] . ' salidas por venta · '
                . (int) $consumo['entradas'] . ' entradas · '
                . (int) $consumo['ajustes'] . ' ajustes · a costo actual';
            $statDelta = $deltaConsumo;
            $statPrev = $comparando ? $money($consumoPrev['valor']) : '';
            include __DIR__ . '/../partials/_stat-card.php';

            // La merma sí trae su costo congelado: lo que se tiró costó lo que
            // costaba ese día, no lo que cuesta hoy.
            $statLabel = 'Merma del periodo';
            $statValue = $money($consumo['mermaValor']);
            $statSub = (int) $consumo['mermas'] . ' registro(s) · al costo del día';
            $statDelta = $deltaMerma;
            $statPrev = $comparando ? $money($consumoPrev['mermaValor']) : '';
            $statTone = $consumo['mermaValor'] > 0 ? 'bad' : '';
            $statInverso = true;
            include __DIR__ . '/../partials/_stat-card.php';
        ?>
    </div>

    <?php if (!empty($ingredientesBajos)) : ?>
        <section class="admin-panel admin-card admin-restock">
            <div class="admin-panel-head">
                <div>
                    <h3>Reabastecimiento rápido</h3>
                    <p>Estos ingredientes están por debajo del mínimo. Registra la entrada de mercancía recibida y se sumará al stock.</p>
                </div>
                <span class="admin-badge admin-badge--warning"><?php echo count($ingredientesBajos); ?> por surtir</span>
            </div>

            <div class="admin-restock__grid">
                <?php foreach ($ingredientesBajos as $ing) : ?>
                    <?php
                        // Cuánto falta para volver al mínimo y qué porción del mínimo hay cubierta.
                        $rStock   = (float) $ing->stock;
                        $rMin     = (float) $ing->stock_minimo;
                        $rFaltan  = max(0.0, $rMin - $rStock);
                        $rPct     = $rMin > 0 ? max(0.0, min(100.0, ($rStock / $rMin) * 100)) : ($rStock > 0 ? 100.0 : 0.0);
                        $rUnidad  = htmlspecialchars($ing->unidad);
                        $rCritico = $rStock < 0 || ($rMin > 0 && $rStock < $rMin * 0.5);
                    ?>
                    <article class="admin-restock__card">
                        <header class="admin-restock__header">
                            <span class="admin-restock__name" title="<?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>"><?php echo htmlspecialchars($ing->nombre); ?></span>
                            <span class="admin-badge admin-badge--<?php echo $rCritico ? 'danger' : 'warning'; ?>">
                                Faltan <?php echo $fmt($rFaltan); ?> <?php echo $rUnidad; ?>
                            </span>
                        </header>

                        <div class="admin-restock__levels">
                            <div class="admin-restock__level">
                                <span class="admin-restock__level-label">Actual</span>
                                <span class="admin-restock__level-value admin-restock__level-value--now"><?php echo $fmt($rStock); ?> <?php echo $rUnidad; ?></span>
                            </div>
                            <div class="admin-restock__level admin-restock__level--target">
                                <span class="admin-restock__level-label">Mínimo</span>
                                <span class="admin-restock__level-value"><?php echo $fmt($rMin); ?> <?php echo $rUnidad; ?></span>
                            </div>
                        </div>

                        <div class="admin-restock__bar" role="img"
                             aria-label="<?php echo $fmt($rStock); ?> de <?php echo $fmt($rMin); ?> <?php echo $rUnidad; ?>">
                            <span class="admin-restock__bar-fill <?php echo $rCritico ? 'is-critical' : ''; ?>" style="width: <?php echo round($rPct, 1); ?>%"></span>
                        </div>

                        <form class="admin-restock__form" method="POST" action="/admin/inventario/entrada">
                            <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                            <div class="admin-restock__input" style="--restock-unit: <?php echo mb_strlen((string) $ing->unidad); ?>ch">
                                <input type="number" name="cantidad" step="0.001" min="0.001" placeholder="Cantidad recibida"
                                       aria-label="Cantidad recibida de <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>">
                                <span class="admin-restock__unit"><?php echo $rUnidad; ?></span>
                            </div>
                            <button type="submit" class="admin-btn admin-btn--gold admin-restock__add">
                                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                                Sumar
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php /*
      Panel de merma: alta y bitácora en el mismo sitio.
      El botón por renglón de la tabla se conserva como atajo, pero registrar
      una merma no puede depender de encontrar antes su ingrediente en una lista
      de 34 filas, y lo que se tiró no se veía en ninguna parte.
    */ ?>
    <section class="admin-panel admin-card admin-merma" id="inventario-merma" aria-labelledby="merma-titulo">
        <div class="admin-panel-head">
            <div>
                <h3 id="merma-titulo">Merma</h3>
                <p>Producto que se tiró. Se descuenta del stock y cuenta como costo del periodo.</p>
            </div>
            <span class="admin-badge admin-badge--<?php echo $consumo['mermaValor'] > 0 ? 'danger' : 'neutral'; ?>">
                <?php echo $money($consumo['mermaValor']); ?> en el periodo
            </span>
        </div>

        <form class="admin-merma__form" method="POST" action="/admin/inventario/merma">
            <div class="admin-merma__field admin-merma__field--wide">
                <label for="merma-ingrediente">Ingrediente</label>
                <select id="merma-ingrediente" name="id" required>
                    <option value="">Elige un ingrediente</option>
                    <?php foreach ($ingredientes as $ing) : ?>
                        <option value="<?php echo (int) $ing->id; ?>">
                            <?php echo htmlspecialchars($ing->nombre); ?> — <?php echo $fmt($ing->stock); ?> <?php echo htmlspecialchars($ing->unidad); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-merma__field">
                <label for="merma-cantidad">Cantidad</label>
                <input type="number" id="merma-cantidad" name="cantidad" step="0.001" min="0.001" placeholder="0" required>
            </div>
            <div class="admin-merma__field">
                <label for="merma-motivo">Motivo</label>
                <select id="merma-motivo" name="motivo" required>
                    <?php foreach ($motivosMerma as $clave => $etiqueta) : ?>
                        <option value="<?php echo htmlspecialchars($clave, ENT_QUOTES); ?>"><?php echo htmlspecialchars($etiqueta); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-merma__field admin-merma__field--wide">
                <label for="merma-nota">Detalle <small>(opcional)</small></label>
                <input type="text" id="merma-nota" name="nota" maxlength="255" autocomplete="off"
                       placeholder="Ej. se rompió la cadena de frío el sábado">
            </div>
            <div class="admin-merma__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Registrar merma</button>
            </div>
        </form>

        <?php if (!empty($mermaPorMotivo)) : ?>
            <ul class="admin-merma__motivos">
                <?php foreach ($mermaPorMotivo as $clave => $valorMotivo) : ?>
                    <li>
                        <span class="admin-merma__motivo-label"><?php echo htmlspecialchars($motivosMerma[$clave] ?? $clave); ?></span>
                        <span class="admin-merma__motivo-val"><?php echo $money($valorMotivo); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (empty($mermas)) : ?>
            <div class="admin-empty admin-merma__empty">
                <strong>Sin mermas registradas en el periodo</strong>
                <span>
                    Anota aquí lo que se echa a perder, se derrama o no llega a venderse.
                    Sin ese registro, el costo aparece como si el producto siguiera en el almacén.
                </span>
            </div>
        <?php else : ?>
            <div class="admin-table-wrap" data-lenis-prevent>
                <table class="admin-table admin-merma__table">
                    <thead>
                        <tr>
                            <th>Ingrediente</th>
                            <th>Cantidad</th>
                            <th>Motivo</th>
                            <th>Costo</th>
                            <th>Registró</th>
                            <th>Cuándo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mermas as $m) : ?>
                            <tr>
                                <td>
                                    <span class="admin-table__cell-main"><?php echo htmlspecialchars($m['ingrediente']); ?></span>
                                    <?php if ($m['nota'] !== '') : ?>
                                        <span class="admin-table__cell-sub"><?php echo htmlspecialchars($m['nota']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="admin-table__cell-sub"><?php echo $fmt($m['cantidad']); ?> <?php echo htmlspecialchars($m['unidad']); ?></span></td>
                                <td><span class="admin-badge admin-badge--neutral"><?php echo htmlspecialchars($motivosMerma[$m['motivo']] ?? $m['motivo']); ?></span></td>
                                <td><strong class="admin-merma__costo"><?php echo $money($m['valor']); ?></strong></td>
                                <td><span class="admin-table__cell-sub"><?php echo $m['usuario'] !== '' ? htmlspecialchars($m['usuario']) : '—'; ?></span></td>
                                <td><span class="admin-table__cell-sub"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($m['fecha']))); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel admin-card">
        <div class="admin-panel-head">
            <div>
                <h3>Existencias</h3>
                <p><?php echo $totalIngredientes; ?> ingredientes registrados.</p>
            </div>
        </div>

        <?php if (empty($ingredientes)) : ?>
            <p class="admin-empty">No hay ingredientes registrados. Crea el primero para comenzar a controlar el inventario.</p>
        <?php else : ?>
            <div class="admin-table-wrap" data-lenis-prevent>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Ingrediente</th>
                            <th>Unidad</th>
                            <th>Stock actual</th>
                            <th>Stock mínimo</th>
                            <th>Costo/u</th>
                            <th>Ajuste rápido</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingredientes as $ing) : ?>
                            <?php
                                $stock = (float) $ing->stock;
                                $min = (float) $ing->stock_minimo;
                                $bajo = $stock <= $min;
                                $neg = $stock < 0;
                            ?>
                            <tr data-row-href="/admin/inventario/edit?id=<?php echo (int) $ing->id; ?>">
                                <td>
                                    <span class="admin-table__cell-main"><?php echo htmlspecialchars($ing->nombre); ?></span>
                                    <?php if (!$ing->activo) : ?>
                                        <span class="admin-table__cell-sub">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="admin-badge admin-badge--neutral"><?php echo htmlspecialchars($ing->unidad); ?></span></td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $neg ? 'danger' : ($bajo ? 'warning' : 'success'); ?>">
                                        <?php echo rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.'); ?>
                                    </span>
                                </td>
                                <td><span class="admin-table__cell-sub"><?php echo rtrim(rtrim(number_format($min, 3, '.', ''), '0'), '.'); ?></span></td>
                                <td><span class="admin-table__cell-sub">$<?php echo number_format((float) $ing->costo, 4); ?></span></td>
                                <td>
                                    <form class="admin-inline-form" method="POST" action="/admin/inventario/ajustar">
                                        <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                                        <input type="number" name="stock" step="0.001" value="<?php echo htmlspecialchars($fmt($stock)); ?>" aria-label="Nuevo stock de <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>">
                                        <button type="submit" class="admin-btn admin-btn--gold admin-inventario__save" title="Fijar stock">
                                            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>
                                            Guardar
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <?php /* Los data-* del disparador rellenan el modal: uno solo
                                                 para toda la tabla en vez de un formulario por fila. */ ?>
                                        <button type="button" class="admin-icon-button admin-icon-button--warn"
                                                data-merma-open
                                                data-admin-modal-open="inventario-merma-modal"
                                                data-id="<?php echo (int) $ing->id; ?>"
                                                data-nombre="<?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>"
                                                data-unidad="<?php echo htmlspecialchars($ing->unidad, ENT_QUOTES); ?>"
                                                data-stock="<?php echo htmlspecialchars($fmt($stock), ENT_QUOTES); ?>"
                                                title="Registrar merma" aria-label="Registrar merma de <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>"
                                                aria-haspopup="dialog" aria-controls="inventario-merma-modal">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M12 10v6"/></svg>
                                        </button>
                                        <a class="admin-icon-button admin-icon-button--edit" href="/admin/inventario/edit?id=<?php echo (int) $ing->id; ?>" title="Editar" aria-label="Editar <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </a>
                                        <form method="POST" action="/admin/inventario/delete"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar ingrediente"
                                              data-confirm-title="¿Eliminar «<?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>»?"
                                              data-confirm-description="Se borrará del inventario y de las recetas que lo usan."
                                              data-confirm-consequence="También se pierde su bitácora de movimientos. Esta acción no se puede deshacer.">
                                            <input type="hidden" name="id" value="<?php echo (int) $ing->id; ?>">
                                            <button type="submit" class="admin-icon-button admin-icon-button--danger" title="Eliminar" aria-label="Eliminar <?php echo htmlspecialchars($ing->nombre, ENT_QUOTES); ?>">
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

<?php /*
  Un solo modal para toda la tabla: el disparador de cada fila lo rellena desde
  sus data-*. Con un formulario por renglón la página cargaba 34 diálogos que
  nadie iba a abrir.
*/ ?>
<div class="admin-modal" id="inventario-merma-modal" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <?php /* --wide: el <select> de motivos no cabe en media columna de los
             440px del diálogo por omisión y "Faltante de inventario" se
             recortaba a la mitad. */ ?>
    <div class="admin-modal__dialog admin-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="merma-modal-title" tabindex="-1" data-admin-modal-dialog>
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow">Inventario</span>
                <h2 class="admin-modal__title" id="merma-modal-title">Registrar merma</h2>
                <p class="admin-modal__text">
                    Anota lo que se tiró de <strong data-merma-nombre>este ingrediente</strong>.
                    Se descuenta del stock y se cuenta como costo del periodo.
                </p>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>

        <form class="admin-modal__form" method="POST" action="/admin/inventario/merma" data-merma-form>
            <input type="hidden" name="id" value="" data-merma-id>

            <label class="admin-field">
                <span class="admin-field__label">Cantidad</span>
                <input type="number" name="cantidad" step="0.001" min="0.001" required
                       autocomplete="off" placeholder="0" data-merma-cantidad>
                <span class="admin-field__hint">
                    En existencia: <span data-merma-stock>—</span> <span data-merma-unidad></span>
                </span>
            </label>

            <label class="admin-field">
                <span class="admin-field__label">Motivo</span>
                <select name="motivo" required data-merma-motivo>
                    <?php foreach ($motivosMerma as $clave => $etiqueta) : ?>
                        <option value="<?php echo htmlspecialchars($clave, ENT_QUOTES); ?>"><?php echo htmlspecialchars($etiqueta); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="admin-field admin-modal__field--wide">
                <span class="admin-field__label">Detalle <small>(opcional)</small></span>
                <input type="text" name="nota" maxlength="255" autocomplete="off"
                       placeholder="Ej. se rompió la cadena de frío el sábado">
            </label>

            <div class="admin-modal__actions admin-modal__field--wide">
                <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cancelar</button>
                <button type="submit" class="admin-btn admin-btn--primary">Registrar merma</button>
            </div>
        </form>
    </div>
</div>
