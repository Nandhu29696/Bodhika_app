<?php
/**
 * exam/trash.php — Admin: review and restore soft-deleted exams & questions.
 *
 * Deleting an exam (exam/search.php, exam/manage.php) or a question
 * (exam/questions.php) only sets IsDeleted='Y' — nothing is ever physically
 * removed, so every row shown here can be brought back with one click.
 * Requires migrations/migration_v43.sql.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$migrationMissing = false;
$msg = filter_input(INPUT_GET, 'msg', FILTER_UNSAFE_RAW);
$msg = $msg !== null ? trim(strip_tags($msg)) : '';

/* ── Handle restore actions ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    if (isset($_POST['restore_examid'])) {
        $id = (int)$_POST['restore_examid'];
        try {
            Database::execute(
                "UPDATE examinfo SET IsDeleted='N', DeletedAt=NULL, DeletedBy=NULL WHERE ExamInfoId=?",
                [$id]
            );
            try {
                $name = Database::fetchOne("SELECT ExamName FROM examinfo WHERE ExamInfoId=? LIMIT 1", [$id])['ExamName'] ?? '';
                Database::execute(
                    "INSERT INTO exam_changelog (ExamInfoId,ExamName,Action,ActionBy,Details) VALUES (?,?,?,?,?)",
                    [$id, $name, 'RESTORE', Auth::currentUser(), 'Restored from Trash']
                );
            } catch (Exception $eLog) {}
        } catch (Exception $e) { /* migration_v43 not yet run */ }
        header('Location: trash.php?msg=' . urlencode('Exam restored.')); exit;
    }

    if (isset($_POST['restore_qid'])) {
        $id = (int)$_POST['restore_qid'];
        try {
            Database::execute(
                "UPDATE questions SET IsDeleted='N', DeletedAt=NULL, DeletedBy=NULL WHERE QuestionId=?",
                [$id]
            );
        } catch (Exception $e) { /* migration_v43 not yet run */ }
        header('Location: trash.php?msg=' . urlencode('Question restored.')); exit;
    }
}

/* ── Load deleted exams ─────────────────────────────────────────────────── */
$deletedExams = [];
try {
    $deletedExams = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, e.DeletedAt, e.DeletedBy, g.GradeName, s.SubjectName
           FROM examinfo e
      LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
      LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
          WHERE e.IsDeleted = 'Y'
          ORDER BY e.DeletedAt DESC LIMIT 200", []);
} catch (Exception $e) {
    $migrationMissing = true;
}

/* ── Load deleted questions (with originating exam for context) ──────────── */
$deletedQuestions = [];
try {
    $deletedQuestions = Database::fetchAll(
        "SELECT q.QuestionId, q.QuestionDesc, q.DeletedAt, q.DeletedBy,
                q.ExamInfoId, e.ExamName
           FROM questions q
      LEFT JOIN examinfo e ON e.ExamInfoId = q.ExamInfoId
          WHERE q.IsDeleted = 'Y'
          ORDER BY q.DeletedAt DESC LIMIT 200", []);
} catch (Exception $e) {
    $migrationMissing = true;
}

$pageTitle = 'Trash';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .trash-tbl{width:100%;border-collapse:collapse;}
  .trash-tbl th,.trash-tbl td{padding:9px 10px;vertical-align:middle;text-align:left;}
  .trash-tbl thead th{background:#334155;color:#fff;font-size:.82rem;}
  .trash-tbl tbody tr{border-bottom:1px solid #e2e8f0;}
  .trash-tbl tbody tr:hover{background:#f8fafc;}
  .trash-meta{font-size:.75rem;color:#718096;}
</style>

<!-- Breadcrumb -->
<nav style="font-size:.85rem;color:#718096;margin-bottom:10px;">
  <a href="search.php" style="color:#3182ce;text-decoration:none;">&#128196; Exams</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>&#128465; Trash</span>
</nav>

<?php if ($msg !== ''): ?>
<div style="background:#f0fff4;border:1px solid #9ae6b4;color:#276749;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;">
  &#10003; <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php if ($migrationMissing): ?>
<div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;">
  &#9888; Soft delete isn't set up on this database yet. Run
  <code>migrations/migration_v43.sql</code> to enable Trash.
</div>
<?php endif; ?>

<!-- Deleted exams -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128196; Deleted Exams
      <span style="font-size:.8rem;font-weight:400;color:#718096;margin-left:6px;">(<?php echo count($deletedExams); ?>)</span>
    </span>
    <a href="search.php" class="btn btn-secondary btn-sm">&#8592; Exam List</a>
  </div>
  <?php if (empty($deletedExams)): ?>
  <div style="text-align:center;padding:28px;color:#718096;">Nothing here. Deleted exams will show up in this list.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="trash-tbl">
      <thead>
        <tr><th>Exam Name</th><th>Grade</th><th>Subject</th><th>Deleted</th><th style="width:120px;">Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($deletedExams as $ex): ?>
        <tr>
          <td style="font-weight:600;"><?php echo htmlspecialchars($ex['ExamName'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($ex['GradeName'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($ex['SubjectName'] ?? '—'); ?></td>
          <td class="trash-meta">
            <?php echo !empty($ex['DeletedAt']) ? htmlspecialchars(date('d M Y, g:i a', strtotime($ex['DeletedAt']))) : '—'; ?>
            <?php if (!empty($ex['DeletedBy'])): ?><br>by <?php echo htmlspecialchars($ex['DeletedBy']); ?><?php endif; ?>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('Restore &quot;<?php echo addslashes(htmlspecialchars($ex['ExamName'] ?? '')); ?>&quot;?');">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
              <input type="hidden" name="restore_examid" value="<?php echo (int)$ex['ExamInfoId']; ?>">
              <button type="submit" class="btn btn-success btn-xs">&#8634; Restore</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Deleted questions -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#10067; Deleted Questions
      <span style="font-size:.8rem;font-weight:400;color:#718096;margin-left:6px;">(<?php echo count($deletedQuestions); ?>)</span>
    </span>
    <a href="questions-hub.php" class="btn btn-secondary btn-sm">&#8592; Manage Questions</a>
  </div>
  <?php if (empty($deletedQuestions)): ?>
  <div style="text-align:center;padding:28px;color:#718096;">Nothing here. Deleted questions will show up in this list.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="trash-tbl">
      <thead>
        <tr><th>Question</th><th>Exam</th><th>Deleted</th><th style="width:120px;">Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($deletedQuestions as $q): ?>
        <tr>
          <td style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
              title="<?php echo htmlspecialchars($q['QuestionDesc'] ?? ''); ?>">
            <?php echo htmlspecialchars($q['QuestionDesc'] ?? '(no text)'); ?>
          </td>
          <td>
            <?php if (!empty($q['ExamInfoId'])): ?>
              <a href="questions.php?examId=<?php echo (int)$q['ExamInfoId']; ?>" style="color:#3182ce;">
                <?php echo htmlspecialchars($q['ExamName'] ?? ('Exam #' . (int)$q['ExamInfoId'])); ?>
              </a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="trash-meta">
            <?php echo !empty($q['DeletedAt']) ? htmlspecialchars(date('d M Y, g:i a', strtotime($q['DeletedAt']))) : '—'; ?>
            <?php if (!empty($q['DeletedBy'])): ?><br>by <?php echo htmlspecialchars($q['DeletedBy']); ?><?php endif; ?>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('Restore this question?');">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
              <input type="hidden" name="restore_qid" value="<?php echo (int)$q['QuestionId']; ?>">
              <button type="submit" class="btn btn-success btn-xs">&#8634; Restore</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
