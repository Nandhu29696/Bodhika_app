<?php
/**
 * Admin/AddEditExamDirectory.php — Add / Edit an ExamPath Directory entry
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$id = filter_input(INPUT_GET, 'ExamDirectoryId', FILTER_VALIDATE_INT) ?: 0;

$row = [];
if ($id > 0) {
    $row = Database::fetchOne("SELECT * FROM exam_directory WHERE ExamDirectoryId = ? LIMIT 1", [$id]) ?: [];
    if (empty($row)) { header('Location: ExamDirectoryList.php'); exit; }
}

$tracks = Database::fetchAll("SELECT TrackId, Label FROM exam_directory_track WHERE Active='Y' ORDER BY SortOrder");
$priorityOptions = ['CRITICAL','HIGH','MEDIUM','OPTIONAL','FUTURE'];

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    Auth::validateCsrf();

    $examName    = trim($_POST['ExamName']    ?? '');
    $shortName   = trim($_POST['ShortName']   ?? '');
    $trackId     = (int)($_POST['TrackId']    ?? 0) ?: null;
    $details     = trim($_POST['DetailsText'] ?? '');
    $examDate    = trim($_POST['ExamDateText']    ?? '');
    $regOpen     = trim($_POST['RegOpenText']     ?? '');
    $regDeadline = trim($_POST['RegDeadlineText'] ?? '');
    $regDeadlineDate = trim($_POST['RegDeadlineDate'] ?? '');
    $url         = trim($_POST['OfficialUrl'] ?? '');
    $fee         = trim($_POST['FeeText']     ?? '');
    $outcome     = trim($_POST['Outcome']     ?? '');
    $priority    = trim($_POST['Priority']    ?? 'MEDIUM');
    $howTo       = trim($_POST['HowToPrep']   ?? '');
    $isBlr       = ($_POST['IsBangalore'] ?? 'N') === 'Y' ? 'Y' : 'N';
    $active      = trim($_POST['Active']      ?? 'Y');
    $sortOrder   = (int)($_POST['SortOrder']  ?? 0);

    if ($examName === '')  $errors[] = 'Exam / College name is required.';
    if ($shortName === '') $errors[] = 'Short name is required.';
    if (!in_array($priority, $priorityOptions, true)) $errors[] = 'Invalid priority.';
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $errors[] = 'Official URL is not valid.';
    if ($regDeadlineDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $regDeadlineDate)) {
        $errors[] = 'Registration Deadline Date must be a valid date (YYYY-MM-DD).';
    }

    if (empty($errors)) {
        $data = [
            $trackId, $examName, $shortName, $details ?: null, $examDate ?: null,
            $regOpen ?: null, $regDeadline ?: null, $regDeadlineDate ?: null,
            $url ?: null, $fee ?: null, $outcome ?: null, $priority, $howTo ?: null,
            $isBlr, $active, $sortOrder,
        ];

        if ($id > 0) {
            Database::execute(
                "UPDATE exam_directory
                    SET TrackId=?, ExamName=?, ShortName=?, DetailsText=?, ExamDateText=?,
                        RegOpenText=?, RegDeadlineText=?, RegDeadlineDate=?, OfficialUrl=?,
                        FeeText=?, Outcome=?, Priority=?, HowToPrep=?, IsBangalore=?,
                        Active=?, SortOrder=?, UpdatedBy=?
                  WHERE ExamDirectoryId=?",
                array_merge($data, [Auth::currentUserId(), $id]));
            $success = 'Directory entry updated.';
            $row = Database::fetchOne("SELECT * FROM exam_directory WHERE ExamDirectoryId=? LIMIT 1", [$id]) ?: [];
        } else {
            Database::execute(
                "INSERT INTO exam_directory
                    (TrackId, ExamName, ShortName, DetailsText, ExamDateText,
                     RegOpenText, RegDeadlineText, RegDeadlineDate, OfficialUrl,
                     FeeText, Outcome, Priority, HowToPrep, IsBangalore,
                     Active, SortOrder, CreatedBy, UpdatedBy)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge($data, [Auth::currentUserId(), Auth::currentUserId()]));
            header('Location: ExamDirectoryList.php?msg=added');
            exit;
        }
    }
}

$pageTitle = ($id > 0 ? 'Edit' : 'Add') . ' ExamPath Directory Entry';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .form-wrap { max-width:700px; margin:0 auto; }
  .field-hint { font-size:.78rem; color:#6b7280; margin-top:3px; }
  .form-row { display:flex; gap:12px; }
  .form-row .form-group { flex:1; }
</style>

<div class="card form-wrap">
  <div class="card-header">&#127942; <?php echo htmlspecialchars($pageTitle); ?></div>
  <div class="card-body">

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">

      <div class="form-group">
        <label>Exam / College Name <span style="color:#dc2626;">*</span></label>
        <textarea name="ExamName" class="form-control" rows="2" required
                  placeholder="e.g. JEE Main 2027&#10;Session 1 (2nd line is optional, shown smaller)"
        ><?php echo htmlspecialchars($row['ExamName'] ?? ''); ?></textarea>
        <div class="field-hint">A second line (press Enter) shows as a subtitle on the student card.</div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Short Name <span style="color:#dc2626;">*</span></label>
          <input type="text" name="ShortName" class="form-control" maxlength="120" required
                 value="<?php echo htmlspecialchars($row['ShortName'] ?? ''); ?>"
                 placeholder="e.g. JEE Main S1">
        </div>
        <div class="form-group">
          <label>Track / Category</label>
          <select name="TrackId" class="form-control">
            <option value="0">— None —</option>
            <?php foreach ($tracks as $t): ?>
              <option value="<?php echo (int)$t['TrackId']; ?>"
                <?php echo (($row['TrackId'] ?? 0) == $t['TrackId']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($t['Label']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label>Details (subjects / format / duration)</label>
        <input type="text" name="DetailsText" class="form-control" maxlength="500"
               value="<?php echo htmlspecialchars($row['DetailsText'] ?? ''); ?>"
               placeholder="e.g. PCM · 90 MCQs · 3 hrs · CBT">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Exam Date (text)</label>
          <input type="text" name="ExamDateText" class="form-control" maxlength="150"
                 value="<?php echo htmlspecialchars($row['ExamDateText'] ?? ''); ?>" placeholder="~Jan 21-30, 2027">
        </div>
        <div class="form-group">
          <label>Registration Opens (text)</label>
          <input type="text" name="RegOpenText" class="form-control" maxlength="100"
                 value="<?php echo htmlspecialchars($row['RegOpenText'] ?? ''); ?>" placeholder="Oct 2026">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Registration Deadline (text)</label>
          <input type="text" name="RegDeadlineText" class="form-control" maxlength="100"
                 value="<?php echo htmlspecialchars($row['RegDeadlineText'] ?? ''); ?>" placeholder="Nov 2026">
        </div>
        <div class="form-group">
          <label>Registration Deadline (actual date)</label>
          <input type="date" name="RegDeadlineDate" class="form-control"
                 value="<?php echo htmlspecialchars($row['RegDeadlineDate'] ?? ''); ?>">
          <div class="field-hint">Drives the urgency countdown and default sort order on the student page.</div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Official URL</label>
          <input type="text" name="OfficialUrl" class="form-control" maxlength="500"
                 value="<?php echo htmlspecialchars($row['OfficialUrl'] ?? ''); ?>" placeholder="https://…">
        </div>
        <div class="form-group">
          <label>Fee</label>
          <input type="text" name="FeeText" class="form-control" maxlength="100"
                 value="<?php echo htmlspecialchars($row['FeeText'] ?? ''); ?>" placeholder="₹1,000 or Free">
        </div>
      </div>

      <div class="form-group">
        <label>Outcome (colleges / what it leads to)</label>
        <textarea name="Outcome" class="form-control" rows="3"
                  placeholder="e.g. NITs · IIITs · GFTIs (31,000+ seats)…"><?php echo htmlspecialchars($row['Outcome'] ?? ''); ?></textarea>
      </div>

      <div class="form-group">
        <label>How to Prepare</label>
        <textarea name="HowToPrep" class="form-control" rows="3"
                  placeholder="Short prep-strategy note for students"><?php echo htmlspecialchars($row['HowToPrep'] ?? ''); ?></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Priority</label>
          <select name="Priority" class="form-control">
            <?php $curPr = $row['Priority'] ?? 'MEDIUM'; foreach ($priorityOptions as $p): ?>
              <option value="<?php echo $p; ?>" <?php echo $curPr===$p?'selected':''; ?>><?php echo $p; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Bangalore Relevant</label>
          <select name="IsBangalore" class="form-control">
            <option value="N" <?php echo (($row['IsBangalore'] ?? 'N')==='N')?'selected':''; ?>>No</option>
            <option value="Y" <?php echo (($row['IsBangalore'] ?? '')==='Y')?'selected':''; ?>>Yes — Bangalore Only filter</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Sort Order</label>
          <input type="number" name="SortOrder" class="form-control" min="0" max="9999"
                 value="<?php echo (int)($row['SortOrder'] ?? 0); ?>">
          <div class="field-hint">Lower number = appears first when deadlines tie.</div>
        </div>
        <div class="form-group">
          <label>Visible to Students</label>
          <select name="Active" class="form-control">
            <option value="Y" <?php echo (($row['Active'] ?? 'Y')==='Y')?'selected':''; ?>>Yes (Active)</option>
            <option value="N" <?php echo (($row['Active'] ?? '')==='N')?'selected':''; ?>>No (Hidden)</option>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:24px;">
        <button type="submit" name="save" class="btn btn-primary">&#128190; Save</button>
        <a href="ExamDirectoryList.php" class="btn btn-secondary">&#8592; Back</a>
      </div>

    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
