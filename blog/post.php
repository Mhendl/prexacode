<?php
/**
 * Pagina de un articulo. nginx reescribe /blog/{slug} hacia aca.
 */

require_once __DIR__ . '/../api/_bootstrap.php';
require_once __DIR__ . '/../lib/blog.php';
require_once __DIR__ . '/_layout.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['slug'] ?? '')));

try {
    $db   = db_connect();
    $post = $slug !== '' ? post_por_slug($db, $slug) : null;
} catch (Exception $e) {
    $post = null;
}

if (!$post) {
    http_response_code(404);
    blog_head([
        'meta_title'       => 'Artículo no encontrado — PREXAcode',
        'meta_description' => 'El artículo que buscás no existe o fue dado de baja.',
        'canonical'        => rtrim((string)COMPANY_DOMAIN, '/') . '/blog/',
    ]);
    echo '<main class="bl-wrap"><div class="bl-404">'
       . '<h1>No encontramos ese artículo</h1>'
       . '<p>Puede que haya cambiado de dirección o que ya no esté publicado.</p>'
       . '<a class="bl-btn" href="/blog/">Ver todos los artículos</a>'
       . '</div></main>';
    blog_footer();
    exit;
}

$base       = rtrim((string)COMPANY_DOMAIN, '/');
$url        = $base . '/blog/' . $post['slug'];
$relacionados = posts_relacionados($db, $post, 3);
$fechaTs    = strtotime((string)$post['publicado_at']) ?: time();

blog_head([
    'meta_title'       => $post['meta_title'] ?: $post['titulo'],
    'meta_description' => $post['meta_description'],
    'canonical'        => $url,
    'imagen'           => $post['imagen_path'],
    'schema'           => schema_blogposting($post),
    'es_articulo'      => true,
]);
?>

<main class="bl-wrap">
  <article class="bl-article">

    <nav class="bl-breadcrumb" aria-label="Migas de pan">
      <a href="/">Inicio</a> <span>/</span> <a href="/blog/">Blog</a>
    </nav>

    <!-- Un unico h1 en toda la pagina -->
    <h1><?= htmlspecialchars((string)$post['titulo'], ENT_QUOTES, 'UTF-8') ?></h1>

    <div class="bl-meta">
      <span>PREXAcode</span>
      <span>·</span>
      <time datetime="<?= date('Y-m-d', $fechaTs) ?>"><?= fecha_es($fechaTs) ?></time>
      <?php if ((int)$post['palabras'] > 0): ?>
        <span>·</span><span><?= max(1, (int)round((int)$post['palabras'] / 220)) ?> min de lectura</span>
      <?php endif; ?>
    </div>

    <?php if (!empty($post['imagen_path'])): ?>
      <figure class="bl-hero">
        <img src="/<?= htmlspecialchars(ltrim((string)$post['imagen_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars((string)$post['imagen_alt'], ENT_QUOTES, 'UTF-8') ?>"
             width="1792" height="1024" fetchpriority="high">
      </figure>
    <?php endif; ?>

    <div class="bl-body">
      <?= $post['cuerpo_html'] ?>
    </div>

    <!-- Bloque de conversion: WhatsApp directo -->
    <aside class="bl-cta">
      <h2>¿Tenés este problema en tu operación?</h2>
      <p>Contanos tu caso y te decimos, sin vueltas, si se resuelve con software a medida y por dónde conviene empezar.</p>
      <a class="bl-cta-wa" href="https://wa.me/5491133679492?text=<?= rawurlencode('Hola, leí el artículo "' . $post['titulo'] . '" y quiero consultarles.') ?>"
         target="_blank" rel="noopener">Escribinos por WhatsApp</a>
    </aside>

    <!-- Bloque de conversion: formulario -->
    <section class="bl-form-box" id="consulta">
      <h2>O dejanos tu consulta</h2>
      <p class="bl-form-sub">Te respondemos a la brevedad.</p>

      <form class="bl-form" id="blogLeadForm" novalidate>
        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
        <!-- Trampa anti-spam: un bot completa este campo, una persona no lo ve -->
        <div class="bl-hp"><label>No completar<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="bl-form-row">
          <label>Nombre *<input type="text" name="nombre" required maxlength="120" autocomplete="name"></label>
          <label>Email *<input type="email" name="email" required maxlength="190" autocomplete="email"></label>
        </div>
        <label>Empresa<input type="text" name="empresa" maxlength="120" autocomplete="organization"></label>
        <label>Consulta *<textarea name="consulta" required rows="4" maxlength="2000" placeholder="Contanos qué proceso querés resolver."></textarea></label>

        <button type="submit" class="bl-btn bl-btn-full">Enviar consulta</button>
        <p class="bl-form-msg" id="blogLeadMsg" role="status"></p>
      </form>
    </section>

    <?php if ($relacionados): ?>
    <section class="bl-rel">
      <h2>Seguí leyendo</h2>
      <div class="bl-rel-grid">
        <?php foreach ($relacionados as $r): ?>
          <a class="bl-rel-card" href="/blog/<?= htmlspecialchars((string)$r['slug'], ENT_QUOTES, 'UTF-8') ?>">
            <?php if (!empty($r['imagen_path'])): ?>
              <img src="/<?= htmlspecialchars(ltrim((string)$r['imagen_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                   alt="<?= htmlspecialchars((string)$r['imagen_alt'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" width="400" height="229">
            <?php endif; ?>
            <span><?= htmlspecialchars((string)$r['titulo'], ENT_QUOTES, 'UTF-8') ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

  </article>
</main>

<script>
document.getElementById('blogLeadForm').addEventListener('submit', async function (e) {
  e.preventDefault();
  var form = e.target, msg = document.getElementById('blogLeadMsg');
  var btn  = form.querySelector('button[type="submit"]');
  var d    = Object.fromEntries(new FormData(form).entries());

  if (!d.nombre || !d.email || !d.consulta) {
    msg.textContent = 'Completá nombre, email y consulta.';
    msg.className = 'bl-form-msg error';
    return;
  }

  btn.disabled = true; btn.textContent = 'Enviando...';
  try {
    var res  = await fetch('/api/blog-lead.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(d)
    });
    var data = await res.json();
    if (data.success) {
      form.innerHTML = '<p class="bl-form-ok">' + data.message + '</p>';
    } else {
      msg.textContent = data.error || 'No se pudo enviar. Intentá de nuevo.';
      msg.className = 'bl-form-msg error';
      btn.disabled = false; btn.textContent = 'Enviar consulta';
    }
  } catch (err) {
    msg.textContent = 'No se pudo conectar. Intentá de nuevo.';
    msg.className = 'bl-form-msg error';
    btn.disabled = false; btn.textContent = 'Enviar consulta';
  }
});
</script>

<?php
blog_footer((int)$post['id']);

function fecha_es(int $ts): string {
    $meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return date('j', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}
