<?php
/**
 * Header operativo único para mapa de mesas y mapa de reservaciones.
 *
 * La geometría del header no cambia entre módulos: cada vista solo entrega
 * título, rutas, actualización y datos del usuario.
 */
$operationalView = (string)($operationalView ?? 'reservations');
$operationalModule = (string)($operationalModule ?? $operationalView);
$operationalModuleTitle = (string)($operationalModuleTitle ?? ($operationalView === 'map' ? 'Mapa de mesas' : 'Mapa de reservaciones'));
$operationalDate = (string)($operationalDate ?? date('Y-m-d'));
$operationalHour = (string)($operationalHour ?? '');
$operationalBrandHref = (string)($operationalBrandHref ?? '/punto-de-venta');
$operationalHeaderBackUrl = (string)($operationalHeaderBackUrl ?? '');
$operationalDrawerId = (string)($operationalDrawerId ?? 'operational-reservations-drawer');
$operationalHeaderDrawerToggleHtml = (string)($operationalHeaderDrawerToggleHtml ?? '');
$operationalHeaderActionsHtml = (string)($operationalHeaderActionsHtml ?? '');
$operationalUsuarioNombre = trim((string)($operationalUsuarioNombre ?? ''));
$operationalUsuarioRol = (string)($operationalUsuarioRol ?? 'Usuario');
$operationalHeaderUserMenuId = 'operational-user-menu-' . preg_replace('/[^a-z0-9_-]+/i', '-', $operationalModule);
$operationalHeaderH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

if ($operationalHeaderDrawerToggleHtml === '') {
    ob_start();
    $operationalDrawerInitialCount = '0';
    include __DIR__ . '/drawer-toggle.php';
    $operationalHeaderDrawerToggleHtml = (string)ob_get_clean();
}

$operationalHeaderInitial = $operationalUsuarioNombre !== ''
    ? function_exists('mb_substr') ? mb_strtoupper(mb_substr($operationalUsuarioNombre, 0, 1)) : strtoupper(substr($operationalUsuarioNombre, 0, 1))
    : '';

if ($operationalHeaderBackUrl === '') {
    $operationalHeaderQuery = http_build_query(array_filter([
        'fecha' => $operationalDate,
        'hora' => $operationalHour,
    ], static fn($value): bool => $value !== ''));
    $operationalHeaderBackUrl = '/admin/reservations' . ($operationalHeaderQuery !== '' ? '?' . $operationalHeaderQuery : '');
}
?>
<header
    class="operational-header"
    data-operational-header
    data-operational-module="<?php echo $operationalHeaderH($operationalModule); ?>"
>
    <div class="operational-header__region operational-header__region--left">
        <?php echo $operationalHeaderDrawerToggleHtml; ?>
        <a
            class="operational-header__brand"
            href="<?php echo $operationalHeaderH($operationalBrandHref); ?>"
            title="CASA PESTALOZZI"
            aria-label="CASA PESTALOZZI"
        >
            <span class="operational-header__brand-name">CASA PESTALOZZI</span>
            <span class="operational-header__brand-meta">Del Valle · México</span>
        </a>
    </div>

    <div class="operational-header__region operational-header__region--center">
        <span class="operational-header__module"><?php echo $operationalHeaderH($operationalModuleTitle); ?></span>
    </div>

    <div class="operational-header__region operational-header__region--right">
        <div class="operational-header__last-update" aria-label="Última actualización">
            <?php
            $operationalUpdateId = $operationalView === 'map' ? 'mapa-live-badge' : '';
            $operationalUpdateTextId = $operationalView === 'map' ? 'mapa-update-status' : '';
            $operationalUpdateText = $operationalView === 'map' ? 'En vivo' : 'Preparando operación';
            $operationalUpdateTextAttributes = $operationalView === 'reservations'
                ? ['data-operation-update-status' => true]
                : [];
            $operationalUpdateClass = $operationalView === 'map' ? 'mapa-live-badge' : '';
            include __DIR__ . '/last-update.php';
            ?>
        </div>

        <?php echo $operationalHeaderActionsHtml; ?>

        <a
            class="operational-header__back"
            href="<?php echo $operationalHeaderH($operationalHeaderBackUrl); ?>"
            aria-label="Volver a la gestión"
            title="Volver a la gestión"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5"></path><path d="m12 19-7-7 7-7"></path></svg>
        </a>

        <?php if ($operationalUsuarioNombre !== ''): ?>
            <div class="operational-user-menu" data-operational-user-menu>
                <button
                    type="button"
                    class="operational-header__user"
                    aria-haspopup="menu"
                    aria-expanded="false"
                    aria-controls="<?php echo $operationalHeaderH($operationalHeaderUserMenuId); ?>"
                    data-operational-user-toggle
                >
                    <span class="operational-header__user-avatar" aria-hidden="true"><?php echo $operationalHeaderH($operationalHeaderInitial); ?></span>
                    <span class="operational-header__user-info">
                        <span class="operational-header__user-name"><?php echo $operationalHeaderH($operationalUsuarioNombre); ?></span>
                        <span class="operational-header__user-role"><?php echo $operationalHeaderH($operationalUsuarioRol); ?></span>
                    </span>
                    <svg class="operational-header__user-chevron" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m7 10 5 5 5-5"></path></svg>
                </button>
                <div
                    class="operational-user-menu__panel"
                    id="<?php echo $operationalHeaderH($operationalHeaderUserMenuId); ?>"
                    role="menu"
                    aria-label="Menú de usuario"
                    hidden
                    data-operational-user-panel
                >
                    <form class="operational-header__logout-form" method="POST" action="/logout" data-operational-logout-form>
                        <button type="submit" class="operational-user-menu__logout" role="menuitem">
                            <span>Cerrar sesión</span>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</header>
<?php unset($operationalView, $operationalModule, $operationalModuleTitle, $operationalDate, $operationalHour, $operationalBrandHref, $operationalHeaderBackUrl, $operationalHeaderDrawerToggleHtml, $operationalHeaderActionsHtml, $operationalUsuarioNombre, $operationalUsuarioRol, $operationalHeaderUserMenuId, $operationalHeaderH, $operationalHeaderInitial); ?>
