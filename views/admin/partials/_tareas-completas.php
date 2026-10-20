<?php
/**
 * Lista de pendientes vacía: el acuse de que el trabajo está hecho.
 *
 * Es el único momento con movimiento de las listas de pendientes (Menú e
 * Inventario): en una herramienta que se usa a diario la animación se reserva
 * para responder a lo que la persona consiguió, no para entrar en pantalla. El
 * sello entra con rebote y el visto se traza; con movimiento reducido aparece
 * quieto (ver _pendientes.scss).
 *
 * Dos intensidades: completo (no queda nada en todo el catálogo) y parcial (la
 * sección filtrada está al día pero quedan pendientes fuera de ella). La
 * parcial no celebra el catálogo entero: lo dice y ofrece ir a por el resto.
 *
 * Parámetros:
 * - $tcTitulo   (string) obligatorio.
 * - $tcTexto    (string) opcional.
 * - $tcParcial  (bool)   sello menor y sin rebote.
 * - $tcAcciones (array)  [['href' => ..., 'texto' => ..., 'primaria' => bool]].
 *
 * Cierra con unset() de sus parámetros.
 */
require_once __DIR__ . '/_icons.php';

$tcTitulo = (string) ($tcTitulo ?? '');
$tcTexto = (string) ($tcTexto ?? '');
$tcParcial = (bool) ($tcParcial ?? false);
$tcAcciones = is_array($tcAcciones ?? null) ? $tcAcciones : [];
?>
<div class="admin-tareas-fin<?php echo $tcParcial ? ' admin-tareas-fin--parcial' : ''; ?>">
    <span class="admin-tareas-fin__sello" aria-hidden="true"><?php echo admin_icon('check', 30); ?></span>
    <p class="admin-tareas-fin__titulo"><?php echo htmlspecialchars($tcTitulo, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($tcTexto !== '') : ?>
        <p class="admin-tareas-fin__texto"><?php echo htmlspecialchars($tcTexto, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if (!empty($tcAcciones)) : ?>
        <div class="admin-tareas-fin__acciones">
            <?php foreach ($tcAcciones as $tcAccion) : ?>
                <a class="admin-btn <?php echo !empty($tcAccion['primaria']) ? 'admin-btn--primary' : 'admin-btn--secondary'; ?>"
                   href="<?php echo htmlspecialchars((string) ($tcAccion['href'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars((string) ($tcAccion['texto'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
unset($tcTitulo, $tcTexto, $tcParcial, $tcAcciones, $tcAccion);
?>
