<?php

/**
 * Gestiona las "areas de mejora" del feedback que genera el flujo de n8n.
 *
 * n8n hace POST del JSON generado por el modelo -> se normaliza y persiste en
 * un archivo (storage/areas_de_mejora.json) -> el panel admin lo lee para
 * renderizar las tarjetas. Mantener la logica aqui evita duplicarla entre el
 * controlador que recibe (FeedbackController) y el que muestra (AdminController).
 */

namespace Services;

class AreasMejora
{
    /** Mapea la palabra clave del icono a su path SVG (viewBox 0 0 24 24). */
    private const ICONOS = [
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'utensils'  => '<path d="M4 7h16"/><path d="M7 7v10a3 3 0 0 0 3 3h4a3 3 0 0 0 3-3V7"/><path d="M9 3v4"/><path d="M15 3v4"/>',
        'user'      => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/>',
        'star'      => '<path d="M12 3l2.5 5 5.5.8-4 3.9 1 5.5L12 21l-5 2.6 1-5.5-4-3.9 5.5-.8Z"/>',
        'message'   => '<path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2Z"/>',
    ];

    private const ICONO_DEFAULT = 'message';

    /** Traduce el tipo de prioridad al "tono" de color usado por la vista. */
    private const TONOS = [
        'alta'        => 'danger',
        'high'        => 'danger',
        'critica'     => 'danger',
        'media'       => 'warning',
        'medium'      => 'warning',
        'oportunidad' => 'success',
        'opportunity' => 'success',
        'baja'        => 'success',
        'continuo'    => 'info',
        'continuous'  => 'info',
    ];

    private const TONO_DEFAULT = 'info';

    /** Etiqueta legible por defecto segun el tipo de prioridad. */
    private const LABELS = [
        'danger'  => 'Prioridad alta',
        'warning' => 'Prioridad media',
        'success' => 'Oportunidad',
        'info'    => 'Continuo',
    ];

    /** Ruta absoluta del archivo JSON donde se persisten las areas. */
    public static function rutaArchivo(): string
    {
        return dirname(__DIR__) . '/storage/areas_de_mejora.json';
    }

    /**
     * Normaliza y guarda las areas recibidas desde n8n.
     *
     * @param array $payload Cuerpo decodificado del request de n8n.
     * @return int Cantidad de areas guardadas.
     * @throws \RuntimeException si no se pudo escribir el archivo.
     */
    public static function guardar(array $payload): int
    {
        $areas = self::extraerAreas($payload);

        $limpias = [];
        foreach ($areas as $area) {
            if (!is_array($area)) {
                continue;
            }
            $limpias[] = self::normalizarArea($area);
        }

        $data = [
            'generado_en'     => date('c'),
            'areas_de_mejora' => $limpias,
        ];

        $ruta = self::rutaArchivo();
        $dir  = dirname($ruta);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio de almacenamiento.');
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($ruta, $json, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir el archivo de areas de mejora.');
        }

        return count($limpias);
    }

    /**
     * Lee las areas persistidas y las prepara para la vista.
     *
     * @return array Cada elemento tiene: cat, titulo, texto, nivel, tono, icon.
     */
    public static function leer(): array
    {
        $ruta = self::rutaArchivo();
        if (!is_file($ruta)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($ruta), true);
        if (!is_array($data) || empty($data['areas_de_mejora'])) {
            return [];
        }

        $vista = [];
        foreach ($data['areas_de_mejora'] as $area) {
            $tono = self::TONOS[$area['prioridad_tipo'] ?? ''] ?? self::TONO_DEFAULT;
            $vista[] = [
                'cat'    => (string) ($area['categoria'] ?? ''),
                'titulo' => (string) ($area['titulo'] ?? ''),
                'texto'  => (string) ($area['descripcion'] ?? ''),
                'nivel'  => (string) ($area['prioridad_label'] ?? self::LABELS[$tono]),
                'tono'   => $tono,
                'icon'   => self::ICONOS[$area['icono'] ?? ''] ?? self::ICONOS[self::ICONO_DEFAULT],
            ];
        }

        return $vista;
    }

    /** Fecha ISO-8601 de la ultima actualizacion, o null si no hay datos. */
    public static function generadoEn(): ?string
    {
        $ruta = self::rutaArchivo();
        if (!is_file($ruta)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($ruta), true);
        return is_array($data) ? ($data['generado_en'] ?? null) : null;
    }

    /** URL del webhook de n8n que dispara el flujo (configurable por env). */
    public static function webhookUrl(): string
    {
        $url = $_ENV['N8N_WEBHOOK_AREAS_MEJORA_URL'] ?? getenv('N8N_WEBHOOK_AREAS_MEJORA_URL');
        return is_string($url) ? trim($url) : '';
    }

    /** Secreto compartido opcional para autenticar el POST entrante de n8n. */
    public static function secret(): string
    {
        $secret = $_ENV['N8N_SECRET'] ?? getenv('N8N_SECRET');
        return is_string($secret) ? trim($secret) : '';
    }

    /** Encuentra el arreglo de areas dentro de payloads con formas variadas. */
    private static function extraerAreas(array $payload): array
    {
        // Forma esperada: { "areas_de_mejora": [ ... ] }
        if (isset($payload['areas_de_mejora']) && is_array($payload['areas_de_mejora'])) {
            return $payload['areas_de_mejora'];
        }

        // A veces n8n envuelve el JSON del modelo como texto en "data"/"text".
        foreach (['data', 'text', 'output'] as $clave) {
            if (isset($payload[$clave]) && is_string($payload[$clave])) {
                $decodificado = json_decode($payload[$clave], true);
                if (is_array($decodificado)) {
                    return self::extraerAreas($decodificado);
                }
            }
        }

        // Arreglo plano de objetos: [ {..}, {..} ]
        if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
            return $payload;
        }

        return [];
    }

    /** Normaliza una area aceptando alias de llaves y saneando el texto. */
    private static function normalizarArea(array $area): array
    {
        $icono = self::primerValor($area, ['icono', 'icon']);
        $tipo  = strtolower(trim(self::primerValor($area, ['prioridad_tipo', 'tono', 'nivel'])));

        // Solo se guardan iconos/tipos conocidos; el resto cae al default.
        if (!isset(self::ICONOS[$icono])) {
            $icono = self::ICONO_DEFAULT;
        }
        if ($tipo === '' || !isset(self::TONOS[$tipo])) {
            $tipo = 'continuo';
        }

        $tono  = self::TONOS[$tipo];
        $label = trim(self::primerValor($area, ['prioridad_label', 'label']));
        if ($label === '') {
            $label = self::LABELS[$tono];
        }

        return [
            'icono'           => $icono,
            'categoria'       => self::primerValor($area, ['categoria', 'cat', 'category']),
            'titulo'          => self::primerValor($area, ['titulo', 'title']),
            'descripcion'     => self::primerValor($area, ['descripcion', 'texto', 'description']),
            'prioridad_tipo'  => $tipo,
            'prioridad_label' => $label,
        ];
    }

    /** Devuelve el primer valor string presente entre varias llaves candidatas. */
    private static function primerValor(array $area, array $llaves): string
    {
        foreach ($llaves as $llave) {
            if (isset($area[$llave]) && is_scalar($area[$llave])) {
                return trim((string) $area[$llave]);
            }
        }
        return '';
    }
}
