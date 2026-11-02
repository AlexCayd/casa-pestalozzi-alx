<?php
$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<section class="admin-menu admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro"><h1 class="admin-page__title">Asociar producto existente</h1>
            <p class="admin-page__subtitle">Elige una identidad por su identificador. Define precio, categoría y área propios; no se copian condiciones de otro negocio.</p></div>
        <a class="admin-btn admin-btn--secondary" href="<?= $esc($menuBase) ?>">Volver al menú</a>
    </header>
    <?php include __DIR__.'/../partials/alertas.php'; ?>
    <form method="GET" class="admin-filters">
        <label class="admin-field"><span class="admin-field__label">Buscar identidad</span><input type="search" name="q" value="<?= $esc($q) ?>" maxlength="100"></label>
        <button class="admin-btn admin-btn--secondary">Buscar</button>
    </form>
    <?php if (!$identidades) : ?><p class="admin-empty">No hay identidades en esta página. Puedes crear un producto nuevo desde Menú.</p><?php else : ?>
    <form method="POST" class="admin-card">
        <input type="hidden" name="menu_csrf" value="<?= $esc($menuCsrf) ?>">
        <fieldset class="admin-menu__fieldset" <?= $menuPuedeEditar ? '' : 'disabled' ?>>
        <div class="admin-form-grid">
            <label class="admin-field admin-form-grid__full"><span class="admin-field__label">Identidad comercial</span>
                <select name="producto_id" required><option value="">Selecciona una identidad</option>
                    <?php foreach ($identidades as $p) : ?><option value="<?= (int)$p['id'] ?>" <?= (int)($_POST['producto_id']??0)===(int)$p['id']?'selected':'' ?>>#<?= (int)$p['id'] ?> · <?= $esc($p['nombre']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="admin-field"><span class="admin-field__label">Categoría local</span><select name="categoria_id" required>
                <option value="">Selecciona una categoría</option><?php foreach ($categorias as $c) : ?><option value="<?= (int)$c->id ?>" <?= (int)($_POST['categoria_id']??0)===(int)$c->id?'selected':'' ?>><?= $esc($c->nombre) ?></option><?php endforeach; ?>
            </select></label>
            <label class="admin-field"><span class="admin-field__label">Área local</span><select name="area_id" required>
                <option value="">Selecciona un área</option><?php foreach ($areas as $a) : ?><option value="<?= (int)$a['id'] ?>" <?= (int)($_POST['area_id']??0)===(int)$a['id']?'selected':'' ?>><?= $esc($a['nombre']) ?></option><?php endforeach; ?>
            </select></label>
            <label class="admin-field"><span class="admin-field__label">Precio (MXN)</span><input name="precio" type="number" min="0" max="999999.99" step="0.01" required value="<?= $esc($_POST['precio']??'') ?>"></label>
            <label class="admin-field"><span><input type="checkbox" name="activo" value="1" <?= ($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||isset($_POST['activo'])?'checked':'' ?>> Oferta disponible</span></label>
        </div>
        <?php if ($menuPuedeEditar) : ?><button class="admin-btn admin-btn--primary">Asociar oferta</button><?php endif; ?>
        </fieldset>
    </form>
    <?php endif; ?>
    <nav class="admin-actions" aria-label="Páginas de identidades">
        <?php if ($pagina>1) : ?><a class="admin-btn admin-btn--secondary" href="?<?= $esc(http_build_query(['q'=>$q,'page'=>$pagina-1])) ?>">Anterior</a><?php endif; ?>
        <?php if (count($identidades)===30) : ?><a class="admin-btn admin-btn--secondary" href="?<?= $esc(http_build_query(['q'=>$q,'page'=>$pagina+1])) ?>">Siguiente</a><?php endif; ?>
    </nav>
</section>
