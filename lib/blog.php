<?php
/**
 * Acceso a los articulos del blog y generacion del sitemap.
 */

require_once __DIR__ . '/seo.php';

function post_por_slug(PDO $db, string $slug, bool $soloPublicado = true): ?array {
    $sql = "SELECT * FROM blog_posts WHERE slug = ?";
    if ($soloPublicado) $sql .= " AND estado = 'publicado'";

    $stmt = $db->prepare($sql);
    $stmt->execute([$slug]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    return $post ?: null;
}

function post_por_id(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function posts_publicados(PDO $db, int $limite = 10, int $offset = 0): array {
    $stmt = $db->prepare("SELECT id, slug, titulo, extracto, imagen_path, imagen_alt, publicado_at, keyword
                          FROM blog_posts
                          WHERE estado = 'publicado'
                          ORDER BY publicado_at DESC
                          LIMIT " . (int)$limite . " OFFSET " . (int)$offset);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function contar_publicados(PDO $db): int {
    return (int)$db->query("SELECT COUNT(*) FROM blog_posts WHERE estado = 'publicado'")->fetchColumn();
}

/** Articulos relacionados por cluster, para enlazado interno entre posts. */
function posts_relacionados(PDO $db, array $post, int $limite = 3): array {
    $stmt = $db->prepare("SELECT slug, titulo, imagen_path, imagen_alt
                          FROM blog_posts
                          WHERE estado = 'publicado' AND id != ? AND cluster_id = ?
                          ORDER BY publicado_at DESC LIMIT " . (int)$limite);
    $stmt->execute([(int)$post['id'], (int)$post['cluster_id']]);
    $rel = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si el cluster todavia no tiene suficientes, completamos con los mas nuevos
    if (count($rel) < $limite) {
        $faltan = $limite - count($rel);
        $excluir = array_merge([(int)$post['id']], array_map(fn($r) => $r['slug'], $rel));
        $stmt = $db->prepare("SELECT slug, titulo, imagen_path, imagen_alt
                              FROM blog_posts
                              WHERE estado = 'publicado' AND id != ?
                              ORDER BY publicado_at DESC LIMIT " . (int)($faltan + count($rel)));
        $stmt->execute([(int)$post['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (count($rel) >= $limite) break;
            if (in_array($r['slug'], $excluir, true)) continue;
            $rel[] = $r;
        }
    }
    return $rel;
}

function cambiar_estado_post(PDO $db, int $id, string $estado): bool {
    if (!in_array($estado, ['publicado', 'programado', 'despublicado'], true)) return false;

    $post = post_por_id($db, $id);
    if (!$post) return false;

    // Al publicar por primera vez se sella la fecha; despues no se toca,
    // porque cambiarla le miente a Google sobre la antiguedad del contenido.
    $publicadoAt = $post['publicado_at'];
    if ($estado === 'publicado' && !$publicadoAt) {
        $publicadoAt = date('Y-m-d H:i:s');
    }

    $db->prepare("UPDATE blog_posts SET estado = ?, publicado_at = ? WHERE id = ?")
       ->execute([$estado, $publicadoAt, $id]);

    return true;
}

/**
 * Sitemap con la home y todos los articulos publicados.
 *
 * Es imprescindible: el blog es una estructura satelite sin enlaces desde la
 * home ni desde el menu, asi que sin sitemap Google no tendria por donde
 * descubrir los articulos.
 */
function generar_sitemap(PDO $db): string {
    $base  = rtrim((string)COMPANY_DOMAIN, '/');
    $posts = $db->query("SELECT slug, publicado_at FROM blog_posts
                         WHERE estado = 'publicado' ORDER BY publicado_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $xml .= "  <url>\n    <loc>{$base}/</loc>\n    <changefreq>monthly</changefreq>\n    <priority>1.0</priority>\n  </url>\n";
    $xml .= "  <url>\n    <loc>{$base}/blog/</loc>\n    <changefreq>weekly</changefreq>\n    <priority>0.8</priority>\n  </url>\n";

    foreach ($posts as $p) {
        $loc = $base . '/blog/' . htmlspecialchars((string)$p['slug'], ENT_XML1);
        $ts  = strtotime((string)$p['publicado_at']) ?: time();
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$loc}</loc>\n";
        $xml .= "    <lastmod>" . date('Y-m-d', $ts) . "</lastmod>\n";
        $xml .= "    <changefreq>monthly</changefreq>\n";
        $xml .= "    <priority>0.7</priority>\n";
        $xml .= "  </url>\n";
    }

    $xml .= '</urlset>' . "\n";
    return $xml;
}

function escribir_sitemap(PDO $db): bool {
    $ruta = dirname(__DIR__) . '/sitemap.xml';
    $ok   = file_put_contents($ruta, generar_sitemap($db), LOCK_EX) !== false;
    if ($ok) @chmod($ruta, 0644);
    return $ok;
}
