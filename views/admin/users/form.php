<?php
    $usuario = $usuario ?? [];
    $alertas = $alertas ?? [];
    $roles = $roles ?? ['admin', 'waiter', 'cook'];
    $accion = $accion ?? 'Guardar usuario';
    $modo = $modo ?? 'crear';
    $action = $action ?? '';

    $roleLabels = [
        'admin' => 'Administrador',
        'waiter' => 'Mesero',
        'cook' => 'Cocinero',
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
    // La sección de seguridad se emite SIEMPRE. Al editar, los campos son
    // opcionales: antes desaparecía y cambiar la contraseña obligaba a salir a
    // una pantalla aparte.
    $esEdicion = $modo === 'editar';

    $accesoPorRol = [];
    foreach ($roles as $rolDisponible) {
        $accesoPorRol[(string) $rolDisponible] = \Classes\Auth::areasPorRol((string) $rolDisponible);
    }
?>
<script>
    window.AdminUserRoleAccess = <?php echo json_encode($accesoPorRol, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>

<?php include __DIR__ . '/../partials/alertas.php'; ?>

<form class="admin-menu__form admin-users-form" data-users-form method="POST" action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
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
                <label for="fecha_nacimiento_display">Fecha de nacimiento</label>
                <?php /*
                  Campo de texto con máscara en vez de <input type="date">: el
                  nativo se pinta con el formato del locale del navegador, que
                  aquí sale mm/dd/yyyy. El hidden conserva Y-m-d, que es lo que
                  valida el backend.
                */ ?>
                <input
                    type="text"
                    id="fecha_nacimiento_display"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="10"
                    placeholder="dd/mm/aaaa"
                    data-birthdate-display
                >
                <input
                    type="hidden"
                    name="fecha_nacimiento"
                    value="<?php echo htmlspecialchars((string) $valor('fecha_nacimiento'), ENT_QUOTES, 'UTF-8'); ?>"
                    data-birthdate-value
                >
                <p class="admin-users-form__hint">Con ella se sugiere el NIP: día y mes del cumpleaños.</p>
            </div>

            <div class="admin-users-form__field" data-user-nip-field>
                <label for="nip">NIP de acceso</label>
                <input
                    type="text"
                    id="nip"
                    name="nip"
                    inputmode="numeric"
                    pattern="\d{4}"
                    maxlength="4"
                    autocomplete="off"
                    placeholder="<?php echo $modo === 'editar' ? 'Sin cambio' : 'ej. 4821'; ?>"
                    title="NIP numérico de 4 dígitos"
                    data-user-nip
                >
                <p class="admin-users-form__hint admin-users-form__hint--warn">
                    Un NIP derivado del cumpleaños lo puede adivinar un compañero. Cámbialo cuando importe.
                </p>
            </div>

            <div class="admin-users-form__field">
                <span class="admin-users-form__field-label">Rol</span>
                <div class="admin-tabs" role="radiogroup" aria-label="Rol">
                    <?php foreach ($roles as $rol) : ?>
                        <?php $rol = (string) $rol; ?>
                        <label class="admin-tabs__tab">
                            <input type="radio" name="rol" value="<?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?>"
                                   data-user-role
                                   <?php echo $rolActual === $rol ? 'checked' : ''; ?> required>
                            <span><?php echo htmlspecialchars($roleLabels[$rol] ?? ucfirst($rol), ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="admin-users-form__hint">El administrador entra con contraseña; meseros y cocineros, con NIP.</p>

                <?php /* Qué abre este rol, en vez de dejarlo a la memoria de
                         quien da de alta. La fuente es Auth::areasPorRol(),
                         derivada de la misma guardia que aplica proteger().
                         users-form.js reemplaza la lista al cambiar de tab. */ ?>
                <div class="admin-role-access" data-role-access>
                    <span class="admin-role-access__title">Acceso del rol</span>
                    <ul class="admin-role-access__list" data-role-access-list></ul>
                </div>
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

        <section class="admin-users-form__section" data-user-password-section
                 <?php echo $esEdicion ? 'data-user-password-optional' : ''; ?>>
            <div class="admin-users-form__section-head">
                <h4>Seguridad</h4>
                <p>
                    <?php echo $esEdicion
                        ? 'Deja los campos vacíos para conservar la contraseña actual.'
                        : 'Define la contraseña inicial. Podrá cambiarla más adelante.'; ?>
                </p>
            </div>

            <div class="admin-users-form__grid">
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
                            <?php echo $esEdicion ? '' : 'required'; ?>
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
                    <label for="password_confirm"><?php echo $esEdicion ? "Confirmar nueva contraseña" : "Confirmar contraseña"; ?></label>
                    <div class="admin-password-field">
                        <input
                            type="password"
                            id="password_confirm"
                            name="password_confirm"
                            autocomplete="new-password"
                            minlength="8"
                            pattern="(?=.*[A-Z])(?=.*\d).{8,}"
                            title="La contraseña debe tener al menos 8 caracteres, una mayúscula y un número"
                            <?php echo $esEdicion ? '' : 'required'; ?>
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

    <div class="admin-menu__form-actions admin-users-form__actions">
        <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" data-admin-magnetic>
            <?php echo htmlspecialchars($accion, ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/usuarios">Cancelar</a>
    </div>
</form>
