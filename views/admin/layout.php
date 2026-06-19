<?php
/**
 * Layout general del panel de administración.
 * Compone sidebar, topbar, contenido y recursos específicos de cada módulo.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Casa Pestalozzi Admin - <?php echo $title ?? 'Panel'; ?></title>
    <link rel="stylesheet" href="/build/css/admin.css">
    <?php foreach ($styles ?? [] as $stylesheet): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($stylesheet, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
</head>
<body class="admin-body">
    <div class="admin-shell">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <button
            class="admin-sidebar-backdrop"
            type="button"
            aria-label="Cerrar navegación"
            data-admin-sidebar-backdrop
        ></button>

        <div class="admin-main">
            <?php include_once __DIR__ . '/partials/_topbar.php'; ?>

            <main class="admin-content">
                <?php echo $content; ?>
            </main>
        </div>
    </div>

    <script src="/build/js/admin.js" defer></script>
    <?php foreach ($scripts ?? [] as $script): ?>
        <script src="<?php echo htmlspecialchars($script, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
