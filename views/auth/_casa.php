<?php
  /* Columna de la casa del acceso: la comparten el login y el segundo paso
     (views/auth/dos-factores.php). */
?>
    <?php /* La casa. Decorativo para el lector (aria-hidden): el nombre ya lo
             dice el <title> y los dos titulares dicen qué hacer aquí. */ ?>
    <div class="login-aside" aria-hidden="true">
      <span class="login-aside__rotulo">Acceso del personal</span>

      <div class="login-aside__centro">
        <?php /* ═══ COMPONENTE REUTILIZABLE → views/templates/header-casa-pestalozzi.php
                 Sin enlace (el panel es aria-hidden: un <a> quedaría oculto al
                 lector pero en el recorrido de tabulación) y en <span>: un <p>
                 se llevaría el color atenuado de `.admin-body p`. */ ?>
        <?php
        $hcpEtiqueta = 'div';
        $hcpNivel = 'span';
        $hcpHref = '';
        $hcpDosLineas = true;
        $hcpClase = 'login-marca login-marca--aside';
        $hcpAtributos = ['data-login-marca' => ''];
        include __DIR__ . '/../templates/header-casa-pestalozzi.php';
        ?>
        <span class="login-aside__filete"></span>
        <p class="login-aside__frase">Una mesa italiana con alma mexicana.</p>
      </div>

      <span class="login-aside__rotulo">Del Valle · México</span>
    </div>
