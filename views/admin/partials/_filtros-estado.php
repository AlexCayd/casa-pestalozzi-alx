<?php
/**
 * Tarjetas-filtro de un listado: el resumen que además es el control.
 *
 * Nació en Menú («Platillos» / «Con receta») y lo comparte Inventario
 * («Ingredientes» / «Sobre el mínimo» / «Con proveedor»), para que los dos
 * módulos se lean como un mismo sistema: la misma tarjeta dice cuánto falta y,
 * al pulsarla, el listado de debajo se convierte en la lista de pendientes.
 *
 * Son ENLACES, no botones: el filtro vive en la URL (?pendiente=), así que se
 * puede recargar, compartir y volver con «Atrás», y sin JS funciona igual. La
 * tarjeta vigente lleva aria-current="true"; si es una tarea, su enlace la
 * apaga (vuelve a «todos»), que es lo que se espera al pulsar un filtro que ya
 * está puesto.
 *
 * Dos tipos de tarjeta:
 * - 'todos': rótulo, cifra y una nota. Es el «quitar filtro» de la tira.
 * - 'tarea': avance «hechos de total» con su barra, el sello de cuántos faltan
 *   y la acción. Se cuenta lo HECHO y no lo pendiente porque la barra tiene
 *   que crecer a medida que se trabaja: un contador que baja hasta cero no se
 *   lee como avance.
 *
 * Parámetros:
 * - $feEtiqueta (string) aria-label de la navegación.
 * - $feTarjetas (array)  una entrada por tarjeta:
 *     rotulo (string), href (string), activa (bool), tipo ('todos'|'tarea');
 *     'todos' → cifra (string), nota (string, opcional), accion (string, opcional);
 *     'tarea' → hechos (int), total (int), pendientesTexto (string, «sin receta»),
 *               completoTexto (string, opcional).
 *
 * Cierra con unset(): una página podría incluirlo dos veces.
 */
require_once __DIR__ . '/_icons.php';

$feEtiqueta = (string) ($feEtiqueta ?? 'Estado del listado');
$feTarjetas = is_array($feTarjetas ?? null) ? $feTarjetas : [];
$feEsc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$feNumTareas = 0;
foreach ($feTarjetas as $feT) {
    if (($feT['tipo'] ?? '') === 'tarea') {
        $feNumTareas++;
    }
}
?>
<?php /* --fe-tareas reparte la rejilla: la tarjeta «todos» se queda en lo que
         ocupa su cifra y las de tarea se llevan el resto, que es donde la barra
         de avance aprovecha el ancho. Con anchos iguales la de «todos» quedaba
         como una caja grande y casi vacía. */ ?>
<nav class="admin-filtros-estado" aria-label="<?php echo $feEsc($feEtiqueta); ?>"
     style="--fe-tareas: <?php echo max(1, $feNumTareas); ?>">
    <?php foreach ($feTarjetas as $feT) : ?>
        <?php
            $feTipo = ($feT['tipo'] ?? 'todos') === 'tarea' ? 'tarea' : 'todos';
            $feActiva = (bool) ($feT['activa'] ?? false);
            $feClases = ['admin-filtro-estado', 'admin-filtro-estado--' . $feTipo];
            $feTotal = $feHechos = $fePendientes = $fePct = 0;
            $feCompleta = false;

            if ($feTipo === 'tarea') {
                $feTotal = max(0, (int) ($feT['total'] ?? 0));
                $feHechos = min($feTotal, max(0, (int) ($feT['hechos'] ?? 0)));
                $fePendientes = $feTotal - $feHechos;
                // floor y no round: 99,6 % redondeado diría «100 %» con un
                // pendiente todavía en la lista.
                $fePct = $feTotal > 0 ? (int) floor(($feHechos / $feTotal) * 100) : 0;
                $feCompleta = $feTotal > 0 && $fePendientes === 0;
                if ($feCompleta) {
                    $feClases[] = 'is-completa';
                }
            }
        ?>
        <a class="<?php echo implode(' ', $feClases); ?>"
           href="<?php echo $feEsc($feT['href'] ?? '#'); ?>"
           <?php echo $feActiva ? 'aria-current="true"' : ''; ?>>
            <span class="admin-filtro-estado__cabeza">
                <span class="admin-filtro-estado__rotulo"><?php echo $feEsc($feT['rotulo'] ?? ''); ?></span>
                <?php if ($feTipo === 'tarea' && $feTotal > 0) : ?>
                    <?php /* Oculto al lector: «30 de 107» ya lo dice, y leído antes
                             que la cifra el porcentaje sonaba a un tercer dato. */ ?>
                    <span class="admin-filtro-estado__pct" aria-hidden="true"><?php echo $fePct; ?>&nbsp;%</span>
                <?php endif; ?>
            </span>

            <?php if ($feTipo === 'tarea') : ?>
                <span class="admin-filtro-estado__cifra">
                    <?php echo number_format($feHechos); ?>
                    <span class="admin-filtro-estado__de">de <?php echo number_format($feTotal); ?></span>
                </span>
                <?php /* Decorativa: la cifra de arriba ya dice lo mismo en texto. */ ?>
                <span class="admin-filtro-estado__barra" aria-hidden="true">
                    <span class="admin-filtro-estado__relleno" style="--avance: <?php echo $feTotal > 0 ? round(($feHechos / $feTotal) * 100, 2) : 0; ?>%"></span>
                </span>
                <span class="admin-filtro-estado__pie">
                    <?php if ($feTotal === 0) : ?>
                        <span class="admin-badge admin-badge--neutral admin-badge--outline">Nada que revisar</span>
                    <?php elseif ($feCompleta) : ?>
                        <span class="admin-badge admin-badge--success admin-badge--outline"><?php echo admin_icon('check', 12); ?><?php echo $feEsc($feT['completoTexto'] ?? 'Completo'); ?></span>
                    <?php else : ?>
                        <span class="admin-badge admin-badge--warning admin-badge--outline"><?php echo number_format($fePendientes); ?> <?php echo $feEsc($feT['pendientesTexto'] ?? 'pendientes'); ?></span>
                    <?php endif; ?>

                    <?php if ($feActiva) : ?>
                        <span class="admin-filtro-estado__accion">Quitar filtro<?php echo admin_icon('cerrar', 14); ?></span>
                    <?php elseif ($feTotal > 0 && !$feCompleta) : ?>
                        <span class="admin-filtro-estado__accion">Ver pendientes<?php echo admin_icon('flecha-derecha', 14); ?></span>
                    <?php endif; ?>
                </span>
            <?php else : ?>
                <span class="admin-filtro-estado__cifra"><?php echo $feEsc($feT['cifra'] ?? '0'); ?></span>
                <?php if (!empty($feT['nota'])) : ?>
                    <span class="admin-filtro-estado__nota"><?php echo $feEsc($feT['nota']); ?></span>
                <?php endif; ?>
                <?php if (!$feActiva && !empty($feT['accion'])) : ?>
                    <span class="admin-filtro-estado__pie">
                        <span class="admin-filtro-estado__accion admin-filtro-estado__accion--volver"><?php echo admin_icon('flecha-izquierda', 14); ?><?php echo $feEsc($feT['accion']); ?></span>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
<?php
unset(
    $feEtiqueta, $feTarjetas, $feEsc, $feNumTareas, $feT, $feTipo, $feActiva, $feClases,
    $feTotal, $feHechos, $fePendientes, $fePct, $feCompleta
);
?>
