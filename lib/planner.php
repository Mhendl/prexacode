<?php
/**
 * Motor de decision de contenido (Modulo 2).
 *
 * Cada cluster tematico tiene un peso. El peso sube cuando sus articulos
 * traen trafico organico y retencion, y baja cuando no. La eleccion del
 * proximo tema es una ruleta ponderada por ese peso, no un "gana el mejor":
 * si siempre eligieramos el lider nunca descubririamos que otro tema podia
 * rendir mas. Por eso hay un piso de peso (exploracion).
 *
 * Limite honesto: Google no informa por que termino de busqueda entro la
 * visita (los buscadores no mandan la query en el referrer desde 2011).
 * Lo que medimos es trafico organico POR ARTICULO, y de ahi inferimos que
 * tema funciona. Para keywords reales hace falta conectar Search Console.
 */

require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/settings.php';

const PESO_MINIMO = 0.25;
const PESO_MAXIMO = 3.00;
const SUAVIZADO   = 0.30;   // cuanto pesa la medicion nueva frente al historico

/** Clusters y keywords iniciales, alineados a los servicios reales. */
function semillas(): array {
    return [
        'whatsapp-automatizado' => [
            'etiqueta' => 'WhatsApp automatizado',
            'servicio' => '/#servicios',
            'keywords' => [
                'automatizar whatsapp para ventas',
                'whatsapp business api para empresas',
                'chatbot de whatsapp para atencion al cliente',
                'integrar whatsapp con crm',
            ],
        ],
        'agentes-ia' => [
            'etiqueta' => 'Agentes de IA',
            'servicio' => '/#servicios',
            'keywords' => [
                'agentes de ia para empresas',
                'chatbot con inteligencia artificial para pymes',
                'automatizar atencion al cliente con ia',
                'cuanto cuesta un agente de ia a medida',
            ],
        ],
        'crm-a-medida' => [
            'etiqueta' => 'CRM a medida',
            'servicio' => '/#servicios',
            'keywords' => [
                'crm a medida vs crm enlatado',
                'sistema de gestion de clientes personalizado',
                'migrar de excel a un crm propio',
            ],
        ],
        'software-a-medida' => [
            'etiqueta' => 'Software a medida',
            'servicio' => '/#servicios',
            'keywords' => [
                'software a medida para pymes',
                'cuanto cuesta desarrollar un sistema a medida',
                'software enlatado o a medida para mi empresa',
            ],
        ],
        'apis-integraciones' => [
            'etiqueta' => 'APIs e integraciones',
            'servicio' => '/#servicios',
            'keywords' => [
                'integrar sistemas de gestion con api',
                'conectar ecommerce con sistema de facturacion',
                'que es una api y para que sirve en mi empresa',
            ],
        ],
        'automatizacion-procesos' => [
            'etiqueta' => 'Automatización de procesos',
            'servicio' => '/#servicios',
            'keywords' => [
                'automatizar procesos administrativos en una pyme',
                'reducir tareas repetitivas con software',
                'automatizacion de reportes internos',
            ],
        ],
        'consultoria-tecnologica' => [
            'etiqueta' => 'Consultoría tecnológica',
            'servicio' => '/#servicios',
            'keywords' => [
                'consultoria tecnologica para pymes',
                'como elegir proveedor de software',
            ],
        ],
    ];
}

/** Carga clusters y keywords iniciales una sola vez. */
function sembrar_si_hace_falta(PDO $db): void {
    $hay = (int)$db->query("SELECT COUNT(*) FROM blog_clusters")->fetchColumn();
    if ($hay > 0) return;

    $insCluster = $db->prepare("INSERT INTO blog_clusters (nombre, etiqueta, servicio, peso) VALUES (?, ?, ?, 1.0)");
    $insKw      = $db->prepare("INSERT OR IGNORE INTO blog_keywords (keyword, cluster_id, estado, origen) VALUES (?, ?, 'sugerida', 'semilla')");

    foreach (semillas() as $nombre => $c) {
        $insCluster->execute([$nombre, $c['etiqueta'], $c['servicio']]);
        $clusterId = (int)$db->lastInsertId();
        foreach ($c['keywords'] as $kw) {
            $insKw->execute([$kw, $clusterId]);
        }
    }
}

/**
 * Recalcula el rendimiento de cada cluster y ajusta su peso.
 * Se corre semanalmente desde cron.
 */
