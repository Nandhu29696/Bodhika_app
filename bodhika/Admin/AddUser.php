<?php
/**
 * Admin/AddUser.php — Admin: create a new user account.
 *
 * Full rewrite. The previous version predated the PDO migration: its Save
 * handler called mysql_query()/mysql_num_rows()/mysql_fetch_array(), all of
 * which were removed in PHP 7.0 (this server runs PHP 8.3), so clicking Save
 * would fatal-error every time. It also stored the password in plain text,
 * and its GET-side form used the old Admin/Includes/Top.php header, which
 * links a style.css that no longer exists — hence the completely unstyled
 * page. This version uses Database:: (PDO), password_hash(), CSRF
 * protection, and the current includes/header.php layout, mirroring the
 * conventions in Lib/Registration.php (self-registration) and
 * Admin/EditUser.php (admin edit).
 *
 * Creates a logininfo + userinfo row and, unlike self-registration, is
 * active immediately — no approval step, since an admin is doing this
 * directly (same philosophy as EditUser.php's inline edits).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }
Auth::validateCsrf();

$success = '';
$errors  = [];

/* ── Dropdown data ────────────────────────────────────────────────────── */
$institutes = Database::fetchAll(
    "SELECT InstituteId, InstituteName FROM institutes ORDER BY InstituteName", []);

const ROLE_OPTIONS = [
    'STDNT'    => 'Student',
    'TEACH'    => 'Teacher',
    'INSTADMIN'=> 'Institute Admin',
    'ADMIN'    => 'Admin',
];

/* ── Form field values (repopulated on validation error) ─────────────── */
$f = [
    'FstName'     => trim($_POST['FstName']     ?? ''),
    'LstName'     => trim($_POST['LstName']     ?? ''),
    'Gender'      => $_POST['Gender']           ?? 'Male',
    'LoginName'   => trim($_POST['LoginName']   ?? ''),
    'EMail'       => trim($_POST['EMail']       ?? ''),
    'Mobile'      => trim($_POST['Mobile']      ?? ''),
    'Address'     => trim($_POST['Address']     ?? ''),
    'Note'        => trim($_POST['Note']        ?? ''),
    'Role'        => $_POST['Role']             ?? 'STDNT',
    'InstituteId' => filter_input(INPUT_POST, 'InstituteId', FILTER_VALIDATE_INT) ?: null,
];

/* ── Handle POST ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {

    $password = (string)($_POST['Password']        ?? '');
    $confirm  = (string)($_POST['ConfirmPassword']  ?? '');

    if ($f['FstName'] === '')   $errors[] = 'First name is required.';
    if ($f['LstName'] === '')   $errors[] = 'Last name is required.';
    if ($f['LoginName'] === '') $errors[] = 'Login name is required.';
    elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $f['LoginName']))
        $errors[] = 'Login name: 3-50 characters, letters/digits/_ . - only.';
    if ($f['EMail'] === '') $errors[] = 'Email is required.';
    elseif (!filter_var($f['EMail'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (!array_key_exists($f['Role'], ROLE_OPTIONS)) $errors[] = 'Invalid role selected.';
    if ($f['Role'] === 'INSTADMIN' && !$f['InstituteId'])
        $errors[] = 'Institute Admin accounts must have an Institute selected — they can only manage students in that institute.';
    if ($password === '') $errors[] = 'Password is required.';
    elseif (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    elseif ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors) && Database::fetchOne(
            "SELECT LoginInfoId FROM logininfo WHERE LoginName = ? LIMIT 1", [$f['LoginName']])) {
        $errors[] = 'That login name is already taken — please choose another.';
    }
    if (empty($errors)) {
        $emailUsed = Database::fetchOne("SELECT LoginInfoId FROM logininfo WHERE Email = ? LIMIT 1", [$f['EMail']]);
        if (!$emailUsed) {
            try { $emailUsed = Database::fetchOne("SELECT UserInfoId FROM userinfo WHERE EMail = ? LIMIT 1", [$f['EMail']]); }
            catch (Exception $e) {}
        }
        if ($emailUsed) $errors[] = 'An account with that email already exists.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            Database::beginTransaction();

            /* logininfo — graceful fallback if InstituteId column isn't present */
            try {
                Database::execute(
                    "INSERT INTO logininfo (LoginName, Password, Role, Email, Active, InstituteId)
                     VALUES (?, ?, ?, ?, 'Y', ?)",
                    [$f['LoginName'], $hash, $f['Role'], $f['EMail'], $f['InstituteId']]
                );
            } catch (Exception $e) {
                Database::execute(
                    "INSERT INTO logininfo (LoginName, Password, Role, Email, Active)
                     VALUES (?, ?, ?, ?, 'Y')",
                    [$f['LoginName'], $hash, $f['Role'], $f['EMail']]
                );
            }

            /* userinfo — same multi-tier fallback pattern as Lib/Registration.php */
            try {
                Database::execute(
                    "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, Note, InstituteId)
                     VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?)",
                    [$f['LoginName'], $f['FstName'], $f['LstName'], $f['Gender'], $f['EMail'],
                     $f['Mobile'], $f['Address'], $f['Note'], $f['InstituteId']]
                );
            } catch (Exception $e) {
                Database::execute(
                    "INSERT INTO userinfo (LoginName, FstName, MiddleName, LstName, Gender, EMail, Mobile, Address, Note)
                     VALUES (?, ?, '', ?, ?, ?, ?, ?, ?)",
                    [$f['LoginName'], $f['FstName'], $f['LstName'], $f['Gender'], $f['EMail'],
                     $f['Mobile'], $f['Address'], $f['Note']]
                );
            }

            Database::commit();
            header('Location: AdminUsers.php?added=1');
            exit;
        } catch (Exception $ex) {
            Database::rollBack();
            error_log('Admin/AddUser.php: create failed: ' . $ex->getMessage());
            $errors[] = 'Could not create the user — please try again.';
        }
    }
}

