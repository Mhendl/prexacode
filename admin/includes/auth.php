<?php
require_once __DIR__ . '/../../api/_bootstrap.php';

// ── Sesión ──
function admin_session_start(): void {
    if (session_status() !== PHP_SESSION_NONE) return;

    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => $https,
        'cookie_samesite' => 'Strict',
    ]);
}

function auth_check(): void {
    admin_session_start();
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: /admin/');
        exit;
    }
}

function auth_login(string $user, string $pass): bool {
    // Se evalúan ambos factores siempre, para no filtrar cuál falló por tiempo
    $userOk = hash_equals(ADMIN_USER, $user);
    $passOk = password_verify($pass, ADMIN_PASS_HASH);

    if (!($userOk && $passOk)) return false;

    admin_session_start();
    session_regenerate_id(true);            // corta cualquier fijación de sesión
    $_SESSION['admin_logged_in'] = true;
    return true;
}

function auth_logout(): void {
    admin_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── CSRF ──
function csrf_token(): string {
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok($token): bool {
    admin_session_start();
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

// ── Datos ──
function get_db(): PDO {
    return db_connect();
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
