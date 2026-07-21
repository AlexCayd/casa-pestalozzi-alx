<?php
/**
 * Vista de tickets / ventas del panel de administración.
 * Lista los tickets de la base con su total (suma de items) y estado.
 */
$money = static function ($amount): string {
    return '$' . number_format((float) $amount, 2);
};

$estados = [
    'abierto'   => ['label' => 'Abierto',   'badge' => 'admin-badge--warning'],
    'cerrado'   => ['label' => 'Cerrado',   'badge' => 'admin-badge--success'],
    'cancelado' => ['label' => 'Cancelado', 'badge' => 'admin-badge--danger'],
];

$metodos = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'dividido' => 'Dividido'];

$metricas = [
    ['label' => 'Tickets',        'value' => (int) ($ticketStats['total'] ?? 0)],
    ['label' => 'Abiertos',       'value' => (int) ($ticketStats['abiertos'] ?? 0)],
    ['label' => 'Cerrados',       'value' => (int) ($ticketStats['cerrados'] ?? 0)],
    ['label' => 'Venta acumulada', 'value' => $money($ticketStats['ventas'] ?? 0), 'accent' => true],
];
?>
<section class="admin-tickets admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Operación</span>
            <h2 class="admin-page__title">Tickets</h2>
            <p class="admin-page__subtitle">
                Cuentas abiertas y cerradas del servicio, con el total calculado a partir de sus items.
            </p>
        </div>
    </header>

    <section class="admin-grid admin-tk__metrics" aria-label="Resumen de tickets">
        <?php foreach ($metricas as $m) : ?>
            <article class="admin-card admin-tk__metric">
                <span class="admin-tk__metric-label"><?php echo htmlspecialchars($m['label']); ?></span>
                <strong class="admin-tk__metric-value <?php echo !empty($m['accent']) ? 'is-accent' : ''; ?>">
                    <?php echo htmlspecialchars((string) $m['value']); ?>
                </strong>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="admin-menu__panel admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Tickets recientes</h3>
                <p>Mostrando los <?php echo min(100, count($ticketRows)); ?> más recientes.</p>
            </div>
        </div>

        <?php if (empty($ticketRows)) : ?>
            <p class="admin-menu__empty admin-empty">Aún no hay tickets registrados.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-tk__table">
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Mesa</th>
                            <th>Cliente</th>
                            <th>Comensales</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Propina</th>
                            <th>Pago</th>
                            <th>Estado</th>
                            <th>Apertura</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ticketRows as $t) : ?>
                            <?php
                            $estado = (string) ($t['estado'] ?? 'abierto');
                            $estadoInfo = $estados[$estado] ?? ['label' => ucfirst($estado), 'badge' => 'admin-badge--neutral'];
                            $mesaNombre = $t['mesa_nombre'] ?: ('Mesa ' . ($t['mesa_numero'] ?? '—'));
                            ?>
                            <tr>
                                <td><span class="admin-table__cell-main">T-<?php echo str_pad((string) $t['id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                                <td><?php echo htmlspecialchars((string) $mesaNombre); ?></td>
                                <td><?php echo htmlspecialchars($t['nombre'] ?: '—'); ?></td>
                                <td><?php echo (int) $t['comensales']; ?></td>
                                <td><?php echo (int) $t['num_items']; ?></td>
                                <td><span class="admin-table__cell-main"><?php echo $money($t['total']); ?></span></td>
                                <?php
                                $propina = (float) ($t['propina'] ?? 0);
                                $totalNum = (float) $t['total'];
                                $pct = ($propina > 0 && $totalNum > 0) ? ($propina / $totalNum * 100) : 0;
                                ?>
                                <td>
                                    <?php if ($propina > 0) : ?>
                                        <span class="admin-table__cell-main admin-tk__tip"><?php echo $money($propina); ?></span>
                                        <span class="admin-tk__tip-pct"><?php echo number_format($pct, 1); ?>%</span>
                                    <?php else : ?>
                                        <span class="admin-table__cell-sub">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(!empty($t['metodo_pago']) ? ($metodos[$t['metodo_pago']] ?? '—') : '—'); ?></td>
                                <td><span class="admin-badge <?php echo $estadoInfo['badge']; ?>"><?php echo htmlspecialchars($estadoInfo['label']); ?></span></td>
                                <td>
                                    <span class="admin-table__cell-sub">
                                        <?php echo $t['hora_apertura'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($t['hora_apertura']))) : '—'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>

<style>
    .admin-tk__metrics {
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        margin-bottom: 20px;
    }

    .admin-tk__metric {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 18px 20px;
    }

    .admin-tk__metric-label {
        color: var(--admin-muted);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .admin-tk__metric-value {
        color: var(--admin-text);
        font-family: var(--admin-font-heading);
        font-size: 28px;
        line-height: 1;
    }

    .admin-tk__metric-value.is-accent {
        color: var(--admin-gold);
    }

    .admin-tk__tip { color: var(--admin-sage, #7ba86a); }
    .admin-tk__tip-pct {
        display: inline-block;
        margin-left: 6px;
        font-size: 11px;
        font-weight: 600;
        color: var(--admin-muted);
    }
</style>
