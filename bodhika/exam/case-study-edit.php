<?php
/**
 * exam/case-study-edit.php — Add / edit a case study (title + tabbed sections).
 *
 * A case study's "sections" are the tabbed background-info blocks students
 * see (e.g. "Existing Environment", "Requirements. Planned Changes") — see
 * migration_v52.sql. Sections are fully re-saved on every submit (delete +
 * re-insert in the posted order), the same pattern the MCQ/MATCH answer
 * rows in question-edit.php use — simplest correct approach for a small,
 * always-fully-shown list with no other table referencing a section by id.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$examId = filter_input(INPUT_GET,  'examId', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'examId', FILTER_VALIDATE_INT);
$csId   = filter_input(INPUT_GET,  'csId',    FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'csId',    FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

$exam = Database::fetchOne("SELECT ExamName FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: search.php'); exit; }

/* ── Load existing case study + sections for edit ─────────────────────────── */
$cs = ['Title' => '', 'DisplayOrder' => 0, 'IsActive' => 'Y'];
$sections = [['SectionTitle' => '', 'ContentHtml' => '']];
if ($csId) {
    $row = Database::fetchOne(
        "SELECT * FROM case_studies WHERE CaseStudyId = ? AND ExamInfoId = ? LIMIT 1", [$csId, $examId]);
    if (!$row) { header('Location: case-studies.php?examId='.$examId); exit; }
    $cs = $row;
    $existingSections = Database::fetchAll(
        "SELECT SectionTitle, ContentHtml FROM case_study_sections
          WHERE CaseStudyId = ? ORDER BY SectionOrder, SectionId", [$csId]);
    if ($existingSections) $sections = $existingSections;
}

$errors = [];

/* ── Handle save ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    Auth::validateCsrf();

    $title        = trim($_POST['Title'] ?? '');
    $displayOrder = (int)($_POST['DisplayOrder'] ?? 0);
    $isActive     = isset($_POST['IsActive']) ? 'Y' : 'N';

    $secTitles   = $_POST['SectionTitle']   ?? [];
    $secContents = $_POST['ContentHtml']    ?? [];
    $postedSections = [];
    foreach ($secTitles as $i => $st) {
        $st  = trim($st);
        $sc  = trim($secContents[$i] ?? '');
        if ($st === '' && $sc === '') continue; // skip fully-empty rows
        $postedSections[] = ['SectionTitle' => $st, 'ContentHtml' => $sc];
    }

    if ($title === '') $errors[] = 'Case study title is required.';
    if (empty($postedSections)) $errors[] = 'Add at least one section (e.g. "Existing Environment").';
    foreach ($postedSections as $ps) {
        if ($ps['SectionTitle'] === '') { $errors[] = 'Every section needs a title.'; break; }
    }

    if (!$errors) {
        Database::beginTransaction();
        try {
            if ($csId) {
                Database::execute(
                    "UPDATE case_studies SET Title=?, DisplayOrder=?, IsActive=? WHERE CaseStudyId=? AND ExamInfoId=?",
                    [$title, $displayOrder, $isActive, $csId, $examId]);
            } else {
                Database::execute(
                    "INSERT INTO case_studies (ExamInfoId, Title, DisplayOrder, IsActive) VALUES (?,?,?,?)",
                    [$examId, $title, $displayOrder, $isActive]);
                $csId = (int)Database::lastInsertId();
            }

            /* Re-save sections: delete + re-insert in posted order (see file docblock) */
            Database::execute("DELETE FROM case_study_sections WHERE CaseStudyId=?", [$csId]);
            foreach ($postedSections as $order => $ps) {
                Database::execute(
                    "INSERT INTO case_study_sections (CaseStudyId, SectionTitle, SectionOrder, ContentHtml)
                     VALUES (?,?,?,?)",
                    [$csId, $ps['SectionTitle'], $order, $ps['ContentHtml']]);
            }

            Database::commit();
            header('Location: case-studies.php?examId='.$examId.'&saved=1'); exit;
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('Case study save failed (CaseStudyId=' . ($csId ?: 'new') . '): ' . $e->getMessage());
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }

    /* Re-populate form from POST on validation error */
    $cs = ['Title' => $title, 'DisplayOrder' => $displayOrder, 'IsActive' => $isActive];
    $sections = $postedSections ?: $sections;
}