$pageTitle = 'Add User';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrap">

  <div style="font-size:.82rem;color:var(--clr-text-muted);margin-bottom:16px;">
    <a href="AdminUsers.php" style="color:var(--clr-primary);text-decoration:none;">Users &amp; Students</a>
    › Add User
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger" style="margin-bottom:16px;">
      <?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?>
    </div>
  <?php endif; ?>

  <div class="card" style="max-width:640px;">
    <div class="card-header" style="font-weight:700;">&#10133; Registering User</div>
    <div class="card-body">
      <form method="post" action="AddUser.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="save">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label class="form-label">First Name <span style="color:#dc2626">*</span></label>
            <input type="text" name="FstName" class="form-control" required
                   value="<?= htmlspecialchars($f['FstName']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Last Name <span style="color:#dc2626">*</span></label>
            <input type="text" name="LstName" class="form-control" required
                   value="<?= htmlspecialchars($f['LstName']) ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Gender</label>
          <label style="font-weight:normal;margin-right:16px;">
            <input type="radio" name="Gender" value="Male" <?= $f['Gender'] === 'Male' ? 'checked' : '' ?>> Male
          </label>
          <label style="font-weight:normal;">
            <input type="radio" name="Gender" value="Female" <?= $f['Gender'] === 'Female' ? 'checked' : '' ?>> Female
          </label>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label class="form-label">Login Name <span style="color:#dc2626">*</span></label>
            <input type="text" name="LoginName" class="form-control" required
                   value="<?= htmlspecialchars($f['LoginName']) ?>" placeholder="e.g. jdoe">
          </div>
          <div class="form-group">
            <label class="form-label">Role <span style="color:#dc2626">*</span></label>
            <select name="Role" class="form-control">
              <?php foreach (ROLE_OPTIONS as $val => $label): ?>
                <option value="<?= $val ?>" <?= $f['Role'] === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label class="form-label">Password <span style="color:#dc2626">*</span></label>
            <input type="password" name="Password" class="form-control" required autocomplete="new-password">
            <small style="color:var(--clr-text-muted);">At least 8 characters.</small>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password <span style="color:#dc2626">*</span></label>
            <input type="password" name="ConfirmPassword" class="form-control" required autocomplete="new-password">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Email <span style="color:#dc2626">*</span></label>
          <input type="email" name="EMail" class="form-control" required
                 value="<?= htmlspecialchars($f['EMail']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Mobile</label>
          <input type="text" name="Mobile" class="form-control"
                 value="<?= htmlspecialchars($f['Mobile']) ?>" placeholder="e.g. 9876543210">
        </div>

        <div class="form-group">
          <label class="form-label">Institute</label>
          <select name="InstituteId" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($institutes as $inst): ?>
              <option value="<?= (int)$inst['InstituteId'] ?>"
                <?= $f['InstituteId'] === (int)$inst['InstituteId'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($inst['InstituteName']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--clr-text-muted);">Required for Institute Admin — they only see students in this institute.</small>
        </div>

        <div class="form-group">
          <label class="form-label">Address</label>
          <textarea name="Address" class="form-control" rows="3"><?= htmlspecialchars($f['Address']) ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Note</label>
          <textarea name="Note" class="form-control" rows="2"><?= htmlspecialchars($f['Note']) ?></textarea>
        </div>

        <div style="display:flex;gap:10px;margin-top:6px;">
          <button type="submit" class="btn">Save</button>
          <a href="AdminUsers.php" class="btn btn-outline">Cancel</a>
        </div>
      </form>
    </div>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
