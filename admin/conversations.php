<?php
require_once __DIR__ . '/includes/auth.php';
auth_check();

$db = get_db();

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/');
    exit;
}

// Filtros
$busqueda   = trim($_GET['q']    ?? '');
$filtroFecha = trim($_GET['fecha'] ?? '');   // hoy / semana / mes / custom
$desde      = trim($_GET['desde'] ?? '');
$hasta      = trim($_GET['hasta'] ?? '');
$soloLeads  = isset($_GET['leads']);
$pagina     = max(1, (int)($_GET['p'] ?? 1));
$por_pagina = 25;

$where  = [];
$params = [];

if ($busqueda !== '') {
    $where[]  = "(messages LIKE ? OR ip LIKE ?)";
    $s = "%{$busqueda}%";
    array_push($params, $s, $s);
}

if ($soloLeads) {
    $where[]  = "is_lead = 1";
}

// Filtros de fecha
$hoy = date('Y-m-d');
switch ($filtroFecha) {
    case 'hoy':
        $where[]  = "DATE(last_active) = ?";
        $params[] = $hoy;
        break;
    case 'semana':
        $where[]  = "DATE(last_active) >= DATE('now', '-7 days')";
        break;
    case 'mes':
        $where[]  = "DATE(last_active) >= DATE('now', '-30 days')";
        break;
    case 'custom':
        if ($desde) { $where[] = "DATE(last_active) >= ?"; $params[] = $desde; }
        if ($hasta)  { $where[] = "DATE(last_active) <= ?"; $params[] = $hasta; }
        break;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $db->prepare("SELECT COUNT(*) FROM conversations {$whereSQL}");
$totalStmt->execute($params);
$total      = (int)$totalStmt->fetchColumn();
$totalPags  = max(1, ceil($total / $por_pagina));
$offset     = ($pagina - 1) * $por_pagina;

$stmt = $db->prepare("SELECT id, session_id, started_at, last_active, msg_count, is_lead, ip
                      FROM conversations {$whereSQL}
                      ORDER BY last_active DESC
                      LIMIT {$por_pagina} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats rápidas
$stats = $db->query("SELECT
    COUNT(*) as total,
    SUM(is_lead) as leads,
    COALESCE(AVG(msg_count),0) as avg_msgs,
    COUNT(CASE WHEN DATE(last_active)=DATE('now','localtime') THEN 1 END) as hoy
FROM conversations")->fetch(PDO::FETCH_ASSOC);

// Función: extraer extracto de mensajes
function msg_preview(string $json): string {
    $msgs = json_decode($json, true) ?? [];
    foreach ($msgs as $m) {
        if (($m['role'] ?? '') === 'user') {
            return htmlspecialchars(mb_substr($m['content'] ?? '', 0, 60)) . '…';
        }
    }
    return '—';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Conversaciones — PREXAcode Admin</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',system-ui,sans-serif;background:#080e1d;color:#e2e8f0;min-height:100vh}
  a{color:inherit;text-decoration:none}

  .sidebar{position:fixed;top:0;left:0;bottom:0;width:220px;background:#0a1628;border-right:1px solid rgba(109,40,217,.2);padding:28px 20px;display:flex;flex-direction:column;gap:8px}
  .sidebar-logo{font-size:1.2rem;font-weight:800;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,.08)}
  .sidebar-logo span{color:#22d3ee}
  .sidebar a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;font-size:.875rem;color:#94a3b8;transition:.2s}
  .sidebar a:hover,.sidebar a.active{background:rgba(109,40,217,.2);color:#fff}
  .sidebar-bottom{margin-top:auto}
  .sidebar-bottom a{color:#ef4444!important}
  .sidebar-bottom a:hover{background:rgba(239,68,68,.1)!important}

  .main{margin-left:220px;padding:32px}
  .page-header{margin-bottom:28px}
  .page-header h1{font-size:1.4rem}
  .page-header p{color:#64748b;font-size:.875rem;margin-top:4px}

  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
  .stat-card{background:linear-gradient(135deg,rgba(109,40,217,.12),rgba(6,182,212,.08));border:1px solid rgba(109,40,217,.25);border-radius:12px;padding:20px}
  .stat-num{font-size:2rem;font-weight:800;line-height:1;color:#22d3ee}
  .stat-num.lead{color:#a78bfa}
  .stat-num.avg{color:#fbbf24}
  .stat-label{font-size:.8rem;color:#64748b;margin-top:6px}

  .filters{background:rgba(10,22,40,.6);border:1px solid rgba(109,40,217,.2);border-radius:12px;padding:20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
  .filter-group{display:flex;flex-direction:column;gap:6px}
  .filter-label{font-size:.75rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
  .filter-input{padding:8px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:.85rem;outline:none;font-family:inherit}
  .filter-input:focus{border-color:#7c3aed}
  .filter-input::placeholder{color:#475569}
  .btn-group{display:flex;gap:8px;flex-wrap:wrap}
  .btn-f{padding:8px 14px;border-radius:100px;font-size:.8rem;font-weight:600;border:1px solid rgba(255,255,255,.1);background:transparent;color:#94a3b8;cursor:pointer;transition:.2s;font-family:inherit}
  .btn-f.active,.btn-f:hover{background:rgba(109,40,217,.3);border-color:#7c3aed;color:#fff}
  .btn-f.primary{background:linear-gradient(135deg,#6d28d9,#06b6d4);border-color:transparent;color:#fff}
  .btn-f.danger{background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.3);color:#f87171}

  .table-wrap{background:rgba(10,22,40,.6);border:1px solid rgba(109,40,217,.2);border-radius:12px;overflow:hidden}
  table{width:100%;border-collapse:collapse}
  th{padding:14px 16px;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#475569;border-bottom:1px solid rgba(255,255,255,.06)}
  td{padding:14px 16px;font-size:.875rem;border-bottom:1px solid rgba(255,255,255,.04)}
  tr:last-child td{border-bottom:none}
  tr:hover td{background:rgba(109,40,217,.06)}

  .badge-lead{display:inline-block;padding:3px 10px;background:rgba(167,139,250,.2);border:1px solid rgba(167,139,250,.3);border-radius:100px;font-size:.72rem;color:#a78bfa;font-weight:600}
  .badge-conv{display:inline-block;padding:3px 10px;background:rgba(6,182,212,.1);border:1px solid rgba(6,182,212,.2);border-radius:100px;font-size:.72rem;color:#22d3ee;font-weight:600}
  .preview-text{color:#64748b;font-size:.8rem;margin-top:3px;font-style:italic}

  .empty{text-align:center;padding:64px;color:#475569}
  .pagination{display:flex;gap:8px;justify-content:center;margin-top:24px}
  .pagination a,.pagination span{padding:8px 14px;border-radius:8px;font-size:.85rem;border:1px solid rgba(255,255,255,.1);color:#94a3b8}
  .pagination a:hover{border-color:#7c3aed;color:#fff}
  .pagination .current{background:rgba(109,40,217,.3);border-color:#7c3aed;color:#fff}

  /* Modal conversación */
  .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center}
  .modal-overlay.open{display:flex}
  .modal{background:#0f2044;border:1px solid rgba(109,40,217,.3);border-radius:16px;padding:28px;width:100%;max-width:680px;max-height:80vh;overflow-y:auto;position:relative}
  .modal-close{position:absolute;top:16px;right:16px;background:rgba(255,255,255,.1);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center}
  .modal h3{font-size:1.1rem;margin-bottom:6px}
  .modal-meta{font-size:.8rem;color:#64748b;margin-bottom:20px}
  .msg-list{display:flex;flex-direction:column;gap:10px}
  .msg-item{padding:10px 14px;border-radius:12px;font-size:.875rem;line-height:1.5}
  .msg-item.user{background:linear-gradient(135deg,rgba(109,40,217,.3),rgba(6,182,212,.2));border:1px solid rgba(109,40,217,.3);align-self:flex-end;max-width:80%}
  .msg-item.bot{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);max-width:80%}
  .msg-role{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;opacity:.6}
</style>
</head>
<body>

<div class="sidebar">
  <div class="sidebar-logo">PREXA<span>code</span></div>
  <a href="/admin/dashboard.php">🎫 Tickets</a>
  <a href="/admin/conversations.php" class="active">💬 Conversaciones</a>
  <a href="/">🌐 Ver sitio</a>
  <div class="sidebar-bottom">
    <a href="/admin/?logout=1">🚪 Cerrar sesión</a>
  </div>
</div>

<div class="main">
  <div class="page-header">
    <h1>Conversaciones con el bot</h1>
    <p>Todas las sesiones iniciadas con el asistente de IA, sean o no leads.</p>
  </div>

  <!-- Stats -->
  <div class="stats">
    <div class="stat-card">
      <div class="stat-num"><?= number_format($stats['total']) ?></div>
      <div class="stat-label">Total conversaciones</div>
    </div>
    <div class="stat-card">
      <div class="stat-num lead"><?= number_format($stats['leads']) ?></div>
      <div class="stat-label">Terminaron en lead</div>
    </div>
    <div class="stat-card">
      <div class="stat-num avg"><?= number_format((float)$stats['avg_msgs'], 1) ?></div>
      <div class="stat-label">Mensajes promedio</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= number_format($stats['hoy']) ?></div>
      <div class="stat-label">Hoy</div>
    </div>
  </div>

  <!-- Filtros -->
  <form method="GET" action="/admin/conversations.php" class="filters">
    <div class="filter-group">
      <span class="filter-label">Buscar</span>
      <input class="filter-input" type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Texto en conversación, IP..." style="width:220px">
    </div>

    <div class="filter-group">
      <span class="filter-label">Período</span>
      <div class="btn-group">
        <?php foreach (['hoy'=>'Hoy','semana'=>'7 días','mes'=>'30 días','custom'=>'Rango'] as $k=>$l): ?>
        <button type="submit" name="fecha" value="<?= $k ?>" class="btn-f <?= $filtroFecha===$k?'active':'' ?>"><?= $l ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($filtroFecha === 'custom'): ?>
    <div class="filter-group">
      <span class="filter-label">Desde</span>
      <input class="filter-input" type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
    </div>
    <div class="filter-group">
      <span class="filter-label">Hasta</span>
      <input class="filter-input" type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
    </div>
    <input type="hidden" name="fecha" value="custom">
    <?php endif; ?>

    <div class="filter-group">
      <span class="filter-label">Tipo</span>
      <button type="submit" name="leads" value="1" class="btn-f <?= $soloLeads?'active':'' ?>">⭐ Solo leads</button>
    </div>

    <div class="filter-group" style="margin-left:auto;flex-direction:row;gap:8px;align-items:flex-end">
      <button type="submit" class="btn-f primary">Filtrar</button>
      <a href="/admin/conversations.php" class="btn-f danger">✕ Limpiar</a>
    </div>
  </form>

  <!-- Tabla -->
  <div class="table-wrap">
    <?php if (empty($rows)): ?>
    <div class="empty">
      <div style="font-size:3rem;margin-bottom:16px">💬</div>
      <p>No hay conversaciones<?= $busqueda ? " con «{$busqueda}»" : '' ?></p>
    </div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Inicio</th>
          <th>Última actividad</th>
          <th>Mensajes</th>
          <th>Tipo</th>
          <th>Primera consulta</th>
          <th>IP</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $msgs    = json_decode($r['messages'] ?? '[]', true) ?? [];
        $preview = msg_preview($r['messages'] ?? '[]');
      ?>
        <tr>
          <td style="color:#475569;font-size:.8rem">#<?= $r['id'] ?></td>
          <td style="font-size:.8rem;white-space:nowrap"><?= date('d/m/y H:i', strtotime($r['started_at'])) ?></td>
          <td style="font-size:.8rem;white-space:nowrap"><?= date('d/m/y H:i', strtotime($r['last_active'])) ?></td>
          <td style="text-align:center;font-weight:700;color:#22d3ee"><?= $r['msg_count'] ?></td>
          <td>
            <?php if ($r['is_lead']): ?>
              <span class="badge-lead">⭐ Lead</span>
            <?php else: ?>
              <span class="badge-conv">💬 Chat</span>
            <?php endif; ?>
          </td>
          <td style="max-width:200px">
            <div class="preview-text"><?= $preview ?></div>
          </td>
          <td style="font-size:.75rem;color:#475569"><?= htmlspecialchars($r['ip'] ?? '—') ?></td>
          <td>
            <button class="btn-f" onclick="openModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">Ver →</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Paginación -->
  <?php if ($totalPags > 1): ?>
  <div class="pagination">
    <?php
    $qs = http_build_query(array_filter(['q'=>$busqueda,'fecha'=>$filtroFecha,'desde'=>$desde,'hasta'=>$hasta,'leads'=>$soloLeads?1:null]));
    for ($i = 1; $i <= $totalPags; $i++):
    ?>
      <?php if ($i === $pagina): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?<?= $qs ?>&p=<?= $i ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Modal conversación -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)closeModal()">
  <div class="modal" id="modal">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <h3 id="modalTitle">Conversación</h3>
    <div class="modal-meta" id="modalMeta"></div>
    <div class="msg-list" id="modalMsgs"></div>
  </div>
</div>

<script>
function openModal(row) {
  const msgs  = JSON.parse(row.messages || '[]');
  const title = document.getElementById('modalTitle');
  const meta  = document.getElementById('modalMeta');
  const list  = document.getElementById('modalMsgs');

  title.textContent = 'Sesión #' + row.id + (row.is_lead ? ' ⭐ Lead' : '');
  meta.textContent  = 'Inicio: ' + row.started_at + ' · Última actividad: ' + row.last_active + ' · IP: ' + (row.ip || '—');

  list.innerHTML = msgs
    .filter(m => m.role !== 'system')
    .map(m => `
      <div class="msg-item ${m.role}">
        <div class="msg-role">${m.role === 'user' ? '👤 Usuario' : '🤖 Asistente'}</div>
        ${escHtml(m.content || '')}
      </div>
    `).join('');

  document.getElementById('modalOverlay').classList.add('open');
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}
</script>
</body>
</html>
