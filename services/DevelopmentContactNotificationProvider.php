<?php

/**
 * Proveedor controlado de la Etapa 1: nunca realiza llamadas externas.
 *
 * El código existe únicamente durante la petición. La persistencia se limita
 * al hash y el proveedor real debe entregarlo por el canal elegido; nunca se
 * devuelve al navegador.
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
