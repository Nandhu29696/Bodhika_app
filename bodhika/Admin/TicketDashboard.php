<?php
/**
 * Admin/TicketDashboard.php — Ticket quality & SLA monitoring dashboard
 *
 * Metrics:
 *  • Total / Open / Resolved / SLA Breached (KPI cards)
 *  • SLA compliance rate (first response)
 *  • Tickets by category (bar chart)
 *  • Tickets by status (donut chart)
 *  • Avg first-response time per priority
 *  • Resolution rate over last 7 days (line chart)
 *  • Top 5 overdue tickets table
 *  • Recent resolved tickets table
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

/* ── Date range filter ────────────────────────────────────────────────── */
$days   = max(7, min(365, (int)($_GET['days'] ?? 30)));
$from   = date('Y-m-d', strtotime("-{$days} days"));
$toDate = date('Y-m-d');

/* ── KPI stats ─────────────────────────────────────────────────────────── */
$kpi = Database::fetchOne(
    "SELECT
       COUNT(*)                                                   AS total,
       SUM(Status NOT IN ('resolved','closed'))                   AS open_count,
       SUM(Status='resolved')                                     AS resolved,
       SUM(Status='closed')                                       AS closed_count,
       SUM(Status='open')                                         AS status_open,
       SUM(Status='in_progress')                                  AS status_progress,
       SUM(Status='waiting')                                      AS status_waiting,
       SUM(Status='critical' OR Priority='critical')              AS critical_count,
       SUM(SlaDeadline < NOW() AND Status NOT IN ('resolved','closed')) AS sla_breached,
       SUM(SlaDeadline >= NOW() AND Status NOT IN ('resolved','closed')) AS sla_ok,
       SUM(FirstRepliedAt IS NOT NULL AND FirstRepliedAt <= SlaDeadline) AS replied_within_sla,
       SUM(FirstRepliedAt IS NOT NULL)                            AS replied_total,
       ROUND(AVG(CASE WHEN FirstRepliedAt IS NOT NULL
                      THEN TIMESTAMPDIFF(MINUTE,CreatedAt,FirstRepliedAt) END)/60,1) AS avg_first_response_hrs,
       ROUND(AVG(CASE WHEN ResolvedAt IS NOT NULL
                      THEN TIMESTAMPDIFF(HOUR,CreatedAt,ResolvedAt) END),1)    AS avg_resolution_hrs
     FROM tickets
     WHERE DATE(CreatedAt) BETWEEN ? AND ?",
    [$from, $toDate]
);

$slaRate = $kpi['replied_total'] > 0
    ? round(100 * $kpi['replied_within_sla'] / $kpi['replied_total'], 1)
    : null;

/* ── Tickets by category ────────────────────────────────────────────── */
$byCat = Database::fetchAll(
    "SELECT tc.Name AS cat,
            COUNT(*)                                    AS total,
            SUM(t.Status='open')                        AS open_cnt,
            SUM(t.Status IN ('resolved','closed'))      AS done_cnt,
            SUM(t.SlaDeadline<NOW() AND t.Status NOT IN('resolved','closed')) AS breached
       FROM tickets t
       LEFT JOIN ticket_categories tc ON tc.CategoryId=t.CategoryId
      WHERE DATE(t.CreatedAt) BETWEEN ? AND ?
      GROUP BY tc.CategoryId, tc.Name
      ORDER BY total DESC",
    [$from, $toDate]
);

/* ── Tickets by status ───────────────────────────────────────────────── */
$byStatus = Database::fetchAll(
    "SELECT Status, COUNT(*) AS cnt
       FROM tickets
      WHERE DATE(CreatedAt) BETWEEN ? AND ?
      GROUP BY Status",
    [$from, $toDate]
);

/* ── Avg response time by priority ───────────────────────────────────── */
$byPriority = Database::fetchAll(
    "SELECT Priority,
            COUNT(*)                                                         AS total,
            SUM(FirstRepliedAt IS NOT NULL)                                  AS replied,
            ROUND(AVG(CASE WHEN FirstRepliedAt IS NOT NULL
                           THEN TIMESTAMPDIFF(MINUTE,CreatedAt,FirstRepliedAt) END)/60,1) AS avg_hrs,
            SUM(SlaDeadline<NOW() AND Status NOT IN('resolved','closed'))    AS sla_breached
       FROM tickets
      WHERE DATE(CreatedAt) BETWEEN ? AND ?
      GROUP BY Priority
      ORDER BY FIELD(Priority,'critical','high','medium','low')",
    [$from, $toDate]
);

