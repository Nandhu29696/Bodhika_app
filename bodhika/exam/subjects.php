<?php
/**
 * exam/subjects.php — Admin: manage exam subjects (CRUD + fee).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$pageTitle = 'Manage Subjects';
$msg = ''; $isErr = false;

/* ── Determine mode ────────────────────────────────────────────────────── */
$editId   = filter_input(INPUT_GET, 'edit',   FILTER_VALIDATE_INT) ?: 0;
$deleteId = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT) ?: 0;

/* ── DELETE ────────────────────────────────────────────────────────────── */
if ($deleteId > 0 && isset($_GET['confirm'])) {
    Auth::validateCsrf();
    $inUse = Database::fetchOne(
        "SELECT COUNT(*) AS c FROM examinfo WHERE SubjectInfoId = ?", [$deleteId]);
    if ((int)($inUse['c'] ?? 0) > 0) {
        $msg = 'Cannot delete — this subject is used by ' . (int)$inUse['c'] . ' exam(s).';
        $isErr = true;
    } else {
        Database::execute("DELETE FROM subjectinfo WHERE SubjectInfoId = ?", [$deleteId]);
        $msg = 'Subject deleted.';
    }
    $editId = 0;
}

/* ── SAVE (add or update) ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSave'])) {
    Auth::validateCsrf();
    $saveId   = (int)($_POST['saveId']      ?? 0);
    $name     = trim($_POST['txtName']      ?? '');
    $active   = ($_POST['txtActive']        ?? 'Y') === 'Y' ? 'Y' : 'N';
    $fee      = max(0, (float)($_POST['txtFee']      ?? 0));
    $discount = min(100, max(0, (float)($_POST['txtDiscount'] ?? 0)));

    if ($name === '') {
        $msg = 'Subject name is required.'; $isErr = true;
    } else {
        $dup = Database::fetchOne(
            "SELECT SubjectInfoId FROM subjectinfo WHERE SubjectName = ? AND SubjectInfoId <> ?",
            [$name, $saveId]);
        if ($dup) {
            $msg = 'A subject named "' . htmlspecialchars($name) . '" already exists.'; $isErr = true;
        } elseif ($saveId > 0) {
            try {
                Database::execute(
                    "UPDATE subjectinfo SET SubjectName=?, Active=?, ExamFee=?, DiscountPct=?
                      WHERE SubjectInfoId=?",
                    [$name, $active, $fee, $discount, $saveId]);
            } catch (Exception $e) {
                Database::execute(
                    "UPDATE subjectinfo SET SubjectName=?, Active=? WHERE SubjectInfoId=?",
                    [$name, $active, $saveId]);
            }
            $msg = 'Subject updated.';
            $editId = 0;
        } else {
            try {
                Database::execute(
                    "INSERT INTO subjectinfo (SubjectName, Active, ExamFee, DiscountPct) VALUES (?,?,?,?)",
                    [$name, $active, $fee, $discount]);
            } catch (Exception $e) {
                Database::execute(
                    "INSERT INTO subjectinfo (SubjectName, Active) VALUES (?,?)",
                    [$name, $active]);
            }
            $msg = 'Subject added.';
            $editId = 0;
        }
    }
}

/* ── Load record being edited ──────────────────────────────────────────── */
$editing = null;
if ($editId > 0) {
    $editing = Database::fetchOne(
        "SELECT * FROM subjectinfo WHERE SubjectInfoId = ?", [$editId]);
    if (!$editing) $editId = 0;
}

