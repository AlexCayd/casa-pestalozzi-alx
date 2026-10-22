<?php

namespace Services\Security;

/** Frontera de unidad de Etapa 1. No representa inventarios operativos. */
final class Contextos
{
    public const NOMBRES = ['global' => 'Global', 'restaurante' => 'Restaurante', 'panaderia' => 'Panadería'];

    public static function permitidos(string $rol, ?string $unidad): array
    {
        if ($rol === Permisos::SUPER_ADMIN) {
            return array_keys(self::NOMBRES);
        }
        if (in_array($unidad, ['restaurante', 'panaderia'], true)) {
            return [$unidad];
        }
        // Personal anterior a la asignación de unidades: conserva sólo Restaurante.
        if ($unidad === null && $rol !== '' && $rol !== Permisos::ADMIN) {
            return ['restaurante'];
        }
        return [];
    }

    public static function deRuta(string $ruta): string
    {
        foreach (['global', 'panaderia'] as $contexto) {
            $prefijo = '/admin/' . $contexto;
            if ($ruta === $prefijo || str_starts_with($ruta, $prefijo . '/')) {
                return $contexto;
            }
        }
        return 'restaurante';
    }

    public static function permite(string $ruta, string $rol, ?string $unidad): bool
    {
        $permitidos = self::permitidos($rol, $unidad);
        if (in_array($ruta, ['/admin', '/admin/contexto'], true)) {
            return $permitidos !== [];
        }
        return in_array(self::deRuta($ruta), $permitidos, true);
    }

    public static function inicio(string $contexto): string
    {
        return $contexto === 'restaurante' ? '/admin' : '/admin/' . $contexto;
    }

    public static function demoHabilitada(): bool
    {
        $entorno = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''));
        return in_array($entorno, ['development', 'test'], true);
    }
}
