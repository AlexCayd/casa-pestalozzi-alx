<?php
/**
 * Aviso de privacidad.
 *
 * Va como diálogo y no como página aparte para no sacar al visitante del flujo
 * de reservación justo cuando está entregando sus datos: se abre desde el pie
 * y desde el propio formulario, se lee y se cierra donde estaba.
 *
 * El contenido describe lo que el sistema recoge de verdad (ver
 * ReservacionService y el formulario de _reserva.php); si cambia lo que se
 * pide, hay que actualizarlo aquí.
 */
$contactoAviso = $contactoReservas ?? [];
$telefonoVisible = (string) ($contactoAviso['telefono_visible'] ?? '56 1481 8297');
$telefonoTel = (string) ($contactoAviso['telefono_tel'] ?? '+525614818297');
?>
<div class="privacidad" data-privacidad hidden>
  <button class="privacidad__backdrop" type="button" tabindex="-1" aria-hidden="true" data-privacidad-close></button>
  <div
    class="privacidad__panel"
    role="dialog"
    aria-modal="true"
    aria-labelledby="privacidad-titulo"
    tabindex="-1"
    data-privacidad-panel
  >
    <header class="privacidad__head">
      <span class="eyebrow no-rule">Tus datos</span>
      <h2 id="privacidad-titulo">Aviso de privacidad</h2>
      <button class="privacidad__close" type="button" aria-label="Cerrar" data-privacidad-close>&times;</button>
    </header>

    <div class="privacidad__body" data-lenis-prevent>
      <p class="privacidad__lead">
        Casa Pestalozzi, con domicilio en José Enrique Pestalozzi 1250, Del Valle,
        Ciudad de México, es responsable del tratamiento de los datos personales
        que nos compartes al reservar una mesa.
      </p>

      <h3>Qué datos pedimos</h3>
      <ul>
        <li><strong>Tu nombre</strong>, para recibirte y localizar tu mesa al llegar.</li>
        <li><strong>Un medio de contacto</strong> —correo electrónico o teléfono, el que elijas—,
            para enviarte el código de confirmación y avisarte de cualquier cambio.</li>
        <li><strong>Los detalles de la visita</strong>: fecha, hora y número de comensales.</li>
        <li><strong>Las indicaciones que escribas</strong>, si nos cuentas de una alergia,
            una celebración o una necesidad de accesibilidad. Este campo es opcional.</li>
      </ul>

      <h3>Para qué los usamos</h3>
      <p>
        Únicamente para gestionar tu reservación: confirmarla, asignarte mesa,
        contactarte si surge un imprevisto y tener presente lo que nos pediste
        considerar. Si después de tu visita compartes una reseña, la usamos para
        mejorar el servicio.
      </p>
      <p>
        <strong>No vendemos ni compartimos tus datos con terceros</strong> con fines
        comerciales, y no te enviaremos publicidad que no hayas pedido.
      </p>

      <h3>Cuánto tiempo los conservamos</h3>
      <p>
        El tiempo necesario para atender tu reservación y llevar el registro
        operativo del restaurante. Puedes pedirnos que los eliminemos cuando quieras.
      </p>

      <h3>Tus derechos</h3>
      <p>
        Puedes solicitar el acceso, la rectificación, la cancelación de tus datos
        o la oposición a su uso (derechos ARCO), así como revocar el consentimiento
        que nos diste. Basta con que nos lo digas:
      </p>
      <p class="privacidad__contacto">
        <a href="tel:<?php echo s($telefonoTel); ?>">Llamar al <?php echo s($telefonoVisible); ?></a>
        <span aria-hidden="true">·</span>
        <span>o pregúntanos en el restaurante</span>
      </p>

      <p class="privacidad__nota">
        Si modificamos este aviso, publicaremos la versión actualizada en esta
        misma página.
      </p>
    </div>

    <footer class="privacidad__foot">
      <button type="button" class="btn-line btn-line--secondary" data-privacidad-close>
        <span>Entendido</span>
      </button>
    </footer>
  </div>
</div>
