<?php
/**
 * Admin/Tickets.php — Admin: all tickets list with filters, assign, bulk status
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

$adminLoginId = (int)($_SESSION['login_id'] ?? $_SESSION['LoginInfoId'] ?? 0);

/* ── Bulk action ─────────────────────────────────────────────────────── */
$bulkMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $ids    = array_map('intval', (array)($_POST['sel'] ?? []));
    $action = $_POST['bulk_action'] ?? '';
    if ($ids && in_array($action, ['open','in_progress','resolved','closed','waiting'])) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $extra = '';
        if ($action === 'resolved') $extra = ", ResolvedAt=NOW(), FirstRepliedAt=IFNULL(FirstRepliedAt,NOW())";
        if ($action === 'closed')   $extra = ", ClosedAt=NOW()";
        Database::execute(
            "UPDATE tickets SET Status=?, UpdatedAt=NOW()$extra WHERE TicketId IN ($placeholders)",
            array_merge([$action], $ids)
        );
        $bulkMsg = count($ids) . ' ticket(s) updated to "' . $action . '".';
    }
}

/* ── Filters ─────────────────────────────────────────────────────────── */
$status    = $_GET['status']   ?? '';
$priority  = $_GET['priority'] ?? '';
$catId     = isset($_GET['catId']) ? (int)$_GET['catId'] : 0;
$search    = trim($_GET['q']   ?? '');
$slaFilter = $_GET['sla']      ?? '';   // 'breached' | ''
$dateFrom  = $_GET['from']     ?? '';
$dateTo    = $_GET['to']       ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($status   !== '') { $where[] = 't.Status = ?';   $params[] = $status; }
if ($priority !== '') { $where[] = 't.Priority = ?'; $params[] = $priority; }
if ($catId    > 0)    { $where[] = 't.CategoryId = ?'; $params[] = $catId; }
if ($search   !== '') { $where[] = '(t.TicketNo LIKE ? OR t.Subject LIKE ? OR u.FstName LIKE ? OR u.LstName LIKE ?)';
                        $like = "%$search%"; $params = array_merge($params, [$like,$like,$like,$like]); }
if ($slaFilter === 'breached') {
    $where[] = 't.SlaDeadline < NOW() AND t.Status NOT IN (\'resolved\',\'closed\')';
}
if ($dateFrom !== '') { $where[] = 'DATE(t.CreatedAt) >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'DATE(t.CreatedAt) <= ?'; $params[] = $dateTo; }

$whereStr = implode(' AND ', $where);

$total = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS n
       FROM tickets t
       LEFT JOIN userinfo u ON u.UserInfoId = t.UserId
      WHERE $whereStr",
    $params
)['n'] ?? 0);

$tickets = Database::fetchAll(
    "SELECT t.TicketId, t.TicketNo, t.Subject, t.Priority, t.Status,
            t.CreatedAt, t.UpdatedAt, t.SlaDeadline, t.ResolutionDue,
            t.FirstRepliedAt, t.ResolvedAt,
            tc.Name AS CategoryName,
            TRIM(CONCAT(COALESCE(u.FstName,''),' ',COALESCE(u.LstName,''))) AS StudentName,
            COALESCE(li.LoginName, CONCAT('#', ABS(t.UserId))) AS LoginName,
            (SELECT COUNT(*) FROM ticket_replies r WHERE r.TicketId=t.TicketId AND r.IsInternal=0) AS ReplyCount,
            (SELECT COUNT(*) FROM ticket_replies r WHERE r.TicketId=t.TicketId AND r.IsInternal=1) AS NoteCount
       FROM tickets t
       LEFT JOIN ticket_categories tc ON tc.CategoryId = t.CategoryId
       LEFT JOIN userinfo u           ON u.UserInfoId = t.UserId
       LEFT JOIN logininfo li         ON li.LoginName = u.LoginName
      WHERE $whereStr
      ORDER BY
        CASE t.Priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
        t.UpdatedAt DESC
      LIMIT $perPage OFFSET $offset",
    $params
);
$totalPages = max(1, (int)ceil($total / $perPage));

$categories = Database::fetchAll(
    "SELECT CategoryId, Name FROM ticket_categories WHERE IsActive=1 ORDER BY SortOrder", []
);

/* Quick stats */
$stats = Database::fetchOne(
    "SELECT
       SUM(Status='open')        AS cnt_open,
       SUM(Status='in_progress') AS cnt_progress,
       SUM(Status='waiting')     AS cnt_waiting,
       SUM(Status='resolved')    AS cnt_resolved,
       SUM(SlaDeadline < NOW() AND Status NOT IN ('resolved','closed')) AS cnt_sla
     FROM tickets", []
);

