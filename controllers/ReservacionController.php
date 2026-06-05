<?php
namespace Controllers;

use Model\Reservacion;
use Model\DiaReservacion;
use MVC\Router;

class ReservacionController {

    public static function crear(Router $router) {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
            return;
        }

        $reservacion = new Reservacion();
        $reservacion->sincronizar($_POST);

        $alertas = $reservacion->validar();

        if (!empty($alertas['error'])) {
            echo json_encode(['ok' => false, 'msg' => $alertas['error'][0]]);
            return;
        }

        // Validar que la hora esté disponible para el día de la semana de la fecha
        $diaSemana = (int) date('w', strtotime($reservacion->fecha));
        $dia = DiaReservacion::where('dia_semana', $diaSemana);

        if (!$dia || !$dia->activo) {
            echo json_encode(['ok' => false, 'msg' => 'No hay servicio ese día']);
            return;
        }

        $horaFormato = date('H:i:s', strtotime($reservacion->hora));
        $query = "SELECT id FROM horarios_reservacion WHERE dia_id = {$dia->id} AND hora = '{$horaFormato}' LIMIT 1";
        $resultado = Reservacion::consultarSQL($query);

        if (empty($resultado)) {
            echo json_encode(['ok' => false, 'msg' => 'Horario no disponible para ese día']);
            return;
        }

        $guardado = $reservacion->guardar();

        if ($guardado && $guardado['resultado']) {
            echo json_encode(['ok' => true, 'id' => $guardado['id']]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar la reservación. Intenta de nuevo.']);
        }
    }
}
