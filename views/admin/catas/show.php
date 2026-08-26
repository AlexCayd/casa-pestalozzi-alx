<?php
/**
 * Detalle de una cata: resumen de cupo y lista de inscritos con su estado.
 *
 * El cambio de estado de cada inscripción es un formulario propio con envío
 * automático al elegir: es la acción que más se repite durante el día de la cata.
 */

$inscripciones = $inscripciones ?? [];
$estadosInscripcion = $estadosInscripcion ?? [];
$lugaresTomados = (int)($lugaresTomados ?? 0);
$lugaresDisponibles = (int)($lugaresDisponibles ?? 0);
$adminCsrfToken = (string)($adminCsrfToken ?? \Services\AdminCsrfService::token());

$e = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$badgePorEstado = [
    'pendiente'  => 'warning',
    'confirmada' => 'success',
    'cancelada'  => 'danger',
    'asistio'    => 'info',
    'no_show'    => 'neutral',
];

$etiquetaInscripcion = [
    'pendiente'  => 'Pendiente',
    'confirmada' => 'Confirmada',
    'cancelada'  => 'Cancelada',
    'asistio'    => 'Asistió',
    'no_show'    => 'No se presentó',
];

$cupo = (int)$cata->cupo;
$ocupacion = $cupo > 0 ? min(100, (int)round($lugaresTomados / $cupo * 100)) : 0;
$inicio = $cata->inicio();
?>
<section class="admin-catas admin-catas--detalle admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Catas</span>
            <h2 class="admin-page__title"><?php echo $e($cata->titulo); ?></h2>
            <p class="admin-page__subtitle">
                <?php echo $inicio ? $e($inicio->format('d/m/Y')) . ' · ' . $e($inicio->format('H:i')) : 'Fecha por definir'; ?>
                · <?php echo (int)$cata->duracion_min; ?> min
                · <?php echo $e('$' . number_format((float)$cata->precio, 2)); ?> por persona
            </p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary" href="/admin/catas">Volver</a>
            <a class="admin-btn admin-btn--primary" href="/admin/catas/editar?id=<?php echo (int)$cata->id; ?>">Editar cata</a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-catas__resumen">
        <article class="admin-card admin-catas__stat">
            <span class="admin-catas__stat-label">Lugares ocupados</span>
            <strong class="admin-catas__stat-valor"><?php echo $lugaresTomados; ?> / <?php echo $cupo; ?></strong>
            <span class="admin-catas__barra" aria-hidden="true">
                <span class="admin-catas__barra-fill" style="width: <?php echo $ocupacion; ?>%"></span>
            </span>
        </article>
        <article class="admin-card admin-catas__stat">
            <span class="admin-catas__stat-label">Lugares libres</span>
            <strong class="admin-catas__stat-valor"><?php echo $lugaresDisponibles; ?></strong>
        </article>
        <article class="admin-card admin-catas__stat">
            <span class="admin-catas__stat-label">Estado de la cata</span>
            <strong class="admin-catas__stat-valor">
                <span class="admin-badge admin-badge--<?php
                    echo $e(['borrador' => 'neutral', 'publicada' => 'success', 'agotada' => 'warning',
                             'realizada' => 'info', 'cancelada' => 'danger'][$cata->estado] ?? 'neutral');
                ?>"><?php echo $e(ucfirst((string)$cata->estado)); ?></span>
            </strong>
        </article>
    </section>

    <?php if (!empty($cata->descripcion)) : ?>
        <section class="admin-card admin-panel admin-catas__descripcion">
            <h3>Descripción</h3>
            <p><?php echo nl2br($e($cata->descripcion)); ?></p>
        </section>
    <?php endif; ?>

    <section class="admin-panel admin-card">
        <div class="admin-catas__panel-head">
            <div>
                <h3>Inscritos</h3>
                <p><?php echo count($inscripciones); ?> inscripción(es) registrada(s).</p>
            </div>
        </div>

        <?php if (empty($inscripciones)) : ?>
            <p class="admin-empty">Todavía nadie se ha inscrito a esta cata.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-catas__tabla">
                    <thead>
                        <tr>
                            <th>Persona</th>
                            <th>Contacto</th>
                            <th class="admin-table__num">Personas</th>
                            <th>Nota</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inscripciones as $inscripcion) : ?>
                            <?php
                            $estado = (string)$inscripcion['estado'];
                            $creada = $inscripcion['created_at'] ?? null;
                            ?>
                            <tr>
                                <td>
                                    <span class="admin-table__cell-main"><?php echo $e($inscripcion['nombre']); ?></span>
                                    <?php if ($creada) : ?>
                                        <span class="admin-table__cell-sub">
                                            Inscrita el <?php echo $e(date('d/m/Y H:i', strtotime((string)$creada))); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    // El contacto se guarda normalizado; el enlace usa
                                    // el esquema que corresponde para poder responder
                                    // desde la misma tabla.
                                    $esEmail = (string)$inscripcion['contacto_tipo'] === 'email';
                                    $href = ($esEmail ? 'mailto:' : 'tel:') . (string)$inscripcion['contacto'];
                                    ?>
                                    <a href="<?php echo $e($href); ?>"><?php echo $e($inscripcion['contacto']); ?></a>
                                    <span class="admin-table__cell-sub"><?php echo $esEmail ? 'Correo' : 'Teléfono'; ?></span>
                                </td>
                                <td class="admin-table__num"><?php echo (int)$inscripcion['personas']; ?></td>
                                <td>
                                    <?php if (!empty($inscripcion['nota'])) : ?>
                                        <?php echo $e($inscripcion['nota']); ?>
                                    <?php else : ?>
                                        <span class="admin-catas__muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form class="admin-catas__estado-form" method="POST"
                                          action="/admin/catas/inscripcion/estado">
                                        <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
                                        <input type="hidden" name="cata_id" value="<?php echo (int)$cata->id; ?>">
                                        <input type="hidden" name="inscripcion_id" value="<?php echo (int)$inscripcion['id']; ?>">
                                        <span class="admin-badge admin-badge--<?php echo $e($badgePorEstado[$estado] ?? 'neutral'); ?>">
                                            <?php echo $e($etiquetaInscripcion[$estado] ?? ucfirst($estado)); ?>
                                        </span>
                                        <select class="admin-catas__input admin-catas__input--compacto"
                                                name="estado" data-cata-estado aria-label="Cambiar estado de la inscripción">
                                            <?php foreach ($estadosInscripcion as $opcion) : ?>
                                                <option value="<?php echo $e($opcion); ?>" <?php echo $opcion === $estado ? 'selected' : ''; ?>>
                                                    <?php echo $e($etiquetaInscripcion[$opcion] ?? ucfirst($opcion)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php /* Respaldo sin JS: si catas.js no cargó, el botón sigue enviando. */ ?>
                                        <noscript>
                                            <button class="admin-btn admin-btn--secondary admin-btn--small" type="submit">Guardar</button>
                                        </noscript>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
