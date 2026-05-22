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

$leadId  = date('YmdHis');
$resumen = $empresa ? "Empresa: {$empresa}\n\n{$mensaje}" : $mensaje;

$waSuccess = send_whatsapp(
    $leadId,
    $nombre,
    $telefono ?: 'No proporcionado',
    $email,
    $servicio ?: 'No especificado',
    $resumen
);

echo json_encode([
    'success' => true,
    'message' => '¡Mensaje recibido! Te contactaremos a la brevedad.',
    'wa_sent' => $waSuccess
]);

function send_whatsapp(string $id, string $nombre, string $celular, string $correo, string $servicio, string $resumen): bool {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => WA_TO,
        'type'              => 'template',
        'template'          => [
            'name'       => 'nuevo_lead_siqat',
            'language'   => ['code' => 'es_AR'],
            'components' => [
                [
                    'type'       => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $id],
                        ['type' => 'text', 'text' => $nombre],
                        ['type' => 'text', 'text' => $celular],
                        ['type' => 'text', 'text' => $correo],
                        ['type' => 'text', 'text' => $servicio],
                        ['type' => 'text', 'text' => $resumen],
                    ]
                ]
            ]
        ]
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
        log_wa_error($curlError, $httpCode, $response, $id);
        return false;
    }
    return true;
}

function log_wa_error(string $curlErr, int $code, string $response, string $ref): void {
    $logDir  = dirname(__DIR__) . '/data';
    $logFile = $logDir . '/wa_errors.log';
    if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
    $entry  = '[' . date('Y-m-d H:i:s') . '] HTTP ' . $code . ' | cURL: ' . $curlErr . "\n";
    $entry .= 'Response: ' . substr($response, 0, 500) . "\n";
    $entry .= 'Lead ID: ' . $ref . "\n---\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}
