<?php

/**
 * Módulo de Catas del panel: la agenda que publica la landing.
 *
 * Tres pantallas se quedaron en dos. El detalle con la lista de inscritos
 * desapareció junto con las inscripciones —el lugar se aparta por WhatsApp— y
 * lo que era una tabla de ocupación es ahora una fila con su fecha y un
 * interruptor.
 *
 * Sigue el patrón de AdminPrintersController (render que delega en
 * AdminController, validarId, alertas por modelo) y el CSRF de
 * AdminReservacionController.
 *
 * Las mutaciones cierran con POST-redirect-GET. Como las alertas de
 * ActiveRecord son estáticas y no sobreviven a un redirect, el resultado viaja
 * en `?aviso=` y se traduce a mensaje al pintar la página siguiente; así
 * recargar no reenvía el formulario.
 */

namespace Controllers;

use Model\Cata;
use MVC\Router;
use Services\AdminCsrfService;
use Services\CataService;

class AdminCataController
{
    private const RUTA = '/admin/catas';
    private const CSS = '/build/css/admin/catas.css';
    private const JS = '/build/js/admin/catas.js';

    /** Avisos que pueden llegar por query string tras un redirect. */
    private const AVISOS = [
        'creada' => ['exito', 'Cata creada correctamente'],
        'actualizada' => ['exito', 'Cata actualizada correctamente'],
        'eliminada' => ['exito', 'Cata eliminada correctamente'],
        'publicada' => ['exito', 'La cata vuelve a admitir gente'],
        'despublicada' => ['exito', 'La cata se marcó sin cupo. Sigue anunciada en la landing.'],
        'no-existe' => ['error', 'La cata no existe'],
        'id-invalido' => ['error', 'Identificador no válido'],
        'sesion-expirada' => ['error', 'La sesión expiró. Vuelve a intentarlo.'],
        'error-eliminar' => ['error', 'No se pudo eliminar la cata'],
        'error-disponibilidad' => ['error', 'No se pudo cambiar la disponibilidad'],
    ];

    public static function index(Router $router): void
    {
        // Sólo '1' y '0' filtran; cualquier otra cosa —incluido lo que alguien
        // escriba a mano en la URL— cae en «todas».
        $disponibilidad = (string)($_GET['disponible'] ?? '');
        $disponibilidad = in_array($disponibilidad, ['0', '1'], true) ? $disponibilidad : '';
        $busqueda = (string)($_GET['q'] ?? '');

        self::render('catas/index', [
            'title' => 'Catas',
            'topbarSection' => 'Catas',
            'catas' => CataService::listaAdministrativa($disponibilidad, $busqueda),
            'disponibilidadActiva' => $disponibilidad,
            'busqueda' => $busqueda,
            'alertas' => self::avisos(),
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    public static function create(Router $router): void
    {
        self::formulario(new Cata(), 'Nueva cata', 'Crear cata', 'creada');
    }

    public static function edit(Router $router): void
    {
        $cata = Cata::find(self::validarId($_GET['id'] ?? $_POST['id'] ?? null));

        if (!$cata) {
            self::redirect(self::RUTA . '?aviso=no-existe');
        }

        // El <input type="time"> no acepta los segundos que devuelve la BD.
        $cata->hora = substr((string)$cata->hora, 0, 5);

        self::formulario($cata, 'Editar cata', 'Guardar cambios', 'actualizada');
    }

    /**
     * El interruptor de la lista. Es un POST propio y no una edición completa
     * para que encender una cata no exija abrir su formulario ni revalidarlo.
     */
    public static function disponibilidad(Router $router): void
    {
        self::exigirPost();

        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::redirect(self::RUTA . '?aviso=sesion-expirada');
        }

        $id = self::validarId($_POST['id'] ?? null);
        // El <input type="checkbox"> no viaja cuando está apagado, así que el
        // valor que manda el formulario es el que se QUIERE dejar, no el actual.
        $disponible = (string)($_POST['disponible'] ?? '0') === '1';

        $resultado = CataService::cambiarDisponibilidad($id, $disponible);

        if (!$resultado['ok']) {
            self::redirect(self::RUTA . '?aviso=' . (
                $resultado['codigo'] === CataService::NO_EXISTE ? 'no-existe' : 'error-disponibilidad'
            ));
        }

        self::redirect(self::RUTA . self::filtrosDeVuelta() . 'aviso=' . ($disponible ? 'publicada' : 'despublicada'));
    }

    public static function delete(Router $router): void
    {
        self::exigirPost();

        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::redirect(self::RUTA . '?aviso=sesion-expirada');
        }

        $cata = Cata::find(self::validarId($_POST['id'] ?? null));

        if (!$cata) {
            self::redirect(self::RUTA . '?aviso=no-existe');
        }

        self::redirect(self::RUTA . ($cata->eliminar() ? '?aviso=eliminada' : '?aviso=error-eliminar'));
    }

    /** Alta y edición comparten formulario, validación y persistencia. */
    private static function formulario(Cata $cata, string $titulo, string $accion, string $aviso): void
    {
        $alertas = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
                Cata::setAlerta('error', 'La sesión expiró. Vuelve a enviar el formulario.');
                $alertas = Cata::getAlertas();
            } else {
                self::asignarDatos($cata);
                $resultado = CataService::guardar($cata);

                if ($resultado['ok']) {
                    self::redirect(self::RUTA . '?aviso=' . $aviso);
                }

                // El formulario se repinta con lo que el usuario escribió: se
                // pierde menos captura que devolviéndolo en blanco.
                Cata::setAlerta('error', $resultado['mensaje']);
                $alertas = Cata::getAlertas();
            }
        }

