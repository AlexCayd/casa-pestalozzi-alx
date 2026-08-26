<?php
/**
 * Insegna: el rótulo que corre.
 *
 * Banda a sangre entre secciones. Se incluye varias veces en la landing, así
 * que recibe su contenido por variables locales y las libera al final —los
 * parciales incluidos más de una vez conservan lo que dejó el include
 * anterior (CLAUDE.md, "Componentes").
 *
 *   $insegnaTono   tono de la banda ('cafe' | 'verde'). Por defecto 'cafe'.
 *   $insegnaLemas  lista de [italiano, traducción].
 *
 * El grupo se imprime dos veces: la animación desplaza la pista media anchura
 * exacta, y con una sola copia el bucle mostraría el hueco al reiniciar.
 */

$insegnaTono = $insegnaTono ?? 'cafe';
$insegnaLemas = $insegnaLemas ?? [
  ['Cucina Italiana', 'cocina italiana'],
  ['Forno a Legna', 'horno de leña'],
  ['Pasta Fatta in Casa', 'pasta de la casa'],
  ['Pane di Casa', 'pan del día'],
];
?>
<div class="insegna" data-tono="<?php echo htmlspecialchars($insegnaTono, ENT_QUOTES, 'UTF-8'); ?>" data-insegna aria-hidden="true">
  <div class="insegna__pista" data-insegna-pista>
    <?php for ($copia = 0; $copia < 2; $copia++) : ?>
      <div class="insegna__grupo">
        <?php foreach ($insegnaLemas as $lema) : ?>
          <span class="insegna__it"><?php echo htmlspecialchars($lema[0], ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="insegna__es"><?php echo htmlspecialchars($lema[1], ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="insegna__rombo"></span>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
<?php unset($insegnaTono, $insegnaLemas, $copia, $lema); ?>
