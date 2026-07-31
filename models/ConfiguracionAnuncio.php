<?php

namespace Model;

use DateTimeImmutable;
use Services\AnuncioConfig;
use Services\ReservacionConfig;

class ConfiguracionAnuncio extends ActiveRecord
{
    private const ID_UNICO = 1;
    protected static $tabla = 'configuracion_anuncio';
    protected static $columnasDB = [
        'id',
        'mensaje',
        'tipo',
        'activo',
        'fecha_inicio',
        'fecha_fin',
        'texto_enlace',
        'url_enlace',
        'updated_by',
        'updated_at',
    ];

    public $id = self::ID_UNICO;
    public $mensaje = '';
    public $tipo = AnuncioConfig::TIPO_PREDETERMINADO;
    public $activo = 0;
    public $fecha_inicio = null;
    public $fecha_fin = null;
    public $texto_enlace = null;
    public $url_enlace = null;
    public $updated_by = null;
    public $updated_at = null;
    private array $erroresCampos = [];

    public function __construct(array $args = [])
    {
        foreach ($args as $propiedad => $valor) {
            if (property_exists($this, $propiedad)) {
                $this->$propiedad = $valor;
            }
        }

        $this->id = self::ID_UNICO;
    }

    public static function obtener(): ?self
    {
        $stmt = self::getDB()->prepare(
            'SELECT * FROM ' . static::$tabla . ' WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la consulta del anuncio.');
        }

