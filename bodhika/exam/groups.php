<?php
/**
 * exam/groups.php — Admin: manage education-level groups (CRUD).
 *
 * Groups (Primary / Secondary / Undergraduate / ...) categorize Grades,
 * which in turn categorize Exams — Group -> Grade -> Exam. This mirrors
 * exam/grades.php's structure exactly; see migrations/migration_v44.sql
 * for the groupinfo table and gradeinfo.GroupId column this manages.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$pageTitle = 'Manage Groups';
$msg = ''; $isErr = false;
$migrationMissing = false;

/* ── Determine mode ────────────────────────────────────────────────────── */
$editId   = filter_input(INPUT_GET, 'edit',   FILTER_VALIDATE_INT) ?: 0;
$deleteId = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT) ?: 0;

/* ── DELETE ────────────────────────────────────────────────────────────── */
if ($deleteId > 0 && isset($_GET['confirm'])) {
    Auth::validateCsrf();
    $inUse = Database::fetchOne(
        "SELECT COUNT(*) AS c FROM gradeinfo WHERE GroupId = ?", [$deleteId]);
    if ((int)($inUse['c'] ?? 0) > 0) {
        $msg = 'Cannot delete — this group is used by ' . (int)$inUse['c'] . ' grade(s). Reassign those grades first.';
        $isErr = true;
    } else {
        Database::execute("DELETE FROM groupinfo WHERE GroupId = ?", [$deleteId]);
        $msg = 'Group deleted successfully.';
    }
    $editId = 0;
}

/* ── SAVE ──────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSave'])) {
    Auth::validateCsrf();
    $saveId    = (int)($_POST['saveId'] ?? 0);
    $name      = trim($_POST['txtName']   ?? '');
    $active    = ($_POST['txtActive'] ?? 'Y') === 'Y' ? 'Y' : 'N';
    $sortOrder = (int)($_POST['txtSortOrder'] ?? 0);

    if ($name === '') {
        $msg = 'Group name is required.'; $isErr = true;
    } else {
        $dup = Database::fetchOne(
            "SELECT GroupId FROM groupinfo WHERE GroupName = ? AND GroupId <> ?",
            [$name, $saveId]);
        if ($dup) {
            $msg = "A group named \"" . htmlspecialchars($name) . "\" already exists."; $isErr = true;
        } elseif ($saveId > 0) {
            Database::execute(
                "UPDATE groupinfo SET GroupName = ?, Active = ?, SortOrder = ? WHERE GroupId = ?",
                [$name, $active, $sortOrder, $saveId]);
            $msg = 'Group updated successfully.';
            $editId = 0;
        } else {
            Database::execute(
                "INSERT INTO groupinfo (GroupName, Active, SortOrder) VALUES (?, ?, ?)",
                [$name, $active, $sortOrder]);
            $msg = 'Group added successfully.';
            $editId = 0;
        }
    }
}

/* ── Load record being edited ──────────────────────────────────────────── */
$editing = null;
if ($editId > 0) {
    $editing = Database::fetchOne(
        "SELECT * FROM groupinfo WHERE GroupId = ?", [$editId]);
    if (!$editing) { $editId = 0; }
}

