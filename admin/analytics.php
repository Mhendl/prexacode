<?php
/**
 * Analitica propia (Modulo 5.1): visitas de todo el sitio, incluida la home.
 */

require_once __DIR__ . '/includes/auth.php';
auth_check();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../lib/analytics.php';

$db   = get_db();
$dias = max(1, min(365, (int)($_GET['dias'] ?? 30)));

$resumen  = resumen_sitio($db, $dias);
$paginas  = paginas_top($db, $dias, 20);
$origenes = origenes_trafico($db, $dias);
$serie    = serie_diaria($db, $dias);

$totalHits = (int)$db->query("SELECT COUNT(*) FROM hits")->fetchColumn();
$bots      = (int)$db->query("SELECT COUNT(*) FROM hits WHERE es_bot = 1")->fetchColumn();

$maxDia = $serie ? max($serie) : 1;

$etiquetasOrigen = [
    'organico' => ['Buscadores', '#4ade80'],
    'directo'  => ['Directo',    '#22d3ee'],
    'referido' => ['Referidos',  '#a78bfa'],
    'social'   => ['Redes',      '#fbbf24'],
    'interno'  => ['Interno',    '#64748b'],
];

admin_head('Analítica');
admin_sidebar('analytics');
?>

<div class="main">
  <div class="page-header">
    <div>
      <h1>Analítica del sitio</h1>
      <p>Tracking propio, sin cookies de terceros. La IP se guarda hasheada con sal diaria, nunca en claro.</p>
    </div>
    <div style="display:flex;gap:8px">
      <?php foreach ([7 => '7 días', 30 => '30 días', 90 => '90 días'] as $d => $lbl): ?>
        <a href="?dias=<?= $d ?>" class="btn btn-sm <?= $dias === $d ? '' : 'btn-sec' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($totalHits === 0): ?>
    <div class="aviso info">
      Todavía no se registraron visitas. El tracking se activa cuando el snippet corre en las
      páginas: ya está incluido en el blog y en la home.
    </div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat-card"><div class="stat-num"><?= number_format($resumen['unicas']) ?></div><div class="stat-label">Visitantes únicos</div></div>
    <div class="stat-card"><div class="stat-num violeta"><?= number_format($resumen['visitas']) ?></div><div class="stat-label">Páginas vistas</div></div>
    <div class="stat-card"><div class="stat-num verde"><?= number_format($resumen['organicas']) ?></div><div class="stat-label">Desde buscadores</div></div>
    <div class="stat-card"><div class="stat-num amarillo"><?= number_format($resumen['al_blog']) ?></div><div class="stat-label">Vistas de artículos</div></div>
    <div class="stat-card"><div class="stat-num"><?= $resumen['permanencia'] ?>s</div><div class="stat-label">Permanencia media</div></div>
  </div>

  <div class="card">
    <h2>Visitas por día</h2>
    <?php if (!$serie): ?>
      <p style="color:#475569;font-size:.875rem">Sin datos en el período.</p>
    <?php else: ?>
      <div style="display:flex;align-items:flex-end;gap:3px;height:150px;padding-top:10px">
        <?php foreach ($serie as $fecha => $n): ?>
          <div title="<?= htmlspecialchars((string)$fecha) ?>: <?= (int)$n ?> visitas"
               style="flex:1;min-width:4px;height:<?= max(2, (int)(($n / $maxDia) * 100)) ?>%;
                      background:linear-gradient(180deg,#22d3ee,#6d28d9);border-radius:3px 3px 0 0"></div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;justify-content:space-between;color:#475569;font-size:.72rem;margin-top:8px">
        <span><?= htmlspecialchars((string)array_key_first($serie)) ?></span>
        <span>máx <?= (int)$maxDia ?>/día</span>
        <span><?= htmlspecialchars((string)array_key_last($serie)) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start" class="ana-grid">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Página</th><th style="width:90px">Vistas</th><th style="width:90px">Únicas</th><th style="width:100px">Orgánicas</th></tr></thead>
        <tbody>
        <?php if (!$paginas): ?>
          <tr><td colspan="4" style="color:#475569;text-align:center;padding:32px">Sin datos en el período.</td></tr>
        <?php else: foreach ($paginas as $p): ?>
          <tr>
            <td class="mono" style="font-size:.8rem"><?= htmlspecialchars((string)$p['path']) ?></td>
            <td style="font-weight:600"><?= (int)$p['visitas'] ?></td>
            <td style="color:#22d3ee;font-weight:600"><?= (int)$p['unicas'] ?></td>
            <td style="color:#4ade80;font-weight:600"><?= (int)$p['organicas'] ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div>
      <div class="card">
        <h2>Origen del tráfico</h2>
        <?php if (!$origenes): ?>
          <p style="color:#475569;font-size:.85rem">Sin datos.</p>
        <?php else: ?>
          <?php $totalOrigen = array_sum($origenes) ?: 1; ?>
          <?php foreach ($origenes as $o => $n): ?>
            <?php [$lbl, $color] = $etiquetasOrigen[$o] ?? [ucfirst((string)$o), '#94a3b8']; ?>
            <div style="margin-bottom:12px">
              <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:4px">
                <span><?= $lbl ?></span>
                <span style="color:<?= $color ?>;font-weight:600"><?= (int)$n ?> · <?= round(($n / $totalOrigen) * 100) ?>%</span>
              </div>
              <div style="height:6px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden">
                <div style="height:100%;width:<?= max(2, (int)(($n / $totalOrigen) * 100)) ?>%;background:<?= $color ?>"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Higiene de datos</h2>
        <div style="font-size:.85rem;color:#94a3b8;display:flex;flex-direction:column;gap:8px">
          <div>Hits totales: <span style="color:#e2e8f0"><?= number_format($totalHits) ?></span></div>
          <div>Bots descartados: <span style="color:#e2e8f0"><?= number_format($bots) ?></span></div>
        </div>
        <p style="color:#475569;font-size:.75rem;margin-top:12px">
          Los bots se excluyen de todas las métricas de arriba.
        </p>
      </div>
    </div>
  </div>
</div>

<style>@media(max-width:1000px){.ana-grid{grid-template-columns:1fr!important}}</style>

<?php admin_foot(); ?>
