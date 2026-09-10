<?php
/**
 * Configuracion del blog autonomo: API key de OpenAI (cifrada), prueba de
 * conexion y parametros de generacion.
 */

require_once __DIR__ . '/includes/auth.php';
auth_check();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/openai.php';

$db = get_db();

// ── Prueba de conexion (AJAX) ──
if (($_GET['ajax'] ?? '') === 'test') {
    header('Content-Type: application/json; charset=utf-8');

    if (!csrf_ok($_POST['csrf'] ?? null)) {
        echo json_encode(['ok' => false, 'error' => 'Sesión expirada, recargá la página.']);
        exit;
    }

    // Si el input viene vacio se prueba la que ya esta guardada
    $key = trim((string)($_POST['api_key'] ?? ''));
    if ($key === '' || str_contains($key, '•')) {
        $key = openai_key($db);
    }

    $r = openai_test($key);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$aviso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $aviso = ['error', 'La sesión expiró. Volvé a intentarlo.'];
    } else {
        $key = trim((string)($_POST['api_key'] ?? ''));
        // Si no la tocaron (viene enmascarada) no la sobreescribimos
        if ($key !== '' && !str_contains($key, '•')) {
            setting_set($db, 'openai_api_key', $key, true);
        }

        setting_set($db, 'blog_activo',           isset($_POST['blog_activo']) ? '1' : '0');
        setting_set($db, 'publicar_automatico',   isset($_POST['publicar_automatico']) ? '1' : '0');
        setting_set($db, 'generar_imagenes',      isset($_POST['generar_imagenes']) ? '1' : '0');
        setting_set($db, 'modelo_texto',          (string)($_POST['modelo_texto'] ?? 'gpt-4o-mini'));
        setting_set($db, 'modelo_imagen',         (string)($_POST['modelo_imagen'] ?? 'dall-e-3'));
        setting_set($db, 'tamano_imagen',         (string)($_POST['tamano_imagen'] ?? '1792x1024'));
        setting_set($db, 'max_posts_semana',      (string)max(1, min(7, (int)($_POST['max_posts_semana'] ?? 3))));
        setting_set($db, 'ventana_metricas_dias', (string)max(7, min(180, (int)($_POST['ventana_metricas_dias'] ?? 30))));
        setting_set($db, 'min_keywords_en_cola',  (string)max(3, min(50, (int)($_POST['min_keywords_en_cola'] ?? 8))));

        $aviso = ['ok', 'Configuración guardada.'];
    }
}

$keyActual   = openai_key($db);
$keyDelPanel = setting_get($db, 'openai_api_key');
$origenKey   = $keyDelPanel ? 'guardada en el panel (cifrada)' : 'tomada de config.php';

$modeloTexto = setting_get($db, 'modelo_texto', 'gpt-4o-mini');
$modeloImg   = setting_get($db, 'modelo_imagen', 'dall-e-3');
$tamanoImg   = setting_get($db, 'tamano_imagen', '1792x1024');

admin_head('Configuración');
admin_sidebar('settings');
?>

