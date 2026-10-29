<?php
use Services\Security\Contextos;
require_once __DIR__.'/_icons.php';
$contextoIconos=['global'=>'users','restaurante'=>'products','panaderia'=>'inventario'];
$contextoEsc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<div class="admin-context-switch" data-context-scope="<?php echo $contextoEsc($contextoActivo); ?>">
  <button type="button" class="admin-context-switch__trigger" popovertarget="admin-context-menu" aria-expanded="false" aria-controls="admin-context-menu" aria-label="Cambiar contexto. Actual: <?php echo $contextoEsc(Contextos::NOMBRES[$contextoActivo]); ?>" data-context-trigger>
    <span class="admin-context-switch__icon"><?php echo admin_icon($contextoIconos[$contextoActivo]); ?></span>
    <span class="admin-context-switch__name"><?php echo $contextoEsc(Contextos::NOMBRES[$contextoActivo]); ?></span>
    <span class="admin-context-switch__chevron"><?php echo admin_icon('chevron-abajo',16); ?></span>
  </button>
  <nav id="admin-context-menu" class="admin-context-menu" popover aria-label="Elegir contexto de trabajo">
    <p>Contexto de trabajo</p>
    <?php foreach($contextosDisponibles as $opcion): ?><a href="/admin/contexto?contexto=<?php echo $contextoEsc($opcion); ?>" <?php echo $contextoActivo===$opcion?'aria-current="page"':''; ?>><?php echo admin_icon($contextoIconos[$opcion]); ?><span><?php echo $contextoEsc(Contextos::NOMBRES[$opcion]); ?></span><?php if($contextoActivo===$opcion)echo admin_icon('check',16); ?></a><?php endforeach; ?>
  </nav>
  <noscript><form action="/admin/contexto" method="get"><label for="admin-contexto">Contexto de trabajo</label><select data-native data-context-select id="admin-contexto" name="contexto"><?php foreach($contextosDisponibles as $opcion): ?><option value="<?php echo $contextoEsc($opcion); ?>" <?php echo $contextoActivo===$opcion?'selected':''; ?>><?php echo $contextoEsc(Contextos::NOMBRES[$opcion]); ?></option><?php endforeach; ?></select><button type="submit">Cambiar contexto</button></form></noscript>
</div>