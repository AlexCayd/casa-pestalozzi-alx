<?php
    $usuario = $usuario ?? [];
    $alertas = $alertas ?? [];
    $roles = $roles ?? ['admin', 'observer', 'waiter', 'cashier'];
    $accion = $accion ?? 'Guardar usuario';
    $modo = $modo ?? 'crear';
    $action = $action ?? '';

    $roleLabels = [
        'admin' => 'Administrador',
        'waiter' => 'Mesero',
        'cashier' => 'Cajero',
        'observer' => 'Observador',
    ];

    $valor = static function (string $campo, $default = '') use ($usuario) {
        if (is_array($usuario)) {
            return $usuario[$campo] ?? $default;
        }
        if (is_object($usuario)) {
            return $usuario->$campo ?? $default;
        }
        return $default;
    };

    $rolActual = (string) $valor('rol', 'waiter');
    $activoActual = (int) $valor('activo', 1);
    $mostrarPassword = $modo !== 'editar';
?>

<?php include __DIR__ . '/../partials/alertas.php'; ?>

<form class="admin-menu__form admin-users-form" method="POST" action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
    <section class="admin-users-form__section">
        <div class="admin-users-form__section-head">
            <h4>Identidad y acceso</h4>
            <p>Datos con los que la persona inicia sesión y su nivel de permisos.</p>
        </div>

        <div class="admin-users-form__grid">
            <div class="admin-users-form__field">
                <label for="username">Usuario</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    maxlength="80"
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

            <div class="admin-users-form__field">
                <label for="rol">Rol</label>
                <select id="rol" name="rol" required>
                    <?php foreach ($roles as $rol) : ?>
                        <?php $rol = (string) $rol; ?>
                        <option value="<?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $rolActual === $rol ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($roleLabels[$rol] ?? ucfirst($rol), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="admin-users-form__field">
                <span class="admin-users-form__field-label">Estado</span>
                <label class="admin-users-form__toggle">
                    <input type="checkbox" id="activo" name="activo" value="1" <?php echo $activoActual === 1 ? 'checked' : ''; ?>>
                    <span class="admin-users-form__toggle-track" aria-hidden="true"><span class="admin-users-form__toggle-thumb"></span></span>
                    <span class="admin-users-form__toggle-text">Usuario activo</span>
                </label>
                <p class="admin-users-form__hint">Los usuarios inactivos no pueden acceder al panel.</p>
            </div>
        </div>
    </section>

    <?php if ($mostrarPassword) : ?>
        <section class="admin-users-form__section">
            <div class="admin-users-form__section-head">
                <h4>Seguridad</h4>
                <p>Define la contraseña inicial. Podrá cambiarla más adelante.</p>
            </div>

            <div class="admin-users-form__grid">
                <div class="admin-users-form__field">
                    <label for="password">Contraseña</label>
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
                            required
                        >
                        <button type="button" class="admin-password-toggle" aria-label="Mostrar contraseña" title="Mostrar contraseña" data-password-toggle data-target="password">
                            <svg class="admin-password-toggle__icon admin-password-toggle__icon--show" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="admin-password-toggle__icon admin-password-toggle__icon--hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="m3 3 18 18"/><path d="M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 2.4-1.2"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-3.1 4.1"/><path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 7 10 7a10.7 10.7 0 0 0 4.2-.8"/>
                            </svg>
                        </button>
                    </div>
                    <p class="admin-users-form__hint admin-password-help" id="password_help" data-password-feedback>
                        Mínimo 8 caracteres, una mayúscula y un número.
                    </p>
                </div>

                <div class="admin-users-form__field">
                    <label for="password_confirm">Confirmar contraseña</label>
                    <div class="admin-password-field">
                        <input
                            type="password"
                            id="password_confirm"
                            name="password_confirm"
                            autocomplete="new-password"
                            minlength="8"
                            pattern="(?=.*[A-Z])(?=.*\d).{8,}"
                            title="La contraseña debe tener al menos 8 caracteres, una mayúscula y un número"
                            required
                        >
                        <button type="button" class="admin-password-toggle" aria-label="Mostrar contraseña" title="Mostrar contraseña" data-password-toggle data-target="password_confirm">
                            <svg class="admin-password-toggle__icon admin-password-toggle__icon--show" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="admin-password-toggle__icon admin-password-toggle__icon--hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="m3 3 18 18"/><path d="M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 2.4-1.2"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-3.1 4.1"/><path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 7 10 7a10.7 10.7 0 0 0 4.2-.8"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <div class="admin-menu__form-actions admin-users-form__actions">
        <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" data-admin-magnetic>
            <?php echo htmlspecialchars($accion, ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/usuarios">Cancelar</a>
    </div>
</form>
