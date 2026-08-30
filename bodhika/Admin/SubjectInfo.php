<?php
/**
 * Admin/SubjectInfo.php — Manage subjects (list).
 *
 * Modernised from the old mysql_query()/ps_pagination.php page (which fatal-
 * errored on any current PHP install — those functions were removed in
 * PHP 7). Follows the same PDO + includes/header.php pattern already used by
 * Admin/ChapterInfo.php and Admin/AddEditSubjectInfo.php (the add/edit
 * counterpart to this list, which was already modernised).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/* ── Handle quick actions ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';
    $subId  = (int)($_POST['SubjectInfoId'] ?? 0);

    if ($action === 'toggle' && $subId > 0) {
        Database::execute(
            "UPDATE subjectinfo SET Active = IF(Active='Y','N','Y') WHERE SubjectInfoId = ?",
            [$subId]);
        header('Location: SubjectInfo.php');
        exit;
    }
}

/* ── List, with question/chapter/exam counts for context ────────────────── */
$sql = "SELECT s.*,
               (SELECT COUNT(*) FROM questions   q WHERE q.SubjectInfoId = s.SubjectInfoId) AS QuestionCount,
               (SELECT COUNT(*) FROM chapterinfo  c WHERE c.SubjectInfoId = s.SubjectInfoId) AS ChapterCount,
               (SELECT COUNT(*) FROM examinfo     e WHERE e.SubjectInfoId = s.SubjectInfoId) AS ExamCount
          FROM subjectinfo s
      ORDER BY s.SubjectName ASC";
$subjects = Database::fetchAll($sql);

$pageTitle = 'Subjects';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .inactive-row { opacity:.55; }
  .act-btn { font-size:.8rem; padding:3px 10px; }
  .tbl th, .tbl td { vertical-align:middle; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128218; Subjects</span>
    <a href="AddEditSubjectInfo.php?InfoId=0" class="btn btn-primary" style="font-size:.85rem;padding:5px 14px;">
      &#43; Add Subject
    </a>
  </div>
  <div class="card-body">

    <?php if (empty($subjects)): ?>
      <p style="text-align:center;color:#6b7280;padding:30px 0;">
        No subjects found. <a href="AddEditSubjectInfo.php?InfoId=0">Add the first one</a>.
      </p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Subject</th>
            <th>Exam Fee</th>
            <th>Default Discount</th>
            <th>Chapters</th>
            <th>Questions</th>
            <th>Exams</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subjects as $s):
            $inactive = ($s['Active'] === 'N');
          ?>
          <tr class="<?php echo $inactive ? 'inactive-row' : ''; ?>">
            <td><strong><?php echo htmlspecialchars($s['SubjectName']); ?></strong></td>
            <td>&#8377;<?php echo number_format((float)($s['ExamFee'] ?? 0), 2); ?></td>
            <td><?php echo number_format((float)($s['DiscountPct'] ?? 0), 2); ?>%</td>
            <td><?php echo (int)$s['ChapterCount']; ?></td>
            <td><?php echo (int)$s['QuestionCount']; ?></td>
            <td><?php echo (int)$s['ExamCount']; ?></td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="SubjectInfoId" value="<?php echo (int)$s['SubjectInfoId']; ?>">
                <button type="submit" class="btn act-btn <?php echo $inactive ? 'btn-secondary' : 'btn-success'; ?>">
                  <?php echo $inactive ? 'Inactive' : 'Active'; ?>
                </button>
              </form>
            </td>
            <td style="white-space:nowrap;">
              <a href="AddEditSubjectInfo.php?InfoId=<?php echo (int)$s['SubjectInfoId']; ?>"
                 class="btn btn-primary act-btn">&#9998; Edit</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:10px;font-size:.82rem;color:#6b7280;">
      <?php echo count($subjects); ?> subject<?php echo count($subjects) !== 1 ? 's' : ''; ?> found.
    </div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
