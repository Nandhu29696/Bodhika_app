<?php
/**
 * exam/tickets.php — Student: raise a ticket + view own tickets
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['UserInfoId'] ?? 0);

/* ── Helpers ─────────────────────────────────────────────────────────── */
function nextTicketNo(): string {
    $row = Database::fetchOne("SELECT MAX(CAST(SUBSTRING(TicketNo,5) AS UNSIGNED)) AS mx FROM tickets", []);
    $next = (int)($row['mx'] ?? 0) + 1;
    return 'TKT-' . str_pad($next, 6, '0', STR_PAD_LEFT);
}

/* ── POST: raise new ticket ─────────────────────────────────────────── */
$formError = '';
$formOk    = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'raise') {
    Auth::validateCsrf();
    $subject    = trim($_POST['subject']      ?? '');
    $desc       = trim($_POST['description']  ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 1);
    $priority   = in_array($_POST['priority'] ?? '', ['low','medium','high','critical'])
                  ? $_POST['priority'] : 'medium';

    if ($subject === '' || $desc === '') {
        $formError = 'Subject and description are required.';
    } else {
        /* Fetch SLA windows */
        $cat = Database::fetchOne(
            "SELECT SlaHours, ResolutionHours FROM ticket_categories WHERE CategoryId=?",
            [$categoryId]
        );
        $slaH = (int)($cat['SlaHours']        ?? 24);
        $resH = (int)($cat['ResolutionHours']  ?? 72);

        /* Priority multiplier: critical=0.25×, high=0.5× */
        $mult = match($priority) { 'critical'=>0.25, 'high'=>0.5, default=>1.0 };
        $slaDeadline = date('Y-m-d H:i:s', time() + (int)($slaH * $mult * 3600));
        $resDue      = date('Y-m-d H:i:s', time() + (int)($resH * $mult * 3600));

        $ticketNo = nextTicketNo();
        Database::execute(
            "INSERT INTO tickets
               (TicketNo,UserId,CategoryId,Subject,Description,Priority,Status,SlaDeadline,ResolutionDue)
             VALUES (?,?,?,?,?,?,'open',?,?)",
            [$ticketNo, $userId, $categoryId, $subject, $desc, $priority, $slaDeadline, $resDue]
        );
        header('Location: tickets.php?raised=' . urlencode($ticketNo));
        exit;
    }
}

if (!empty($_GET['raised'])) {
    $formOk = 'Ticket <strong>' . htmlspecialchars($_GET['raised']) . '</strong> raised successfully. We\'ll respond within the SLA window.';
}

/* ── Fetch categories ─────────────────────────────────────────────────── */
$categories = Database::fetchAll(
    "SELECT CategoryId, Name, SlaHours FROM ticket_categories WHERE IsActive=1 ORDER BY SortOrder,Name",
    []
);

/* ── Paginated ticket list ────────────────────────────────────────────── */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;
$status  = $_GET['status'] ?? '';
$where   = ['t.UserId = ?'];
$params  = [$userId];
if ($status !== '') { $where[] = 't.Status = ?'; $params[] = $status; }
$whereStr = implode(' AND ', $where);

$total = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS n FROM tickets t WHERE $whereStr", $params
)['n'] ?? 0);

$tickets = Database::fetchAll(
    "SELECT t.TicketId, t.TicketNo, t.Subject, t.Priority, t.Status,
            t.CreatedAt, t.UpdatedAt, t.SlaDeadline, t.ResolvedAt,
            tc.Name AS CategoryName,
            (SELECT COUNT(*) FROM ticket_replies r WHERE r.TicketId=t.TicketId AND r.IsInternal=0) AS ReplyCount
       FROM tickets t
       LEFT JOIN ticket_categories tc ON tc.CategoryId = t.CategoryId
      WHERE $whereStr
      ORDER BY t.UpdatedAt DESC
      LIMIT $perPage OFFSET $offset",
    $params
);
$totalPages = max(1, (int)ceil($total / $perPage));

function statusBadge(string $s): string {
    $map = [
        'open'        => ['#2563eb','#dbeafe','Open'],
        'in_progress' => ['#7c3aed','#ede9fe','In Progress'],
        'waiting'     => ['#d97706','#fef3c7','Waiting'],
        'resolved'    => ['#059669','#d1fae5','Resolved'],
        'closed'      => ['#64748b','#f1f5f9','Closed'],
    ];
    [$c,$bg,$lbl] = $map[$s] ?? ['#64748b','#f1f5f9', ucfirst($s)];
    return "<span style='background:$bg;color:$c;padding:2px 10px;border-radius:20px;font-size:.75rem;font-weight:700;'>$lbl</span>";
}
function priorityBadge(string $p): string {
    $map = [
        'critical' => ['#dc2626','🔴'],
        'high'     => ['#ea580c','🟠'],
        'medium'   => ['#d97706','🟡'],
        'low'      => ['#64748b','⚪'],
    ];
    [$c,$ico] = $map[$p] ?? ['#64748b','⚪'];
    return "<span style='color:$c;font-weight:700;font-size:.8rem;'>$ico " . ucfirst($p) . "</span>";
}
function isSlaBreached(?string $deadline, string $status): bool {
    if ($deadline === null || in_array($status, ['resolved','closed'])) return false;
    return strtotime($deadline) < time();
}

