<?php
/**
 * exam/grades.php — Admin: manage exam grades (CRUD).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$pageTitle = 'Manage Grades';
$msg = ''; $isErr = false;

/* ── Determine mode ────────────────────────────────────────────────────── */
$editId   = filter_input(INPUT_GET, 'edit',   FILTER_VALIDATE_INT) ?: 0;
$deleteId = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT) ?: 0;

/* ── DELETE ────────────────────────────────────────────────────────────── */
if ($deleteId > 0 && isset($_GET['confirm'])) {
    Auth::validateCsrf();
    $inUse = Database::fetchOne(
        "SELECT COUNT(*) AS c FROM examinfo WHERE GradeInfoId = ?", [$deleteId]);
    if ((int)($inUse['c'] ?? 0) > 0) {
        $msg = 'Cannot delete — this grade is used by ' . (int)$inUse['c'] . ' exam(s). Remove or reassign those exams first.';
        $isErr = true;
    } else {
        Database::execute("DELETE FROM gradeinfo WHERE GradeInfoId = ?", [$deleteId]);
        $msg = 'Grade deleted successfully.';
    }
    $editId = 0;
}

/* ── SAVE ──────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnSave'])) {
    Auth::validateCsrf();
    $saveId  = (int)($_POST['saveId'] ?? 0);
    $name    = trim($_POST['txtName']   ?? '');
    $active  = ($_POST['txtActive'] ?? 'Y') === 'Y' ? 'Y' : 'N';
    $groupId = (int)($_POST['txtGroupId'] ?? 0) ?: null;   // 0 = "Uncategorized" -> NULL

    if ($name === '') {
        $msg = 'Grade name is required.'; $isErr = true;
    } else {
        $dup = Database::fetchOne(
            "SELECT GradeInfoId FROM gradeinfo WHERE GradeName = ? AND GradeInfoId <> ?",
            [$name, $saveId]);
        if ($dup) {
            $msg = "A grade named \"" . htmlspecialchars($name) . "\" already exists."; $isErr = true;
        } elseif ($saveId > 0) {
            /* Try updating with GroupId + Active; fall back through missing columns */
            try {
                Database::execute(
                    "UPDATE gradeinfo SET GradeName = ?, GroupId = ?, Active = ? WHERE GradeInfoId = ?",
                    [$name, $groupId, $active, $saveId]);
            } catch (Exception $e) {
                try {
                    Database::execute(
                        "UPDATE gradeinfo SET GradeName = ?, Active = ? WHERE GradeInfoId = ?",
                        [$name, $active, $saveId]);
                } catch (Exception $e2) {
                    Database::execute(
                        "UPDATE gradeinfo SET GradeName = ? WHERE GradeInfoId = ?",
                        [$name, $saveId]);
                }
            }
            $msg = 'Grade updated successfully.';
            $editId = 0;
        } else {
            /* Insert — GroupId + Active may not exist on every install; fall back gracefully. */
            try {
                Database::execute(
                    "INSERT INTO gradeinfo (GradeName, GroupId, Active) VALUES (?, ?, ?)",
                    [$name, $groupId, $active]);
            } catch (Exception $e) {
                try {
                    Database::execute(
                        "INSERT INTO gradeinfo (GradeName, Active) VALUES (?, ?)",
                        [$name, $active]);
                } catch (Exception $e2) {
                    Database::execute(
                        "INSERT INTO gradeinfo (GradeName) VALUES (?)", [$name]);
                }
            }
            $msg = 'Grade added successfully.';
            $editId = 0;
        }
    }
}

/* ── Load record being edited ──────────────────────────────────────────── */
$editing = null;
if ($editId > 0) {
    $editing = Database::fetchOne(
        "SELECT * FROM gradeinfo WHERE GradeInfoId = ?", [$editId]);
    if (!$editing) { $editId = 0; }
}

/* ── Load groups for the picker (gracefully empty if migration_v44 not run) ── */
try {
    $groupOptions = Database::fetchAll(
        "SELECT GroupId, GroupName FROM groupinfo WHERE Active = 'Y' ORDER BY SortOrder, GroupName");
} catch (Exception $e) {
    $groupOptions = [];
}

