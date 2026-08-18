<?php
    $usuario = $usuario ?? [];
    $alertas = $alertas ?? [];
    $esPropio = (bool) ($esPropio ?? false);
    $usuarioId = is_array($usuario) ? (int) ($usuario['id'] ?? 0) : (int) ($usuario->id ?? 0);
    $nombreUsuario = is_array($usuario)
        ? (string) ($usuario['nombre'] ?? $usuario['username'] ?? 'Usuario')
        : (string) ($usuario->nombre ?? $usuario->username ?? 'Usuario');
    $action = $action ?? '/admin/usuarios/cambiar-password?id=' . $usuarioId;
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Usuarios</span>
            <h2 class="admin-page__title">Cambiar contraseña</h2>
            <p class="admin-page__subtitle">
                <?php echo $esPropio ? 'Actualiza tu contraseña administrativa.' : 'Actualiza la contraseña de ' . $h($nombreUsuario) . '.'; ?>
            </p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/usuarios">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver
        </a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Contraseña administrativa</h3>
                <p>Mínimo 8 caracteres, una mayúscula y un número.</p>
            </div>
        </div>

        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <form class="admin-menu__form admin-users-form admin-users-form--single" method="POST" action="<?php echo $h($action); ?>">
            <section class="admin-users-form__section admin-credential-step">
                <span class="admin-credential-step__num" aria-hidden="true">1</span>
                <div class="admin-credential-step__body">
                    <label for="secreto_actual">Tu contraseña de administrador</label>
                    <input type="password" id="secreto_actual" name="secreto_actual" autocomplete="current-password" required>
                    <p class="admin-users-form__hint">Confirma que eres tú antes de actualizar la contraseña.</p>
                </div>
            </section>

            <section class="admin-users-form__section admin-credential-step">
                <span class="admin-credential-step__num" aria-hidden="true">2</span>
                <div class="admin-credential-step__body">
                    <label for="nuevo">Contraseña nueva</label>
                    <input type="password" id="nuevo" name="nuevo" autocomplete="new-password" minlength="8" pattern="(?=.*[A-Z])(?=.*\d).{8,}" data-password-strength required>
                    <p class="admin-users-form__hint" data-password-feedback>Mínimo 8 caracteres, una mayúscula y un número.</p>
                </div>
            </section>

            <section class="admin-users-form__section admin-credential-step">
                <span class="admin-credential-step__num" aria-hidden="true">3</span>
                <div class="admin-credential-step__body">
                    <label for="confirmacion">Confirmar contraseña nueva</label>
                    <input type="password" id="confirmacion" name="confirmacion" autocomplete="new-password" minlength="8" pattern="(?=.*[A-Z])(?=.*\d).{8,}" required>
                    <span class="admin-field__error" data-credential-match aria-live="polite"></span>
                </div>
            </section>

            <div class="admin-menu__form-actions admin-users-form__actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary">Guardar contraseña</button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/usuarios">Cancelar</a>
            </div>
        </form>
    </section>
</section>

<script>
  (function () {
    var nuevo = document.getElementById('nuevo');
    var confirmacion = document.getElementById('confirmacion');
    var aviso = document.querySelector('[data-credential-match]');
    if (!nuevo || !confirmacion || !aviso) return;
    function revisar() {
      var distinto = confirmacion.value !== '' && confirmacion.value !== nuevo.value;
      aviso.textContent = distinto ? 'No coincide con la contraseña nueva.' : '';
      confirmacion.setCustomValidity(distinto ? 'No coincide' : '');
    }
    nuevo.addEventListener('input', revisar);
    confirmacion.addEventListener('input', revisar);
  })();
</script>
