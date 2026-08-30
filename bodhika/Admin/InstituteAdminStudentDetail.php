<?php
/**
 * Admin/InstituteAdminStudentDetail.php?id=UserInfoId
 * Institute-Admin: one student's performance history, assigned exams and
 * forthcoming (not-yet-attempted) exams. Read-only.
 *
 * Fail-safe-closed: the target student must belong to the logged-in
 * Institute-Admin's own institute (or, for a full Admin, the institute
 * picked via ?instId=) — any mismatch redirects away rather than showing
 * another institute's student.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isInstituteAdmin() && !Auth::isAdmin()) {
    header('Location: ../exam/search.php');
    exit;
}

$isFullAdmin = Auth::isAdmin() && !Auth::isInstituteAdmin();
$instId = $isFullAdmin
    ? (filter_input(INPUT_GET, 'instId', FILTER_VALIDATE_INT) ?: 0)
    : (Auth::currentInstituteId() ?? 0);
$userId = (int)($_GET['id'] ?? 0);

if ($instId <= 0 || $userId <= 0) {
    header('Location: InstituteAdminHome.php');
    exit;
}

$student = Database::fetchOne(
    "SELECT u.UserInfoId, u.FstName, u.LstName, u.LoginName, u.EMail, u.Mobile, u.InstituteId,
            l.Active, l.LoginInfoId, l.Role,
            i.InstituteName
       FROM userinfo u
       LEFT JOIN logininfo  l ON l.LoginName    = u.LoginName
       LEFT JOIN institutes i ON i.InstituteId  = u.InstituteId
      WHERE u.UserInfoId = ? LIMIT 1",
    [$userId]
);

// Fail-safe-closed: must be a student (Role='STDNT') in exactly the
// institute this Institute-Admin (or full-Admin-via-picker) is viewing —
// any mismatch (wrong institute, no institute, not a student) → not visible.
if (!$student || ($student['Role'] ?? '') !== 'STDNT' || (int)($student['InstituteId'] ?? 0) !== $instId) {
    header('Location: InstituteAdminStudents.php' . ($isFullAdmin ? '?instId='.$instId : ''));
    exit;
}

/* ── Performance history ─────────────────────────────────────────────── */
$history = [];
try {
    $history = Database::fetchAll(
        "SELECT se.StudentExamId, se.Score, se.MarksOutOf, se.Description, se.ExamDate, se.CreateDate,
                e.ExamName
           FROM studentexam se
           LEFT JOIN examinfo e ON e.ExamInfoId = se.ExamInfoId
          WHERE se.UserInfoId = ?
          ORDER BY COALESCE(se.ExamDate, se.CreateDate) DESC
          LIMIT 50",
        [$userId]
    );
} catch (\Throwable $e) {}

/* ── Assigned / forthcoming exams ────────────────────────────────────── */
$assignments = [];
try {
    $assignments = Database::fetchAll(
        "SELECT ea.AssignmentId, ea.Status, ea.DueDate, ea.AssignedAt, e.ExamName, e.ExamInfoId
           FROM exam_assignments ea
           JOIN examinfo e ON e.ExamInfoId = ea.ExamInfoId
          WHERE ea.UserInfoId = ?
          ORDER BY ea.Status ASC, ea.AssignedAt DESC",
        [$userId]
    );
} catch (\Throwable $e) {}

