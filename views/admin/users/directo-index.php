<?php
$usuarios = is_array($usuarios ?? null) ? $usuarios : [];
$filtros = is_array($filtros ?? null) ? $filtros : [];
$baseUrl = (string) ($baseUrl ?? '/admin/usuarios');
$ctx = (string) ($contextoUsuarios ?? 'restaurante');
$nombreCtx = (string) ($contextoNombre ?? 'Restaurante');
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$valor = static function ($usuario, string $key, $default = '') {
    return is_array($usuario) ? ($usuario[$key] ?? $default) : (is_object($usuario) ? ($usuario->$key ?? $default) : $default);
};
$nombresAmbitos = \Services\Security\Contextos::NOMBRES;
$alertas = $alertas ?? [];
$puedeEditar = !empty($puedeEditar);
?>
<section class="admin-users admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Administración · <?php echo $esc($nombreCtx); ?></span>
            <h2 class="admin-page__title">Usuarios</h2>
            <p class="admin-page__subtitle">Consulta y administra los accesos asignados a este ámbito.</p>
        </div>
        <?php if ($puedeEditar) : ?><div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary admin-create-button" href="<?php echo $esc($baseUrl); ?>/create">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                <span>Nuevo usuario</span>
            </a>
        </div><?php endif; ?>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <form class="admin-filters" method="GET" action="<?php echo $esc($baseUrl); ?>" aria-label="Filtros de usuarios">
        <div class="admin-filters__search">
            <label for="direct-users-q">Buscar</label>
            <input id="direct-users-q" type="search" name="q" value="<?php echo $esc($filtros['q'] ?? ''); ?>" placeholder="Nombre o usuario">
        </div>
        <?php if ($ctx === 'global') : ?>
            <div class="admin-filters__group">
                <label for="direct-users-context">Ámbito</label>
                <select id="direct-users-context" name="ambito">
                    <option value="">Todos</option>
                    <?php foreach ($nombresAmbitos as $slug => $label) : ?>
                        <option value="<?php echo $esc($slug); ?>" <?php echo ($filtros['ambito'] ?? '') === $slug ? 'selected' : ''; ?>><?php echo $esc($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="admin-filters__group">
            <label for="direct-users-status">Estado</label>
            <select id="direct-users-status" name="status">
                <option value="">Todos</option>
                <option value="active" <?php echo ($filtros['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Activos</option>
                <option value="inactive" <?php echo ($filtros['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactivos</option>
            </select>
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary">Buscar</button>
            <?php if (!empty($filtrosActivos)) : ?>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="<?php echo $esc($baseUrl); ?>">Limpiar filtros</a>
            <?php endif; ?>
        </div>
    </form>

    <section class="admin-menu__panel admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div><h3>Equipo</h3><p><?php echo count($usuarios); ?> usuario(s) en los resultados.</p></div>
        </div>
        <?php if ($usuarios === []) : ?>
            <p class="admin-menu__empty admin-empty">
                <?php echo !empty($filtrosActivos) ? 'No se encontraron usuarios con estos filtros.' : 'Todavía no hay usuarios asignados a este ámbito.'; ?>
            </p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table" data-sortable>
                    <thead><tr><th data-sort-type="text">Usuario</th><th data-sort-type="text">Ámbitos</th><th data-sort-type="text">Acceso</th><th data-sort-type="number">Estado</th><th data-sort-disabled>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($usuarios as $usuario) :
                        $id = (int) $valor($usuario, 'id', 0);
                        $username = (string) $valor($usuario, 'username');
                        $nombre = (string) $valor($usuario, 'nombre', $username);
                        $activo = (bool) $valor($usuario, 'activo', false);
                        $tipo = (string) $valor($usuario, 'tipo_acceso', $valor($usuario, 'tipoAcceso', 'ninguno'));
                        $ambitos = is_array($valor($usuario, 'ambitos', [])) ? $valor($usuario, 'ambitos', []) : [];
                        $etiquetas = [];
                        foreach ($ambitos as $slug => $membresia) {
                            if (is_array($membresia) && (!array_key_exists('activo', $membresia) || !empty($membresia['activo'])) && isset($nombresAmbitos[$slug])) $etiquetas[] = $nombresAmbitos[$slug];
                        }
                        $ini = mb_strtoupper(mb_substr(trim($nombre) !== '' ? trim($nombre) : $username, 0, 1));
                        $esSuper = (bool) $valor($usuario, 'es_superadmin', $valor($usuario, 'super', false));
                        $gestionable = (bool) $valor($usuario, 'gestionable', true);
                        $compartida = (bool) $valor($usuario, 'compartida', $valor($usuario, 'es_compartida', false)) || count($etiquetas) > 1;
                        $revocaAmbito = $ctx !== 'global' && $compartida;
                    ?>
                        <tr>
                            <td><div class="admin-user-cell"><span class="admin-user-avatar" aria-hidden="true"><?php echo $esc($ini); ?></span><span class="admin-user-identity"><span class="admin-user-identity__name"><?php echo $esc($nombre); ?></span><span class="admin-user-identity__handle">@<?php echo $esc($username); ?><?php echo $esSuper ? ' · Superadministrador' : ''; ?></span></span></div></td>
                            <td><?php echo $esc($etiquetas === [] ? 'Sin ámbitos activos' : implode(' · ', $etiquetas)); ?></td>
                            <td><span class="admin-tag admin-role admin-role--<?php echo $tipo === 'contrasena' ? 'admin' : ($tipo === 'nip' ? 'waiter' : 'cook'); ?>"><?php echo $tipo === 'contrasena' ? 'Contraseña' : ($tipo === 'nip' ? 'NIP' : 'Sin acceso'); ?></span></td>
                            <td data-sort-value="<?php echo $activo ? '1' : '0'; ?>">
                                <?php if ($puedeEditar && $gestionable && !$esSuper && (!$compartida || $ctx === 'global')) : ?>
                                    <form method="POST" action="<?php echo $esc($baseUrl); ?>/<?php echo $activo ? 'deactivate' : 'activate'; ?>">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="admin_csrf" value="<?php echo $esc($adminCsrfToken ?? ''); ?>">
                                        <button type="submit" class="admin-status-switch <?php echo $activo ? 'is-on' : 'is-off'; ?>" aria-label="<?php echo $activo ? 'Desactivar usuario ' : 'Activar usuario '; ?><?php echo $esc($username); ?>"><span class="admin-status-switch__track" aria-hidden="true"><span class="admin-status-switch__thumb"></span></span><span class="admin-sr-only"><?php echo $activo ? 'Activo' : 'Inactivo'; ?></span></button>
                                    </form>
                                <?php else : ?><span><?php echo $activo ? 'Activo' : 'Inactivo'; ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($gestionable) : ?>
                                    <div class="admin-table-actions"><a class="admin-icon-button admin-icon-button--edit" href="<?php echo $esc($baseUrl); ?>/edit?id=<?php echo $id; ?>" title="Editar" aria-label="Editar usuario <?php echo $esc($username); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></a>
                                        <?php if ($puedeEditar && !$esSuper) : ?><form method="POST" action="<?php echo $esc($baseUrl); ?>/delete" data-user-delete-form><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="admin_csrf" value="<?php echo $esc($adminCsrfToken ?? ''); ?>"><button type="button" class="admin-icon-button admin-icon-button--danger admin-icon-button--danger-text" title="<?php echo $revocaAmbito ? 'Revocar acceso a ' . $esc($nombreCtx) : 'Eliminar usuario'; ?>" aria-label="<?php echo $revocaAmbito ? 'Revocar acceso a ' . $esc($nombreCtx) . ' para ' : 'Eliminar usuario '; ?><?php echo $esc($username); ?>" data-user-delete data-user-name="<?php echo $esc($nombre); ?>" data-delete-scope="<?php echo $revocaAmbito ? '1' : '0'; ?>" data-scope-name="<?php echo $esc($nombreCtx); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg></button></form><?php endif; ?>
                                    </div>
                                <?php else : ?><span class="admin-table__cell-sub">Sin facultad de gestión</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>

<div class="admin-modal" data-user-delete-modal hidden>
    <div class="admin-modal__backdrop" data-modal-close></div>
    <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="direct-delete-title" aria-describedby="direct-delete-desc">
        <h3 class="admin-modal__title" id="direct-delete-title" data-modal-title>Eliminar usuario</h3>
        <p class="admin-modal__text" id="direct-delete-desc" data-modal-description>Esta acción eliminará la identidad y todos sus accesos. Confirma el nombre de la persona: <strong data-modal-username></strong></p>
        <input type="text" class="admin-modal__input" data-modal-input autocomplete="off" aria-label="Nombre de la persona para confirmar">
        <div class="admin-modal__actions"><button type="button" class="admin-btn admin-btn--secondary" data-modal-close>Cancelar</button><button type="button" class="admin-btn admin-btn--danger-solid" data-modal-confirm disabled data-confirm-label>Eliminar usuario</button></div>
    </div>
</div>
