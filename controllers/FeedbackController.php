<?php
namespace Controllers;

use MVC\Router;
use Model\Ticket;
use Services\Analytics\AreasMejora;

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
                  WHERE ft.token = '{$tokenEsc}'" . Ticket::filtroUnidad('ft') . Ticket::filtroUnidad('t') . "
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

        // Vuelta del envío SIN JavaScript: guardar() redirige aquí con una de
        // las dos marcas. Con token, una reseña guardada ya sale como
        // $yaRespondio; `enviada` cubre la encuesta abierta sin token.
        $enviada    = isset($_GET['enviada']);
        $errorEnvio = isset($_GET['error']);

        include_once __DIR__ . '/../views/feedback/index.php';
    }

    // POST /api/feedback
    //
    // Dos clientes. feedback.js manda JSON y espera JSON. El formulario de la
    // vista, cuando el bundle no corre, se envía solo como
    // application/x-www-form-urlencoded: a ése se le contesta con un 303 de
    // vuelta a /feedback, porque un JSON en pantalla dejaría al comensal ante
    // `{"ok":true}`.
    public static function guardar(Router $router) {
        $esFormulario = stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false
            && !empty($_POST);

        $data = $esFormulario
            ? $_POST
            : (json_decode(file_get_contents('php://input'), true) ?: []);
        if (!is_array($data)) {
            $data = [];
        }

        // is_string: un `comentario[]=` o un token en array llegaría a trim()
        // como array, y en PHP 8 eso es un TypeError y no un 'ok' => false.
        $comentario = is_string($data['comentario'] ?? null) ? trim($data['comentario']) : '';
        $token      = is_string($data['token'] ?? null)      ? trim($data['token'])      : '';

        $responder = static function (bool $ok, string $msg = '') use ($esFormulario, $token): void {
            if ($esFormulario) {
                $query = [];
                if (preg_match('/^[a-f0-9]{32}$/', $token)) {
                    $query['token'] = $token;
                }
                $query[$ok ? 'enviada' : 'error'] = 1;
                header('Location: /feedback?' . http_build_query($query), true, 303);
                return;
            }
            header('Content-Type: application/json');
            echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'msg' => $msg]);
        };

        $campos = ['calidad_sabor', 'atencion_mesero', 'tiempo_espera', 'experiencia_global'];
        $valores = [];
        foreach ($campos as $campo) {
            $v = isset($data[$campo]) && is_scalar($data[$campo]) ? (int)$data[$campo] : 0;
            if ($v < 1 || $v > 5) {
                $responder(false, 'Valoración inválida en ' . $campo);
                return;
            }
            $valores[$campo] = $v;
        }

        $tokenId  = 'NULL';
        $ticketId = 'NULL';

        // Una encuesta CON token es de un ticket concreto y se responde una
        // sola vez. Antes, un token ya usado (o desconocido) se guardaba igual
        // como reseña anónima: reenviar el formulario —o abrir dos veces el
        // enlace del POS— duplicaba la opinión. Sin token sigue siendo la
        // encuesta abierta, anónima.
        if ($token !== '') {
            if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
                $responder(false, 'El enlace de la encuesta no es válido.');
                return;
            }
            $tokenEsc = Ticket::escaparString($token);
            $result   = Ticket::ejecutarSQL(
                "SELECT id, ticket_id, usado FROM feedback_tokens WHERE token = '{$tokenEsc}'" . Ticket::filtroUnidad() . " LIMIT 1"
            );
            $row = ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
            if (!$row) {
                $responder(false, 'El enlace de la encuesta no es válido.');
                return;
            }

            // Se aparta el token ANTES de insertar y sólo si seguía libre: dos
            // envíos simultáneos con el mismo token no pueden pasar los dos.
            $tokenId = (int) ($row['id'] ?? 0);
            Ticket::ejecutarSQL("UPDATE feedback_tokens SET usado = 1 WHERE id = {$tokenId} AND usado = 0" . Ticket::filtroUnidad());
            if (Ticket::getDB()->affected_rows < 1) {
                $responder(false, 'Esta encuesta ya se respondió. ¡Gracias!');
                return;
            }
            $ticketId = (int) ($row['ticket_id'] ?? 0);
        }

        $cs  = $valores['calidad_sabor'];
        $am  = $valores['atencion_mesero'];
        $te  = $valores['tiempo_espera'];
        $eg  = $valores['experiencia_global'];
        $com = $comentario !== '' ? "'" . Ticket::escaparString($comentario) . "'" : 'NULL';

        try {
            Ticket::ejecutarSQL(
                "INSERT INTO feedback (" . (\Services\Security\ContextoNegocio::nuevo()?'unidad_id,':'') . "token_id, ticket_id, calidad_sabor, atencion_mesero, tiempo_espera, experiencia_global, comentario)
                 VALUES (" . (\Services\Security\ContextoNegocio::nuevo()?Ticket::unidadRestaurante().',':'') . "{$tokenId}, {$ticketId}, {$cs}, {$am}, {$te}, {$eg}, {$com})"
            );

            $responder(true);
        } catch (\Throwable $e) {
            // La reseña no entró: se libera el token para que el comensal
            // pueda reintentar con el mismo enlace.
            if ($tokenId !== 'NULL') {
                try {
                    Ticket::ejecutarSQL("UPDATE feedback_tokens SET usado = 0 WHERE id = {$tokenId}" . Ticket::filtroUnidad());
                } catch (\Throwable $ignorada) {
                }
            }
            // El mensaje de la excepción es SQL y rutas: va al log, no a la
            // tablet de un comensal en una ruta pública.
            error_log('[feedback] No se guardó la reseña: ' . $e->getMessage());
            $responder(false, 'No pudimos guardar tu reseña.');
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
            if (\Services\Security\ContextoNegocio::nuevo()) {
                // La integración existente corresponde exclusivamente a Restaurante.
                $unidad = $payload['unidad_id'] ?? null;
                $contexto = $payload['contexto'] ?? 'restaurante';
                if ($contexto !== 'restaurante' || ($unidad !== null && (int)$unidad !== Ticket::unidadRestaurante())) {
                    http_response_code(422);
                    echo json_encode(['ok'=>false,'msg'=>'Ámbito de integración inválido']);
                    return;
                }
                $total = \Services\Security\ContextoNegocio::en('restaurante',static fn()=>AreasMejora::guardar($payload));
            } else {
                $total = AreasMejora::guardar($payload);
            }
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
