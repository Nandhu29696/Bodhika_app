<?php
/**
 * Admin/EnrollmentPayments.php
 *
 * migration_v51: subject-level pricing/checkout has been retired in favour of
 * exam-level pricing. The "Enrollment Records" section below (backed by
 * enrollment_payments) is now LEGACY/HISTORICAL — it shows old subject-wide
 * payments made before the pivot (including ones grandfathered in by
 * migration_v51) but new payments are no longer written there. The
 * "Exam-Level Fee Payments" section (backed by exam_fee_payments) is the
 * live, authoritative ledger going forward.
 *
 * Allows admin to:
 *   - Manually mark a payment as Paid / Waived (legacy subject records, or
 *     the current exam-level records)
 *   - Set StartDate / EndDate (enrollment validity window)
 *   - Add an override note
 *   - Grant / revoke ScholarshipFlag per student
 *   - Filter by subject, payment status, or student name (legacy section only)
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$msg     = '';
$msgType = 'success';
$action  = $_POST['action'] ?? '';
$adminId = (int)Auth::currentUserId();

/* ── Handle override (status + dates + note) ─────────────────────────────── */
if ($action === 'override') {
    $paymentId    = (int)($_POST['PaymentId']  ?? 0);
    $newStatus    = in_array($_POST['NewStatus'] ?? '', ['Paid','Waived','Free','Pending','Failed','Refunded'], true)
                        ? $_POST['NewStatus'] : '';
    $overrideNote = trim($_POST['OverrideNote'] ?? '');
    $startDate    = trim($_POST['StartDate']    ?? '') ?: null;
    $endDate      = trim($_POST['EndDate']      ?? '') ?: null;

    if ($paymentId > 0 && $newStatus !== '') {
        Database::execute(
            "UPDATE enrollment_payments
                SET PaymentStatus = ?,
                    OverrideBy    = ?,
                    OverrideNote  = ?,
                    StartDate     = ?,
                    EndDate       = ?,
                    PaidAt        = IF(? IN ('Paid','Waived','Free') AND PaidAt IS NULL, NOW(), PaidAt),
                    UpdatedAt     = CURRENT_TIMESTAMP
              WHERE PaymentId = ?",
            [$newStatus, $adminId, $overrideNote, $startDate, $endDate, $newStatus, $paymentId]);
        $msg = 'Enrollment record updated.';
    }
}

/* ── Handle date-only update ─────────────────────────────────────────────── */
if ($action === 'update_dates') {
    $paymentId = (int)($_POST['PaymentId'] ?? 0);
    $startDate = trim($_POST['StartDate']  ?? '') ?: null;
    $endDate   = trim($_POST['EndDate']    ?? '') ?: null;
    if ($paymentId > 0) {
        Database::execute(
            "UPDATE enrollment_payments
                SET StartDate = ?, EndDate = ?, OverrideBy = ?, UpdatedAt = CURRENT_TIMESTAMP
              WHERE PaymentId = ?",
            [$startDate, $endDate, $adminId, $paymentId]);
        $msg = 'Enrollment dates updated.';
    }
}