/* ── Daily resolution trend (last 14 days) ──────────────────────────── */
$trend = Database::fetchAll(
    "SELECT DATE(ResolvedAt) AS day, COUNT(*) AS cnt
       FROM tickets
      WHERE ResolvedAt IS NOT NULL
        AND DATE(ResolvedAt) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
      GROUP BY DATE(ResolvedAt)
      ORDER BY day",
    []
);
$dailyOpened = Database::fetchAll(
    "SELECT DATE(CreatedAt) AS day, COUNT(*) AS cnt
       FROM tickets
      WHERE DATE(CreatedAt) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
      GROUP BY DATE(CreatedAt)
      ORDER BY day",
    []
);

/* Build 14-day arrays */
$trendDays  = [];
$trendRes   = [];
$trendOpen  = [];
for ($i=13; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trendDays[]  = date('d M', strtotime($d));
    $resMap  = array_column($trend, 'cnt', 'day');
    $openMap = array_column($dailyOpened, 'cnt', 'day');
    $trendRes[]   = (int)($resMap[$d]  ?? 0);
    $trendOpen[]  = (int)($openMap[$d] ?? 0);
}

/* ── Top 5 overdue tickets ──────────────────────────────────────────── */
$overdue = Database::fetchAll(
    "SELECT t.TicketId, t.TicketNo, t.Subject, t.Priority, t.Status,
            t.SlaDeadline, t.CreatedAt,
            TRIM(CONCAT(COALESCE(u.FstName,''),' ',COALESCE(u.LstName,''))) AS StudentName
       FROM tickets t
       LEFT JOIN userinfo u ON u.UserInfoId=t.UserId
      WHERE t.SlaDeadline < NOW()
        AND t.Status NOT IN ('resolved','closed')
      ORDER BY t.SlaDeadline ASC
      LIMIT 10",
    []
);

/* ── Recently resolved tickets ──────────────────────────────────────── */
$recentResolved = Database::fetchAll(
    "SELECT t.TicketId, t.TicketNo, t.Subject, t.Priority,
            t.ResolvedAt, t.CreatedAt,
            ROUND(TIMESTAMPDIFF(MINUTE,t.CreatedAt,t.ResolvedAt)/60,1) AS resolution_hrs,
            TRIM(CONCAT(COALESCE(u.FstName,''),' ',COALESCE(u.LstName,''))) AS StudentName
       FROM tickets t
       LEFT JOIN userinfo u ON u.UserInfoId=t.UserId
      WHERE t.ResolvedAt IS NOT NULL
      ORDER BY t.ResolvedAt DESC
      LIMIT 8",
    []
);

/* JSON for charts */
$catLabels   = json_encode(array_column($byCat, 'cat'));
$catTotal    = json_encode(array_map('intval', array_column($byCat, 'total')));
$catOpen     = json_encode(array_map('intval', array_column($byCat, 'open_cnt')));
$catBreached = json_encode(array_map('intval', array_column($byCat, 'breached')));

$statusLabels = json_encode(array_map(fn($r)=>ucfirst(str_replace('_',' ',$r['Status'])), $byStatus));
$statusData   = json_encode(array_map(fn($r)=>(int)$r['cnt'], $byStatus));
$statusColors = json_encode(array_map(function($r){
    return match($r['Status']) {
        'open'        => '#3b82f6',
        'in_progress' => '#8b5cf6',
        'waiting'     => '#f59e0b',
        'resolved'    => '#10b981',
        'closed'      => '#94a3b8',
        default       => '#64748b',
    };
}, $byStatus));

$trendDaysJson = json_encode($trendDays);
$trendResJson  = json_encode($trendRes);
$trendOpenJson = json_encode($trendOpen);

