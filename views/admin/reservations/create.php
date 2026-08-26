<?php
/**
 * Admin creation screen for reservations.
 */

$alertas = isset($alertas) && is_array($alertas) ? $alertas : [];
$backUrl = (string)($backUrl ?? '/admin/reservaciones');

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$alertasNormalizadas = [];
$agregarAlerta = static function ($tipo, $mensajes) use (&$alertasNormalizadas, &$agregarAlerta): void {
    if ($mensajes === null || $mensajes === '') {
        return;
    }

    if (is_array($mensajes)) {
        foreach ($mensajes as $mensaje) {
            $agregarAlerta($tipo, $mensaje);
        }
        return;
    }

    $tipo = is_string($tipo) ? $tipo : 'error';
    $alertasNormalizadas[$tipo][] = (string)$mensajes;
};

foreach ($alertas as $tipo => $mensajes) {
    $agregarAlerta($tipo, $mensajes);
}
?>

<section class="admin-reservations admin-reservation-detail admin-menu admin-page">
    <header class="admin-menu__header admin-page__header reservation-detail-header">
        <a class="admin-btn admin-btn--secondary admin-btn--icon admin-menu__button admin-menu__button--light" href="<?php echo $h($backUrl); ?>" aria-label="Volver a reservaciones" title="Volver a reservaciones">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
        </a>
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Reservaciones</span>
            <h2 class="admin-page__title">Nueva reservación</h2>
            <p class="admin-page__subtitle">Registra los datos de la visita.</p>
        </div>
    </header>

    <?php foreach ($alertasNormalizadas as $tipo => $mensajes) : ?>
        <?php
        $tipoAlerta = $tipo === 'exito' ? 'success' : ($tipo === 'warning' ? 'warning' : 'error');
        $tituloAlerta = $tipoAlerta === 'success' ? 'Listo' : ($tipoAlerta === 'warning' ? 'Atencion' : 'Revisa los siguientes datos');
        ?>
        <div class="admin-alert admin-alert--<?php echo $h($tipoAlerta); ?>">
            <strong><?php echo $h($tituloAlerta); ?></strong>
            <span><?php echo $h(implode(' ', $mensajes)); ?></span>
        </div>
    <?php endforeach; ?>

    <?php
    $modo = 'crear';
    include __DIR__ . '/_form.php';
    ?>
</section>
