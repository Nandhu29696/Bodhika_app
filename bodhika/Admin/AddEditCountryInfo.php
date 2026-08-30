<?php
/**
 * Admin/AddEditCountryInfo.php — Add / edit a country reference row
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Countries.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$countryId = filter_input(INPUT_GET, 'CountryId', FILTER_VALIDATE_INT) ?: 0;

$row = [];
if ($countryId > 0) {
    $row = Database::fetchOne(
        "SELECT * FROM countryinfo WHERE CountryId = ? LIMIT 1", [$countryId]) ?: [];
    if (empty($row)) { header('Location: CountryInfo.php'); exit; }
}

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    Auth::validateCsrf();

    $countryCode  = strtoupper(trim($_POST['CountryCode'] ?? ''));
    $countryName  = trim($_POST['CountryName'] ?? '');
    $sortOrder    = (int)($_POST['SortOrder']  ?? 100);
    $active       = trim($_POST['Active']      ?? 'Y');

    if (!preg_match('/^[A-Z]{2}$/', $countryCode)) $errors[] = 'Country code must be exactly 2 letters (ISO 3166-1 alpha-2, e.g. IN, US).';
    if ($countryName === '') $errors[] = 'Country name is required.';
    if (!in_array($active, ['Y', 'N'], true)) $active = 'Y';

    // Uniqueness pre-check (friendlier than waiting for the DB's unique-index error)
    if (empty($errors)) {
        $dupSql = "SELECT CountryId FROM countryinfo WHERE CountryCode = ?";
        $dupParams = [$countryCode];
        if ($countryId > 0) { $dupSql .= " AND CountryId <> ?"; $dupParams[] = $countryId; }
        if (Database::fetchOne($dupSql, $dupParams)) {
            $errors[] = 'A country with that code already exists.';
        }
    }

    if (empty($errors)) {
        if ($countryId > 0) {
            Database::execute(
                "UPDATE countryinfo
                    SET CountryCode=?, CountryName=?, SortOrder=?, Active=?
                  WHERE CountryId=?",
                [$countryCode, $countryName, $sortOrder, $active, $countryId]);
            $success = 'Country updated.';
            $row = Database::fetchOne(
                "SELECT * FROM countryinfo WHERE CountryId=? LIMIT 1", [$countryId]) ?: [];
        } else {
            Database::execute(
                "INSERT INTO countryinfo (CountryCode, CountryName, SortOrder, Active)
                 VALUES (?, ?, ?, ?)",
                [$countryCode, $countryName, $sortOrder, $active]);
            header('Location: CountryInfo.php?msg=added');
            exit;
        }
    }
}

$pageTitle = ($countryId > 0 ? 'Edit' : 'Add') . ' Country';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .form-wrap { max-width:560px; margin:0 auto; }
  .field-hint { font-size:.78rem; color:#6b7280; margin-top:3px; }
</style>

<div class="card form-wrap">
  <div class="card-header">&#127760; <?php echo htmlspecialchars($pageTitle); ?></div>
  <div class="card-body">

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($row['CountryCode'])): ?>
      <div style="margin-bottom:14px;"><?php echo Countries::flagImg($row['CountryCode'], $row['CountryName'] ?? '', 40); ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">

      <div class="form-group">
        <label>Country Code (ISO 3166-1 alpha-2) <span style="color:#dc2626;">*</span></label>
        <input type="text" name="CountryCode" class="form-control" maxlength="2" style="max-width:120px;text-transform:uppercase;" required
               value="<?php echo htmlspecialchars($row['CountryCode'] ?? ''); ?>"
               placeholder="e.g. IN">
        <div class="field-hint">Exactly 2 letters. Used to look up the flag icon — <a href="https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2" target="_blank" rel="noopener">full code list</a>.</div>
      </div>

      <div class="form-group">
        <label>Country Name <span style="color:#dc2626;">*</span></label>
        <input type="text" name="CountryName" class="form-control" maxlength="100" required
               value="<?php echo htmlspecialchars($row['CountryName'] ?? ''); ?>"
               placeholder="e.g. India">
      </div>

      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="SortOrder" class="form-control" min="0" max="999" style="max-width:120px;"
               value="<?php echo (int)($row['SortOrder'] ?? 100); ?>">
        <div class="field-hint">Lower number appears first in the country picker/filter. The pre-seeded common countries use 1-8.</div>
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="Active" class="form-control" style="max-width:160px;">
          <option value="Y" <?php echo (($row['Active'] ?? 'Y')==='Y')?'selected':''; ?>>Active</option>
          <option value="N" <?php echo (($row['Active'] ?? '')==='N')?'selected':''; ?>>Inactive</option>
        </select>
        <div class="field-hint">Inactive countries are hidden from the Add/Edit Exam country picker and the exam list filter.</div>
      </div>

      <div style="display:flex;gap:10px;margin-top:24px;">
        <button type="submit" name="save" class="btn btn-primary">&#128190; Save</button>
        <a href="CountryInfo.php" class="btn btn-secondary">&#8592; Back</a>
      </div>

    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
