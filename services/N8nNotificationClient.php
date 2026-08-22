<?php

namespace Services;

/** Cliente HTTP redactado para el único webhook de reservaciones. */
class N8nNotificationClient
{
    private string $url;
    private string $secret;
    /** @var callable|null */
    private $transport;

    public function __construct(?string $url = null, ?string $secret = null, ?callable $transport = null)
    {
        $this->url = trim($url ?? self::env('N8N_WEBHOOK_RESERVATIONS_URL'));
        $this->secret = trim($secret ?? self::env('N8N_SECRET'));
        $this->transport = $transport;
    }

    /** @return array{ok:bool,accepted:bool,codigo:string,http_status?:int} */
    public function send(array $payload): array
    {
        if ($this->url === '') {
            return ['ok' => false, 'accepted' => false, 'codigo' => 'NOTIFICACION_URL_FALTANTE'];
        }
        if ($this->secret === '') {
            return ['ok' => false, 'accepted' => false, 'codigo' => 'NOTIFICACION_SECRET_FALTANTE'];
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ['ok' => false, 'accepted' => false, 'codigo' => 'NOTIFICACION_PAYLOAD_INVALIDO'];
        }

        try {
            $respuesta = $this->transport
                ? ($this->transport)($this->url, $this->secret, $json)
                : $this->curl($json);
        } catch (\Throwable $e) {
            error_log('N8nNotificationClient::send - fallo de conexión redactado.');
            return ['ok' => false, 'accepted' => false, 'codigo' => 'NOTIFICACION_CONEXION_FALLIDA'];
        }
        $status = (int)($respuesta['status'] ?? 0);
        $error = trim((string)($respuesta['error'] ?? ''));
        if ($error !== '' || $status === 0) {
            error_log('N8nNotificationClient::send - transporte no disponible; sin payload.');
            return ['ok' => false, 'accepted' => false, 'codigo' => 'NOTIFICACION_CONEXION_FALLIDA'];
        }
        $body = json_decode((string)($respuesta['body'] ?? ''), true);
        if (!is_array($body)) {
            return [
                'ok' => false,
                'accepted' => false,
                'codigo' => 'NOTIFICACION_RESPUESTA_INVALIDA',
                'http_status' => $status,
            ];
        }
        $accepted = $status === 202 && ($body['ok'] ?? false) === true && ($body['accepted'] ?? false) === true;
        return [
            'ok' => $accepted,
            'accepted' => $accepted,
            'codigo' => $accepted ? 'NOTIFICACION_ACEPTADA' : 'NOTIFICACION_NO_ACEPTADA',
            'http_status' => $status,
        ];
    }

    /** @return array{status:int,body:string,error:string} */
    private function curl(string $json): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_unavailable'];
        }
        $curl = curl_init($this->url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-N8N-Secret: ' . $this->secret,
            ],
            CURLOPT_POSTFIELDS => $json,
        ]);
        $body = curl_exec($curl);
        $error = $body === false ? curl_error($curl) : '';
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return is_string($value) ? $value : '';
    }
}