/* ── Load all groups (with grade + exam counts) ──────────────────────────── */
try {
    $groups = Database::fetchAll(
        "SELECT gr.*,
                (SELECT COUNT(*) FROM gradeinfo gd WHERE gd.GroupId = gr.GroupId) AS GradeCount,
                (SELECT COUNT(*) FROM gradeinfo gd
                   JOIN examinfo e ON e.GradeInfoId = gd.GradeInfoId
                  WHERE gd.GroupId = gr.GroupId) AS ExamCount
           FROM groupinfo gr
          ORDER BY gr.SortOrder, gr.GroupName");
} catch (Exception $e) {
    $migrationMissing = true;
    $groups = [];
}

/* Grades with no group yet, for the "needs attention" hint */
$uncategorizedCount = 0;
if (!$migrationMissing) {
    try {
        $uncategorizedCount = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM gradeinfo WHERE GroupId IS NULL")['c'] ?? 0);
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';
?>
<style>
  .setup-split{display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;}
  .setup-form {flex:0 0 320px;min-width:260px;}
  .setup-list {flex:1;min-width:280px;}
  .tbl td,.tbl th{padding:8px 12px;}
  .badge-active  {background:#ecfdf5;color:#059669;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700;}
  .badge-inactive{background:#fef2f2;color:#ef4444;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700;}
</style>

<!-- Page header -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#127959; Manage Groups</span>
    <div style="display:flex;gap:8px;">
      <a href="grades.php"   class="btn btn-secondary btn-sm">&#127891; Grades</a>
      <a href="subjects.php" class="btn btn-secondary btn-sm">&#128218; Subjects</a>
      <a href="search.php"   class="btn btn-secondary btn-sm">&#8592; Back to Exams</a>
    </div>
  </div>
  <div class="card-body" style="padding:12px 20px;background:#f7fafc;border-bottom:1px solid #e2e8f0;font-size:.875rem;color:#4a5568;">
    &#128161; Groups categorize <strong>Grades</strong> (e.g. "NEET" &rarr; Competitive / Professional), and exams inherit
    their group from whichever grade they use. Assign a group to each grade on the
    <a href="grades.php" style="font-weight:600;">Manage Grades</a> page.
  </div>
</div>

<?php if ($migrationMissing): ?>
<div class="card" style="margin-bottom:12px;border-left:4px solid #f59e0b;">
  <div class="card-body" style="padding:12px 20px;color:#92400e;font-weight:600;">
    &#9888; Groups aren't set up on this database yet. Run <code>migrations/migration_v44.sql</code> to enable this page.
  </div>
</div>
<?php endif; ?>

<?php if (!$migrationMissing && $uncategorizedCount > 0): ?>
<div class="card" style="margin-bottom:12px;border-left:4px solid #3b82f6;">
  <div class="card-body" style="padding:12px 20px;color:#1e40af;font-weight:600;">
    &#8505; <?php echo $uncategorizedCount; ?> grade<?php echo $uncategorizedCount !== 1 ? 's' : ''; ?> not yet assigned to a group.
    <a href="grades.php" style="color:#1e40af;text-decoration:underline;">Assign them now &rarr;</a>
  </div>
</div>
<?php endif; ?>

<?php if ($msg): ?>
<div class="card" style="margin-bottom:12px;border-left:4px solid <?php echo $isErr ? '#ef4444' : '#059669'; ?>;">
  <div class="card-body" style="padding:12px 20px;color:<?php echo $isErr ? '#c53030' : '#276749'; ?>;font-weight:600;">
    <?php echo $isErr ? '&#9888; ' : '&#10004; '; echo htmlspecialchars($msg); ?>
  </div>
</div>
<?php endif; ?>

<div class="setup-split">

  <!-- ── Add / Edit Form ─────────────────────────────────────────── -->
  <div class="card setup-form">
    <div class="card-header">
      <?php echo $editing ? '&#9998; Edit Group' : '&#10010; Add Group'; ?>
    </div>
    <div class="card-body">
      <form method="post" action="groups.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
        <input type="hidden" name="saveId" value="<?php echo $editId; ?>">

        <div class="form-group">
          <label for="txtName">Group Name <span style="color:#e53e3e">*</span></label>
          <input type="text" id="txtName" name="txtName" class="form-control"
                 maxlength="100" required autofocus
                 placeholder="e.g. Secondary, Undergraduate"
                 value="<?php echo htmlspecialchars($editing['GroupName'] ?? ''); ?>">
        </div>

        <div class="form-group">
          <label for="txtSortOrder">Display Order</label>
          <input type="number" id="txtSortOrder" name="txtSortOrder" class="form-control"
                 min="0" max="999"
                 value="<?php echo (int)($editing['SortOrder'] ?? 0); ?>">
          <small style="color:#6b7280;">Lower numbers show first in dropdowns and filters.</small>
        </div>

        <div class="form-group">
          <label for="txtActive">Status</label>
          <select id="txtActive" name="txtActive" class="form-control">
            <option value="Y" <?php echo (!$editing || ($editing['Active'] ?? 'Y') === 'Y') ? 'selected' : ''; ?>>Active</option>
            <option value="N" <?php echo ($editing && ($editing['Active'] ?? 'Y') === 'N') ? 'selected' : ''; ?>>Inactive</option>
          </select>
        </div>

        <div style="display:flex;gap:8px;margin-top:16px;">
          <button type="submit" name="btnSave" class="btn btn-success">
            <?php echo $editing ? '&#10003; Save Changes' : '&#10010; Add Group'; ?>
          </button>
          <?php if ($editing): ?>
            <a href="groups.php" class="btn btn-secondary">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Groups List ─────────────────────────────────────────────── -->
  <div class="card setup-list">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span>All Groups</span>
      <span style="font-size:.8rem;color:#94a3b8;"><?php echo count($groups); ?> total</span>
    </div>
    <div style="overflow-x:auto;">
      <?php if (empty($groups)): ?>
        <p style="padding:20px;color:#718096;text-align:center;">
          <?php echo $migrationMissing ? 'Run the migration above to get started.' : 'No groups yet. Add one using the form.'; ?>
        </p>
      <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>Group Name</th>
            <th style="width:70px;text-align:center;">Status</th>
            <th style="width:60px;text-align:center;">Grades</th>
            <th style="width:60px;text-align:center;">Exams</th>
            <th style="width:120px;text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($groups as $i => $g):
            $gid       = (int)$g['GroupId'];
            $isActive  = ($g['Active'] ?? 'Y') === 'Y';
            $gradeCnt  = (int)$g['GradeCount'];
            $examCnt   = (int)$g['ExamCount'];
          ?>
          <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>"
              <?php if ($editId === $gid) echo 'style="background:#ede9fe;"'; ?>>
            <td style="font-weight:<?php echo $editId===$gid?'700':'400'; ?>;">
              <?php echo htmlspecialchars($g['GroupName']); ?>
            </td>
            <td class="text-center">
              <span class="<?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?>">
                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
              </span>
            </td>
            <td class="text-center">
              <?php if ($gradeCnt > 0): ?>
                <span style="font-weight:700;color:#4f46e5;"><?php echo $gradeCnt; ?></span>
              <?php else: ?>
                <span style="color:#a0aec0;">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($examCnt > 0): ?>
                <span style="font-weight:700;color:#0891b2;"><?php echo $examCnt; ?></span>
              <?php else: ?>
                <span style="color:#a0aec0;">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                <a href="groups.php?edit=<?php echo $gid; ?>"
                   class="btn btn-warning btn-sm" title="Edit">&#9998; Edit</a>
                <?php if ($gradeCnt === 0): ?>
                  <a href="groups.php?delete=<?php echo $gid; ?>&confirm=1&csrf_token=<?php echo htmlspecialchars(Auth::csrfToken()); ?>"
                     class="btn btn-sm" style="background:#ef4444;color:#fff;"
                     title="Delete"
                     onclick="return confirm('Delete group &quot;<?php echo addslashes($g['GroupName']); ?>&quot;?')">
                    &#128465; Del
                  </a>
                <?php else: ?>
                  <span style="font-size:.75rem;color:#a0aec0;padding:2px 6px;" title="In use by <?php echo $gradeCnt; ?> grade(s)">
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
