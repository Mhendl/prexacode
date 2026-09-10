<?php
require_once __DIR__ . '/_bootstrap.php';

api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Metodo no permitido');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) api_error(400, 'Datos invalidos');

$nombre       = trim($input['nombre']   ?? '');
$email        = trim($input['email']    ?? '');
$telefono     = trim($input['telefono'] ?? '');
$empresa      = trim($input['empresa']  ?? '');
$servicio     = trim($input['servicio'] ?? '');
$resumen      = trim($input['resumen']  ?? '');
$conversacion = is_array($input['conversacion'] ?? null) ? $input['conversacion'] : [];
$sessionId    = preg_replace('/[^a-zA-Z0-9\-_]/', '', $input['session_id'] ?? '');

if ($nombre === '' || $email === '')                 api_error(400, 'Nombre y email son requeridos');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))      api_error(400, 'Email invalido');
if (mb_strlen($nombre) > 120 || mb_strlen($email) > 190) api_error(400, 'Datos demasiado largos');

try {
    $db = db_connect();
} catch (Exception $e) {
    api_error(500, 'Error al guardar el ticket');
}

// Máximo 5 solicitudes por hora desde la misma IP
if (!rate_limit_ok($db, 'ticket', 5, 3600)) {
    api_error(429, 'Recibimos varias solicitudes tuyas. Te contactamos a la brevedad.');
}

// Se guarda en crudo: el escapado corresponde al renderizar (panel admin)
try {
    $stmt = $db->prepare("INSERT INTO tickets (nombre, email, telefono, empresa, servicio, resumen, conversacion, ip)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $nombre,
        $email,
        $telefono,
        $empresa,
        $servicio,
        $resumen,
        json_encode($conversacion, JSON_UNESCAPED_UNICODE),
        client_ip(),
    ]);
    $ticketId = (int)$db->lastInsertId();
} catch (Exception $e) {
    api_error(500, 'Error al guardar el ticket');
}

// Marcar la conversación de origen como lead calificado
if ($sessionId !== '') {
    try {
        $db->prepare("UPDATE conversations SET is_lead = 1 WHERE session_id = ?")->execute([$sessionId]);
    } catch (Exception $e) {
        // No es crítico: el ticket ya quedó guardado
    }
}

wa_enviar_lead(
    (string)$ticketId,
    $nombre,
    $telefono ?: 'No proporcionado',
    $email,
    $servicio ?: 'Chat asistente IA - PREXAcode',
    $resumen  ?: 'Sin resumen'
);

echo json_encode([
    'success'   => true,
    'ticket_id' => $ticketId,
    'message'   => 'Solicitud enviada! Tu numero de ticket es #' . $ticketId . '. Te contactaremos a la brevedad.',
], JSON_UNESCAPED_UNICODE);
