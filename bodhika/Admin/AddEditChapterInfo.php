<?php
/**
 * Admin/AddEditChapterInfo.php — Add / edit a syllabus chapter
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$chapterId = filter_input(INPUT_GET, 'ChapterInfoId', FILTER_VALIDATE_INT) ?: 0;

$row = [];
if ($chapterId > 0) {
    $row = Database::fetchOne(
        "SELECT * FROM chapterinfo WHERE ChapterInfoId = ? LIMIT 1", [$chapterId]) ?: [];
    if (empty($row)) { header('Location: ChapterInfo.php'); exit; }
}

$subjects = Database::fetchAll(
    "SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    Auth::validateCsrf();

    $subjectId    = (int)($_POST['SubjectInfoId'] ?? 0);
    $chapterName  = trim($_POST['ChapterName']    ?? '');
    $chapterOrder = (int)($_POST['ChapterOrder']  ?? 0);
    $active       = trim($_POST['Active']         ?? 'Y');

    if ($subjectId <= 0) $errors[] = 'Subject is required.';
    if ($chapterName === '') $errors[] = 'Chapter name is required.';
    if (!in_array($active, ['Y', 'N'], true)) $active = 'Y';

    // Uniqueness pre-check (friendlier than waiting for the DB's unique-index error)
    if (empty($errors)) {
        $dupSql = "SELECT ChapterInfoId FROM chapterinfo WHERE SubjectInfoId = ? AND ChapterName = ?";
        $dupParams = [$subjectId, $chapterName];
        if ($chapterId > 0) { $dupSql .= " AND ChapterInfoId <> ?"; $dupParams[] = $chapterId; }
        if (Database::fetchOne($dupSql, $dupParams)) {
            $errors[] = 'This subject already has a chapter with that exact name.';
        }
    }

    if (empty($errors)) {
        if ($chapterId > 0) {
            Database::execute(
                "UPDATE chapterinfo
                    SET SubjectInfoId=?, ChapterName=?, ChapterOrder=?, Active=?
                  WHERE ChapterInfoId=?",
                [$subjectId, $chapterName, $chapterOrder, $active, $chapterId]);
            $success = 'Chapter updated.';
            $row = Database::fetchOne(
                "SELECT * FROM chapterinfo WHERE ChapterInfoId=? LIMIT 1", [$chapterId]) ?: [];
        } else {
            Database::execute(
                "INSERT INTO chapterinfo (SubjectInfoId, ChapterName, ChapterOrder, Active)
                 VALUES (?, ?, ?, ?)",
                [$subjectId, $chapterName, $chapterOrder, $active]);
            header('Location: ChapterInfo.php?msg=added');
            exit;
        }
    }
}

$pageTitle = ($chapterId > 0 ? 'Edit' : 'Add') . ' Chapter';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .form-wrap { max-width:560px; margin:0 auto; }
  .field-hint { font-size:.78rem; color:#6b7280; margin-top:3px; }
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
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">

      <div class="form-group">
        <label>Subject <span style="color:#dc2626;">*</span></label>
        <select name="SubjectInfoId" class="form-control" required>
          <option value="0">— Select Subject —</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?php echo $s['SubjectInfoId']; ?>"
              <?php echo (($row['SubjectInfoId'] ?? 0) == $s['SubjectInfoId']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($s['SubjectName']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="field-hint">Which subject this chapter belongs to (e.g. Physics, Chemistry, Botany, Zoology).</div>
      </div>

      <div class="form-group">
        <label>Chapter Name <span style="color:#dc2626;">*</span></label>
        <input type="text" name="ChapterName" class="form-control" maxlength="150" required
               value="<?php echo htmlspecialchars($row['ChapterName'] ?? ''); ?>"
               placeholder="e.g. Laws of Motion">
      </div>

      <div class="form-group">
        <label>Chapter Order</label>
        <input type="number" name="ChapterOrder" class="form-control" min="0" max="999" style="max-width:120px;"
               value="<?php echo (int)($row['ChapterOrder'] ?? 0); ?>">
        <div class="field-hint">Study/display order within the subject. Lower number appears first.</div>
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="Active" class="form-control" style="max-width:160px;">
          <option value="Y" <?php echo (($row['Active'] ?? 'Y')==='Y')?'selected':''; ?>>Active</option>
          <option value="N" <?php echo (($row['Active'] ?? '')==='N')?'selected':''; ?>>Inactive</option>
        </select>
        <div class="field-hint">Inactive chapters are hidden from the chapter dropdown when adding/editing a question, and from student chapter filters.</div>
      </div>

      <div style="display:flex;gap:10px;margin-top:24px;">
        <button type="submit" name="save" class="btn btn-primary">&#128190; Save</button>
        <a href="ChapterInfo.php" class="btn btn-secondary">&#8592; Back</a>
      </div>

    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
