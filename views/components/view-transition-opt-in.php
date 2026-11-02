<?php
/**
 * El opt-in de las View Transitions de documento cruzado, EN LÍNEA.
 *
 * Va en el <head> de toda pantalla que participa en una (panel, login, POS y
 * tableros), antes de cualquier hoja externa. La misma regla vive en
 * _finishes.scss (bloque 4c) y no basta: Chrome decide si el documento NUEVO
 * acepta la transición en cuanto calcula su primer estilo, y si en ese momento
 * la hoja externa todavía no ha aplicado, la da por rechazada («ViewTransition
 * opt-in disabled») y la anula. Medido con dos páginas mínimas: con el opt-in
 * sólo en una hoja externa la transición se perdía una de cada dos veces; en
 * línea, ninguna. Era la razón de que el primer tiempo de la llegada —el
 * nombre viajando del login al centro— no se viera nunca, y de que el login,
 * el POS y los tableros entraran siempre en seco.
 *
 * Movimiento reducido, apagada: es lo mismo que dice _finishes.scss.
 */
?>
<style>@view-transition{navigation:auto}@media (prefers-reduced-motion:reduce){@view-transition{navigation:none}}</style>
