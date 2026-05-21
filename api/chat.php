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

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['messages']) || !is_array($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos']);
    exit;
}

$sessionId = preg_replace('/[^a-zA-Z0-9\-_]/', '', $input['session_id'] ?? '');
$ip        = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$systemPrompt = [
    'role' => 'system',
    'content' => 'Eres el asistente virtual de PREXAcode, una empresa argentina especializada en desarrollo de software a medida y agentes de Inteligencia Artificial para negocios.

Tus objetivos:
1. Responder preguntas sobre los servicios de PREXAcode
2. Calificar leads y entender las necesidades del cliente
3. Generar una solicitud formal cuando el usuario muestre interés concreto

Servicios que ofrecemos:
- Desarrollo de Software a Medida: aplicaciones web, sistemas de gestión, CRM, e-commerce, APIs
- Agentes de IA: chatbots inteligentes, automatización de atención al cliente, captación de leads, automatización de procesos
- Automatización: flujos de trabajo, integración con herramientas existentes (CRM, WhatsApp, email)
- Consultoría tecnológica

Proceso de trabajo: Análisis → Diseño → Desarrollo → Implementación y soporte

FLUJO DE CONVERSACIÓN:
1. Saluda y preguntá en qué podés ayudar
2. Escuchá la necesidad del usuario, hacé preguntas para entender bien el proyecto
3. Cuando el usuario describa un proyecto concreto o pregunte cómo contratar, decí algo como "Perfecto, te genero una solicitud formal para que nuestro equipo se contacte con vos" y al FINAL de tu mensaje agregá exactamente el texto [[FORM]] (sin espacios ni texto después)

CUÁNDO AGREGAR [[FORM]]:
- El usuario describió un proyecto o necesidad concreta
- El usuario pregunta cómo contratar, cuánto cuesta, o cómo empezar
- Después de 3 o más mensajes donde el usuario muestra interés real
- El usuario dice que quiere una propuesta o presupuesto

IMPORTANTE: [[FORM]] debe ir al FINAL del mensaje, en una línea aparte, SOLO CUANDO corresponda. No lo agregues en preguntas generales ni en la bienvenida.

Siempre respondé en español, tono profesional y amigable. Sé conciso. No inventes precios ni plazos. Si preguntan precios, decí que depende del proyecto y ofrecé una consulta gratuita.'
];

$messages = array_merge([$systemPrompt], array_slice($input['messages'], -10));

$payload = [
    'model'       => 'gpt-4o-mini',
    'messages'    => $messages,
    'max_tokens'  => 500,
    'temperature' => 0.7
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    save_conversation($sessionId, $input['messages'], null, $ip, $userAgent);
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión con el servicio de IA']);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
    save_conversation($sessionId, $input['messages'], null, $ip, $userAgent);
    http_response_code(500);
    echo json_encode(['error' => 'Error al procesar la respuesta de IA']);
    exit;
}

$botReply = $data['choices'][0]['message']['content'];

// Guardar conversación completa (incluyendo esta respuesta)
$allMessages   = $input['messages'];
$allMessages[] = ['role' => 'assistant', 'content' => $botReply];
save_conversation($sessionId, $allMessages, $ip, $userAgent);

echo json_encode([
    'message' => $botReply,
    'usage'   => $data['usage'] ?? null
]);

// ── Guardar/actualizar conversación en SQLite ──
function save_conversation(string $sessionId, array $messages, ?string $ip, string $userAgent): void {
    if (empty($sessionId)) return;

    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE IF NOT EXISTS conversations (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id   TEXT    NOT NULL UNIQUE,
            started_at   TEXT    DEFAULT (datetime('now', 'localtime')),
            last_active  TEXT    DEFAULT (datetime('now', 'localtime')),
            messages     TEXT,
            ip           TEXT,
            user_agent   TEXT,
            is_lead      INTEGER DEFAULT 0,
            msg_count    INTEGER DEFAULT 0
        )");

        $msgJson   = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $msgCount  = count(array_filter($messages, fn($m) => ($m['role'] ?? '') !== 'system'));

        // Upsert: crear o actualizar según session_id
        $stmt = $db->prepare("
            INSERT INTO conversations (session_id, messages, ip, user_agent, msg_count)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(session_id) DO UPDATE SET
                messages    = excluded.messages,
                last_active = datetime('now', 'localtime'),
                msg_count   = excluded.msg_count
        ");
        $stmt->execute([$sessionId, $msgJson, $ip, $userAgent, $msgCount]);
    } catch (Exception $e) {
        // Silencioso — no interrumpir respuesta al usuario
    }
}
