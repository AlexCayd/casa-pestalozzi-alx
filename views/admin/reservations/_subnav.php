<?php
/**
 * Sub-navegación del módulo de reservaciones: Agenda · Grupos grandes · Casos.
 *
 * Son tres lecturas del mismo dato con tres preguntas distintas —quién viene
 * y qué pasó (agenda), dónde cabe un grupo (grupos grandes), qué tengo que
 * resolver (casos)—, así que comparten entrada en el sidebar y se separan aquí.
 *
 * Va ARRIBA del encabezado de cada pantalla, no debajo: los tres encabezados
 * tienen subtítulos y acciones de alto distinto, y debajo de ellos las
 * pestañas cambiaban de altura al saltar de una pantalla a otra. Arriba están
 * siempre en el mismo sitio, que es lo que pide una navegación.
 *
 * Son enlaces que navegan, no pestañas de un panel: por eso <nav> con
 * aria-current y no role="tablist" (mismo criterio que las cartas de Menú).
 *
 * Parámetros:
 * - $subnavActiva: 'historico' | 'grupos' | 'casos'.
 *
 * Cierra con unset() de sus parámetros, como los demás parciales del panel.
 */
$subnavActiva = (string)($subnavActiva ?? 'historico');
$subnavDestinos = [
    // «Agenda» y no «Histórico»: abre en los próximos 30 días, y quien
    // buscaba las reservas de esta noche no las iba a buscar en un histórico.
    // La clave interna sigue siendo 'historico' (la usan las vistas y su
    // prueba estática).
    'historico' => ['url' => '/admin/reservaciones', 'etiqueta' => 'Agenda'],
    'grupos' => ['url' => '/admin/reservaciones/grupos-grandes', 'etiqueta' => 'Grupos grandes'],
    'casos' => ['url' => '/admin/reservaciones/casos', 'etiqueta' => 'Casos'],
];
?>
<nav class="admin-tabs admin-tabs--links reservations-subnav" aria-label="Secciones de reservaciones">
    <?php foreach ($subnavDestinos as $subnavClave => $subnavDestino) : ?>
        <?php $subnavEsActiva = $subnavClave === $subnavActiva; ?>
        <span class="admin-tabs__tab">
            <a href="<?php echo htmlspecialchars($subnavDestino['url'], ENT_QUOTES, 'UTF-8'); ?>"
               class="<?php echo $subnavEsActiva ? 'is-active' : ''; ?>"
               <?php echo $subnavEsActiva ? 'aria-current="page"' : ''; ?>><span><?php echo htmlspecialchars($subnavDestino['etiqueta'], ENT_QUOTES, 'UTF-8'); ?></span></a>
        </span>
    <?php endforeach; ?>
</nav>
<?php
unset($subnavActiva, $subnavDestinos, $subnavClave, $subnavDestino, $subnavEsActiva);
?>
