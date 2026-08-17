<?php

declare(strict_types=1);

use Services\ContactNotificationProvider;

/**
 * Proveedor controlado para pruebas de OTP.
 *
 * Captura el código que el servicio entrega al canal sin alterar el contrato
 * HTTP: el navegador nunca recibe este proveedor ni su código.
 */
final class FakeContactNotificationProvider implements ContactNotificationProvider
{
    /** @var array<int, array{tipo: string, contacto: string, codigo: string}> */
    private array $envios = [];

    public function sendOtp(string $tipo, string $contacto, string $codigo): array
    {
        $this->envios[] = [
            'tipo' => $tipo,
            'contacto' => $contacto,
            'codigo' => $codigo,
        ];

        return [
            'ok' => true,
            'provider' => 'fake',
            'delivered' => true,
        ];
    }

    public function ultimoCodigo(): ?string
    {
        if ($this->envios === []) {
            return null;
        }

        return $this->envios[array_key_last($this->envios)]['codigo'];
    }
}
