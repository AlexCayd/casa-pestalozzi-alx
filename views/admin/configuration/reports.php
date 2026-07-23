<?php
$reportes = is_array($reportes ?? null) ? $reportes : [];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusLabels = [
    'nuevo' => ['label' => 'Nuevo', 'class' => 'admin-badge--info'],
    'en_revision' => ['label' => 'En revisión', 'class' => 'admin-badge--warning'],
    'resuelto' => ['label' => 'Resuelto', 'class' => 'admin-badge--success'],
    'descartado' => ['label' => 'Descartado', 'class' => 'admin-badge--neutral'],
];
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="reports">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Reportes del sistema</h1>
            <p class="admin-page__subtitle">Consulta los problemas reportados desde el panel y revisa el contexto técnico capturado.</p>
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

    <form class="admin-filters admin-config-filters" data-report-filters>
        <div class="admin-filters__search">
            <label for="reports-search">Buscar</label>
            <input id="reports-search" type="search" placeholder="Título o módulo" data-report-search>
        </div>
        <div class="admin-filters__group">
            <label for="reports-status">Estado</label>
            <select id="reports-status" data-report-status>
                <option value="">Todos</option>
                <?php foreach ($statusLabels as $value => $meta) : ?>
                    <option value="<?php echo $value; ?>"><?php echo $meta['label']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filters__actions">
            <button type="button" class="admin-btn admin-btn--secondary" data-report-reset>Limpiar</button>
        </div>
    </form>

    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="reports-list-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="reports-list-title">Reportes recibidos</h2>
                <p><span data-report-count><?php echo count($reportes); ?></span> reporte(s).</p>
            </div>
        </div>

        <div class="admin-table-wrap admin-config-table-wrap" <?php echo empty($reportes) ? 'hidden' : ''; ?>>
            <table class="admin-table admin-config-table">
                <thead><tr><th>Folio</th><th>Título</th><th>Módulo</th><th>Navegador</th><th>Estado</th><th>Fecha</th><th>Acción</th></tr></thead>
                <tbody data-report-list>
                    <?php foreach ($reportes as $reporte) : ?>
                        <?php
                        $status = (string) ($reporte['estado'] ?? 'nuevo');
                        $meta = $statusLabels[$status] ?? $statusLabels['nuevo'];
                        $json = json_encode($reporte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        ?>
                        <tr data-report-row data-search="<?php echo $h(($reporte['titulo'] ?? '') . ' ' . ($reporte['modulo'] ?? '')); ?>" data-status="<?php echo $h($status); ?>">
                            <td data-label="Folio"><strong><?php echo $h($reporte['folio'] ?? ''); ?></strong></td>
                            <td data-label="Título"><?php echo $h($reporte['titulo'] ?? ''); ?></td>
                            <td data-label="Módulo"><?php echo $h($reporte['modulo'] ?? ''); ?></td>
                            <td data-label="Navegador"><?php echo $h($reporte['navegador'] ?? ''); ?></td>
                            <td data-label="Estado"><span class="admin-badge <?php echo $meta['class']; ?>"><?php echo $meta['label']; ?></span></td>
                            <td data-label="Fecha"><?php echo $h($reporte['fecha'] ?? ''); ?></td>
                            <td data-label="Acción"><button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-report-detail='<?php echo $h($json ?: '{}'); ?>'>Ver detalles</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="admin-empty admin-config-empty" data-report-empty <?php echo empty($reportes) ? '' : 'hidden'; ?>>
            <strong>No hay reportes para mostrar</strong>
            <span>Los problemas enviados desde el panel aparecerán aquí cuando se conecte el backend.</span>
        </div>
    </section>
</section>

<div class="admin-modal" id="report-detail-modal" data-admin-modal hidden>
    <button class="admin-modal__backdrop" type="button" tabindex="-1" aria-hidden="true" data-admin-modal-close></button>
    <div class="admin-modal__dialog admin-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="report-detail-title" tabindex="-1" data-admin-modal-dialog>
        <div class="admin-modal__head">
            <div>
                <span class="admin-modal__eyebrow" data-detail-folio>Reporte</span>
                <h2 class="admin-modal__title" id="report-detail-title" data-detail-title>Detalle del reporte</h2>
            </div>
            <button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close>&times;</button>
        </div>
        <dl class="admin-report-detail">
            <div class="admin-report-detail__wide"><dt>Descripción</dt><dd data-detail-description></dd></div>
            <div><dt>Módulo</dt><dd data-detail-module></dd></div>
            <div><dt>Navegador</dt><dd data-detail-browser></dd></div>
            <div class="admin-report-detail__wide"><dt>Ruta de origen</dt><dd data-detail-route></dd></div>
            <div><dt>Fecha</dt><dd data-detail-date></dd></div>
            <div><dt>Estado</dt><dd data-detail-status></dd></div>
        </dl>
        <div class="admin-modal__actions"><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cerrar</button></div>
    </div>
</div>