$pageTitle = 'My Tickets';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .ticket-split { display:grid;grid-template-columns:1fr 340px;gap:1.2rem;align-items:start; }
  @media(max-width:860px){ .ticket-split { grid-template-columns:1fr; } }
  .raise-form { background:#fff;border:1px solid var(--clr-border);border-radius:8px;padding:1.2rem; }
  .raise-form h3 { margin:0 0 1rem;font-size:.95rem;color:var(--clr-primary); }
  .breach-badge { background:#fee2e2;color:#dc2626;padding:1px 7px;border-radius:12px;
                  font-size:.7rem;font-weight:700;margin-left:5px; }
</style>

<?php if ($formOk): ?>
  <div class="alert alert-success" style="margin-bottom:1rem;"><?= $formOk ?></div>
<?php endif; ?>

<div class="ticket-split">
  <!-- Left: list -->
  <div>
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2 style="margin:0;font-size:1rem;">🎫 My Tickets <span style="font-size:.8rem;color:var(--tx-muted);font-weight:400;">(<?= $total ?>)</span></h2>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
          <?php foreach ([''=>'All','open'=>'Open','in_progress'=>'In Progress','waiting'=>'Waiting','resolved'=>'Resolved','closed'=>'Closed'] as $v=>$lbl): ?>
            <a href="?status=<?= urlencode($v) ?>"
               class="btn btn-xs <?= $status===$v ? 'btn-primary' : 'btn-secondary' ?>"><?= $lbl ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card-body" style="padding:0;">
        <?php if (!$tickets): ?>
          <div style="text-align:center;padding:2rem;color:var(--tx-muted);">
            No tickets found. Raise one using the form →
          </div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr>
              <th>Ticket #</th>
              <th>Subject</th>
              <th>Category</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Replies</th>
              <th>Raised</th>
            </tr></thead>
            <tbody>
            <?php foreach ($tickets as $t): ?>
            <tr onclick="location.href='ticket-view.php?id=<?= $t['TicketId'] ?>'" style="cursor:pointer;">
              <td style="white-space:nowrap;">
                <a href="ticket-view.php?id=<?= $t['TicketId'] ?>" style="font-weight:700;color:var(--clr-primary);"><?= htmlspecialchars($t['TicketNo']) ?></a>
                <?php if (isSlaBreached($t['SlaDeadline'], $t['Status'])): ?>
                  <span class="breach-badge">SLA!</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars(mb_strimwidth($t['Subject'],0,50,'…')) ?></td>
              <td style="font-size:.82rem;"><?= htmlspecialchars($t['CategoryName'] ?? '—') ?></td>
              <td><?= priorityBadge($t['Priority']) ?></td>
              <td><?= statusBadge($t['Status']) ?></td>
              <td style="text-align:center;"><?= (int)$t['ReplyCount'] ?></td>
              <td style="font-size:.8rem;white-space:nowrap;"><?= date('d M, H:i', strtotime($t['CreatedAt'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div style="display:flex;gap:.4rem;justify-content:center;padding:.8rem;">
          <?php for ($p=1;$p<=$totalPages;$p++): ?>
            <a href="?status=<?= urlencode($status) ?>&page=<?= $p ?>"
               class="btn btn-xs <?= $p===$page ? 'btn-primary' : 'btn-secondary' ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: raise form -->
  <div class="raise-form">
    <h3>➕ Raise a New Ticket</h3>
    <?php if ($formError): ?>
      <div class="alert alert-danger" style="margin-bottom:.8rem;"><?= htmlspecialchars($formError) ?></div>
    <?php endif; ?>
    <form method="post" action="tickets.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
      <input type="hidden" name="action"     value="raise">

      <div class="form-group">
        <label class="form-label">Category <span style="color:#dc2626;">*</span></label>
        <select name="category_id" class="form-control" required>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['CategoryId'] ?>"><?= htmlspecialchars($c['Name']) ?>
              (SLA: <?= $c['SlaHours'] ?>h)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Priority</label>
        <select name="priority" class="form-control">
          <option value="low">⚪ Low</option>
          <option value="medium" selected>🟡 Medium</option>
          <option value="high">🟠 High</option>
          <option value="critical">🔴 Critical</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Subject <span style="color:#dc2626;">*</span></label>
        <input type="text" name="subject" class="form-control" maxlength="200"
               placeholder="Brief summary of the issue" required>
      </div>

      <div class="form-group">
        <label class="form-label">Description <span style="color:#dc2626;">*</span></label>
        <textarea name="description" class="form-control" rows="6"
                  placeholder="Describe the issue in detail — include any error messages, steps to reproduce, exam name, etc." required></textarea>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;">Submit Ticket</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
