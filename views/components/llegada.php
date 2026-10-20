<?php
/**
 * La llegada: el velo con el nombre de la casa que recibe a quien acaba de
 * iniciar sesión, y que se resuelve en el logo de la pantalla de destino.
 *
 * Sólo se emite UNA vez por inicio de sesión: quien incluye este parcial lo
 * hace detrás de `Auth::consumirLlegada()` (views/admin/layout.php y
 * views/operation/partials/shell.php). En cualquier otra carga no existe, así
 * que no cuesta ni un byte.
 *
 * Tres piezas, y cada una degrada sola:
 *   1. Este marcado, al principio del <body>: tapa la página desde la primera
 *      pintura, con la misma tinta que el login, así que el cambio de documento
 *      no parpadea.
 *   2. CSS (_transitions.scss): el nombre lleva `view-transition-name:
 *      cp-marca`, de modo que la transición de documento cruzado lo trae desde
 *      la marca del login hasta el centro. Si no hay JS, el velo se sostiene y
 *      se retira solo con una animación propia.
 *   3. JS (src/js/components/llegada.js): tras la pausa, el nombre vuela hasta
 *      la marca de la pantalla (`data-llegada-destino`) mientras el velo se
 *      cierra sobre ella. Se salta con cualquier tecla o toque.
 *
 * Variables, todas opcionales:
 * - llegadaDestino: selectores CSS de la marca de destino, en orden de
 *   preferencia; se usa la primera que esté a la vista. Vacío: el nombre se
 *   apaga en su sitio (el POS no lleva marca en la barra).
 * - llegadaLugar: adónde se llega, en palabras («Punto de venta»).
 */

$llegadaDestino = trim((string)($llegadaDestino ?? ''));
$llegadaLugar = trim((string)($llegadaLugar ?? ''));
$llegadaH = static fn($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

// Sólo el nombre de pila: en una tablet compartida es lo que confirma de un
// vistazo que el NIP tecleado era el propio, y el apellido no añade nada.
$llegadaNombre = trim((string)($_SESSION['nombre'] ?? ''));
$llegadaPila = $llegadaNombre !== '' ? (preg_split('/\s+/u', $llegadaNombre)[0] ?? '') : '';
?>
<?php
/* `hidden` a propósito: lo oculta la hoja del NAVEGADOR, y el
   `display: grid` de .cp-llegada (_transitions.scss) le gana. Si la página
   trae una hoja vieja de caché —las vistas de área todavía enlazan
   operation.css con un `?v=` escrito a mano—, el velo no se pinta como un
   bloque de texto suelto encima de la pantalla: simplemente no aparece. */
?>
<div class="cp-llegada" data-llegada<?php echo $llegadaDestino !== '' ? ' data-llegada-destino="' . $llegadaH($llegadaDestino) . '"' : ''; ?> aria-hidden="true" hidden>
    <div class="cp-llegada__centro">
        <?php
        /* ═══ COMPONENTE REUTILIZABLE → views/templates/header-casa-pestalozzi.php
           Apilado y centrado: es el isologo del manual, y apilado también es
           como lo lleva el sidebar, que es adonde vuela en el panel. Nivel
           <span>: un <p> se llevaría el color atenuado de `.admin-body p`. */
        $hcpEtiqueta = 'div';
        $hcpNivel = 'span';
        $hcpHref = '';
        $hcpDosLineas = true;
        $hcpClase = 'cp-llegada__marca';
        include __DIR__ . '/../templates/header-casa-pestalozzi.php';
        ?>
        <span class="cp-llegada__filete"></span>
        <?php if ($llegadaPila !== '' || $llegadaLugar !== '') : ?>
            <p class="cp-llegada__saludo">
                <?php if ($llegadaPila !== '') : ?>
                    <span>Hola, <?php echo $llegadaH($llegadaPila); ?></span>
                <?php endif; ?>
                <?php if ($llegadaPila !== '' && $llegadaLugar !== '') : ?>
                    <span class="cp-llegada__punto"></span>
                <?php endif; ?>
                <?php if ($llegadaLugar !== '') : ?>
                    <span><?php echo $llegadaH($llegadaLugar); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>
<?php
// Se incluye una vez por página, pero el patrón de la casa es cerrar siempre:
// el siguiente parcial no debe heredar estos nombres.
unset($llegadaDestino, $llegadaLugar, $llegadaH, $llegadaNombre, $llegadaPila);
?>
