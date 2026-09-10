<?php
/**
 * Replanificacion semanal (Modulos 2 y 5).
 *
 * 1. Actualiza el estado y el score de cada keyword segun el trafico real.
 * 2. Recalcula el peso de cada cluster tematico.
 * 3. Le pide a la IA keywords nuevas para profundizar los temas ganadores.
 * 4. Regenera el sitemap.
 *
 * Cron: una vez por semana.
 */

require_once __DIR__ . '/_cli.php';
require_once __DIR__ . '/../lib/planner.php';
require_once __DIR__ . '/../lib/blog.php';

$candado = tomar_candado('replanificar');
if (!$candado) {
    cli_log('Ya hay una replanificacion en curso. Salgo.');
    exit(0);
}

$db  = db_connect();
$run = run_inicio($db, 'replanificar');
$dias = setting_int($db, 'ventana_metricas_dias', 30);

sembrar_si_hace_falta($db);

cli_log("Ventana de analisis: ultimos {$dias} dias");

// 1. Estados de keywords segun trafico
actualizar_estados_keywords($db, $dias);
cli_log('Estados de keywords actualizados.');

// 2. Pesos por cluster
$informe = recalcular_pesos($db, $dias);
cli_log('Pesos recalculados:');
foreach ($informe as $i) {
    $flecha = $i['nuevo'] > $i['peso'] ? '↑' : ($i['nuevo'] < $i['peso'] ? '↓' : '=');
    cli_log(sprintf('  %-28s %.3f %s %.3f   (rendimiento %.2f)',
        $i['cluster'], $i['peso'], $flecha, $i['nuevo'], $i['puntos']));
}

// 3. Nuevas keywords sobre los temas ganadores
$pendientes = (int)$db->query("SELECT COUNT(*) FROM blog_keywords WHERE estado = 'sugerida'")->fetchColumn();
$minimo     = setting_int($db, 'min_keywords_en_cola', 8);
$detalle    = 'pesos actualizados';

if ($pendientes < $minimo) {
    cli_log("Quedan {$pendientes} keywords en cola (minimo {$minimo}). Pido nuevas a la IA...");
    $r = proponer_keywords($db, 6);

    if ($r['ok']) {
        cli_log("  {$r['agregadas']} keywords nuevas (USD {$r['costo_usd']})");
        $detalle .= " | +{$r['agregadas']} keywords";
    } else {
        cli_log('  No se pudieron generar: ' . $r['error']);
        $detalle .= ' | fallo al proponer keywords: ' . $r['error'];
    }
} else {
    cli_log("Hay {$pendientes} keywords en cola: no hace falta pedir mas.");
}

// 4. Sitemap
escribir_sitemap($db);
cli_log('sitemap.xml regenerado.');

run_fin($db, $run, 'ok', $detalle);
cli_log('Listo.');
