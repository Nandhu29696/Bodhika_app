<?php
/**
 * Admin/StudentApprovals.php — Approve/reject pending student self-registrations.
 *
 * migration_v60: new student self-registrations (auth/register.php web form
 * AND Lib/Registration.php's mobile API) now land as Active='N',
 * RegistrationStatus='Pending' instead of instantly active — mirroring how
 * teacher self-registration has always worked (Admin/ManageTeachers.php's
 * "Pending Approval" tab). Auth::verifyCredentials() already refuses login
 * for any Active<>'Y' account, so a pending student simply cannot sign in
 * (on either web or mobile) until approved here.
 *
 * Access:
 *   Full Admin          — sees every pending student, any institute.
 *   Institute-Admin      — sees only pending students who selected their own
 *                          institute at signup (userinfo.InstituteId ==
 *                          Auth::currentInstituteId()). A student who didn't
 *                          pick an institute never shows up in an
 *                          Institute-Admin's queue — only a full Admin can
 *                          review those.
 *
 * Approve → Active='Y', RegistrationStatus='Approved' (student can log in).
 * Reject  → Active stays 'N', RegistrationStatus='Rejected' (stays blocked,
 *           and — unlike a bare Active='N' — never again shows up in this
 *           queue, distinguishing it from a fresh pending signup).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin() && !Auth::isInstituteAdmin()) {
    header('Location: ../exam/search.php');
    exit;
}

$isFullAdmin  = Auth::isAdmin() && !Auth::isInstituteAdmin();
$scopeInstId  = $isFullAdmin ? null : (Auth::currentInstituteId() ?: 0);

$msg = ''; $msgType = 'success';

/**
 * True if the given LoginInfoId is a STDNT the current admin is allowed to
 * act on — a full Admin can act on any student; an Institute-Admin only on
 * students whose userinfo.InstituteId matches their own institute. Re-checked
 * server-side on every POST so a forged request can't reach across
 * institutes even if the UI never renders the button.
 */
function studentInScope(int $loginInfoId, bool $isFullAdmin, ?int $scopeInstId): bool
{
    if ($isFullAdmin) {
        $row = Database::fetchOne(
            "SELECT LoginInfoId FROM logininfo WHERE LoginInfoId = ? AND Role = 'STDNT' LIMIT 1", [$loginInfoId]);
        return (bool)$row;
    }
    if (!$scopeInstId) return false;
    $row = Database::fetchOne(
        "SELECT l.LoginInfoId
           FROM logininfo l
           JOIN userinfo u ON u.LoginName = l.LoginName
          WHERE l.LoginInfoId = ? AND l.Role = 'STDNT' AND u.InstituteId = ? LIMIT 1",
        [$loginInfoId, $scopeInstId]);
    return (bool)$row;
}

/* ── POST handlers ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action      = $_POST['post_action'] ?? '';
    $loginInfoId = (int)($_POST['LoginInfoId'] ?? 0);

    if ($loginInfoId > 0 && studentInScope($loginInfoId, $isFullAdmin, $scopeInstId)) {
        if ($action === 'approve_student') {
            Database::execute(
                "UPDATE logininfo SET Active = 'Y', RegistrationStatus = 'Approved' WHERE LoginInfoId = ? AND Role = 'STDNT'",
                [$loginInfoId]);
            header('Location: StudentApprovals.php?msg=approved'); exit;
        }
        if ($action === 'reject_student') {
            Database::execute(
                "UPDATE logininfo SET Active = 'N', RegistrationStatus = 'Rejected' WHERE LoginInfoId = ? AND Role = 'STDNT'",
                [$loginInfoId]);
            header('Location: StudentApprovals.php?msg=rejected'); exit;
        }
    } else {
        header('Location: StudentApprovals.php?msg=denied'); exit;
    }
}

if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'approved': $msg = 'Student approved — they can now sign in.'; break;
        case 'rejected': $msg = 'Registration rejected.'; $msgType = 'info'; break;
        case 'denied':    $msg = 'That student is outside your institute — nothing was changed.'; $msgType = 'error'; break;
    }
}

/* ── Pending queue ───────────────────────────────────────────────────────── */
$pending = [];
$hasRegStatusCol = Database::hasColumn('logininfo', 'RegistrationStatus');