function recalcular_pesos(PDO $db, int $dias = 30): array {
    $metricas = metricas_posts($db, $dias);

    // Rendimiento por cluster a partir de los posts que le pertenecen
    $porCluster = [];
    $posts = $db->query("SELECT id, cluster_id FROM blog_posts WHERE estado = 'publicado'")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($posts as $p) {
        $cid = (int)$p['cluster_id'];
        if ($cid === 0) continue;

        $m = $metricas[(int)$p['id']] ?? ['visitas' => 0, 'unicas' => 0, 'permanencia' => 0, 'organicas' => 0];

        // El trafico organico vale mas que el interno: es el objetivo del blog.
        // La permanencia entra como multiplicador acotado para que un articulo
        // con muchas visitas de rebote no gane.
        $retencion = 1 + min(1.0, $m['permanencia'] / 90);
        $puntos    = ($m['organicas'] * 3 + $m['unicas']) * $retencion;

        if (!isset($porCluster[$cid])) $porCluster[$cid] = ['puntos' => 0.0, 'posts' => 0];
        $porCluster[$cid]['puntos'] += $puntos;
        $porCluster[$cid]['posts']++;
    }

    // Promedio por articulo, para no premiar a un cluster solo por tener mas posts
    $promedios = [];
    foreach ($porCluster as $cid => $d) {
        $promedios[$cid] = $d['posts'] > 0 ? $d['puntos'] / $d['posts'] : 0.0;
    }
    $mejor = $promedios ? max($promedios) : 0.0;

    $clusters = $db->query("SELECT id, nombre, etiqueta, peso FROM blog_clusters WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
    $upd      = $db->prepare("UPDATE blog_clusters SET peso = ?, updated_at = datetime('now','localtime') WHERE id = ?");
    $informe  = [];

    foreach ($clusters as $c) {
        $cid   = (int)$c['id'];
        $viejo = (float)$c['peso'];

        if ($mejor <= 0) {
            // Sin datos todavia: nadie se mueve, seguimos explorando parejo
            $informe[] = ['cluster' => $c['etiqueta'], 'peso' => $viejo, 'nuevo' => $viejo, 'puntos' => 0.0];
            continue;
        }

        if (!isset($promedios[$cid])) {
            // Cluster sin articulos publicados: se lo mantiene en exploracion
            $objetivo = 1.0;
        } else {
            $objetivo = PESO_MINIMO + ($promedios[$cid] / $mejor) * (PESO_MAXIMO - PESO_MINIMO);
        }

        $nuevo = $viejo * (1 - SUAVIZADO) + $objetivo * SUAVIZADO;
        $nuevo = round(max(PESO_MINIMO, min(PESO_MAXIMO, $nuevo)), 3);

        $upd->execute([$nuevo, $cid]);
        $informe[] = [
            'cluster' => $c['etiqueta'],
            'peso'    => $viejo,
            'nuevo'   => $nuevo,
            'puntos'  => round($promedios[$cid] ?? 0, 2),
        ];
    }

    return $informe;
}

/** Actualiza el estado de las keywords segun el trafico que trajo su articulo. */
function actualizar_estados_keywords(PDO $db, int $dias = 30): void {
    $metricas = metricas_posts($db, $dias);
    $upd = $db->prepare("UPDATE blog_keywords
                         SET estado = ?, visitas = ?, visitas_organicas = ?, score = ?,
                             last_scored_at = datetime('now','localtime')
                         WHERE id = ?");

    $filas = $db->query("SELECT k.id, k.post_id, k.estado, p.estado AS estado_post
                         FROM blog_keywords k
                         LEFT JOIN blog_posts p ON p.id = k.post_id")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($filas as $f) {
        $postId = (int)$f['post_id'];
        if ($postId === 0) continue;

        $m = $metricas[$postId] ?? ['visitas' => 0, 'unicas' => 0, 'permanencia' => 0, 'organicas' => 0];

        $estado = ($f['estado_post'] === 'publicado')
            ? ($m['organicas'] > 0 ? 'con_trafico' : 'publicada')
            : $f['estado'];

        $score = $m['organicas'] * 3 + $m['unicas'];
        $upd->execute([$estado, $m['visitas'], $m['organicas'], $score, (int)$f['id']]);
    }
}

/**
 * Elige el proximo tema: ruleta ponderada por peso de cluster, y dentro del
 * cluster ganador la keyword sugerida mas prometedora.
 */
function elegir_siguiente(PDO $db): ?array {
    $clusters = $db->query("
        SELECT c.id, c.nombre, c.etiqueta, c.servicio, c.peso
        FROM blog_clusters c
        WHERE c.activo = 1
          AND EXISTS (SELECT 1 FROM blog_keywords k WHERE k.cluster_id = c.id AND k.estado = 'sugerida')
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$clusters) return null;

    $total = 0.0;
    foreach ($clusters as $c) $total += max(PESO_MINIMO, (float)$c['peso']);

    $tiro     = (mt_rand() / mt_getrandmax()) * $total;
    $elegido  = $clusters[0];
    $acumulado = 0.0;
    foreach ($clusters as $c) {
        $acumulado += max(PESO_MINIMO, (float)$c['peso']);
        if ($tiro <= $acumulado) { $elegido = $c; break; }
    }

    $stmt = $db->prepare("SELECT id, keyword FROM blog_keywords
                          WHERE cluster_id = ? AND estado = 'sugerida'
                          ORDER BY score DESC, id ASC LIMIT 1");
    $stmt->execute([(int)$elegido['id']]);
    $kw = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$kw) return null;

    return [
        'keyword_id' => (int)$kw['id'],
        'keyword'    => (string)$kw['keyword'],
        'cluster_id' => (int)$elegido['id'],
        'cluster'    => (string)$elegido['etiqueta'],
        'servicio'   => (string)$elegido['servicio'],
        'peso'       => (float)$elegido['peso'],
    ];
}

/**
 * Evolucion: pide a la IA nuevas keywords profundizando en los clusters que
 * mejor rinden, para que el plan de las proximas semanas siga al ganador.
 */
function proponer_keywords(PDO $db, int $cuantas = 6): array {
    $key = openai_key($db);
    if ($key === '') return ['ok' => false, 'error' => 'Sin API key'];

    $top = $db->query("SELECT etiqueta, peso FROM blog_clusters
                       WHERE activo = 1 ORDER BY peso DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    if (!$top) return ['ok' => false, 'error' => 'Sin clusters'];

    $existentes = $db->query("SELECT keyword FROM blog_keywords ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);

    $lista = implode(', ', array_map(fn($c) => $c['etiqueta'] . ' (peso ' . round((float)$c['peso'], 2) . ')', $top));
    $ya    = implode(' | ', $existentes);

    $prompt = "Sos estratega SEO de PREXAcode, software house argentina que vende desarrollo a medida, "
        . "agentes de IA, automatizacion y consultoria a PYMES y empresas B2B.\n\n"
        . "Los temas que mejor rinden ahora son: {$lista}.\n\n"
        . "Proponé {$cuantas} nuevas keywords long-tail en español rioplatense para profundizar ESOS temas ganadores. "
        . "Tienen que tener intencion comercial o informativa-comercial (alguien con un problema de negocio real que "
        . "podria terminar contratando desarrollo a medida). Nada de keywords genericas de marketing.\n\n"
        . "NO repitas ni parafrasees estas que ya existen: {$ya}\n\n"
        . "Devolvé JSON: {\"keywords\": [{\"keyword\": \"...\", \"cluster\": \"nombre exacto del cluster de la lista\"}]}";

    $r = openai_chat($key, [['role' => 'user', 'content' => $prompt]], 'gpt-4o-mini', true, 1200);
    if (!$r['ok']) return $r;

    $datos = json_decode($r['contenido'], true);
    if (!is_array($datos) || !isset($datos['keywords'])) {
        return ['ok' => false, 'error' => 'Respuesta sin keywords'];
    }

    $mapa = $db->query("SELECT etiqueta, id FROM blog_clusters")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ins  = $db->prepare("INSERT OR IGNORE INTO blog_keywords (keyword, cluster_id, estado, origen) VALUES (?, ?, 'sugerida', 'evolucion')");

    $agregadas = 0;
    foreach ($datos['keywords'] as $k) {
        $kw      = trim((string)($k['keyword'] ?? ''));
        $cluster = trim((string)($k['cluster'] ?? ''));
        if ($kw === '' || !isset($mapa[$cluster])) continue;

        $ins->execute([mb_strtolower($kw), (int)$mapa[$cluster]]);
        $agregadas += $ins->rowCount();
    }

    return ['ok' => true, 'agregadas' => $agregadas, 'costo_usd' => $r['costo_usd']];
}
