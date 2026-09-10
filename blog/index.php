<?php
/**
 * Indice del blog. Se indexa y sirve de hub interno entre articulos,
 * pero no se enlaza desde la home ni desde el menu publico de la landing.
 */

require_once __DIR__ . '/../api/_bootstrap.php';
require_once __DIR__ . '/../lib/blog.php';
require_once __DIR__ . '/_layout.php';

$porPagina = 9;
$pagina    = max(1, (int)($_GET['p'] ?? 1));

try {
    $db     = db_connect();
    $total  = contar_publicados($db);
    $posts  = posts_publicados($db, $porPagina, ($pagina - 1) * $porPagina);
} catch (Exception $e) {
    $total = 0;
    $posts = [];
}

$totalPags = max(1, (int)ceil($total / $porPagina));
$base      = rtrim((string)COMPANY_DOMAIN, '/');
$canonical = $base . '/blog/' . ($pagina > 1 ? '?p=' . $pagina : '');

blog_head([
    'meta_title'       => $pagina > 1
        ? "Blog de PREXAcode — página {$pagina}"
        : 'Blog de PREXAcode — Software a medida, IA y automatización',
    'meta_description' => 'Artículos técnicos sobre software a medida, agentes de IA, automatización de procesos e integraciones para PYMES y empresas B2B.',
    'canonical'        => $canonical,
]);
?>

<main class="bl-wrap">
  <header class="bl-index-head">
    <h1>Blog</h1>
    <p>Cómo resolver problemas concretos de operación con software a medida, agentes de IA y automatización.</p>
  </header>

  <?php if (!$posts): ?>
    <p class="bl-vacio">Todavía no hay artículos publicados.</p>
  <?php else: ?>
    <div class="bl-grid">
      <?php foreach ($posts as $p): ?>
        <article class="bl-card">
          <a href="/blog/<?= htmlspecialchars((string)$p['slug'], ENT_QUOTES, 'UTF-8') ?>">
            <?php if (!empty($p['imagen_path'])): ?>
              <img src="/<?= htmlspecialchars(ltrim((string)$p['imagen_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                   alt="<?= htmlspecialchars((string)$p['imagen_alt'], ENT_QUOTES, 'UTF-8') ?>"
                   loading="lazy" width="400" height="229">
            <?php endif; ?>
            <div class="bl-card-body">
              <h2><?= htmlspecialchars((string)$p['titulo'], ENT_QUOTES, 'UTF-8') ?></h2>
              <p><?= htmlspecialchars((string)$p['extracto'], ENT_QUOTES, 'UTF-8') ?></p>
              <span class="bl-card-fecha"><?= date('d/m/Y', strtotime((string)$p['publicado_at']) ?: time()) ?></span>
            </div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPags > 1): ?>
      <nav class="bl-pag" aria-label="Paginación">
        <?php for ($i = 1; $i <= $totalPags; $i++): ?>
          <?php if ($i === $pagina): ?>
            <span class="actual"><?= $i ?></span>
          <?php else: ?>
            <a href="?p=<?= $i ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</main>

<?php blog_footer(); ?>
