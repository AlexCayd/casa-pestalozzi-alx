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
// El tablero de área lo apaga: es una pantalla fija en la cocina, y ahí el
// wordmark no informa de nada —quien la mira ya sabe en qué restaurante está—
// mientras que el nombre de la estación sí. Sin marca, el título pasa a la
// izquierda y ocupa el sitio que ella dejó, que es el más legible de la barra.
$operationalHeaderBrand = (bool)($operationalHeaderBrand ?? true);
// Destino opcional del título. El tablero lo usa para devolver al admin al
// panel sin gastar un botón: el rótulo que ya está en pantalla hace de enlace.
$operationalHeaderModuleHref = (string)($operationalHeaderModuleHref ?? '');
// Con el chip apagado queda sólo el botón de salida. En una estación
// compartida el nombre de quien inició sesión no es dato de trabajo.
$operationalHeaderUserChip = (bool)($operationalHeaderUserChip ?? true);
$operationalDate = (string)($operationalDate ?? date('Y-m-d'));
$operationalHour = (string)($operationalHour ?? '');
$operationalBrandHref = (string)($operationalBrandHref ?? '/punto-de-venta');
$operationalHeaderBackUrl = (string)($operationalHeaderBackUrl ?? '');
// El POS lo apaga para los meseros: el destino vive bajo /admin y la guardia de
// rol los rebotaría a una pantalla de error.
$operationalHeaderBack = (bool)($operationalHeaderBack ?? true);
$operationalDrawerId = (string)($operationalDrawerId ?? 'operational-reservations-drawer');
$operationalHeaderDrawerToggleHtml = (string)($operationalHeaderDrawerToggleHtml ?? '');
// Los tableros de área lo apagan: no tienen cajón que abrir, y un hamburguesa
// que no despliega nada es peor que no tenerlo.
$operationalHeaderDrawerToggle = (bool)($operationalHeaderDrawerToggle ?? true);
$operationalHeaderActionsHtml = (string)($operationalHeaderActionsHtml ?? '');
$operationalUsuarioNombre = trim((string)($operationalUsuarioNombre ?? ''));
$operationalUsuarioRol = (string)($operationalUsuarioRol ?? 'Usuario');
// El POS los apaga: sin reloj de actualización y con salida directa en vez de
// menú desplegable. Reservaciones conserva ambos, de ahí los defaults en true.
$operationalShowLastUpdate = (bool)($operationalShowLastUpdate ?? true);
$operationalUserMenu = (bool)($operationalUserMenu ?? true);
$operationalHeaderUserMenuId = 'operational-user-menu-' . preg_replace('/[^a-z0-9_-]+/i', '-', $operationalModule);
$operationalHeaderH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

if ($operationalHeaderDrawerToggleHtml === '' && $operationalHeaderDrawerToggle) {
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
    $operationalHeaderBackUrl = '/admin/reservaciones' . ($operationalHeaderQuery !== '' ? '?' . $operationalHeaderQuery : '');
}
?>
<header
    class="operational-header<?php echo $operationalView === 'reservations' ? ' operational-header--reservations' : ''; ?><?php echo $operationalHeaderBrand ? '' : ' operational-header--bare'; ?>"
    data-operational-header
    data-operational-module="<?php echo $operationalHeaderH($operationalModule); ?>"
>
    <div class="operational-header__region operational-header__region--left">
        <?php echo $operationalHeaderDrawerToggleHtml; ?>
        <?php if ($operationalHeaderBrand): ?>
            <a
                class="operational-header__brand"
                href="<?php echo $operationalHeaderH($operationalBrandHref); ?>"
                title="CASA PESTALOZZI"
                aria-label="CASA PESTALOZZI"
            >
                <span class="operational-header__brand-name">CASA PESTALOZZI</span>
                <span class="operational-header__brand-meta">Del Valle · México</span>
            </a>
        <?php else: ?>
            <?php // Sin marca el título ocupa su sitio: es el único rótulo que
                  // queda y el que se lee desde el otro lado de la cocina. ?>
            <h1 class="operational-header__module">
                <?php if ($operationalHeaderModuleHref !== ''): ?>
                    <a href="<?php echo $operationalHeaderH($operationalHeaderModuleHref); ?>"><?php echo $operationalHeaderH($operationalModuleTitle); ?></a>
                <?php else: ?>
                    <?php echo $operationalHeaderH($operationalModuleTitle); ?>
                <?php endif; ?>
            </h1>
        <?php endif; ?>
    </div>

    <?php if ($operationalView === 'map' && $operationalHeaderBrand): ?>
        <div class="operational-header__region operational-header__region--center">
            <h1 class="operational-header__module"><?php echo $operationalHeaderH($operationalModuleTitle); ?></h1>
        </div>
    <?php endif; ?>

    <div class="operational-header__region operational-header__region--right">
        <?php if ($operationalShowLastUpdate): ?>
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
        <?php endif; ?>

        <?php echo $operationalHeaderActionsHtml; ?>

        <?php if ($operationalHeaderBack): ?>
            <?php // Rejilla de módulos, no flecha: el enlace no retrocede en el
                  // historial, salta al panel de administración. ?>
            <a
                class="operational-header__back"
                href="<?php echo $operationalHeaderH($operationalHeaderBackUrl); ?>"
                aria-label="Ir al panel de administración"
                title="Panel de administración"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                </svg>
            </a>
        <?php endif; ?>

        <?php if ($operationalUsuarioNombre !== '' && $operationalUserMenu): ?>
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
        <?php elseif ($operationalUsuarioNombre !== ''): ?>
            <?php if ($operationalHeaderUserChip): ?>
                <?php /* Chip informativo: la cuenta se ve, pero salir es un toque, no dos. */ ?>
                <div class="operational-header__user operational-header__user--static">
                    <span class="operational-header__user-avatar" aria-hidden="true"><?php echo $operationalHeaderH($operationalHeaderInitial); ?></span>
                    <span class="operational-header__user-info">
                        <span class="operational-header__user-name"><?php echo $operationalHeaderH($operationalUsuarioNombre); ?></span>
                        <span class="operational-header__user-role"><?php echo $operationalHeaderH($operationalUsuarioRol); ?></span>
                    </span>
                </div>
            <?php endif; ?>
            <form class="operational-header__logout-form" method="POST" action="/logout" data-operational-logout-form data-confirm-logout>
                <button
                    type="submit"
                    class="operational-header__logout"
                    aria-label="Cerrar sesión"
                    title="Cerrar sesión"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="m16 17 5-5-5-5"></path><path d="M21 12H9"></path></svg>
                </button>
            </form>
        <?php endif; ?>
    </div>
</header>
<?php unset($operationalView, $operationalModule, $operationalModuleTitle, $operationalDate, $operationalHour, $operationalBrandHref, $operationalHeaderBackUrl, $operationalHeaderDrawerToggleHtml, $operationalHeaderDrawerToggle, $operationalHeaderActionsHtml, $operationalUsuarioNombre, $operationalUsuarioRol, $operationalHeaderUserMenuId, $operationalHeaderH, $operationalHeaderInitial, $operationalShowLastUpdate, $operationalUserMenu, $operationalHeaderBrand, $operationalHeaderModuleHref, $operationalHeaderUserChip); ?>
