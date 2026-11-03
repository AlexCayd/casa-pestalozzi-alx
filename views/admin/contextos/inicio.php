<?php
/**
 * Inicio de cada ámbito (Restaurante, Panadería y Global): todos los módulos
 * autorizados, sin descripciones —icono y título— y con tres niveles de
 * lectura: el ámbito en grande, los grupos con su rótulo y las teselas, grandes
 * en el primer grupo y compactas en los demás.
 *
 * Las tarjetas y su orden vienen de InicioAdministrativo (perfil vigente); aquí
 * sólo se agrupan por `grupo`. Una sección sin grupos (las de Global) es un
 * bloque con su propio título.
 */
use Services\Security\InicioAdministrativo;

require_once __DIR__.'/../partials/_icons.php';
$esc=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');

$bloques=[];
$totalAccesos=0;
foreach ($seccionesInicio as $seccion) {
    $totalAccesos+=count($seccion['tarjetas']);
    $porGrupo=[];
    foreach ($seccion['tarjetas'] as $tarjeta) $porGrupo[$tarjeta['grupo']??''][]=$tarjeta;
    if ($porGrupo===[] || array_keys($porGrupo)===['']) {
        $bloques[]=['titulo'=>$seccion['titulo'],'tarjetas'=>$seccion['tarjetas'],'vacio'=>$seccion['vacio']];
        continue;
    }
    foreach (InicioAdministrativo::GRUPOS as $clave=>$nombre) {
        if (!empty($porGrupo[$clave])) $bloques[]=['titulo'=>$nombre,'tarjetas'=>$porGrupo[$clave],'vacio'=>''];
    }
}
$principal=null;
foreach ($bloques as $i=>$bloque) { if ($bloque['tarjetas']) { $principal=$i; break; } }
$cuenta=$inicioContexto==='global'
    ? $totalAccesos.' '.($totalAccesos===1?'acceso':'accesos')
    : $totalAccesos.' '.($totalAccesos===1?'módulo':'módulos');
?>
<section class="admin-inicio admin-page" aria-labelledby="inicio-titulo">
    <header class="admin-inicio__cabeza">
        <p class="admin-inicio__eyebrow">Inicio</p>
        <h1 class="admin-inicio__titulo" id="inicio-titulo"><?= $esc($nombreInicio) ?></h1>
        <p class="admin-inicio__cuenta admin-num"><?= $esc($cuenta) ?></p>
    </header>

    <?php if ($principal===null) : ?>
        <p class="admin-empty"><?= $esc($seccionesInicio[0]['vacio'] ?? 'No hay módulos disponibles con tus permisos actuales.') ?></p>
    <?php endif; ?>

    <?php foreach ($bloques as $i=>$bloque) : ?>
    <section class="admin-inicio__grupo<?= $i===$principal ? ' admin-inicio__grupo--principal' : '' ?>" aria-labelledby="inicio-grupo-<?= (int) $i ?>">
        <h2 class="admin-inicio__grupo-titulo" id="inicio-grupo-<?= (int) $i ?>">
            <span><?= $esc($bloque['titulo']) ?></span>
            <?php if ($bloque['tarjetas']) : ?><span class="admin-inicio__grupo-cuenta admin-num" aria-hidden="true"><?= count($bloque['tarjetas']) ?></span><?php endif; ?>
        </h2>
        <?php if (!$bloque['tarjetas']) : ?>
            <p class="admin-empty"><?= $esc($bloque['vacio']) ?></p>
        <?php else : ?>
            <?php $tarjetas=$bloque['tarjetas']; $grande=$i===$principal; include __DIR__.'/_modulos.php'; unset($tarjetas, $grande); ?>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <?php if ($inicioContexto==='panaderia') : ?><p class="admin-inicio__nota">POS, recetas administrativas y producción se habilitarán en una etapa posterior.</p><?php endif; ?>
</section>
