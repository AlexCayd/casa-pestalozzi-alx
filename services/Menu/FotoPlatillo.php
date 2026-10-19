<?php

namespace Services\Menu;

use Classes\ImagenUploader;
use Model\Producto;

/**
 * Ciclo de vida de la foto propia de un platillo (`productos.img`).
 *
 * Mismo uploader y mismo criterio que la imagen de categoría
 * (CategoriaMenuService): el archivo viejo se borra DESPUÉS de guardar, nunca
 * antes, así que un POST que no pasa la validación no deja al platillo sin la
 * foto que ya tenía.
 *
 * Y una cosa que la categoría no hace: la foto recién subida en un POST que
 * falla no se tira. Se queda en la sesión como «pendiente» y el formulario la
 * vuelve a ofrecer, porque el navegador vacía el <input type="file"> al
 * repintar, y obligar a buscarla otra vez en la carpeta por culpa de un nombre
 * repetido es castigar al que ya hizo su parte.
 *
 * El formulario manda tres cosas y se resuelven en este orden:
 *   1. `imagen`           — archivo nuevo: gana a todo.
 *   2. `quitar_imagen`    — volver a la foto de la categoría; descarta también
 *                           una pendiente.
 *   3. `imagen_pendiente` — ficha de la foto que sobrevivió a un POST fallido.
 *   Sin ninguna de las tres, se queda la que había.
 *
 * La pendiente viaja como una ficha aleatoria y no como su ruta: con la ruta en
 * un campo oculto, un formulario manipulado podría apuntar al archivo de OTRO
 * platillo, y al reemplazarlo después se borraría la foto del vecino.
 */
final class FotoPlatillo
{
    public const CAMPO_ARCHIVO = 'imagen';
    public const CAMPO_QUITAR = 'quitar_imagen';
    public const CAMPO_PENDIENTE = 'imagen_pendiente';

    private const SESION = 'menu_fotos_pendientes';

    // Una pendiente abandonada —se cerró la pestaña después del error— no tiene
    // dueño en la base. Pasado este plazo, la siguiente purga borra su archivo.
    private const VIGENCIA_SEGUNDOS = 6 * 3600;
    private const MAX_PENDIENTES = 6;

    /** Ruta guardada hoy en la base, o null. */
    private ?string $actual;

    /** Ruta que tendrá el platillo si el guardado sale bien. */
    private ?string $final;

    /** Archivo escrito EN ESTA petición: o se queda en la base o en la sesión. */
    private ?string $subida = null;

    /** Pendiente reclamada en esta petición: ['ficha' => …, 'ruta' => …]. */
    private ?array $pendiente = null;

    private bool $quitar = false;
    private int $productoId;
    private array $errores = [];

    private function __construct(?string $actual, int $productoId)
    {
        $this->actual = self::normalizar($actual);
        $this->final = $this->actual;
        $this->productoId = $productoId;
    }

    /**
     * Lee el formulario, procesa el archivo si lo hay y deja en $platillo->img
     * la ruta que se guardaría. No borra nada del disco: eso espera a saber si
     * el guardado salió bien (confirmar) o no (revertir).
     *
     * @param string|null $actual La foto que el platillo tiene HOY en la base,
     *                            leída antes de sincronizar el POST.
     */
    public static function desdeFormulario(Producto $platillo, ?string $actual, array $post, array $files): self
    {
        $foto = new self($actual, (int) ($platillo->id ?? 0));
        $foto->quitar = !empty($post[self::CAMPO_QUITAR]);
        $foto->pendiente = self::reclamarPendiente((string) ($post[self::CAMPO_PENDIENTE] ?? ''), $foto->productoId);

        $archivo = $files[self::CAMPO_ARCHIVO] ?? null;
        if (ImagenUploader::seEnvioArchivo($archivo)) {
            $uploader = new ImagenUploader();
            $ruta = $uploader->procesar($archivo);

            if ($ruta) {
                $foto->subida = $ruta;
            } else {
                $foto->errores = $uploader->getErrores();
            }
        }

        if ($foto->subida !== null) {
            $foto->final = $foto->subida;
            $foto->quitar = false;
        } elseif ($foto->quitar) {
            $foto->final = null;
        } elseif ($foto->pendiente !== null) {
            $foto->final = $foto->pendiente['ruta'];
        }

        $platillo->img = $foto->final;

        return $foto;
    }

    /** Errores del archivo subido, con el formato de alertas del panel. */
    public function errores(): array
    {
        return $this->errores;
    }

    /**
     * El guardado salió bien: se borra lo que la base ya no nombra —la foto
     * anterior, o una pendiente que se reemplazó o se quitó— y se cierra la
     * ficha.
     */
    public function confirmar(): void
    {
        if ($this->actual !== null && $this->actual !== $this->final) {
            ImagenUploader::eliminar($this->actual);
        }

        if ($this->pendiente !== null) {
            if ($this->pendiente['ruta'] !== $this->final) {
                ImagenUploader::eliminar($this->pendiente['ruta']);
            }
            self::olvidarPendiente($this->pendiente['ficha']);
        }

        $this->subida = null;
        $this->pendiente = null;
        $this->quitar = false;
        $this->actual = $this->final;
        self::purgar();
    }

