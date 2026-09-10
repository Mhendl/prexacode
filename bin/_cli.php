<?php
/**
 * Base comun de las tareas de linea de comandos.
 * Nunca deben ser ejecutables por HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/_bootstrap.php';

function cli_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

/** Registra la corrida en blog_runs y devuelve el id. */
function run_inicio(PDO $db, string $tarea): int {
    $db->prepare("INSERT INTO blog_runs (tarea) VALUES (?)")->execute([$tarea]);
    return (int)$db->lastInsertId();
}

function run_fin(PDO $db, int $id, string $resultado, string $detalle = ''): void {
    $db->prepare("UPDATE blog_runs SET fin = datetime('now','localtime'), resultado = ?, detalle = ? WHERE id = ?")
       ->execute([$resultado, mb_substr($detalle, 0, 2000), $id]);
}

/**
 * Candado por archivo: si el cron se solapa (una corrida lenta y la siguiente
 * que arranca), la segunda sale sin hacer nada en vez de duplicar articulos.
 */
function tomar_candado(string $nombre) {
    $ruta = dirname(DB_PATH) . '/' . $nombre . '.lock';
    $fh   = fopen($ruta, 'c');
    if ($fh === false) return null;

    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return null;
    }
    return $fh;
}
