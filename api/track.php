<?php
/**
 * Beacon de analitica propia (Modulo 5).
 *
 * Dos usos:
 *   { path, referrer, post_id }  -> registra la visita y devuelve hit_id
 *   { hit_id, segundos }         -> completa el tiempo de permanencia
 *
 * Sin cookies. La IP se guarda hasheada con sal diaria, nunca en claro.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/analytics.php';

api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Método no permitido');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) api_error(400, 'Datos inválidos');

try {
    $db = db_connect();
} catch (Exception $e) {
    // La analitica nunca debe romper la navegacion
    echo json_encode(['ok' => false]);
    exit;
}

// ── Reporte de permanencia ──
if (isset($input['hit_id'])) {
    registrar_permanencia($db, (int)$input['hit_id'], (int)($input['segundos'] ?? 0));
    echo json_encode(['ok' => true]);
    exit;
}

// ── Nueva visita ──
$path = (string)($input['path'] ?? '');
if ($path === '' || $path[0] !== '/') $path = '/';

// Tope generoso: no molesta a nadie real, corta el ruido de un script
if (!rate_limit_ok($db, 'track', 120, 600)) {
    echo json_encode(['ok' => false]);
    exit;
}

$postId = isset($input['post_id']) && $input['post_id'] !== null ? (int)$input['post_id'] : null;

$hitId = registrar_hit(
    $db,
    $path,
    (string)($input['referrer'] ?? ''),
    $_SERVER['HTTP_USER_AGENT'] ?? '',
    client_ip(),
    $postId ?: null
);

echo json_encode(['ok' => true, 'hit_id' => $hitId]);
