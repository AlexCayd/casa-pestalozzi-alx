<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Casa Pestalozzi — <?php echo htmlspecialchars($titulo ?? 'Panel'); ?></title>
    <link rel="stylesheet" href="/build/css/dashboard.css" />
</head>
<body>
    <header class="dash-top">
        <h1>CASA PESTALOZZI · Administración</h1>
        <a href="/">← Volver al sitio</a>
    </header>
    <div class="wrap">
        <?php echo $contenido; ?>
    </div>
</body>
</html>
