<?php
/**
 * Corrige de una sola pasada los tickets guardados con htmlspecialchars()
 * aplicado antes de insertar (bug corregido en api/ticket.php).
 * El panel vuelve a escapar al renderizar, así que esas filas se ven como
 * "O&#039;Brien" en lugar de "O'Brien".
 *
 * Uso:  php tools/fix_doble_escape.php           (simulacro, no escribe)
 *       php tools/fix_doble_escape.php --apply   (aplica, previo backup)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/_bootstrap.php';

$aplicar = in_array('--apply', $argv, true);
$campos  = ['nombre', 'email', 'telefono', 'empresa', 'servicio', 'resumen', 'notas'];

$db = db_connect();

if ($aplicar) {
    $backup = dirname(DB_PATH) . '/tickets.backup-' . date('Ymd-His') . '.db';
    // VACUUM INTO copia en caliente sin frenar al sitio (SQLite 3.27+)
    $db->exec("VACUUM INTO " . $db->quote($backup));
    echo "Backup: {$backup}\n";
}

$filas     = $db->query("SELECT id, " . implode(', ', $campos) . " FROM tickets ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$upd       = $db->prepare("UPDATE tickets SET " . implode(' = ?, ', $campos) . " = ? WHERE id = ?");
$cambiadas = 0;

foreach ($filas as $f) {
    $nuevos  = [];
    $cambio  = false;

    foreach ($campos as $c) {
        $valor = (string)($f[$c] ?? '');
        // html_entity_decode revierte exactamente una pasada de htmlspecialchars
        $limpio = html_entity_decode($valor, ENT_QUOTES, 'UTF-8');
        if ($limpio !== $valor) $cambio = true;
        $nuevos[] = $limpio;
    }

    if (!$cambio) continue;
    $cambiadas++;

    echo "#{$f['id']}: {$f['nombre']} -> {$nuevos[0]}\n";

    if ($aplicar) {
        $nuevos[] = $f['id'];
        $upd->execute($nuevos);
    }
}

echo $aplicar
    ? "\nListo: {$cambiadas} tickets corregidos de " . count($filas) . ".\n"
    : "\nSimulacro: {$cambiadas} tickets se corregirían de " . count($filas) . ". Repetí con --apply.\n";
