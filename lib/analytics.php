<?php
/**
 * Analitica propia y liviana: una fila por visita, sin cookies de terceros.
 *
 * La IP nunca se guarda en claro. Se hashea junto con una sal diaria, asi el
 * hash sirve para deduplicar visitantes dentro del dia pero no permite
 * seguir a la misma persona entre dias ni revertir la IP.
 */

require_once __DIR__ . '/settings.php';

const BOT_PATRONES = [
    'bot', 'crawler', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit',
    'headlesschrome', 'python-requests', 'curl/', 'wget', 'phantomjs',
    'lighthouse', 'pingdom', 'uptimerobot', 'semrush', 'ahrefs', 'mj12',
];

const BUSCADORES = ['google.', 'bing.', 'duckduckgo.', 'yahoo.', 'ecosia.', 'brave.', 'yandex.'];
const REDES      = ['facebook.', 'instagram.', 'linkedin.', 'twitter.', 't.co', 'x.com', 'youtube.', 'whatsapp'];

function es_bot(string $ua): bool {
    $u = strtolower($ua);
    if ($u === '') return true;
    foreach (BOT_PATRONES as $p) {
        if (str_contains($u, $p)) return true;
    }
    return false;
}

/** Hash de visitante: IP + user agent + sal diaria. Irreversible por diseno. */
function hash_visitante(string $ip, string $ua): string {
    $sal = 'prexa|' . date('Y-m-d') . '|' . (defined('APP_SECRET') ? APP_SECRET : 'sin-secreto');
    return substr(hash('sha256', $ip . '|' . $ua . '|' . $sal), 0, 32);
}

function hash_ip(string $ip): string {
    $sal = 'ip|' . date('Y-m-d') . '|' . (defined('APP_SECRET') ? APP_SECRET : 'sin-secreto');
    return substr(hash('sha256', $ip . '|' . $sal), 0, 24);
}

function host_de(string $url): string {
    if ($url === '') return '';
    $h = parse_url($url, PHP_URL_HOST);
    return is_string($h) ? strtolower($h) : '';
}

/** De donde llego la visita, deducido del referrer. */
function clasificar_origen(string $referrerHost): string {
    if ($referrerHost === '') return 'directo';

    if (str_contains($referrerHost, 'prexacode.com')) return 'interno';

    foreach (BUSCADORES as $b) {
        if (str_contains($referrerHost, $b)) return 'organico';
    }
    foreach (REDES as $r) {
        if (str_contains($referrerHost, $r)) return 'social';
    }
    return 'referido';
}

/**
 * Registra la visita y devuelve el id, que el frontend usa despues para
 * reportar el tiempo de permanencia.
 */
function registrar_hit(PDO $db, string $path, string $referrer, string $ua, string $ip, ?int $postId = null): ?int
{
    try {
        $host   = host_de($referrer);
        $origen = clasificar_origen($host);

        $stmt = $db->prepare("INSERT INTO hits
            (fecha, path, post_id, referrer, referrer_host, origen, ip_hash, visitante, user_agent, es_bot)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            date('Y-m-d'),
            mb_substr($path, 0, 300),
            $postId,
            mb_substr($referrer, 0, 400),
            $host,
            $origen,
            hash_ip($ip),
            hash_visitante($ip, $ua),
            mb_substr($ua, 0, 255),
            es_bot($ua) ? 1 : 0,
        ]);

        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

/** Segunda llamada del frontend: cuanto tiempo estuvo en la pagina. */
function registrar_permanencia(PDO $db, int $hitId, int $segundos): void {
    if ($segundos < 0 || $segundos > 3600) return;
    try {
        $db->prepare("UPDATE hits SET segundos = ? WHERE id = ? AND segundos = 0")
           ->execute([$segundos, $hitId]);
    } catch (Exception $e) {
        // Metrica secundaria: si falla no afecta a nadie
    }
}

// ── Consultas de agregacion ──

/** Metricas por post en los ultimos N dias (excluye bots). */
function metricas_posts(PDO $db, int $dias = 30): array {
    $stmt = $db->prepare("
        SELECT post_id,
               COUNT(*)                                   AS visitas,
               COUNT(DISTINCT visitante)                  AS unicas,
               COALESCE(AVG(NULLIF(segundos, 0)), 0)      AS permanencia,
               SUM(CASE WHEN origen = 'organico' THEN 1 ELSE 0 END) AS organicas
        FROM hits
        WHERE es_bot = 0 AND post_id IS NOT NULL
          AND fecha >= date('now', 'localtime', ?)
        GROUP BY post_id");
    $stmt->execute(['-' . $dias . ' days']);

    $salida = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $salida[(int)$f['post_id']] = [
            'visitas'     => (int)$f['visitas'],
            'unicas'      => (int)$f['unicas'],
            'permanencia' => round((float)$f['permanencia'], 1),
            'organicas'   => (int)$f['organicas'],
        ];
    }
    return $salida;
}

function resumen_sitio(PDO $db, int $dias = 30): array {
    $stmt = $db->prepare("
        SELECT COUNT(*)                              AS visitas,
               COUNT(DISTINCT visitante)             AS unicas,
               SUM(CASE WHEN origen='organico' THEN 1 ELSE 0 END) AS organicas,
               SUM(CASE WHEN post_id IS NOT NULL THEN 1 ELSE 0 END) AS al_blog,
               COALESCE(AVG(NULLIF(segundos,0)), 0)  AS permanencia
        FROM hits
        WHERE es_bot = 0 AND fecha >= date('now', 'localtime', ?)");
    $stmt->execute(['-' . $dias . ' days']);
    $f = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'visitas'     => (int)($f['visitas'] ?? 0),
        'unicas'      => (int)($f['unicas'] ?? 0),
        'organicas'   => (int)($f['organicas'] ?? 0),
        'al_blog'     => (int)($f['al_blog'] ?? 0),
        'permanencia' => round((float)($f['permanencia'] ?? 0), 1),
    ];
}

function paginas_top(PDO $db, int $dias = 30, int $limite = 15): array {
    $stmt = $db->prepare("
        SELECT path,
               COUNT(*)                  AS visitas,
               COUNT(DISTINCT visitante) AS unicas,
               SUM(CASE WHEN origen='organico' THEN 1 ELSE 0 END) AS organicas
        FROM hits
        WHERE es_bot = 0 AND fecha >= date('now', 'localtime', ?)
        GROUP BY path
        ORDER BY visitas DESC
        LIMIT " . (int)$limite);
    $stmt->execute(['-' . $dias . ' days']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function origenes_trafico(PDO $db, int $dias = 30): array {
    $stmt = $db->prepare("
        SELECT origen, COUNT(*) AS visitas
        FROM hits
        WHERE es_bot = 0 AND fecha >= date('now', 'localtime', ?)
        GROUP BY origen ORDER BY visitas DESC");
    $stmt->execute(['-' . $dias . ' days']);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function serie_diaria(PDO $db, int $dias = 30): array {
    $stmt = $db->prepare("
        SELECT fecha, COUNT(*) AS visitas
        FROM hits
        WHERE es_bot = 0 AND fecha >= date('now', 'localtime', ?)
        GROUP BY fecha ORDER BY fecha");
    $stmt->execute(['-' . $dias . ' days']);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
