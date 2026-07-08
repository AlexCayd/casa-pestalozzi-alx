<?php
$usuarios = isset($usuarios) && is_iterable($usuarios) ? $usuarios : [];
$totalAdminsActivos = (int) ($totalAdminsActivos ?? 0);
$filtros = is_array($filtros ?? null) ? $filtros : ['q' => '', 'role' => '', 'status' => ''];
$filtrosActivos = (bool) ($filtrosActivos ?? false);
$roles = isset($roles) && is_iterable($roles) ? $roles : [];
$roleLabels = is_array($roleLabels ?? null) ? $roleLabels : [];

$valorUsuario = static function ($usuario, string $campo, $default = '') {
    if (is_array($usuario)) {
        return $usuario[$campo] ?? $default;
    }
    if (is_object($usuario)) {
        return $usuario->$campo ?? $default;
    }
    return $default;
};

/** Iniciales (máx. 2) a partir del nombre o el usuario. */
$iniciales = static function (string $nombre, string $username): string {
    $base = trim($nombre) !== '' ? trim($nombre) : $username;
    $partes = preg_split('/\s+/', $base) ?: [];
    $ini = '';
    foreach ($partes as $p) {
        if ($p !== '') {
            $ini .= mb_substr($p, 0, 1);
        }
        if (mb_strlen($ini) >= 2) {
            break;
        }
    }
    return mb_strtoupper($ini !== '' ? $ini : 'U');
};
?>

