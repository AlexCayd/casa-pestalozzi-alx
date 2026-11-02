<?php
    $usuario = $usuario ?? [];
    $alertas = $alertas ?? [];
    // Los roles que quien edita puede asignar (AdminUsersController) y el
    // catálogo completo, para el nombre y la vía de acceso de cada uno.
    $roles = is_array($roles ?? null) ? $roles : [];
    $catalogoRoles = is_array($catalogoRoles ?? null) ? $catalogoRoles : [];
    $unidades = is_iterable($unidades ?? null) ? $unidades : [];
    $puedeAsignarUnidad = !empty($puedeAsignarUnidad);
    $accion = $accion ?? 'Guardar usuario';
    $modo = $modo ?? 'crear';
    $action = $action ?? '';

    $nombreRol = static fn (string $slug): string =>
        isset($catalogoRoles[$slug]) ? (string) $catalogoRoles[$slug]['rol']->nombre : ucfirst($slug);
    // Cómo entra cada rol: lo deducen sus permisos (contraseña si tiene algún
    // módulo del panel, NIP si sólo pantallas de piso).
    $accesoRol = static fn (string $slug): string =>
        (string) ($catalogoRoles[$slug]['acceso'] ?? (in_array($slug, ['super_admin', 'admin'], true) ? 'contrasena' : 'nip'));

    $valor = static function (string $campo, $default = '') use ($usuario) {
        if (is_array($usuario)) {
            return $usuario[$campo] ?? $default;
        }
        if (is_object($usuario)) {
            return $usuario->$campo ?? $default;
        }
        return $default;
    };

    $rolActual = (string) $valor('rol', in_array('waiter', $roles, true) ? 'waiter' : (string) ($roles[0] ?? 'waiter'));
    $rolOriginal = (string) ($rolOriginal ?? $rolActual);
    $activoActual = (int) $valor('activo', 1);
    $unidadActual = (int) $valor('unidad_id', 0);
    $esEdicion = $modo === 'editar';
    // $esAdmin conserva el nombre de antes, pero ya no es «rol admin»: es
    // «este rol entra con usuario y contraseña».
    $esAdmin = $accesoRol($rolActual) === 'contrasena';
    $requierePassword = $esAdmin && (!$esEdicion || $accesoRol($rolOriginal) !== 'contrasena');
    $tieneNipPersistido = $esEdicion
        && $accesoRol($rolOriginal) !== 'contrasena'
        && trim((string) $valor('nip_hash')) !== ''
        && trim((string) $valor('nip_lookup')) !== '';

    $accesoPorRol = [];
    foreach ($roles as $rolDisponible) {
        $accesoPorRol[(string) $rolDisponible] = \Classes\Auth::areasPorRol((string) $rolDisponible);
    }