/* ── Handle exam-fee-override payment override (migration_v50) ───────────── */
if ($action === 'exam_override') {
    $examPaymentId = (int)($_POST['ExamPaymentId'] ?? 0);
    $newStatus     = in_array($_POST['NewStatus'] ?? '', ['Paid','Waived','Free','Pending','Failed','Refunded'], true)
                        ? $_POST['NewStatus'] : '';
    $overrideNote  = trim($_POST['OverrideNote'] ?? '');

    if ($examPaymentId > 0 && $newStatus !== '') {
        try {
            Database::execute(
                "UPDATE exam_fee_payments
                    SET PaymentStatus = ?,
                        OverrideBy    = ?,
                        OverrideNote  = ?,
                        PaidAt        = IF(? IN ('Paid','Waived','Free') AND PaidAt IS NULL, NOW(), PaidAt),
                        UpdatedAt     = CURRENT_TIMESTAMP
                  WHERE PaymentId = ?",
                [$newStatus, $adminId, $overrideNote, $newStatus, $examPaymentId]);
            $msg = 'Exam fee payment record updated.';
        } catch (Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }
}

/* ── Add / override an exam-fee-override payment record (manual) ─────────── */
if ($action === 'add_exam_record') {
    $userId = (int)($_POST['ExamUserId'] ?? 0);
    $examId = (int)($_POST['ExamId']     ?? 0);
    $status = in_array($_POST['ExamStatus'] ?? '', ['Paid','Waived','Free'], true) ? $_POST['ExamStatus'] : 'Waived';
    $note   = trim($_POST['ExamOverrideNote'] ?? '');

    if ($userId > 0 && $examId > 0) {
        try {
            Database::execute(
                "INSERT INTO exam_fee_payments
                    (ExamInfoId, UserInfoId, FeeAtTime, PaymentStatus, OverrideBy, OverrideNote, PaidAt)
                 SELECT ?, ?, COALESCE(ExamFee,0), ?, ?, ?, NOW()
                   FROM examinfo WHERE ExamInfoId = ? LIMIT 1
                 ON DUPLICATE KEY UPDATE
                    PaymentStatus = VALUES(PaymentStatus),
                    OverrideBy    = VALUES(OverrideBy),
                    OverrideNote  = VALUES(OverrideNote),
                    PaidAt        = IF(PaymentStatus NOT IN ('Paid','Waived','Free'), NOW(), PaidAt),
                    UpdatedAt     = CURRENT_TIMESTAMP",
                [$examId, $userId, $status, $adminId, $note, $examId]);
            $msg = 'Exam fee payment record saved.';
        } catch (Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }
}

/* ── Handle scholarship flag ─────────────────────────────────────────────── */
if ($action === 'scholarship') {
    $userId = (int)($_POST['UserId'] ?? 0);
    $flag   = ($_POST['Flag'] ?? 'N') === 'Y' ? 'Y' : 'N';
    Database::execute(
        "UPDATE userinfo SET ScholarshipFlag=? WHERE UserInfoId=?", [$flag, $userId]);
    $msg = 'Scholarship flag ' . ($flag === 'Y' ? 'granted' : 'revoked') . '.';
}

/* ── Add / override enrollment record (manual) ───────────────────────────── */
if ($action === 'add_record') {
    $userId    = (int)($_POST['UserId']    ?? 0);
    $subjectId = (int)($_POST['SubjectId'] ?? 0);
    $status    = in_array($_POST['Status'] ?? '', ['Paid','Waived','Free'], true) ? $_POST['Status'] : 'Waived';
    $note      = trim($_POST['OverrideNote'] ?? '');
    $startDate = trim($_POST['StartDate']    ?? '') ?: date('Y-m-d');
    $endDate   = trim($_POST['EndDate']      ?? '') ?: null;

    if ($userId > 0 && $subjectId > 0) {
        try {
            Database::execute(
                "INSERT INTO enrollment_payments
                    (UserInfoId, SubjectInfoId, ExamFeeAtTime, CouponCode, DiscountApplied,
                     FinalAmount, PaymentStatus, OverrideBy, OverrideNote, PaidAt, StartDate, EndDate)
                 SELECT ?, ?, COALESCE(ExamFee,0), '', 0, 0, ?, ?, ?, NOW(), ?, ?
                   FROM subjectinfo WHERE SubjectInfoId = ? LIMIT 1
                 ON DUPLICATE KEY UPDATE
                    PaymentStatus = VALUES(PaymentStatus),
                    OverrideBy    = VALUES(OverrideBy),
                    OverrideNote  = VALUES(OverrideNote),
                    StartDate     = VALUES(StartDate),
                    EndDate       = VALUES(EndDate),
                    PaidAt        = IF(PaymentStatus NOT IN ('Paid','Waived','Free'), NOW(), PaidAt),
                    UpdatedAt     = CURRENT_TIMESTAMP",
                [$userId, $subjectId, $status, $adminId, $note, $startDate, $endDate, $subjectId]);
            $msg = 'Enrollment record saved.';
        } catch (Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'danger';
        }
    }
}

/* ── Pagination helpers (mirrors AdminUsers.php / StudentGroupMembers.php) ── */
const PAGE_SIZE = 25;
function currentPage(string $key): int {
    return max(1, (int)($_GET[$key] ?? 1));
}
function paginator(int $total, int $current, int $pageSize, array $qs, string $pageKey): string {
    if ($total <= $pageSize) return '';
    $pages = (int)ceil($total / $pageSize);
    $html  = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $q    = array_merge($qs, [$pageKey => $i]);
        $url  = '?' . http_build_query($q);
        $cls  = $i === $current ? 'pager-active' : 'pager-link';
        $html .= "<a href=\"{$url}\" class=\"{$cls}\">{$i}</a> ";
    }
    return $html . '</div>';
}

/* ── Filters ─────────────────────────────────────────────────────────────── */
$filterSubject = (int)($_GET['subject'] ?? 0);
$filterStatus  = trim($_GET['status']   ?? '');
$filterName    = trim($_GET['name']     ?? '');
$filterExpiry  = trim($_GET['expiry']   ?? '');  // 'expired' | 'active' | ''
$filterMethod  = trim($_GET['method']   ?? '');  // 'Razorpay' | 'UPI' | ''

$where  = [];
$params = [];
if ($filterSubject > 0)  { $where[] = 'ep.SubjectInfoId = ?'; $params[] = $filterSubject; }
if ($filterStatus  !== '') { $where[] = 'ep.PaymentStatus = ?'; $params[] = $filterStatus; }
if ($filterMethod  !== '') { $where[] = 'ep.PaymentMethod = ?'; $params[] = $filterMethod; }
if ($filterName    !== '') {
    $where[]  = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.EMail LIKE ? OR li.LoginName LIKE ?)';
    $like     = "%$filterName%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterExpiry === 'expired') {
    $where[]  = 'ep.EndDate IS NOT NULL AND ep.EndDate < CURDATE()';
} elseif ($filterExpiry === 'active') {
    $where[]  = '(ep.EndDate IS NULL OR ep.EndDate >= CURDATE())';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$paymentsPage = currentPage('p');

/* Count of manual UPI submissions still awaiting verification (unfiltered,
   for the quick-action banner below — migration_v34+ only). */
$pendingUpiCount = 0;
try {
    $row = Database::fetchOne(
        "SELECT COUNT(*) AS c FROM enrollment_payments
          WHERE PaymentMethod = 'UPI' AND PaymentStatus = 'Pending' AND TransactionId IS NOT NULL");
    $pendingUpiCount = (int)($row['c'] ?? 0);
} catch (Exception $e) { /* migration_v34 not yet run */ }

$paymentsBaseSQL = "FROM enrollment_payments ep
           JOIN userinfo    u   ON u.UserInfoId    = ep.UserInfoId
           JOIN logininfo   li  ON li.LoginName    = u.LoginName
           JOIN subjectinfo s   ON s.SubjectInfoId = ep.SubjectInfoId
      LEFT JOIN userinfo    adm ON adm.UserInfoId  = ep.OverrideBy
          $whereSQL";

$paymentsTotal = 0;
try {
    $paymentsTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$paymentsBaseSQL}", $params)['cnt'] ?? 0);
} catch (Exception $e) { /* fall through to the fetchAll's own error handling below */ }

$paymentsOffset = ($paymentsPage - 1) * PAGE_SIZE;
try {
    $payments = Database::fetchAll(
        "SELECT ep.*,
                u.FstName, u.LstName, u.EMail, u.ScholarshipFlag,
                li.LoginName,
                s.SubjectName,
                adm.FstName AS AdminFstName, adm.LstName AS AdminLstName
         {$paymentsBaseSQL}
          ORDER BY ep.UpdatedAt DESC, ep.PaymentId DESC
          LIMIT {$paymentsOffset}, " . PAGE_SIZE,
        $params);
} catch (Exception $e) {
    $payments = [];
    $msg = 'Error loading records: ' . $e->getMessage(); $msgType = 'danger';
}
$qsPayments = array_filter([
    'subject' => $filterSubject ?: null,
    'status'  => $filterStatus  ?: null,
    'method'  => $filterMethod  ?: null,
    'name'    => $filterName    ?: null,
    'expiry'  => $filterExpiry  ?: null,
]);

$subjects = Database::fetchAll(
    "SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

/* ── Exam-fee-override payments (migration_v50) — separate ledger ─────────────
   Previously fetchAll'd every row, no filter, no LIMIT — the live ledger is
   the one that keeps growing going forward, so it needed pagination the
   most. Added a search box (student/exam) since it had no filtering at all. */
$examFeeSearch = trim($_GET['eq'] ?? '');
$examFeePage   = currentPage('ep');
$examFeeTotal  = 0;
$examFeePayments = [];
try {
    $efWhere  = [];
    $efParams = [];
    if ($examFeeSearch !== '') {
        $efWhere[] = '(u.FstName LIKE ? OR u.LstName LIKE ? OR li.LoginName LIKE ? OR e.ExamName LIKE ?)';
        $like      = "%{$examFeeSearch}%";
        array_push($efParams, $like, $like, $like, $like);
    }
    $efWhereSQL = $efWhere ? ('WHERE ' . implode(' AND ', $efWhere)) : '';
    $efBaseSQL  = "FROM exam_fee_payments efp
           JOIN examinfo  e   ON e.ExamInfoId    = efp.ExamInfoId
           JOIN userinfo  u   ON u.UserInfoId    = efp.UserInfoId
           JOIN logininfo li  ON li.LoginName    = u.LoginName
      LEFT JOIN userinfo  adm ON adm.UserInfoId  = efp.OverrideBy
          {$efWhereSQL}";

    $examFeeTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$efBaseSQL}", $efParams)['cnt'] ?? 0);

    $efOffset = ($examFeePage - 1) * PAGE_SIZE;
    $examFeePayments = Database::fetchAll(
        "SELECT efp.*, e.ExamName, u.FstName, u.LstName, u.EMail, li.LoginName,
                adm.FstName AS AdminFstName, adm.LstName AS AdminLstName
         {$efBaseSQL}
          ORDER BY efp.UpdatedAt DESC, efp.PaymentId DESC
          LIMIT {$efOffset}, " . PAGE_SIZE,
        $efParams);
} catch (Exception $e) {
    $examFeePayments = []; // migration_v50 not yet run
}
$qsExamFee = array_filter(['eq' => $examFeeSearch]);

try {
    $examsWithOverride = Database::fetchAll(
        "SELECT ExamInfoId, ExamName, COALESCE(ExamFee,0) AS ExamFee FROM examinfo
          WHERE IsActive = 'Y' AND IsDeleted = 'N'
          ORDER BY ExamName");
} catch (Exception $e) {
    $examsWithOverride = []; // migration_v51 not yet run
}

/* The three "Admin Actions" dropdowns below (Add/Override Enrollment Record,
   Manage Scholarship Flag, Add/Override Exam-Level Fee Payment) used to each
   render one <option> per registered student — 1600+ options, three times,
   on every page load, with no way to search. They now reuse the "Student
   Name / Email" filter box further down the page (search once, it narrows
   the payment list AND these three pickers); with nothing typed yet, the
   list is capped at 100 so the page still loads fast. */
try {
    $studentDdWhere  = ["li.Role = 'STDNT'"];
    $studentDdParams = [];
    if ($filterName !== '') {
        $studentDdWhere[] = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.EMail LIKE ? OR li.LoginName LIKE ?)';
        $like             = "%{$filterName}%";
        array_push($studentDdParams, $like, $like, $like, $like);
    }
    $students = Database::fetchAll(
        "SELECT u.UserInfoId, u.FstName, u.LstName, u.EMail, u.ScholarshipFlag, li.LoginName
           FROM userinfo u
           JOIN logininfo li ON li.LoginName = u.LoginName
          WHERE " . implode(' AND ', $studentDdWhere) . "
          ORDER BY u.FstName, u.LstName
          LIMIT 100",
        $studentDdParams);
} catch (Exception $e) {
    $students = [];
}

$statusColors = [
    'Paid'     => '#dcfce7:#166534',
    'Waived'   => '#dbeafe:#1e40af',
    'Free'     => '#e0f2fe:#0369a1',
    'Pending'  => '#fef9c3:#92400e',
    'Failed'   => '#fee2e2:#991b1b',
    'Refunded' => '#f3f4f6:#374151',
];

$today = date('Y-m-d');

$pageTitle = 'Enrollment Payments';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .status-badge { display:inline-block;padding:2px 10px;border-radius:10px;font-size:.74rem;font-weight:700; }
  .expired-badge{ display:inline-block;padding:2px 8px;border-radius:8px;font-size:.72rem;font-weight:700;
                  background:#fee2e2;color:#991b1b;margin-top:3px; }
  .active-badge { display:inline-block;padding:2px 8px;border-radius:8px;font-size:.72rem;font-weight:700;
                  background:#dcfce7;color:#166534;margin-top:3px; }
  .filter-bar   { display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px; }
  .filter-bar .form-group { margin-bottom:0;min-width:150px; }
  .section-card { margin-bottom:20px; }
  details summary { cursor:pointer;font-weight:600;color:#1e3a5f;user-select:none; }
  details[open] summary { margin-bottom:12px; }
  .date-col { font-size:.78rem;white-space:nowrap; }
  .pager        { margin:10px 0; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
  .pager-link   { display:inline-block; padding:3px 10px; border:1px solid #cbd5e1; border-radius:4px;
                   text-decoration:none; color:#475569; }
  .pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
  .pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                   background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }
</style>

<?php if ($msg): ?>
  <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:16px;">
    <?php echo htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<?php if ($pendingUpiCount > 0): ?>
  <a href="?status=Pending&method=UPI" class="alert" style="display:block;margin-bottom:16px;
     background:#fef9c3;border:1px solid #facc15;color:#854d0e;text-decoration:none;font-weight:600;">
    &#9203; <?php echo $pendingUpiCount; ?> UPI payment<?php echo $pendingUpiCount === 1 ? '' : 's'; ?>
    awaiting verification &rarr; click to review
  </a>
<?php endif; ?>

<!-- ── Admin Actions ───────────────────────────────────────────────────────── -->
<div class="card section-card">
  <div class="card-header">&#9881; Admin Actions</div>
  <div class="card-body">

    <details>
      <summary>&#10010; Add / Override Enrollment Record <span style="font-weight:400;font-size:.78rem;color:#b45309;">(legacy — subject-level, historical use only)</span></summary>
      <form method="post" action="EnrollmentPayments.php" style="max-width:680px;margin-top:10px;">
        <input type="hidden" name="action" value="add_record">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">
          <div class="form-group">
            <label>Student
              <small style="font-weight:400;color:#6b7280;"><?php echo $filterName !== '' ? '(filtered by the search box below)' : '(first 100 — use the search box below to find someone else)'; ?></small>
            </label>
            <select name="UserId" class="form-control" required>
              <option value="">— Select student —</option>
              <?php foreach ($students as $st): ?>
                <option value="<?php echo (int)$st['UserInfoId']; ?>">
                  <?php echo htmlspecialchars(trim($st['FstName'].' '.$st['LstName'])); ?>
                  (<?php echo htmlspecialchars($st['LoginName']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Subject</label>
            <select name="SubjectId" class="form-control" required>
              <option value="">— Select subject —</option>
              <?php foreach ($subjects as $s): ?>
                <option value="<?php echo (int)$s['SubjectInfoId']; ?>">
                  <?php echo htmlspecialchars($s['SubjectName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="Status" class="form-control">
              <option value="Waived">Waived (offline payment)</option>
              <option value="Free">Free (scholarship / promo)</option>
              <option value="Paid">Paid</option>
            </select>
          </div>
          <div class="form-group">
            <label>Override Note</label>
            <input type="text" name="OverrideNote" class="form-control"
                   placeholder="e.g. Paid cash at counter" maxlength="255">
          </div>
          <div class="form-group">
            <label>Enrollment Start Date</label>
            <input type="date" name="StartDate" class="form-control"
                   value="<?php echo $today; ?>">
          </div>
          <div class="form-group">
            <label>Enrollment End Date <span style="color:#6b7280;font-size:.78rem;">(blank = no expiry)</span></label>
            <input type="date" name="EndDate" class="form-control">
          </div>
        </div>
        <button type="submit" class="btn btn-success btn-sm" style="margin-top:10px;">
          &#128190; Save Record
        </button>
      </form>
    </details>

    <details style="margin-top:16px;">
      <summary>&#127891; Manage Scholarship Flag</summary>
      <form method="post" action="EnrollmentPayments.php" style="max-width:500px;margin-top:10px;">
        <input type="hidden" name="action" value="scholarship">
        <div style="display:flex;gap:10px;align-items:flex-end;">
          <div class="form-group" style="margin-bottom:0;flex:1;">
            <label>Student
              <small style="font-weight:400;color:#6b7280;"><?php echo $filterName !== '' ? '(filtered by the search box below)' : '(first 100 — use the search box below to find someone else)'; ?></small>
            </label>
            <select name="UserId" class="form-control" required>
              <option value="">— Select student —</option>
              <?php foreach ($students as $st): ?>
                <option value="<?php echo (int)$st['UserInfoId']; ?>">
                  <?php echo htmlspecialchars(trim($st['FstName'].' '.$st['LstName'])); ?>
                  <?php echo $st['ScholarshipFlag'] === 'Y' ? ' ★' : ''; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Flag</label>
            <select name="Flag" class="form-control">
              <option value="Y">Grant (Y)</option>
              <option value="N">Revoke (N)</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">&#128190; Update</button>
        </div>
      </form>
    </details>

    <?php if (!empty($examsWithOverride)): ?>
    <details style="margin-top:16px;">
      <summary>&#128273; Add / Override Exam-Level Fee Payment</summary>
      <p style="font-size:.78rem;color:#6b7280;margin:8px 0;max-width:640px;">
        Pricing lives on the exam (set on the exam's Edit page → Pricing section). Use this to mark
        a manual/offline payment for one specific exam.
      </p>
      <form method="post" action="EnrollmentPayments.php" style="max-width:680px;margin-top:10px;">
        <input type="hidden" name="action" value="add_exam_record">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">
          <div class="form-group">
            <label>Student
              <small style="font-weight:400;color:#6b7280;"><?php echo $filterName !== '' ? '(filtered by the search box below)' : '(first 100 — use the search box below to find someone else)'; ?></small>
            </label>
            <select name="ExamUserId" class="form-control" required>
              <option value="">— Select student —</option>
              <?php foreach ($students as $st): ?>
                <option value="<?php echo (int)$st['UserInfoId']; ?>">
                  <?php echo htmlspecialchars(trim($st['FstName'].' '.$st['LstName'])); ?>
                  (<?php echo htmlspecialchars($st['LoginName']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Exam</label>
            <select name="ExamId" class="form-control" required>
              <option value="">— Select exam —</option>
              <?php foreach ($examsWithOverride as $ewo): ?>
                <option value="<?php echo (int)$ewo['ExamInfoId']; ?>">
                  <?php echo htmlspecialchars($ewo['ExamName']); ?>
                  (&#8377;<?php echo number_format((float)$ewo['ExamFee'], 2); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="ExamStatus" class="form-control">
              <option value="Waived">Waived (offline payment)</option>
              <option value="Free">Free (promo)</option>
              <option value="Paid">Paid</option>
            </select>
          </div>
          <div class="form-group">
            <label>Override Note</label>
            <input type="text" name="ExamOverrideNote" class="form-control"
                   placeholder="e.g. Paid cash at counter" maxlength="255">
          </div>
        </div>
        <button type="submit" class="btn btn-success btn-sm" style="margin-top:10px;">
          &#128190; Save Record
        </button>
      </form>
    </details>
    <?php endif; ?>

  </div>
</div>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<form method="get" action="EnrollmentPayments.php" class="filter-bar">
  <div class="form-group">
    <label>Subject</label>
    <select name="subject" class="form-control">
      <option value="">All Subjects</option>
      <?php foreach ($subjects as $s): ?>
        <option value="<?php echo (int)$s['SubjectInfoId']; ?>"
          <?php echo $filterSubject === (int)$s['SubjectInfoId'] ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($s['SubjectName']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Status</label>
    <select name="status" class="form-control">
      <option value="">All Statuses</option>
      <?php foreach (['Paid','Waived','Free','Pending','Failed','Refunded'] as $st): ?>
        <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>>
          <?php echo $st; ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Payment Method</label>
    <select name="method" class="form-control">
      <option value="">All Methods</option>
      <option value="Razorpay" <?php echo $filterMethod === 'Razorpay' ? 'selected' : ''; ?>>Razorpay</option>
      <option value="UPI"      <?php echo $filterMethod === 'UPI'      ? 'selected' : ''; ?>>UPI / QR (manual)</option>
    </select>
  </div>
  <div class="form-group">
    <label>Enrollment Access</label>
    <select name="expiry" class="form-control">
      <option value="">All</option>
      <option value="active"  <?php echo $filterExpiry === 'active'  ? 'selected' : ''; ?>>Active (not expired)</option>
      <option value="expired" <?php echo $filterExpiry === 'expired' ? 'selected' : ''; ?>>Expired</option>
    </select>
  </div>
  <div class="form-group">
    <label>Student Name / Email</label>
    <input type="text" name="name" class="form-control" placeholder="Search…"
           value="<?php echo htmlspecialchars($filterName); ?>">
  </div>
  <div>
    <button type="submit" class="btn btn-primary">&#128269; Filter</button>
    <a href="EnrollmentPayments.php" class="btn btn-secondary" style="margin-left:6px;">Clear</a>
  </div>
</form>

<?php
$enrollExportParams = array_filter([
    'type'    => 'enrollments',
    'subject' => $filterSubject ?: null,
    'status'  => $filterStatus  ?: null,
    'name'    => $filterName    ?: null,
    'expiry'  => $filterExpiry  ?: null,
    'method'  => $filterMethod  ?: null,
]);
$enrollExportUrl = '../exam/export-excel.php?' . http_build_query($enrollExportParams);
?>

<!-- ── Payment list ───────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128200; Enrollment Records — Legacy / Historical (<?php echo $paymentsTotal; ?>)
      <span style="font-weight:400;font-size:.76rem;color:#6b7280;">subject-level, pre migration_v51</span>
    </span>
    <?php if (!empty($payments)): ?>
      <a href="<?php echo htmlspecialchars($enrollExportUrl); ?>"
         class="btn btn-sm" style="background:#217346;color:#fff;font-weight:700;">
        &#128190; Export XL
      </a>
    <?php endif; ?>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <?php if (empty($payments)): ?>
      <p style="padding:20px;color:#718096;text-align:center;">No records found.</p>
    <?php else: ?>
    <table class="tbl" style="font-size:.81rem;">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Subject</th>
          <th>Fee / Paid</th>
          <th>Coupon</th>
          <th>Method / Txn ID</th>
          <th>Status</th>
          <th>Start Date</th>
          <th>End Date</th>
          <th>Access</th>
          <th>Scholarship</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $i => $p):
          [$bg, $fg] = explode(':', $statusColors[$p['PaymentStatus']] ?? '#f3f4f6:#374151');
          $endDate   = $p['EndDate'] ?? null;
          $isExpired = ($endDate && $endDate < $today);
          $isPaid    = in_array($p['PaymentStatus'], ['Paid','Waived','Free'], true);
          $method    = $p['PaymentMethod'] ?? 'Razorpay';
          $awaitingVerification = ($method === 'UPI' && $p['PaymentStatus'] === 'Pending' && !empty($p['TransactionId']));
        ?>
        <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>"
            <?php echo $awaitingVerification ? 'style="background:#fefce8;"' : ''; ?>>
          <td><?php echo (int)$p['PaymentId']; ?></td>
          <td>
            <strong><?php echo htmlspecialchars(trim($p['FstName'].' '.$p['LstName'])); ?></strong><br>
            <span style="color:#6b7280;font-size:.76rem;">
              <?php echo htmlspecialchars($p['LoginName']); ?> &bull;
              <?php echo htmlspecialchars($p['EMail'] ?? ''); ?>
            </span>
          </td>
          <td><?php echo htmlspecialchars($p['SubjectName']); ?></td>
          <td>
            <span style="color:#6b7280;">&#8377;<?php echo number_format((float)$p['ExamFeeAtTime'], 2); ?></span>
            <?php if ((float)$p['DiscountApplied'] > 0): ?>
              <br><span style="color:#059669;font-size:.76rem;">
                -&#8377;<?php echo number_format((float)$p['DiscountApplied'], 2); ?>
              </span>
            <?php endif; ?>
            <br><strong>&#8377;<?php echo number_format((float)$p['FinalAmount'], 2); ?></strong>
          </td>
          <td style="font-size:.76rem;color:#7c3aed;">
            <?php echo $p['CouponCode'] ? htmlspecialchars($p['CouponCode']) : '—'; ?>
          </td>
          <td style="font-size:.78rem;white-space:nowrap;">
            <span style="font-weight:700;color:<?php echo $method === 'UPI' ? '#7c3aed' : '#1d4ed8'; ?>;">
              <?php echo $method === 'UPI' ? '📱 UPI' : '💳 Razorpay'; ?>
            </span>
            <?php if (!empty($p['TransactionId'])): ?>
              <br><span style="font-family:monospace;color:#374151;" title="Transaction / UTR ID">
                <?php echo htmlspecialchars($p['TransactionId']); ?>
              </span>
              <?php if ($awaitingVerification): ?>
                <br><span style="font-size:.71rem;color:#854d0e;font-weight:700;">&#9203; Verify me</span>
              <?php endif; ?>
            <?php elseif (!empty($p['RazorpayPaymentId'])): ?>
              <br><span style="font-family:monospace;color:#9ca3af;font-size:.72rem;">
                <?php echo htmlspecialchars($p['RazorpayPaymentId']); ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-badge" style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;">
              <?php echo htmlspecialchars($p['PaymentStatus']); ?>
            </span>
            <?php if ($p['OverrideNote']): ?>
              <br><span style="font-size:.71rem;color:#6b7280;"
                        title="<?php echo htmlspecialchars($p['OverrideNote']); ?>">
                &#128196; <?php echo htmlspecialchars(mb_substr($p['OverrideNote'], 0, 25)); ?>…
              </span>
            <?php endif; ?>
          </td>
          <td class="date-col">
            <?php echo $p['StartDate'] ? date('d M Y', strtotime($p['StartDate'])) : '<span style="color:#9ca3af;">—</span>'; ?>
          </td>
          <td class="date-col">
            <?php if ($endDate): ?>
              <?php echo date('d M Y', strtotime($endDate)); ?>
              <?php if ($isExpired): ?>
                <br><span class="expired-badge">&#9888; Expired</span>
              <?php else: ?>
                <br><span style="font-size:.71rem;color:#059669;">
                  <?php
                    $diff = (new DateTime($endDate))->diff(new DateTime($today));
                    echo $diff->days . ' day' . ($diff->days !== 1 ? 's' : '') . ' left';
                  ?>
                </span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#9ca3af;">No expiry</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if (!$isPaid): ?>
              <span style="color:#94a3b8;font-size:.78rem;">—</span>
            <?php elseif ($isExpired): ?>
              <span class="expired-badge">&#128274; Expired</span>
            <?php else: ?>
              <span class="active-badge">&#10004; Active</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($p['ScholarshipFlag'] === 'Y'): ?>
              <span style="color:#d97706;font-weight:700;" title="Scholarship">&#11088; Yes</span>
            <?php else: ?>
              <span style="color:#9ca3af;">No</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <button type="button" class="btn btn-warning btn-sm"
                    onclick="openOverride(
                      <?php echo (int)$p['PaymentId']; ?>,
                      '<?php echo addslashes($p['PaymentStatus']); ?>',
                      '<?php echo addslashes($p['StartDate'] ?? ''); ?>',
                      '<?php echo addslashes($p['EndDate'] ?? ''); ?>',
                      '<?php echo addslashes($p['OverrideNote'] ?? ''); ?>')">
              &#9998; Edit
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php echo paginator($paymentsTotal, $paymentsPage, PAGE_SIZE, $qsPayments, 'p'); ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($examsWithOverride) || $examFeeTotal > 0): ?>
<!-- ── Exam-level fee-override payment list (migration_v50) ────────────────── -->
<div class="card" style="margin-top:20px;">
  <div class="card-header">
    &#128273; Exam-Level Fee Payments (<?php echo $examFeeTotal; ?>)
    <span style="font-weight:400;font-size:.78rem;color:#166534;">— the live ledger (subject enrollments above are legacy/historical)</span>
  </div>
  <form method="get" action="EnrollmentPayments.php" style="padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#f7fafc;">
    <input type="text" name="eq" value="<?php echo htmlspecialchars($examFeeSearch); ?>" class="form-control"
           placeholder="&#128269; Search by student or exam name…" style="flex:1;min-width:220px;max-width:360px;">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($examFeeSearch !== ''): ?>
      <a href="EnrollmentPayments.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </form>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <?php if (empty($examFeePayments)): ?>
      <p style="padding:20px;color:#718096;text-align:center;">
        <?php echo $examFeeSearch !== '' ? 'No exam-level fee payments match your search.' : 'No exam-level fee payments yet.'; ?>
      </p>
    <?php else: ?>
    <table class="tbl" style="font-size:.81rem;">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Exam</th>
          <th>Fee</th>
          <th>Method / Txn ID</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($examFeePayments as $i => $p):
          [$bg, $fg] = explode(':', $statusColors[$p['PaymentStatus']] ?? '#f3f4f6:#374151');
          $method = $p['PaymentMethod'] ?: 'Razorpay';
          $awaitingVerification = ($method === 'UPI' && $p['PaymentStatus'] === 'Pending' && !empty($p['TransactionId']));
        ?>
        <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>"
            <?php echo $awaitingVerification ? 'style="background:#fefce8;"' : ''; ?>>
          <td><?php echo (int)$p['PaymentId']; ?></td>
          <td>
            <strong><?php echo htmlspecialchars(trim($p['FstName'].' '.$p['LstName'])); ?></strong><br>
            <span style="color:#6b7280;font-size:.76rem;"><?php echo htmlspecialchars($p['LoginName']); ?></span>
          </td>
          <td><?php echo htmlspecialchars($p['ExamName']); ?></td>
          <td><strong>&#8377;<?php echo number_format((float)$p['FeeAtTime'], 2); ?></strong></td>
          <td style="font-size:.78rem;white-space:nowrap;">
            <span style="font-weight:700;color:<?php echo $method === 'UPI' ? '#7c3aed' : '#1d4ed8'; ?>;">
              <?php echo $method === 'UPI' ? '📱 UPI' : '💳 Razorpay'; ?>
            </span>
            <?php if (!empty($p['TransactionId'])): ?>
              <br><span style="font-family:monospace;color:#374151;"><?php echo htmlspecialchars($p['TransactionId']); ?></span>
              <?php if ($awaitingVerification): ?>
                <br><span style="font-size:.71rem;color:#854d0e;font-weight:700;">&#9203; Verify me</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-badge" style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;">
              <?php echo htmlspecialchars($p['PaymentStatus']); ?>
            </span>
          </td>
          <td>
            <button type="button" class="btn btn-warning btn-sm"
                    onclick="openExamOverride(<?php echo (int)$p['PaymentId']; ?>, '<?php echo addslashes($p['PaymentStatus']); ?>', '<?php echo addslashes($p['OverrideNote'] ?? ''); ?>')">
              &#9998; Edit
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php echo paginator($examFeeTotal, $examFeePage, PAGE_SIZE, $qsExamFee, 'ep'); ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── Exam fee payment override modal ───────────────────────────────────── -->
<div id="examOverrideModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;
     align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;padding:28px 32px;max-width:440px;width:94%;
              box-shadow:0 8px 32px rgba(0,0,0,.22);">
    <h3 style="margin:0 0 16px;font-size:1.1rem;color:#1e3a5f;">&#9998; Edit Exam Fee Payment</h3>
    <form method="post" action="EnrollmentPayments.php">
      <input type="hidden" name="action" value="exam_override">
      <input type="hidden" name="ExamPaymentId" id="modalExamPaymentId" value="">
      <div class="form-group">
        <label>Payment Status</label>
        <select name="NewStatus" id="modalExamNewStatus" class="form-control">
          <option value="Paid">Paid</option>
          <option value="Waived">Waived</option>
          <option value="Free">Free</option>
          <option value="Pending">Pending</option>
          <option value="Failed">Failed</option>
          <option value="Refunded">Refunded</option>
        </select>
      </div>
      <div class="form-group">
        <label>Override Note</label>
        <input type="text" name="OverrideNote" id="modalExamNote" class="form-control" maxlength="255"
               placeholder="Reason for change…">
      </div>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">&#128190; Save Changes</button>
        <button type="button" class="btn btn-secondary" onclick="closeExamOverride()">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
function openExamOverride(paymentId, status, note) {
  document.getElementById('modalExamPaymentId').value = paymentId;
  document.getElementById('modalExamNewStatus').value = status;
  document.getElementById('modalExamNote').value       = note;
  document.getElementById('examOverrideModal').style.display = 'flex';
}
function closeExamOverride() {
  document.getElementById('examOverrideModal').style.display = 'none';
}
document.getElementById('examOverrideModal').addEventListener('click', function(e) {
  if (e.target === this) closeExamOverride();
});
</script>
<?php endif; ?>

<!-- ── Override / Edit modal ─────────────────────────────────────────────── -->
<div id="overrideModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;
     align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;padding:28px 32px;max-width:480px;width:94%;
              box-shadow:0 8px 32px rgba(0,0,0,.22);max-height:90vh;overflow-y:auto;">
    <h3 style="margin:0 0 16px;font-size:1.1rem;color:#1e3a5f;">&#9998; Edit Enrollment Record</h3>
    <form method="post" action="EnrollmentPayments.php">
      <input type="hidden" name="action"    value="override">
      <input type="hidden" name="PaymentId" id="modalPaymentId" value="">

      <div class="form-group">
        <label>Payment Status</label>
        <select name="NewStatus" id="modalNewStatus" class="form-control">
          <option value="Paid">Paid</option>
          <option value="Waived">Waived</option>
          <option value="Free">Free</option>
          <option value="Pending">Pending</option>
          <option value="Failed">Failed</option>
          <option value="Refunded">Refunded</option>
        </select>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 12px;">
        <div class="form-group">
          <label>Enrollment Start Date</label>
          <input type="date" name="StartDate" id="modalStartDate" class="form-control">
        </div>
        <div class="form-group">
          <label>Enrollment End Date
            <span style="color:#6b7280;font-size:.75rem;">(blank = no expiry)</span>
          </label>
          <input type="date" name="EndDate" id="modalEndDate" class="form-control">
        </div>
      </div>

      <div class="form-group">
        <label>Override Note</label>
        <input type="text" name="OverrideNote" id="modalNote" class="form-control" maxlength="255"
               placeholder="Reason for change…">
      </div>

      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">&#128190; Save Changes</button>
        <button type="button" class="btn btn-secondary" onclick="closeOverride()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openOverride(paymentId, status, startDate, endDate, note) {
  document.getElementById('modalPaymentId').value  = paymentId;
  document.getElementById('modalNewStatus').value  = status;
  document.getElementById('modalStartDate').value  = startDate;
  document.getElementById('modalEndDate').value    = endDate;
  document.getElementById('modalNote').value       = note;
  document.getElementById('overrideModal').style.display = 'flex';
}
function closeOverride() {
  document.getElementById('overrideModal').style.display = 'none';
}
document.getElementById('overrideModal').addEventListener('click', function(e) {
  if (e.target === this) closeOverride();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
