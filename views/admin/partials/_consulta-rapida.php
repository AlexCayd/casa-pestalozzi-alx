<?php
/**
 * Barra de consultas rápidas: una pregunta en español y la respuesta al
 * instante, sin salir del módulo. La comparten Tickets y Análisis (ventas) y
 * Menú e Inventario (productos); `$crAmbito` sólo decide los ejemplos y cómo
 * se desempata una palabra que es platillo e ingrediente a la vez.
 *
 * El JS (core/consultas-rapidas.js, en admin.js) se engancha solo a
 * [data-consulta-rapida]. Sin JS el formulario hace un GET a la API y el
 * navegador enseña el JSON: no rompe nada, pero no es la experiencia.
 *
 * Parámetros: $crAmbito ('ventas' | 'productos'). Se limpian al final porque
 * el parcial puede incluirse más de una vez por página.
 */
require_once __DIR__ . '/_icons.php';

$crAmbito = in_array($crAmbito ?? '', ['ventas', 'productos'], true) ? $crAmbito : 'ventas';
$crId = 'consulta-' . $crAmbito;
try {
    $crEjemplos = \Services\Analytics\ConsultasRapidas::ejemplos($crAmbito);
} catch (\Throwable $e) {
    $crEjemplos = [];
}
// El rótulo visible ya dice «Pregunta»: el placeholder sólo pone el ejemplo,
// escrito como se escribiría la pregunta.
$crPlaceholder = $crAmbito === 'productos'
    ? '¿Cuánta leche queda? ¿Qué está por agotarse?'
    : '¿Cuántos capuchinos se vendieron ayer?';
?>
<section class="admin-consulta" data-consulta-rapida data-ambito="<?php echo htmlspecialchars($crAmbito, ENT_QUOTES, 'UTF-8'); ?>" aria-labelledby="<?php echo $crId; ?>-titulo">
    <h2 class="admin-visually-hidden" id="<?php echo $crId; ?>-titulo">Consulta rápida</h2>
    <form class="admin-consulta__barra" role="search" action="/admin/api/consultas" method="get" data-consulta-form>
        <input type="hidden" name="ambito" value="<?php echo htmlspecialchars($crAmbito, ENT_QUOTES, 'UTF-8'); ?>">
        <?php /* Signo de pregunta y no lupa: en Menú e Inventario la lupa ya es
                 la del filtro de la tabla, y dos lupas en la misma pantalla
                 piden adivinar cuál filtra y cuál responde. */ ?>
        <span class="admin-consulta__icono" aria-hidden="true"><?php echo admin_icon('ayuda', 18); ?></span>
        <label class="admin-consulta__rotulo" for="<?php echo $crId; ?>">Pregunta</label>
        <input
            id="<?php echo $crId; ?>"
            class="admin-consulta__input"
            type="search"
            name="q"
            maxlength="160"
            autocomplete="off"
            spellcheck="false"
            enterkeyhint="search"
            placeholder="<?php echo htmlspecialchars($crPlaceholder, ENT_QUOTES, 'UTF-8'); ?>"
            aria-describedby="<?php echo $crId; ?>-ayuda"
            data-consulta-input
        >
        <kbd class="admin-consulta__atajo" aria-hidden="true">/</kbd>
        <button type="submit" class="admin-visually-hidden">Consultar</button>
    </form>
    <p class="admin-visually-hidden" id="<?php echo $crId; ?>-ayuda">La respuesta aparece debajo mientras escribes. Tecla barra para enfocar, Escape para limpiar.</p>

    <?php if ($crEjemplos !== []) : ?>
        <div class="admin-consulta__ejemplos" data-consulta-ejemplos>
            <span class="admin-consulta__ejemplos-label">Prueba</span>
            <?php foreach ($crEjemplos as $ejemplo) : ?>
                <button type="button" class="admin-consulta__ejemplo" data-consulta-ejemplo="<?php echo htmlspecialchars($ejemplo, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ejemplo, ENT_QUOTES, 'UTF-8'); ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php /* La tarjeta NO es región viva: se repinta entera con cada pausa al
             teclear, y anunciada así el lector leería rubro, seis periodos,
             cifra, desglose y enlaces en cada letra. Lo que se anuncia es la
             frase de la respuesta, en su propio nodo. */ ?>
    <p class="admin-visually-hidden" data-consulta-anuncio aria-live="polite"></p>
    <div class="admin-consulta__resultado" data-consulta-resultado hidden></div>
</section>
<?php unset($crAmbito, $crId, $crEjemplos, $crPlaceholder, $ejemplo); ?>