function priBadge(string $p): string {
    return match($p) {
        'critical' => "<span style='color:#dc2626;font-weight:800;font-size:.78rem;'>🔴 Critical</span>",
        'high'     => "<span style='color:#ea580c;font-weight:700;font-size:.78rem;'>🟠 High</span>",
        'medium'   => "<span style='color:#d97706;font-weight:700;font-size:.78rem;'>🟡 Medium</span>",
        default    => "<span style='color:#64748b;font-size:.78rem;'>⚪ Low</span>",
    };
}
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 3600)  return round($diff/60)   . 'm ago';
    if ($diff < 86400) return round($diff/3600)  . 'h ago';
    return round($diff/86400) . 'd ago';
}

$pageTitle = 'Ticket Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .kpi-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:.7rem;margin-bottom:1.2rem; }
  .kpi-card { border-radius:10px;padding:14px 16px;color:#fff;position:relative;overflow:hidden; }
  .kpi-card .kpi-num { font-size:2rem;font-weight:900;line-height:1; }
  .kpi-card .kpi-lbl { font-size:.75rem;font-weight:600;opacity:.9;margin-top:4px;text-transform:uppercase; }
  .kpi-card .kpi-sub { font-size:.72rem;opacity:.75;margin-top:4px; }
  .kpi-card::after   { content:'';position:absolute;right:-18px;top:-18px;width:80px;height:80px;
                        border-radius:50%;background:rgba(255,255,255,.08); }

  .dash-grid-2 { display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem; }
  .dash-grid-3 { display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-bottom:1rem; }
  @media(max-width:820px){
    .dash-grid-2,.dash-grid-3 { grid-template-columns:1fr; }
  }

  .chart-card { background:#fff;border:1px solid var(--clr-border);border-radius:8px;padding:1rem; }
  .chart-card h4 { margin:0 0 .8rem;font-size:.88rem; }

  .sla-bar-wrap { background:#e2e8f0;border-radius:8px;height:14px;overflow:hidden;margin:.4rem 0; }
  .sla-bar { height:100%;border-radius:8px;transition:.3s; }

  .filter-pill { display:flex;gap:.4rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center; }
  .filter-pill a { padding:4px 14px;border-radius:20px;font-size:.8rem;font-weight:600;
                   border:1px solid var(--clr-border);color:var(--tx-muted);text-decoration:none; }
  .filter-pill a.active { background:var(--clr-primary);color:#fff;border-color:var(--clr-primary); }
</style>

<!-- Period filter -->
<div class="filter-pill">
  <span style="font-size:.8rem;color:var(--tx-muted);">Period:</span>
  <?php foreach ([7=>'7 days',14=>'14 days',30=>'30 days',90=>'90 days'] as $d=>$lbl): ?>
    <a href="?days=<?=$d?>" class="<?=$days===$d?'active':''?>"><?=$lbl?></a>
  <?php endforeach; ?>
  <span style="font-size:.78rem;color:var(--tx-muted);margin-left:.5rem;"><?= date('d M', strtotime($from)) ?> – <?= date('d M Y') ?></span>
  <a href="Tickets.php" class="btn btn-secondary btn-xs" style="margin-left:auto;">🎫 All Tickets</a>
</div>

<!-- KPI cards -->
<div class="kpi-grid">
  <div class="kpi-card" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
    <div class="kpi-num"><?= (int)($kpi['total'] ?? 0) ?></div>
    <div class="kpi-lbl">Total Tickets</div>
    <div class="kpi-sub"><?= $days ?>-day window</div>
  </div>
  <div class="kpi-card" style="background:linear-gradient(135deg,#7c3aed,#8b5cf6);">
    <div class="kpi-num"><?= (int)($kpi['open_count'] ?? 0) ?></div>
    <div class="kpi-lbl">Still Open</div>
    <div class="kpi-sub"><?= (int)($kpi['status_progress'] ?? 0) ?> in progress</div>
  </div>
  <div class="kpi-card" style="background:linear-gradient(135deg,#059669,#10b981);">
    <div class="kpi-num"><?= (int)($kpi['resolved'] ?? 0) ?></div>
    <div class="kpi-lbl">Resolved</div>
    <?php $resRate = $kpi['total'] > 0 ? round(100*$kpi['resolved']/$kpi['total']) : 0; ?>
    <div class="kpi-sub"><?= $resRate ?>% resolution rate</div>
  </div>
  <div class="kpi-card" style="background:linear-gradient(135deg,#dc2626,#ef4444);">
    <div class="kpi-num"><?= (int)($kpi['sla_breached'] ?? 0) ?></div>
    <div class="kpi-lbl">SLA Breached</div>
    <div class="kpi-sub">First-response overdue</div>
  </div>
  <div class="kpi-card" style="background:linear-gradient(135deg,#d97706,#f59e0b);">
    <div class="kpi-num"><?= $slaRate !== null ? $slaRate . '%' : 'N/A' ?></div>
    <div class="kpi-lbl">SLA Compliance</div>
    <div class="kpi-sub"><?= (int)($kpi['replied_total'] ?? 0) ?> tickets replied</div>
  </div>
  <div class="kpi-card" style="background:linear-gradient(135deg,#0891b2,#06b6d4);">
    <div class="kpi-num"><?= $kpi['avg_first_response_hrs'] ?? '—' ?>h</div>
    <div class="kpi-lbl">Avg Response</div>
    <div class="kpi-sub">First reply time</div>
  </div>
  <div class="kpi-card" style="background:linear-gradient(135deg,#be185d,#ec4899);">
    <div class="kpi-num"><?= $kpi['avg_resolution_hrs'] ?? '—' ?>h</div>
    <div class="kpi-lbl">Avg Resolution</div>
    <div class="kpi-sub">Resolved tickets</div>
  </div>
</div>

<!-- Row 1: Trend chart + Donut -->
<div class="dash-grid-3">
  <div class="chart-card">
    <h4>📈 Daily Opened vs Resolved (last 14 days)</h4>
    <canvas id="trendChart" height="110"></canvas>
  </div>
  <div class="chart-card">
    <h4>🍩 Tickets by Status</h4>
    <canvas id="statusChart" height="180"></canvas>
  </div>
</div>

<!-- Row 2: By category + by priority -->
<div class="dash-grid-2">
  <div class="chart-card">
    <h4>📂 Tickets by Category</h4>
    <canvas id="catChart" height="140"></canvas>
  </div>
  <div class="chart-card">
    <h4>🎯 Response by Priority</h4>
    <table style="width:100%;font-size:.8rem;border-collapse:collapse;">
      <thead><tr style="border-bottom:1px solid var(--clr-border);">
        <th style="text-align:left;padding:4px 0;">Priority</th>
        <th style="text-align:right;">Tickets</th>
        <th style="text-align:right;">Avg Response</th>
        <th style="text-align:right;">SLA Breach</th>
      </tr></thead>
      <tbody>
      <?php foreach ($byPriority as $bp): ?>
      <tr style="border-bottom:1px solid var(--clr-border);">
        <td style="padding:5px 0;"><?= priBadge($bp['Priority']) ?></td>
        <td style="text-align:right;"><?= (int)$bp['total'] ?></td>
        <td style="text-align:right;"><?= $bp['avg_hrs'] !== null ? $bp['avg_hrs'].'h' : '—' ?></td>
        <td style="text-align:right;color:<?= $bp['sla_breached']>0?'#dc2626':'#059669' ?>;font-weight:700;">
          <?= (int)$bp['sla_breached'] ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$byPriority): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--tx-muted);padding:1rem;">No data yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>

    <!-- SLA compliance bar -->
    <?php if ($slaRate !== null): ?>
    <div style="margin-top:1rem;">
      <div style="display:flex;justify-content:space-between;font-size:.78rem;font-weight:700;margin-bottom:4px;">
        <span>Overall SLA Compliance</span>
        <span style="color:<?= $slaRate>=80?'#059669':($slaRate>=60?'#d97706':'#dc2626') ?>;"><?= $slaRate ?>%</span>
      </div>
      <div class="sla-bar-wrap">
        <div class="sla-bar" style="width:<?= $slaRate ?>%;background:<?= $slaRate>=80?'#10b981':($slaRate>=60?'#f59e0b':'#ef4444') ?>;"></div>
      </div>
      <div style="font-size:.72rem;color:var(--tx-muted);">Target: ≥ 80% within first-response SLA window</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Row 3: Overdue + Recently resolved -->
<div class="dash-grid-2">
  <!-- Overdue -->
  <div class="card">
    <div class="card-header" style="background:#fff5f5;"><h4 style="margin:0;font-size:.88rem;color:#dc2626;">🔴 Overdue Tickets (SLA Breached)</h4></div>
    <div class="card-body" style="padding:0;">
      <?php if (!$overdue): ?>
        <div style="text-align:center;padding:1.5rem;color:#059669;font-weight:600;">✓ No overdue tickets!</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table class="tbl" style="font-size:.78rem;">
          <thead><tr><th>Ticket</th><th>Subject</th><th>Priority</th><th>SLA Due</th><th>Student</th></tr></thead>
          <tbody>
          <?php foreach ($overdue as $o): ?>
          <tr>
            <td><a href="TicketView.php?id=<?= $o['TicketId'] ?>" style="color:var(--clr-primary);font-weight:700;"><?= htmlspecialchars($o['TicketNo']) ?></a></td>
            <td><?= htmlspecialchars(mb_strimwidth($o['Subject'],0,40,'…')) ?></td>
            <td><?= priBadge($o['Priority']) ?></td>
            <td style="color:#dc2626;font-weight:700;"><?= timeAgo($o['SlaDeadline']) ?> overdue</td>
            <td><?= htmlspecialchars(trim($o['StudentName']) ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recently resolved -->
  <div class="card">
    <div class="card-header" style="background:#f0fdf4;"><h4 style="margin:0;font-size:.88rem;color:#059669;">✅ Recently Resolved</h4></div>
    <div class="card-body" style="padding:0;">
      <?php if (!$recentResolved): ?>
        <div style="text-align:center;padding:1.5rem;color:var(--tx-muted);">No resolved tickets yet.</div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table class="tbl" style="font-size:.78rem;">
          <thead><tr><th>Ticket</th><th>Subject</th><th>Priority</th><th>Resolved In</th></tr></thead>
          <tbody>
          <?php foreach ($recentResolved as $r): ?>
          <tr>
            <td><a href="TicketView.php?id=<?= $r['TicketId'] ?>" style="color:var(--clr-primary);font-weight:700;"><?= htmlspecialchars($r['TicketNo']) ?></a></td>
            <td><?= htmlspecialchars(mb_strimwidth($r['Subject'],0,40,'…')) ?></td>
            <td><?= priBadge($r['Priority']) ?></td>
            <td style="color:#059669;"><?= $r['resolution_hrs'] ?>h</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
Chart.defaults.font.size   = 12;

/* Trend line */
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= $trendDaysJson ?>,
    datasets: [
      { label:'Opened',   data:<?= $trendOpenJson ?>, borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,.1)', fill:true, tension:.35, pointRadius:3 },
      { label:'Resolved', data:<?= $trendResJson ?>,  borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.1)', fill:true, tension:.35, pointRadius:3 }
    ]
  },
  options: { responsive:true, plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1}}} }
});

/* Status donut */
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: <?= $statusLabels ?>,
    datasets: [{ data:<?= $statusData ?>, backgroundColor:<?= $statusColors ?>, borderWidth:2 }]
  },
  options: { responsive:true, cutout:'65%', plugins:{legend:{position:'bottom'}} }
});

/* Category bar */
new Chart(document.getElementById('catChart'), {
  type: 'bar',
  data: {
    labels: <?= $catLabels ?>,
    datasets: [
      { label:'Total',    data:<?= $catTotal ?>,    backgroundColor:'rgba(99,102,241,.7)', borderRadius:4 },
      { label:'Open',     data:<?= $catOpen ?>,     backgroundColor:'rgba(239,68,68,.7)',  borderRadius:4 },
      { label:'Breached', data:<?= $catBreached ?>, backgroundColor:'rgba(245,158,11,.7)',borderRadius:4 },
    ]
  },
  options: {
    responsive:true,
    plugins:{ legend:{position:'bottom'} },
    scales:{ y:{ beginAtZero:true, ticks:{stepSize:1} } }
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
