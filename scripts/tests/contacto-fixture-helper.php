<?php
declare(strict_types=1);

use Services\Contact\ContactoProtegidoService;
use Services\Contact\ContactoService;

/** Campos para INSERTs de fixtures en una base aislada. */
function contactoProtegidoFixture(string $tipo, ?string $contacto): array
{
    if ($contacto === null || $contacto === '') {
        return ['contacto' => null, 'contacto_hash' => null];
    }
    $normalizado = ContactoService::normalizar($tipo, $contacto);
    return [
        'contacto' => ContactoProtegidoService::cifrar($tipo, $normalizado),
        'contacto_hash' => ContactoProtegidoService::identificador($tipo, $normalizado),
    ];
}
