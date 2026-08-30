<?php
/**
 * Admin/StudentGroupEdit.php — Add / edit a student group's basic info
 * (name, description, blanket discount %, active flag), plus its shareable
 * self-registration code (migration_v68 — student_groups.StudentGroupCode).
 * Member and exam assignment management live in their own pages, linked
 * from the footer once the group exists.
 *
 * The registration code lets an admin hand students a single URL
 * (auth/register-group.php?code=XXXXXXXX) instead of registering everyone
 * one-by-one and then bulk-adding them here — a student who signs up
 * through that link is added to this group automatically. See
 * Lib/StudentGroup.php::findByCode()/generateCode()/enrollSelfByCode().
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/StudentGroup.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$gid = filter_input(INPUT_GET, 'gid', FILTER_VALIDATE_INT) ?: 0;

// Guarded: an install that hasn't run migration_v68.sql yet simply doesn't
// get the registration-code UI — same graceful-degradation convention used
// throughout this codebase (see Database::hasColumn()'s docblock).
$hasCodeColumn = Database::hasColumn('student_groups', 'StudentGroupCode');

$row = ['GroupName' => '', 'Description' => '', 'DiscountPct' => 0, 'IsActive' => 'Y', 'StudentGroupCode' => null];
if ($gid > 0) {
    $found = Database::fetchOne("SELECT * FROM student_groups WHERE StudentGroupId = ? LIMIT 1", [$gid]) ?: [];
    if (empty($found)) { header('Location: StudentGroups.php'); exit; }
    $row = $found;
}

$errors  = [];
$success = '';

/* ── Regenerate / generate the registration code (its own tiny POST action,
   separate from the main Save form so clicking it doesn't require re-typing
   or accidentally re-validating the rest of the group form) ──────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_code']) && $gid > 0 && $hasCodeColumn) {
    Auth::validateCsrf();
    $newCode = StudentGroup::generateCode();
    Database::execute("UPDATE student_groups SET StudentGroupCode = ? WHERE StudentGroupId = ?", [$newCode, $gid]);
    header('Location: StudentGroupEdit.php?gid=' . $gid . '&coderegen=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    Auth::validateCsrf();

    $groupName   = trim($_POST['GroupName'] ?? '');
    $description = trim($_POST['Description'] ?? '');
    $discountPct = min(100.0, max(0.0, (float)($_POST['DiscountPct'] ?? 0)));
    $isActive    = ($_POST['IsActive'] ?? 'Y') === 'Y' ? 'Y' : 'N';

    if ($groupName === '') $errors[] = 'Group name is required.';

    if (empty($errors)) {
        $dupSql = "SELECT StudentGroupId FROM student_groups WHERE GroupName = ?";
        $dupParams = [$groupName];
        if ($gid > 0) { $dupSql .= " AND StudentGroupId <> ?"; $dupParams[] = $gid; }
        if (Database::fetchOne($dupSql, $dupParams)) {
            $errors[] = 'A group with that exact name already exists.';
        }
    }

    if (empty($errors)) {
        if ($gid > 0) {
            Database::execute(
                "UPDATE student_groups SET GroupName=?, Description=?, DiscountPct=?, IsActive=? WHERE StudentGroupId=?",
                [$groupName, $description, $discountPct, $isActive, $gid]);
            $success = 'Group updated.';
            $row = Database::fetchOne("SELECT * FROM student_groups WHERE StudentGroupId=? LIMIT 1", [$gid]) ?: $row;
        } else {
            // New group: generate its registration code up front so the
            // shareable link is available immediately after creation,
            // without a separate "now click Generate" step.
            $newCode = $hasCodeColumn ? StudentGroup::generateCode() : null;
            if ($hasCodeColumn) {
                Database::execute(
                    "INSERT INTO student_groups (GroupName, Description, DiscountPct, IsActive, CreatedBy, StudentGroupCode)
                     VALUES (?,?,?,?,?,?)",
                    [$groupName, $description, $discountPct, $isActive, Auth::currentUser() ?: 'admin', $newCode]);
            } else {
                Database::execute(
                    "INSERT INTO student_groups (GroupName, Description, DiscountPct, IsActive, CreatedBy)
                     VALUES (?,?,?,?,?)",
                    [$groupName, $description, $discountPct, $isActive, Auth::currentUser() ?: 'admin']);
            }
            $gid = (int)Database::lastInsertId();
            header('Location: StudentGroupEdit.php?gid=' . $gid . '&created=1'); exit;
        }
    } else {
        $row = ['GroupName' => $groupName, 'Description' => $description, 'DiscountPct' => $discountPct, 'IsActive' => $isActive, 'StudentGroupCode' => $row['StudentGroupCode'] ?? null];
    }
}

// Full shareable URL for the registration code, built the same way
// register.php already builds its own post-registration login link
// (scheme + HTTP_HOST, no trailing assumptions about the install path).
function groupRegistrationUrl(string $code): string {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Admin/StudentGroupEdit.php -> ../auth/register-group.php
    $base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $host . $base . '/auth/register-group.php?code=' . rawurlencode($code);
}

$pageTitle = ($gid > 0 ? 'Edit' : 'New') . ' Student Group';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .form-wrap { max-width:600px; margin:0 auto; }
  .field-hint { font-size:.78rem; color:#6b7280; margin-top:3px; }
  .reglink-box { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:14px 16px; margin-top:24px; }
  .reglink-box h3 { margin:0 0 6px; font-size:.9rem; color:#065f46; }
  .reglink-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .reglink-row input[type=text] { flex:1; min-width:220px; padding:7px 10px; border:1px solid #a7f3d0; border-radius:4px; font-size:.82rem; background:#fff; color:#065f46; font-family:monospace; }
  .reglink-copied { font-size:.78rem; color:#059669; font-weight:700; margin-left:6px; display:none; }
</style>

<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;max-width:600px;margin-left:auto;margin-right:auto;">
  <a href="AdminUsers.php?tab=students" style="color:#3182ce;text-decoration:none;">&#128101; Users</a>
  <span style="margin:0 6px;">›</span>
  <a href="StudentGroups.php" style="color:#3182ce;text-decoration:none;">Student Groups</a>
  <span style="margin:0 6px;">›</span>
  <span><?php echo $gid > 0 ? 'Edit' : 'New'; ?></span>
</nav>

<div class="card form-wrap">
  <div class="card-header">&#128101; <?php echo $gid > 0 ? 'Edit' : 'New'; ?> Student Group</div>
  <div class="card-body">

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if (filter_input(INPUT_GET, 'created', FILTER_VALIDATE_INT)): ?>
      <div class="alert alert-success">
        &#10003; Group created. Share the registration link below so students can join it themselves, or add members and
        (optionally) assign exams using the links below.
      </div>
    <?php endif; ?>
    <?php if (filter_input(INPUT_GET, 'coderegen', FILTER_VALIDATE_INT)): ?>
      <div class="alert alert-success">
        &#10003; A new registration code was generated. The old link will no longer work.
      </div>
    <?php endif; ?>
    <?php if (!$hasCodeColumn): ?>
      <div class="alert alert-warning">
        Self-registration links aren't available yet — run <code>migrations/migration_v68.sql</code> against the
        database to enable them.
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">

      <div class="form-group">
        <label>Group Name <span style="color:#dc2626;">*</span></label>
        <input type="text" name="GroupName" class="form-control" maxlength="150" required
               value="<?php echo htmlspecialchars($row['GroupName'] ?? ''); ?>"
               placeholder="e.g. JEE 2026 Batch A">
      </div>

      <div class="form-group">
        <label>Description <span style="color:#6b7280;font-size:.78rem;">(optional)</span></label>
        <input type="text" name="Description" class="form-control" maxlength="255"
               value="<?php echo htmlspecialchars($row['Description'] ?? ''); ?>"
               placeholder="e.g. Morning batch, started Jan 2026">
      </div>

      <div class="form-group">
        <label>Discount (%)</label>
        <div style="display:flex;align-items:center;gap:8px;">
          <input type="number" name="DiscountPct" class="form-control" min="0" max="100" step="0.01"
                 style="max-width:140px;"
                 value="<?php echo rtrim(rtrim(number_format((float)($row['DiscountPct'] ?? 0), 2), '0'), '.') ?: '0'; ?>">
          <span>%</span>
        </div>
        <div class="field-hint">
          Applied automatically (no coupon code needed) to any exam a member enrolls in and pays for.
          0 = no discount. If a member also qualifies for an institute discount, the institute discount
          wins (it's a negotiated per-course rate).
        </div>
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="IsActive" class="form-control" style="max-width:160px;">
          <option value="Y" <?php echo (($row['IsActive'] ?? 'Y') === 'Y') ? 'selected' : ''; ?>>Active</option>
          <option value="N" <?php echo (($row['IsActive'] ?? '') === 'N') ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <div class="field-hint">
          Inactive groups stop granting their discount, stop showing "Recommended" badges, and stop accepting
          new self-registrations via the group link — but members and exam assignments are kept (not deleted)
          so you can reactivate later.
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:24px;flex-wrap:wrap;">
        <button type="submit" name="save" class="btn btn-primary">&#128190; Save</button>
        <?php if ($gid > 0): ?>
          <a href="StudentGroupMembers.php?gid=<?php echo $gid; ?>" class="btn btn-secondary">&#128101; Manage Members</a>
          <a href="StudentGroupExams.php?gid=<?php echo $gid; ?>" class="btn btn-secondary">&#128220; Assign Exams</a>
        <?php endif; ?>
        <a href="StudentGroups.php" class="btn btn-secondary">&#8592; Back</a>
      </div>
    </form>

    <?php if ($gid > 0 && $hasCodeColumn): ?>
    <div class="reglink-box">
      <h3>&#128279; Self-Registration Link</h3>
      <?php if (!empty($row['StudentGroupCode'])): ?>
        <p style="font-size:.82rem;color:#065f46;margin:0 0 8px;">
          Share this link with students — anyone who registers through it is added to
          "<?php echo htmlspecialchars($row['GroupName']); ?>" automatically, and immediately picks up any exams
          already recommended or assigned to this group.
        </p>
        <div class="reglink-row">
          <input type="text" id="regLinkInput" readonly
                 value="<?php echo htmlspecialchars(groupRegistrationUrl($row['StudentGroupCode'])); ?>"
                 onclick="this.select();">
          <button type="button" class="btn btn-secondary btn-sm" onclick="copyRegLink()">&#128203; Copy</button>
          <span id="regLinkCopied" class="reglink-copied">Copied!</span>
        </div>
        <form method="post" style="margin-top:10px;" onsubmit="return confirm('Generate a new link? The current link will stop working immediately.');">
          <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
          <input type="hidden" name="regenerate_code" value="1">
          <button type="submit" class="btn btn-secondary btn-sm">&#8635; Regenerate Link</button>
        </form>
      <?php else: ?>
        <p style="font-size:.82rem;color:#065f46;margin:0 0 10px;">
          No registration link yet for this group.
        </p>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
          <input type="hidden" name="regenerate_code" value="1">
          <button type="submit" class="btn btn-primary btn-sm">&#10010; Generate Link</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
function copyRegLink() {
  var input = document.getElementById('regLinkInput');
  var copied = document.getElementById('regLinkCopied');
  input.select();
  input.setSelectionRange(0, 99999);
  var done = function () {
    copied.style.display = 'inline';
    setTimeout(function () { copied.style.display = 'none'; }, 1800);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(input.value).then(done).catch(function () {
      document.execCommand('copy'); done();
    });
  } else {
    document.execCommand('copy'); done();
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