<div class="main">
  <div class="page-header">
    <div>
      <h1>Configuración del blog autónomo</h1>
      <p>Credenciales, modelos y ritmo de publicación.</p>
    </div>
  </div>

  <?php if ($aviso): ?>
    <div class="aviso <?= $aviso[0] ?>"><?= htmlspecialchars($aviso[1]) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

    <div class="card">
      <h2>API de OpenAI</h2>

      <label>API Key</label>
      <input type="text" name="api_key" id="apiKey" class="mono" autocomplete="off"
             placeholder="sk-proj-..."
             value="<?= $keyActual ? htmlspecialchars(key_enmascarada($keyActual)) : '' ?>">
      <p style="color:#64748b;font-size:.78rem;margin-top:6px">
        Actualmente <?= $origenKey ?>. Se guarda cifrada con AES-256-GCM (sodium).
        Dejala como está si no querés cambiarla.
      </p>

      <div style="margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <button type="button" class="btn btn-sec" id="btnTest">Probar conexión</button>
        <span id="testMsg" style="font-size:.875rem"></span>
      </div>
    </div>

    <div class="card">
      <h2>Generación</h2>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px">
        <div>
          <label>Modelo de texto</label>
          <select name="modelo_texto">
            <option value="gpt-4o-mini" <?= $modeloTexto === 'gpt-4o-mini' ? 'selected' : '' ?>>gpt-4o-mini (económico)</option>
            <option value="gpt-4o"      <?= $modeloTexto === 'gpt-4o' ? 'selected' : '' ?>>gpt-4o (mejor calidad, ~16x más caro)</option>
          </select>
        </div>
        <div>
          <label>Modelo de imagen</label>
          <select name="modelo_imagen">
            <option value="dall-e-3" <?= $modeloImg === 'dall-e-3' ? 'selected' : '' ?>>dall-e-3</option>
          </select>
        </div>
        <div>
          <label>Tamaño de imagen</label>
          <select name="tamano_imagen">
            <option value="1792x1024" <?= $tamanoImg === '1792x1024' ? 'selected' : '' ?>>1792x1024 (panorámica, USD 0.08)</option>
            <option value="1024x1024" <?= $tamanoImg === '1024x1024' ? 'selected' : '' ?>>1024x1024 (cuadrada, USD 0.04)</option>
          </select>
        </div>
        <div>
          <label>Máximo de artículos por semana</label>
          <input type="number" name="max_posts_semana" min="1" max="7"
                 value="<?= (int)setting_int($db, 'max_posts_semana', 3) ?>">
        </div>
        <div>
          <label>Ventana de métricas (días)</label>
          <input type="number" name="ventana_metricas_dias" min="7" max="180"
                 value="<?= (int)setting_int($db, 'ventana_metricas_dias', 30) ?>">
        </div>
        <div>
          <label>Mínimo de keywords en cola</label>
          <input type="number" name="min_keywords_en_cola" min="3" max="50"
                 value="<?= (int)setting_int($db, 'min_keywords_en_cola', 8) ?>">
        </div>
      </div>

      <div style="margin-top:22px;display:flex;flex-direction:column;gap:12px">
        <label style="display:flex;gap:10px;align-items:center;cursor:pointer;margin:0">
          <input type="checkbox" name="blog_activo" style="width:auto" <?= setting_bool($db, 'blog_activo', true) ? 'checked' : '' ?>>
          <span>Blog activo (si lo desactivás, el cron no genera nada)</span>
        </label>
        <label style="display:flex;gap:10px;align-items:center;cursor:pointer;margin:0">
          <input type="checkbox" name="publicar_automatico" style="width:auto" <?= setting_bool($db, 'publicar_automatico', true) ? 'checked' : '' ?>>
          <span>Publicar automáticamente sin revisión (si lo desactivás, quedan en cola)</span>
        </label>
        <label style="display:flex;gap:10px;align-items:center;cursor:pointer;margin:0">
          <input type="checkbox" name="generar_imagenes" style="width:auto" <?= setting_bool($db, 'generar_imagenes', true) ? 'checked' : '' ?>>
          <span>Generar imagen destacada con DALL·E 3</span>
        </label>
      </div>
    </div>

    <button type="submit" class="btn">Guardar configuración</button>
  </form>

  <div class="card" style="margin-top:28px">
    <h2>Tareas automáticas</h2>
    <?php
    $runs = $db->query("SELECT tarea, inicio, resultado, detalle FROM blog_runs ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <?php if (!$runs): ?>
      <p style="color:#475569;font-size:.875rem">Todavía no corrió ninguna tarea.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Tarea</th><th>Cuándo</th><th>Resultado</th><th>Detalle</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): ?>
          <tr>
            <td><?= htmlspecialchars((string)$r['tarea']) ?></td>
            <td style="color:#64748b;font-size:.8rem"><?= htmlspecialchars((string)$r['inicio']) ?></td>
            <td><span class="badge <?= $r['resultado'] === 'ok' ? 'b-publicado' : ($r['resultado'] === 'error' ? 'b-error' : 'b-programado') ?>"><?= htmlspecialchars((string)$r['resultado']) ?></span></td>
            <td style="color:#64748b;font-size:.8rem"><?= htmlspecialchars(mb_substr((string)$r['detalle'], 0, 90)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<script>
document.getElementById('btnTest').addEventListener('click', async function () {
  var btn = this, msg = document.getElementById('testMsg');
  btn.disabled = true; msg.textContent = 'Probando...'; msg.style.color = '#94a3b8';

  var body = new URLSearchParams({
    csrf: document.querySelector('input[name=csrf]').value,
    api_key: document.getElementById('apiKey').value
  });

  try {
    var res = await fetch('?ajax=test', { method: 'POST', body: body });
    var d   = await res.json();
    if (d.ok) {
      msg.textContent = '✅ Conexión correcta (' + d.ms + ' ms, respondió "' + d.respuesta + '")';
      msg.style.color = '#4ade80';
    } else {
      msg.textContent = '❌ ' + d.error;
      msg.style.color = '#f87171';
    }
  } catch (e) {
    msg.textContent = '❌ No se pudo completar la prueba.';
    msg.style.color = '#f87171';
  }
  btn.disabled = false;
});
</script>

<?php admin_foot(); ?>
