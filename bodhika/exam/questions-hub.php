<?php
/**
 * exam/questions-hub.php
 * Admin landing page: browse all exams and jump straight to their questions.
 * Admin / Teacher / Principal only.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$pageTitle = 'Manage Questions';

/* Load all exams with live question counts + scope/institute (migration_v24).
   Falls back gracefully if either IsActive (v4) or ExamScope (v24) column is missing. */
try {
    $exams = Database::fetchAll(
        "SELECT e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.MinPassing, e.TimeAlloted,
                e.ExamScope, e.ExamInstituteId, e.ExamFreeFor, i.InstituteName,
                g.GradeName, s.SubjectName, gr.GroupId, gr.GroupName,
                COUNT(q.QuestionId)                                           AS TotalQ,
                SUM(CASE WHEN q.IsActive = 'Y' OR q.IsActive IS NULL THEN 1 ELSE 0 END) AS ActiveQ
           FROM examinfo e
      LEFT JOIN gradeinfo   g  ON g.GradeInfoId   = e.GradeInfoId
      LEFT JOIN groupinfo   gr ON gr.GroupId      = g.GroupId
      LEFT JOIN subjectinfo s  ON s.SubjectInfoId = e.SubjectInfoId
      LEFT JOIN questions   q  ON q.ExamInfoId    = e.ExamInfoId AND COALESCE(q.IsDeleted,'N') = 'N'
      LEFT JOIN institutes  i  ON i.InstituteId   = e.ExamInstituteId
          WHERE COALESCE(e.IsDeleted,'N') = 'N'
          GROUP BY e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.MinPassing,
                   e.TimeAlloted, e.ExamScope, e.ExamInstituteId, e.ExamFreeFor, i.InstituteName,
                   g.GradeName, s.SubjectName, gr.GroupId, gr.GroupName
          ORDER BY e.ExamInfoId DESC",
        []);
} catch (Exception $e) {
    // Scope columns, IsActive, or IsDeleted not yet available — minimal fallback
    try {
        $exams = Database::fetchAll(
            "SELECT e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.MinPassing, e.TimeAlloted,
                    g.GradeName, s.SubjectName,
                    COUNT(q.QuestionId)                                           AS TotalQ,
                    SUM(CASE WHEN q.IsActive = 'Y' OR q.IsActive IS NULL THEN 1 ELSE 0 END) AS ActiveQ
               FROM examinfo e
          LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
          LEFT JOIN subjectinfo s ON s.SubjectInfoId  = e.SubjectInfoId
          LEFT JOIN questions   q ON q.ExamInfoId    = e.ExamInfoId
              GROUP BY e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.MinPassing,
                       e.TimeAlloted, g.GradeName, s.SubjectName
              ORDER BY e.ExamInfoId DESC",
            []);
    } catch (Exception $e2) {
        $exams = Database::fetchAll(
            "SELECT e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.MinPassing, e.TimeAlloted,
                    g.GradeName, s.SubjectName,
                    COUNT(q.QuestionId) AS TotalQ,
                    COUNT(q.QuestionId) AS ActiveQ
               FROM examinfo e
          LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
          LEFT JOIN subjectinfo s ON s.SubjectInfoId  = e.SubjectInfoId
          LEFT JOIN questions   q ON q.ExamInfoId    = e.ExamInfoId
              GROUP BY e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.MinPassing,
                       e.TimeAlloted, g.GradeName, s.SubjectName
              ORDER BY e.ExamInfoId DESC",
            []);
    }
}

$grades   = Database::fetchAll("SELECT GradeInfoId, GradeName   FROM gradeinfo   ORDER BY GradeName");
$subjects = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");
try {
    $groups = Database::fetchAll("SELECT GroupId, GroupName FROM groupinfo WHERE Active = 'Y' ORDER BY SortOrder, GroupName");
} catch (Exception $e) {
    $groups = [];
}

