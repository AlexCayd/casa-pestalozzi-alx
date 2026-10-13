<?php
require_once __DIR__ . '/../partials/_icons.php';

$modulosVisibilidad = is_array($modulosVisibilidad ?? null) ? $modulosVisibilidad : [];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$totalModulos = count($modulosVisibilidad);
$totalVisibles = count(array_filter($modulosVisibilidad, static fn (array $m): bool => !empty($m['visible'])));
?>
<section class="admin-configuration admin-menu admin-page" data-configuration-page="modules">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Configuración</span>
            <h1 class="admin-page__title">Visibilidad de módulos</h1>
            <p class="admin-page__subtitle">Elige qué módulos aparecen en el panel de administración. Un módulo oculto sale del menú lateral y su página deja de abrirse.</p>
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

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-panel admin-card admin-config-panel" aria-labelledby="modules-form-title">
        <div class="admin-config-panel__head">
            <div>
                <h2 id="modules-form-title">Módulos del panel</h2>
                <p>Ocultar no borra nada: los datos del módulo se conservan y vuelve tal cual al encenderlo.</p>
            </div>
            <p class="admin-modules-count">
                <span class="admin-num" data-modules-visible-count><?php echo $totalVisibles; ?></span>
                de <span class="admin-num"><?php echo $totalModulos; ?></span> visibles
            </p>
        </div>

        <form class="admin-modules-form" method="POST" action="/admin/configuracion/modulos" data-modules-form>
            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken ?? ''); ?>">

            <ul class="admin-modules-list">
                <?php foreach ($modulosVisibilidad as $modulo) : ?>
                    <?php
                    $clave = (string) $modulo['clave'];
                    $visible = !empty($modulo['visible']);
                    $bloqueado = !empty($modulo['bloqueado']);
                    $idTitulo = 'modulo-' . preg_replace('/[^a-z0-9-]/', '-', $clave);
                    // 'productos' es la clave histórica de Recetas; su icono es
                    // 'products', igual que en el sidebar.
                    $icono = admin_icon($clave === 'productos' ? 'products' : $clave, 20);
                    ?>
                    <li class="admin-modules-item<?php echo $visible ? '' : ' is-hidden'; ?>">
                        <span class="admin-modules-item__icon" aria-hidden="true"><?php echo $icono; ?></span>
                        <span class="admin-modules-item__body">
                            <span class="admin-modules-item__title" id="<?php echo $h($idTitulo); ?>"><?php echo $h($modulo['titulo']); ?></span>
                            <span class="admin-modules-item__path"><?php echo $h($modulo['ruta']); ?></span>
                        </span>

                        <?php if ($bloqueado) : ?>
                            <?php /* Deshabilitado y no oculto: que se vea que existe
                                     y por qué no se puede apagar. El navegador no
                                     envía un campo disabled; el servicio lo guarda
                                     visible de todos modos. */ ?>
                            <label class="admin-switch admin-modules-item__switch">
                                <input type="checkbox" checked disabled aria-labelledby="<?php echo $h($idTitulo); ?>" aria-describedby="<?php echo $h($idTitulo); ?>-nota">
                                <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                                <span class="admin-switch__label" id="<?php echo $h($idTitulo); ?>-nota">Siempre visible</span>
                            </label>
                        <?php else : ?>
                            <label class="admin-switch admin-modules-item__switch">
                                <input type="checkbox" name="visible[]" value="<?php echo $h($clave); ?>"
                                       aria-labelledby="<?php echo $h($idTitulo); ?>"
                                       data-module-toggle
                                       <?php echo $visible ? 'checked' : ''; ?>>
                                <span class="admin-switch__track" aria-hidden="true"><span class="admin-switch__thumb"></span></span>
                                <span class="admin-switch__label" data-module-state><?php echo $visible ? 'Visible' : 'Oculto'; ?></span>
                            </label>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="admin-pos-form__note">
                Configuración no se puede ocultar: es donde vive este ajuste. El alcance es sólo el
                panel de administración: el punto de venta de los meseros, los tableros de producción
                y el mapa operativo de reservaciones siguen funcionando igual aunque su módulo esté
                oculto aquí.
            </p>

            <div class="admin-config-form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Guardar cambios</button>
                <p class="admin-form-status" aria-live="polite"></p>
            </div>
        </form>
    </section>
</section>
