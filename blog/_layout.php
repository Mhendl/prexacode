<?php
/**
 * Plantilla compartida del blog.
 *
 * El blog es una estructura satelite: se indexa en Google pero no se enlaza
 * desde la home, el menu publico ni ninguna card de la landing. Por eso el
 * header de estas paginas es propio y minimo, y el descubrimiento depende
 * del sitemap.xml.
 */

function blog_head(array $o): void {
    $base   = rtrim((string)COMPANY_DOMAIN, '/');
    $titulo = htmlspecialchars($o['meta_title'] ?? 'Blog', ENT_QUOTES, 'UTF-8');
    $desc   = htmlspecialchars($o['meta_description'] ?? '', ENT_QUOTES, 'UTF-8');
    $canon  = htmlspecialchars($o['canonical'] ?? $base, ENT_QUOTES, 'UTF-8');
    $imagen = !empty($o['imagen']) ? $base . '/' . ltrim((string)$o['imagen'], '/') : $base . '/images/hero-image.png';
    ?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $titulo ?></title>
<meta name="description" content="<?= $desc ?>">
<link rel="canonical" href="<?= $canon ?>">
<meta name="robots" content="index, follow, max-image-preview:large">

<meta property="og:type" content="<?= !empty($o['es_articulo']) ? 'article' : 'website' ?>">
<meta property="og:title" content="<?= $titulo ?>">
<meta property="og:description" content="<?= $desc ?>">
<meta property="og:url" content="<?= $canon ?>">
<meta property="og:image" content="<?= htmlspecialchars($imagen, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:site_name" content="PREXAcode">
<meta name="twitter:card" content="summary_large_image">

<link rel="icon" type="image/png" href="/images/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/blog.css">
<?php if (!empty($o['schema'])): ?>
<script type="application/ld+json">
<?= $o['schema'] ?>
</script>
<?php endif; ?>
</head>
<body>

<header class="bl-nav">
  <div class="bl-nav-inner">
    <a href="/" class="bl-logo" aria-label="PREXAcode">
      <img src="/images/logo-navbar.png" alt="PREXAcode" width="150" height="34">
    </a>
    <nav class="bl-nav-links">
      <a href="/#servicios">Servicios</a>
      <a href="/#proceso">Cómo trabajamos</a>
      <a href="/#contacto" class="bl-nav-cta">Hablemos</a>
    </nav>
  </div>
</header>
<?php
}

function blog_footer(?int $postId = null): void {
    $wa = 'https://wa.me/5491133679492';
    ?>
<footer class="bl-footer">
  <div class="bl-footer-inner">
    <img src="/images/logo-navbar.png" alt="PREXAcode" width="140" height="32">
    <p>Software a medida y agentes de IA para que tu operación deje de depender de tareas manuales.</p>
    <div class="bl-footer-links">
      <a href="/#servicios">Servicios</a>
      <a href="/#proceso">Cómo trabajamos</a>
      <a href="/#contacto">Contacto</a>
      <a href="/blog/">Blog</a>
    </div>
    <p class="bl-copy">&copy; <?= date('Y') ?> PREXAcode. Código que funciona. IA que transforma.</p>
  </div>
</footer>

<a class="bl-wa-float" href="<?= $wa ?>" target="_blank" rel="noopener" aria-label="Escribinos por WhatsApp">
  <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
  <span>Hablemos</span>
</a>

<script>
(function () {
  // Analitica propia: un beacon al entrar y otro con el tiempo de permanencia.
  var inicio = Date.now(), hitId = null;

  fetch('/api/track.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      path: location.pathname,
      referrer: document.referrer || '',
      post_id: <?= $postId !== null ? (int)$postId : 'null' ?>
    })
  }).then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.hit_id) hitId = d.hit_id; })
    .catch(function () {});

  function reportarPermanencia() {
    if (!hitId) return;
    var seg = Math.round((Date.now() - inicio) / 1000);
    if (seg < 2) return;
    var datos = JSON.stringify({ hit_id: hitId, segundos: seg });
    // sendBeacon sobrevive al cierre de la pestaña; fetch no siempre
    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/track.php', new Blob([datos], { type: 'application/json' }));
    }
    hitId = null;
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') reportarPermanencia();
  });
  window.addEventListener('pagehide', reportarPermanencia);
})();
</script>
</body>
</html>
<?php
}
