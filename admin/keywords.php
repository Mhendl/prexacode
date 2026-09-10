<?php
/**
 * Panel de palabras clave (Modulo 5.2) y pesos del motor de decision.
 */

require_once __DIR__ . '/includes/auth.php';
auth_check();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../lib/planner.php';

$db    = get_db();
$aviso = null;

sembrar_si_hace_falta($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok($_POST['csrf'] ?? null)) {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'replanificar') {
        if (function_exists('exec')) {
            $script = escapeshellarg(dirname(__DIR__) . '/bin/replanificar.php');
            @exec('php ' . $script . ' > /dev/null 2>&1 &');
            $aviso = ['info', 'Replanificación lanzada en segundo plano. Recargá en unos segundos.'];
        } else {
            $aviso = ['error', 'exec() deshabilitado: corré php bin/replanificar.php desde la consola.'];
        }
    }

    if ($accion === 'agregar') {
        $kw      = mb_strtolower(trim((string)($_POST['keyword'] ?? '')));
        $cluster = (int)($_POST['cluster_id'] ?? 0);
        if ($kw !== '' && $cluster > 0) {
            $db->prepare("INSERT OR IGNORE INTO blog_keywords (keyword, cluster_id, estado, origen) VALUES (?, ?, 'sugerida', 'manual')")
               ->execute([$kw, $cluster]);
            $aviso = ['ok', 'Keyword agregada a la cola.'];
        }
    }
}

