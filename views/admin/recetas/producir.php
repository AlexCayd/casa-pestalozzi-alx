<?php
/**
 * Ficha de producción de una subreceta POR LOTE.
 *
 * Arriba lo que más se hace: registrar un lote, viendo antes qué sale del
 * almacén y cuánto cuesta. Al lado, la existencia con las dos correcciones
 * (conteo y merma). Abajo, los últimos lotes.
 *
 * Sin JS la vista previa enseña lo que sale con UN lote, que es el valor con
 * el que nace el campo; recipe-builder.js la reescala al escribir otra
 * cantidad. El servidor vuelve a calcular todo al guardar.
 */
require_once __DIR__ . '/../partials/_icons.php';

$sub = $subreceta;
$composicion = is_array($composicion ?? null) ? $composicion : [];
$lotes = is_array($lotes ?? null) ? $lotes : [];
$motivosMerma = is_array($motivosMerma ?? null) ? $motivosMerma : [];
$costoUnitario = (float) ($costoUnitario ?? 0);

$esc = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
// Menos tipográfico, como DiagnosticoFinanciero::dinero() y el JS de la vista
// previa: la cifra no cambia de forma al reescalarse.
$cant = static fn ($valor): string => str_replace('-', '−', rtrim(rtrim(number_format((float) $valor, 3, '.', ','), '0'), '.'));
$campo = static fn ($valor): string => rtrim(rtrim(number_format((float) $valor, 3, '.', ''), '0'), '.');
$money = static fn ($valor): string => ((float) $valor < 0 ? '−$' : '$') . number_format(abs((float) $valor), 2);

$id = (int) $sub->id;
$unidad = (string) $sub->unidad;
$rendimiento = (float) $sub->rendimiento > 0 ? (float) $sub->rendimiento : 1.0;
$stock = (float) $sub->stock;
$minimo = (float) $sub->stock_minimo;
$activa = (int) $sub->activo === 1;
$puedeProducir = $activa && $composicion !== [];

