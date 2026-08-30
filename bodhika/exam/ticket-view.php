<?php
/**
 * exam/ticket-view.php — Student: view single ticket thread + reply
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$userId   = (int)($_SESSION['user_id'] ?? $_SESSION['UserInfoId'] ?? 0);
$ticketId = (int)($_GET['id'] ?? 0);

if (!$ticketId) { header('Location: tickets.php'); exit; }

/* Load ticket — students can only see their own */
$ticket = Database::fetchOne(
    "SELECT t.*, tc.Name AS CategoryName, tc.SlaHours, tc.ResolutionHours
       FROM tickets t
       LEFT JOIN ticket_categories tc ON tc.CategoryId = t.CategoryId
      WHERE t.TicketId = ? AND t.UserId = ?",
    [$ticketId, $userId]
);
if (!$ticket) { header('Location: tickets.php'); exit; }

$error   = '';
$success = '';

/* ── POST: reply or close ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        $body = trim($_POST['body'] ?? '');
        if ($body === '') {
            $error = 'Reply cannot be empty.';
        } else {
            Database::execute(
                "INSERT INTO ticket_replies (TicketId, AuthorId, AuthorRole, Body, IsInternal)
                 VALUES (?,?,'student',?,0)",
                [$ticketId, $userId, $body]
            );
            /* If ticket was waiting, reopen it */
            if ($ticket['Status'] === 'waiting') {
                Database::execute("UPDATE tickets SET Status='open', UpdatedAt=NOW() WHERE TicketId=?", [$ticketId]);
            } else {
                Database::execute("UPDATE tickets SET UpdatedAt=NOW() WHERE TicketId=?", [$ticketId]);
            }
            $success = 'Reply sent.';
            /* Reload ticket */
            $ticket = Database::fetchOne(
                "SELECT t.*, tc.Name AS CategoryName, tc.SlaHours, tc.ResolutionHours
                   FROM tickets t LEFT JOIN ticket_categories tc ON tc.CategoryId=t.CategoryId
                  WHERE t.TicketId=? AND t.UserId=?",
                [$ticketId, $userId]
            );
        }
    }

    if ($action === 'close') {
        Database::execute(
            "UPDATE tickets SET Status='closed', ClosedAt=NOW(), UpdatedAt=NOW() WHERE TicketId=? AND UserId=?",
            [$ticketId, $userId]
        );
        header('Location: tickets.php?closed=1');
        exit;
    }

    if ($action === 'reopen') {
        Database::execute(
            "UPDATE tickets SET Status='open', ClosedAt=NULL, UpdatedAt=NOW() WHERE TicketId=? AND UserId=?",
            [$ticketId, $userId]
        );
        $ticket['Status'] = 'open';
        $success = 'Ticket reopened.';
    }
}

/* ── Replies (public only) ─────────────────────────────────────────── */
$replies = Database::fetchAll(
    "SELECT r.*,
            CASE r.AuthorRole
              WHEN 'admin'  THEN 'Support Team'
              WHEN 'system' THEN 'System'
              ELSE TRIM(CONCAT(COALESCE(u.FstName,''), ' ', COALESCE(u.LstName,'')))
            END AS AuthorName
       FROM ticket_replies r
       LEFT JOIN userinfo u ON u.UserInfoId = r.AuthorId AND r.AuthorRole = 'student'
      WHERE r.TicketId = ? AND r.IsInternal = 0
      ORDER BY r.CreatedAt ASC",
    [$ticketId]
);

/* SLA breach check */
$isSlaBreached = $ticket['SlaDeadline'] && !in_array($ticket['Status'],['resolved','closed'])
                 && strtotime($ticket['SlaDeadline']) < time();
$isResDue      = $ticket['ResolutionDue'] && !in_array($ticket['Status'],['resolved','closed'])
                 && strtotime($ticket['ResolutionDue']) < time();

function statusBadge(string $s): string {
    $map = ['open'=>['#2563eb','#dbeafe','Open'],'in_progress'=>['#7c3aed','#ede9fe','In Progress'],
            'waiting'=>['#d97706','#fef3c7','Waiting'],'resolved'=>['#059669','#d1fae5','Resolved'],
            'closed'=>['#64748b','#f1f5f9','Closed']];
    [$c,$bg,$lbl] = $map[$s] ?? ['#64748b','#f1f5f9', ucfirst($s)];
    return "<span style='background:$bg;color:$c;padding:3px 12px;border-radius:20px;font-size:.8rem;font-weight:700;'>$lbl</span>";
}
function priorityColor(string $p): string {
    return match($p) { 'critical'=>'#dc2626','high'=>'#ea580c','medium'=>'#d97706', default=>'#64748b' };
}