if (!$isFullAdmin && !$scopeInstId) {
    // Institute-Admin with no institute on their own account — nothing to show.
    $pending = [];
} else {
    $where  = ["l.Role = 'STDNT'"];
    $params = [];
    if ($hasRegStatusCol) {
        $where[] = "l.RegistrationStatus = 'Pending'";
    } else {
        // migration_v60 not yet run — fall back to the old signal (still
        // correct for registrations made after this code was deployed,
        // since register.php always sets Active='N' for new signups; just
        // can't be told apart from an admin-suspended account until the
        // migration runs).
        $where[] = "l.Active = 'N'";
    }
    if (!$isFullAdmin) {
        $where[] = "u.InstituteId = ?";
        $params[] = $scopeInstId;
    }
    $whereSQL = implode(' AND ', $where);

    $pending = Database::fetchAll(
        "SELECT l.LoginInfoId, l.LoginName, l.Email,
                u.FstName, u.LstName, u.Mobile, u.InstituteId,
                COALESCE(i.InstituteName, '—') AS InstituteName
           FROM logininfo l
           JOIN userinfo u ON u.LoginName = l.LoginName
      LEFT JOIN institutes i ON i.InstituteId = u.InstituteId
          WHERE {$whereSQL}
          ORDER BY l.LoginInfoId DESC",
        $params
    );
}

$pageTitle = 'Student Approvals';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.sa-wrap{max-width:1100px;margin:0 auto;padding:0 16px;}
.sa-title{font-size:1.3rem;font-weight:700;color:var(--clr-primary);margin:0 0 4px;}
.sa-sub{font-size:.85rem;color:#6b7280;margin-bottom:18px;}
.sa-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;}
.sa-tbl{width:100%;border-collapse:collapse;font-size:.85rem;}
.sa-tbl th{background:#1e3a5f;color:#fff;padding:8px 12px;text-align:left;white-space:nowrap;}
.sa-tbl td{padding:8px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.sa-tbl tr:hover td{background:#f7faff;}
.sa-btn{border:none;border-radius:5px;padding:6px 14px;font-size:.8rem;font-weight:600;cursor:pointer;color:#fff;}
.sa-btn-approve{background:#059669;}
.sa-btn-reject{background:#dc2626;margin-left:6px;}
</style>

<div class="sa-wrap">
  <div class="sa-title">&#9989; Student Approvals</div>
  <div class="sa-sub">
    <?php if ($isFullAdmin): ?>
      Review new student self-registrations before they can sign in. Applies to registrations made on both the website and the mobile app.
    <?php else: ?>
      New student self-registrations for your institute, awaiting your approval before they can sign in.
    <?php endif; ?>
  </div>

  <?php if ($msg): ?>
  <div style="background:<?php echo $msgType==='error' ? '#fee2e2;color:#991b1b' : ($msgType==='info' ? '#eff6ff;color:#1e40af' : '#d1fae5;color:#065f46'); ?>;
              padding:10px 16px;border-radius:6px;margin-bottom:16px;">
    <?php echo htmlspecialchars($msg); ?>
  </div>
  <?php endif; ?>

  <div class="sa-card">
    <?php if (!$hasRegStatusCol): ?>
      <div style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;border-radius:6px;padding:10px 14px;font-size:.82rem;margin-bottom:14px;">
        &#9888; Run migrations/migration_v60.sql to distinguish new pending signups from students an admin has separately deactivated. Until then this list is based on the Active flag alone.
      </div>
    <?php endif; ?>

    <?php if (!$isFullAdmin && !$scopeInstId): ?>
      <p style="color:#888;">Your account isn't linked to an institute, so there is nothing to review here. Contact a full Admin.</p>
    <?php elseif (empty($pending)): ?>
      <p style="color:#888;">No student registrations pending approval.</p>
    <?php else: ?>
      <table class="sa-tbl">
        <thead><tr>
          <th>Name</th><th>Username</th><th>Email</th><th>Mobile</th>
          <?php if ($isFullAdmin): ?><th>Institute</th><?php endif; ?>
          <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pending as $p): ?>
        <tr>
          <td><strong><?php echo htmlspecialchars(trim(($p['FstName'] ?? '') . ' ' . ($p['LstName'] ?? ''))); ?></strong></td>
          <td><?php echo htmlspecialchars($p['LoginName']); ?></td>
          <td><?php echo htmlspecialchars($p['Email'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($p['Mobile'] ?? '—'); ?></td>
          <?php if ($isFullAdmin): ?><td><?php echo htmlspecialchars($p['InstituteName']); ?></td><?php endif; ?>
          <td style="white-space:nowrap;">
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
              <input type="hidden" name="LoginInfoId" value="<?php echo (int)$p['LoginInfoId']; ?>">
              <input type="hidden" name="post_action" value="approve_student">
              <button type="submit" class="sa-btn sa-btn-approve">Approve</button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('Reject this registration? The account will stay unable to sign in.');">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
              <input type="hidden" name="LoginInfoId" value="<?php echo (int)$p['LoginInfoId']; ?>">
              <input type="hidden" name="post_action" value="reject_student">
              <button type="submit" class="sa-btn sa-btn-reject">Reject</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
