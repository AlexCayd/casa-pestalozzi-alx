<?php
/**
 * Bandeja de solicitudes de cotización de catering.
 *
 * El filtro por defecto son las abiertas: esto es una lista de pendientes, no
 * un archivo histórico.
 */

$solicitudes = $solicitudes ?? [];
$estados = $estados ?? [];
$conteo = $conteo ?? [];
$estadoActivo = (string)($estadoActivo ?? 'abiertas');
$busqueda = (string)($busqueda ?? '');
$adminCsrfToken = (string)($adminCsrfToken ?? \Services\AdminCsrfService::token());

$e = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$badgePorEstado = [
    'nueva'      => 'info',
    'contactada' => 'warning',
    'cotizada'   => 'neutral',
    'ganada'     => 'success',
    'perdida'    => 'danger',
];

$etiquetaEstado = [
    'nueva'      => 'Nueva',
    'contactada' => 'Contactada',
    'cotizada'   => 'Cotizada',
    'ganada'     => 'Ganada',
    'perdida'    => 'Perdida',
];

// Pestañas: primero las dos vistas agregadas, luego cada estado.
$pestanas = [['abiertas', 'Abiertas'], ['', 'Todas']];
foreach ($estados as $estado) {
    $pestanas[] = [$estado, $etiquetaEstado[$estado] ?? ucfirst($estado)];
}
?>
<section class="admin-catering admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Experiencias</span>
            <h2 class="admin-page__title">Catering</h2>
            <p class="admin-page__subtitle">
                Solicitudes de cotización llegadas desde la landing. Se dan de alta solas;
                aquí se les da seguimiento hasta cerrarlas como ganadas o perdidas.
            </p>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <form class="admin-catering__filtros" method="GET" action="/admin/catering">
        <div class="admin-catering__tabs admin-tabs" role="group" aria-label="Filtrar por estado">
            <?php foreach ($pestanas as [$valor, $etiqueta]) : ?>
                <?php
                $activa = $estadoActivo === $valor;
                $url = $valor === '' ? '/admin/catering?estado=' : '/admin/catering?estado=' . $valor;
                $total = $valor === '' ? ($conteo['todas'] ?? 0) : ($conteo[$valor] ?? 0);
                ?>
                <a class="admin-tabs__tab <?php echo $activa ? 'is-active' : ''; ?>" href="<?php echo $e($url); ?>">
                    <?php echo $e($etiqueta); ?>
                    <span class="admin-catering__contador"><?php echo (int)$total; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="admin-catering__buscador admin-field">
            <input type="hidden" name="estado" value="<?php echo $e($estadoActivo); ?>">
            <label class="admin-field__label" for="catering-q">Buscar</label>
            <input class="admin-catering__input" type="search" id="catering-q" name="q"
                   value="<?php echo $e($busqueda); ?>" placeholder="Nombre, contacto o tipo de evento">
            <button class="admin-btn admin-btn--secondary" type="submit">Buscar</button>
        </div>
    </form>

    <section class="admin-panel admin-card">
        <div class="admin-catering__panel-head">
            <div>
                <h3>Solicitudes</h3>
                <p><?php echo count($solicitudes); ?> solicitud(es) en este filtro.</p>
            </div>
        </div>

        <?php if (empty($solicitudes)) : ?>
            <p class="admin-empty">No hay solicitudes con este filtro.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-catering__tabla">
                    <thead>
                        <tr>
                            <th>Solicitante</th>
                            <th>Evento</th>
                            <th class="admin-table__num">Invitados</th>
                            <th>Recibida</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($solicitudes as $solicitud) : ?>
                            <?php
                            $id = (int)$solicitud['id'];
                            $estado = (string)$solicitud['estado'];
                            $esEmail = (string)$solicitud['contacto_tipo'] === 'email';
                            $fechaEvento = $solicitud['fecha_evento'] ?? null;
                            ?>
                            <tr data-row-href="/admin/catering/detalle?id=<?php echo $id; ?>">
                                <td>
                                    <span class="admin-table__cell-main"><?php echo $e($solicitud['nombre']); ?></span>
                                    <span class="admin-table__cell-sub">
                                        <a href="<?php echo $e(($esEmail ? 'mailto:' : 'tel:') . $solicitud['contacto']); ?>">
                                            <?php echo $e($solicitud['contacto']); ?>
                                        </a>
                                    </span>
                                </td>
                                <td>
                                    <span class="admin-table__cell-main"><?php echo $e($solicitud['tipo_evento']); ?></span>
                                    <span class="admin-table__cell-sub">
                                        <?php echo $fechaEvento
                                            ? $e(date('d/m/Y', strtotime((string)$fechaEvento)))
                                            : 'Sin fecha definida'; ?>
                                    </span>
                                </td>
                                <td class="admin-table__num">
                                    <?php echo $solicitud['invitados'] !== null
                                        ? (int)$solicitud['invitados']
                                        : '<span class="admin-catering__muted">—</span>'; ?>
                                </td>
                                <td><?php echo $e(date('d/m/Y H:i', strtotime((string)$solicitud['created_at']))); ?></td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $e($badgePorEstado[$estado] ?? 'neutral'); ?>">
                                        <?php echo $e($etiquetaEstado[$estado] ?? ucfirst($estado)); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-catering__acciones">
                                        <a class="admin-btn admin-btn--secondary admin-btn--small"
                                           href="/admin/catering/detalle?id=<?php echo $id; ?>">Ver</a>
                                        <form class="admin-catering__estado-form" method="POST" action="/admin/catering/estado">
                                            <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
                                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                                            <select class="admin-catering__input admin-catering__input--compacto"
                                                    name="estado" data-catering-estado aria-label="Cambiar estado">
                                                <?php foreach ($estados as $opcion) : ?>
                                                    <option value="<?php echo $e($opcion); ?>" <?php echo $opcion === $estado ? 'selected' : ''; ?>>
                                                        <?php echo $e($etiquetaEstado[$opcion] ?? ucfirst($opcion)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <noscript>
                                                <button class="admin-btn admin-btn--secondary admin-btn--small" type="submit">Guardar</button>
                                            </noscript>
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
