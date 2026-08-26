<?php
    $title = $title ?? 'Registrar usuario';
    $accion = $accion ?? 'Crear usuario';
    $modo = 'crear';
    $action = $action ?? '/admin/usuarios/create';
?>

<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Usuarios</span>
            <h2 class="admin-page__title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="admin-page__subtitle">Crea un usuario con acceso al panel administrativo.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/usuarios">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m15 18-6-6 6-6"/>
            </svg>
            Volver
        </a>
    </header>

    <section class="admin-menu__panel admin-menu__panel--form admin-users-panel admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Datos del usuario</h3>
            <p>Completa usuario, nombre, rol y estado. La credencial se define según el rol.</p>
            </div>
        </div>

        <?php include __DIR__ . '/form.php'; ?>
    </section>
</section>

<?php if (is_array($nipFlash ?? null) && !empty($nipFlash['nip'])) : ?>
    <div
        hidden
        data-user-nip-delivery
        data-nip="<?php echo htmlspecialchars((string) $nipFlash['nip'], ENT_QUOTES, 'UTF-8'); ?>"
        data-nip-visibility-seconds="<?php echo (int) \Services\UsuarioConfig::NIP_MODAL_VISIBILIDAD_SEGUNDOS; ?>"
        data-after-url="<?php echo htmlspecialchars((string) ($nipFlash['after_url'] ?? '/admin/usuarios'), ENT_QUOTES, 'UTF-8'); ?>"
        data-flow="<?php echo htmlspecialchars((string) ($nipFlash['flujo'] ?? 'alta'), ENT_QUOTES, 'UTF-8'); ?>"
    ></div>
<?php endif; ?>
