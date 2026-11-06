<?php $esc=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'',ENT_QUOTES,'UTF-8');$id=(int)$articulo['id']; ?>
<section class="admin-recetas admin-page">
    <header class="admin-page__header"><div><h1 class="admin-page__title"><?= $esc($articulo['nombre']) ?> · Versiones publicadas</h1><p class="admin-page__subtitle">Consulta de la composición guardada. Cada preparación conserva la versión que eligió al publicarse.</p></div>
    <a class="admin-btn admin-btn--secondary" href="<?= $esc($baseArticulos.'/editar?'.http_build_query(array_filter(['id'=>$id,'volver'=>$volverContextual]))) ?>">Volver a la ficha actual</a></header>
    <?php if($versiones): ?><form method="GET" class="admin-form-grid">
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= (int)$versionConsultada['id'] ?>">
        <?php if($volverContextual): ?><input type="hidden" name="volver" value="<?= $esc($volverContextual) ?>"><?php endif; ?>
        <label class="admin-field">Comparar con otra versión<select name="comparar" required><option value="">Selecciona una versión</option><?php foreach($versiones as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)($versionComparada['id']??0)===(int)$v['id']?'selected':'' ?>>Versión <?= (int)$v['version'] ?> · <?= $esc($v['rendimiento'].' '.$v['unidad']) ?></option><?php endforeach; ?></select></label>
        <div class="admin-actions"><button class="admin-btn admin-btn--secondary">Comparar versiones</button></div>
    </form><?php endif; ?>
    <?php $versionVisible=$versionConsultada;include __DIR__.'/_composicion-publicada.php'; ?>
    <?php if($versionComparada): $versionVisible=$versionComparada;include __DIR__.'/_composicion-publicada.php'; endif; ?>
</section>
