<?php
$escapedId = htmlspecialchars($publicId, ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars(\Services\ReservationClientSession::csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cambio de horario · Casa Pestalozzi</title>
    <meta name="referrer" content="no-referrer">
    <link rel="stylesheet" href="/build/css/app.css">
</head>
<body>
    <main class="reservation-link-bridge" data-reservation-link-bridge>
        <div class="reservation-link-bridge__card">
            <span class="eyebrow">Casa Pestalozzi</span>
            <h1>Abriendo tu reservación</h1>
            <p data-bridge-status role="status" aria-live="polite">Estamos preparando un acceso seguro para gestionar el cambio de horario.</p>
            <form data-bridge-form method="post" action="/reservaciones/acceso-cambio-horario">
                <input type="hidden" name="public_id" value="<?php echo $escapedId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <noscript>Activa JavaScript para continuar con este enlace de cambio de horario.</noscript>
            </form>
        </div>
    </main>
    <script>
        (function () {
            var root = document.querySelector('[data-reservation-link-bridge]');
            var form = root && root.querySelector('[data-bridge-form]');
            var status = root && root.querySelector('[data-bridge-status]');
            if (!root || !form) return;
            var hash = window.location.hash.replace(/^#/, '');
            var params = new URLSearchParams(hash);
            var token = params.get('token') || '';
            var cleanUrl = window.location.pathname + window.location.search;
            window.history.replaceState(null, document.title, cleanUrl);
            if (!token) {
                window.location.replace('/reservaciones?cambio_horario=invalido#reserva');
                return;
            }
            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    public_id: form.elements.public_id.value,
                    csrf_token: form.elements.csrf_token.value,
                    token: token
                })
            }).then(function (response) {
                if (response.redirected) {
                    window.location.replace(response.url);
                    return;
                }
                throw new Error('No fue posible validar el enlace.');
            }).catch(function () {
                if (status) status.textContent = 'Este enlace ya no es válido. Regresando a la gestión de reservaciones…';
                window.setTimeout(function () {
                    window.location.replace('/reservaciones?cambio_horario=invalido#reserva');
                }, 250);
            });
        })();
    </script>
</body>
</html>
