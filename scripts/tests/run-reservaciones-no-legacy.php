<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$classes = ['ContactNotificationProvider', 'DevelopmentContactNotificationProvider',
    'DevelopmentOperationalNotificationProvider', 'OperationalNotificationProvider',
    'OperationalNotificationProviderFactory', 'N8nOperationalNotificationProvider',
    'ReservationNotificationDispatcher', 'N8nNotificationClient',
    'ReservationNotificationResultService', 'ReservationReminderService'];
foreach ($classes as $class) {
    if (file_exists($root . '/services/' . $class . '.php')
        || class_exists('Services\\' . $class) || interface_exists('Services\\' . $class)) {
        throw new RuntimeException('Legacy activo: ' . $class);
    }
}
$forbidden = ['OperationalNotificationProvider', 'ContactNotificationProvider',
    'ReservationNotificationDispatcher', 'N8nNotificationClient',
    'CONTACT_NOTIFICATION_PROVIDER', 'RESERVATION_NOTIFICATION_PROVIDER',
    'N8N_WEBHOOK_RESERVATIONS_URL', 'CONTACT_OTP_SEND_ENABLED'];
foreach (['services', 'controllers', 'models', 'src/js'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir)) as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js'], true)) continue;
        $source = file_get_contents($file->getPathname());
        foreach ($forbidden as $name) {
            if (str_contains($source, $name)) throw new RuntimeException('Referencia activa: ' . $name . ' en ' . $file->getFilename());
        }
    }
}
if (file_exists($root . '/n8n/reservaciones-comunicaciones.json')) throw new RuntimeException('Workflow monolítico presente.');
echo "Notificaciones: sin clases, variables ni workflow legacy activos.\n";
