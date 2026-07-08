<?php
/**
 * Vista de feedback de clientes del panel de administración.
 * Muestra promedios por criterio y el detalle de las últimas reseñas.
 */

/** Devuelve una barra de estrellas para una calificación 1–5. */
$stars = static function ($value): string {
    if ($value === null) {
        return '<span class="admin-fb__stars admin-fb__stars--empty">Sin datos</span>';
    }

    $rounded = max(0, min(5, (int) round((float) $value)));
    $full = str_repeat('★', $rounded);
    $empty = str_repeat('☆', 5 - $rounded);

    return '<span class="admin-fb__stars">' . $full . $empty . '</span>';
};

$criterios = [
    ['label' => 'Experiencia global', 'value' => $feedbackStats['global'] ?? null],
    ['label' => 'Calidad / sabor',    'value' => $feedbackStats['sabor'] ?? null],
    ['label' => 'Atención del mesero', 'value' => $feedbackStats['atencion'] ?? null],
    ['label' => 'Tiempo de espera',   'value' => $feedbackStats['espera'] ?? null],
];
?>
<section class="admin-feedback admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Voz del cliente</span>
            <h2 class="admin-page__title">Feedback de clientes</h2>
            <p class="admin-page__subtitle">
                Reseñas capturadas al cerrar la cuenta mediante el enlace de opinión.
                Promedios sobre <?php echo (int) ($feedbackStats['total'] ?? 0); ?> respuesta(s).
            </p>
        </div>
    </header>

    <section class="admin-grid admin-fb__metrics" aria-label="Promedios de satisfacción">
        <?php foreach ($criterios as $criterio) : ?>
            <article class="admin-card admin-fb__metric">
                <span class="admin-fb__metric-label"><?php echo htmlspecialchars($criterio['label']); ?></span>
                <strong class="admin-fb__metric-value">
                    <?php echo $criterio['value'] !== null ? number_format((float) $criterio['value'], 1) : '—'; ?>
                    <small>/ 5</small>
                </strong>
                <?php echo $stars($criterio['value']); ?>
            </article>
        <?php endforeach; ?>
    </section>

    <?php
    // Áreas de mejora (estáticas por ahora; luego se generarán desde los datos).
    $acciones = [
        [
            'cat' => 'Tiempo de espera',
            'titulo' => 'Agilizar el cierre de cuenta',
            'texto' => 'Habilita el cobro en mesa en horas pico para reducir la espera final que más penalizan los clientes.',
            'nivel' => 'Prioridad alta',
            'tono' => 'danger',
            'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        ],
        [
            'cat' => 'Consistencia en cocina',
            'titulo' => 'Controlar temperatura de salida',
            'texto' => 'Refuerza el pase de cocina para que las pizzas y platillos lleguen calientes a la mesa.',
            'nivel' => 'Prioridad alta',
            'tono' => 'danger',
            'icon' => '<path d="M4 7h16"/><path d="M7 7v10a3 3 0 0 0 3 3h4a3 3 0 0 0 3-3V7"/><path d="M9 3v4"/><path d="M15 3v4"/>',
        ],
        [
            'cat' => 'Atención en piso',
            'titulo' => 'Reforzar seguimiento de mesas',
            'texto' => 'Asigna rondas de cortesía cada 10 minutos para elevar la calificación de atención del mesero.',
            'nivel' => 'Prioridad media',
            'tono' => 'warning',
            'icon' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/>',
        ],
        [
            'cat' => 'Fidelización',
            'titulo' => 'Programa de clientes frecuentes',
            'texto' => 'Convierte las reseñas de 5 estrellas en visitas recurrentes con beneficios o cortesías.',
            'nivel' => 'Oportunidad',
            'tono' => 'success',
            'icon' => '<path d="M12 3l2.5 5 5.5.8-4 3.9 1 5.5L12 21l-5 2.6 1-5.5-4-3.9 5.5-.8Z"/>',
        ],
        [
            'cat' => 'Gestión de reseñas',
            'titulo' => 'Responder y agradecer',
            'texto' => 'Contesta cada reseña —positiva o negativa— para mostrar cercanía y cerrar el ciclo de retroalimentación.',
            'nivel' => 'Continuo',
            'tono' => 'info',
            'icon' => '<path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2Z"/>',
        ],
    ];
    ?>
    <section class="admin-fb__improve" aria-label="Áreas de mejora sugeridas">
        <div class="admin-fb__improve-head">
            <h3>Áreas de mejora</h3>
            <p>Acciones sugeridas a partir de la retroalimentación de los clientes.</p>
        </div>

        <div class="admin-grid admin-fb__actions">
            <?php foreach ($acciones as $a) : ?>
                <article class="admin-card admin-fb__action admin-fb__action--<?php echo $a['tono']; ?>" data-reveal>
                    <span class="admin-fb__action-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><?php echo $a['icon']; ?></svg>
                    </span>
                    <span class="admin-fb__action-cat"><?php echo htmlspecialchars($a['cat']); ?></span>
                    <h4><?php echo htmlspecialchars($a['titulo']); ?></h4>
                    <p><?php echo htmlspecialchars($a['texto']); ?></p>
                    <span class="admin-fb__action-tag"><?php echo htmlspecialchars($a['nivel']); ?></span>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="admin-menu__panel admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Últimas reseñas</h3>
                <p>Mostrando las <?php echo min(100, count($feedbackRows)); ?> más recientes.</p>
            </div>
        </div>

        <?php if (empty($feedbackRows)) : ?>
            <p class="admin-menu__empty admin-empty">Aún no hay reseñas registradas.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-fb__table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Global</th>
                            <th>Sabor</th>
                            <th>Atención</th>
                            <th>Espera</th>
                            <th>Comentario</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feedbackRows as $fb) : ?>
                            <tr>
                                <td>
                                    <span class="admin-table__cell-main">
                                        <?php echo htmlspecialchars(date('d/m/Y', strtotime($fb['created_at']))); ?>
                                    </span>
                                    <span class="admin-table__cell-sub">
                                        <?php echo htmlspecialchars(date('H:i', strtotime($fb['created_at']))); ?>
                                    </span>
                                </td>
                                <td><?php echo $stars($fb['experiencia_global']); ?></td>
                                <td><?php echo $stars($fb['calidad_sabor']); ?></td>
                                <td><?php echo $stars($fb['atencion_mesero']); ?></td>
                                <td><?php echo $stars($fb['tiempo_espera']); ?></td>
                                <td>
                                    <?php if (!empty($fb['comentario'])) : ?>
                                        <span class="admin-table__description"><?php echo htmlspecialchars($fb['comentario']); ?></span>
                                    <?php else : ?>
                                        <span class="admin-fb__no-comment">—</span>
                                    <?php endif; ?>
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
    .admin-fb__metrics {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        margin-bottom: 20px;
    }

    .admin-fb__metric {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 18px 20px;
    }

    .admin-fb__metric-label {
        color: var(--admin-muted);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .admin-fb__metric-value {
        color: var(--admin-text);
        font-family: var(--admin-font-heading);
        font-size: 30px;
        line-height: 1;
    }

    .admin-fb__metric-value small {
        color: var(--admin-muted);
        font-family: var(--admin-font-body);
        font-size: 13px;
        font-weight: 600;
    }

    .admin-fb__stars {
        color: var(--admin-gold);
        font-size: 15px;
        letter-spacing: 2px;
    }

    .admin-fb__stars--empty,
    .admin-fb__no-comment {
        color: var(--admin-faint);
        font-size: 12px;
        letter-spacing: normal;
    }

    /* La columna Comentario es la última pero debe ir alineada a la izquierda. */
    .admin-fb__table th:last-child,
    .admin-fb__table td:last-child {
        text-align: left;
    }

    .admin-fb__table .admin-table__description {
        max-width: 460px;
    }

    /* ── Áreas de mejora ─────────────────────────────────────── */
    .admin-fb__improve {
        margin-bottom: 20px;
    }

    .admin-fb__improve-head {
        margin-bottom: 14px;
    }

    .admin-fb__improve-head h3 {
        color: var(--admin-text);
    }

    .admin-fb__improve-head p {
        margin-top: 4px;
        color: var(--admin-muted);
        font-size: 13px;
    }

    .admin-fb__actions {
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 14px;
    }

    .admin-fb__action {
        display: flex;
        flex-direction: column;
        gap: 9px;
        padding: 20px;
    }

    .admin-fb__action-icon {
        display: grid;
        place-items: center;
        width: 42px;
        height: 42px;
        border-radius: 12px;
        color: var(--admin-gold);
        background: var(--admin-neutral-bg);
        border: 1px solid var(--admin-neutral-border);
    }

    .admin-fb__action-icon svg { width: 22px; height: 22px; }

    .admin-fb__action-cat {
        margin-top: 2px;
        color: var(--admin-muted);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .admin-fb__action h4 {
        color: var(--admin-text);
        font-size: 1.05rem;
        line-height: 1.2;
    }

    .admin-fb__action p {
        color: var(--admin-muted);
        font-size: 13px;
        line-height: 1.5;
        flex: 1 1 auto;
    }

    .admin-fb__action-tag {
        align-self: flex-start;
        margin-top: 4px;
        padding: 4px 11px;
        border-radius: 999px;
        border: 1px solid var(--admin-neutral-border);
        background: var(--admin-neutral-bg);
        color: var(--admin-neutral-text);
        font-size: 11px;
        font-weight: 700;
    }

    /* Tonos por prioridad */
    .admin-fb__action--danger .admin-fb__action-icon,
    .admin-fb__action--danger .admin-fb__action-tag {
        color: var(--admin-danger-text);
        background: var(--admin-danger-bg);
        border-color: var(--admin-danger-border);
    }

    .admin-fb__action--warning .admin-fb__action-icon,
    .admin-fb__action--warning .admin-fb__action-tag {
        color: var(--admin-warning-text);
        background: var(--admin-warning-bg);
        border-color: var(--admin-warning-border);
    }

    .admin-fb__action--success .admin-fb__action-icon,
    .admin-fb__action--success .admin-fb__action-tag {
        color: var(--admin-success-text);
        background: var(--admin-success-bg);
        border-color: var(--admin-success-border);
    }

    .admin-fb__action--info .admin-fb__action-icon,
    .admin-fb__action--info .admin-fb__action-tag {
        color: var(--admin-info-text);
        background: var(--admin-info-bg);
        border-color: var(--admin-info-border);
    }
</style>
