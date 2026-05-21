<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método no permitido']); exit; }

require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);

$nombre   = trim($input['nombre'] ?? '');
$email    = trim($input['email'] ?? '');
$telefono = trim($input['telefono'] ?? '');
$empresa  = trim($input['empresa'] ?? '');
$servicio = trim($input['servicio'] ?? '');
$resumen  = trim($input['resumen'] ?? '');
$conversacion = $input['conversacion'] ?? [];

if (empty($nombre) || empty($email)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nombre y email son requeridos']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email inválido']);
    exit;
}

$nombre   = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
$email    = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$telefono = htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8');
$empresa  = htmlspecialchars($empresa, ENT_QUOTES, 'UTF-8');
$servicio = htmlspecialchars($servicio, ENT_QUOTES, 'UTF-8');
$resumen  = htmlspecialchars($resumen, ENT_QUOTES, 'UTF-8');

// ── Init SQLite ──
$dataDir = dirname(DB_PATH);
if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS tickets (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at   TEXT    DEFAULT (datetime('now', 'localtime')),
        nombre       TEXT    NOT NULL,
        email        TEXT,
        telefono     TEXT,
        empresa      TEXT,
        servicio     TEXT,
        resumen      TEXT,
        conversacion TEXT,
        estado       TEXT    DEFAULT 'nuevo',
        notas        TEXT,
        ip           TEXT
    )");

    $convJson = json_encode($conversacion, JSON_UNESCAPED_UNICODE);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $db->prepare("INSERT INTO tickets (nombre, email, telefono, empresa, servicio, resumen, conversacion, ip)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nombre, $email, $telefono, $empresa, $servicio, $resumen, $convJson, $ip]);
    $ticketId = $db->lastInsertId();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al guardar el ticket']);
    exit;
}

// ── WhatsApp notification ──
$waMsg  = "🎫 *Nuevo Ticket #" . $ticketId . " - PREXAcode*\n\n";
$waMsg .= "👤 *Nombre:* " . $nombre . "\n";
$waMsg .= "📧 *Email:* " . $email . "\n";
if ($telefono) $waMsg .= "📱 *Teléfono:* " . $telefono . "\n";
if ($empresa)  $waMsg .= "🏢 *Empresa:* " . $empresa . "\n";
if ($servicio) $waMsg .= "⚙️ *Servicio:* " . $servicio . "\n";
if ($resumen)  $waMsg .= "\n💬 *Resumen:*\n" . $resumen . "\n";
$waMsg .= "\n⏰ " . date('d/m/Y H:i') . " (Argentina)";
$waMsg .= "\n\n🔗 Ver en panel: " . COMPANY_DOMAIN . "/admin/";

$waPayload = [
    'messaging_product' => 'whatsapp',
    'to'   => WA_TO,
    'type' => 'text',
    'text' => ['body' => $waMsg]
];

$ch = curl_init('https://graph.facebook.com/v21.0/' . WA_PHONE_NUMBER_ID . '/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_TOKEN],
    CURLOPT_POSTFIELDS     => json_encode($waPayload),
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$waResp     = curl_exec($ch);
$waHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$waCurlErr  = curl_error($ch);
curl_close($ch);

// Log si falla
if ($waCurlErr || $waHttpCode !== 200) {
    $logFile = dirname(DB_PATH) . '/wa_errors.log';
    $entry   = '[' . date('Y-m-d H:i:s') . '] ticket #' . $ticketId . ' | HTTP ' . $waHttpCode . ' | cURL: ' . $waCurlErr . "\n";
    $entry  .= 'Response: ' . substr($waResp, 0, 500) . "\n---\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

echo json_encode([
    'success'   => true,
    'ticket_id' => (int)$ticketId,
    'message'   => "¡Solicitud enviada! Tu número de ticket es #" . $ticketId . ". Te contactaremos a la brevedad."
]);
