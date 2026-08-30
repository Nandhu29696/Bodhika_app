<?php
/**
 * Admin/UserChangeRequests.php — Review & approve/reject user field change requests.
 *
 * Students can request changes to fields like InstituteId; admin approves here.
 * POST action=approve → applies the new value to userinfo
 * POST action=reject  → marks as rejected with optional note
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$adminId = Auth::currentUserId();
$success = '';
$error   = '';

/* ── Handle POST ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']     ?? '';
    $reqId   = (int)($_POST['request_id'] ?? 0);
    $note    = trim($_POST['admin_note'] ?? '');

    if ($reqId) {
        $req = Database::fetchOne(
            "SELECT * FROM user_change_requests WHERE RequestId=? AND Status='pending' LIMIT 1",
            [$reqId]
        );

        if ($req && $action === 'approve') {
            $col     = $req['FieldName'];
            $val     = $req['NewValue'];
            $allowed = ['InstituteId','EMail','Mobile','FstName','LstName'];
            if (in_array($col, $allowed, true)) {
                Database::execute(
                    "UPDATE userinfo SET `$col`=? WHERE UserInfoId=?",
                    [$val ?: null, (int)$req['UserId']]
                );
            }
            Database::execute(
                "UPDATE user_change_requests
                    SET Status='approved', ReviewedBy=?, ReviewedAt=NOW(), AdminNote=?
                  WHERE RequestId=?",
                [$adminId, $note ?: 'Approved.', $reqId]
            );
            $success = 'Change approved and applied to user profile.';
        }

        if ($req && $action === 'reject') {
            Database::execute(
                "UPDATE user_change_requests
                    SET Status='rejected', ReviewedBy=?, ReviewedAt=NOW(), AdminNote=?
                  WHERE RequestId=?",
                [$adminId, $note ?: 'Rejected by admin.', $reqId]
            );
            $success = 'Change request rejected.';
        }
    }
}

/* ── Filters ─────────────────────────────────────────────────────────── */
$fStatus = in_array($_GET['status'] ?? '', ['pending','approved','rejected'])
           ? $_GET['status'] : 'pending';
$fField  = trim($_GET['field'] ?? '');

