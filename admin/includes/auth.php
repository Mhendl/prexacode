<?php
require_once __DIR__ . '/../../config.php';

function auth_check() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure'   => isset($_SERVER['HTTPS']),
            'cookie_samesite' => 'Strict'
        ]);
    }
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: /admin/');
        exit;
    }
}

function auth_login(string $user, string $pass): bool {
    return $user === ADMIN_USER && password_verify($pass, ADMIN_PASS_HASH);
}

function get_db(): PDO {
    $dataDir = dirname(DB_PATH);
    if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);
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
    return $db;
}

function estado_badge(string $estado): string {
    $map = [
        'nuevo'       => ['#22d3ee', '🔵'],
        'contactado'  => ['#a78bfa', '🟣'],
        'en_progreso' => ['#fbbf24', '🟡'],
        'cerrado'     => ['#6b7280', '⚫'],
    ];
    [$color, $icon] = $map[$estado] ?? ['#94a3b8', '⚪'];
    $label = ucfirst(str_replace('_', ' ', $estado));
    return "<span style='color:{$color};font-weight:600'>{$icon} {$label}</span>";
}
