<?php
/**
 * Ámbito de trabajo (Global · Restaurante · Panadería), en el header del panel.
 *
 * Es el estado que decide qué se está mirando, así que va donde empieza la
 * lectura: primero del header, junto al botón del menú, y una sola vez (antes
 * salía en el sidebar como selector y en el header como texto). Con más de un
 * ámbito autorizado es un botón que abre el menú en la capa superior
 * (popover; teclado en src/js/admin/core/contextos.js); con uno solo es una
 * etiqueta fija, sin nada que elegir.
 *
 * Los enlaces vienen de los ámbitos autorizados (Auth::contextos()) y llevan a
 * /admin/contexto, que vuelve a autorizar: elegir aquí no concede nada.
 */
use Services\Security\Contextos;
require_once __DIR__.'/_icons.php';
$contextoIconos=['global'=>'users','restaurante'=>'products','panaderia'=>'inventario'];
$contextoEsc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$contextosDisponibles=is_array($contextosDisponibles??null)&&$contextosDisponibles!==[]?$contextosDisponibles:[$contextoActivo];
$contextoCambiable=count($contextosDisponibles)>1;
$contextoNombre=Contextos::NOMBRES[$contextoActivo];
?>
<div class="admin-context-switch" data-context-scope="<?php echo $contextoEsc($contextoActivo); ?>">
  <?php if ($contextoCambiable): ?>
  <button type="button" class="admin-context-switch__trigger" popovertarget="admin-context-menu" aria-expanded="false" aria-controls="admin-context-menu" aria-label="Ámbito de trabajo: <?php echo $contextoEsc($contextoNombre); ?>. Cambiar" data-context-trigger>
    <span class="admin-context-switch__icon"><?php echo admin_icon($contextoIconos[$contextoActivo]); ?></span>
    <span class="admin-context-switch__texto">
      <span class="admin-context-switch__rotulo" aria-hidden="true">Ámbito</span>
      <span class="admin-context-switch__name"><?php echo $contextoEsc($contextoNombre); ?></span>
    </span>
    <span class="admin-context-switch__chevron"><?php echo admin_icon('chevron-abajo',16); ?></span>
  </button>
  <nav id="admin-context-menu" class="admin-context-menu" popover aria-label="Elegir ámbito de trabajo">
    <p>Ámbito de trabajo</p>
    <?php foreach($contextosDisponibles as $opcion): ?><a href="/admin/contexto?contexto=<?php echo $contextoEsc($opcion); ?>" <?php echo $contextoActivo===$opcion?'aria-current="page"':''; ?>><span class="admin-context-menu__icon"><?php echo admin_icon($contextoIconos[$opcion]); ?></span><span><?php echo $contextoEsc(Contextos::NOMBRES[$opcion]); ?></span><?php if($contextoActivo===$opcion)echo admin_icon('check',16); ?></a><?php endforeach; ?>
  </nav>
  <noscript><form action="/admin/contexto" method="get" class="admin-context-switch__sinjs"><label for="admin-contexto">Ámbito de trabajo</label><select data-native data-context-select id="admin-contexto" name="contexto"><?php foreach($contextosDisponibles as $opcion): ?><option value="<?php echo $contextoEsc($opcion); ?>" <?php echo $contextoActivo===$opcion?'selected':''; ?>><?php echo $contextoEsc(Contextos::NOMBRES[$opcion]); ?></option><?php endforeach; ?></select><button type="submit">Cambiar</button></form></noscript>
  <?php else: ?>
  <p class="admin-context-switch__fijo">
    <span class="admin-context-switch__icon"><?php echo admin_icon($contextoIconos[$contextoActivo]); ?></span>
    <span class="admin-context-switch__texto">
      <span class="admin-context-switch__rotulo">Ámbito</span>
      <span class="admin-context-switch__name"><?php echo $contextoEsc($contextoNombre); ?></span>
    </span>
  </p>
  <?php endif; ?>
</div>
<?php unset($contextoCambiable, $contextoNombre); ?>