include __DIR__ . '/../includes/header.php';
?>
<style>
  .hub-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;transition:box-shadow .15s;}
  .hub-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
  .hub-exam-name{font-weight:700;font-size:1rem;color:#1a365d;}
  .hub-meta{font-size:.82rem;color:#718096;margin-top:2px;}
  .hub-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;}
  .hub-pill{padding:2px 10px;border-radius:12px;font-size:.78rem;font-weight:600;}
  .pill-ok {background:#c6efce;color:#276749;}
  .pill-warn{background:#fff3cd;color:#b7791f;}
  .pill-bad {background:#ffc7ce;color:#c53030;}
  .pill-gray{background:#e2e8f0;color:#4a5568;}
  .hub-actions{display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap;}
  .group-filter-btn{padding:4px 12px;border-radius:14px;border:1.5px solid #cbd5e0;background:#fff;
                     color:#4a5568;font-size:.78rem;font-weight:600;cursor:pointer;}
  .group-filter-btn:hover{border-color:#4f46e5;color:#4f46e5;}
  .group-filter-btn.active{background:#4f46e5;border-color:#4f46e5;color:#fff;}
  .hub-group-badge{display:inline-block;padding:1px 9px;border-radius:10px;font-size:.72rem;
                    font-weight:700;background:#eef2ff;color:#4338ca;margin-left:6px;vertical-align:middle;}
  .hub-group-badge-none{background:#f1f5f9;color:#94a3b8;}
</style>

<!-- Page header with quick-action buttons -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#10067; Manage Questions</span>
    <div style="display:flex;gap:8px;">
      <a href="manage.php?InfoId=0" class="btn btn-success btn-sm">&#10010; Add New Exam</a>
      <a href="trash.php"           class="btn btn-sm" style="background:#64748b;color:#fff;" title="Restore deleted exams / questions">&#128465; Trash</a>
      <a href="search.php"          class="btn btn-secondary btn-sm">&#128196; Exam List</a>
    </div>
  </div>
  <div class="card-body" style="padding:12px 20px;background:#f7fafc;border-bottom:1px solid #e2e8f0;font-size:.875rem;color:#4a5568;">
    &#128161; Click <strong>Questions</strong> on any exam to view and edit its questions, or <strong>Add Question</strong> to go directly to the add form.
  </div>
  <?php if (!empty($groups)): ?>
  <div class="card-body" style="padding:12px 20px;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:.82rem;font-weight:700;color:#4a5568;">Filter by group:</span>
    <button type="button" class="group-filter-btn active" data-group="0" onclick="filterByGroup(this)">All</button>
    <?php foreach ($groups as $gr): ?>
      <button type="button" class="group-filter-btn" data-group="<?php echo (int)$gr['GroupId']; ?>" onclick="filterByGroup(this)">
        <?php echo htmlspecialchars($gr['GroupName']); ?>
      </button>
    <?php endforeach; ?>
    <a href="groups.php" style="font-size:.78rem;color:#4f46e5;font-weight:600;margin-left:auto;">&#127959; Manage Groups</a>
  </div>
  <?php endif; ?>
</div>

<!-- Exam list -->
<?php if (empty($exams)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:40px;color:#718096;">
    No exams found. <a href="manage.php?InfoId=0" class="btn btn-success" style="margin-left:12px;">&#10010; Add First Exam</a>
  </div>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:10px;margin-top:4px;">
<?php foreach ($exams as $exam):
  $total  = (int)$exam['TotalQ'];
  $active = (int)$exam['ActiveQ'];
  $needed = (int)$exam['NumOfQuestions'];
  $ok     = ($active >= $needed && $needed > 0);
  $statusClass = $total === 0 ? 'pill-bad' : ($ok ? 'pill-ok' : 'pill-warn');
  $statusLabel = $total === 0 ? 'No Questions' : ($ok ? 'Ready' : 'Needs more active Qs');
?>
<?php $examIsActive = ($exam['IsActive'] ?? 'Y') === 'Y'; ?>
<?php $examGroupId = (int)($exam['GroupId'] ?? 0); ?>
<div class="hub-card" data-group="<?php echo $examGroupId; ?>"
     style="<?php echo $examIsActive ? '' : 'opacity:.7;border-left:4px solid #dc2626;'; ?>">
  <div style="flex:1;min-width:200px;">
    <div class="hub-exam-name">
      <?php echo htmlspecialchars($exam['ExamName']); ?>
      <?php if (!empty($exam['GroupName'])): ?>
        <span class="hub-group-badge"><?php echo htmlspecialchars($exam['GroupName']); ?></span>
      <?php elseif (!empty($groups)): ?>
        <span class="hub-group-badge hub-group-badge-none" title="Assign a group to this exam's grade">Uncategorized</span>
      <?php endif; ?>
      <?php if (!$examIsActive): ?>
        <span style="display:inline-block;margin-left:6px;padding:2px 8px;background:#fee2e2;color:#991b1b;border-radius:10px;font-size:.68rem;font-weight:700;vertical-align:middle;">🚫 INACTIVE</span>
      <?php endif; ?>
      <?php $ff = $exam['ExamFreeFor'] ?? 'None';
            if ($ff === 'All'): ?>
        <span style="display:inline-block;margin-left:6px;padding:2px 8px;background:#d1fae5;color:#065f46;border-radius:10px;font-size:.68rem;font-weight:700;vertical-align:middle;">FREE ALL</span>
      <?php elseif ($ff === 'Institute'): ?>
        <span style="display:inline-block;margin-left:6px;padding:2px 8px;background:#dbeafe;color:#1e40af;border-radius:10px;font-size:.68rem;font-weight:700;vertical-align:middle;">FREE 🏫</span>
      <?php endif; ?>
    </div>
    <div class="hub-meta">
      <?php echo htmlspecialchars($exam['GradeName'] ?? '—'); ?> &bull;
      <?php echo htmlspecialchars($exam['SubjectName'] ?? '—'); ?> &bull;
      Pass: <?php echo min(100, max(0, (int)$exam['MinPassing'])); ?>% &bull;
      Time: <?php echo htmlspecialchars($exam['TimeAlloted'] ?? '—'); ?> min
    </div>
    <div class="hub-pills">
      <span class="hub-pill pill-gray">Needs <?php echo $needed; ?> Qs</span>
      <span class="hub-pill pill-gray">Total: <?php echo $total; ?></span>
      <span class="hub-pill pill-ok">Active: <?php echo $active; ?></span>
      <?php if ($total - $active > 0): ?>
      <span class="hub-pill pill-warn">Inactive: <?php echo $total - $active; ?></span>
      <?php endif; ?>
      <span class="hub-pill <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
      <?php
        $scope = $exam['ExamScope'] ?? 'All';
        if ($scope === 'Institute'):
          $instName = $exam['InstituteName'] ?? 'Institute';
      ?>
      <span class="hub-pill" style="background:#dbeafe;color:#1e40af;" title="Institute-restricted exam">
        &#127982; <?php echo htmlspecialchars($instName); ?>
      </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="hub-actions">
    <a href="questions.php?examId=<?php echo (int)$exam['ExamInfoId']; ?>"
       class="btn btn-sm" style="background:#6b46c1;color:#fff;font-weight:700;">
      &#10067; Questions
    </a>
    <a href="question-edit.php?examId=<?php echo (int)$exam['ExamInfoId']; ?>"
       class="btn btn-success btn-sm">
      &#10010; Add Question
    </a>
    <a href="manage.php?InfoId=<?php echo (int)$exam['ExamInfoId']; ?>"
       class="btn btn-warning btn-sm">
      &#9998; Edit Exam
    </a>
    <a href="write.php?InfoId=<?php echo (int)$exam['ExamInfoId']; ?>"
       class="btn btn-primary btn-sm">
      &#9998; Write Exam
    </a>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function filterByGroup(btn) {
  var gid = btn.getAttribute('data-group');
  document.querySelectorAll('.group-filter-btn').forEach(function(b) {
    b.classList.toggle('active', b === btn);
  });
  document.querySelectorAll('.hub-card').forEach(function(card) {
    var show = (gid === '0') || (card.getAttribute('data-group') === gid);
    card.style.display = show ? '' : 'none';
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