$where  = ['ucr.Status = ?'];
$params = [$fStatus];
if ($fField) { $where[] = 'ucr.FieldName = ?'; $params[] = $fField; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

/* ── Counts ──────────────────────────────────────────────────────────── */
$counts = [];
foreach (['pending','approved','rejected'] as $st) {
    $row = Database::fetchOne(
        "SELECT COUNT(*) AS cnt FROM user_change_requests WHERE Status=?", [$st]);
    $counts[$st] = (int)($row['cnt'] ?? 0);
}

/* ── Rows ────────────────────────────────────────────────────────────── */
$rows = Database::fetchAll(
    "SELECT ucr.*,
            u.FstName, u.LstName, u.LoginName,
            a.FstName AS AdminFst, a.LstName AS AdminLst
       FROM user_change_requests ucr
       LEFT JOIN userinfo u ON u.UserInfoId = ucr.UserId
       LEFT JOIN userinfo a ON a.UserInfoId = ucr.ReviewedBy
      $whereSQL
      ORDER BY ucr.RequestedAt DESC
      LIMIT 200",
    $params
);

/* ── Unique field names for filter ───────────────────────────────────── */
$fieldNames = array_unique(array_column(
    Database::fetchAll("SELECT DISTINCT FieldName FROM user_change_requests ORDER BY FieldName", []),
    'FieldName'
));

$pageTitle = 'User Change Requests';
include __DIR__ . '/../includes/header.php';
?>

<style>
.ucr-tabs  { display:flex; gap:6px; margin-bottom:18px; flex-wrap:wrap; }
.ucr-tab   { padding:7px 18px; border-radius:8px; border:1px solid var(--clr-border);
             font-size:.83rem; font-weight:700; text-decoration:none;
             color:var(--clr-text-muted); background:#fff; display:flex; align-items:center; gap:6px; }
.ucr-tab:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
.ucr-tab.active { background:var(--clr-primary); color:#fff; border-color:var(--clr-primary); }
.ucr-cnt   { background:rgba(255,255,255,.25); border-radius:99px;
             padding:1px 7px; font-size:.72rem; }
.ucr-cnt-dark { background:rgba(0,0,0,.08); }
.req-row   { background:#fff; border:1px solid var(--clr-border); border-radius:10px;
             padding:16px 18px; margin-bottom:12px; }
.req-row.pending  { border-left:4px solid #fbbf24; }
.req-row.approved { border-left:4px solid #22c55e; }
.req-row.rejected { border-left:4px solid #ef4444; }
.req-meta  { display:flex; gap:18px; flex-wrap:wrap; align-items:flex-start; }
.req-user  { font-weight:700; }
.req-login { font-size:.78rem; color:var(--clr-text-muted); }
.req-diff  { display:flex; align-items:center; gap:10px; margin:10px 0 12px; }
.req-field-badge { font-size:.68rem; font-weight:800; text-transform:uppercase;
                   letter-spacing:.06em; background:#e0e7ff; color:#3730a3;
                   padding:2px 8px; border-radius:4px; }
.val-old   { color:#64748b; text-decoration:line-through; font-size:.88rem; }
.val-new   { font-weight:700; font-size:.95rem; color:#1e293b; }
.note-box  { font-size:.78rem; color:#64748b; font-style:italic; margin-top:6px; }
</style>

<div class="page-wrap">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
    <h1 style="font-size:1.35rem;font-weight:800;color:var(--clr-primary);margin:0;">
      📋 User Change Requests
    </h1>
    <?php if ($fField): ?>
      <a href="?status=<?= urlencode($fStatus) ?>"
         class="btn btn-sm btn-outline" style="font-size:.8rem;">✕ Clear filter</a>
    <?php endif; ?>
  </div>

  <!-- Status tabs -->
  <div class="ucr-tabs">
    <?php
    $tabColors = ['pending'=>'#d97706','approved'=>'#059669','rejected'=>'#dc2626'];
    foreach (['pending','approved','rejected'] as $st):
      $isActive = $fStatus === $st;
    ?>
    <a href="?status=<?= $st ?><?= $fField ? '&field='.urlencode($fField) : '' ?>"
       class="ucr-tab <?= $isActive ? 'active' : '' ?>">
      <span style="<?= !$isActive ? 'color:'.$tabColors[$st].';' : '' ?>text-transform:capitalize;">
        <?= $st ?>
      </span>
      <span class="ucr-cnt <?= $isActive ? '' : 'ucr-cnt-dark' ?>"
            style="<?= !$isActive ? 'color:'.$tabColors[$st] : '' ?>">
        <?= number_format($counts[$st]) ?>
      </span>
    </a>
    <?php endforeach; ?>

    <?php if (!empty($fieldNames)): ?>
      <form method="get" style="margin-left:auto;">
        <input type="hidden" name="status" value="<?= htmlspecialchars($fStatus) ?>">
        <select name="field" class="form-control form-control-sm"
                onchange="this.form.submit()" style="height:34px;">
          <option value="">— All Fields —</option>
          <?php foreach ($fieldNames as $fn): ?>
            <option value="<?= htmlspecialchars($fn) ?>"
              <?= $fField === $fn ? 'selected' : '' ?>>
              <?= htmlspecialchars($fn) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>

  <!-- Request list -->
  <?php if (empty($rows)): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:48px;color:var(--clr-text-muted);">
        No <?= $fStatus ?> requests<?= $fField ? ' for field "' . htmlspecialchars($fField) . '"' : '' ?>.
      </div>
    </div>
  <?php else: ?>

    <?php if ($success): ?>
      <div class="alert alert-success" style="margin-bottom:14px;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php foreach ($rows as $req):
      $name  = trim(($req['FstName']??'') . ' ' . ($req['LstName']??'')) ?: $req['LoginName'] ?? '—';
      $admin = trim(($req['AdminFst']??'') . ' ' . ($req['AdminLst']??'')) ?: null;
      $isPending = $req['Status'] === 'pending';
    ?>
    <div class="req-row <?= htmlspecialchars($req['Status']) ?>">
      <div class="req-meta">
        <div style="flex:1;min-width:160px;">
          <div class="req-user"><?= htmlspecialchars($name) ?></div>
          <div class="req-login">@<?= htmlspecialchars($req['LoginName'] ?? '—') ?></div>
        </div>
        <div style="font-size:.78rem;color:var(--clr-text-muted);text-align:right;white-space:nowrap;">
          <?= date('d M Y H:i', strtotime($req['RequestedAt'])) ?>
          <?php if (!$isPending && $req['ReviewedAt']): ?>
            <div>Reviewed <?= date('d M Y H:i', strtotime($req['ReviewedAt'])) ?></div>
            <?php if ($admin): ?><div>by <?= htmlspecialchars($admin) ?></div><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="req-diff">
        <span class="req-field-badge"><?= htmlspecialchars($req['FieldName']) ?></span>
        <span class="val-old"><?= htmlspecialchars($req['OldLabel'] ?: $req['OldValue'] ?: '(none)') ?></span>
        <span style="color:#94a3b8;">→</span>
        <span class="val-new"><?= htmlspecialchars($req['NewLabel'] ?: $req['NewValue'] ?: '(none)') ?></span>
      </div>

      <?php if ($req['AdminNote']): ?>
        <div class="note-box">Note: <?= htmlspecialchars($req['AdminNote']) ?></div>
      <?php endif; ?>

      <?php if ($isPending): ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:flex-end;">
        <!-- Approve -->
        <form method="post" style="margin:0;">
          <input type="hidden" name="action"     value="approve">
          <input type="hidden" name="request_id" value="<?= (int)$req['RequestId'] ?>">
          <button type="submit" class="btn btn-sm"
                  style="background:#16a34a;border-color:#16a34a;"
                  onclick="return confirm('Approve and apply this change?')">
            ✓ Approve
          </button>
        </form>

        <!-- Reject with note -->
        <form method="post" style="margin:0;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="action"     value="reject">
          <input type="hidden" name="request_id" value="<?= (int)$req['RequestId'] ?>">
          <input type="text" name="admin_note" class="form-control form-control-sm"
                 placeholder="Reason (optional)" style="width:200px;">
          <button type="submit" class="btn btn-sm btn-outline"
                  style="border-color:#dc2626;color:#dc2626;"
                  onclick="return confirm('Reject this change?')">
            ✗ Reject
          </button>
        </form>

        <!-- View user -->
        <a href="EditUser.php?id=<?= (int)$req['UserId'] ?>"
           class="btn btn-sm btn-outline" style="margin-left:auto;">
          👤 View User
        </a>
      </div>
      <?php else: ?>
        <div style="margin-top:8px;">
          <a href="EditUser.php?id=<?= (int)$req['UserId'] ?>"
             class="btn btn-xs btn-outline">👤 View User</a>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
