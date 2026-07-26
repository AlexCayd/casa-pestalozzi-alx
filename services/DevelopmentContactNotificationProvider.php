<?php

/**
 * Proveedor controlado de la Etapa 1: nunca realiza llamadas externas.
 *
 * El código existe únicamente durante la petición. La persistencia se limita
 * al hash y el controlador decide, con una doble condición de entorno, si
 * puede devolverlo como vista previa.
 */

namespace Services;

class DevelopmentContactNotificationProvider implements ContactNotificationProvider
{
    /**
     * @return array{ok: bool, provider: string, delivered: bool}
     */
    public function sendOtp(string $tipo, string $contacto, string $codigo): array
    {
        return [
            'ok' => true,
            'provider' => 'development',
            'delivered' => false,
        ];
    }
}
