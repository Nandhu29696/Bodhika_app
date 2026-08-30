<?php
/**
 * Admin/StudentGroups.php — list & manage student groups (batches/cohorts).
 *
 * See Lib/StudentGroup.php's docblock for what a group actually does:
 * bulk exam assignment (recommendation, not free access) + one blanket
 * discount % for every member, plus (migration_v68) an optional shareable
 * self-registration link. This page lists groups; member management lives
 * in StudentGroupMembers.php, exam assignment in StudentGroupExams.php, and
 * the registration link lives on StudentGroupEdit.php (all linked per-row
 * below).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$msg = ''; $msgType = 'success';

/* ── Handle toggle active/inactive ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_gid'])) {
    Auth::validateCsrf();
    $gid      = (int)$_POST['toggle_gid'];
    $newState = $_POST['new_state'] === 'Y' ? 'Y' : 'N';
    Database::execute("UPDATE student_groups SET IsActive=? WHERE StudentGroupId=?", [$newState, $gid]);
    header('Location: StudentGroups.php'); exit;
}

/* ── Handle delete (cascades members + exam assignments — neither is
   referenced elsewhere, so this is always safe, unlike case studies which
   block on in-use questions) ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_gid'])) {
    Auth::validateCsrf();
    $gid = (int)$_POST['delete_gid'];
    Database::beginTransaction();
    try {
        Database::execute("DELETE FROM student_group_members WHERE StudentGroupId=?", [$gid]);
        Database::execute("DELETE FROM student_group_exam_assignments WHERE StudentGroupId=?", [$gid]);
        Database::execute("DELETE FROM student_groups WHERE StudentGroupId=?", [$gid]);
        Database::commit();
        header('Location: StudentGroups.php?deleted=1'); exit;
    } catch (\Throwable $e) {
        Database::rollBack();
        $msg = 'Delete failed: ' . $e->getMessage(); $msgType = 'danger';
    }
}

/* migration_v68 — guarded the same way everywhere else this column is used,
   so this page still renders fine on an install that hasn't run it yet. */
$hasCodeColumn = Database::hasColumn('student_groups', 'StudentGroupCode');

/* ── Load groups (all, including inactive — admins need to see/reactivate).
   Lib/StudentGroup.php::listAll() defaults to active-only for pricing/display
   use elsewhere; this admin list needs inactive groups too, so query
   directly rather than stretch that helper's signature for a one-off need. */
