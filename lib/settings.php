<?php
/**
 * Configuracion persistida en base, con cifrado autenticado para secretos.
 *
 * La clave maestra sale de APP_SECRET (config.php). Si no esta definida se
 * genera un archivo de clave en data/, que nginx no sirve. Conviene ser
 * honesto sobre el alcance: esto protege el secreto ante una lectura suelta
 * de la base (un backup, un dump), no ante alguien que ya lee config.php,
 * porque ahi vive la clave maestra.
 */

require_once __DIR__ . '/schema.php';

function app_secret(): string {
    if (defined('APP_SECRET') && strlen((string)APP_SECRET) >= 32) {
        return hash('sha256', (string)APP_SECRET, true);
    }

    // Respaldo autogenerado para no romper si falta la constante
    $archivo = dirname(DB_PATH) . '/.appkey';
    if (!is_file($archivo)) {
        $dir = dirname($archivo);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($archivo, base64_encode(random_bytes(32)), LOCK_EX);
        @chmod($archivo, 0600);
    }
    return hash('sha256', (string)file_get_contents($archivo), true);
}

function cifrar(string $texto): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, app_secret()));
}

function descifrar(string $paquete): ?string {
    $bin = base64_decode($paquete, true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;

    $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $texto = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, app_secret());
    return $texto === false ? null : $texto;
}

// ── Acceso a configuracion ──
function setting_get(PDO $db, string $clave, ?string $default = null): ?string {
    $stmt = $db->prepare("SELECT valor, cifrado FROM blog_settings WHERE clave = ?");
    $stmt->execute([$clave]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila) return $default;
    if (!(int)$fila['cifrado']) return $fila['valor'];

    return descifrar((string)$fila['valor']) ?? $default;
}

function setting_set(PDO $db, string $clave, string $valor, bool $cifrado = false): void {
    $db->prepare("INSERT INTO blog_settings (clave, valor, cifrado, updated_at)
                  VALUES (?, ?, ?, datetime('now','localtime'))
                  ON CONFLICT(clave) DO UPDATE SET
                      valor = excluded.valor,
                      cifrado = excluded.cifrado,
                      updated_at = excluded.updated_at")
       ->execute([$clave, $cifrado ? cifrar($valor) : $valor, $cifrado ? 1 : 0]);
}

function setting_bool(PDO $db, string $clave, bool $default = false): bool {
    $v = setting_get($db, $clave, $default ? '1' : '0');
    return $v === '1';
}

function setting_int(PDO $db, string $clave, int $default): int {
    $v = setting_get($db, $clave, (string)$default);
    return is_numeric($v) ? (int)$v : $default;
}

/**
 * La API key del panel manda; si no se cargo, cae a la de config.php,
 * que es la que ya usa el chat del sitio.
 */
function openai_key(PDO $db): string {
    $delPanel = setting_get($db, 'openai_api_key');
    if (is_string($delPanel) && $delPanel !== '') return $delPanel;
    return defined('OPENAI_API_KEY') ? (string)OPENAI_API_KEY : '';
}

/** Muestra solo los extremos: nunca devolvemos la clave entera al navegador. */
function key_enmascarada(string $key): string {
    $n = strlen($key);
    if ($n === 0) return '';
    if ($n <= 12) return str_repeat('•', $n);
    return substr($key, 0, 6) . str_repeat('•', 12) . substr($key, -4);
}
