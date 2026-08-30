<?php
/**
 * Admin/TicketView.php — Admin: view + manage a single ticket
 * Features: reply, internal note, change status/priority/category/assignment, SLA indicator
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

$adminUserId  = (int)($_SESSION['user_id']   ?? $_SESSION['UserInfoId']  ?? 0);
$adminLoginId = (int)($_SESSION['login_id']  ?? $_SESSION['LoginInfoId'] ?? 0);
$ticketId     = (int)($_GET['id'] ?? 0);

if (!$ticketId) { header('Location: Tickets.php'); exit; }

$ticket = Database::fetchOne(
    "SELECT t.*, tc.Name AS CategoryName, tc.SlaHours, tc.ResolutionHours,
            TRIM(CONCAT(COALESCE(u.FstName,''),' ',COALESCE(u.LstName,''))) AS StudentName,
            COALESCE(li.LoginName,'') AS StudentLogin
       FROM tickets t
       LEFT JOIN ticket_categories tc ON tc.CategoryId = t.CategoryId
       LEFT JOIN userinfo u           ON u.UserInfoId = t.UserId
       LEFT JOIN logininfo li         ON li.LoginName = u.LoginName
      WHERE t.TicketId = ?",
    [$ticketId]
);
if (!$ticket) { header('Location: Tickets.php'); exit; }

$error   = '';
$success = '';

/* ── POST handlers ───────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';

    /* Reply / internal note */
    if ($action === 'reply' || $action === 'note') {
        $body       = trim($_POST['body'] ?? '');
        $isInternal = ($action === 'note') ? 1 : 0;
        if ($body === '') {
            $error = 'Message cannot be empty.';
        } else {
            Database::execute(
                "INSERT INTO ticket_replies (TicketId, AuthorId, AuthorRole, Body, IsInternal)
                 VALUES (?, ?, 'admin', ?, ?)",
                [$ticketId, $adminUserId ?: -$adminLoginId, $body, $isInternal]
            );
            /* Set FirstRepliedAt if first public reply */
            if (!$isInternal && !$ticket['FirstRepliedAt']) {
                Database::execute(
                    "UPDATE tickets SET FirstRepliedAt=NOW(), UpdatedAt=NOW() WHERE TicketId=?",
                    [$ticketId]
                );
            }
            if ($isInternal) {
                Database::execute("UPDATE tickets SET UpdatedAt=NOW() WHERE TicketId=?", [$ticketId]);
            }
            $success = $isInternal ? 'Internal note added.' : 'Reply sent to student.';
        }
    }

    /* Update ticket fields */
    if ($action === 'update') {
        $newStatus   = $_POST['status']   ?? $ticket['Status'];
        $newPriority = $_POST['priority'] ?? $ticket['Priority'];
        $newCatId    = (int)($_POST['category_id'] ?? $ticket['CategoryId']);

        $extra = '';
        $xParams = [];

        if ($newStatus === 'resolved' && $ticket['Status'] !== 'resolved') {
            $extra = ", ResolvedAt=NOW()";
        }
        if ($newStatus === 'closed' && $ticket['Status'] !== 'closed') {
            $extra .= ", ClosedAt=NOW()";
        }
        if (in_array($newStatus, ['open','in_progress']) && $ticket['Status'] === 'closed') {
            $extra .= ", ClosedAt=NULL";
        }

        /* Log audit entries for changed fields */
        $changes = [];
        if ($newStatus   !== $ticket['Status'])   $changes[] = ['status',   $ticket['Status'],            $newStatus];
        if ($newPriority !== $ticket['Priority']) $changes[] = ['priority', $ticket['Priority'],           $newPriority];
        if ($newCatId    !== (int)$ticket['CategoryId']) $changes[] = ['category', $ticket['CategoryId'], $newCatId];

        Database::execute(
            "UPDATE tickets SET Status=?, Priority=?, CategoryId=?, UpdatedAt=NOW()$extra
              WHERE TicketId=?",
            array_merge([$newStatus, $newPriority, $newCatId], $xParams, [$ticketId])
        );

        foreach ($changes as [$f,$old,$new]) {
            Database::execute(
                "INSERT INTO ticket_audit (TicketId,ActorId,ActorRole,Field,OldValue,NewValue)
                 VALUES (?,?,'admin',?,?,?)",
                [$ticketId, $adminUserId ?: -$adminLoginId, $f, (string)$old, (string)$new]
            );
        }

        /* System note */
        if ($changes) {
            $parts = array_map(fn($c) => "{$c[0]}: {$c[1]} → {$c[2]}", $changes);
            Database::execute(
                "INSERT INTO ticket_replies (TicketId,AuthorId,AuthorRole,Body,IsInternal)
                 VALUES (?,?,'system',?,1)",
                [$ticketId, $adminUserId ?: -$adminLoginId, 'Updated: ' . implode('; ', $parts)]
            );
        }

        /* Reload ticket */
        $ticket = Database::fetchOne(
            "SELECT t.*, tc.Name AS CategoryName, tc.SlaHours, tc.ResolutionHours,
                    TRIM(CONCAT(COALESCE(u.FstName,''),' ',COALESCE(u.LstName,''))) AS StudentName,
                    COALESCE(li.LoginName,'') AS StudentLogin
               FROM tickets t
               LEFT JOIN ticket_categories tc ON tc.CategoryId = t.CategoryId
               LEFT JOIN userinfo u           ON u.UserInfoId = t.UserId
               LEFT JOIN logininfo li         ON li.LoginName = u.LoginName
              WHERE t.TicketId = ?",
            [$ticketId]
        );
        $success = 'Ticket updated.';
    }
}