try {
    $groups = Database::fetchAll(
        "SELECT g.*,
                (SELECT COUNT(*) FROM student_group_members m WHERE m.StudentGroupId = g.StudentGroupId) AS MemberCount,
                (SELECT COUNT(*) FROM student_group_exam_assignments a WHERE a.StudentGroupId = g.StudentGroupId) AS ExamCount
           FROM student_groups g
          ORDER BY g.IsActive DESC, g.GroupName");
} catch (Exception $e) {
    $groups = [];
}

/* Same URL builder as StudentGroupEdit.php — kept in sync deliberately
   rather than shared, since this is the only other place it's needed and a
   shared helper would mean a third require just for one function. */
function groupRegistrationUrl(string $code): string {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $host . $base . '/auth/register-group.php?code=' . rawurlencode($code);
}

$pageTitle = 'Student Groups';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .sg-tbl { width:100%; border-collapse:collapse; }
  .sg-tbl th, .sg-tbl td { padding:9px 12px; text-align:left; vertical-align:middle; }
  .sg-tbl thead th { background:#1a365d; color:#fff; font-size:.82rem; }
  .sg-tbl tbody tr { border-bottom:1px solid #e2e8f0; }
  .sg-tbl tbody tr:hover { background:#ebf8ff; }
  .badge-active   { background:#c6efce; color:#276749; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:700; }
  .badge-inactive { background:#f0f0f0; color:#718096; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:700; }
  .badge-discount { background:#ede9fe; color:#5b21b6; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:700; }
  .action-btn { padding:3px 9px; border-radius:4px; font-size:.78rem; font-weight:600; text-decoration:none;
                border:none; cursor:pointer; display:inline-block; white-space:nowrap; }
  .btn-edit    { background:#3182ce; color:#fff; }
  .btn-members { background:#5b21b6; color:#fff; }
  .btn-view-exams   { background:#0e7490; color:#fff; }
  .btn-assign-exam  { background:#b45309; color:#fff; }
  .btn-reglink { background:#059669; color:#fff; }
  .btn-on      { background:#276749; color:#fff; }
  .btn-off     { background:#c53030; color:#fff; }
</style>

<nav style="font-size:.85rem;color:#718096;margin-bottom:10px;">
  <a href="AdminUsers.php?tab=students" style="color:#3182ce;text-decoration:none;">&#128101; Users</a>
  <span style="margin:0 6px;">›</span>
  <span>Student Groups</span>
</nav>

<?php if ($msg): ?>
  <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:16px;"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<?php if (filter_input(INPUT_GET, 'deleted', FILTER_VALIDATE_INT)): ?>
  <div class="alert alert-success" style="margin-bottom:16px;">&#10003; Student group deleted.</div>
<?php endif; ?>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128101; Student Groups</span>
    <a href="StudentGroupEdit.php" class="btn btn-success btn-sm" style="font-weight:700;">&#10010; New Group</a>
  </div>

  <div style="padding:10px 16px;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:.82rem;color:#1e40af;">
    &#8505; A group's discount % applies automatically to any exam a member enrolls in and pays for.
    Assigning an exam to a group makes it appear as "Recommended" for that group — it does not grant
    free access; every exam is already open for anyone to browse and self-enroll in.
    <?php if ($hasCodeColumn): ?>
      Use &#128279; <strong>Reg Link</strong> to copy a self-registration URL that auto-joins new students to a group.
    <?php endif; ?>
  </div>

  <?php if (empty($groups)): ?>
    <div style="text-align:center;padding:40px;color:#718096;">
      No student groups yet. <a href="StudentGroupEdit.php" style="color:#3182ce;">Create the first one</a>.
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="sg-tbl">
      <thead>
        <tr>
          <th>Name</th>
          <th>Description</th>
          <th style="text-align:center;">Discount</th>
          <th style="text-align:center;">Members</th>
          <th style="text-align:center;">Assigned Exams</th>
          <th style="text-align:center;">Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($groups as $g):
          $isActive = ($g['IsActive'] ?? 'Y') === 'Y';
          $gid      = (int)$g['StudentGroupId'];
          $regCode  = $hasCodeColumn ? ($g['StudentGroupCode'] ?? null) : null;
        ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($g['GroupName']); ?></strong></td>
          <td style="font-size:.85rem;color:#4a5568;"><?php echo htmlspecialchars($g['Description'] ?? ''); ?></td>
          <td style="text-align:center;">
            <?php if ((float)$g['DiscountPct'] > 0): ?>
              <span class="badge-discount"><?php echo rtrim(rtrim(number_format((float)$g['DiscountPct'], 2), '0'), '.'); ?>% off</span>
            <?php else: ?>
              <span style="color:#a0aec0;font-size:.8rem;">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <?php echo (int)$g['MemberCount']; ?>
            <a href="StudentGroupMembers.php?gid=<?php echo $gid; ?>" style="font-size:.75rem;color:#3182ce;margin-left:4px;">manage</a>
          </td>
          <td style="text-align:center;">
            <?php echo (int)$g['ExamCount']; ?>
            <a href="StudentGroupExams.php?gid=<?php echo $gid; ?>" style="font-size:.75rem;color:#3182ce;margin-left:4px;">manage</a>
          </td>
          <td style="text-align:center;">
            <span class="<?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?>">
              <?php echo $isActive ? 'Active' : 'Inactive'; ?>
            </span>
          </td>
          <td style="white-space:nowrap;">
            <a href="StudentGroupMembers.php?gid=<?php echo $gid; ?>" class="action-btn btn-members">&#128101; Members</a>
            <a href="StudentGroupExams.php?gid=<?php echo $gid; ?>" class="action-btn btn-view-exams">&#128220; Assigned Exams</a>
            <a href="StudentGroupExams.php?gid=<?php echo $gid; ?>#assign" class="action-btn btn-assign-exam">&#10010; Assign Exam</a>
            <a href="StudentGroupEdit.php?gid=<?php echo $gid; ?>" class="action-btn btn-edit">Edit</a>
            <?php if ($regCode): ?>
              <button type="button" class="action-btn btn-reglink"
                      onclick="copyGroupLink('<?php echo htmlspecialchars(groupRegistrationUrl($regCode), ENT_QUOTES); ?>', this)">
                &#128279; Reg Link
              </button>
            <?php elseif ($hasCodeColumn): ?>
              <a href="StudentGroupEdit.php?gid=<?php echo $gid; ?>" class="action-btn" style="background:#e2e8f0;color:#4a5568;">
                &#128279; Generate Link
              </a>
            <?php endif; ?>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('<?php echo $isActive ? 'Deactivate' : 'Activate'; ?> this group?');">
              <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="toggle_gid" value="<?php echo $gid; ?>">
              <input type="hidden" name="new_state"  value="<?php echo $isActive ? 'N' : 'Y'; ?>">
              <button type="submit" class="action-btn <?php echo $isActive ? 'btn-off' : 'btn-on'; ?>">
                <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
              </button>
            </form>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Delete group &quot;<?php echo addslashes($g['GroupName']); ?>&quot;? This removes all its members and exam assignments. This cannot be undone.');">
              <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="delete_gid" value="<?php echo $gid; ?>">
              <button type="submit" class="action-btn" style="background:#7f1d1d;color:#fff;">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
function copyGroupLink(url, btn) {
  var done = function () {
    var original = btn.textContent;
    btn.textContent = '✓ Copied';
    setTimeout(function () { btn.innerHTML = '&#128279; Reg Link'; }, 1500);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(done).catch(function () { fallbackCopy(url, done); });
  } else {
    fallbackCopy(url, done);
  }
}
function fallbackCopy(text, done) {
  var ta = document.createElement('textarea');
  ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(ta);
  done();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
