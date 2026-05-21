<?php
require_once __DIR__ . '/includes/auth.php';
auth_check();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin/dashboard.php'); exit; }

$db = get_db();

// Update estado/notas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuevo_estado = $_POST['estado'] ?? '';
    $notas        = trim($_POST['notas'] ?? '');
    $estados_validos = ['nuevo','contactado','en_progreso','cerrado'];
    if (in_array($nuevo_estado, $estados_validos)) {
        $stmt = $db->prepare("UPDATE tickets SET estado=?, notas=? WHERE id=?");
        $stmt->execute([$nuevo_estado, $notas, $id]);
        header("Location: /admin/ticket.php?id={$id}&saved=1");
        exit;
    }
}

$ticket = $db->prepare("SELECT * FROM tickets WHERE id=?");
$ticket->execute([$id]);
$t = $ticket->fetch(PDO::FETCH_ASSOC);

if (!$t) { header('Location: /admin/dashboard.php'); exit; }

$conv = json_decode($t['conversacion'] ?? '[]', true) ?: [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ticket #<?= $t['id'] ?> — PREXAcode Admin</title>
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
  .main{margin-left:220px;padding:32px;max-width:900px}
  .back-link{color:#64748b;font-size:.85rem;margin-bottom:20px;display:inline-flex;align-items:center;gap:6px}
  .back-link:hover{color:#22d3ee}
  .ticket-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px}
  .ticket-id{font-size:.85rem;color:#64748b;margin-bottom:4px}
  .ticket-title{font-size:1.4rem;font-weight:700}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
  .card{background:rgba(10,22,40,.7);border:1px solid rgba(109,40,217,.2);border-radius:12px;padding:24px}
  .card h3{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#475569;margin-bottom:16px}
  .info-row{display:flex;gap:12px;margin-bottom:12px;font-size:.9rem}
  .info-label{color:#64748b;min-width:90px;flex-shrink:0}
  .info-value{font-weight:500}
  .saved-msg{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#4ade80;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:.875rem}
  select{padding:10px 14px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:8px;color:#fff;font-size:.9rem;outline:none;width:100%}
  select:focus{border-color:#7c3aed}
  select option{background:#0f2044}
  textarea{width:100%;padding:12px 14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:.875rem;resize:vertical;min-height:100px;outline:none;font-family:inherit;margin-top:8px}
  textarea:focus{border-color:#7c3aed}
  .btn-save{padding:12px 28px;background:linear-gradient(135deg,#6d28d9,#06b6d4);border:none;border-radius:100px;color:#fff;font-size:.9rem;font-weight:600;cursor:pointer;margin-top:16px;transition:.2s}
  .btn-save:hover{opacity:.9}
  .btn-wa{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:linear-gradient(135deg,#25D366,#128C7E);border-radius:100px;font-size:.85rem;font-weight:600;color:#fff;transition:.2s;margin-top:12px}
  .btn-wa:hover{opacity:.9}
  /* Chat */
  .chat-log{display:flex;flex-direction:column;gap:10px;max-height:400px;overflow-y:auto;padding:4px}
  .chat-log::-webkit-scrollbar{width:4px}
  .chat-log::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}
  .msg{max-width:80%;padding:10px 14px;border-radius:14px;font-size:.85rem;line-height:1.5}
  .msg.user{background:linear-gradient(135deg,#6d28d9,#06b6d4);align-self:flex-end;border-bottom-right-radius:3px}
  .msg.bot{background:rgba(109,40,217,.2);border:1px solid rgba(109,40,217,.3);align-self:flex-start;border-bottom-left-radius:3px}
  .msg-role{font-size:.7rem;color:#64748b;margin-bottom:4px}
  .empty-conv{color:#475569;font-size:.875rem;padding:20px;text-align:center}
</style>
</head>
<body>

<div class="sidebar">
  <div class="sidebar-logo">PREXA<span>code</span></div>
  <a href="/admin/dashboard.php">🎫 Tickets</a>
  <a href="/">🌐 Ver sitio</a>
  <div class="sidebar-bottom">
    <a href="/admin/?logout=1">🚪 Cerrar sesión</a>
  </div>
</div>

<div class="main">
  <a href="/admin/dashboard.php" class="back-link">← Volver a tickets</a>

  <?php if (isset($_GET['saved'])): ?>
  <div class="saved-msg">✅ Ticket actualizado correctamente.</div>
  <?php endif; ?>

  <div class="ticket-header">
    <div>
      <div class="ticket-id">Ticket</div>
      <div class="ticket-title">#<?= $t['id'] ?> — <?= htmlspecialchars($t['nombre']) ?></div>
    </div>
    <?php if ($t['telefono']): ?>
    <a class="btn-wa" href="https://wa.me/<?= preg_replace('/\D/','',$t['telefono']) ?>?text=Hola+<?= urlencode($t['nombre']) ?>%2C+te+contacto+desde+PREXAcode+por+tu+solicitud+%23<?= $t['id'] ?>" target="_blank">
      💬 WhatsApp
    </a>
    <?php endif; ?>
  </div>

  <div class="grid">
    <!-- Info del contacto -->
    <div class="card">
      <h3>Datos del contacto</h3>
      <div class="info-row"><span class="info-label">Nombre</span><span class="info-value"><?= htmlspecialchars($t['nombre']) ?></span></div>
      <div class="info-row"><span class="info-label">Email</span><span class="info-value"><a href="mailto:<?= htmlspecialchars($t['email']) ?>" style="color:#22d3ee"><?= htmlspecialchars($t['email']) ?></a></span></div>
      <?php if ($t['telefono']): ?><div class="info-row"><span class="info-label">Teléfono</span><span class="info-value"><?= htmlspecialchars($t['telefono']) ?></span></div><?php endif; ?>
      <?php if ($t['empresa']): ?><div class="info-row"><span class="info-label">Empresa</span><span class="info-value"><?= htmlspecialchars($t['empresa']) ?></span></div><?php endif; ?>
      <?php if ($t['servicio']): ?><div class="info-row"><span class="info-label">Servicio</span><span class="info-value"><?= htmlspecialchars($t['servicio']) ?></span></div><?php endif; ?>
      <div class="info-row"><span class="info-label">Fecha</span><span class="info-value"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></span></div>
      <?php if ($t['resumen']): ?>
      <div style="margin-top:12px;padding:12px;background:rgba(109,40,217,.1);border-radius:8px;font-size:.875rem;color:#c4b5fd">
        <strong style="display:block;margin-bottom:6px">Resumen:</strong><?= htmlspecialchars($t['resumen']) ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Estado y notas -->
    <div class="card">
      <h3>Gestión del ticket</h3>
      <form method="POST">
        <label style="font-size:.85rem;color:#94a3b8;display:block;margin-bottom:8px">Estado</label>
        <select name="estado">
          <option value="nuevo"       <?= $t['estado']==='nuevo'?'selected':'' ?>>🔵 Nuevo</option>
          <option value="contactado"  <?= $t['estado']==='contactado'?'selected':'' ?>>🟣 Contactado</option>
          <option value="en_progreso" <?= $t['estado']==='en_progreso'?'selected':'' ?>>🟡 En progreso</option>
          <option value="cerrado"     <?= $t['estado']==='cerrado'?'selected':'' ?>>⚫ Cerrado</option>
        </select>
        <label style="font-size:.85rem;color:#94a3b8;display:block;margin-top:16px;margin-bottom:4px">Notas internas</label>
        <textarea name="notas" placeholder="Ej: Llamé el lunes, pidió propuesta para el viernes..."><?= htmlspecialchars($t['notas'] ?? '') ?></textarea>
        <button type="submit" class="btn-save">Guardar cambios</button>
      </form>
    </div>
  </div>

  <!-- Conversación -->
  <div class="card">
    <h3>Conversación con el chatbot</h3>
    <?php if (empty($conv)): ?>
    <div class="empty-conv">No hay conversación registrada para este ticket.</div>
    <?php else: ?>
    <div class="chat-log">
      <?php foreach ($conv as $msg):
        if (empty($msg['role']) || empty($msg['content'])) continue;
        $role = $msg['role'] === 'user' ? 'user' : 'bot';
        $label = $msg['role'] === 'user' ? '👤 Usuario' : '🤖 Asistente';
      ?>
      <div class="msg <?= $role ?>">
        <div class="msg-role"><?= $label ?></div>
        <?= nl2br(htmlspecialchars($msg['content'])) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
