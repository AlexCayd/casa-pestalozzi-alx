<?php
namespace Controllers;

use MVC\Router;
use Model\Ticket;
use Services\AreasMejora;

class FeedbackController {

    // GET /feedback
    public static function index(Router $router) {
        $token       = isset($_GET['token']) ? trim($_GET['token']) : null;
        $tokenData   = null;
        $yaRespondio = false;

        if ($token && preg_match('/^[a-f0-9]{32}$/', $token)) {
            $tokenEsc = Ticket::escaparString($token);
            $result   = Ticket::ejecutarSQL(
                "SELECT ft.id, ft.ticket_id, ft.usado, t.nombre
                   FROM feedback_tokens ft
                   JOIN tickets t ON t.id = ft.ticket_id
                  WHERE ft.token = '{$tokenEsc}'
                  LIMIT 1"
            );
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row && (int)($row['usado'] ?? 0) === 1) {
                    $yaRespondio = true;
                }
            } else {
                $token = null;
            }
        } else {
            $token = null;
        }

        include_once __DIR__ . '/../views/feedback/index.php';
    }

    // POST /api/feedback
    public static function guardar(Router $router) {
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        $campos = ['calidad_sabor', 'atencion_mesero', 'tiempo_espera', 'experiencia_global'];
        $valores = [];
        foreach ($campos as $campo) {
            $v = isset($data[$campo]) ? (int)$data[$campo] : 0;
            if ($v < 1 || $v > 5) {
                echo json_encode(['ok' => false, 'msg' => 'Valoración inválida en ' . $campo]);
                return;
            }
            $valores[$campo] = $v;
        }

        $comentario = isset($data['comentario']) ? trim($data['comentario']) : '';
        $token      = isset($data['token'])      ? trim($data['token'])      : '';

        $tokenId  = 'NULL';
        $ticketId = 'NULL';

        if ($token && preg_match('/^[a-f0-9]{32}$/', $token)) {
            $tokenEsc = Ticket::escaparString($token);
            $result   = Ticket::ejecutarSQL(
                "SELECT id, ticket_id, usado FROM feedback_tokens WHERE token = '{$tokenEsc}' LIMIT 1"
            );
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row && (int)($row['usado'] ?? 1) === 0) {
                    $tokenId  = (int)($row['id'] ?? 0);
                    $ticketId = (int)($row['ticket_id'] ?? 0);
                }
            }
        }

        $cs  = $valores['calidad_sabor'];
        $am  = $valores['atencion_mesero'];
        $te  = $valores['tiempo_espera'];
        $eg  = $valores['experiencia_global'];
        $com = $comentario !== '' ? "'" . Ticket::escaparString($comentario) . "'" : 'NULL';

        try {
            Ticket::ejecutarSQL(
                "INSERT INTO feedback (token_id, ticket_id, calidad_sabor, atencion_mesero, tiempo_espera, experiencia_global, comentario)
                 VALUES ({$tokenId}, {$ticketId}, {$cs}, {$am}, {$te}, {$eg}, {$com})"
            );

            if ($tokenId !== 'NULL') {
                Ticket::ejecutarSQL("UPDATE feedback_tokens SET usado = 1 WHERE id = {$tokenId}");
            }

            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Error al guardar: ' . $e->getMessage()]);
        }
    }

    // POST /api/feedback-n8n
    // Endpoint publico que consume el flujo de n8n: recibe el JSON de "areas
    // de mejora" generado por el modelo y lo persiste para el panel admin.
    public static function recibirN8n(Router $router) {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Metodo no permitido']);
            return;
        }

        // Secreto compartido opcional (env N8N_SECRET): si esta configurado,
        // n8n debe mandarlo en el header X-N8N-Secret.
        $secret = AreasMejora::secret();
        if ($secret !== '') {
            $recibido = $_SERVER['HTTP_X_N8N_SECRET'] ?? '';
            if (!hash_equals($secret, $recibido)) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
                return;
            }
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'JSON invalido']);
            return;
        }

        try {
            $total = AreasMejora::guardar($payload);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Error al guardar: ' . $e->getMessage()]);
            return;
        }

        if ($total === 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'msg' => 'No se encontraron areas_de_mejora en el payload']);
            return;
        }

        http_response_code(200);
        echo json_encode(['ok' => true, 'guardadas' => $total]);
    }
}
