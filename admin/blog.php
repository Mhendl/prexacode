<?php
/**
 * Listado de articulos del blog con sus metricas rapidas (Modulo 6.2).
 */

require_once __DIR__ . '/includes/auth.php';
auth_check();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../lib/blog.php';
require_once __DIR__ . '/../lib/analytics.php';
require_once __DIR__ . '/../lib/settings.php';

$db     = get_db();
$aviso  = null;

// Disparar una generacion manual. Corre en segundo plano: generar un articulo
// con imagen puede tardar mas que el timeout de nginx/PHP-FPM.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'generar') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $aviso = ['error', 'La sesión expiró. Volvé a intentarlo.'];
    } elseif (!function_exists('exec')) {
        $aviso = ['error', 'exec() está deshabilitado: generá desde la consola con php bin/publicar.php'];
    } else {
        $script = escapeshellarg(dirname(__DIR__) . '/bin/publicar.php');
        @exec('php ' . $script . ' > /dev/null 2>&1 &');
        $aviso = ['info', 'Generación lanzada en segundo plano. Suele tardar entre 40 y 90 segundos: recargá la página para verla.'];
    }
}

$filtro    = $_GET['estado'] ?? 'todos';
$porPagina = 15;
$pagina    = max(1, (int)($_GET['p'] ?? 1));

$where  = [];
$params = [];
if (in_array($filtro, ['publicado', 'programado', 'despublicado', 'error'], true)) {
    $where[]  = 'estado = ?';
    $params[] = $filtro;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM blog_posts {$whereSQL}");
$stmt->execute($params);
$total     = (int)$stmt->fetchColumn();
$totalPags = max(1, (int)ceil($total / $porPagina));
$offset    = ($pagina - 1) * $porPagina;

$stmt = $db->prepare("SELECT p.*, c.etiqueta AS cluster
                      FROM blog_posts p
                      LEFT JOIN blog_clusters c ON c.id = p.cluster_id
                      {$whereSQL}
                      ORDER BY COALESCE(p.publicado_at, p.created_at) DESC
                      LIMIT {$porPagina} OFFSET {$offset}");
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$metricas = metricas_posts($db, setting_int($db, 'ventana_metricas_dias', 30));

$conteos = $db->query("SELECT estado, COUNT(*) c FROM blog_posts GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
$costoTotal = (float)$db->query("SELECT COALESCE(SUM(costo_usd),0) FROM blog_posts")->fetchColumn();

admin_head('Artículos');
admin_sidebar('blog');
?>

<div class="main">
  <div class="page-header">
    <div>
      <h1>Artículos del blog</h1>
      <p><?= $total ?> artículos · costo acumulado en OpenAI: USD <?= number_format($costoTotal, 2) ?></p>
    </div>
    <form method="POST" style="margin:0">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="generar">
      <button type="submit" class="btn">Generar uno ahora</button>
    </form>
  </div>

  <?php if ($aviso): ?>
    <div class="aviso <?= $aviso[0] ?>"><?= htmlspecialchars($aviso[1]) ?></div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat-card"><div class="stat-num verde"><?= (int)($conteos['publicado'] ?? 0) ?></div><div class="stat-label">Publicados</div></div>
    <div class="stat-card"><div class="stat-num amarillo"><?= (int)($conteos['programado'] ?? 0) ?></div><div class="stat-label">En cola</div></div>
    <div class="stat-card"><div class="stat-num violeta"><?= (int)($conteos['despublicado'] ?? 0) ?></div><div class="stat-label">Despublicados</div></div>
    <div class="stat-card"><div class="stat-num" style="color:#f87171"><?= (int)($conteos['error'] ?? 0) ?></div><div class="stat-label">Con error</div></div>
  </div>

  <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <?php foreach (['todos'=>'Todos','publicado'=>'Publicados','programado'=>'En cola','despublicado'=>'Despublicados','error'=>'Errores'] as $k => $lbl): ?>
      <a href="?estado=<?= $k ?>" class="btn btn-sm <?= $filtro === $k ? '' : 'btn-sec' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <div class="table-wrap">
    <?php if (!$posts): ?>
      <div class="empty">
        <div style="font-size:2.6rem;margin-bottom:12px">📝</div>
        <p>No hay artículos todavía.</p>
        <p style="font-size:.82rem;margin-top:8px">El cron publica 3 por semana, o podés generar uno ahora.</p>
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th style="width:110px">Imagen</th>
            <th>Artículo</th>
            <th style="width:130px">Estado</th>
            <th style="width:90px">Visitas</th>
            <th style="width:90px">Orgánicas</th>
            <th style="width:80px"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($posts as $p): ?>
          <?php $m = $metricas[(int)$p['id']] ?? ['visitas'=>0,'unicas'=>0,'organicas'=>0,'permanencia'=>0]; ?>
          <tr>
            <td>
              <?php if (!empty($p['imagen_path'])): ?>
                <img src="/<?= htmlspecialchars(ltrim((string)$p['imagen_path'], '/')) ?>"
                     alt="" width="96" height="55"
                     style="border-radius:6px;object-fit:cover;width:96px;height:55px">
              <?php else: ?>
                <div style="width:96px;height:55px;border-radius:6px;background:rgba(255,255,255,.05);display:flex;align-items:center;justify-content:center;color:#334155;font-size:1.2rem">—</div>
              <?php endif; ?>
            </td>
            <td>
              <div style="font-weight:500;color:#e2e8f0;margin-bottom:4px"><?= htmlspecialchars((string)$p['titulo']) ?></div>
              <div style="color:#64748b;font-size:.8rem;margin-bottom:5px">
                <?= htmlspecialchars(mb_substr((string)($p['extracto'] ?: strip_tags((string)$p['cuerpo_html'])), 0, 100)) ?><?= mb_strlen((string)$p['extracto']) > 100 ? '…' : '' ?>
              </div>
              <div style="color:#475569;font-size:.74rem">
                <?php if ($p['cluster']): ?><span style="color:#a78bfa"><?= htmlspecialchars((string)$p['cluster']) ?></span> · <?php endif; ?>
                <?= htmlspecialchars((string)$p['keyword']) ?>
                <?php if ($p['publicado_at']): ?> · <?= date('d/m/y', strtotime((string)$p['publicado_at'])) ?><?php endif; ?>
              </div>
            </td>
            <td><span class="badge b-<?= htmlspecialchars((string)$p['estado']) ?>"><?= ucfirst(htmlspecialchars((string)$p['estado'])) ?></span></td>
            <td style="font-weight:700;color:#22d3ee"><?= $m['unicas'] ?><div style="color:#475569;font-size:.7rem;font-weight:400">únicas</div></td>
            <td style="font-weight:700;color:#4ade80"><?= $m['organicas'] ?><div style="color:#475569;font-size:.7rem;font-weight:400">de Google</div></td>
            <td><a class="btn btn-sm btn-sec" href="/admin/blog-post.php?id=<?= (int)$p['id'] ?>">Ver</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($totalPags > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $totalPags; $i++): ?>
        <?php if ($i === $pagina): ?>
          <span class="current"><?= $i ?></span>
        <?php else: ?>
          <a href="?estado=<?= htmlspecialchars($filtro) ?>&p=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php admin_foot(); ?>
