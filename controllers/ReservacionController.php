<?php

namespace Controllers;

use Model\Reservacion;
use Model\DiaReservacion;
use MVC\Router;

class ReservacionController
{

    public static function crear(Router $router)
    {
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

        $diaSemana = (int) date('w', strtotime($reservacion->fecha));
        $dia = DiaReservacion::where('dia_semana', $diaSemana);

        if (!$dia || !$dia->activo) {
            echo json_encode(['ok' => false, 'msg' => 'No hay servicio ese día']);
            return;
        }

        $horaFormato = Reservacion::escaparString(date('H:i:s', strtotime($reservacion->hora)));
        $query = "SELECT id FROM horarios_reservacion WHERE dia_id = {$dia->id} AND hora = '{$horaFormato}' LIMIT 1";
        $resultado = Reservacion::consultarSQL($query);

        if (empty($resultado)) {
            echo json_encode(['ok' => false, 'msg' => 'Horario no disponible para ese día']);
            return;
        }

        $guardado = $reservacion->guardar();

        if (!$guardado || !$guardado['resultado']) {
            echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar la reservación. Intenta de nuevo.']);
            return;
        }

        $reservaId  = (int) $guardado['id'];
        $fecha      = $reservacion->fecha;
        $hora       = $reservacion->hora;
        $comensales = (int) $reservacion->comensales;

        $mesa1Nombre = '';
        $mesa2Nombre = '';
        $mesasNombres = [];
        $warning = null;
        $requiereConfirmacion = false;

        $mesasDisponibles = Reservacion::obtenerMesasDisponibles($fecha, $hora, $reservaId);
        $mesasSeleccionadas = Reservacion::seleccionarMesasParaComensales($mesasDisponibles, $comensales);

        if (empty($mesasSeleccionadas)) {
            Reservacion::limpiarMesasAsignadas($reservaId);
            $requiereConfirmacion = true;
            $warning = 'Solicitud recibida. Confirmaremos la disponibilidad de mesa para este horario.';
        } else {
            $mesaIds = array_map(function ($mesa) {
                return (int)$mesa->id;
            }, $mesasSeleccionadas);

            Reservacion::asignarMesas($reservaId, $mesaIds);
            $mesasAsignadas = Reservacion::obtenerMesasAsignadas($reservaId);
            $mesasNombres = array_map(function ($mesa) {
                return $mesa->nombre;
            }, $mesasAsignadas);

            $mesa1Nombre = $mesasNombres[0] ?? '';
            $mesa2Nombre = $mesasNombres[1] ?? '';
        }

        $respuesta = [
            'ok'    => true,
            'id'    => $reservaId,
            'mesa'  => $mesa1Nombre,
            'mesa2' => $mesa2Nombre,
            'mesas' => $mesasNombres,
            'requiere_confirmacion' => $requiereConfirmacion,
        ];

        if ($warning) {
            $respuesta['warning'] = $warning;
        }

        echo json_encode($respuesta);
    }
}
