<?php
/**
 * Lo que ve quien abre por URL —un marcador, el historial, un enlace de otro
 * módulo— un módulo que el administrador ocultó. Explica qué pasó y dónde se
 * vuelve a encender, en vez de un 404 que haría pensar que el módulo se borró.
 */
$moduloOculto = is_array($moduloOculto ?? null) ? $moduloOculto : [];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="admin-placeholder admin-module-hidden" aria-labelledby="modulo-oculto-titulo">
    <span class="admin-placeholder__status">Módulo oculto</span>
    <h2 id="modulo-oculto-titulo"><?php echo $h($moduloOculto['title'] ?? 'Módulo'); ?></h2>
    <p>
        Este módulo está oculto en el panel de administración. Sus datos se conservan;
        para volver a usarlo, enciéndelo en la visibilidad de módulos.
    </p>
    <div class="admin-module-hidden__actions">
        <a class="admin-btn admin-btn--primary" href="/admin/configuracion/modulos">Visibilidad de módulos</a>
        <a class="admin-btn admin-btn--secondary" href="<?php echo $h($panelInicio ?? '/admin'); ?>">Ir al inicio del panel</a>
    </div>
</section>
