<?php

namespace Services\Integrations;

/** Adaptador HTTP JSON. El llamador aporta endpoint, credencial y payload. */
final class N8nClient
{
    private string $baseUrl;
    private string $secret;
    /** @var callable|null */
    private $transport;

    public function __construct(
        string $baseUrl,
        string $secret,
        ?callable $transport = null
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->secret = trim($secret);
        $this->transport = $transport;
    }

    /** @return array{ok:bool,accepted:bool,codigo:string,http_status?:int} */
    public function post(string $path, array $payload, int $expectedStatus = 202, int $timeoutSeconds = 8): array
    {
        $path = '/' . ltrim(trim($path), '/');
        if (preg_match('#^/webhook/[a-zA-Z0-9_/-]+$#', $path) !== 1
            || str_contains($path, '//')) {
            return self::failure('NOTIFICACION_RUTA_INVALIDA');
        }
        if ($this->baseUrl === '') {
            return self::failure('NOTIFICACION_URL_FALTANTE');
        }
        if ($this->secret === '') {
            return self::failure('NOTIFICACION_SECRET_FALTANTE');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return self::failure('NOTIFICACION_PAYLOAD_INVALIDO');
        }

        $url = $this->baseUrl . $path;
        try {
            $response = $this->transport
                ? ($this->transport)($url, $this->secret, $json)
                : $this->curl($url, $json, $timeoutSeconds);
        } catch (\Throwable) {
            error_log('N8nClient::post - fallo de conexión redactado.');
            return self::failure('NOTIFICACION_CONEXION_FALLIDA');
        }

        $status = (int)($response['status'] ?? 0);
        if (trim((string)($response['error'] ?? '')) !== '' || $status === 0) {
            error_log('N8nClient::post - transporte no disponible; sin payload.');
            return self::failure('NOTIFICACION_CONEXION_FALLIDA');
        }

        $body = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($body)) {
            return self::failure('NOTIFICACION_RESPUESTA_INVALIDA', $status);
        }

        $accepted = $status === $expectedStatus
            && ($body['ok'] ?? false) === true
            && ($body['accepted'] ?? false) === true;

        return [
            'ok' => $accepted,
            'accepted' => $accepted,
            'codigo' => $accepted ? 'NOTIFICACION_ACEPTADA' : 'NOTIFICACION_NO_ACEPTADA',
            'http_status' => $status,
            'channel' => is_string($body['channel'] ?? null) ? $body['channel'] : null,
        ];
    }

    /** @return array{status:int,body:string,error:string} */
    private function curl(string $url, string $json, int $timeoutSeconds): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_unavailable'];
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => max(1, min(30, $timeoutSeconds)),
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

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    /** @return array{ok:false,accepted:false,codigo:string,http_status?:int} */
    private static function failure(string $code, ?int $status = null): array
    {
        $result = ['ok' => false, 'accepted' => false, 'codigo' => $code];
        if ($status !== null) {
            $result['http_status'] = $status;
        }

        return $result;
    }
}