$pageTitle = trim($student['FstName'] . ' ' . $student['LstName']) . ' — Student Detail';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.sd-wrap{max-width:1100px;margin:0 auto;padding:0 16px;}
.sd-back{font-size:12px;color:#64748b;text-decoration:none;display:inline-block;margin-bottom:14px;}
.sd-back:hover{color:var(--clr-primary);}
.sd-head{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.sd-title{font-size:1.3rem;font-weight:700;color:var(--clr-primary);margin:0 0 4px;}
.sd-meta{font-size:12px;color:#64748b;}
.sd-table{width:100%;border-collapse:collapse;margin-top:4px;font-size:13px;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.sd-table th{background:var(--clr-primary);color:#fff;padding:9px 12px;font-size:12px;text-align:left;white-space:nowrap;}
.sd-table td{padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#1e293b;}
.sd-table tr:nth-child(even) td{background:#f8fafc;}
.badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;}
.badge-y{background:#dcfce7;color:#15803d;} .badge-n{background:#fee2e2;color:#b91c1c;} .badge-fc{background:#fef3c7;color:#92400e;}
.sd-section{margin-bottom:26px;}
.sd-section h3{font-size:1rem;margin:0 0 10px;color:#1e293b;}
</style>

<div class="sd-wrap">
  <a href="InstituteAdminStudents.php<?= $isFullAdmin ? '?instId='.$instId : '' ?>" class="sd-back">&larr; Back to Students</a>

  <div class="sd-head">
    <div>
      <div class="sd-title">&#128100; <?= htmlspecialchars(trim($student['FstName'] . ' ' . $student['LstName'])) ?></div>
      <div class="sd-meta">
        @<?= htmlspecialchars($student['LoginName']) ?> &middot;
        <?= htmlspecialchars(Pii::email($student['EMail'] ?? '') ?: '—') ?> &middot;
        <?= htmlspecialchars(Pii::mobile($student['Mobile'] ?? '') ?: '—') ?> &middot;
        <?= htmlspecialchars($student['InstituteName'] ?: '—') ?> &middot;
        <span class="badge <?= ($student['Active'] ?? 'N') === 'Y' ? 'badge-y' : 'badge-n' ?>">
          <?= ($student['Active'] ?? 'N') === 'Y' ? 'Active' : 'Inactive' ?>
        </span>
      </div>
    </div>
    <a href="ResetStudentPassword.php?id=<?= (int)$student['UserInfoId'] ?><?= $isFullAdmin ? '&instId='.$instId : '' ?>"
       class="btn btn-outline" style="border-color:#dc2626;color:#dc2626;">&#128274; Reset Password</a>
  </div>

  <div class="sd-section">
    <h3>&#128197; Assigned &amp; Forthcoming Exams</h3>
    <?php if ($assignments): ?>
    <table class="sd-table">
      <thead><tr><th>Exam</th><th>Status</th><th>Due Date</th><th>Assigned</th></tr></thead>
      <tbody>
        <?php foreach ($assignments as $a): $forthcoming = $a['Status'] === 'Assigned'; ?>
        <tr>
          <td><?= htmlspecialchars($a['ExamName']) ?></td>
          <td><span class="badge <?= $forthcoming ? 'badge-fc' : 'badge-y' ?>"><?= $forthcoming ? 'Forthcoming' : htmlspecialchars($a['Status']) ?></span></td>
          <td><?= $a['DueDate'] ? date('d M Y', strtotime($a['DueDate'])) : '—' ?></td>
          <td><?= $a['AssignedAt'] ? date('d M Y', strtotime($a['AssignedAt'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p style="color:#888;font-size:13px;">No exams assigned to this student yet.</p>
    <?php endif; ?>
  </div>

  <div class="sd-section">
    <h3>&#128202; Performance History</h3>
    <?php if ($history): ?>
    <table class="sd-table">
      <thead><tr><th>Exam</th><th>Score</th><th>Percent</th><th>Result</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($history as $h):
          $outOf = (float)($h['MarksOutOf'] ?? 0);
          $score = (float)($h['Score'] ?? 0);
          $pct   = $outOf > 0 ? round($score / $outOf * 100, 1) : null;
          $desc  = $h['Description'] ?? '';
          $when  = $h['ExamDate'] ?? $h['CreateDate'] ?? null;
        ?>
        <tr>
          <td><?= htmlspecialchars($h['ExamName'] ?? '—') ?></td>
          <td><?= $outOf > 0 ? htmlspecialchars($score . ' / ' . $outOf) : '—' ?></td>
          <td><?= $pct !== null ? $pct . '%' : '—' ?></td>
          <td>
            <?php if (stripos($desc, 'pass') !== false): ?>
              <span class="badge badge-y">Pass</span>
            <?php elseif (stripos($desc, 'fail') !== false): ?>
              <span class="badge badge-n">Fail</span>
            <?php else: ?>
              <?= htmlspecialchars($desc ?: '—') ?>
            <?php endif; ?>
          </td>
          <td><?= $when ? date('d M Y', strtotime($when)) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p style="color:#888;font-size:13px;">This student hasn't attempted any exams yet.</p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