        $id = self::ID_UNICO;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible consultar el anuncio.');
        }

        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila ? static::crearObjeto($fila) : null;
    }

    public static function obtenerOCrear(): self
    {
        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla . ' (id) VALUES (?)
             ON DUPLICATE KEY UPDATE id = VALUES(id)'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la configuración inicial del anuncio.');
        }

        $id = self::ID_UNICO;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('No fue posible inicializar la configuración del anuncio.');
        }
        $stmt->close();

        $anuncio = static::obtener();
        if (!$anuncio) {
            throw new \RuntimeException('No fue posible recuperar la configuración del anuncio.');
        }

        return $anuncio;
    }

    public static function obtenerVisible(?DateTimeImmutable $ahora = null): ?self
    {
        $anuncio = static::obtener();

        return $anuncio && $anuncio->esVisible($ahora) ? $anuncio : null;
    }

    public function esVisible(?DateTimeImmutable $ahora = null): bool
    {
        if ((int) $this->activo !== 1) {
            return false;
        }

        $ahora = $ahora ?? new DateTimeImmutable('now');
        $inicio = self::crearFecha((string) ($this->fecha_inicio ?? ''));
        $fin = self::crearFecha((string) ($this->fecha_fin ?? ''));

        if ($inicio && $ahora < $inicio) {
            return false;
        }
        if ($fin && $ahora > $fin) {
            return false;
        }

        return true;
    }

    public function validar(): array
    {
        static::$alertas = [];
        $this->erroresCampos = [];
        $mensaje = trim((string) $this->mensaje);
        $textoEnlace = trim((string) ($this->texto_enlace ?? ''));
        $urlEnlace = trim((string) ($this->url_enlace ?? ''));
        $inicio = self::crearFecha((string) ($this->fecha_inicio ?? ''));
        $fin = self::crearFecha((string) ($this->fecha_fin ?? ''));
        $hoy = new DateTimeImmutable('today', ReservacionConfig::timezone());

        if ((int) $this->activo === 1 && $mensaje === '') {
            static::setAlerta('error', 'El mensaje es obligatorio cuando el anuncio está activo.');
        }
        if (mb_strlen($mensaje) > 255) {
            static::setAlerta('error', 'El mensaje no puede superar 255 caracteres.');
        }
        if (self::contieneHtml($mensaje)) {
            static::setAlerta('error', 'El mensaje debe contener únicamente texto, sin etiquetas HTML.');
        }
        if (!AnuncioConfig::tipoValido((string) $this->tipo)) {
            static::setAlerta('error', 'El tipo de anuncio no es válido.');
        }

        if ($this->fecha_inicio !== null && $this->fecha_inicio !== '' && !$inicio) {
            static::setAlerta('error', 'La fecha de inicio no es válida.');
        }
        if ($this->fecha_fin !== null && $this->fecha_fin !== '' && !$fin) {
            static::setAlerta('error', 'La fecha de finalización no es válida.');
        }
        if ($inicio && $inicio < $hoy) {
            static::setAlerta('error', 'La fecha de inicio no puede ser anterior al día actual.');
        }
        if ($fin && $fin < $hoy) {
            static::setAlerta('error', 'La fecha de finalización no puede ser anterior al día actual.');
        }
        if ($inicio && $fin && $fin <= $inicio) {
            static::setAlerta('error', 'La fecha de finalización debe ser posterior a la fecha de inicio.');
        }
        if ((int) $this->activo === 1 && $fin && $fin < new DateTimeImmutable('now')) {
            static::setAlerta('error', 'La fecha de finalización no puede estar en el pasado al activar el anuncio.');
        }

        if (mb_strlen($textoEnlace) > 80) {
            $this->registrarErrorCampo('texto_enlace', 'El texto del enlace no puede superar 80 caracteres.');
        }
        if (self::contieneHtml($textoEnlace)) {
            $this->registrarErrorCampo(
                'texto_enlace',
                'El texto del enlace debe contener únicamente texto, sin etiquetas HTML.'
            );
        }
        if (mb_strlen($urlEnlace) > 500) {
            $this->registrarErrorCampo('url_enlace', 'La URL del enlace no puede superar 500 caracteres.');
        }
        if ($textoEnlace !== '' && $urlEnlace === '') {
            $this->registrarErrorCampo('url_enlace', 'Ingresa también la URL del enlace.');
        } elseif ($urlEnlace !== '' && $textoEnlace === '') {
            $this->registrarErrorCampo('texto_enlace', 'Ingresa también el texto del enlace.');
        }
        if ($urlEnlace !== '' && !self::esUrlPermitida($urlEnlace)) {
            $this->registrarErrorCampo(
                'url_enlace',
                'La URL debe ser una ruta interna o una URL absoluta con protocolo http o https.'
            );
        }

        return static::$alertas;
    }

    public function normalizar(): void
    {
        $this->id = self::ID_UNICO;
        $this->mensaje = trim((string) $this->mensaje);
        $this->tipo = AnuncioConfig::tipoValido(trim((string) $this->tipo))
            ? trim((string) $this->tipo)
            : AnuncioConfig::TIPO_PREDETERMINADO;
        $this->activo = (int) ((bool) $this->activo);
        $this->fecha_inicio = self::normalizarFecha($this->fecha_inicio);
        $this->fecha_fin = self::normalizarFecha($this->fecha_fin);
        $this->texto_enlace = self::normalizarOpcional($this->texto_enlace);
        $this->url_enlace = self::normalizarUrlOpcional($this->url_enlace);
        $this->updated_by = filter_var(
            $this->updated_by,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        ) ?: null;
    }

    public function guardarConfiguracion(): bool
    {
        $this->normalizar();

        $stmt = self::getDB()->prepare(
            'INSERT INTO ' . static::$tabla . '
                (id, mensaje, tipo, activo, fecha_inicio, fecha_fin, texto_enlace, url_enlace, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                mensaje = VALUES(mensaje),
                tipo = VALUES(tipo),
                activo = VALUES(activo),
                fecha_inicio = VALUES(fecha_inicio),
                fecha_fin = VALUES(fecha_fin),
                texto_enlace = VALUES(texto_enlace),
                url_enlace = VALUES(url_enlace),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar el guardado del anuncio.');
        }

        $id = self::ID_UNICO;
        $mensaje = $this->mensaje;
        $tipo = $this->tipo;
        $activo = (int) $this->activo;
        $fechaInicio = $this->fecha_inicio;
        $fechaFin = $this->fecha_fin;
        $textoEnlace = $this->texto_enlace;
        $urlEnlace = $this->url_enlace;
        $usuarioId = $this->updated_by;
        $stmt->bind_param(
            'ississssi',
            $id,
            $mensaje,
            $tipo,
            $activo,
            $fechaInicio,
            $fechaFin,
            $textoEnlace,
            $urlEnlace,
            $usuarioId
        );

        $resultado = $stmt->execute();
        $stmt->close();

        return $resultado;
    }

    public function valoresFormulario(): array
    {
        $tipo = (string) $this->tipo;

        return [
            'activo' => (int) $this->activo === 1,
            'mensaje' => (string) $this->mensaje,
            'tipo' => AnuncioConfig::tipoValido($tipo) ? $tipo : AnuncioConfig::TIPO_PREDETERMINADO,
            'fecha_inicio' => self::fechaParaFormulario($this->fecha_inicio),
            'fecha_fin' => self::fechaParaFormulario($this->fecha_fin),
            'texto_enlace' => (string) ($this->texto_enlace ?? ''),
            'url_enlace' => (string) ($this->url_enlace ?? ''),
            'updated_at' => (string) ($this->updated_at ?? ''),
        ];
    }

    public static function esUrlPermitida(string $url): bool
    {
        return self::urlPermitida(trim($url));
    }

    private static function normalizarFecha($valor): ?string
    {
        $fecha = self::crearFecha(trim((string) ($valor ?? '')));

        return $fecha ? $fecha->format('Y-m-d H:i:s') : null;
    }

    private static function fechaParaFormulario($valor): string
    {
        $fecha = self::crearFecha(trim((string) ($valor ?? '')));

        return $fecha ? $fecha->format('Y-m-d\TH:i') : '';
    }

    private static function crearFecha(string $valor): ?DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        foreach (['!Y-m-d\TH:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i', '!Y-m-d H:i:s'] as $formato) {
            $fecha = DateTimeImmutable::createFromFormat($formato, $valor, ReservacionConfig::timezone());
            $errores = DateTimeImmutable::getLastErrors();
            if ($fecha && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))) {
                return $fecha;
            }
        }

        return null;
    }

    private static function normalizarOpcional($valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }

    public function erroresCampos(): array
    {
        return $this->erroresCampos;
    }

    private static function normalizarUrlOpcional($valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));
        if ($valor === '') {
            return null;
        }

        $sanitizada = filter_var($valor, FILTER_SANITIZE_URL);

        return is_string($sanitizada) && self::urlPermitida($sanitizada)
            ? $sanitizada
            : null;
    }

    private static function contieneHtml(string $valor): bool
    {
        return $valor !== strip_tags($valor) || str_contains($valor, "\0");
    }

    private function registrarErrorCampo(string $campo, string $mensaje): void
    {
        $this->erroresCampos[$campo][] = $mensaje;
        static::setAlerta('error', $mensaje);
    }

    private static function urlPermitida(string $url): bool
    {
        if (preg_match('/[\\x00-\\x20\\x7F]/', $url)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && !str_contains($url, '\\');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $esquema = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($esquema, ['http', 'https'], true);
    }
}