    /**
     * El guardado no llegó a hacerse (validación o error de base). La foto de
     * la base sigue intacta y el objeto vuelve a nombrarla; lo recién subido
     * pasa a pendiente para el siguiente intento.
     *
     * Con «Quitar foto» marcado, la pendiente NO se borra todavía: si en el
     * siguiente intento se deshace, tiene que seguir ahí.
     */
    public function revertir(Producto $platillo): void
    {
        $platillo->img = $this->actual;

        if ($this->subida === null) {
            return;
        }

        // Una foto nueva sustituye a la pendiente anterior: ya nadie la
        // puede pedir, así que su archivo sobra.
        if ($this->pendiente !== null) {
            ImagenUploader::eliminar($this->pendiente['ruta']);
            self::olvidarPendiente($this->pendiente['ficha']);
            $this->pendiente = null;
        }

        $this->pendiente = self::guardarPendiente($this->subida, $this->productoId);
        $this->subida = null;
    }

    /**
     * Lo que el formulario necesita para repintarse. Las rutas salen ya como URL
     * desde la raíz, igual que en Carta::publica().
     *
     * @return array{actual: ?string, pendiente: ?array{ficha: string, url: string}, quitar: bool, errores: array}
     */
    public function vista(): array
    {
        return [
            'actual' => self::url($this->actual),
            'pendiente' => $this->pendiente === null ? null : [
                'ficha' => $this->pendiente['ficha'],
                'url' => self::url($this->pendiente['ruta']),
            ],
            'quitar' => $this->quitar,
            'errores' => $this->errores,
        ];
    }

    /** Estado de un formulario recién abierto (GET): sólo la foto vigente. */
    public static function vistaInicial(?string $actual): array
    {
        return (new self($actual, 0))->vista();
    }

    /** Ruta relativa → URL desde la raíz, o null. */
    public static function url(?string $ruta): ?string
    {
        $ruta = self::normalizar($ruta);

        return $ruta === null ? null : '/' . ltrim($ruta, '/');
    }

    // ── Pendientes en sesión ───────────────────────────────────────────────

    private static function reclamarPendiente(string $ficha, int $productoId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $ficha) || !self::haySesion()) {
            return null;
        }

        $entrada = $_SESSION[self::SESION][$ficha] ?? null;
        if (!is_array($entrada) || (int) ($entrada['producto'] ?? -1) !== $productoId) {
            return null;
        }

        $ruta = self::normalizar($entrada['ruta'] ?? null);
        $archivo = ImagenUploader::archivoAbsoluto($ruta);
        if ($archivo === null || !is_file($archivo)) {
            self::olvidarPendiente($ficha);
            return null;
        }

        return ['ficha' => $ficha, 'ruta' => $ruta];
    }

    /**
     * Sin sesión activa no hay dónde dejarla, y entonces se comporta como la
     * imagen de categoría: el archivo se borra y hay que volver a elegirlo.
     */
    private static function guardarPendiente(string $ruta, int $productoId): ?array
    {
        if (!self::haySesion()) {
            ImagenUploader::eliminar($ruta);
            return null;
        }

        $ficha = bin2hex(random_bytes(16));
        $_SESSION[self::SESION][$ficha] = [
            'ruta' => $ruta,
            'producto' => $productoId,
            'creada' => time(),
        ];
        self::purgar();

        return ['ficha' => $ficha, 'ruta' => $ruta];
    }

    private static function olvidarPendiente(string $ficha): void
    {
        if (self::haySesion()) {
            unset($_SESSION[self::SESION][$ficha]);
        }
    }

    /**
     * Borra las pendientes vencidas y, si se acumulan, las más viejas. Todo lo
     * que hay aquí es, por construcción, un archivo que ninguna fila nombra:
     * al guardarse, la ficha se cierra en confirmar().
     */
    private static function purgar(): void
    {
        if (!self::haySesion() || empty($_SESSION[self::SESION]) || !is_array($_SESSION[self::SESION])) {
            return;
        }

        $limite = time() - self::VIGENCIA_SEGUNDOS;
        $vigentes = [];

        foreach ($_SESSION[self::SESION] as $ficha => $entrada) {
            if (!is_array($entrada) || (int) ($entrada['creada'] ?? 0) < $limite) {
                ImagenUploader::eliminar(is_array($entrada) ? ($entrada['ruta'] ?? null) : null);
                continue;
            }
            $vigentes[$ficha] = $entrada;
        }

        uasort($vigentes, static fn(array $a, array $b): int => $b['creada'] <=> $a['creada']);
        foreach (array_slice($vigentes, self::MAX_PENDIENTES, null, true) as $ficha => $entrada) {
            ImagenUploader::eliminar($entrada['ruta'] ?? null);
            unset($vigentes[$ficha]);
        }

        $_SESSION[self::SESION] = $vigentes;
    }

    private static function haySesion(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    private static function normalizar(?string $ruta): ?string
    {
        $ruta = trim((string) $ruta);

        return $ruta === '' ? null : $ruta;
    }
}