?>
<script>
    window.AdminUserRoleAccess = <?php echo json_encode($accesoPorRol, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>

<?php include __DIR__ . '/../partials/alertas.php'; ?>

<form
    class="admin-menu__form admin-users-form"
    data-users-form
    method="POST"
    action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>"
    data-user-id="<?php echo (int) $valor('id', 0); ?>"
    data-original-role="<?php echo htmlspecialchars($rolOriginal, ENT_QUOTES, 'UTF-8'); ?>"
    data-original-access="<?php echo htmlspecialchars($accesoRol($rolOriginal), ENT_QUOTES, 'UTF-8'); ?>"
    data-form-mode="<?php echo htmlspecialchars($modo, ENT_QUOTES, 'UTF-8'); ?>"
    data-has-persisted-nip="<?php echo $tieneNipPersistido ? '1' : '0'; ?>"
>
    <input type="hidden" name="admin_csrf" value="<?php echo htmlspecialchars((string) ($adminCsrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
    <section class="admin-users-form__section">
        <div class="admin-users-form__section-head">
            <h4>Identidad y acceso</h4>
            <p>Define los datos básicos y el alcance operativo de la cuenta.</p>
        </div>

        <div class="admin-users-form__grid">
            <div class="admin-users-form__field">
                <label for="username">Usuario</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    maxlength="20"
                    autocomplete="username"
                    placeholder="ej. mariana_lopez"
                    value="<?php echo htmlspecialchars((string) $valor('username'), ENT_QUOTES, 'UTF-8'); ?>"
                    required
                >
                <p class="admin-users-form__hint">3 a 20 caracteres: letras, números o guion bajo.</p>
            </div>

            <div class="admin-users-form__field">
                <label for="nombre">Nombre completo</label>
                <input
                    type="text"
                    id="nombre"
                    name="nombre"
                    maxlength="120"
                    placeholder="ej. Mariana López"
                    value="<?php echo htmlspecialchars((string) $valor('nombre'), ENT_QUOTES, 'UTF-8'); ?>"
                    required
                >
            </div>

            <div class="admin-users-form__field admin-users-form__field--wide">
                <span class="admin-users-form__field-label">Rol</span>
                <div class="admin-tabs" role="radiogroup" aria-label="Rol">
                    <?php foreach ($roles as $rol) : ?>
                        <?php $rol = (string) $rol; ?>
                        <label class="admin-tabs__tab">
                            <input
                                type="radio"
                                name="rol"
                                value="<?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?>"
                                data-user-role
                                data-acceso="<?php echo htmlspecialchars($accesoRol($rol), ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $rolActual === $rol ? 'checked' : ''; ?>
                                required
                            >
                            <span><?php echo htmlspecialchars($nombreRol($rol), ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="admin-users-form__hint" data-role-credential-hint>
                    <?php echo $esAdmin
                        ? 'Este rol entra al panel con usuario y contraseña.'
                        : 'El NIP se genera automáticamente al crear el usuario.'; ?>
                </p>
                <div class="admin-role-access" data-role-access>
                    <span class="admin-role-access__title">Acceso del rol</span>
                    <ul class="admin-role-access__list" data-role-access-list></ul>
                </div>
            </div>

            <?php /* Unidad de negocio: sólo para el rol Administrador. Hoy no
                     restringe nada —es la base de la integración multiempresa—
                     y sólo la asigna el super admin; al resto se le enseña,
                     deshabilitada. Sin JS se ve siempre; el servidor la
                     descarta en cualquier rol que no sea Administrador. */ ?>
            <div class="admin-users-form__field" data-user-unidad-field <?php echo $rolActual === 'admin' ? '' : 'hidden'; ?>>
                <label for="unidad_id">Unidad</label>
                <select id="unidad_id" name="unidad_id" <?php echo $puedeAsignarUnidad ? '' : 'disabled'; ?>>
                    <option value="">Sin unidad</option>
                    <?php foreach ($unidades as $unidad) : ?>
                        <option value="<?php echo (int) $unidad->id; ?>" <?php echo $unidadActual === (int) $unidad->id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $unidad->nombre, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="admin-users-form__hint">
                    <?php echo $puedeAsignarUnidad
                        ? 'A qué unidad pertenece este administrador. Por ahora no limita lo que ve: lo hará la integración multiempresa.'
                        : 'Sólo el super admin asigna unidades.'; ?>
                </p>
            </div>

            <?php /* Correo del segundo paso: sólo los roles de sistema lo usan
                     (Services\Security\DosFactores) y el servidor lo descarta
                     en cualquier otro. */ ?>
            <div class="admin-users-form__field" data-user-email-field <?php echo in_array($rolActual, ['super_admin', 'admin'], true) ? '' : 'hidden'; ?>>
                <label for="email">Correo</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="160"
                    autocomplete="email"
                    spellcheck="false"
                    placeholder="ej. mariana@casapestalozzi.mx"
                    value="<?php echo htmlspecialchars((string) $valor('email'), ENT_QUOTES, 'UTF-8'); ?>"
                >
                <p class="admin-users-form__hint">Aquí llega el código de 6 dígitos que pide el inicio de sesión. Sin correo, la cuenta no podrá entrar al panel.</p>
            </div>

            <div
                class="admin-users-access-status-grid<?php echo !$esEdicion || $esAdmin ? ' admin-users-access-status-grid--single' : ''; ?>"
                data-user-access-status-grid
            >
                <?php if ($esEdicion) : ?>
                    <div
                        class="admin-users-form__field admin-users-access-status-card admin-users-access-card"
                        data-role-nip-section
                        data-has-persisted-nip="<?php echo $tieneNipPersistido ? '1' : '0'; ?>"
                        <?php echo $esAdmin ? 'hidden' : ''; ?>
                    >
                        <span class="admin-users-form__field-label">Acceso de piso</span>
                        <button
                            type="submit"
                            form="admin-user-regenerate-form"
                            class="admin-btn admin-btn--secondary admin-btn--small"
                            data-user-regenerate
                            <?php echo !$tieneNipPersistido ? 'hidden' : ''; ?>
                        >
                            Regenerar NIP
                        </button>
                        <span class="admin-users-access-card__status" data-role-nip-pending <?php echo $tieneNipPersistido ? 'hidden' : ''; ?>>
                            NIP se generará al guardar
                        </span>
                        <p class="admin-users-form__hint" data-role-nip-hint <?php echo !$tieneNipPersistido ? 'hidden' : ''; ?>>
                            El NIP actual no puede consultarse. Si se extravió, genera uno nuevo.
                        </p>
                        <p class="admin-users-form__hint" data-role-nip-pending-description <?php echo $tieneNipPersistido ? 'hidden' : ''; ?>>
                            El código se generará automáticamente al guardar los cambios.
                        </p>
                    </div>
                <?php endif; ?>

                <div class="admin-users-form__field admin-users-access-status-card admin-users-state-card">
                    <span class="admin-users-form__field-label">Estado</span>
                    <label class="admin-users-form__toggle admin-users-access-status-card__control">
                        <input type="checkbox" id="activo" name="activo" value="1" <?php echo $activoActual === 1 ? 'checked' : ''; ?>>
                        <span class="admin-users-form__toggle-track" aria-hidden="true"><span class="admin-users-form__toggle-thumb"></span></span>
                        <span class="admin-users-form__toggle-text">Usuario activo</span>
                    </label>
                    <p class="admin-users-form__hint">Los usuarios inactivos conservan su credencial, pero no pueden iniciar sesión.</p>
                </div>
            </div>
        </div>
    </section>

    <section
        class="admin-users-form__section"
        data-user-password-section
        <?php echo $esAdmin ? '' : 'hidden'; ?>
        <?php echo $esEdicion ? 'data-user-password-optional' : ''; ?>
    >
        <div class="admin-users-form__section-head">
            <h4>Contraseña de acceso al panel</h4>
            <p>
                <?php echo $esEdicion && !$requierePassword
                    ? 'Deja los campos vacíos para conservar la contraseña actual.'
                    : 'Mínimo 8 caracteres, una mayúscula y un número.'; ?>
            </p>
        </div>

        <div class="admin-users-form__grid admin-users-form__grid--pair">
            <div class="admin-users-form__field">
                <label for="password"><?php echo $esEdicion ? 'Nueva contraseña' : 'Contraseña'; ?></label>
                <div class="admin-password-field">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="new-password"
                        minlength="8"
                        pattern="(?=.*[A-Z])(?=.*\d).{8,}"
                        title="La contraseña debe tener al menos 8 caracteres, una mayúscula y un número"
                        aria-describedby="password_help"
                        data-password-strength
                        <?php echo $esAdmin ? '' : 'disabled'; ?>
                        <?php echo $requierePassword ? 'required' : ''; ?>
                    >
                    <button type="button" class="admin-password-toggle" aria-label="Mostrar contraseña" title="Mostrar contraseña" data-password-toggle data-target="password">
                        <svg class="admin-password-toggle__icon admin-password-toggle__icon--show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="admin-password-toggle__icon admin-password-toggle__icon--hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 18 18"/><path d="M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 2.4-1.2"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-3.1 4.1"/><path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 7 10 7a10.7 10.7 0 0 0 4.2-.8"/></svg>
                    </button>
                </div>
                <p class="admin-users-form__hint admin-password-help" id="password_help" data-password-feedback>Mínimo 8 caracteres, una mayúscula y un número.</p>
            </div>

            <div class="admin-users-form__field">
                <label for="password_confirm"><?php echo $esEdicion ? 'Confirmar nueva contraseña' : 'Confirmar contraseña'; ?></label>
                <div class="admin-password-field">
                    <input
                        type="password"
                        id="password_confirm"
                        name="password_confirm"
                        autocomplete="new-password"
                        minlength="8"
                        pattern="(?=.*[A-Z])(?=.*\d).{8,}"
                        title="La contraseña debe tener al menos 8 caracteres, una mayúscula y un número"
                        <?php echo $esAdmin ? '' : 'disabled'; ?>
                        <?php echo $requierePassword ? 'required' : ''; ?>
                    >
                    <button type="button" class="admin-password-toggle" aria-label="Mostrar contraseña" title="Mostrar contraseña" data-password-toggle data-target="password_confirm">
                        <svg class="admin-password-toggle__icon admin-password-toggle__icon--show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="admin-password-toggle__icon admin-password-toggle__icon--hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 18 18"/><path d="M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 2.4-1.2"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-3.1 4.1"/><path d="M6.6 6.6C3.6 6.6 2 12 2 12s3.5 7 10 7a10.7 10.7 0 0 0 4.2-.8"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <div class="admin-menu__form-actions admin-users-form__actions">
        <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" data-admin-magnetic>
            <?php echo htmlspecialchars($accion, ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/usuarios">Cancelar</a>
    </div>
</form>
