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

    <?php $meseros = $meseros ?? []; ?>
    <section class="admin-panel admin-card admin-fb__meseros" aria-label="Rendimiento de meseros">
        <div class="admin-fb__meseros-head">
            <h3 class="admin-fb__meseros-title">Rendimiento de meseros</h3>
            <p class="admin-fb__meseros-sub">Atención según el feedback de sus tickets y la propina promedio que dejó el cliente.</p>
        </div>
        <?php if (empty($meseros)) : ?>
            <div class="admin-menu__empty">Aún no hay tickets con mesero asignado para evaluar.</div>
        <?php else : ?>
            <div class="admin-fb__meseros-grid">
                <?php foreach ($meseros as $m) : ?>
                    <article class="admin-card admin-fb__mesero<?php echo $m['activo'] ? '' : ' admin-fb__mesero--inactivo'; ?>">
                        <div class="admin-fb__mesero-top">
                            <span class="admin-fb__mesero-name"><?php echo htmlspecialchars($m['nombre']); ?></span>
                            <?php if (!$m['activo']) : ?><span class="admin-fb__mesero-tag">Inactivo</span><?php endif; ?>
                        </div>
                        <div class="admin-fb__mesero-score">
                            <strong><?php echo $m['rendimiento'] !== null ? (int) $m['rendimiento'] : '—'; ?></strong>
                            <small>/ 100 rendimiento</small>
                        </div>
                        <div class="admin-fb__mesero-metrics">
                            <div class="admin-fb__mesero-metric">
                                <span class="admin-fb__mesero-metric-label">Atención</span>
                                <?php echo $stars($m['atencion']); ?>
                                <span class="admin-fb__mesero-metric-num"><?php echo $m['atencion'] !== null ? number_format((float) $m['atencion'], 1) . ' / 5' : '—'; ?></span>
                            </div>
                            <div class="admin-fb__mesero-metric">
                                <span class="admin-fb__mesero-metric-label">Propina prom.</span>
                                <span class="admin-fb__mesero-metric-num admin-fb__mesero-tip"><?php echo $m['tip_pct'] !== null ? number_format((float) $m['tip_pct'], 1) . '%' : '—'; ?></span>
                            </div>
                        </div>
                        <div class="admin-fb__mesero-foot">
                            <?php echo (int) $m['tickets']; ?> ticket(s) · <?php echo (int) $m['resenas']; ?> reseña(s)
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php
    // Las áreas de mejora llegan desde el flujo de n8n (AreasMejora::leer()).
    // Mientras no haya datos generados, se muestran ejemplos como marcador.
    $acciones = $acciones ?? [];
    $sonEjemplo = empty($acciones);

    if ($sonEjemplo) {
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
    }
    ?>
    <section class="admin-fb__improve" aria-label="Áreas de mejora sugeridas"
             data-generado="<?php echo htmlspecialchars((string) $accionesActualizadas); ?>">
        <div class="admin-fb__improve-head">
            <div>
                <h3>Áreas de mejora</h3>
                <p>
                    Acciones sugeridas a partir de la retroalimentación de los clientes.
                    <span class="admin-fb__improve-updated" id="fbUpdated">
                        <?php if (!empty($accionesActualizadas)) : ?>
                            · Generado el <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($accionesActualizadas))); ?>
                        <?php elseif ($sonEjemplo) : ?>
                            · Mostrando ejemplos (aún sin análisis generado)
                        <?php endif; ?>
                    </span>
                </p>
            </div>
            <div class="admin-fb__improve-actions">
                <button type="button" id="fbRunFlow" class="admin-btn admin-btn--gold">
                    <svg class="fb-ico-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                    <span class="fb-btn-label">Actualizar ahora</span>
                </button>
            </div>
        </div>

        <div class="admin-fb__notice" id="fbNotice" role="status" aria-live="polite" hidden>
            <span class="admin-fb__notice-icon" aria-hidden="true"></span>
            <span class="admin-fb__notice-text"></span>
        </div>

        <div class="admin-grid admin-fb__actions" id="fbActions">
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

    /* ── Rendimiento de meseros ─────────────────────────────── */
    .admin-fb__meseros { margin-top: 22px; margin-bottom: 40px; padding: 20px; }
    .admin-fb__meseros-head { margin-bottom: 16px; }
    .admin-fb__meseros-title { margin: 0; font-size: 16px; }
    .admin-fb__meseros-sub {
        margin: 4px 0 0;
        font-size: 12.5px;
        color: var(--admin-muted);
    }

    .admin-fb__meseros-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        gap: 14px;
    }

    .admin-fb__mesero { padding: 16px; display: flex; flex-direction: column; gap: 10px; }
    .admin-fb__mesero--inactivo { opacity: .6; }

    .admin-fb__mesero-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .admin-fb__mesero-name { font-weight: 700; font-size: 14px; }
    .admin-fb__mesero-tag {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--admin-muted);
        border: 1px solid var(--admin-border);
        border-radius: 999px;
        padding: 1px 7px;
    }

    .admin-fb__mesero-score { display: flex; align-items: baseline; gap: 6px; }
    .admin-fb__mesero-score strong { font-size: 30px; color: var(--admin-gold); line-height: 1; }
    .admin-fb__mesero-score small { font-size: 11px; color: var(--admin-muted); }

    .admin-fb__mesero-metrics {
        display: flex;
        flex-direction: column;
        gap: 6px;
        border-top: 1px solid var(--admin-border);
        padding-top: 10px;
    }
    .admin-fb__mesero-metric {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        font-size: 12.5px;
    }
    .admin-fb__mesero-metric-label { color: var(--admin-muted); }
    .admin-fb__mesero-metric-num { font-weight: 600; }
    .admin-fb__mesero-tip { color: var(--admin-sage, #7ba86a); }

    .admin-fb__mesero-foot {
        font-size: 11.5px;
        color: var(--admin-faint);
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
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
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

    .admin-fb__improve-updated {
        color: var(--admin-faint);
    }

    .admin-fb__improve-actions {
        margin: 0;
    }

    /* Botón para disparar el flujo de n8n. Autocontenido porque .admin-btn
       vive en el CSS del módulo de reservaciones, no en el global. */
    .admin-fb__improve-actions button {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 16px;
        border-radius: 10px;
        border: 1px solid var(--admin-gold, #c9a24b);
        background: var(--admin-gold, #c9a24b);
        color: #1a1204;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: opacity 0.15s ease, transform 0.15s ease;
    }

    .admin-fb__improve-actions button:hover {
        opacity: 0.9;
        transform: translateY(-1px);
    }

    /* ── Notificación premium ────────────────────────────────── */
    @keyframes fb-notice-in {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: none; }
    }

    /* El display:flex de abajo vence al display:none nativo de [hidden],
       así que se re-oculta explícitamente cuando lleva el atributo. */
    .admin-fb__notice[hidden] { display: none; }

    .admin-fb__notice {
        --fb-notice-accent: var(--admin-gold, #c9a24b);
        --fb-notice-tint: rgba(201, 162, 75, 0.12);
        position: relative;
        display: flex;
        align-items: center;
        gap: 13px;
        margin-bottom: 16px;
        padding: 14px 18px 14px 16px;
        border-radius: 14px;
        overflow: hidden;
        font-size: 13.5px;
        line-height: 1.45;
        color: var(--admin-text);
        border: 1px solid var(--admin-neutral-border);
        background:
            linear-gradient(120deg, var(--fb-notice-tint), transparent 62%),
            var(--admin-card-bg, var(--admin-neutral-bg));
        box-shadow: 0 10px 26px -14px rgba(0, 0, 0, 0.55);
        animation: fb-notice-in 0.32s cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    /* Barra de acento a la izquierda. */
    .admin-fb__notice::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: var(--fb-notice-accent);
    }

    .admin-fb__notice-icon {
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        width: 34px;
        height: 34px;
        border-radius: 10px;
        color: var(--fb-notice-accent);
        background: var(--fb-notice-tint);
        border: 1px solid color-mix(in srgb, var(--fb-notice-accent) 32%, transparent);
    }

    .admin-fb__notice-icon svg { width: 18px; height: 18px; }

    /* El ícono gira mientras el aviso está en modo "cargando". */
    .admin-fb__notice.is-busy .admin-fb__notice-icon svg {
        transform-origin: 50% 50%;
        animation: fb-spin 0.9s linear infinite;
    }

    .admin-fb__notice-text { flex: 1 1 auto; font-weight: 500; }

    /* Salida suave cuando el aviso se autooculta. */
    .admin-fb__notice.is-leaving {
        opacity: 0;
        transform: translateY(-6px);
        transition: opacity 0.45s ease, transform 0.45s ease;
    }

    .admin-fb__notice--success {
        --fb-notice-accent: var(--admin-success-text, #4ea36b);
        --fb-notice-tint: color-mix(in srgb, var(--admin-success-text, #4ea36b) 14%, transparent);
        border-color: var(--admin-success-border);
    }

    .admin-fb__notice--danger {
        --fb-notice-accent: var(--admin-danger-text, #d1596a);
        --fb-notice-tint: color-mix(in srgb, var(--admin-danger-text, #d1596a) 14%, transparent);
        border-color: var(--admin-danger-border);
    }

    .admin-fb__notice--warning {
        --fb-notice-accent: var(--admin-warning-text, #d9a441);
        --fb-notice-tint: color-mix(in srgb, var(--admin-warning-text, #d9a441) 14%, transparent);
        border-color: var(--admin-warning-border);
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

    /* ── Estado de carga (mientras corre el flujo de n8n) ────── */
    @keyframes fb-spin { to { transform: rotate(360deg); } }
    @keyframes fb-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.55; } }

    .fb-ico-refresh.is-spinning {
        transform-origin: 50% 50%;
        animation: fb-spin 0.9s linear infinite;
    }

    .admin-fb__improve-actions button[disabled] {
        cursor: progress;
        opacity: 0.75;
    }

    /* Las tarjetas laten y quedan inertes mientras se regeneran. */
    .admin-fb__actions.is-loading {
        pointer-events: none;
    }

    .admin-fb__actions.is-loading .admin-fb__action {
        animation: fb-pulse 1.1s ease-in-out infinite;
    }

    /* Escalona el latido para dar sensación de progreso. */
    .admin-fb__actions.is-loading .admin-fb__action:nth-child(2) { animation-delay: 0.12s; }
    .admin-fb__actions.is-loading .admin-fb__action:nth-child(3) { animation-delay: 0.24s; }
    .admin-fb__actions.is-loading .admin-fb__action:nth-child(4) { animation-delay: 0.36s; }
    .admin-fb__actions.is-loading .admin-fb__action:nth-child(5) { animation-delay: 0.48s; }

    /* Aparición suave de las tarjetas recién renderizadas. */
    @keyframes fb-fade-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    .admin-fb__action.is-fresh { animation: fb-fade-in 0.35s ease both; }
</style>

<script>
(function () {
    const seccion   = document.querySelector('.admin-fb__improve');
    const boton     = document.getElementById('fbRunFlow');
    const contenedor = document.getElementById('fbActions');
    const aviso     = document.getElementById('fbNotice');
    const updated   = document.getElementById('fbUpdated');
    const icono     = boton ? boton.querySelector('.fb-ico-refresh') : null;
    const etiqueta  = boton ? boton.querySelector('.fb-btn-label') : null;

    if (!boton || !contenedor) return;

    const POLL_MS = 2000;   // cada cuánto se consulta si ya hay datos nuevos
    const MAX_MS  = 60000;  // tiempo máximo de espera antes de rendirse

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const NOTICE_ICONS = {
        loading: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        success: '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        danger:  '<circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>',
        warning: '<path d="M10.3 4l-7.6 13A2 2 0 0 0 4.4 20h15.2a2 2 0 0 0 1.7-3L13.7 4a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 16h.01"/>',
    };

    const noticeIconEl = aviso.querySelector('.admin-fb__notice-icon');
    const noticeTextEl = aviso.querySelector('.admin-fb__notice-text');
    let dismissTimer = null;

    function mostrarAviso(tono, texto, opciones) {
        opciones = opciones || {};
        const busy = !!opciones.busy;
        const clave = busy ? 'loading' : tono;
        clearTimeout(dismissTimer);
        aviso.className = 'admin-fb__notice admin-fb__notice--' + tono + (busy ? ' is-busy' : '');
        noticeIconEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' +
            (NOTICE_ICONS[clave] || NOTICE_ICONS.loading) + '</svg>';
        noticeTextEl.textContent = texto;
        aviso.hidden = false;

        // Se autooculta tras unos segundos (por defecto solo el aviso de éxito).
        if (opciones.autoOcultar) {
            dismissTimer = setTimeout(ocultarAviso, opciones.autoOcultar);
        }
    }

    function ocultarAviso() {
        aviso.classList.add('is-leaving');
        const cerrar = () => {
            aviso.hidden = true;
            aviso.classList.remove('is-leaving');
            aviso.removeEventListener('transitionend', cerrar);
        };
        aviso.addEventListener('transitionend', cerrar);
        // Respaldo por si no dispara transitionend.
        setTimeout(cerrar, 600);
    }

    function tarjeta(a) {
        return '' +
            '<article class="admin-card admin-fb__action admin-fb__action--' + esc(a.tono) + ' is-fresh" data-reveal>' +
                '<span class="admin-fb__action-icon" aria-hidden="true">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + (a.icon || '') + '</svg>' +
                '</span>' +
                '<span class="admin-fb__action-cat">' + esc(a.cat) + '</span>' +
                '<h4>' + esc(a.titulo) + '</h4>' +
                '<p>' + esc(a.texto) + '</p>' +
                '<span class="admin-fb__action-tag">' + esc(a.nivel) + '</span>' +
            '</article>';
    }

    function pintar(acciones) {
        contenedor.innerHTML = acciones.map(tarjeta).join('');
    }

    function fechaBonita(iso) {
        const d = new Date(iso);
        if (isNaN(d)) return '';
        const p = (n) => String(n).padStart(2, '0');
        return '· Generado el ' + p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() +
               ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function setCargando(activo) {
        boton.disabled = activo;
        contenedor.classList.toggle('is-loading', activo);
        if (icono) icono.classList.toggle('is-spinning', activo);
        if (etiqueta) etiqueta.textContent = activo ? 'Actualizando…' : 'Actualizar ahora';
    }

    function esperarNuevasAreas(generadoPrevio) {
        const limite = Date.now() + MAX_MS;

        return new Promise((resolve, reject) => {
            const tick = () => {
                fetch('/admin/api/feedback-areas', { headers: { 'Accept': 'application/json' } })
                    .then((r) => r.json())
                    .then((data) => {
                        const gen = data.generado_en || '';
                        // Hay datos nuevos si la marca de tiempo cambió respecto
                        // a la que había al iniciar el flujo.
                        if (gen && gen !== generadoPrevio && Array.isArray(data.acciones) && data.acciones.length) {
                            resolve(data);
                            return;
                        }
                        if (Date.now() > limite) {
                            reject(new Error('timeout'));
                            return;
                        }
                        setTimeout(tick, POLL_MS);
                    })
                    .catch(() => {
                        if (Date.now() > limite) { reject(new Error('network')); return; }
                        setTimeout(tick, POLL_MS);
                    });
            };
            setTimeout(tick, POLL_MS);
        });
    }

    boton.addEventListener('click', function () {
        if (boton.disabled) return;

        const generadoPrevio = seccion.getAttribute('data-generado') || '';
        aviso.hidden = true;
        setCargando(true);
        mostrarAviso('warning', 'Analizando las reseñas de tus clientes… esto puede tardar unos segundos.', { busy: true });

        fetch('/admin/feedback/refresh', { method: 'POST', headers: { 'Accept': 'application/json' } })
            .then((r) => r.json().then((body) => ({ ok: r.ok, body })))
            .then(({ ok, body }) => {
                if (!ok || !body.ok) {
                    throw new Error(body && body.msg ? body.msg : 'No pudimos iniciar el análisis. Inténtalo de nuevo en un momento.');
                }
                return esperarNuevasAreas(generadoPrevio);
            })
            .then((data) => {
                pintar(data.acciones);
                seccion.setAttribute('data-generado', data.generado_en || '');
                if (updated) updated.textContent = fechaBonita(data.generado_en);
                mostrarAviso('success', '¡Listo! Actualizamos las áreas de mejora con las reseñas más recientes.', { autoOcultar: 4500 });
            })
            .catch((err) => {
                const msg = err && err.message === 'timeout'
                    ? 'El análisis está tardando más de lo esperado. Vuelve a intentarlo en unos minutos.'
                    : (err && err.message) || 'Ocurrió un error al actualizar las áreas de mejora.';
                mostrarAviso('danger', msg);
            })
            .finally(() => setCargando(false));
    });
})();
</script>
