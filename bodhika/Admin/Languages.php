<?php
/**
 * Admin/Languages.php — CRUD for the admin-managed `languages` table
 * (migration_v47.sql).
 *
 * These are the languages an exam can be translated into via
 * Admin/TranslateExam.php, and the choices offered on exam/search.php's
 * language filter. Simple single-table CRUD — no sub-resources, so this is
 * intentionally a single flat list+form on one page (unlike
 * ManageInstitutes.php's multi-view action set).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$flash     = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $code       = strtolower(trim($_POST['LanguageCode'] ?? ''));
        $name       = trim($_POST['LanguageName'] ?? '');
        $native     = trim($_POST['NativeName']   ?? '');
        $sortOrder  = (int)($_POST['SortOrder']   ?? 0);
        $origCode   = trim($_POST['OrigCode'] ?? '');

        $errors = [];
        if ($code === '' || !preg_match('/^[a-z\-]{2,10}$/', $code)) {
            $errors[] = 'Language code must be 2-10 lowercase letters (e.g. "en", "hi", "mr").';
        }
        if ($name === '') {
            $errors[] = 'Language name is required.';
        }

        if (!$errors && $origCode === '') {
            // Creating a new language — code must not already exist.
            $exists = Database::fetchOne(
                "SELECT LanguageCode FROM languages WHERE LanguageCode = ? LIMIT 1", [$code]);
            if ($exists) $errors[] = "Language code \"$code\" already exists.";
        }

        if ($errors) {
            $flash = implode(' ', $errors);
            $flashType = 'danger';
        } elseif ($origCode !== '') {
            // Editing — LanguageCode is the primary key and stays fixed;
            // only name/native-name/sort order are editable.
            Database::execute(
                "UPDATE languages SET LanguageName=?, NativeName=?, SortOrder=? WHERE LanguageCode=?",
                [$name, $native !== '' ? $native : null, $sortOrder, $origCode]);
            header('Location: Languages.php?flash=saved'); exit;
        } else {
            Database::execute(
                "INSERT INTO languages (LanguageCode, LanguageName, NativeName, SortOrder, IsActive)
                 VALUES (?,?,?,?, 'Y')",
                [$code, $name, $native !== '' ? $native : null, $sortOrder]);
            header('Location: Languages.php?flash=saved'); exit;
        }
    }

    if ($action === 'toggle') {
        $code = trim($_POST['LanguageCode'] ?? '');
        if ($code === 'en') {
            header('Location: Languages.php?flash=cannot_disable_en&flashType=danger'); exit;
        }
        $cur = Database::fetchOne("SELECT IsActive FROM languages WHERE LanguageCode=? LIMIT 1", [$code]);
        if ($cur) {
            $new = $cur['IsActive'] === 'Y' ? 'N' : 'Y';
            Database::execute("UPDATE languages SET IsActive=? WHERE LanguageCode=?", [$new, $code]);
        }
        header('Location: Languages.php?flash=toggled'); exit;
    }
}

if (isset($_GET['flash'])) {
    $map = [
        'saved'   => 'Language saved.',
        'toggled' => 'Language status updated.',
        'cannot_disable_en' => 'English is the default source language and can\'t be disabled.',
    ];
    $flash = $map[$_GET['flash']] ?? '';
    $flashType = ($_GET['flash'] === 'cannot_disable_en') ? 'danger' : 'success';
}

/* Editing an existing language? */
$editRow = null;
$editCode = trim($_GET['edit'] ?? '');
if ($editCode !== '') {
    $editRow = Database::fetchOne("SELECT * FROM languages WHERE LanguageCode=? LIMIT 1", [$editCode]);
}

/* Usage count per language — how many exams currently use it (informational,
   shown so an admin doesn't blindly disable a language exams depend on). */