        self::render('catas/form', [
            'title' => $titulo,
            'topbarSection' => 'Catas / ' . $titulo,
            'cata' => $cata,
            'accion' => $accion,
            'alertas' => $alertas,
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    private static function asignarDatos(Cata $cata): void
    {
        $cata->titulo = trim((string)($_POST['titulo'] ?? ''));
        $cata->descripcion = trim((string)($_POST['descripcion'] ?? '')) ?: null;
        $cata->fecha = trim((string)($_POST['fecha'] ?? ''));
        $cata->hora = trim((string)($_POST['hora'] ?? ''));
        $cata->duracion_min = (int)($_POST['duracion_min'] ?? 90);
        $cata->precio = (float)($_POST['precio'] ?? 0);
        $cata->disponible = isset($_POST['disponible']) ? 1 : 0;
    }

    /**
     * Devuelve al mismo filtro desde el que se pulsó el interruptor. Sin esto,
     * apagar una cata mientras se mira «Disponibles» tiraba la lista entera de
     * vuelta a «Todas» y había que volver a filtrar en cada cambio.
     *
     * Cierra siempre en `?…&` o en `?` para que quien llama sólo tenga que
     * concatenar su `aviso=`.
     */
    private static function filtrosDeVuelta(): string
    {
        $filtros = [];

        $disponibilidad = (string)($_POST['volver_disponible'] ?? '');
        if (in_array($disponibilidad, ['0', '1'], true)) {
            $filtros['disponible'] = $disponibilidad;
        }

        $busqueda = trim((string)($_POST['volver_q'] ?? ''));
        if ($busqueda !== '') {
            $filtros['q'] = $busqueda;
        }

        return $filtros ? '?' . http_build_query($filtros) . '&' : '?';
    }

    /**
     * Traduce `?aviso=` al formato que espera views/admin/partials/alertas.php.
     *
     * @return array<string, array<int, string>>
     */
    private static function avisos(): array
    {
        $clave = (string)($_GET['aviso'] ?? '');

        if (!isset(self::AVISOS[$clave])) {
            return [];
        }

        [$tipo, $mensaje] = self::AVISOS[$clave];
        return [$tipo => [$mensaje]];
    }

    private static function render(string $vista, array $datos = []): void
    {
        AdminController::render($vista, array_merge([
            'activeModule' => 'catas',
            'styles' => [self::CSS],
            'scripts' => [self::JS],
        ], $datos));
    }

    private static function exigirPost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::RUTA);
        }
    }

    private static function validarId(mixed $id): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!$id) {
            self::redirect(self::RUTA . '?aviso=id-invalido');
        }

        return (int)$id;
    }

    /** `never` es lo que permite dar por buenas las comprobaciones previas. */
    private static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
