<?php
/**
 * Listado de catas programadas.
 *
 * Cada fila lleva el cupo consumido para que el estado de una cata se lea sin
 * entrar al detalle: es el dato que decide si hay que abrir otra sesión.
 */

$catas = $catas ?? [];
$estados = $estados ?? [];
$estadoActivo = (string)($estadoActivo ?? '');
$busqueda = (string)($busqueda ?? '');
$adminCsrfToken = (string)($adminCsrfToken ?? \Services\AdminCsrfService::token());

$e = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

// Cada estado con su pastilla del sistema de badges del panel.
$badgePorEstado = [
    'borrador'  => 'neutral',
    'publicada' => 'success',
    'agotada'   => 'warning',
    'realizada' => 'info',
    'cancelada' => 'danger',
];

$meses = [1 => 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];
?>
<section class="admin-catas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Experiencias</span>
            <h2 class="admin-page__title">Catas</h2>
            <p class="admin-page__subtitle">
                Programa las catas dirigidas y controla sus inscritos. Sólo las publicadas
                aparecen en la landing; una cata pasa sola a «agotada» cuando se llena el cupo.
            </p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--primary" href="/admin/catas/crear">Nueva cata</a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <form class="admin-catas__filtros" method="GET" action="/admin/catas">
        <div class="admin-catas__tabs admin-tabs" role="group" aria-label="Filtrar por estado">
            <a class="admin-tabs__tab <?php echo $estadoActivo === '' ? 'is-active' : ''; ?>"
               href="/admin/catas">Todas</a>
            <?php foreach ($estados as $estado) : ?>
                <a class="admin-tabs__tab <?php echo $estadoActivo === $estado ? 'is-active' : ''; ?>"
                   href="/admin/catas?estado=<?php echo $e($estado); ?>">
                    <?php echo $e(ucfirst($estado)); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="admin-catas__buscador admin-field">
            <?php if ($estadoActivo !== '') : ?>
                <input type="hidden" name="estado" value="<?php echo $e($estadoActivo); ?>">
            <?php endif; ?>
            <label class="admin-field__label" for="cata-q">Buscar</label>
            <input class="admin-catas__input" type="search" id="cata-q" name="q"
                   value="<?php echo $e($busqueda); ?>" placeholder="Título de la cata">
            <button class="admin-btn admin-btn--secondary" type="submit">Buscar</button>
        </div>
    </form>

    <section class="admin-panel admin-card">
        <div class="admin-catas__panel-head">
            <div>
                <h3>Catas programadas</h3>
                <p><?php echo count($catas); ?> cata(s) en este filtro.</p>
            </div>
        </div>

        <?php if (empty($catas)) : ?>
            <p class="admin-empty">
                No hay catas con este filtro.
                <a href="/admin/catas/crear">Programa la primera</a>.
            </p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-catas__tabla">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Cata</th>
                            <th>Cupo</th>
                            <th class="admin-table__num">Precio</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catas as $cata) : ?>
                            <?php
                            $id = (int)$cata['id'];
                            $cupo = (int)$cata['cupo'];
                            $tomados = (int)$cata['lugares_tomados'];
                            // Porcentaje para la barra de ocupación; se topa en
                            // 100 porque un recorte de cupo puede dejar sobrecupo.
                            $ocupacion = $cupo > 0 ? min(100, (int)round($tomados / $cupo * 100)) : 0;
                            $inicio = $cata['inicio'] ?? null;
                            $mes = $inicio ? $meses[(int)$inicio->format('n')] : '';
                            $badge = $badgePorEstado[$cata['estado']] ?? 'neutral';
                            ?>
                            <tr data-row-href="/admin/catas/detalle?id=<?php echo $id; ?>">
                                <td>
                                    <div class="admin-catas__fecha">
                                        <span class="admin-catas__dia"><?php echo $inicio ? $inicio->format('d') : '--'; ?></span>
                                        <span class="admin-catas__mes"><?php echo $e($mes); ?></span>
                                    </div>
                                    <span class="admin-table__cell-sub">
                                        <?php echo $inicio ? $e($inicio->format('H:i')) : ''; ?> ·
                                        <?php echo (int)$cata['duracion_min']; ?> min
                                    </span>
                                </td>
                                <td>
                                    <span class="admin-table__cell-main"><?php echo $e($cata['titulo']); ?></span>
                                    <?php if (!empty($cata['descripcion'])) : ?>
                                        <span class="admin-table__cell-sub">
                                            <?php echo $e(mb_strimwidth((string)$cata['descripcion'], 0, 70, '…')); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="admin-catas__cupo">
                                        <span class="admin-catas__cupo-cifra">
                                            <?php echo $tomados; ?> / <?php echo $cupo; ?>
                                        </span>
                                        <span class="admin-catas__barra" aria-hidden="true">
                                            <span class="admin-catas__barra-fill" style="width: <?php echo $ocupacion; ?>%"></span>
                                        </span>
                                        <span class="admin-table__cell-sub">
                                            <?php echo (int)$cata['inscripciones']; ?> inscripción(es)
                                        </span>
                                    </div>
                                </td>
                                <td class="admin-table__num">
                                    <?php echo $e('$' . number_format((float)$cata['precio'], 2)); ?>
                                </td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $e($badge); ?>">
                                        <?php echo $e(ucfirst((string)$cata['estado'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-catas__acciones">
                                        <a class="admin-btn admin-btn--secondary admin-btn--small"
                                           href="/admin/catas/detalle?id=<?php echo $id; ?>">Inscritos</a>
                                        <a class="admin-btn admin-btn--ghost admin-btn--small"
                                           href="/admin/catas/editar?id=<?php echo $id; ?>">Editar</a>
                                        <?php /* Sin confirm() nativo: admin.js engancha [data-confirm-delete]
                                                 al ConfirmationModal de la casa (ver CLAUDE.md). */ ?>
                                        <form method="POST" action="/admin/catas/eliminar"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar cata"
                                              data-confirm-title="¿Eliminar «<?php echo $e($cata['titulo']); ?>»?"
                                              data-confirm-description="Se eliminarán también sus <?php echo (int)$cata['inscripciones']; ?> inscripción(es)."
                                              data-confirm-consequence="Esta acción no se puede deshacer.">
                                            <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
                                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                                            <button class="admin-btn admin-btn--danger admin-btn--small" type="submit">Eliminar</button>
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