<section class="admin-users admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Administración</span>
            <h2 class="admin-page__title">Usuarios</h2>
            <p class="admin-page__subtitle">Gestiona los accesos, roles y estado de las personas con acceso al panel.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary admin-create-button" href="/admin/usuarios/create" title="Nuevo usuario" aria-label="Nuevo usuario">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
                </svg>
                <span>Nuevo usuario</span>
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <form class="admin-filters" method="GET" action="/admin/usuarios">
        <div class="admin-filters__search">
            <label for="users-q">Buscar</label>
            <input
                id="users-q"
                type="search"
                name="q"
                value="<?php echo htmlspecialchars((string) ($filtros['q'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Nombre o usuario"
            >
        </div>
        <div class="admin-filters__group">
            <label for="users-role">Rol</label>
            <select id="users-role" name="role">
                <option value="">Todos</option>
                <?php foreach ($roles as $role) : ?>
                    <?php $role = (string) $role; ?>
                    <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($filtros['role'] ?? '') === $role ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($roleLabels[$role] ?? ucfirst($role), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="admin-filters__group">
            <label for="users-status">Estado</label>
            <select id="users-status" name="status">
                <option value="">Todos</option>
                <option value="active" <?php echo ($filtros['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Activos</option>
                <option value="inactive" <?php echo ($filtros['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactivos</option>
            </select>
        </div>
        <div class="admin-filters__actions">
            <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary">Buscar</button>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/usuarios">Limpiar</a>
        </div>
    </form>

    <section class="admin-menu__panel admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Equipo</h3>
                <p><?php echo count($usuarios); ?> usuario(s) · <?php echo $totalAdminsActivos; ?> administrador(es) activo(s).</p>
            </div>
        </div>

        <?php if (empty($usuarios)) : ?>
            <p class="admin-menu__empty admin-empty">
                <?php echo $filtrosActivos ? 'No se encontraron resultados con los filtros aplicados.' : 'No hay usuarios registrados.'; ?>
            </p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-menu__table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Alta</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario) : ?>
                            <?php
                            $id = (int) $valorUsuario($usuario, 'id', 0);
                            $username = (string) $valorUsuario($usuario, 'username');
                            $nombre = (string) $valorUsuario($usuario, 'nombre');
                            $rol = (string) $valorUsuario($usuario, 'rol');
                            $activo = (int) $valorUsuario($usuario, 'activo', 0) === 1;
                            $createdAt = (string) $valorUsuario($usuario, 'created_at', '');
                            $createdLabel = $createdAt !== '' ? date('d/m/Y', strtotime($createdAt)) : 'Sin fecha';
                            $esUnicoAdminActivo = $rol === 'admin' && $activo && $totalAdminsActivos <= 1;
                            $mensajeAdminActivo = 'Debe existir un usuario administrador activo siempre.';
                            $rolClase = in_array($rol, ['admin', 'waiter', 'cashier', 'observer'], true) ? $rol : 'observer';
                            ?>
                            <tr>
                                <td>
                                    <div class="admin-user-cell">
                                        <span class="admin-user-avatar" aria-hidden="true"><?php echo htmlspecialchars($iniciales($nombre, $username), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="admin-user-identity">
                                            <span class="admin-user-identity__name"><?php echo htmlspecialchars($nombre !== '' ? $nombre : $username, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="admin-user-identity__handle">@<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="admin-role admin-role--<?php echo $rolClase; ?>">
                                        <?php echo htmlspecialchars($roleLabels[$rol] ?? ucfirst($rol), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="/admin/usuarios/<?php echo $activo ? 'deactivate' : 'activate'; ?>">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <button
                                            type="submit"
                                            class="admin-status-switch <?php echo $activo ? 'is-on' : 'is-off'; ?>"
                                            title="<?php echo $esUnicoAdminActivo ? $mensajeAdminActivo : ($activo ? 'Desactivar usuario' : 'Activar usuario'); ?>"
                                            aria-label="<?php echo $activo ? 'Desactivar usuario ' : 'Activar usuario '; ?><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $esUnicoAdminActivo ? 'disabled' : ''; ?>
                                        >
                                            <span class="admin-status-switch__track" aria-hidden="true">
                                                <span class="admin-status-switch__thumb"></span>
                                            </span>
                                            <span class="admin-sr-only"><?php echo $activo ? 'Activo' : 'Inactivo'; ?></span>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <span class="admin-table__cell-sub"><?php echo htmlspecialchars($createdLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <?php $nombreCompleto = $nombre !== '' ? $nombre : $username; ?>
                                <td>
                                    <div class="admin-table-actions">
                                        <a
                                            class="admin-icon-button admin-icon-button--edit"
                                            href="/admin/usuarios/edit?id=<?php echo $id; ?>"
                                            title="Editar"
                                            aria-label="Editar usuario <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                                        >
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M12 20h9"/>
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                            </svg>
                                        </a>
                                        <form
                                            method="POST"
                                            action="/admin/usuarios/delete"
                                            class="admin-delete-form"
                                            data-user-delete-form
                                        >
                                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                                            <button
                                                type="button"
                                                class="admin-icon-button admin-icon-button--danger admin-icon-button--danger-text"
                                                title="<?php echo $esUnicoAdminActivo ? $mensajeAdminActivo : 'Eliminar'; ?>"
                                                aria-label="Eliminar usuario <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-user-delete
                                                data-user-name="<?php echo htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8'); ?>"
                                                <?php echo $esUnicoAdminActivo ? 'disabled' : ''; ?>
                                            >
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M3 6h18"/>
                                                    <path d="M8 6V4h8v2"/>
                                                    <path d="M19 6l-1 14H6L5 6"/>
                                                    <path d="M10 11v5"/>
                                                    <path d="M14 11v5"/>
                                                </svg>
                                                <span>Eliminar</span>
                                            </button>
                                        </form>
                                    </div>
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
    <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="user-delete-title" aria-describedby="user-delete-desc">
        <div class="admin-modal__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/>
            </svg>
        </div>
        <h3 class="admin-modal__title" id="user-delete-title">Eliminar usuario</h3>
        <p class="admin-modal__text" id="user-delete-desc">
            Esta acción es permanente y no se puede deshacer. Para confirmar, escribe el nombre completo del usuario:
            <strong data-modal-username></strong>
        </p>
        <input
            type="text"
            class="admin-modal__input"
            data-modal-input
            placeholder="Nombre completo del usuario"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            aria-label="Nombre completo para confirmar"
        >
        <div class="admin-modal__actions">
            <button type="button" class="admin-btn admin-btn--secondary" data-modal-close>Cancelar</button>
            <button type="button" class="admin-btn admin-btn--danger-solid" data-modal-confirm disabled>Eliminar usuario</button>
        </div>
    </div>
</div>
