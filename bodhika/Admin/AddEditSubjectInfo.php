<?php
/**
 * Admin/AddEditSubjectInfo.php — Add / Edit Subject (modernised to PDO + fee fields)
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$primkeyId = filter_input(INPUT_GET, 'InfoId', FILTER_VALIDATE_INT) ?: 0;

/* ── Load existing row ───────────────────────────────────────────────────── */
$row = [];
if ($primkeyId > 0) {
    $row = Database::fetchOne(
        "SELECT * FROM subjectinfo WHERE SubjectInfoId = ? LIMIT 1", [$primkeyId]) ?: [];
}

/* ── Handle save ─────────────────────────────────────────────────────────── */
$errors  = [];
$success = '';
if (isset($_POST['save'])) {
    $subjectName  = trim($_POST['txtSubjectName'] ?? '');
    $active       = trim($_POST['Active'] ?? 'Y');
    $examFee      = max(0.0, (float)str_replace(',', '', $_POST['ExamFee']      ?? '0'));
    $discountPct  = min(100.0, max(0.0, (float)($_POST['DiscountPct'] ?? '0')));

    if ($subjectName === '') $errors[] = 'Subject Name is required.';

    if (empty($errors)) {
        if ($primkeyId > 0) {
            Database::execute(
                "UPDATE subjectinfo
                    SET SubjectName = ?, Active = ?,
                        ExamFee     = ?, DiscountPct = ?
                  WHERE SubjectInfoId = ?",
                [$subjectName, $active, $examFee, $discountPct, $primkeyId]);
            $success = 'Subject updated successfully.';
            /* Refresh row */
            $row = Database::fetchOne(
                "SELECT * FROM subjectinfo WHERE SubjectInfoId = ? LIMIT 1", [$primkeyId]) ?: [];
        } else {
            Database::execute(
                "INSERT INTO subjectinfo (SubjectName, Active, ExamFee, DiscountPct)
                 VALUES (?, ?, ?, ?)",
                [$subjectName, $active, $examFee, $discountPct]);
            header('Location: SubjectInfo.php');
            exit;
        }
    }
}

$pageTitle = ($primkeyId > 0 ? 'Edit' : 'Add') . ' Subject';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .form-wrap { max-width:560px; margin:0 auto; }
  .field-hint { font-size:.78rem; color:#6b7280; margin-top:3px; }
  .input-prefix { display:flex; align-items:center; }
  .input-prefix span { padding:6px 10px; background:#f3f4f6; border:1px solid #d1d5db;
                       border-right:0; border-radius:4px 0 0 4px; font-size:.9rem; color:#374151; }
  .input-prefix input { border-radius:0 4px 4px 0; }
</style>

<div class="card form-wrap">
  <div class="card-header">&#128218; <?php echo htmlspecialchars($pageTitle); ?></div>
  <div class="card-body">

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="post" action="">

      <!-- Subject Name -->
      <div class="form-group">
        <label>Subject Name <span style="color:#dc2626;">*</span></label>
        <input type="text" name="txtSubjectName" class="form-control" maxlength="150"
               value="<?php echo htmlspecialchars($row['SubjectName'] ?? ''); ?>" required>
      </div>

      <!-- Active -->
      <div class="form-group">
        <label>Active <span style="color:#dc2626;">*</span></label>
        <select name="Active" class="form-control">
          <option value="Y" <?php echo (($row['Active'] ?? 'Y') === 'Y') ? 'selected' : ''; ?>>Yes</option>
          <option value="N" <?php echo (($row['Active'] ?? '') === 'N') ? 'selected' : ''; ?>>No</option>
        </select>
      </div>

      <!-- Exam Fee -->
      <div class="form-group">
        <label>Exam Fee (&#8377;)</label>
        <div class="input-prefix">
          <span>&#8377;</span>
          <input type="number" name="ExamFee" class="form-control" min="0" step="0.01"
                 value="<?php echo number_format((float)($row['ExamFee'] ?? 0), 2, '.', ''); ?>"
                 placeholder="0.00">
        </div>
        <div class="field-hint">Set to 0 for free access. Students must pay before attempting the exam.</div>
      </div>

      <!-- Default Discount -->
      <div class="form-group">
        <label>Default Discount (%)</label>
        <div class="input-prefix">
          <span>%</span>
          <input type="number" name="DiscountPct" class="form-control" min="0" max="100" step="0.01"
                 value="<?php echo number_format((float)($row['DiscountPct'] ?? 0), 2, '.', ''); ?>"
                 placeholder="0.00">
        </div>
        <div class="field-hint">Applied automatically to all students (before any coupon code). 0 = no discount.</div>
      </div>

      <div style="display:flex;gap:10px;margin-top:24px;">
        <button type="submit" name="save" class="btn btn-primary">&#128190; Save</button>
        <a href="SubjectInfo.php" class="btn btn-secondary">&#8592; Back</a>
      </div>
    </form>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