/* ── Load all grades ───────────────────────────────────────────────────── */
try {
    $grades = Database::fetchAll(
        "SELECT g.*, gr.GroupName,
                (SELECT COUNT(*) FROM examinfo e WHERE e.GradeInfoId = g.GradeInfoId) AS ExamCount
           FROM gradeinfo g
      LEFT JOIN groupinfo gr ON gr.GroupId = g.GroupId
          ORDER BY g.GradeName");
} catch (Exception $e) {
    // migration_v44 not yet run — GroupId column missing on gradeinfo
    $grades = Database::fetchAll(
        "SELECT g.*, NULL AS GroupName,
                (SELECT COUNT(*) FROM examinfo e WHERE e.GradeInfoId = g.GradeInfoId) AS ExamCount
           FROM gradeinfo g
          ORDER BY g.GradeName");
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
    <span>&#127891; Manage Grades</span>
    <div style="display:flex;gap:8px;">
      <a href="groups.php"   class="btn btn-secondary btn-sm">&#127959; Groups</a>
      <a href="subjects.php" class="btn btn-secondary btn-sm">&#128218; Subjects</a>
      <a href="search.php"   class="btn btn-secondary btn-sm">&#8592; Back to Exams</a>
    </div>
  </div>
</div>

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
      <?php echo $editing ? '&#9998; Edit Grade' : '&#10010; Add Grade'; ?>
    </div>
    <div class="card-body">
      <form method="post" action="grades.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
        <input type="hidden" name="saveId" value="<?php echo $editId; ?>">

        <div class="form-group">
          <label for="txtName">Grade Name <span style="color:#e53e3e">*</span></label>
          <input type="text" id="txtName" name="txtName" class="form-control"
                 maxlength="100" required autofocus
                 placeholder="e.g. All, Grade 10, Year 12"
                 value="<?php echo htmlspecialchars($editing['GradeName'] ?? ''); ?>">
        </div>

        <div class="form-group">
          <label for="txtGroupId" style="display:flex;justify-content:space-between;align-items:center;">
            <span>Group</span>
            <a href="groups.php" style="font-size:.73rem;color:#4f46e5;font-weight:500;" title="Manage groups">&#127959; Manage</a>
          </label>
          <select id="txtGroupId" name="txtGroupId" class="form-control">
            <option value="0">— Uncategorized —</option>
            <?php foreach ($groupOptions as $go): ?>
              <option value="<?php echo (int)$go['GroupId']; ?>"
                <?php echo ((int)($editing['GroupId'] ?? 0) === (int)$go['GroupId']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($go['GroupName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($groupOptions)): ?>
            <p style="font-size:.75rem;color:#e53e3e;margin:4px 0 0;">
              No groups found. <a href="groups.php">Add one</a> to categorize this grade (e.g. Primary, Secondary).
            </p>
          <?php endif; ?>
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
            <?php echo $editing ? '&#10003; Save Changes' : '&#10010; Add Grade'; ?>
          </button>
          <?php if ($editing): ?>
            <a href="grades.php" class="btn btn-secondary">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Grades List ─────────────────────────────────────────────── -->
  <div class="card setup-list">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span>All Grades</span>
      <span style="font-size:.8rem;color:#94a3b8;"><?php echo count($grades); ?> total</span>
    </div>
    <div style="overflow-x:auto;">
      <?php if (empty($grades)): ?>
        <p style="padding:20px;color:#718096;text-align:center;">No grades yet. Add one using the form.</p>
      <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>Grade Name</th>
            <th style="width:130px;">Group</th>
            <th style="width:70px;text-align:center;">Status</th>
            <th style="width:60px;text-align:center;">Exams</th>
            <th style="width:120px;text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grades as $i => $g):
            $gid      = (int)$g['GradeInfoId'];
            $isActive = ($g['Active'] ?? 'Y') === 'Y';
            $examCnt  = (int)$g['ExamCount'];
          ?>
          <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>"
              <?php if ($editId === $gid) echo 'style="background:#ede9fe;"'; ?>>
            <td style="font-weight:<?php echo $editId===$gid?'700':'400'; ?>;">
              <?php echo htmlspecialchars($g['GradeName']); ?>
            </td>
            <td>
              <?php if (!empty($g['GroupName'])): ?>
                <span style="background:#eef2ff;color:#4338ca;padding:2px 9px;border-radius:10px;font-size:.75rem;font-weight:700;white-space:nowrap;">
                  <?php echo htmlspecialchars($g['GroupName']); ?>
                </span>
              <?php else: ?>
                <span style="background:#f1f5f9;color:#94a3b8;padding:2px 9px;border-radius:10px;font-size:.72rem;font-weight:600;" title="Assign a group using Edit">
                  Uncategorized
                </span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="<?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?>">
                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
              </span>
            </td>
            <td class="text-center">
              <?php if ($examCnt > 0): ?>
                <span style="font-weight:700;color:#4f46e5;"><?php echo $examCnt; ?></span>
              <?php else: ?>
                <span style="color:#a0aec0;">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                <a href="grades.php?edit=<?php echo $gid; ?>"
                   class="btn btn-warning btn-sm" title="Edit">&#9998; Edit</a>
                <?php if ($examCnt === 0): ?>
                  <a href="grades.php?delete=<?php echo $gid; ?>&confirm=1&csrf_token=<?php echo htmlspecialchars(Auth::csrfToken()); ?>"
                     class="btn btn-sm" style="background:#ef4444;color:#fff;"
                     title="Delete"
                     onclick="return confirm('Delete grade &quot;<?php echo addslashes($g['GradeName']); ?>&quot;?')">
                    &#128465; Del
                  </a>
                <?php else: ?>
                  <span style="font-size:.75rem;color:#a0aec0;padding:2px 6px;" title="In use by <?php echo $examCnt; ?> exam(s)">
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
