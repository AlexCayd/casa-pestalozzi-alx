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
 *      no parpadea. Son DOS capas fijas: la cortina (los paños de tinta) y,
 *      encima, el centro (luz, nombre, filete y saludo). Van separadas porque
 *      el centro se funde con `difference` contra lo que tenga debajo —tinta o
 *      panel— y una capa común lo aislaría de la página.
 *   2. CSS (_transitions.scss): el nombre lleva `view-transition-name:
 *      cp-marca`, de modo que la transición de documento cruzado lo trae desde
 *      la marca del login hasta el centro. Si no hay JS, la cortina sube sola
 *      con una animación propia.
 *   3. JS (src/js/components/llegada.js): tras la pausa, la cortina sube en
 *      ola desde la marca de la pantalla (`data-llegada-destino`) y el nombre
 *      vuela en arco hasta ella. Se salta con cualquier tecla o toque.
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
   `display: contents` de .cp-llegada (_transitions.scss) le gana. Si la página
   llegara sin su hoja, el velo no se pinta como un bloque de texto suelto
   encima de la pantalla: simplemente no aparece.

   Cinco paños: los que caben en un monitor. llegada.js los remide (el primero
   con el ancho del sidebar, para que la casa se abra por sus columnas) y en
   un teléfono la hoja deja tres.

   `aria-hidden` también en las dos capas y no sólo en el contenedor: con
   `display: contents` el contenedor no genera caja, y no todos los motores
   propagan el atributo de una caja que no existe. */
?>
<div class="cp-llegada" data-llegada<?php echo $llegadaDestino !== '' ? ' data-llegada-destino="' . $llegadaH($llegadaDestino) . '"' : ''; ?> aria-hidden="true" hidden>
    <div class="cp-llegada__cortina" data-llegada-cortina aria-hidden="true">
        <span class="cp-llegada__pano"></span>
        <span class="cp-llegada__pano"></span>
        <span class="cp-llegada__pano"></span>
        <span class="cp-llegada__pano"></span>
        <span class="cp-llegada__pano"></span>
    </div>
    <div class="cp-llegada__centro" aria-hidden="true">
        <?php /* La luz va DENTRO del centro y debajo del nombre, no en la
                 cortina: bajo un `difference`, una luz en el fondo se RESTA de
                 la letra —el nombre perdía un 12 % de brillo justo al soltarlo
                 la transición de documento, y el saludo bajaba a 4.9:1—. Aquí
                 la letra opaca la tapa dentro del grupo y sólo se funde el
                 halo. */ ?>
        <span class="cp-llegada__luz"></span>
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