$usageMap = [];
try {
    $rows = Database::fetchAll(
        "SELECT Language, COUNT(*) AS cnt FROM examinfo
          WHERE COALESCE(IsDeleted,'N') = 'N' GROUP BY Language");
    foreach ($rows as $r) { $usageMap[$r['Language']] = (int)$r['cnt']; }
} catch (Exception $e) { /* migration_v47 not yet run */ }

$languages = Database::fetchAll("SELECT * FROM languages ORDER BY SortOrder, LanguageName");

$pageTitle = 'Manage Languages';
include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:640px;margin:0 auto 20px;">
  <div class="card-header">
    <?php echo $editRow ? 'Edit Language' : 'Add Language'; ?>
  </div>
  <div class="card-body">
    <?php if ($flash): ?>
      <div class="alert alert-<?php echo $flashType === 'danger' ? 'danger' : 'success'; ?>">
        <?php echo htmlspecialchars($flash); ?>
      </div>
    <?php endif; ?>
    <form method="post" action="Languages.php">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="OrigCode" value="<?php echo htmlspecialchars($editRow['LanguageCode'] ?? ''); ?>">

      <div class="form-group">
        <label for="LanguageCode">Language Code</label>
        <input type="text" id="LanguageCode" name="LanguageCode" class="form-control"
               placeholder="e.g. hi, mr, ta" maxlength="10"
               value="<?php echo htmlspecialchars($editRow['LanguageCode'] ?? ''); ?>"
               <?php echo $editRow ? 'readonly style="background:#f1f5f9;"' : ''; ?> required>
        <small style="color:#718096;">Short code used internally (2-10 lowercase letters). Can't be changed once created.</small>
      </div>

      <div class="form-group">
        <label for="LanguageName">Language Name (English)</label>
        <input type="text" id="LanguageName" name="LanguageName" class="form-control"
               placeholder="e.g. Hindi" maxlength="60"
               value="<?php echo htmlspecialchars($editRow['LanguageName'] ?? ''); ?>" required>
      </div>

      <div class="form-group">
        <label for="NativeName">Native Name <span style="color:#a0aec0;">(optional)</span></label>
        <input type="text" id="NativeName" name="NativeName" class="form-control"
               placeholder="e.g. हिन्दी" maxlength="60"
               value="<?php echo htmlspecialchars($editRow['NativeName'] ?? ''); ?>">
      </div>

      <div class="form-group">
        <label for="SortOrder">Sort Order</label>
        <input type="number" id="SortOrder" name="SortOrder" class="form-control" style="max-width:120px;"
               value="<?php echo (int)($editRow['SortOrder'] ?? 99); ?>">
      </div>

      <div class="btn-group">
        <button type="submit" class="btn btn-primary">&#128190; Save</button>
        <?php if ($editRow): ?>
          <a href="Languages.php" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Languages</div>
  <div class="card-body" style="padding:0;">
    <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Code</th>
          <th>Name</th>
          <th>Native Name</th>
          <th class="text-center">Exams Using</th>
          <th class="text-center">Status</th>
          <th style="width:200px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($languages as $i => $l): ?>
        <tr class="<?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">
          <td><code><?php echo htmlspecialchars($l['LanguageCode']); ?></code></td>
          <td><?php echo htmlspecialchars($l['LanguageName']); ?></td>
          <td><?php echo htmlspecialchars($l['NativeName'] ?? '—'); ?></td>
          <td class="text-center"><?php echo (int)($usageMap[$l['LanguageCode']] ?? 0); ?></td>
          <td class="text-center">
            <?php if ($l['IsActive'] === 'Y'): ?>
              <span class="assign-badge assign-completed">&#10004; Active</span>
            <?php else: ?>
              <span class="assign-badge" style="background:#fee2e2;color:#b91c1c;">&#10005; Inactive</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="btn-group nowrap">
              <a href="Languages.php?edit=<?php echo urlencode($l['LanguageCode']); ?>"
                 class="btn btn-warning btn-xs">&#9881; Edit</a>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="LanguageCode" value="<?php echo htmlspecialchars($l['LanguageCode']); ?>">
                <button type="submit" class="btn btn-xs"
                        style="background:<?php echo $l['IsActive'] === 'Y' ? '#dc2626' : '#16a34a'; ?>;color:#fff;"
                        <?php echo $l['LanguageCode'] === 'en' ? 'disabled title="English can\'t be disabled"' : ''; ?>>
                  <?php echo $l['IsActive'] === 'Y' ? '&#10005; Disable' : '&#10004; Enable'; ?>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
