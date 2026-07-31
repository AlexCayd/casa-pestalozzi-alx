<?php

/**
 * Contrato para el canal que entregará códigos de acceso por contacto.
 *
 * Una etapa posterior podrá aportar proveedores de WhatsApp o correo sin
 * acoplar el flujo OTP a una API externa concreta.
 */

namespace Services;

interface ContactNotificationProvider
{
    /**
     * @return array{ok: bool, provider: string, delivered: bool}
     */
    public function sendOtp(string $tipo, string $contacto, string $codigo): array;
}