$estado = $stock < 0 ? ['danger', 'En negativo'] : ($stock <= 0 ? ['danger', 'Agotada'] : ($minimo > 0 && $stock <= $minimo ? ['warning', 'Bajo el mínimo'] : ['', '']));
$costoLote = 0.0;
foreach ($composicion as $ing) {
    $costoLote += $ing['cantidad'] * $ing['costo'];
}
?>
<section class="admin-recetas admin-page admin-produccion">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Subrecetas · Por lote</span>
            <h2 class="admin-page__title">Producir <?php echo $esc($sub->nombre); ?></h2>
            <p class="admin-page__subtitle">Registra lo que se cocinó. Sus ingredientes salen del almacén ahora, y la venta de los platillos que la llevan descuenta sólo <?php echo $esc($sub->nombre); ?>.</p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary admin-back-button" href="/admin/recetas/subrecetas">
                <?php echo admin_icon('chevron-izquierda', 18, 'admin-btn__icon'); ?>
                Volver
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <div class="admin-produccion__rejilla">
        <section class="admin-panel admin-card admin-produccion__lote" aria-labelledby="produccion-titulo">
            <form method="POST" action="/admin/recetas/subrecetas/producir"
                  data-produccion data-rendimiento="<?php echo $esc($campo($rendimiento)); ?>" data-unidad="<?php echo $esc($unidad); ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">

                <div class="admin-recipe__head">
                    <span class="admin-recipe__eyebrow">Nuevo lote</span>
                    <h3 id="produccion-titulo">¿Cuánto se produjo?</h3>
                    <p>Un lote rinde <?php echo $cant($rendimiento); ?> <?php echo $esc($unidad); ?>. Si hiciste otra cantidad, escríbela: los ingredientes se escalan en proporción.</p>
                </div>

                <?php if (!$activa) : ?>
                    <p class="admin-empty">Está inactiva. <a href="/admin/recetas/subrecetas/edit?id=<?php echo $id; ?>">Actívala</a> para producirla.</p>
                <?php elseif ($composicion === []) : ?>
                    <p class="admin-empty">No tiene ingredientes, así que producirla no sacaría nada del almacén. <a href="/admin/recetas/subrecetas/edit?id=<?php echo $id; ?>">Define su composición</a> primero.</p>
                <?php endif; ?>

                <div class="admin-form-grid">
                    <div class="admin-field">
                        <label class="admin-field__label" for="produccion-cantidad">Cantidad producida</label>
                        <div class="admin-recipe__qty">
                            <input type="number" id="produccion-cantidad" name="cantidad" step="0.001" min="0.001"
                                   max="<?php echo (int) \Services\Inventory\Inventario::MAX_CANTIDAD; ?>"
                                   value="<?php echo $esc($campo($rendimiento)); ?>" required
                                   data-produccion-cantidad aria-describedby="produccion-unidad" <?php echo $puedeProducir ? '' : 'disabled'; ?>>
                            <?php /* Oculta a la lectura lineal (la cifra la lleva el campo),
                                     pero el campo la cita: sin ella no se sabe en qué se cuenta. */ ?>
                            <span class="admin-recipe__unit" id="produccion-unidad" aria-hidden="true"><?php echo $esc($unidad); ?></span>
                        </div>
                    </div>
                    <div class="admin-field">
                        <label class="admin-field__label" for="produccion-nota">Detalle <small>(opcional)</small></label>
                        <input type="text" id="produccion-nota" name="nota" maxlength="255" autocomplete="off"
                               placeholder="Ej. lote doble para el fin de semana" <?php echo $puedeProducir ? '' : 'disabled'; ?>>
                    </div>
                </div>

                <?php if ($composicion !== []) : ?>
                    <div class="admin-table-wrap admin-produccion__salida" data-scrollable>
                        <table class="admin-table">
                            <caption class="admin-eyebrow admin-produccion__caption">Sale del almacén</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Ingrediente</th>
                                    <th scope="col" class="admin-recetas__col-num">Sale</th>
                                    <th scope="col" class="admin-recetas__col-num">Queda</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($composicion as $ing) : ?>
                                    <?php // A milésimas, como lo guarda la base: una resta de flotantes que
                                          // debía dar cero no puede pintar «−0» ni encender el aviso.
                                          $queda = round($ing['stock'] - $ing['cantidad'], 3); ?>
                                    <?php /* data-cantidad es lo que lleva UN LOTE, sin dividir: el JS
                                             escala con cantidad ÷ rendimiento, la misma cuenta que
                                             Inventario::producirSubreceta. Dividido aquí y redondeado a
                                             milésimas, 250 g de un lote de 3 l volvían como 249.999. */ ?>
                                    <tr data-produccion-fila
                                        data-cantidad="<?php echo $esc($campo($ing['cantidad'])); ?>"
                                        data-stock="<?php echo $esc($campo($ing['stock'])); ?>"
                                        data-costo="<?php echo $esc(number_format($ing['costo'], 4, '.', '')); ?>"
                                        data-unidad="<?php echo $esc($ing['unidad']); ?>">
                                        <td><span class="admin-recetas__nombre" data-produccion-nombre><?php echo $esc($ing['nombre']); ?></span></td>
                                        <td class="admin-recetas__col-num admin-num" data-produccion-sale><?php echo $cant($ing['cantidad']); ?> <?php echo $esc($ing['unidad']); ?></td>
                                        <td class="admin-recetas__col-num">
                                            <span class="admin-recetas__cifra-estado">
                                                <span class="admin-num" data-produccion-queda><?php echo $cant($queda); ?> <?php echo $esc($ing['unidad']); ?></span>
                                                <?php /* No frena el lote: la cocina ya cocinó. Avisa que
                                                        falta registrar una entrada en Inventario. */ ?>
                                                <span class="admin-badge admin-badge--warning" data-produccion-negativo <?php echo $queda < 0 ? '' : 'hidden'; ?>>En negativo</span>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row" colspan="2">Costo del lote, a precios de hoy</th>
                                    <td class="admin-recetas__col-num admin-num" data-produccion-costo><?php echo $money($costoLote); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php /* Para lector de pantalla: el JS lo rellena un momento después
                             de dejar de escribir. Una región viva sobre la celda del costo
                             anunciaría una cifra suelta con cada tecla, y lo que importa
                             oír es si algo se queda en negativo. */ ?>
                    <p class="admin-visually-hidden" role="status" data-produccion-resumen></p>
                <?php endif; ?>

                <div class="admin-form-grid__actions admin-recipe__actions">
                    <button type="submit" class="admin-btn admin-btn--primary" <?php echo $puedeProducir ? '' : 'disabled'; ?>>Registrar producción</button>
                </div>
            </form>
        </section>

        <aside class="admin-panel admin-card admin-produccion__existencia" aria-labelledby="existencia-titulo">
            <span class="admin-recipe__eyebrow" id="existencia-titulo">Existencia</span>
            <p class="admin-produccion__cifra">
                <span class="admin-produccion__numero"><?php echo $cant($stock); ?></span>
                <span class="admin-produccion__unidad"><?php echo $esc($unidad); ?></span>
            </p>
            <?php if ($estado[1] !== '') : ?>
                <span class="admin-badge admin-badge--<?php echo $estado[0]; ?>"><?php echo $esc($estado[1]); ?></span>
            <?php endif; ?>
            <dl class="admin-produccion__datos">
                <div>
                    <dt class="admin-eyebrow">Mínimo</dt>
                    <?php /* Mono sólo para la cifra: «Sin mínimo» es una frase. */ ?>
                    <dd><?php if ($minimo > 0) : ?><span class="admin-num"><?php echo $cant($minimo); ?> <?php echo $esc($unidad); ?></span><?php else : ?>Sin mínimo<?php endif; ?></dd>
                </div>
                <div>
                    <dt class="admin-eyebrow">Costo de hoy</dt>
                    <?php /* Sin composición el costo sería un $0.00 que parece un dato. */ ?>
                    <dd><?php if ($composicion !== []) : ?><span class="admin-num"><?php echo $money($costoUnitario); ?> / <?php echo $esc($unidad); ?></span><?php else : ?>Sin ingredientes<?php endif; ?></dd>
                </div>
            </dl>

            <?php /* Las dos correcciones llevan el mismo esqueleto —rótulo, campo,
                     botón— y su rótulo da nombre al formulario, para que el lector
                     de pantalla las encuentre como dos formularios distintos. */ ?>
            <form class="admin-produccion__correccion" method="POST" action="/admin/recetas/subrecetas/ajustar" aria-labelledby="ajuste-titulo">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <span class="admin-eyebrow" id="ajuste-titulo">Corregir tras contar</span>
                <div class="admin-field">
                    <label class="admin-field__label" for="ajuste-stock">Existencia contada</label>
                    <div class="admin-recipe__qty">
                        <input type="number" id="ajuste-stock" name="stock" step="0.001" min="0"
                               max="<?php echo (int) \Services\Inventory\Inventario::MAX_CANTIDAD; ?>"
                               value="<?php echo $esc($campo($stock)); ?>" required aria-describedby="ajuste-unidad ajuste-ayuda">
                        <span class="admin-recipe__unit" id="ajuste-unidad" aria-hidden="true"><?php echo $esc($unidad); ?></span>
                    </div>
                    <p class="admin-field__hint" id="ajuste-ayuda">Escribe lo que hay de verdad. La diferencia queda como ajuste.</p>
                </div>
                <button type="submit" class="admin-btn admin-btn--secondary">Guardar conteo</button>
            </form>

            <form class="admin-produccion__correccion" method="POST" action="/admin/recetas/subrecetas/merma" aria-labelledby="merma-titulo">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <span class="admin-eyebrow" id="merma-titulo">Merma</span>
                <div class="admin-field">
                    <label class="admin-field__label" for="merma-cantidad">Cantidad que se tiró</label>
                    <div class="admin-recipe__qty">
                        <input type="number" id="merma-cantidad" name="cantidad" step="0.001" min="0.001"
                               max="<?php echo (int) \Services\Inventory\Inventario::MAX_CANTIDAD; ?>" placeholder="0" required aria-describedby="merma-unidad">
                        <span class="admin-recipe__unit" id="merma-unidad" aria-hidden="true"><?php echo $esc($unidad); ?></span>
                    </div>
                </div>
                <div class="admin-field">
                    <label class="admin-field__label" for="merma-motivo">Motivo</label>
                    <select id="merma-motivo" name="motivo" required>
                        <?php foreach ($motivosMerma as $clave => $etiqueta) : ?>
                            <option value="<?php echo $esc($clave); ?>"><?php echo $esc($etiqueta); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-field">
                    <label class="admin-field__label" for="merma-nota">Detalle <small>(opcional)</small></label>
                    <input type="text" id="merma-nota" name="nota" maxlength="255" autocomplete="off" placeholder="Ej. se cortó en la cámara">
                </div>
                <button type="submit" class="admin-btn admin-btn--secondary">Registrar merma</button>
            </form>
        </aside>
    </div>

    <section class="admin-panel admin-card" aria-labelledby="lotes-titulo">
        <div class="admin-recipe__head">
            <span class="admin-recipe__eyebrow">Historial</span>
            <h3 id="lotes-titulo">Últimos lotes</h3>
        </div>
        <?php if ($lotes === []) : ?>
            <p class="admin-empty">Todavía no se ha producido ningún lote.</p>
        <?php else : ?>
            <div class="admin-table-wrap" data-scrollable>
                <table class="admin-table admin-produccion__lotes" aria-labelledby="lotes-titulo">
                    <thead>
                        <tr>
                            <th scope="col">Cuándo</th>
                            <th scope="col" class="admin-recetas__col-num">Cantidad</th>
                            <th scope="col" class="admin-recetas__col-num">Costo</th>
                            <th scope="col">Registró</th>
                            <th scope="col">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lotes as $lote) : ?>
                            <tr>
                                <td class="admin-num"><?php echo $esc(date('d/m/Y H:i', strtotime($lote['fecha']))); ?></td>
                                <td class="admin-recetas__col-num admin-num"><?php echo $cant($lote['cantidad']); ?> <?php echo $esc($unidad); ?></td>
                                <td class="admin-recetas__col-num admin-num"><?php echo $money($lote['costo']); ?></td>
                                <td><?php echo $lote['usuario'] !== '' ? $esc($lote['usuario']) : '—'; ?></td>
                                <td><?php echo $lote['nota'] !== '' ? $esc($lote['nota']) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