/* ── Load replies (all including internal) ─────────────────────────── */
$replies = Database::fetchAll(
    "SELECT r.*,
            CASE r.AuthorRole
              WHEN 'admin'  THEN TRIM(CONCAT(COALESCE(ua.FstName,'Admin'),' ',COALESCE(ua.LstName,'')))
              WHEN 'system' THEN 'System'
              ELSE TRIM(CONCAT(COALESCE(us.FstName,''),' ',COALESCE(us.LstName,'')))
            END AS AuthorName
       FROM ticket_replies r
       LEFT JOIN userinfo us ON us.UserInfoId = r.AuthorId AND r.AuthorRole = 'student'
       LEFT JOIN userinfo ua ON ua.UserInfoId = r.AuthorId AND r.AuthorRole = 'admin'
      WHERE r.TicketId = ?
      ORDER BY r.CreatedAt ASC",
    [$ticketId]
);

/* Audit log */
$auditLog = Database::fetchAll(
    "SELECT a.*, TRIM(CONCAT(COALESCE(u.FstName,''),' ',COALESCE(u.LstName,''))) AS ActorName
       FROM ticket_audit a
       LEFT JOIN userinfo u ON u.UserInfoId = a.ActorId
      WHERE a.TicketId = ?
      ORDER BY a.CreatedAt DESC
      LIMIT 20",
    [$ticketId]
);

$categories = Database::fetchAll(
    "SELECT CategoryId, Name FROM ticket_categories WHERE IsActive=1 ORDER BY SortOrder", []
);

/* SLA calculations */
$slaBreached = $ticket['SlaDeadline'] && !in_array($ticket['Status'],['resolved','closed'])
               && strtotime($ticket['SlaDeadline']) < time();
$resDue      = $ticket['ResolutionDue'] && !in_array($ticket['Status'],['resolved','closed'])
               && strtotime($ticket['ResolutionDue']) < time();

/* First response time */
$responseTime = '';
if ($ticket['FirstRepliedAt'] && $ticket['CreatedAt']) {
    $diff = strtotime($ticket['FirstRepliedAt']) - strtotime($ticket['CreatedAt']);
    $h = floor($diff/3600); $m = floor(($diff%3600)/60);
    $responseTime = "{$h}h {$m}m";
}

