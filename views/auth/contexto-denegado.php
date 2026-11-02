<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso denegado · Casa Pestalozzi</title>
    <link rel="stylesheet" href="/build/css/admin.css">
</head>
<body class="admin-body">
    <main class="admin-context-denied">
        <h1><?php echo htmlspecialchars($tituloAcceso ?? 'Acceso denegado', ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($mensajeAcceso ?? 'Tu cuenta no tiene acceso a este contexto de trabajo. Vuelve a tu espacio o solicita al superadministrador que revise tu unidad asignada.', ENT_QUOTES, 'UTF-8'); ?></p>
        <a class="admin-btn admin-btn--primary" href="<?php echo htmlspecialchars($destinoContexto, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($textoDestino ?? 'Volver a mi espacio', ENT_QUOTES, 'UTF-8'); ?></a>
    </main>
</body>
</html>
