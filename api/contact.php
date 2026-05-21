<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

require_once '../config.php';

$input    = json_decode(file_get_contents('php://input'), true);
$nombre   = trim($input['nombre'] ?? '');
$empresa  = trim($input['empresa'] ?? '');
$email    = trim($input['email'] ?? '');
$telefono = trim($input['telefono'] ?? '');
$servicio = trim($input['servicio'] ?? '');
$mensaje  = trim($input['mensaje'] ?? '');
$origen   = trim($input['origen'] ?? 'formulario');

if (empty($nombre) || empty($email) || empty($mensaje)) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan campos requeridos']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'El email no es válido']);
    exit;
}

$nombre   = htmlspecialchars($nombre,   ENT_QUOTES, 'UTF-8');
$empresa  = htmlspecialchars($empresa,  ENT_QUOTES, 'UTF-8');
$email    = htmlspecialchars($email,    ENT_QUOTES, 'UTF-8');
$telefono = htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8');
$servicio = htmlspecialchars($servicio, ENT_QUOTES, 'UTF-8');
$mensaje  = htmlspecialchars($mensaje,  ENT_QUOTES, 'UTF-8');

$waMessage  = "🚀 *Nuevo lead desde PREXAcode*\n\n";
$waMessage .= "📋 *Origen:* " . ucfirst($origen) . "\n";
$waMessage .= "👤 *Nombre:* " . $nombre . "\n";
if ($empresa)  $waMessage .= "🏢 *Empresa:* " . $empresa . "\n";
$waMessage .= "📧 *Email:* " . $email . "\n";
if ($telefono) $waMessage .= "📱 *Teléfono:* " . $telefono . "\n";
if ($servicio) $waMessage .= "⚙️ *Servicio:* " . $servicio . "\n";
$waMessage .= "\n💬 *Mensaje:*\n" . $mensaje;
$waMessage .= "\n\n⏰ " . date('d/m/Y H:i') . " (Argentina)";

$waSuccess = send_whatsapp($waMessage);

echo json_encode([
    'success' => true,
    'message' => '¡Mensaje recibido! Te contactaremos a la brevedad.',
    'wa_sent' => $waSuccess
]);

function send_whatsapp(string $msg): bool {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'   => WA_TO,
        'type' => 'text',
        'text' => ['body' => $msg]
    ];

    $ch = curl_init('https://graph.facebook.com/v21.0/' . WA_PHONE_NUMBER_ID . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_TOKEN
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        log_wa_error($curlError, $httpCode, $response, $msg);
        return false;
    }
    return true;
}

function log_wa_error(string $curlErr, int $code, string $response, string $msg): void {
    $logDir  = dirname(__DIR__) . '/data';
    $logFile = $logDir . '/wa_errors.log';
    if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
    $entry  = '[' . date('Y-m-d H:i:s') . '] HTTP ' . $code . ' | cURL: ' . $curlErr . "\n";
    $entry .= 'Response: ' . substr($response, 0, 500) . "\n";
    $entry .= 'Message snippet: ' . substr($msg, 0, 100) . "\n---\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}
