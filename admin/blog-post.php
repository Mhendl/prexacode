<?php
/**
 * Detalle de un articulo: metricas, SEO, estado y cuerpo.
 * Permite despublicar sin borrar, que es lo correcto cuando la IA publico
 * algo que no queremos indexado.
 */

require_once __DIR__ . '/includes/auth.php';
auth_check();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../lib/blog.php';
require_once __DIR__ . '/../lib/analytics.php';
require_once __DIR__ . '/../lib/settings.php';

$db = get_db();
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok($_POST['csrf'] ?? null)) {
    $nuevo = (string)($_POST['estado'] ?? '');
    if (cambiar_estado_post($db, $id, $nuevo)) {
        escribir_sitemap($db);
        header("Location: /admin/blog-post.php?id={$id}&ok=1");
        exit;
    }
}

$post = post_por_id($db, $id);
if (!$post) {
    header('Location: /admin/blog.php');
    exit;
}

$m = metricas_posts($db, setting_int($db, 'ventana_metricas_dias', 30))[$id]
     ?? ['visitas' => 0, 'unicas' => 0, 'organicas' => 0, 'permanencia' => 0];

$url = rtrim((string)COMPANY_DOMAIN, '/') . '/blog/' . $post['slug'];

admin_head('Artículo #' . $id);
admin_sidebar('blog');
?>

<div class="main">
  <a href="/admin/blog.php" style="color:#64748b;font-size:.85rem;display:inline-block;margin-bottom:18px">← Volver a artículos</a>

  <?php if (isset($_GET['ok'])): ?>
    <div class="aviso ok">Artículo actualizado y sitemap regenerado.</div>
  <?php endif; ?>

  <div class="page-header">
    <div>
      <h1><?= htmlspecialchars((string)$post['titulo']) ?></h1>
      <p>
        <span class="badge b-<?= htmlspecialchars((string)$post['estado']) ?>"><?= ucfirst(htmlspecialchars((string)$post['estado'])) ?></span>
        &nbsp;<?= htmlspecialchars((string)$post['keyword']) ?>
        <?php if ($post['estado'] === 'publicado'): ?>
          &nbsp;·&nbsp;<a href="<?= htmlspecialchars($url) ?>" target="_blank" style="color:#22d3ee">Ver publicado ↗</a>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php if ($post['estado'] === 'error'): ?>
    <div class="aviso error">
      <strong>No se publicó.</strong> El control de calidad lo rechazó:<br>
      <?= htmlspecialchars((string)$post['error']) ?>
    </div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat-card"><div class="stat-num"><?= $m['unicas'] ?></div><div class="stat-label">Visitantes únicos</div></div>
    <div class="stat-card"><div class="stat-num verde"><?= $m['organicas'] ?></div><div class="stat-label">Desde buscadores</div></div>
    <div class="stat-card"><div class="stat-num amarillo"><?= $m['permanencia'] ?>s</div><div class="stat-label">Permanencia media</div></div>
    <div class="stat-card"><div class="stat-num violeta"><?= (int)$post['palabras'] ?></div><div class="stat-label">Palabras</div></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start" class="detalle-grid">
    <div>
      <div class="card">
        <h2>SEO on-page</h2>
        <div style="display:flex;flex-direction:column;gap:14px;font-size:.9rem">
          <div>
            <div style="color:#64748b;font-size:.78rem">URL</div>
            <div class="mono" style="color:#22d3ee">/blog/<?= htmlspecialchars((string)$post['slug']) ?></div>
          </div>
          <div>
            <div style="color:#64748b;font-size:.78rem">Title tag (<?= mb_strlen((string)$post['meta_title']) ?>/60)</div>
            <div><?= htmlspecialchars((string)$post['meta_title']) ?></div>
          </div>
          <div>
            <div style="color:#64748b;font-size:.78rem">Meta description (<?= mb_strlen((string)$post['meta_description']) ?>/155)</div>
            <div><?= htmlspecialchars((string)$post['meta_description']) ?></div>
          </div>
          <div>
            <div style="color:#64748b;font-size:.78rem">Alt de la imagen</div>
            <div><?= htmlspecialchars((string)($post['imagen_alt'] ?: '—')) ?></div>
          </div>
          <div>
            <div style="color:#64748b;font-size:.78rem">Estructura</div>
            <div>1 h1 · <?= preg_match_all('/<h2\b/i', (string)$post['cuerpo_html']) ?> h2 · <?= preg_match_all('/<h3\b/i', (string)$post['cuerpo_html']) ?> h3 · <?= preg_match_all('/<a\b/i', (string)$post['cuerpo_html']) ?> enlaces internos</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Contenido</h2>
        <div style="max-height:520px;overflow-y:auto;font-size:.9rem;line-height:1.7;color:#cbd5e1">
          <?= $post['cuerpo_html'] ?>
        </div>
      </div>
    </div>

    <div>
      <?php if (!empty($post['imagen_path'])): ?>
        <div class="card">
          <h2>Imagen destacada</h2>
          <img src="/<?= htmlspecialchars(ltrim((string)$post['imagen_path'], '/')) ?>"
               alt="<?= htmlspecialchars((string)$post['imagen_alt']) ?>"
               style="width:100%;border-radius:8px">
          <p class="mono" style="color:#475569;font-size:.72rem;margin-top:8px"><?= htmlspecialchars((string)$post['imagen_path']) ?></p>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2>Estado</h2>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <select name="estado">
            <option value="publicado"    <?= $post['estado'] === 'publicado' ? 'selected' : '' ?>>Publicado</option>
            <option value="programado"   <?= $post['estado'] === 'programado' ? 'selected' : '' ?>>En cola</option>
            <option value="despublicado" <?= $post['estado'] === 'despublicado' ? 'selected' : '' ?>>Despublicado</option>
          </select>
          <button type="submit" class="btn" style="width:100%;margin-top:14px">Guardar</button>
        </form>
        <p style="color:#475569;font-size:.75rem;margin-top:12px">
          Despublicar lo saca del sitio y del sitemap, pero no borra el contenido.
        </p>
      </div>

      <div class="card">
        <h2>Generación</h2>
        <div style="font-size:.82rem;color:#94a3b8;display:flex;flex-direction:column;gap:8px">
          <div>Modelo: <span style="color:#e2e8f0"><?= htmlspecialchars((string)($post['modelo'] ?: '—')) ?></span></div>
          <div>Tokens: <span style="color:#e2e8f0"><?= (int)$post['tokens_entrada'] ?> in / <?= (int)$post['tokens_salida'] ?> out</span></div>
          <div>Costo: <span style="color:#e2e8f0">USD <?= number_format((float)$post['costo_usd'], 4) ?></span></div>
          <div>Creado: <span style="color:#e2e8f0"><?= htmlspecialchars((string)$post['created_at']) ?></span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>@media(max-width:1000px){.detalle-grid{grid-template-columns:1fr!important}}</style>

<?php admin_foot(); ?>