function statusBadge(string $s): string {
    $m = ['open'=>['#2563eb','#dbeafe','Open'],'in_progress'=>['#7c3aed','#ede9fe','In Progress'],
          'waiting'=>['#d97706','#fef3c7','Waiting'],'resolved'=>['#059669','#d1fae5','Resolved'],
          'closed'=>['#64748b','#f1f5f9','Closed']];
    [$c,$bg,$lbl] = $m[$s] ?? ['#64748b','#f1f5f9', ucfirst($s)];
    return "<span style='background:$bg;color:$c;padding:3px 12px;border-radius:20px;font-size:.8rem;font-weight:700;'>$lbl</span>";
}
function priorityColor(string $p): string {
    return match($p) { 'critical'=>'#dc2626','high'=>'#ea580c','medium'=>'#d97706',default=>'#64748b' };
}

$pageTitle = 'Ticket — ' . $ticket['TicketNo'];
include __DIR__ . '/../includes/header.php';
?>
<style>
  .tv-grid { display:grid;grid-template-columns:1fr 280px;gap:1rem;align-items:start; }
  @media(max-width:900px){ .tv-grid { grid-template-columns:1fr; } }
  .bubble { padding:12px 16px;border-radius:9px;margin-bottom:12px; }
  .bubble-student  { background:#eef2ff;border-left:4px solid #6366f1;margin-left:20px; }
  .bubble-admin    { background:#f0fdf4;border-left:4px solid #059669;margin-right:20px; }
  .bubble-admin.internal { background:#fefce8;border-left-color:#d97706; }
  .bubble-system   { background:#f8fafc;border-left:3px solid #cbd5e1;font-size:.8rem;font-style:italic;color:var(--tx-muted); }
  .bubble-meta { font-size:.74rem;color:var(--tx-muted);margin-bottom:5px; }
  .bubble-body { font-size:.88rem;white-space:pre-wrap;word-break:break-word; }
  .reply-tabs  { display:flex;gap:0;border-bottom:2px solid var(--clr-border);margin-bottom:12px; }
  .reply-tab   { padding:7px 16px;font-size:.84rem;font-weight:600;cursor:pointer;
                 border-bottom:3px solid transparent;margin-bottom:-2px;color:var(--tx-muted);background:none;border-top:none;border-left:none;border-right:none; }
  .reply-tab.active { color:var(--clr-primary);border-bottom-color:var(--clr-primary); }
  .info-row { display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--clr-border);font-size:.82rem; }
  .info-row:last-child { border-bottom:none; }
  .info-row .k { color:var(--tx-muted); }
</style>

<div style="margin-bottom:.8rem;">
  <a href="Tickets.php" style="color:var(--clr-primary);font-size:.85rem;">← All Tickets</a>
</div>

<!-- Header card -->
<div class="card" style="margin-bottom:1rem;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
      <span style="font-weight:900;color:var(--clr-primary);"><?= htmlspecialchars($ticket['TicketNo']) ?></span>
      <?= statusBadge($ticket['Status']) ?>
      <?php if ($slaBreached): ?><span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700;">⏰ SLA Breached</span><?php endif; ?>
      <?php if ($resDue): ?><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700;">⚠ Resolution Overdue</span><?php endif; ?>
    </div>
    <div style="font-size:.8rem;color:var(--tx-muted);">
      Last updated: <?= date('d M Y H:i', strtotime($ticket['UpdatedAt'])) ?>
    </div>
  </div>
  <div class="card-body">
    <h3 style="margin:0 0 .5rem;font-size:1rem;"><?= htmlspecialchars($ticket['Subject']) ?></h3>
    <div style="font-size:.82rem;color:var(--tx-muted);">
      From: <strong><?= htmlspecialchars(trim($ticket['StudentName']) ?: $ticket['StudentLogin']) ?></strong>
      &bull; <?= htmlspecialchars($ticket['CategoryName'] ?? '—') ?>
      &bull; <span style="color:<?= priorityColor($ticket['Priority']) ?>;font-weight:700;"><?= ucfirst($ticket['Priority']) ?></span>
      priority &bull; Raised <?= date('d M Y H:i', strtotime($ticket['CreatedAt'])) ?>
    </div>
  </div>
</div>

<?php if ($error):   ?><div class="alert alert-danger"  style="margin-bottom:.8rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" style="margin-bottom:.8rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="tv-grid">
  <!-- Left: thread + reply -->
  <div>
    <!-- Thread -->
    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header"><h4 style="margin:0;font-size:.9rem;">💬 Thread (<?= count($replies)+1 ?> messages)</h4></div>
      <div class="card-body">
        <!-- Original -->
        <div class="bubble bubble-student">
          <div class="bubble-meta"><strong><?= htmlspecialchars(trim($ticket['StudentName']) ?: $ticket['StudentLogin']) ?></strong> &bull; <?= date('d M Y H:i', strtotime($ticket['CreatedAt'])) ?> &bull; Original</div>
          <div class="bubble-body"><?= htmlspecialchars($ticket['Description']) ?></div>
        </div>

        <?php foreach ($replies as $r): ?>
        <?php $cls = $r['AuthorRole'] === 'system' ? 'bubble-system' : ('bubble-' . $r['AuthorRole'] . ($r['IsInternal'] ? ' internal' : '')); ?>
        <div class="bubble <?= $cls ?>">
          <div class="bubble-meta">
            <strong><?= htmlspecialchars($r['AuthorName'] ?: ($r['AuthorRole']==='admin'?'Admin':'System')) ?></strong>
            <?php if ($r['AuthorRole']==='admin'): ?><span style="background:#dcfce7;color:#065f46;padding:1px 6px;border-radius:10px;font-size:.68rem;font-weight:700;">Support</span><?php endif; ?>
            <?php if ($r['IsInternal']): ?><span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:10px;font-size:.68rem;font-weight:700;">Internal Note</span><?php endif; ?>
            &bull; <?= date('d M Y H:i', strtotime($r['CreatedAt'])) ?>
          </div>
          <?php if ($r['AuthorRole'] !== 'system'): ?>
          <div class="bubble-body"><?= htmlspecialchars($r['Body']) ?></div>
          <?php else: ?>
          <?= htmlspecialchars($r['Body']) ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Reply / Note form -->
    <div class="card">
      <div class="card-header">
        <div class="reply-tabs">
          <button type="button" class="reply-tab active" id="tabReply" onclick="switchTab('reply')">✉ Reply to Student</button>
          <button type="button" class="reply-tab"        id="tabNote"  onclick="switchTab('note')">📝 Internal Note</button>
        </div>
      </div>
      <div class="card-body">
        <form method="post" id="replyForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
          <input type="hidden" name="action"     id="replyAction" value="reply">
          <div class="form-group">
            <textarea name="body" class="form-control" rows="5" id="replyBody"
                      placeholder="Write your reply here…" required></textarea>
          </div>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <button type="submit" class="btn btn-primary" id="replyBtn">Send Reply</button>
            <span id="noteHint" style="display:none;font-size:.78rem;background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:12px;">
              ⚠ This note is visible to admins only — not to the student.
            </span>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Right: management panel -->
  <div>
    <!-- Update ticket -->
    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header"><h4 style="margin:0;font-size:.88rem;">⚙ Manage Ticket</h4></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
          <input type="hidden" name="action"     value="update">
          <div class="form-group">
            <label class="form-label" style="font-size:.8rem;">Status</label>
            <select name="status" class="form-control" style="font-size:.83rem;">
              <?php foreach (['open','in_progress','waiting','resolved','closed'] as $s): ?>
                <option value="<?=$s?>"<?= $ticket['Status']===$s?' selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-size:.8rem;">Priority</label>
            <select name="priority" class="form-control" style="font-size:.83rem;">
              <?php foreach (['critical','high','medium','low'] as $p): ?>
                <option value="<?=$p?>"<?= $ticket['Priority']===$p?' selected':'' ?>><?= ucfirst($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-size:.8rem;">Category</label>
            <select name="category_id" class="form-control" style="font-size:.83rem;">
              <?php foreach ($categories as $c): ?>
                <option value="<?=$c['CategoryId']?>"<?= (int)$ticket['CategoryId']===$c['CategoryId']?' selected':'' ?>>
                  <?= htmlspecialchars($c['Name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Update Ticket</button>
        </form>
      </div>
    </div>

    <!-- SLA / Info panel -->
    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header"><h4 style="margin:0;font-size:.88rem;">📋 Ticket Info</h4></div>
      <div class="card-body" style="padding:.6rem 1rem;">
        <div class="info-row"><span class="k">Student</span><span><?= htmlspecialchars(trim($ticket['StudentName']) ?: $ticket['StudentLogin']) ?></span></div>
        <div class="info-row"><span class="k">Raised</span><span><?= date('d M Y H:i', strtotime($ticket['CreatedAt'])) ?></span></div>
        <div class="info-row">
          <span class="k">1st Response SLA</span>
          <span style="color:<?= $slaBreached?'#dc2626':'inherit'?>;font-weight:<?= $slaBreached?'700':'400'?>;">
            <?= $ticket['SlaDeadline'] ? date('d M H:i', strtotime($ticket['SlaDeadline'])) : '—' ?>
            <?= $slaBreached ? ' ⚠' : '' ?>
          </span>
        </div>
        <div class="info-row">
          <span class="k">Resolution Due</span>
          <span style="color:<?= $resDue?'#d97706':'inherit'?>;">
            <?= $ticket['ResolutionDue'] ? date('d M H:i', strtotime($ticket['ResolutionDue'])) : '—' ?>
          </span>
        </div>
        <?php if ($ticket['FirstRepliedAt']): ?>
        <div class="info-row"><span class="k">1st Reply</span><span style="color:#059669;"><?= date('d M H:i', strtotime($ticket['FirstRepliedAt'])) ?></span></div>
        <div class="info-row"><span class="k">Response Time</span><span><?= $responseTime ?></span></div>
        <?php else: ?>
        <div class="info-row"><span class="k">1st Reply</span><span style="color:#dc2626;font-weight:700;">Pending</span></div>
        <?php endif; ?>
        <?php if ($ticket['ResolvedAt']): ?>
        <div class="info-row"><span class="k">Resolved</span><span style="color:#059669;"><?= date('d M Y', strtotime($ticket['ResolvedAt'])) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Audit log -->
    <?php if ($auditLog): ?>
    <div class="card">
      <div class="card-header"><h4 style="margin:0;font-size:.88rem;">🕓 Change History</h4></div>
      <div class="card-body" style="padding:.4rem .8rem;max-height:220px;overflow-y:auto;">
        <?php foreach ($auditLog as $a): ?>
        <div style="font-size:.75rem;border-bottom:1px solid var(--clr-border);padding:5px 0;color:var(--tx-muted);">
          <strong style="color:inherit;"><?= htmlspecialchars($a['Field']) ?></strong>:
          <?= htmlspecialchars($a['OldValue'] ?? '—') ?> → <strong><?= htmlspecialchars($a['NewValue'] ?? '—') ?></strong>
          <br><span style="font-size:.7rem;"><?= htmlspecialchars(trim($a['ActorName']) ?: 'Admin') ?> &bull; <?= date('d M H:i', strtotime($a['CreatedAt'])) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function switchTab(tab) {
  document.getElementById('replyAction').value = tab;
  ['reply','note'].forEach(function(t){
    document.getElementById('tab'+t.charAt(0).toUpperCase()+t.slice(1)).classList.toggle('active', t===tab);
  });
  var isNote = tab === 'note';
  document.getElementById('replyBtn').textContent  = isNote ? 'Save Internal Note' : 'Send Reply';
  document.getElementById('replyBody').placeholder = isNote ? 'Internal note — not visible to the student…' : 'Write your reply here…';
  document.getElementById('noteHint').style.display = isNote ? '' : 'none';
  document.getElementById('replyForm').style.background = isNote ? '#fffbeb' : '';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
