<?php
/**
 * «Nueva reservación» desde el POS.
 *
 * Diálogo nativo (<dialog>): trampa de foco, Escape y velo los pone el
 * navegador. Es para el día que se está mirando en el cajón —cambiar de día es
 * cambiar el cajón—, así que aquí no hay calendario. Las horas son sólo las que
 * el servidor ofrece para esa cantidad de personas; elegir una enseña en qué
 * mesas quedaría y a quién movería, antes de crear nada.
 *
 * El mesero no captura contacto: el campo no se pinta y el servidor descarta
 * el que llegue. pos-alta.js rellena horas y vista previa.
 */
$posAltaAdmin = ($_SESSION['rol'] ?? '') === 'admin';
?>
<dialog class="pos-alta" id="pos-alta" aria-labelledby="pos-alta-titulo" data-pos-alta>
  <form class="pos-alta__form" method="dialog" novalidate data-alta-form>
    <header class="pos-alta__cab">
      <div>
        <p class="pos-alta__rotulo" data-alta-fecha>Nueva reservación</p>
        <h2 class="pos-alta__titulo" id="pos-alta-titulo">Nueva reservación</h2>
      </div>
      <button type="button" class="pos-ficha__cerrar" data-alta-cerrar aria-label="Cerrar">
        <?php echo admin_icon('cerrar', 18); ?>
      </button>
    </header>

    <div class="pos-alta__cuerpo">
      <label class="pos-alta__campo">
        <span class="pos-alta__etiqueta">Nombre</span>
        <input type="text" name="nombre" maxlength="120" autocomplete="off" required data-alta-nombre>
      </label>

      <div class="pos-alta__campo pos-alta__campo--personas">
        <span class="pos-alta__etiqueta" id="pos-alta-personas-rotulo">Personas</span>
        <div class="pos-alta__stepper" role="group" aria-labelledby="pos-alta-personas-rotulo">
          <button type="button" class="pos-btn-redondo pos-btn-redondo--icono" data-alta-paso="-1" aria-label="Una persona menos"><?php echo admin_icon('menos', 18); ?></button>
          <output class="pos-alta__personas" data-alta-personas aria-live="polite">2</output>
          <button type="button" class="pos-btn-redondo pos-btn-redondo--icono" data-alta-paso="1" aria-label="Una persona más"><?php echo admin_icon('mas', 18); ?></button>
        </div>
        <p class="pos-alta__ayuda pos-alta__cupo" data-alta-cupo role="status" hidden></p>
      </div>

      <fieldset class="pos-alta__campo pos-alta__horas">
        <legend class="pos-alta__etiqueta">Hora</legend>
        <?php /* Sin role="radiogroup": el <fieldset> ya agrupa y nombra («Hora»),
                 y las horas son botones con aria-pressed (pos-alta.js). */ ?>
        <div class="pos-alta__rejilla" data-alta-horas></div>
        <p class="pos-alta__ayuda" data-alta-horas-estado role="status"></p>
      </fieldset>

      <section class="pos-alta__previa" data-alta-previa hidden aria-live="polite"></section>

      <?php if ($posAltaAdmin): ?>
        <fieldset class="pos-alta__campo">
          <legend class="pos-alta__etiqueta">Contacto</legend>
          <div class="admin-pills__group pos-alta__tipos">
            <label class="admin-pill"><input type="radio" name="contacto_tipo" value="telefono" checked><span class="admin-pill__body"><span class="admin-pill__title">Teléfono</span></span></label>
            <label class="admin-pill"><input type="radio" name="contacto_tipo" value="email"><span class="admin-pill__body"><span class="admin-pill__title">Correo</span></span></label>
            <label class="admin-pill"><input type="radio" name="contacto_tipo" value="ninguno"><span class="admin-pill__body"><span class="admin-pill__title">Sin contacto</span></span></label>
          </div>
          <input type="text" name="contacto" maxlength="160" autocomplete="off" data-alta-contacto aria-label="Teléfono o correo">
        </fieldset>
      <?php endif; ?>

      <label class="pos-alta__campo">
        <span class="pos-alta__etiqueta">Nota <span class="pos-alta__opcional">(opcional)</span></span>
        <textarea name="nota" rows="2" maxlength="500" data-alta-nota></textarea>
      </label>

      <p class="pos-alta__error" role="alert" data-alta-error hidden></p>
    </div>

    <footer class="pos-alta__acciones">
      <button type="button" class="mmodal-btn mmodal-btn--ghost" data-alta-cerrar>Cancelar</button>
      <button type="submit" class="mmodal-btn mmodal-btn--primary" data-alta-crear>Crear reservación</button>
    </footer>
  </form>
</dialog>
<?php unset($posAltaAdmin); ?>
