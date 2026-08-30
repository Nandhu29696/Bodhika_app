<?php
/**
 * exam/case-studies.php — Admin: list & manage case studies for a specific exam.
 *
 * A case study (migration_v52) groups several questions under one shared
 * scenario — background reading shown as tabbed sections, followed by
 * questions that each reference that same scenario independently, the way
 * Microsoft certification exams do. See exam/case-study-edit.php for the
 * add/edit form and exam/question-edit.php for tagging a question to one.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$examId = filter_input(INPUT_GET, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

$exam = Database::fetchOne("SELECT ExamName FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: search.php'); exit; }

$msg = ''; $msgType = 'success';

/* ── Handle toggle active/inactive ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_csid'])) {
    Auth::validateCsrf();
    $csId     = (int)$_POST['toggle_csid'];
    $newState = $_POST['new_state'] === 'Y' ? 'Y' : 'N';
    Database::execute(
        "UPDATE case_studies SET IsActive=? WHERE CaseStudyId=? AND ExamInfoId=?",
        [$newState, $csId, $examId]);
    header('Location: case-studies.php?examId='.$examId); exit;
}

/* ── Handle delete (only when no questions are still tagged to it) ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_csid'])) {
    Auth::validateCsrf();
    $csId = (int)$_POST['delete_csid'];
    $inUse = Database::fetchOne(
        "SELECT COUNT(*) AS c FROM questions WHERE CaseStudyId=? AND COALESCE(IsDeleted,'N')='N'", [$csId]);
    if ((int)($inUse['c'] ?? 0) > 0) {
        $msg = 'Cannot delete — ' . (int)$inUse['c'] . ' question(s) are still tagged to this case study. Retag or delete them first.';
        $msgType = 'danger';
    } else {
        Database::execute("DELETE FROM case_study_sections WHERE CaseStudyId=?", [$csId]);
        Database::execute("DELETE FROM case_studies WHERE CaseStudyId=? AND ExamInfoId=?", [$csId, $examId]);
        header('Location: case-studies.php?examId='.$examId.'&deleted=1'); exit;
    }
}

/* ── Load case studies + question/section counts ─────────────────────────── */
$caseStudies = Database::fetchAll(
    "SELECT cs.*,
            (SELECT COUNT(*) FROM case_study_sections s WHERE s.CaseStudyId = cs.CaseStudyId) AS SectionCount,
            (SELECT COUNT(*) FROM questions q WHERE q.CaseStudyId = cs.CaseStudyId AND COALESCE(q.IsDeleted,'N')='N') AS QuestionCount
       FROM case_studies cs
      WHERE cs.ExamInfoId = ?
   ORDER BY cs.DisplayOrder, cs.Title",
    [$examId]);

$pageTitle = 'Case Studies';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .cs-tbl { width:100%; border-collapse:collapse; }
  .cs-tbl th, .cs-tbl td { padding:9px 12px; text-align:left; vertical-align:middle; }
  .cs-tbl thead th { background:#1a365d; color:#fff; font-size:.82rem; }
  .cs-tbl tbody tr { border-bottom:1px solid #e2e8f0; }
  .cs-tbl tbody tr:hover { background:#ebf8ff; }
  .badge-active   { background:#c6efce; color:#276749; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:700; }
  .badge-inactive { background:#f0f0f0; color:#718096; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:700; }
  .action-btn { padding:3px 9px; border-radius:4px; font-size:.78rem; font-weight:600; text-decoration:none;
                border:none; cursor:pointer; display:inline-block; white-space:nowrap; }
  .btn-edit { background:#3182ce; color:#fff; }
  .btn-on   { background:#276749; color:#fff; }
  .btn-off  { background:#c53030; color:#fff; }
</style>

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
  <span>Case Studies</span>
</nav>

<?php if ($msg): ?>
  <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:16px;"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<?php if (filter_input(INPUT_GET, 'deleted', FILTER_VALIDATE_INT)): ?>
  <div class="alert alert-success" style="margin-bottom:16px;">&#10003; Case study deleted.</div>
<?php endif; ?>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128220; Case Studies — <em style="font-weight:400;font-size:.9rem;"><?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?></em></span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="case-study-edit.php?examId=<?php echo $examId; ?>" class="btn btn-success btn-sm" style="font-weight:700;">
        &#10010; New Case Study
      </a>
      <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-secondary btn-sm">&#8592; Back to Questions</a>
    </div>
  </div>

  <div style="padding:10px 16px;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:.82rem;color:#1e40af;">
    &#8505; A case study is shared background info (business requirements, existing environment, etc., shown as
    tabs) followed by several questions that each reference it independently — each question is still scored
    on its own. Tag a question to a case study from its Edit Question page.
  </div>

  <?php if (empty($caseStudies)): ?>
    <div style="text-align:center;padding:40px;color:#718096;">
      No case studies yet. <a href="case-study-edit.php?examId=<?php echo $examId; ?>" style="color:#3182ce;">Create the first one</a>.
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="cs-tbl">
      <thead>
        <tr>
          <th>#</th>
          <th>Title</th>
          <th style="text-align:center;">Sections</th>
          <th style="text-align:center;">Questions</th>
          <th style="text-align:center;">Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($caseStudies as $i => $cs):
          $isActive = ($cs['IsActive'] ?? 'Y') === 'Y';
        ?>
        <tr>
          <td style="color:#718096;font-weight:700;"><?php echo $i + 1; ?></td>
          <td><strong><?php echo htmlspecialchars($cs['Title']); ?></strong></td>
          <td style="text-align:center;"><?php echo (int)$cs['SectionCount']; ?></td>
          <td style="text-align:center;">
            <?php echo (int)$cs['QuestionCount']; ?>
            <a href="questions.php?examId=<?php echo $examId; ?>&caseStudy=<?php echo (int)$cs['CaseStudyId']; ?>"
               style="font-size:.75rem;color:#3182ce;margin-left:4px;" title="View these questions">view</a>
          </td>
          <td style="text-align:center;">
            <span class="<?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?>">
              <?php echo $isActive ? 'Active' : 'Inactive'; ?>
            </span>
          </td>
          <td>
            <a href="case-study-edit.php?examId=<?php echo $examId; ?>&csId=<?php echo (int)$cs['CaseStudyId']; ?>"
               class="action-btn btn-edit">Edit</a>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('<?php echo $isActive ? 'Deactivate' : 'Activate'; ?> this case study?');">
              <input type="hidden" name="csrf_token"   value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="toggle_csid"  value="<?php echo (int)$cs['CaseStudyId']; ?>">
              <input type="hidden" name="new_state"    value="<?php echo $isActive ? 'N' : 'Y'; ?>">
              <button type="submit" class="action-btn <?php echo $isActive ? 'btn-off' : 'btn-on'; ?>">
                <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
              </button>
            </form>
            <?php if ((int)$cs['QuestionCount'] === 0): ?>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Delete this case study and all its sections? This cannot be undone.');">
              <input type="hidden" name="csrf_token"  value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="delete_csid" value="<?php echo (int)$cs['CaseStudyId']; ?>">
              <button type="submit" class="action-btn" style="background:#7f1d1d;color:#fff;">Delete</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
