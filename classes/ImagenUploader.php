<?php

namespace Classes;

use Intervention\Image\ImageManagerStatic as Image;

/**
 * ImagenUploader
 * --------------------------------------------------------------------------
 * Encapsula la carga de imágenes desde el panel de administración. Recibe un
 * archivo subido vía formulario ($_FILES[...]), lo valida, lo procesa con
 * Intervention Image (varios algoritmos: corrección de orientación EXIF,
 * redimensionado y compresión) y lo guarda SIEMPRE en formato .webp dentro de
 * la carpeta pública de imágenes.
 *
 * Devuelve la ruta relativa (p. ej. "build/images/ab12cd34.webp") lista para
 * almacenarse en la columna `img` de la base de datos, igual que las imágenes
 * existentes del proyecto.
 */
class ImagenUploader
{
    /** Carpeta física donde viven las imágenes públicas. */
    private const CARPETA = __DIR__ . '/../public/build/images/';

    /** Ruta relativa que se guarda en la BD y se sirve desde el navegador. */
    private const RUTA_PUBLICA = 'build/images/';

    /** Tamaño máximo permitido del archivo subido (5 MB). */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Tipos MIME de imagen aceptados como entrada. */
    private const MIME_PERMITIDOS = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ];

    /** Lado máximo (ancho/alto) al que se redimensiona la imagen. */
    private const LADO_MAX = 1200;

    /** Calidad de compresión del .webp resultante (0-100). */
    private const CALIDAD = 80;

    /** Mensajes de error acumulados durante la validación/proceso. */
    private array $errores = [];

    /**
     * Indica si en el arreglo $_FILES realmente se envió un archivo.
     * Sirve para distinguir "no subió nada" (válido al editar) de un error.
     */
    public static function seEnvioArchivo(?array $archivo): bool
    {
        return is_array($archivo)
            && isset($archivo['error'])
            && $archivo['error'] !== UPLOAD_ERR_NO_FILE
            && !empty($archivo['name']);
    }

    /**
     * Procesa el archivo subido y lo convierte a .webp.
     *
     * @param array $archivo Entrada de $_FILES (p. ej. $_FILES['imagen']).
     * @return string|null Ruta relativa guardada, o null si hubo errores.
     */
    public function procesar(array $archivo): ?string
    {
        if (!$this->validar($archivo)) {
            return null;
        }

        try {
            $this->prepararCarpeta();

            $nombre = $this->generarNombre();
            $destino = self::CARPETA . $nombre;

            $imagen = Image::make($archivo['tmp_name']);

            // Algoritmo 1: corrige la orientación según los metadatos EXIF
            // (fotos de cámara/móvil que vienen "giradas"). Solo si el
            // servidor tiene la extensión EXIF disponible.
            if (function_exists('exif_read_data')) {
                $imagen->orientate();
            }

            $imagen
                // Algoritmo 2: redimensiona conservando la proporción y sin
                // ampliar imágenes que ya son pequeñas (upsize).
                ->resize(self::LADO_MAX, self::LADO_MAX, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                })
                // Algoritmo 3: codifica/comprime a WebP con la calidad fijada.
                ->encode('webp', self::CALIDAD)
                ->save($destino);

            return self::RUTA_PUBLICA . $nombre;
        } catch (\Throwable $e) {
            $this->errores[] = 'No se pudo procesar la imagen. Asegúrate de subir un archivo de imagen válido.';
            return null;
        }
    }

    /** Errores acumulados (para mostrarlos como alertas en el formulario). */
    public function getErrores(): array
    {
        return $this->errores;
    }

    /**
     * Borra una imagen previa del disco (al reemplazarla o eliminar la
     * categoría). Solo actúa sobre rutas dentro de build/images para evitar
     * borrados accidentales fuera de la carpeta de imágenes.
     */
    public static function eliminar(?string $rutaRelativa): void
    {
        if (empty($rutaRelativa) || strpos($rutaRelativa, self::RUTA_PUBLICA) !== 0) {
            return;
        }

        $archivo = self::CARPETA . basename($rutaRelativa);
        if (is_file($archivo)) {
            @unlink($archivo);
        }
    }

    // ── Internos ──────────────────────────────────────────────────────────

    private function validar(array $archivo): bool
    {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->errores[] = 'Hubo un problema al subir la imagen. Intenta de nuevo.';
            return false;
        }

        if (($archivo['size'] ?? 0) > self::MAX_BYTES) {
            $this->errores[] = 'La imagen es demasiado grande (máximo 5 MB).';
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($archivo['tmp_name']);

        if (!in_array($mime, self::MIME_PERMITIDOS, true)) {
            $this->errores[] = 'Formato no permitido. Usa JPG, PNG, WebP, GIF o AVIF.';
            return false;
        }

        return true;
    }

    private function prepararCarpeta(): void
    {
        if (!is_dir(self::CARPETA)) {
            mkdir(self::CARPETA, 0775, true);
        }
    }

    private function generarNombre(): string
    {
        return md5(uniqid((string) mt_rand(), true)) . '.webp';
    }
}