/* ── Load all subjects with fee + enrollment counts ────────────────────── */
$subjects = Database::fetchAll(
    "SELECT s.*,
            COALESCE(s.ExamFee, 0)     AS ExamFee,
            COALESCE(s.DiscountPct, 0) AS DiscountPct,
            (SELECT COUNT(*) FROM examinfo e WHERE e.SubjectInfoId = s.SubjectInfoId) AS ExamCount,
            (SELECT COUNT(*) FROM enrollment_payments ep
              WHERE ep.SubjectInfoId = s.SubjectInfoId
                AND ep.PaymentStatus IN ('Paid','Waived','Free'))                      AS EnrollCount
       FROM subjectinfo s
      ORDER BY s.SubjectName");

include __DIR__ . '/../includes/header.php';
?>
<style>
  .setup-split   { display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start; }
  .setup-form    { flex:0 0 340px; min-width:280px; }
  .setup-list    { flex:1; min-width:320px; }
  .badge-active  { background:#ecfdf5;color:#059669;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700; }
  .badge-inactive{ background:#fef2f2;color:#ef4444;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700; }
  .fee-free      { color:var(--clr-text-faint); font-size:.8rem; }
  .fee-amount    { font-weight:700; color:#1e40af; }
  .disc-pct      { font-size:.75rem; color:var(--clr-success); font-weight:600; }
</style>

<!-- Page header -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128218; Manage Subjects</span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="../Admin/EnrollmentPayments.php" class="btn btn-sm" style="background:#0891b2;color:#fff;">&#128200; Enrollment &amp; Payments</a>
      <a href="grades.php"  class="btn btn-secondary btn-sm">&#127891; Grades</a>
      <a href="search.php"  class="btn btn-secondary btn-sm">&#8592; Back to Exams</a>
    </div>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert <?php echo $isErr ? 'alert-danger' : 'alert-success'; ?>" style="margin-bottom:12px;">
  <?php echo $isErr ? '&#9888; ' : '&#10004; '; echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div class="setup-split">

  <!-- ── Add / Edit Form ──────────────────────────────────────────────── -->
  <div class="card setup-form">
    <div class="card-header">
      <?php echo $editing ? '&#9998; Edit Subject' : '&#10010; Add Subject'; ?>
    </div>
    <div class="card-body">
      <form method="post" action="subjects.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
        <input type="hidden" name="saveId"     value="<?php echo $editId; ?>">

        <div class="form-group">
          <label for="txtName">Subject Name <span style="color:#e53e3e">*</span></label>
          <input type="text" id="txtName" name="txtName" class="form-control"
                 maxlength="100" required autofocus
                 value="<?php echo htmlspecialchars($editing['SubjectName'] ?? ''); ?>">
        </div>

        <div class="form-group">
          <label for="txtActive">Status</label>
          <select id="txtActive" name="txtActive" class="form-control">
            <option value="Y" <?php echo (!$editing || ($editing['Active'] ?? 'Y') === 'Y') ? 'selected' : ''; ?>>Active</option>
            <option value="N" <?php echo ($editing && ($editing['Active'] ?? 'Y') === 'N') ? 'selected' : ''; ?>>Inactive</option>
          </select>
        </div>

        <div class="form-row cols-2">
          <div class="form-group">
            <label for="txtFee">Exam Fee (&#8377;)</label>
            <input type="number" id="txtFee" name="txtFee" class="form-control"
                   min="0" step="0.01" placeholder="0.00"
                   value="<?php echo number_format((float)($editing['ExamFee'] ?? 0), 2, '.', ''); ?>">
            <small style="color:var(--clr-text-muted);font-size:.75rem;">Leave 0 for free access</small>
          </div>
          <div class="form-group">
            <label for="txtDiscount">Discount (%)</label>
            <input type="number" id="txtDiscount" name="txtDiscount" class="form-control"
                   min="0" max="100" step="0.1" placeholder="0"
                   value="<?php echo number_format((float)($editing['DiscountPct'] ?? 0), 1, '.', ''); ?>">
            <small style="color:var(--clr-text-muted);font-size:.75rem;">0–100, applied at enrollment</small>
          </div>
        </div>

        <!-- Live fee preview -->
        <div id="feePreview" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;
                                     padding:10px 14px;margin-bottom:16px;font-size:.85rem;display:none;">
          <strong>Fee after discount:</strong>
          <span id="feeAfter" style="font-size:1.1rem;font-weight:800;color:#059669;"></span>
          <span id="feeOrig"  style="font-size:.8rem;color:#9ca3af;text-decoration:line-through;margin-left:6px;"></span>
        </div>

        <div style="display:flex;gap:8px;margin-top:4px;">
          <button type="submit" name="btnSave" class="btn btn-success">
            <?php echo $editing ? '&#10003; Save Changes' : '&#10010; Add Subject'; ?>
          </button>
          <?php if ($editing): ?>
            <a href="subjects.php" class="btn btn-secondary">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Subjects List ─────────────────────────────────────────────────── -->
  <div class="card setup-list">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span>All Subjects</span>
      <span style="font-size:.8rem;color:#94a3b8;"><?php echo count($subjects); ?> total</span>
    </div>
    <div class="tbl-wrap">
      <?php if (empty($subjects)): ?>
        <p style="padding:20px;color:#718096;text-align:center;">No subjects yet. Add one using the form.</p>
      <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>Subject Name</th>
            <th style="text-align:center;">Status</th>
            <th style="text-align:right;">Exam Fee</th>
            <th style="text-align:right;">Discount</th>
            <th style="text-align:center;">Exams</th>
            <th style="text-align:center;">Enrolled</th>
            <th style="text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subjects as $i => $s):
            $sid       = (int)$s['SubjectInfoId'];
            $isActive  = ($s['Active'] ?? 'Y') === 'Y';
            $examCnt   = (int)$s['ExamCount'];
            $enrollCnt = (int)($s['EnrollCount'] ?? 0);
            $fee       = (float)$s['ExamFee'];
            $disc      = (float)$s['DiscountPct'];
            $discFee   = $disc > 0 ? max(0, $fee - round($fee * $disc / 100, 2)) : $fee;
          ?>
          <tr <?php if ($editId === $sid) echo 'style="background:#ede9fe;"'; ?>>
            <td style="font-weight:<?php echo $editId===$sid ? '700' : '400'; ?>;">
              <?php echo htmlspecialchars($s['SubjectName']); ?>
            </td>
            <td class="text-center">
              <span class="<?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?>">
                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
              </span>
            </td>
            <td style="text-align:right;">
              <?php if ($fee <= 0): ?>
                <span class="fee-free">Free</span>
              <?php else: ?>
                <span class="fee-amount">&#8377;<?php echo number_format($discFee, 2); ?></span>
                <?php if ($disc > 0): ?>
                  <div style="font-size:.73rem;color:var(--clr-text-faint);text-decoration:line-through;">
                    &#8377;<?php echo number_format($fee, 2); ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td style="text-align:right;">
              <?php if ($disc > 0): ?>
                <span class="disc-pct">&#128722; <?php echo number_format($disc, 1); ?>%</span>
              <?php else: ?>
                <span class="fee-free">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($examCnt > 0): ?>
                <span style="font-weight:700;color:#4f46e5;"><?php echo $examCnt; ?></span>
              <?php else: ?>
                <span style="color:var(--clr-text-faint);">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($enrollCnt > 0): ?>
                <a href="../Admin/EnrollmentPayments.php?subject=<?php echo $sid; ?>"
                   style="font-weight:700;color:#0891b2;" title="View enrollments">
                  <?php echo $enrollCnt; ?>
                </a>
              <?php else: ?>
                <span style="color:var(--clr-text-faint);">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                <a href="subjects.php?edit=<?php echo $sid; ?>"
                   class="btn btn-warning btn-sm" title="Edit">&#9998; Edit</a>
                <?php if ($fee > 0): ?>
                  <a href="../Admin/EnrollmentPayments.php?subject=<?php echo $sid; ?>"
                     class="btn btn-sm" style="background:#0891b2;color:#fff;" title="View payments">
                    &#128200;
                  </a>
                <?php endif; ?>
                <?php if ($examCnt === 0): ?>
                  <a href="subjects.php?delete=<?php echo $sid; ?>&confirm=1&csrf_token=<?php echo htmlspecialchars(Auth::csrfToken()); ?>"
                     class="btn btn-danger btn-sm"
                     title="Delete"
                     onclick="return confirm('Delete subject &quot;<?php echo addslashes(htmlspecialchars($s['SubjectName'])); ?>&quot;?')">
                    &#128465; Del
                  </a>
                <?php else: ?>
                  <span style="font-size:.75rem;color:var(--clr-text-faint);padding:2px 4px;"
                        title="In use by <?php echo $examCnt; ?> exam(s)">
                    &#128274; In use
                  </span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /.setup-split -->

<script>
/* Live fee-after-discount preview */
(function () {
  var feeIn  = document.getElementById('txtFee');
  var discIn = document.getElementById('txtDiscount');
  var box    = document.getElementById('feePreview');
  var after  = document.getElementById('feeAfter');
  var orig   = document.getElementById('feeOrig');
  if (!feeIn || !discIn) return;

  function update() {
    var fee  = parseFloat(feeIn.value)  || 0;
    var disc = parseFloat(discIn.value) || 0;
    if (fee <= 0) { box.style.display = 'none'; return; }
    var discFee = Math.max(0, fee - Math.round(fee * disc / 100 * 100) / 100);
    after.textContent = '₹' + discFee.toFixed(2);
    if (disc > 0) {
      orig.textContent  = '₹' + fee.toFixed(2);
      orig.style.display = '';
    } else {
      orig.style.display = 'none';
    }
    box.style.display = 'block';
  }

  feeIn.addEventListener('input', update);
  discIn.addEventListener('input', update);
  update(); // run on page load when editing
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
