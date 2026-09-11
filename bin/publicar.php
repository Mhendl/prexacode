<?php
/**
 * Genera y publica un articulo. Se ejecuta 3 veces por semana desde cron.
 *
 * Uso:  php bin/publicar.php            genera y publica
 *       php bin/publicar.php --dry-run  elige el tema pero no llama a OpenAI
 */

require_once __DIR__ . '/_cli.php';
require_once __DIR__ . '/../lib/generator.php';
require_once __DIR__ . '/../lib/blog.php';

$dryRun = in_array('--dry-run', $argv, true);

$candado = tomar_candado('publicar');
if (!$candado) {
    cli_log('Ya hay una publicacion en curso. Salgo.');
    exit(0);
}

$db  = db_connect();
$run = run_inicio($db, 'publicar');

if (!setting_bool($db, 'blog_activo', true)) {
    cli_log('El blog esta desactivado desde el panel. No genero nada.');
    run_fin($db, $run, 'omitido', 'blog_activo = 0');
    exit(0);
}

sembrar_si_hace_falta($db);

// Tope de seguridad: si algo dispara el cron de mas, no publicamos una
// avalancha de articulos ni quemamos la cuota de OpenAI.
//
// Se cuenta desde el lunes de la semana en curso, NO en una ventana movil de
// 7 dias. Con la ventana movil, los articulos del lunes y miercoles seguian
// contando el miercoles siguiente y el cron se salteaba corridas: daba ~2,5
// articulos por semana en vez de los 3 pedidos.
$maxSemana  = setting_int($db, 'max_posts_semana', 3);
$estaSemana = (int)$db->query("SELECT COUNT(*) FROM blog_posts
                               WHERE estado IN ('publicado','programado')
                                 AND created_at >= date('now','localtime','-6 days','weekday 1')")->fetchColumn();

if ($estaSemana >= $maxSemana) {
    cli_log("Ya hay {$estaSemana} articulos esta semana (tope {$maxSemana}). No genero.");
    run_fin($db, $run, 'omitido', "tope semanal alcanzado: {$estaSemana}/{$maxSemana}");
    exit(0);
}

if ($dryRun) {
    $tema = elegir_siguiente($db);
    cli_log($tema
        ? "Simulacro. Tema elegido: \"{$tema['keyword']}\" (cluster {$tema['cluster']}, peso {$tema['peso']})"
        : 'Simulacro. No hay keywords sugeridas disponibles.');
    run_fin($db, $run, 'simulacro', $tema['keyword'] ?? 'sin tema');
    exit(0);
}

cli_log('Generando articulo...');
$r = generar_articulo($db);

if (!$r['ok']) {
    cli_log('ERROR: ' . $r['error']);
    run_fin($db, $run, 'error', $r['error']);
    exit(1);
}

cli_log("Publicado #{$r['post_id']}: {$r['titulo']}");
cli_log("  slug      : /blog/{$r['slug']}");
cli_log("  keyword   : {$r['keyword']} ({$r['cluster']})");
cli_log("  palabras  : {$r['palabras']}");
cli_log("  imagen    : " . ($r['imagen'] ?: 'sin imagen'));
cli_log("  costo USD : {$r['costo_usd']}");

escribir_sitemap($db);
cli_log('sitemap.xml regenerado.');

run_fin($db, $run, 'ok', "#{$r['post_id']} {$r['slug']} | USD {$r['costo_usd']}");
