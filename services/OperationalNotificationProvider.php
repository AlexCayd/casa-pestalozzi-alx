<?php

namespace Services;

interface OperationalNotificationProvider
{
    /** @return array{ok:bool,accepted:bool,codigo:string,http_status?:int} */
    public function sendReservationsEvent(string $event, array $notifications): array;
}