$pageTitle = ($csId ? 'Edit' : 'New') . ' Case Study';
$pageHead  = '<style>
.q-section{background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin-bottom:16px;}
.q-section h3{margin:0 0 14px;font-size:1rem;color:#1a365d;}
.section-row{border:2px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:12px;background:#fff;}
.section-row .sec-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.section-row input[type=text]{width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.92rem;font-weight:700;}
.section-row textarea{width:100%;min-height:110px;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.88rem;resize:vertical;box-sizing:border-box;margin-top:8px;}
.btn-remove-sec{background:#fee2e2;color:#991b1b;border:none;border-radius:5px;padding:4px 10px;font-size:.78rem;font-weight:700;cursor:pointer;}
.error-box{background:#fff5f5;border:1px solid #c53030;color:#c53030;padding:12px;border-radius:6px;margin-bottom:16px;}
.field-hint{font-size:.78rem;color:#718096;margin-top:4px;}
</style>';
include __DIR__ . '/../includes/header.php';
?>

<!-- Breadcrumb -->
<nav style="font-size:.85rem;color:#718096;margin-bottom:10px;">
  <a href="search.php"        style="color:#3182ce;text-decoration:none;">&#128196; Exams</a>
  <span style="margin:0 6px;">›</span>
  <a href="questions-hub.php" style="color:#3182ce;text-decoration:none;">&#10067; Manage Questions</a>
  <span style="margin:0 6px;">›</span>
  <a href="questions.php?examId=<?php echo $examId; ?>" style="color:#3182ce;text-decoration:none;">
    <?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?>
  </a>
  <span style="margin:0 6px;">›</span>
  <a href="case-studies.php?examId=<?php echo $examId; ?>" style="color:#3182ce;text-decoration:none;">Case Studies</a>
  <span style="margin:0 6px;">›</span>
  <span><?php echo $csId ? 'Edit' : 'New'; ?></span>
</nav>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128220; <?php echo $csId ? 'Edit' : 'New'; ?> Case Study
      — <em style="font-weight:400;font-size:.9rem;"><?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?></em>
    </span>
    <a href="case-studies.php?examId=<?php echo $examId; ?>" class="btn btn-secondary btn-sm">&#8592; Back</a>
  </div>

  <div class="card-body">
    <?php if ($errors): ?>
    <div class="error-box">
      <?php foreach ($errors as $e) echo '<div>&#9888; '.htmlspecialchars($e).'</div>'; ?>
    </div>
    <?php endif; ?>

    <form method="post" id="csForm">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
      <input type="hidden" name="examId" value="<?php echo $examId; ?>">
      <?php if ($csId): ?><input type="hidden" name="csId" value="<?php echo $csId; ?>"><?php endif; ?>

      <div class="q-section">
        <h3>&#128221; Case Study Details</h3>
        <div class="form-row" style="display:flex;gap:16px;flex-wrap:wrap;">
          <div style="flex:2;min-width:260px;">
            <label style="font-weight:600;font-size:.875rem;color:#4a5568;display:block;margin-bottom:4px;">
              Title <span style="color:#dc2626;">*</span>
            </label>
            <input type="text" name="Title" required maxlength="200"
                   style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.9rem;"
                   value="<?php echo htmlspecialchars($cs['Title']); ?>" placeholder="e.g. Case Study 6">
          </div>
          <div style="min-width:140px;">
            <label style="font-weight:600;font-size:.875rem;color:#4a5568;display:block;margin-bottom:4px;">Display Order</label>
            <input type="number" name="DisplayOrder" min="0" max="999"
                   style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.9rem;"
                   value="<?php echo (int)$cs['DisplayOrder']; ?>">
          </div>
          <div style="min-width:160px;">
            <label style="font-weight:600;font-size:.875rem;color:#4a5568;display:block;margin-bottom:4px;">Status</label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding-top:8px;">
              <input type="checkbox" name="IsActive" style="transform:scale(1.3);"
                     <?php echo ($cs['IsActive'] ?? 'Y') === 'Y' ? 'checked' : ''; ?>>
              Active
            </label>
          </div>
        </div>
        <div class="field-hint">
          Students only see an active case study's questions/sections if the question itself is also active
          and included in the exam.
        </div>
      </div>

      <div class="q-section">
        <h3>&#128203; Background Info Sections <span style="font-weight:400;font-size:.82rem;color:#718096;">(shown as tabs to the student, e.g. "Existing Environment", "Requirements")</span></h3>
        <div id="sectionsWrap">
          <?php foreach ($sections as $i => $sec): ?>
          <div class="section-row" data-idx="<?php echo $i; ?>">
            <div class="sec-head">
              <input type="text" name="SectionTitle[]" placeholder="Section title, e.g. Existing Environment"
                     value="<?php echo htmlspecialchars($sec['SectionTitle']); ?>" style="flex:1;">
              <button type="button" class="btn-remove-sec" onclick="removeSection(this)" style="margin-left:10px;">&#10005; Remove</button>
            </div>
            <textarea name="ContentHtml[]" placeholder="Background text for this tab…"><?php echo htmlspecialchars($sec['ContentHtml']); ?></textarea>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="addSection()">&#10010; Add Section</button>
      </div>

      <div style="display:flex;gap:10px;margin-top:10px;">
        <button type="submit" name="save" class="btn btn-primary">&#128190; Save Case Study</button>
        <a href="case-studies.php?examId=<?php echo $examId; ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<template id="sectionTpl">
  <div class="section-row">
    <div class="sec-head">
      <input type="text" name="SectionTitle[]" placeholder="Section title, e.g. Existing Environment" style="flex:1;">
      <button type="button" class="btn-remove-sec" onclick="removeSection(this)" style="margin-left:10px;">&#10005; Remove</button>
    </div>
    <textarea name="ContentHtml[]" placeholder="Background text for this tab…"></textarea>
  </div>
</template>

<script>
function addSection() {
  var tpl = document.getElementById('sectionTpl');
  var clone = tpl.content.cloneNode(true);
  document.getElementById('sectionsWrap').appendChild(clone);
}
function removeSection(btn) {
  var wrap = document.getElementById('sectionsWrap');
  if (wrap.querySelectorAll('.section-row').length <= 1) {
    alert('A case study needs at least one section.');
    return;
  }
  btn.closest('.section-row').remove();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
