<?php
/**
 * Layout compartido del panel. Las vistas nuevas lo usan en vez de repetir
 * el bloque de estilos en cada archivo.
 */

function admin_head(string $titulo): void {
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($titulo) ?> — PREXAcode Admin</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',system-ui,sans-serif;background:#080e1d;color:#e2e8f0;min-height:100vh}
  a{color:inherit;text-decoration:none}

  .sidebar{position:fixed;top:0;left:0;bottom:0;width:220px;background:#0a1628;border-right:1px solid rgba(109,40,217,.2);padding:28px 20px;display:flex;flex-direction:column;gap:6px;overflow-y:auto}
  .sidebar-logo{font-size:1.2rem;font-weight:800;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,.08)}
  .sidebar-logo span{color:#22d3ee}
  .sidebar a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;font-size:.875rem;color:#94a3b8;transition:.2s}
  .sidebar a:hover,.sidebar a.active{background:rgba(109,40,217,.2);color:#fff}
  .sidebar-sep{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#334155;margin:16px 0 4px;padding-left:12px}
  .sidebar-bottom{margin-top:auto}
  .sidebar-bottom a{color:#ef4444!important}
  .sidebar-bottom a:hover{background:rgba(239,68,68,.1)!important}

  .main{margin-left:220px;padding:32px}
  .page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;gap:20px;flex-wrap:wrap}
  .page-header h1{font-size:1.4rem}
  .page-header p{color:#64748b;font-size:.875rem;margin-top:4px}

  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:28px}
  .stat-card{background:linear-gradient(135deg,rgba(109,40,217,.12),rgba(6,182,212,.08));border:1px solid rgba(109,40,217,.25);border-radius:12px;padding:20px}
  .stat-num{font-size:1.9rem;font-weight:800;line-height:1;color:#22d3ee}
  .stat-num.violeta{color:#a78bfa} .stat-num.amarillo{color:#fbbf24} .stat-num.verde{color:#4ade80}
  .stat-label{font-size:.78rem;color:#64748b;margin-top:6px}

  .card{background:rgba(10,22,40,.6);border:1px solid rgba(109,40,217,.2);border-radius:12px;padding:24px;margin-bottom:20px}
  .card h2{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#475569;margin-bottom:16px}

  .table-wrap{background:rgba(10,22,40,.6);border:1px solid rgba(109,40,217,.2);border-radius:12px;overflow:hidden}
  table{width:100%;border-collapse:collapse}
  th{padding:14px 16px;text-align:left;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#475569;border-bottom:1px solid rgba(255,255,255,.06)}
  td{padding:14px 16px;font-size:.875rem;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
  tr:last-child td{border-bottom:none}
  tr:hover td{background:rgba(109,40,217,.06)}

  label{display:block;font-size:.85rem;color:#94a3b8;margin-bottom:6px}
  input[type=text],input[type=password],input[type=number],select,textarea{
    width:100%;padding:11px 14px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);
    border-radius:8px;color:#fff;font-size:.92rem;font-family:inherit;outline:none;transition:.2s}
  input:focus,select:focus,textarea:focus{border-color:#7c3aed}
  select option{background:#0f2044}

  .btn{display:inline-block;padding:11px 24px;background:linear-gradient(135deg,#6d28d9,#06b6d4);border:none;border-radius:100px;color:#fff;font-size:.9rem;font-weight:600;cursor:pointer;font-family:inherit;transition:.2s}
  .btn:hover{opacity:.9}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  .btn-sec{background:transparent;border:1px solid rgba(255,255,255,.15);color:#94a3b8}
  .btn-sec:hover{border-color:#7c3aed;color:#fff}
  .btn-sm{padding:7px 14px;font-size:.78rem}

  .aviso{padding:12px 16px;border-radius:8px;font-size:.875rem;margin-bottom:20px}
  .aviso.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80}
  .aviso.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171}
  .aviso.info{background:rgba(6,182,212,.1);border:1px solid rgba(6,182,212,.25);color:#67e8f9}

  .badge{display:inline-block;padding:3px 10px;border-radius:100px;font-size:.72rem;font-weight:600}
  .b-publicado{background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3)}
  .b-programado{background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
  .b-error{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}
  .b-despublicado{background:rgba(107,114,128,.2);color:#9ca3af;border:1px solid rgba(107,114,128,.35)}
  .b-sugerida{background:rgba(148,163,184,.15);color:#94a3b8;border:1px solid rgba(148,163,184,.3)}
  .b-en_cola{background:rgba(251,191,36,.15);color:#fbbf24;border:1px solid rgba(251,191,36,.3)}
  .b-publicada{background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid rgba(167,139,250,.3)}
  .b-con_trafico{background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3)}

  .empty{text-align:center;padding:56px;color:#475569}
  .pagination{display:flex;gap:8px;justify-content:center;margin-top:24px}
  .pagination a,.pagination span{padding:8px 14px;border-radius:8px;font-size:.85rem;border:1px solid rgba(255,255,255,.1);color:#94a3b8}
  .pagination .current{background:rgba(109,40,217,.3);border-color:#7c3aed;color:#fff}
  .mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.82rem}

  @media(max-width:860px){.sidebar{position:static;width:100%;height:auto}.main{margin-left:0;padding:20px}}
</style>
</head>
<body>
    <?php
}

function admin_sidebar(string $activo = ''): void {
    $items = [
        'seccion1'      => 'Captación',
        'dashboard'     => ['🎫 Tickets',        '/admin/dashboard.php'],
        'conversations' => ['💬 Conversaciones', '/admin/conversations.php'],
        'seccion2'      => 'Blog autónomo',
        'blog'          => ['📝 Artículos',      '/admin/blog.php'],
        'keywords'      => ['🔑 Keywords',       '/admin/keywords.php'],
        'analytics'     => ['📊 Analítica',      '/admin/analytics.php'],
        'settings'      => ['⚙️ Configuración',  '/admin/settings.php'],
    ];
    ?>
<div class="sidebar">
  <div class="sidebar-logo">PREXA<span>code</span></div>
  <?php foreach ($items as $clave => $item): ?>
    <?php if (is_string($item)): ?>
      <div class="sidebar-sep"><?= $item ?></div>
    <?php else: ?>
      <a href="<?= $item[1] ?>" class="<?= $activo === $clave ? 'active' : '' ?>"><?= $item[0] ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
  <div class="sidebar-sep">Sitio</div>
  <a href="/" target="_blank">🌐 Ver sitio</a>
  <a href="/blog/" target="_blank">📰 Ver blog</a>
  <div class="sidebar-bottom">
    <a href="/admin/?logout=1">🚪 Cerrar sesión</a>
  </div>
</div>
    <?php
}

function admin_foot(): void {
    echo "\n</body>\n</html>";
}
