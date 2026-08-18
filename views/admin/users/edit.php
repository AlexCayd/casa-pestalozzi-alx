<?php
    $usuario = $usuario ?? [];
    $title = $title ?? 'Editar usuario';
    $accion = $accion ?? 'Guardar cambios';
    $modo = 'editar';

    $usuarioId = 0;
    if (is_array($usuario)) {
        $usuarioId = (int) ($usuario['id'] ?? 0);
    } elseif (is_object($usuario)) {
        $usuarioId = (int) ($usuario->id ?? 0);
    }

    $action = $action ?? '/admin/usuarios/edit?id=' . $usuarioId;
?>

<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Usuarios</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="admin-page__subtitle">Actualiza los datos generales del usuario sin modificar su contraseña.</p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/usuarios">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Volver
            </a>
            <?php $rolUsuario = is_array($usuario) ? ($usuario['rol'] ?? '') : ($usuario->rol ?? ''); ?>
            <?php if ($rolUsuario === 'admin') : ?>
                <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/usuarios/cambiar-password?id=<?php echo $usuarioId; ?>">Cambiar contraseña</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-users-panel admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Datos del usuario</h3>
                <p>Actualiza usuario, nombre, rol y estado. El NIP existente nunca se muestra.</p>
            </div>
        </div>

        <form hidden id="admin-user-regenerate-form" method="POST" action="/admin/usuarios/regenerar-nip" data-user-regenerate-form>
            <input type="hidden" name="id" value="<?php echo $usuarioId; ?>">
            <input type="hidden" name="admin_csrf" value="<?php echo htmlspecialchars((string) ($adminCsrfToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        </form>

        <?php include __DIR__ . '/form.php'; ?>
    </section>
</section>

<?php if (is_array($nipFlash ?? null) && !empty($nipFlash['nip'])) : ?>
    <div
        hidden
        data-user-nip-delivery
        data-nip="<?php echo htmlspecialchars((string) $nipFlash['nip'], ENT_QUOTES, 'UTF-8'); ?>"
        data-nip-visibility-seconds="<?php echo (int) \Services\UsuarioConfig::NIP_MODAL_VISIBILIDAD_SEGUNDOS; ?>"
        data-after-url="<?php echo htmlspecialchars((string) ($nipFlash['after_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        data-flow="<?php echo htmlspecialchars((string) ($nipFlash['flujo'] ?? 'regeneracion'), ENT_QUOTES, 'UTF-8'); ?>"
    ></div>
<?php endif; ?>
