<?php
namespace Controllers;

use Model\Reservacion;
use Model\DiaReservacion;
use Model\Mesa;
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

        if (!$guardado || !$guardado['resultado']) {
            echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar la reservación. Intenta de nuevo.']);
            return;
        }

        $reservaId  = $guardado['id'];
        $fecha      = $reservacion->fecha;
        $comensales = (int) $reservacion->comensales;

        // Minutos desde medianoche de la hora solicitada
        $horaParts  = explode(':', $reservacion->hora);
        $horaMin    = (int)$horaParts[0] * 60 + (int)$horaParts[1];

        // Mesas reservables disponibles
        $todasMesas = Mesa::consultarSQL(
            "SELECT id, numero, nombre FROM mesas WHERE reservable = 1 AND activo = 1 ORDER BY numero ASC"
        );

        // Reservaciones del mismo día con mesa asignada (excluir la recién creada)
        $reservasDelDia = Reservacion::consultarSQL(
            "SELECT mesa_id, mesa_secundaria_id, hora FROM reservaciones
             WHERE fecha = '{$fecha}' AND id != {$reservaId} AND mesa_id IS NOT NULL"
        );

        // Determinar mesas ocupadas en la ventana de la nueva reservación
        // Ventana de conflicto: [hora_existente - 30min, hora_existente + 90min]
        $ocupadas = [];
        foreach ($reservasDelDia as $r) {
            $rParts  = explode(':', $r->hora);
            $rMin    = (int)$rParts[0] * 60 + (int)$rParts[1];
            $rInicio = $rMin - 30;
            $rFin    = $rMin + 90;

            if ($horaMin >= $rInicio && $horaMin < $rFin) {
                if ($r->mesa_id)            $ocupadas[] = (int)$r->mesa_id;
                if ($r->mesa_secundaria_id) $ocupadas[] = (int)$r->mesa_secundaria_id;
            }
        }

        // Filtrar mesas disponibles
        $disponibles = [];
        foreach ($todasMesas as $m) {
            if (!in_array((int)$m->id, $ocupadas)) {
                $disponibles[] = $m;
            }
        }

        $mesa1Id    = null;
        $mesa2Id    = null;
        $mesa1Nombre = '';
        $mesa2Nombre = '';

        if ($comensales > 4) {
            // Intentar pares prioritarios antes del fallback genérico
            $paresPrioridad = [[2,4],[5,11],[10,11],[8,9]]; // números de mesa
            $asignado = false;
            foreach ($paresPrioridad as $par) {
                $m1 = null; $m2 = null;
                foreach ($disponibles as $m) {
                    if ((int)$m->numero === $par[0]) $m1 = $m;
                    if ((int)$m->numero === $par[1]) $m2 = $m;
                }
                if ($m1 && $m2) {
                    $mesa1Id     = (int)$m1->id;
                    $mesa2Id     = (int)$m2->id;
                    $mesa1Nombre = $m1->nombre;
                    $mesa2Nombre = $m2->nombre;
                    $asignado    = true;
                    break;
                }
            }
            if (!$asignado) {
                if (count($disponibles) >= 2) {
                    $mesa1Id     = (int)$disponibles[0]->id;
                    $mesa2Id     = (int)$disponibles[1]->id;
                    $mesa1Nombre = $disponibles[0]->nombre;
                    $mesa2Nombre = $disponibles[1]->nombre;
                } elseif (count($disponibles) === 1) {
                    $mesa1Id     = (int)$disponibles[0]->id;
                    $mesa1Nombre = $disponibles[0]->nombre;
                }
            }
        } else {
            if (count($disponibles) >= 1) {
                $mesa1Id     = (int)$disponibles[0]->id;
                $mesa1Nombre = $disponibles[0]->nombre;
            }
        }

        if ($mesa1Id) {
            $mesa2Val = $mesa2Id ? $mesa2Id : 'NULL';
            Reservacion::ejecutarSQL(
                "UPDATE reservaciones SET mesa_id = {$mesa1Id}, mesa_secundaria_id = {$mesa2Val} WHERE id = {$reservaId}"
            );
        }

        echo json_encode([
            'ok'    => true,
            'id'    => $reservaId,
            'mesa'  => $mesa1Nombre,
            'mesa2' => $mesa2Nombre,
        ]);
    }
}
