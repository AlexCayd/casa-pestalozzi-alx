<?php
    $usuario = $usuario ?? [];
    $alertas = $alertas ?? [];

    $usuarioId = 0;
    $nombreUsuario = 'Usuario';
    if (is_array($usuario)) {
        $usuarioId = (int) ($usuario['id'] ?? 0);
        $nombreUsuario = (string) ($usuario['username'] ?? $usuario['nombre'] ?? 'Usuario');
    } elseif (is_object($usuario)) {
        $usuarioId = (int) ($usuario->id ?? 0);
        $nombreUsuario = (string) ($usuario->username ?? $usuario->nombre ?? 'Usuario');
    }

    $action = $action ?? '/admin/users/change-password?id=' . $usuarioId;
?>

<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Usuarios</span>
            <h2 class="admin-page__title">Cambiar contraseña</h2>
            <p class="admin-page__subtitle">Actualiza la contraseña administrativa de <?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?>.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/users">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            Volver
        </a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Datos de la contraseña</h3>
                <p>Escribe y confirma la nueva contraseña.</p>
            </div>
        </div>

        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <form class="admin-menu__form" method="POST" action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="password">Nueva contraseña</label>
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
                <button
                    type="button"
                    class="admin-password-toggle"
                    aria-label="Mostrar contraseña"
                    title="Mostrar contraseña"
                    data-password-toggle
                    data-target="password"
                >
                    <svg class="admin-password-toggle__icon admin-password-toggle__icon--show" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg class="admin-password-toggle__icon admin-password-toggle__icon--hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="m3 3 18 18"/>
                        <path d="M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 2.4-1.2"/>
                        <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-3.1 4.1"/>
                        <path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 7 10 7a10.7 10.7 0 0 0 4.2-.8"/>
                    </svg>
                </button>
            </div>
            <p class="admin-password-help" id="password_help" data-password-feedback>
                Mínimo 8 caracteres, una mayúscula y un número.
            </p>

            <label for="password_confirm">Confirmar nueva contraseña</label>
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
                <button
                    type="button"
                    class="admin-password-toggle"
                    aria-label="Mostrar contraseña"
                    title="Mostrar contraseña"
                    data-password-toggle
                    data-target="password_confirm"
                >
                    <svg class="admin-password-toggle__icon admin-password-toggle__icon--show" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg class="admin-password-toggle__icon admin-password-toggle__icon--hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="m3 3 18 18"/>
                        <path d="M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 2.4-1.2"/>
                        <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-3.1 4.1"/>
                        <path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 7 10 7a10.7 10.7 0 0 0 4.2-.8"/>
                    </svg>
                </button>
            </div>

            <div class="admin-menu__form-actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary">Guardar contraseña</button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/users">Cancelar</a>
            </div>
        </form>
    </section>
</section>