$clusters = $db->query("SELECT c.id, c.nombre, c.etiqueta, c.peso, c.activo,
                               (SELECT COUNT(*) FROM blog_keywords k WHERE k.cluster_id = c.id) AS kws,
                               (SELECT COUNT(*) FROM blog_posts p WHERE p.cluster_id = c.id AND p.estado='publicado') AS posts
                        FROM blog_clusters c ORDER BY c.peso DESC")->fetchAll(PDO::FETCH_ASSOC);

$filtro = $_GET['estado'] ?? 'todos';
$where  = in_array($filtro, ['sugerida','en_cola','publicada','con_trafico'], true) ? 'WHERE k.estado = ?' : '';
$params = $where ? [$filtro] : [];

$stmt = $db->prepare("SELECT k.*, c.etiqueta AS cluster, p.slug
                      FROM blog_keywords k
                      LEFT JOIN blog_clusters c ON c.id = k.cluster_id
                      LEFT JOIN blog_posts p    ON p.id = k.post_id
                      {$where}
                      ORDER BY k.score DESC, k.id DESC
                      LIMIT 200");
$stmt->execute($params);
$keywords = $stmt->fetchAll(PDO::FETCH_ASSOC);

$conteos  = $db->query("SELECT estado, COUNT(*) c FROM blog_keywords GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
$pesoMax  = 0.0;
foreach ($clusters as $c) $pesoMax = max($pesoMax, (float)$c['peso']);
$pesoMax  = $pesoMax ?: 1.0;

$ultimaReplan = $db->query("SELECT inicio FROM blog_runs WHERE tarea='replanificar' AND resultado='ok' ORDER BY id DESC LIMIT 1")->fetchColumn();

admin_head('Keywords');
admin_sidebar('keywords');
?>

<div class="main">
  <div class="page-header">
    <div>
      <h1>Palabras clave</h1>
      <p>
        Última replanificación: <?= $ultimaReplan ? htmlspecialchars((string)$ultimaReplan) : 'nunca' ?>.
        El motor recalcula pesos y propone keywords nuevas cada 7 días.
      </p>
    </div>
    <form method="POST" style="margin:0">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="replanificar">
      <button type="submit" class="btn btn-sec">Replanificar ahora</button>
    </form>
  </div>

  <?php if ($aviso): ?><div class="aviso <?= $aviso[0] ?>"><?= htmlspecialchars($aviso[1]) ?></div><?php endif; ?>

  <div class="aviso info">
    <strong>Qué mide y qué no.</strong> Google no informa con qué término buscó cada visita
    (los buscadores dejaron de enviar la consulta en el referrer). Lo que se mide acá es el
    tráfico orgánico que recibe el artículo de cada keyword, y de ahí se infiere qué tema rinde.
    Para ver las consultas reales hay que conectar Google Search Console.
  </div>

  <div class="card">
    <h2>Pesos del motor de decisión</h2>
    <p style="color:#64748b;font-size:.8rem;margin-bottom:18px">
      A mayor peso, más probabilidad de que el próximo artículo sea de ese eje. Ningún eje baja
      del piso mínimo, para seguir explorando temas que todavía no tuvieron oportunidad.
    </p>
    <?php foreach ($clusters as $c): ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:5px">
          <span style="color:#e2e8f0"><?= htmlspecialchars((string)$c['etiqueta']) ?>
            <span style="color:#475569;font-size:.78rem">· <?= (int)$c['posts'] ?> posts · <?= (int)$c['kws'] ?> keywords</span>
          </span>
          <span style="color:#a78bfa;font-weight:600"><?= number_format((float)$c['peso'], 2) ?></span>
        </div>
        <div style="height:7px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden">
          <div style="height:100%;width:<?= max(3, (int)(((float)$c['peso'] / $pesoMax) * 100)) ?>%;background:linear-gradient(90deg,#6d28d9,#06b6d4)"></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>Agregar keyword manualmente</h2>
    <form method="POST" style="display:grid;grid-template-columns:1fr 240px auto;gap:12px;align-items:end">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="agregar">
      <div><label>Keyword objetivo</label><input type="text" name="keyword" required maxlength="160" placeholder="ej: automatizar remitos en pyme"></div>
      <div>
        <label>Eje temático</label>
        <select name="cluster_id">
          <?php foreach ($clusters as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars((string)$c['etiqueta']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn">Agregar</button>
    </form>
  </div>

  <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <?php
    $etiquetas = ['todos'=>'Todas','sugerida'=>'Sugeridas','publicada'=>'Publicadas','con_trafico'=>'Con tráfico'];
    foreach ($etiquetas as $k => $lbl):
      $n = $k === 'todos' ? array_sum($conteos) : (int)($conteos[$k] ?? 0);
    ?>
      <a href="?estado=<?= $k ?>" class="btn btn-sm <?= $filtro === $k ? '' : 'btn-sec' ?>"><?= $lbl ?> (<?= $n ?>)</a>
    <?php endforeach; ?>
  </div>

  <div class="table-wrap">
    <?php if (!$keywords): ?>
      <div class="empty"><div style="font-size:2.6rem;margin-bottom:12px">🔑</div><p>No hay keywords con ese estado.</p></div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Keyword</th><th style="width:180px">Eje</th><th style="width:130px">Estado</th>
              <th style="width:90px">Visitas</th><th style="width:100px">Orgánicas</th><th style="width:90px">Origen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($keywords as $k): ?>
          <tr>
            <td>
              <?php if ($k['slug']): ?>
                <a href="/admin/blog-post.php?id=<?= (int)$k['post_id'] ?>" style="color:#e2e8f0"><?= htmlspecialchars((string)$k['keyword']) ?></a>
              <?php else: ?>
                <?= htmlspecialchars((string)$k['keyword']) ?>
              <?php endif; ?>
            </td>
            <td style="color:#a78bfa;font-size:.82rem"><?= htmlspecialchars((string)($k['cluster'] ?? '—')) ?></td>
            <td><span class="badge b-<?= htmlspecialchars((string)$k['estado']) ?>"><?= ucfirst(str_replace('_', ' ', (string)$k['estado'])) ?></span></td>
            <td style="color:#22d3ee;font-weight:600"><?= (int)$k['visitas'] ?></td>
            <td style="color:#4ade80;font-weight:600"><?= (int)$k['visitas_organicas'] ?></td>
            <td style="color:#475569;font-size:.78rem"><?= htmlspecialchars((string)$k['origen']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php admin_foot(); ?>