function statusBadge(string $s): string {
    $m = ['open'=>['#2563eb','#dbeafe','Open'],'in_progress'=>['#7c3aed','#ede9fe','In Progress'],
          'waiting'=>['#d97706','#fef3c7','Waiting'],'resolved'=>['#059669','#d1fae5','Resolved'],
          'closed'=>['#64748b','#f1f5f9','Closed']];
    [$c,$bg,$lbl] = $m[$s] ?? ['#64748b','#f1f5f9', ucfirst($s)];
    return "<span style='background:$bg;color:$c;padding:2px 10px;border-radius:20px;font-size:.73rem;font-weight:700;'>$lbl</span>";
}
function priBadge(string $p): string {
    return match($p) {
        'critical' => "<span style='color:#dc2626;font-weight:800;font-size:.78rem;'>🔴 Critical</span>",
        'high'     => "<span style='color:#ea580c;font-weight:700;font-size:.78rem;'>🟠 High</span>",
        'medium'   => "<span style='color:#d97706;font-weight:700;font-size:.78rem;'>🟡 Medium</span>",
        default    => "<span style='color:#64748b;font-size:.78rem;'>⚪ Low</span>",
    };
}
function slaBreach(?string $dl, string $status): bool {
    return $dl && !in_array($status,['resolved','closed']) && strtotime($dl) < time();
}

