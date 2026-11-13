<?php
// Directorio de proveedores de un ámbito (arquitectura §17). En Global, la vista
// consolidada suma los de Restaurante y Panadería en sólo lectura: cada fila dice
// de quién es el proveedor, y sólo los propios se abren para editar.
require_once __DIR__.'/../partials/_icons.php';
use Services\Security\Contextos;
$esc=static fn($v): string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$fecha=static fn($v): string=>$v?date('d/m/Y',strtotime((string)$v)):'—';
$proveedores=$proveedores??[];$consolidado=!empty($consolidado);
$activos=count(array_filter($proveedores,static fn($p)=>(int)$p['activo']===1));
?>
<section class="admin-inventario admin-page almacen-proveedores">
  <header class="admin-page__header">
    <div class="admin-page__intro">
      <h2 class="admin-page__title">Proveedores</h2>
      <p class="admin-page__subtitle"><?php echo $consolidado?'Directorio de los tres ámbitos. Cada negocio administra los suyos; aquí se consultan.':'Quién surte los almacenes de '.$esc($nombreContexto).', qué se le pide y a qué costo.'; ?></p>
    </div>
    <div class="almacen-actions almacen-page-actions">
      <a class="admin-btn admin-btn--secondary" href="<?php echo $esc($baseAlmacenes); ?>"><?php echo admin_icon('flecha-izquierda'); ?> Almacenes</a>
      <?php if($puedeEditar&&!$consolidado): ?><a class="admin-btn admin-btn--primary" href="<?php echo $esc($base.'/nuevo'); ?>"><?php echo admin_icon('mas'); ?> Nuevo proveedor</a><?php endif; ?>
    </div>
  </header>
  <?php include __DIR__.'/../partials/alertas.php'; ?>
  <?php if(empty($sinListado)): ?>
  <?php if($ctx==='global'): ?>
  <?php /* Dos lecturas de Global, como enlaces: cambian la consulta (GET) y se pueden
     compartir; aria-current dice cuál se está viendo. */ ?>
  <nav class="proveedores-vistas" aria-label="Alcance del directorio">
    <a href="<?php echo $esc($base); ?>" <?php echo !$consolidado?'aria-current="page"':''; ?>>Proveedores de Global</a>
    <a href="<?php echo $esc($base.'?vista=consolidado'); ?>" <?php echo $consolidado?'aria-current="page"':''; ?>>Consolidado de los tres</a>
  </nav>
  <?php endif; ?>
  <section class="admin-panel almacen-list">
    <div class="almacen-section-head"><div><h3><?php echo $consolidado?'Todos los proveedores':'Directorio'; ?></h3><p><span class="admin-num"><?php echo count($proveedores); ?></span> proveedores · <span class="admin-num"><?php echo $activos; ?></span> activos. Una recepción es un día con entradas de ese proveedor.</p></div></div>
    <?php if(!$proveedores): ?>
    <div class="admin-empty"><strong>Todavía no hay proveedores</strong><p><?php echo $puedeEditar&&!$consolidado?'Da de alta el primero para ligarle entradas y ver qué se le pide.':'Cuando el ámbito registre proveedores aparecerán aquí.'; ?></p></div>
    <?php else: ?>
    <div class="admin-table-wrap" data-scrollable><table class="admin-table proveedores-tabla">
      <thead><tr><th>Proveedor</th><?php if($consolidado): ?><th>Ámbito</th><?php endif; ?><th>Contacto</th><th class="admin-table__num">Artículos</th><th class="admin-table__num">Recepciones</th><th>Última entrada</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach($proveedores as $p): ?>
        <tr>
          <td><a class="admin-table__cell-main" href="<?php echo $esc($base.'/ficha?'.http_build_query(['id'=>(int)$p['id']])); ?>"><?php echo $esc($p['nombre']); ?></a></td>
          <?php if($consolidado): ?><td><?php echo $esc(Contextos::NOMBRES[$p['slug']]??$p['slug']); ?><?php if(!$p['editable']): ?><span class="admin-table__cell-sub">Sólo consulta</span><?php endif; ?></td><?php endif; ?>
          <td><?php echo $esc($p['contacto']??'—'); ?><?php if($p['telefono']||$p['correo']): ?><span class="admin-table__cell-sub"><?php echo $esc(implode(' · ',array_filter([$p['telefono'],$p['correo']]))); ?></span><?php endif; ?></td>
          <td class="admin-table__num"><span class="admin-num"><?php echo (int)$p['articulos']; ?></span></td>
          <td class="admin-table__num"><span class="admin-num"><?php echo (int)$p['recepciones']; ?></span></td>
          <td><?php echo $esc($fecha($p['ultima_entrada'])); ?></td>
          <td><span class="admin-badge admin-badge--<?php echo (int)$p['activo']===1?'success':'neutral'; ?>"><?php echo (int)$p['activo']===1?'Activo':'Inactivo'; ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</section>
