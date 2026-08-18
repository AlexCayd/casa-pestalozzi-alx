<?php

namespace Services;

/** Contrato futuro de entrega operativa, separado del proveedor OTP. */
interface OperationalNotificationProvider
{
    /** @return array{ok: bool, provider: string, delivered: bool} */
    public function sendScheduleChange(array $payload): array;
}