$pageTitle = 'All Tickets';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .stat-strip { display:grid;grid-template-columns:repeat(5,1fr);gap:.6rem;margin-bottom:1rem; }
  @media(max-width:700px){ .stat-strip { grid-template-columns:repeat(3,1fr); } }
  .stat-chip { text-align:center;padding:10px;border-radius:8px;border:1px solid var(--clr-border);background:#fff; }
  .stat-chip .num { font-size:1.5rem;font-weight:900; }
  .stat-chip .lbl { font-size:.72rem;color:var(--tx-muted);text-transform:uppercase; }
  .filter-bar { display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:1rem; }
  .filter-bar select, .filter-bar input { font-size:.82rem;padding:5px 8px;height:32px; }
  .breach-tag { background:#fee2e2;color:#dc2626;padding:1px 6px;border-radius:10px;font-size:.68rem;font-weight:700; }
</style>

<?php if ($bulkMsg): ?>
  <div class="alert alert-success" style="margin-bottom:.8rem;"><?= htmlspecialchars($bulkMsg) ?></div>
<?php endif; ?>

<!-- Stats strip -->
<div class="stat-strip">
  <div class="stat-chip"><div class="num" style="color:#2563eb;"><?= (int)($stats['cnt_open'] ?? 0) ?></div><div class="lbl">Open</div></div>
  <div class="stat-chip"><div class="num" style="color:#7c3aed;"><?= (int)($stats['cnt_progress'] ?? 0) ?></div><div class="lbl">In Progress</div></div>
  <div class="stat-chip"><div class="num" style="color:#d97706;"><?= (int)($stats['cnt_waiting'] ?? 0) ?></div><div class="lbl">Waiting</div></div>
  <div class="stat-chip"><div class="num" style="color:#059669;"><?= (int)($stats['cnt_resolved'] ?? 0) ?></div><div class="lbl">Resolved</div></div>
  <div class="stat-chip"><div class="num" style="color:#dc2626;"><?= (int)($stats['cnt_sla'] ?? 0) ?></div><div class="lbl">SLA Breached</div></div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:.8rem;">
  <div class="card-body" style="padding:.7rem;">
    <form method="get" action="" class="filter-bar">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Search ticket/student…" style="min-width:160px;" class="form-control">
      <select name="status" class="form-control">
        <option value="">All Statuses</option>
        <?php foreach (['open','in_progress','waiting','resolved','closed'] as $s): ?>
          <option value="<?=$s?>"<?= $status===$s?' selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="priority" class="form-control">
        <option value="">All Priorities</option>
        <?php foreach (['critical','high','medium','low'] as $p): ?>
          <option value="<?=$p?>"<?= $priority===$p?' selected':'' ?>><?= ucfirst($p) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="catId" class="form-control">
        <option value="0">All Categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?=$c['CategoryId']?>"<?= $catId===$c['CategoryId']?' selected':'' ?>><?= htmlspecialchars($c['Name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="sla" class="form-control">
        <option value="">All SLA</option>
        <option value="breached"<?= $slaFilter==='breached'?' selected':'' ?>>⚠ SLA Breached</option>
      </select>
      <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control" title="From date">
      <input type="date" name="to"   value="<?= htmlspecialchars($dateTo) ?>"   class="form-control" title="To date">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="?" class="btn btn-secondary btn-sm">Clear</a>
      <a href="TicketDashboard.php" class="btn btn-secondary btn-sm" style="margin-left:auto;">📊 Dashboard</a>
    </form>
  </div>
</div>

<!-- Ticket table with bulk actions -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.4rem;">
    <h2 style="margin:0;font-size:1rem;">🎫 Tickets <span style="font-size:.8rem;color:var(--tx-muted);font-weight:400;"><?= $total ?> total</span></h2>
  </div>
  <div class="card-body" style="padding:0;">
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
      <div style="display:flex;gap:.4rem;align-items:center;padding:.6rem 1rem;border-bottom:1px solid var(--clr-border);flex-wrap:wrap;">
        <label style="font-size:.82rem;"><input type="checkbox" id="selAll"> Select all</label>
        <select name="bulk_action" class="form-control" style="width:auto;font-size:.82rem;padding:3px 6px;height:28px;">
          <option value="">— Bulk Action —</option>
          <option value="in_progress">Mark In Progress</option>
          <option value="waiting">Mark Waiting</option>
          <option value="resolved">Mark Resolved</option>
          <option value="closed">Mark Closed</option>
          <option value="open">Reopen</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-xs">Apply</button>
      </div>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr>
            <th style="width:32px;"></th>
            <th>Ticket #</th>
            <th>Subject</th>
            <th>Student</th>
            <th>Category</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Replies</th>
            <th>SLA</th>
            <th>Raised</th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php if (!$tickets): ?>
            <tr><td colspan="11" style="text-align:center;padding:1.5rem;color:var(--tx-muted);">No tickets match the current filters.</td></tr>
          <?php endif; ?>
          <?php foreach ($tickets as $t): ?>
          <?php $breached = slaBreach($t['SlaDeadline'], $t['Status']); ?>
          <tr style="<?= $breached ? 'background:#fff5f5;' : '' ?>">
            <td><input type="checkbox" name="sel[]" value="<?= $t['TicketId'] ?>" class="row-sel"></td>
            <td style="white-space:nowrap;font-weight:700;font-size:.83rem;">
              <a href="TicketView.php?id=<?= $t['TicketId'] ?>" style="color:var(--clr-primary);"><?= htmlspecialchars($t['TicketNo']) ?></a>
              <?php if ($breached): ?><span class="breach-tag">SLA!</span><?php endif; ?>
            </td>
            <td style="max-width:220px;">
              <a href="TicketView.php?id=<?= $t['TicketId'] ?>" style="color:inherit;text-decoration:none;">
                <?= htmlspecialchars(mb_strimwidth($t['Subject'],0,60,'…')) ?>
              </a>
            </td>
            <td style="font-size:.82rem;">
              <?= htmlspecialchars(trim($t['StudentName']) ?: $t['LoginName']) ?>
            </td>
            <td style="font-size:.78rem;"><?= htmlspecialchars($t['CategoryName'] ?? '—') ?></td>
            <td><?= priBadge($t['Priority']) ?></td>
            <td><?= statusBadge($t['Status']) ?></td>
            <td style="text-align:center;font-size:.82rem;">
              <?= (int)$t['ReplyCount'] ?>
              <?php if ((int)$t['NoteCount'] > 0): ?>
                <span style="color:var(--tx-muted);">(+<?= (int)$t['NoteCount'] ?> note<?= $t['NoteCount']>1?'s':'' ?>)</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem;white-space:nowrap;">
              <?php if ($t['SlaDeadline']): ?>
                <?php if ($breached): ?>
                  <span style="color:#dc2626;font-weight:700;">Overdue</span><br>
                  <span style="color:#dc2626;"><?= date('d M H:i', strtotime($t['SlaDeadline'])) ?></span>
                <?php elseif (!in_array($t['Status'],['resolved','closed'])): ?>
                  <?= date('d M H:i', strtotime($t['SlaDeadline'])) ?>
                <?php else: ?>
                  <span style="color:#059669;">✓ Met</span>
                <?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="font-size:.78rem;white-space:nowrap;"><?= date('d M, H:i', strtotime($t['CreatedAt'])) ?></td>
            <td>
              <a href="TicketView.php?id=<?= $t['TicketId'] ?>" class="btn btn-xs btn-secondary">View</a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div style="display:flex;gap:.3rem;justify-content:center;padding:.7rem;">
        <?php for ($p=1;$p<=$totalPages;$p++): ?>
          <a href="?status=<?=urlencode($status)?>&priority=<?=urlencode($priority)?>&catId=<?=$catId?>&q=<?=urlencode($search)?>&sla=<?=urlencode($slaFilter)?>&from=<?=urlencode($dateFrom)?>&to=<?=urlencode($dateTo)?>&page=<?=$p?>"
             class="btn btn-xs <?= $p===$page?'btn-primary':'btn-secondary'?>"><?=$p?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<script>
document.getElementById('selAll').addEventListener('change', function(){
  document.querySelectorAll('.row-sel').forEach(function(c){ c.checked = this.checked; }, this);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