$pageTitle = 'Ticket — ' . $ticket['TicketNo'];
include __DIR__ . '/../includes/header.php';
?>
<style>
  .thread-wrap { max-width:780px; }
  .bubble { padding:14px 18px;border-radius:10px;margin-bottom:14px;position:relative; }
  .bubble-student { background:#eef2ff;border-left:4px solid #6366f1;margin-left:24px; }
  .bubble-admin   { background:#f0fdf4;border-left:4px solid #059669;margin-right:24px; }
  .bubble-system  { background:#f8fafc;border-left:4px solid #94a3b8;font-style:italic; }
  .bubble-meta    { font-size:.75rem;color:var(--tx-muted);margin-bottom:6px; }
  .bubble-body    { font-size:.9rem;white-space:pre-wrap;word-break:break-word; }
  .ticket-meta-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.6rem;
                       margin-bottom:1rem;font-size:.82rem; }
  .meta-chip { background:#f8fafc;border:1px solid var(--clr-border);border-radius:6px;
               padding:6px 10px; }
  .meta-chip .lbl { color:var(--tx-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em; }
  .meta-chip .val { font-weight:700;margin-top:2px; }
</style>

<div class="thread-wrap">
  <div style="margin-bottom:.8rem;">
    <a href="tickets.php" style="color:var(--clr-primary);font-size:.85rem;">← My Tickets</a>
  </div>

  <!-- Ticket header -->
  <div class="card" style="margin-bottom:1rem;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
      <div>
        <span style="font-weight:900;font-size:.95rem;color:var(--clr-primary);"><?= htmlspecialchars($ticket['TicketNo']) ?></span>
        <?= statusBadge($ticket['Status']) ?>
        <?php if ($isSlaBreached): ?><span style="background:#fee2e2;color:#dc2626;padding:2px 9px;border-radius:12px;font-size:.72rem;font-weight:700;margin-left:4px;">⏰ SLA Breached</span><?php endif; ?>
        <?php if ($isResDue): ?><span style="background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:12px;font-size:.72rem;font-weight:700;margin-left:4px;">⚠ Resolution Overdue</span><?php endif; ?>
      </div>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
        <?php if (!in_array($ticket['Status'], ['closed','resolved'])): ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('Close this ticket?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
            <input type="hidden" name="action" value="close">
            <button class="btn btn-xs btn-secondary">✕ Close Ticket</button>
          </form>
        <?php elseif ($ticket['Status'] === 'closed'): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
            <input type="hidden" name="action" value="reopen">
            <button class="btn btn-xs btn-secondary">↩ Reopen</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <h3 style="margin:0 0 .8rem;font-size:1rem;"><?= htmlspecialchars($ticket['Subject']) ?></h3>
      <div class="ticket-meta-grid">
        <div class="meta-chip"><div class="lbl">Category</div><div class="val"><?= htmlspecialchars($ticket['CategoryName'] ?? '—') ?></div></div>
        <div class="meta-chip"><div class="lbl">Priority</div><div class="val" style="color:<?= priorityColor($ticket['Priority']) ?>;"><?= ucfirst($ticket['Priority']) ?></div></div>
        <div class="meta-chip"><div class="lbl">Raised</div><div class="val"><?= date('d M Y H:i', strtotime($ticket['CreatedAt'])) ?></div></div>
        <div class="meta-chip"><div class="lbl">SLA Deadline</div><div class="val" style="color:<?= $isSlaBreached ? '#dc2626' : 'inherit' ?>;"><?= $ticket['SlaDeadline'] ? date('d M, H:i', strtotime($ticket['SlaDeadline'])) : '—' ?></div></div>
        <?php if ($ticket['ResolvedAt']): ?>
        <div class="meta-chip"><div class="lbl">Resolved At</div><div class="val" style="color:#059669;"><?= date('d M Y H:i', strtotime($ticket['ResolvedAt'])) ?></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($error):  ?><div class="alert alert-danger"  style="margin-bottom:.8rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success):?><div class="alert alert-success" style="margin-bottom:.8rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <!-- Thread -->
  <div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><h4 style="margin:0;font-size:.9rem;">💬 Conversation (<?= count($replies) + 1 ?> messages)</h4></div>
    <div class="card-body">
      <!-- Original description as first bubble -->
      <div class="bubble bubble-student">
        <div class="bubble-meta">
          <strong>You</strong> &bull; <?= date('d M Y H:i', strtotime($ticket['CreatedAt'])) ?> &bull; Original message
        </div>
        <div class="bubble-body"><?= htmlspecialchars($ticket['Description']) ?></div>
      </div>

      <?php foreach ($replies as $r): ?>
      <div class="bubble bubble-<?= $r['AuthorRole'] ?>">
        <div class="bubble-meta">
          <strong><?= htmlspecialchars($r['AuthorName'] ?: ($r['AuthorRole'] === 'admin' ? 'Support Team' : 'System')) ?></strong>
          &bull; <?= date('d M Y H:i', strtotime($r['CreatedAt'])) ?>
          <?php if ($r['AuthorRole']==='admin'): ?>&nbsp;<span style="background:#dcfce7;color:#065f46;padding:1px 7px;border-radius:10px;font-size:.7rem;font-weight:700;">Support</span><?php endif; ?>
        </div>
        <div class="bubble-body"><?= htmlspecialchars($r['Body']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Reply form -->
  <?php if (!in_array($ticket['Status'], ['closed'])): ?>
  <div class="card">
    <div class="card-header"><h4 style="margin:0;font-size:.9rem;">✍ Add a Reply</h4></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
        <input type="hidden" name="action"     value="reply">
        <div class="form-group">
          <textarea name="body" class="form-control" rows="5"
                    placeholder="Describe any additional details or updates…" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Send Reply</button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:1rem;color:var(--tx-muted);font-size:.85rem;">
    This ticket is closed. <a href="?id=<?= $ticketId ?>" onclick="document.querySelector('[name=action][value=reopen]')?.closest('form')?.submit();return false;">Reopen it</a> to add more replies.
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
