<?php
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['usuario'] ?? '');
    $pass = $_POST['password'] ?? '';
    if (auth_login($user, $pass)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: /admin/dashboard.php');
        exit;
    }
    $error = 'Usuario o contraseña incorrectos.';
}

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: /admin/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PREXAcode Admin</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',system-ui,sans-serif;background:#0a1628;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center}
  .card{background:linear-gradient(135deg,rgba(109,40,217,.15),rgba(6,182,212,.1));border:1px solid rgba(109,40,217,.3);border-radius:20px;padding:48px 40px;width:100%;max-width:400px}
  .logo{text-align:center;font-size:1.5rem;font-weight:800;margin-bottom:32px}
  .logo span{color:#22d3ee}
  h2{font-size:1.2rem;margin-bottom:24px;color:#e2e8f0;text-align:center}
  label{display:block;font-size:.85rem;margin-bottom:6px;color:#94a3b8}
  input{width:100%;padding:12px 16px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:.95rem;margin-bottom:16px;outline:none;transition:.2s}
  input:focus{border-color:#7c3aed;background:rgba(109,40,217,.1)}
  button{width:100%;padding:14px;background:linear-gradient(135deg,#6d28d9,#06b6d4);border:none;border-radius:100px;color:#fff;font-size:1rem;font-weight:600;cursor:pointer;transition:.2s;margin-top:8px}
  button:hover{opacity:.9;transform:translateY(-1px)}
  .error{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#f87171;padding:12px;border-radius:8px;font-size:.875rem;text-align:center;margin-bottom:16px}
  .back{text-align:center;margin-top:20px;font-size:.85rem;color:#475569}
  .back a{color:#22d3ee}
</style>
</head>
<body>
<div class="card">
  <div class="logo">PREXA<span>code</span> Admin</div>
  <h2>Panel de tickets</h2>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="POST">
    <label>Usuario</label>
    <input type="text" name="usuario" required autocomplete="username" placeholder="admin">
    <label>Contraseña</label>
    <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
    <button type="submit">Ingresar</button>
  </form>
  <p class="back"><a href="/">← Volver al sitio</a></p>
</div>
</body>
</html>
