<?php
$configuraciones = is_array($configuraciones ?? null) ? $configuraciones : [];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="admin-configuration admin-page" data-configuration-page="index">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Opciones generales</span>
            <h1 class="admin-page__title">Configuración</h1>
            <p class="admin-page__subtitle">Administra desde aquí las opciones generales del sistema.</p>
        </div>
    </header>

    <div class="admin-config-grid admin-grid" aria-label="Opciones de configuración">
        <?php foreach ($configuraciones as $configuracion) : ?>
            <?php
              // Solo se acepta un nombre de custom property de la paleta: así el
              // color no puede colarse como valor arbitrario en el style.
              $acento = (string) ($configuracion['acento'] ?? '--admin-gold');
              $acento = preg_match('/^--admin-[a-z0-9-]+$/', $acento) ? $acento : '--admin-gold';
            ?>
            <a
                class="admin-config-option admin-card"
                style="--config-accent: var(<?php echo $h($acento); ?>)"
                href="<?php echo $h($configuracion['ruta'] ?? ''); ?>"
            >
                <span class="admin-config-option__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <?php echo $configuracion['icono'] ?? ''; ?>
                    </svg>
                </span>

                <span class="admin-config-option__body">
                    <span class="admin-config-option__eyebrow">Configuración</span>
                    <h2><?php echo $h($configuracion['titulo'] ?? ''); ?></h2>
                    <span class="admin-config-option__description"><?php echo $h($configuracion['descripcion'] ?? ''); ?></span>
                </span>

                <span class="admin-config-option__cta">
                    <span>Administrar</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="m9 18 6-6-6-6"/>
                    </svg>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
