<?php
/**
 * PREXAcode - Bootstrap compartido (API + panel admin).
 * Sin efectos secundarios al incluirlo: todo vive dentro de funciones.
 */

require_once __DIR__ . '/../config.php';

// ── Cabeceras JSON + CORS restringido al dominio propio ──
function api_headers(string $methods = 'POST, OPTIONS'): void {
    header('Content-Type: application/json; charset=utf-8');

    $origen     = $_SERVER['HTTP_ORIGIN'] ?? '';
    $permitidos = [COMPANY_DOMAIN, str_replace('://', '://www.', COMPANY_DOMAIN)];

    if ($origen !== '' && in_array($origen, $permitidos, true)) {
        header('Access-Control-Allow-Origin: ' . $origen);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Content-Type');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_error(int $code, string $mensaje): void {
    http_response_code($code);
    echo json_encode(['error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// ── Conexión SQLite con el esquema al día ──
function db_connect(): PDO {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    $db = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    db_migrate($db);
    return $db;
}

function db_migrate(PDO $db): void {
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

    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        bucket TEXT NOT NULL,
        ip     TEXT NOT NULL,
        ts     TEXT NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_lookup ON rate_limits (bucket, ip, ts)");
}

/**
 * Límite por IP: devuelve false si ya se superó el cupo en la ventana.
 * Registra el intento sólo cuando lo permite.
 */
function rate_limit_ok(PDO $db, string $bucket, int $maximo, int $ventanaSeg): bool {
    try {
        // Purga perezosa: descarta lo que ya no puede afectar a ninguna ventana
        $db->prepare("DELETE FROM rate_limits WHERE ts < datetime('now', ?)")
           ->execute(['-' . ($ventanaSeg * 4) . ' seconds']);

        $stmt = $db->prepare("SELECT COUNT(*) FROM rate_limits
                              WHERE bucket = ? AND ip = ? AND ts > datetime('now', ?)");
        $stmt->execute([$bucket, client_ip(), '-' . $ventanaSeg . ' seconds']);

        if ((int)$stmt->fetchColumn() >= $maximo) return false;

        $db->prepare("INSERT INTO rate_limits (bucket, ip, ts) VALUES (?, ?, datetime('now'))")
           ->execute([$bucket, client_ip()]);
        return true;
    } catch (Exception $e) {
        // Ante un fallo de base no bloqueamos al usuario legítimo
        return true;
    }
}

// ── Log de errores de WhatsApp ──
function wa_log(string $referencia, int $httpCode, string $curlErr, ?string $respuesta): void {
    $logFile = dirname(DB_PATH) . '/wa_errors.log';
    $entry   = '[' . date('Y-m-d H:i:s') . '] ' . $referencia
             . ' | HTTP ' . $httpCode . ' | cURL: ' . $curlErr . "\n"
             . 'Response: ' . substr((string)$respuesta, 0, 500) . "\n---\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

/**
 * Envía la plantilla nuevo_lead_siqat. Los parámetros de template de Meta
 * no admiten saltos de línea ni tabs.
 */
function wa_enviar_lead(string $ref, string $nombre, string $telefono, string $email, string $servicio, string $resumen): bool {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => WA_TO,
        'type'              => 'template',
        'template'          => [
            'name'       => 'nuevo_lead_siqat',
            'language'   => ['code' => 'es_AR'],
            'components' => [[
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => wa_param($ref)],
                    ['type' => 'text', 'text' => wa_param($nombre)],
                    ['type' => 'text', 'text' => wa_param($telefono)],
                    ['type' => 'text', 'text' => wa_param($email)],
                    ['type' => 'text', 'text' => wa_param($servicio)],
                    ['type' => 'text', 'text' => mb_substr(wa_param($resumen), 0, 500)],
                ]
            ]]
        ]
    ];

    $ch = curl_init('https://graph.facebook.com/v21.0/' . WA_PHONE_NUMBER_ID . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_TOKEN],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200) {
        wa_log('lead #' . $ref, (int)$httpCode, $curlErr, $resp === false ? '' : $resp);
        return false;
    }
    return true;
}

function wa_param(string $texto): string {
    $texto = preg_replace('/[\r\n\t]+/', ' | ', $texto);
    $texto = preg_replace('/  +/', ' ', $texto);
    $texto = trim($texto);
    // Meta rechaza la plantilla entera si algún parámetro llega vacío
    return $texto === '' ? '-' : $texto;
}
