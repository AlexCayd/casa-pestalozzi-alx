<?php

namespace Services\Notifications;

/** Única interfaz de configuración de infraestructura para notificaciones. */
final class NotificationConfig
{
    public const DEVELOPMENT = 'development';
    public const TEST = 'test';
    public const PRODUCTION = 'production';
    public const WHATSAPP_MODE_TEXT = 'text';
    public const WHATSAPP_MODE_TEMPLATE = 'template';

    private const ENVIRONMENTS = [
        self::DEVELOPMENT,
        self::TEST,
        self::PRODUCTION,
    ];

    public static function environment(): string
    {
        $environment = strtolower(trim(self::env('APP_ENV', self::PRODUCTION)));
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \RuntimeException('APP_ENV no es válido para notificaciones.');
        }

        return $environment;
    }

    public static function usesExternalTransport(): bool
    {
        return self::environment() !== self::DEVELOPMENT;
    }

    public static function showConfirmationCode(): bool
    {
        return self::environment() === self::DEVELOPMENT;
    }

    public static function showManagementDebugLinks(): bool
    {
        return self::environment() === self::DEVELOPMENT;
    }

    /** TEST usa texto libre; production usa templates aprobados. Development no transporta. */
    public static function whatsappMode(): string
    {
        return self::environment() === self::PRODUCTION
            ? self::WHATSAPP_MODE_TEMPLATE
            : self::WHATSAPP_MODE_TEXT;
    }

    public static function n8nBaseUrl(): string
    {
        return rtrim(trim(self::env('N8N_BASE_URL')), '/');
    }

    public static function n8nSecret(): string
    {
        return trim(self::env('N8N_SECRET'));
    }

    public static function externalTransportIsConfigured(): bool
    {
        return !self::usesExternalTransport()
            || (self::n8nBaseUrl() !== '' && self::n8nSecret() !== '');
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = array_key_exists($key, $_ENV) ? $_ENV[$key] : getenv($key);

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }
}
