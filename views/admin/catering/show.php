<?php
/**
 * Detalle de una solicitud de catering: los datos que dejó el cliente, el
 * avance del estado y el comentario interno del equipo.
 */

$estados = $estados ?? [];
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

$esEmail = (string)$solicitud->contacto_tipo === 'email';
$estado = (string)$solicitud->estado;
?>
<section class="admin-catering admin-catering--detalle admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Catering</span>
            <h2 class="admin-page__title"><?php echo $e($solicitud->nombre); ?></h2>
            <p class="admin-page__subtitle">
                <?php echo $e($solicitud->tipo_evento); ?>
                · Recibida el <?php echo $e(date('d/m/Y H:i', strtotime((string)$solicitud->created_at))); ?>
            </p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary" href="/admin/catering">Volver</a>
            <a class="admin-btn admin-btn--primary"
               href="<?php echo $e(($esEmail ? 'mailto:' : 'tel:') . $solicitud->contacto); ?>">
                <?php echo $esEmail ? 'Responder por correo' : 'Llamar'; ?>
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <div class="admin-catering__detalle-grid">
        <section class="admin-panel admin-card">
            <h3>Datos de la solicitud</h3>
            <dl class="admin-catering__datos">
                <div>
                    <dt>Contacto</dt>
                    <dd>
                        <a href="<?php echo $e(($esEmail ? 'mailto:' : 'tel:') . $solicitud->contacto); ?>">
                            <?php echo $e($solicitud->contacto); ?>
                        </a>
                        <span class="admin-catering__muted">(<?php echo $esEmail ? 'correo' : 'teléfono'; ?>)</span>
                    </dd>
                </div>
                <div>
                    <dt>Tipo de evento</dt>
                    <dd><?php echo $e($solicitud->tipo_evento); ?></dd>
                </div>
                <div>
                    <dt>Fecha del evento</dt>
                    <dd>
                        <?php echo $solicitud->fecha_evento
                            ? $e(date('d/m/Y', strtotime((string)$solicitud->fecha_evento)))
                            : '<span class="admin-catering__muted">Sin definir</span>'; ?>
                    </dd>
                </div>
                <div>
                    <dt>Invitados</dt>
                    <dd>
                        <?php echo $solicitud->invitados !== null
                            ? (int)$solicitud->invitados
                            : '<span class="admin-catering__muted">Sin especificar</span>'; ?>
                    </dd>
                </div>
                <div>
                    <dt>Presupuesto estimado</dt>
                    <dd>
                        <?php echo !empty($solicitud->presupuesto)
                            ? $e($solicitud->presupuesto)
                            : '<span class="admin-catering__muted">Sin especificar</span>'; ?>
                    </dd>
                </div>
            </dl>

            <?php if (!empty($solicitud->mensaje)) : ?>
                <div class="admin-catering__mensaje">
                    <h4>Mensaje del cliente</h4>
                    <p><?php echo nl2br($e($solicitud->mensaje)); ?></p>
                </div>
            <?php endif; ?>
        </section>

        <div class="admin-catering__lateral">
            <section class="admin-panel admin-card">
                <h3>Seguimiento</h3>
                <p class="admin-catering__estado-actual">
                    Estado actual:
                    <span class="admin-badge admin-badge--<?php echo $e($badgePorEstado[$estado] ?? 'neutral'); ?>">
                        <?php echo $e($etiquetaEstado[$estado] ?? ucfirst($estado)); ?>
                    </span>
                </p>

                <form class="admin-catering__estado-form" method="POST" action="/admin/catering/estado">
                    <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$solicitud->id; ?>">
                    <?php /* Marca el origen para volver aquí y no a la bandeja. */ ?>
                    <input type="hidden" name="origen" value="detalle">

                    <div class="admin-field">
                        <label class="admin-field__label" for="estado">Cambiar a</label>
                        <select class="admin-catering__input" id="estado" name="estado">
                            <?php foreach ($estados as $opcion) : ?>
                                <option value="<?php echo $e($opcion); ?>" <?php echo $opcion === $estado ? 'selected' : ''; ?>>
                                    <?php echo $e($etiquetaEstado[$opcion] ?? ucfirst($opcion)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="admin-btn admin-btn--primary" type="submit">Actualizar estado</button>
                </form>
            </section>

            <section class="admin-panel admin-card">
                <h3>Comentario interno</h3>
                <form method="POST" action="/admin/catering/comentario">
                    <input type="hidden" name="admin_csrf" value="<?php echo $e($adminCsrfToken); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$solicitud->id; ?>">

                    <div class="admin-field">
                        <label class="admin-field__label" for="comentario_admin">Notas del equipo</label>
                        <textarea class="admin-catering__textarea" id="comentario_admin" name="comentario_admin"
                                  rows="6" placeholder="Qué se cotizó, con quién se habló, próximos pasos…"><?php
                            echo $e($solicitud->comentario_admin ?? '');
                        ?></textarea>
                        <span class="admin-field__hint">Sólo lo ve el equipo; el cliente nunca lo lee.</span>
                    </div>

                    <button class="admin-btn admin-btn--secondary" type="submit">Guardar comentario</button>
                </form>
            </section>
        </div>
    </div>
</section>
