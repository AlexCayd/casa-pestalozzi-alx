<?php

/**
 * Auditoría operativa de reservaciones. Nunca recibe OTP ni tokens.
 */

namespace Model;

class ReservacionEvento extends ActiveRecord
{
    protected static $tabla = 'reservacion_eventos';
    protected static $columnasDB = [
        'id',
        'reservacion_id',
        'ticket_id',
        'usuario_id',
        'evento',
        'estado_anterior',
        'estado_nuevo',
        'motivo',
        'metadata_json',
    ];

    public $id;
    public $reservacion_id;
    public $ticket_id = null;
    public $usuario_id = null;
    public $evento;
    public $estado_anterior = null;
    public $estado_nuevo = null;
    public $motivo = null;
    public $metadata_json = null;
    public $created_at = null;

    /**
     * Registra un evento dentro de la transacción que produjo el cambio.
     */
    public static function registrar(
        int $reservacionId,
        string $evento,
        ?string $estadoAnterior,
        ?string $estadoNuevo,
        ?int $usuarioId = null,
        ?int $ticketId = null,
        ?string $motivo = null,
        array $metadata = []
    ): void {
        $db = self::$db;
        $metadataJson = $metadata === []
            ? null
            : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metadataJson === false) {
            throw new \DomainException('Los metadatos del evento no son válidos.');
        }

        $stmt = $db->prepare(
            'INSERT INTO reservacion_eventos
                (reservacion_id, ticket_id, usuario_id, evento,
                 estado_anterior, estado_nuevo, motivo, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new \RuntimeException($db->error);
        }
        $stmt->bind_param(
            'iiisssss',
            $reservacionId,
            $ticketId,
            $usuarioId,
            $evento,
            $estadoAnterior,
            $estadoNuevo,
            $motivo,
            $metadataJson
        );
        if (!$stmt->execute()) {
            throw new \RuntimeException($stmt->error);
        }
        $stmt->close();
    }
}
