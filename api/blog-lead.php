<?php
/**
 * Formulario de consulta de los articulos del blog (Modulo 6.4).
 *
 * El destino pedido era un correo a info@prexacode.com, pero el servidor no
 * tiene MTA instalado (postfix inactivo, sin sendmail), asi que mail() se
 * perderia en silencio. Hasta que haya un relay SMTP configurado el lead se
 * guarda en la tabla tickets (visible en el panel) y se notifica por WhatsApp,
 * que es el canal que ya funciona. La funcion notificar_por_email() deja el
 * enganche listo para cuando exista transporte.
 */

require_once __DIR__ . '/_bootstrap.php';

api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Método no permitido');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) api_error(400, 'Datos inválidos');

// Honeypot: si viene completo es un bot. Respondemos ok para no darle senal.
if (trim((string)($input['website'] ?? '')) !== '') {
    echo json_encode(['success' => true, 'message' => '¡Gracias! Te contactamos a la brevedad.']);
    exit;
}

$nombre   = trim((string)($input['nombre']   ?? ''));
$email    = trim((string)($input['email']    ?? ''));
$empresa  = trim((string)($input['empresa']  ?? ''));
$consulta = trim((string)($input['consulta'] ?? ''));
$postId   = (int)($input['post_id'] ?? 0);

if ($nombre === '' || $email === '' || $consulta === '') api_error(400, 'Completá nombre, email y consulta.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))          api_error(400, 'El email no es válido.');
if (mb_strlen($nombre) > 120 || mb_strlen($email) > 190) api_error(400, 'Datos demasiado largos.');
if (mb_strlen($consulta) > 2000)                         api_error(400, 'La consulta es demasiado larga.');

try {
    $db = db_connect();
} catch (Exception $e) {
    api_error(500, 'No pudimos registrar la consulta.');
}

if (!rate_limit_ok($db, 'blog_lead', 5, 3600)) {
    api_error(429, 'Ya recibimos tu consulta. Te contactamos a la brevedad.');
}

// Contexto: desde que articulo llego el lead. Sirve para saber que contenido convierte.
$origen = 'Blog';
if ($postId > 0) {
    $stmt = $db->prepare("SELECT titulo, slug FROM blog_posts WHERE id = ?");
    $stmt->execute([$postId]);
    if ($post = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $origen = 'Blog: ' . $post['titulo'];
    }
}

try {
    $stmt = $db->prepare("INSERT INTO tickets (nombre, email, telefono, empresa, servicio, resumen, ip)
                          VALUES (?, ?, '', ?, ?, ?, ?)");
    $stmt->execute([$nombre, $email, $empresa, mb_substr($origen, 0, 190), $consulta, client_ip()]);
    $ticketId = (int)$db->lastInsertId();
} catch (Exception $e) {
    api_error(500, 'No pudimos registrar la consulta.');
}

wa_enviar_lead(
    (string)$ticketId,
    $nombre,
    'No proporcionado',
    $email,
    $origen,
    $consulta
);

notificar_por_email($nombre, $email, $empresa, $consulta, $origen, $ticketId);

echo json_encode([
    'success'   => true,
    'ticket_id' => $ticketId,
    'message'   => '¡Gracias ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . '! Recibimos tu consulta y te contactamos a la brevedad.',
], JSON_UNESCAPED_UNICODE);

/**
 * Enganche para el envio por correo. Hoy no hay MTA ni relay, asi que solo
 * intenta si el entorno realmente puede enviar; nunca bloquea la respuesta.
 */
function notificar_por_email(string $nombre, string $email, string $empresa, string $consulta, string $origen, int $ticketId): bool {
    $destino = defined('COMPANY_EMAIL') ? (string)COMPANY_EMAIL : '';
    if ($destino === '' || !function_exists('mail')) return false;

    // Sin sendmail configurado, mail() falla o encola en la nada. No insistimos.
    $sendmail = (string)ini_get('sendmail_path');
    if ($sendmail === '' || !is_executable(explode(' ', $sendmail)[0])) return false;

    $asunto  = "Nueva consulta del blog #{$ticketId} - {$nombre}";
    $cuerpo  = "Ticket: #{$ticketId}\nOrigen: {$origen}\n\n"
             . "Nombre: {$nombre}\nEmail: {$email}\nEmpresa: " . ($empresa ?: '-') . "\n\n"
             . "Consulta:\n{$consulta}\n";
    $headers = "From: PREXAcode <{$destino}>\r\nReply-To: {$nombre} <{$email}>\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($destino, $asunto, $cuerpo, $headers);
}
