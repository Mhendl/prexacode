<?php
require_once __DIR__ . '/includes/auth.php';
auth_check();

$db = get_db();

// Filtros
$filtro_estado = $_GET['estado'] ?? 'todos';
$busqueda      = trim($_GET['q'] ?? '');
$pagina        = max(1, (int)($_GET['p'] ?? 1));
$por_pagina    = 20;

// Query
$where = [];
$params = [];

if ($filtro_estado !== 'todos') {
    $where[] = 'estado = ?';
    $params[] = $filtro_estado;
}
if ($busqueda !== '') {
    $where[] = '(nombre LIKE ? OR email LIKE ? OR empresa LIKE ? OR servicio LIKE ?)';
    $s = "%{$busqueda}%";
    array_push($params, $s, $s, $s, $s);
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $db->prepare("SELECT COUNT(*) FROM tickets {$whereSQL}");
$total->execute($params);
$total_tickets = (int)$total->fetchColumn();
$total_paginas = max(1, ceil($total_tickets / $por_pagina));
$offset = ($pagina - 1) * $por_pagina;

$stmt = $db->prepare("SELECT id, created_at, nombre, email, empresa, servicio, estado FROM tickets {$whereSQL} ORDER BY id DESC LIMIT {$por_pagina} OFFSET {$offset}");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Conteos por estado
$conteos = [];
foreach (['nuevo','contactado','en_progreso','cerrado'] as $e) {
    $r = $db->query("SELECT COUNT(*) FROM tickets WHERE estado = '{$e}'")->fetchColumn();
    $conteos[$e] = (int)$r;
}
$conteos['todos'] = array_sum($conteos);

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tickets — PREXAcode Admin</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',system-ui,sans-serif;background:#080e1d;color:#e2e8f0;min-height:100vh}
  a{color:inherit;text-decoration:none}

  /* Sidebar */
  .sidebar{position:fixed;top:0;left:0;bottom:0;width:220px;background:#0a1628;border-right:1px solid rgba(109,40,217,.2);padding:28px 20px;display:flex;flex-direction:column;gap:8px}
  .sidebar-logo{font-size:1.2rem;font-weight:800;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,.08)}
  .sidebar-logo span{color:#22d3ee}
  .sidebar a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;font-size:.875rem;color:#94a3b8;transition:.2s}
  .sidebar a:hover,.sidebar a.active{background:rgba(109,40,217,.2);color:#fff}
  .sidebar-bottom{margin-top:auto}
  .sidebar-bottom a{color:#ef4444!important}
  .sidebar-bottom a:hover{background:rgba(239,68,68,.1)!important}

  /* Main */
  .main{margin-left:220px;padding:32px}

  /* Header */
  .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px}
  .page-header h1{font-size:1.4rem}
  .page-header p{color:#64748b;font-size:.875rem;margin-top:4px}

  /* Stats */
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
  .stat-card{background:linear-gradient(135deg,rgba(109,40,217,.12),rgba(6,182,212,.08));border:1px solid rgba(109,40,217,.25);border-radius:12px;padding:20px;cursor:pointer;transition:.2s}
  .stat-card:hover{border-color:rgba(109,40,217,.5)}
  .stat-card.active{border-color:#7c3aed;background:rgba(109,40,217,.2)}
  .stat-num{font-size:2rem;font-weight:800;line-height:1}
  .stat-label{font-size:.8rem;color:#64748b;margin-top:6px}
  .s-nuevo .stat-num{color:#22d3ee}
  .s-contactado .stat-num{color:#a78bfa}
  .s-en_progreso .stat-num{color:#fbbf24}
  .s-cerrado .stat-num{color:#6b7280}
  .s-todos .stat-num{color:#e2e8f0}

  /* Filters */
  .filters{display:flex;gap:12px;margin-bottom:20px;align-items:center}
  .search{flex:1;max-width:320px;padding:10px 14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:.875rem;outline:none}
  .search:focus{border-color:#7c3aed}
  .search::placeholder{color:#475569}
  .btn-filter{padding:8px 16px;border-radius:100px;font-size:.8rem;font-weight:600;border:1px solid rgba(255,255,255,.1);background:transparent;color:#94a3b8;cursor:pointer;transition:.2s}
  .btn-filter.active{background:rgba(109,40,217,.3);border-color:#7c3aed;color:#fff}

  /* Table */
  .table-wrap{background:rgba(10,22,40,.6);border:1px solid rgba(109,40,217,.2);border-radius:12px;overflow:hidden}
  table{width:100%;border-collapse:collapse}
  th{padding:14px 16px;text-align:left;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#475569;border-bottom:1px solid rgba(255,255,255,.06)}
  td{padding:14px 16px;font-size:.875rem;border-bottom:1px solid rgba(255,255,255,.04)}
  tr:last-child td{border-bottom:none}
  tr:hover td{background:rgba(109,40,217,.06)}
  .td-id{color:#475569;font-size:.8rem;width:60px}
  .td-nombre{font-weight:500}
  .td-empresa{color:#64748b;font-size:.8rem}
  .td-fecha{color:#475569;font-size:.8rem;white-space:nowrap}
  .td-actions a{padding:6px 12px;border-radius:6px;font-size:.78rem;font-weight:600;background:rgba(109,40,217,.2);border:1px solid rgba(109,40,217,.3);color:#a78bfa;transition:.2s}
  .td-actions a:hover{background:rgba(109,40,217,.35)}

  /* Empty */
  .empty{text-align:center;padding:64px;color:#475569}
  .empty-icon{font-size:3rem;margin-bottom:16px}

  /* Pagination */
  .pagination{display:flex;gap:8px;justify-content:center;margin-top:24px}
  .pagination a,.pagination span{padding:8px 14px;border-radius:8px;font-size:.85rem;border:1px solid rgba(255,255,255,.1);color:#94a3b8}
  .pagination a:hover{border-color:#7c3aed;color:#fff}
  .pagination .current{background:rgba(109,40,217,.3);border-color:#7c3aed;color:#fff}
</style>
</head>
<body>

<div class="sidebar">
  <div class="sidebar-logo">PREXA<span>code</span></div>
  <a href="/admin/dashboard.php" class="active">🎫 Tickets</a>
  <a href="/admin/conversations.php">💬 Conversaciones</a>
  <a href="/">🌐 Ver sitio</a>
  <div class="sidebar-bottom">
    <a href="/admin/?logout=1">🚪 Cerrar sesión</a>
  </div>
</div>

<div class="main">
  <div class="page-header">
    <div>
      <h1>Tickets de contacto</h1>
      <p><?= $total_tickets ?> solicitudes en total</p>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats">
    <?php foreach (['todos'=>'Todos','nuevo'=>'Nuevos','contactado'=>'Contactados','en_progreso'=>'En progreso','cerrado'=>'Cerrados'] as $k=>$label): ?>
    <a href="?estado=<?= $k ?>" class="stat-card s-<?= $k ?> <?= $filtro_estado===$k?'active':'' ?>">
      <div class="stat-num"><?= $conteos[$k] ?? 0 ?></div>
      <div class="stat-label"><?= $label ?></div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <div class="filters">
    <form method="GET" action="/admin/dashboard.php" style="display:flex;gap:10px;width:100%">
      <input type="hidden" name="estado" value="<?= htmlspecialchars($filtro_estado) ?>">
      <input class="search" type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre, email, empresa...">
      <button class="btn-filter active" type="submit">Buscar</button>
      <?php if ($busqueda): ?><a href="?estado=<?= $filtro_estado ?>" class="btn-filter">✕ Limpiar</a><?php endif; ?>
    </form>
  </div>

  <!-- Table -->
  <div class="table-wrap">
    <?php if (empty($tickets)): ?>
    <div class="empty"><div class="empty-icon">🎫</div><p>No hay tickets<?= $busqueda ? " con «{$busqueda}»" : '' ?></p></div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Nombre</th>
          <th>Contacto</th>
          <th>Servicio</th>
          <th>Estado</th>
          <th>Fecha</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
        <tr>
          <td class="td-id">#<?= $t['id'] ?></td>
          <td class="td-nombre">
            <?= htmlspecialchars($t['nombre']) ?>
            <?php if ($t['empresa']): ?><br><span class="td-empresa">🏢 <?= htmlspecialchars($t['empresa']) ?></span><?php endif; ?>
          </td>
          <td style="font-size:.82rem">
            📧 <?= htmlspecialchars($t['email']) ?>
          </td>
          <td style="font-size:.82rem;color:#94a3b8"><?= htmlspecialchars($t['servicio'] ?: '—') ?></td>
          <td><?= estado_badge($t['estado']) ?></td>
          <td class="td-fecha"><?= date('d/m/y H:i', strtotime($t['created_at'])) ?></td>
          <td class="td-actions"><a href="/admin/ticket.php?id=<?= $t['id'] ?>">Ver →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($total_paginas > 1): ?>
  <div class="pagination">
    <?php for ($i=1; $i<=$total_paginas; $i++): ?>
      <?php if ($i === $pagina): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?estado=<?= $filtro_estado ?>&q=<?= urlencode($busqueda) ?>&p=<?= $i ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

</body>
</html>
