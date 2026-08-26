<?php

/**
 * Módulo de Catas del panel: programación de sesiones y gestión de inscritos.
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
use Model\CataInscripcion;
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
        'inscripcion-actualizada' => ['exito', 'Inscripción actualizada'],
        'no-existe' => ['error', 'La cata no existe'],
        'id-invalido' => ['error', 'Identificador no válido'],
        'sesion-expirada' => ['error', 'La sesión expiró. Vuelve a intentarlo.'],
        'error-eliminar' => ['error', 'No se pudo eliminar la cata'],
        'error-inscripcion' => ['error', 'No se pudo actualizar la inscripción'],
    ];

    public static function index(Router $router): void
    {
        $estado = (string)($_GET['estado'] ?? '');
        $busqueda = (string)($_GET['q'] ?? '');

        self::render('catas/index', [
            'title' => 'Catas',
            'topbarSection' => 'Catas',
            'catas' => CataService::listaAdministrativa($estado, $busqueda),
            'estadoActivo' => $estado,
            'busqueda' => $busqueda,
            'estados' => Cata::ESTADOS,
            'alertas' => self::avisos(),
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    public static function create(Router $router): void
    {
        self::formulario(new Cata(), 'Nueva cata', 'Crear', 'creada');
    }

    public static function edit(Router $router): void
    {
        $cata = Cata::find(self::validarId($_GET['id'] ?? $_POST['id'] ?? null));

        if (!$cata) {
            self::redirect(self::RUTA . '?aviso=no-existe');
        }

        // El <input type="time"> no acepta los segundos que devuelve la BD.
        $cata->hora = substr((string)$cata->hora, 0, 5);

        self::formulario($cata, 'Editar cata', 'Actualizar', 'actualizada');
    }

    /** Detalle con la lista de inscritos y el control de su estado. */
    public static function show(Router $router): void
    {
        $id = self::validarId($_GET['id'] ?? null);
        $cata = Cata::find($id);

        if (!$cata) {
            self::redirect(self::RUTA . '?aviso=no-existe');
        }

        self::render('catas/show', [
            'title' => $cata->titulo,
            'topbarSection' => 'Catas / ' . $cata->titulo,
            'cata' => $cata,
            'inscripciones' => CataService::inscripcionesDe($id),
            'lugaresTomados' => CataService::lugaresTomados($id),
            'lugaresDisponibles' => CataService::lugaresDisponibles($id),
            'estadosInscripcion' => CataInscripcion::ESTADOS,
            'adminCsrfToken' => AdminCsrfService::token(),
            'alertas' => self::avisos(),
        ]);
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

        // Las inscripciones caen con ella por el ON DELETE CASCADE.
        self::redirect(self::RUTA . ($cata->eliminar() ? '?aviso=eliminada' : '?aviso=error-eliminar'));
    }

    public static function estadoInscripcion(Router $router): void
    {
        self::exigirPost();

        $cataId = self::validarId($_POST['cata_id'] ?? null);
        $destino = self::RUTA . '/detalle?id=' . $cataId;

        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::redirect($destino . '&aviso=sesion-expirada');
        }

        $resultado = CataService::cambiarEstadoInscripcion(
            self::validarId($_POST['inscripcion_id'] ?? null),
            (string)($_POST['estado'] ?? '')
        );

        self::redirect($destino . ($resultado['ok']
            ? '&aviso=inscripcion-actualizada'
            : '&aviso=error-inscripcion'));
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
            'estados' => Cata::ESTADOS,
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
        $cata->cupo = (int)($_POST['cupo'] ?? 12);
        $cata->precio = (float)($_POST['precio'] ?? 0);
        $cata->imagen = trim((string)($_POST['imagen'] ?? '')) ?: null;

        $estado = (string)($_POST['estado'] ?? 'borrador');
        $cata->estado = in_array($estado, Cata::ESTADOS, true) ? $estado : 'borrador';
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
