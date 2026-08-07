<?php
    /**
     * Cambio de credencial en tres pasos: actual → nueva → confirmación.
     *
     * El tipo lo decide el rol del usuario destino, no quien opera:
     * contraseña para administradores, NIP de 4 dígitos para el personal de
     * piso. El campo "actual" es siempre la contraseña del administrador que
     * ejecuta la acción (re-auth estilo sudo), porque un admin no puede
     * conocer el NIP hasheado de un mesero.
     */
    $usuario = $usuario ?? [];
    $alertas = $alertas ?? [];
    $tipoCredencial = $tipoCredencial ?? 'password';
    $esPropio = $esPropio ?? false;
    $esNip = $tipoCredencial === 'nip';

    $usuarioId = 0;
    $nombreUsuario = 'Usuario';
    if (is_array($usuario)) {
        $usuarioId = (int) ($usuario['id'] ?? 0);
        $nombreUsuario = (string) ($usuario['nombre'] ?? $usuario['username'] ?? 'Usuario');
    } elseif (is_object($usuario)) {
        $usuarioId = (int) ($usuario->id ?? 0);
        $nombreUsuario = (string) ($usuario->nombre ?? $usuario->username ?? 'Usuario');
    }

    $action = $action ?? '/admin/usuarios/cambiar-credencial?id=' . $usuarioId;
    $etiqueta = $esNip ? 'NIP' : 'contraseña';
    $titulo = $esNip ? 'Cambiar NIP' : 'Cambiar contraseña';
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // El ojo de mostrar/ocultar. Se repite tal cual en los tres campos.
    $ojo = static function (string $target) use ($h) : void {
        ?>
        <button
            type="button"
            class="admin-password-toggle"
            aria-label="Mostrar valor"
            title="Mostrar valor"
            data-password-toggle
            data-target="<?php echo $h($target); ?>"
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
        <?php
    };
?>

<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Usuarios</span>
            <h2 class="admin-page__title"><?php echo $h($titulo); ?></h2>
            <p class="admin-page__subtitle">
                <?php if ($esPropio) : ?>
                    Actualiza tu <?php echo $h($etiqueta); ?> de acceso.
                <?php else : ?>
                    Asigna una <?php echo $h($etiqueta); ?> nueva a <?php echo $h($nombreUsuario); ?>.
                <?php endif; ?>
            </p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/usuarios">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            Volver
        </a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3><?php echo $esNip ? 'NIP del personal de piso' : 'Contraseña administrativa'; ?></h3>
                <p>
                    <?php if ($esNip) : ?>
                        4 dígitos, único por usuario. Con él inicia sesión en el punto de venta.
                    <?php else : ?>
                        Mínimo 8 caracteres, una mayúscula y un número.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php include __DIR__ . '/../partials/alertas.php'; ?>

        <form class="admin-menu__form admin-users-form admin-users-form--single" method="POST" action="<?php echo $h($action); ?>">
            <section class="admin-users-form__section admin-credential-step">
                <span class="admin-credential-step__num" aria-hidden="true">1</span>
                <div class="admin-credential-step__body">
                    <label for="secreto_actual">Tu contraseña de administrador</label>
                    <div class="admin-password-field">
                        <input
                            type="password"
                            id="secreto_actual"
                            name="secreto_actual"
                            autocomplete="current-password"
                            required
                        >
                        <?php $ojo('secreto_actual'); ?>
                    </div>
                    <p class="admin-users-form__hint">
                        Confirma que eres tú antes de cambiar
                        <?php echo $esPropio ? 'tu credencial' : 'la credencial de otra persona'; ?>.
                    </p>
                </div>
            </section>

            <section class="admin-users-form__section admin-credential-step">
                <span class="admin-credential-step__num" aria-hidden="true">2</span>
                <div class="admin-credential-step__body">
                    <label for="nuevo"><?php echo $esNip ? 'NIP nuevo' : 'Contraseña nueva'; ?></label>
                    <div class="admin-password-field">
                        <?php if ($esNip) : ?>
                            <input
                                type="password"
                                id="nuevo"
                                name="nuevo"
                                inputmode="numeric"
                                pattern="\d{4}"
                                maxlength="4"
                                autocomplete="off"
                                title="NIP numérico de 4 dígitos"
                                required
                            >
                        <?php else : ?>
                            <input
                                type="password"
                                id="nuevo"
                                name="nuevo"
                                autocomplete="new-password"
                                minlength="8"
                                pattern="(?=.*[A-Z])(?=.*\d).{8,}"
                                title="La contraseña debe tener al menos 8 caracteres, una mayúscula y un número"
                                aria-describedby="nuevo_help"
                                data-password-strength
                                required
                            >
                        <?php endif; ?>
                        <?php $ojo('nuevo'); ?>
                    </div>
                    <?php if (!$esNip) : ?>
                        <p class="admin-password-help" id="nuevo_help" data-password-feedback>
                            Mínimo 8 caracteres, una mayúscula y un número.
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="admin-users-form__section admin-credential-step">
                <span class="admin-credential-step__num" aria-hidden="true">3</span>
                <div class="admin-credential-step__body">
                    <label for="confirmacion">Confirmar <?php echo $esNip ? 'NIP nuevo' : 'contraseña nueva'; ?></label>
                    <div class="admin-password-field">
                        <?php if ($esNip) : ?>
                            <input
                                type="password"
                                id="confirmacion"
                                name="confirmacion"
                                inputmode="numeric"
                                pattern="\d{4}"
                                maxlength="4"
                                autocomplete="off"
                                title="NIP numérico de 4 dígitos"
                                required
                            >
                        <?php else : ?>
                            <input
                                type="password"
                                id="confirmacion"
                                name="confirmacion"
                                autocomplete="new-password"
                                minlength="8"
                                pattern="(?=.*[A-Z])(?=.*\d).{8,}"
                                title="La contraseña debe tener al menos 8 caracteres, una mayúscula y un número"
                                required
                            >
                        <?php endif; ?>
                        <?php $ojo('confirmacion'); ?>
                    </div>
                    <span class="admin-field__error" data-credential-match aria-live="polite"></span>
                </div>
            </section>

            <div class="admin-menu__form-actions admin-users-form__actions">
                <button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" data-admin-magnetic>
                    Guardar <?php echo $h($etiqueta); ?>
                </button>
                <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="/admin/usuarios">Cancelar</a>
            </div>
        </form>
    </section>
</section>

<script>
  // La confirmación se valida también en el servidor; esto solo evita el
  // viaje de ida y vuelta por un error de tecleo.
  (function () {
    var nuevo   = document.getElementById('nuevo');
    var confirm = document.getElementById('confirmacion');
    var aviso   = document.querySelector('[data-credential-match]');
    if (!nuevo || !confirm || !aviso) return;

    function revisar() {
      var distinto = confirm.value !== '' && confirm.value !== nuevo.value;
      aviso.textContent = distinto ? 'No coincide con el valor nuevo.' : '';
      confirm.setCustomValidity(distinto ? 'No coincide' : '');
    }

    nuevo.addEventListener('input', revisar);
    confirm.addEventListener('input', revisar);
  })();
</script>
