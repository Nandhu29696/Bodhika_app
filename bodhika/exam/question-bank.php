<?php
/**
 * exam/question-bank.php
 * Import questions from other exams in the same subject into the current exam.
 * Admin / Teacher / Principal only.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$examId = filter_input(INPUT_GET,  'examId', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

/* ── Load target exam ─────────────────────────────────────────────────── */
$exam = Database::fetchOne(
    "SELECT e.*, s.SubjectInfoId, s.SubjectName, g.GradeName
       FROM examinfo e
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
  LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
      WHERE e.ExamInfoId = ? LIMIT 1",
    [$examId]);
if (!$exam) { header('Location: search.php'); exit; }

$subjectId   = (int)($exam['SubjectInfoId'] ?? 0);
$subjectName = $exam['SubjectName'] ?? '—';

/* ── Handle POST: assign selected questions to this exam ──────────────── */
/* After migration_v22: no data is copied — just INSERT into exam_questions.
   Each question in the bank is a real row in the questions table.
   Falls back to legacy link-row approach if exam_questions doesn't exist yet. */
$importMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qids'])) {
    Auth::validateCsrf();

    $qids     = array_filter(array_map('intval', (array)$_POST['qids']));
    $imported = 0;
    $skipped  = 0;

    foreach ($qids as $srcQid) {
        /* Validate question exists and belongs to the same subject */
        $src = Database::fetchOne(
            "SELECT q.QuestionId, q.SubjectInfoId, q.QuestionType
               FROM questions q
              WHERE q.QuestionId = ?
                AND (q.SubjectInfoId = ? OR ? = 0)
                AND COALESCE(q.IsDeleted,'N') = 'N'
              LIMIT 1",
            [$srcQid, $subjectId, $subjectId]);

        /* Fallback: SubjectInfoId not on questions yet — check via examinfo */
        if (!$src) {
            $src = Database::fetchOne(
                "SELECT q.QuestionId, q.QuestionType, e.SubjectInfoId
                   FROM questions q
                   JOIN examinfo e ON e.ExamInfoId = q.ExamInfoId
                  WHERE q.QuestionId = ?
                    AND (e.SubjectInfoId = ? OR ? = 0)
                    AND q.LinkedFromQuestionId IS NULL
                  LIMIT 1",
                [$srcQid, $subjectId, $subjectId]);
        }
        if (!$src) { $skipped++; continue; }

        $canonicalQid = (int)$src['QuestionId'];

        /* Try exam_questions (migration_v22+) first */
        try {
            $affected = Database::execute(
                "INSERT IGNORE INTO exam_questions (ExamInfoId, QuestionId, IsActive)
                 VALUES (?, ?, 'Y')",
                [$examId, $canonicalQid]);
            if ($affected === 0) { $skipped++; } else { $imported++; }
        } catch (Exception $e) {
            /* exam_questions not yet created — fall back to legacy link row */
            $dup = Database::fetchOne(
                "SELECT QuestionId FROM questions
                  WHERE ExamInfoId = ?
                    AND (QuestionId = ? OR LinkedFromQuestionId = ?) LIMIT 1",
                [$examId, $canonicalQid, $canonicalQid]);
            if ($dup) { $skipped++; continue; }
            try {
                Database::execute(
                    "INSERT INTO questions (ExamInfoId, LinkedFromQuestionId, IsActive, QuestionType)
                     VALUES (?, ?, 'Y', ?)",
                    [$examId, $canonicalQid, $src['QuestionType'] ?? 'MCQ']);
                $imported++;
            } catch (Exception $e2) { continue; }
        }
    }

    $parts = [];
    if ($imported) $parts[] = $imported . ' question' . ($imported !== 1 ? 's' : '') . ' added to exam';
    if ($skipped)  $parts[] = $skipped  . ' skipped (already in this exam)';
    $importMsg = implode(', ', $parts) ?: 'Nothing imported.';

    if ($imported > 0) {
        header('Location: questions.php?examId=' . $examId . '&imported=' . $imported);
        exit;
    }
}

/* ── Load source questions from same-subject OTHER exams ──────────────── */
/* Also collect exam list for the filter dropdown */
$sourceExams = [];
$allSourceQ  = [];

if ($subjectId) {
    $sourceExams = Database::fetchAll(
        "SELECT ExamInfoId, ExamName FROM examinfo
          WHERE SubjectInfoId = ? AND ExamInfoId != ?
          ORDER BY ExamName",
        [$subjectId, $examId]);

    if ($sourceExams) {
        try {
            $allSourceQ = Database::fetchAll(
                "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc,
                        q.CorrectAnswer,
                        COALESCE(q.Complexity,'Medium')  AS Complexity,
                        COALESCE(q.IsActive,'Y')         AS IsActive,
                        COALESCE(q.QuestionType,'MCQ')   AS QuestionType,
                        a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        COALESCE(a.NumStatements,4)       AS NumStatements,
                        a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                        e.ExamInfoId AS SourceExamId, e.ExamName AS SourceExamName
                   FROM questions q
                   JOIN examinfo  e ON e.ExamInfoId = q.ExamInfoId
              LEFT JOIN answers   a ON a.QuestionId = q.QuestionId
                  WHERE e.SubjectInfoId = ?
                    AND q.ExamInfoId   != ?
                    AND COALESCE(q.IsActive,'Y') = 'Y'
                    AND COALESCE(q.IsDeleted,'N') = 'N'
                    AND COALESCE(e.IsDeleted,'N') = 'N'
                  ORDER BY e.ExamName, q.QuestionId",
                [$subjectId, $examId]);
        } catch (Exception $e) {
            // migration_v43 not yet run — IsDeleted columns missing, fall back without them.
            $allSourceQ = Database::fetchAll(
                "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc,
                        q.CorrectAnswer,
                        COALESCE(q.Complexity,'Medium')  AS Complexity,
                        COALESCE(q.IsActive,'Y')         AS IsActive,
                        COALESCE(q.QuestionType,'MCQ')   AS QuestionType,
                        a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        COALESCE(a.NumStatements,4)       AS NumStatements,
                        a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                        e.ExamInfoId AS SourceExamId, e.ExamName AS SourceExamName
                   FROM questions q
                   JOIN examinfo  e ON e.ExamInfoId = q.ExamInfoId
              LEFT JOIN answers   a ON a.QuestionId = q.QuestionId
                  WHERE e.SubjectInfoId = ?
                    AND q.ExamInfoId   != ?
                    AND COALESCE(q.IsActive,'Y') = 'Y'
                  ORDER BY e.ExamName, q.QuestionId",
                [$subjectId, $examId]);
        }
    }
}

/* Mark questions already linked/owned by the target exam.
   Check by canonical QuestionId (handles both direct ownership and existing links). */
$existingQids = [];
$existingRows = Database::fetchAll(
    "SELECT QuestionId, LinkedFromQuestionId FROM questions WHERE ExamInfoId = ?", [$examId]);
foreach ($existingRows as $er) {
    $existingQids[(int)$er['QuestionId']] = true;
    if (!empty($er['LinkedFromQuestionId']))
        $existingQids[(int)$er['LinkedFromQuestionId']] = true;
}

foreach ($allSourceQ as &$q) {
    $canonical = !empty($q['LinkedFromQuestionId'])
                 ? (int)$q['LinkedFromQuestionId']
                 : (int)$q['QuestionId'];
    $q['_alreadyIn'] = isset($existingQids[$canonical]) || isset($existingQids[(int)$q['QuestionId']]);
}
unset($q);

$totalSource = count($allSourceQ);
$pageTitle   = 'Question Bank — Import';

$pageHead = '<style>
/* ── Bank table ─────────────────────────────────────────────────────── */
.bank-tbl{width:100%;border-collapse:collapse;table-layout:fixed;}
.bank-tbl th,.bank-tbl td{padding:9px 10px;vertical-align:middle;overflow:hidden;}
.bank-tbl thead th{background:#1a365d;color:#fff;font-size:.82rem;text-align:left;}
.bank-tbl tbody tr{border-bottom:1px solid #e2e8f0;}
.bank-tbl tbody tr:hover{background:#f0f9ff;}
.bank-tbl tbody tr.already-in{background:#fffbeb;}
.bank-tbl tbody tr.already-in td{opacity:.7;}

.col-chk  {width:40px;  text-align:center;}
.col-src  {width:170px;}
.col-q    {width:auto;}
.col-type {width:105px; text-align:center;}
.col-comp {width:90px;  text-align:center;}
.col-corr {width:80px;  text-align:center;}

.q-text{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;
        max-width:100%;font-size:.85rem;cursor:help;}

/* ── Filter bar ─────────────────────────────────────────────────────── */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;
            padding:10px 16px;background:#f7fafc;border-bottom:1px solid #e2e8f0;}
.filter-bar input[type=text],.filter-bar select{
  padding:6px 10px;border:1px solid #cbd5e0;border-radius:6px;font-size:.875rem;
  background:#fff;}

/* ── Badges ─────────────────────────────────────────────────────────── */
.badge-low    {background:#c6efce;color:#276749;}
.badge-medium {background:#fff3cd;color:#b7791f;}
.badge-high   {background:#ffc7ce;color:#c53030;}
.qbadge{padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;}
.badge-mcq     {background:#ebf8ff;color:#2b6cb0;}
.badge-dropdown{background:#e9d8fd;color:#6b46c1;}
.badge-yesno   {background:#f0fff4;color:#276749;}
.badge-already {background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;
                font-size:.72rem;font-weight:700;}

/* ── Import bar (sticky) ─────────────────────────────────────────────── */
.import-bar{
  position:sticky;bottom:0;background:#1a365d;color:#fff;
  padding:12px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;
  box-shadow:0 -2px 8px rgba(0,0,0,.2);z-index:80;
  border-radius:0 0 8px 8px;
}
.import-bar .sel-count{font-weight:700;font-size:.95rem;min-width:120px;}
</style>';

include __DIR__ . '/../includes/header.php';
?>

<!-- Breadcrumb -->
<nav style="font-size:.85rem;color:#718096;margin-bottom:10px;">
  <a href="search.php"        style="color:#3182ce;text-decoration:none;">&#128196; Exams</a>
  <span style="margin:0 6px;">›</span>
  <a href="questions.php?examId=<?php echo $examId; ?>"
     style="color:#3182ce;text-decoration:none;">
    <?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?>
  </a>
  <span style="margin:0 6px;">›</span>
  <span>&#128218; Question Bank</span>
</nav>

<?php if ($importMsg): ?>
<div style="background:#fff5f5;border:1px solid #c53030;color:#c53030;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;">
  &#9888; <?php echo htmlspecialchars($importMsg); ?>
</div>
<?php endif; ?>

<div class="card" style="padding-bottom:0;">

  <!-- Card header -->
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div>
      <span>&#128218; Question Bank</span>
      <span style="margin-left:10px;font-size:.85rem;font-weight:400;opacity:.85;">
        — Import into <em><?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?></em>
      </span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <span style="font-size:.82rem;color:#fff;background:#2b6cb0;padding:4px 12px;border-radius:16px;font-weight:700;">
        Subject: <?php echo htmlspecialchars($subjectName); ?>
      </span>
      <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-secondary btn-sm">
        &#8592; Back to Questions
      </a>
    </div>
  </div>

  <?php if (empty($sourceExams)): ?>
  <div style="text-align:center;padding:48px;color:#718096;">
    <div style="font-size:2.5rem;margin-bottom:12px;">&#128218;</div>
    <p style="font-size:1rem;font-weight:600;margin:0 0 6px;">No other exams found for <em><?php echo htmlspecialchars($subjectName); ?></em>.</p>
    <p style="font-size:.875rem;margin:0;">Create more exams for this subject to build up a question bank.</p>
  </div>
  <?php elseif (empty($allSourceQ)): ?>
  <div style="text-align:center;padding:48px;color:#718096;">
    <p style="font-size:.95rem;">Other <?php echo htmlspecialchars($subjectName); ?> exams exist but have no active questions yet.</p>
  </div>
  <?php else: ?>

  <!-- Info banner -->
  <div style="padding:8px 16px;background:#ebf8ff;border-bottom:1px solid #bee3f8;
              font-size:.82rem;color:#2b6cb0;display:flex;gap:16px;flex-wrap:wrap;align-items:center;">
    <span>&#128218; <strong><?php echo $totalSource; ?></strong> available questions from <strong><?php echo count($sourceExams); ?></strong> other exam<?php echo count($sourceExams)!==1?'s':''; ?> in <strong><?php echo htmlspecialchars($subjectName); ?></strong></span>
    <span style="margin-left:auto;">
      <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;">
        &#9888; Already imported
      </span>
      = already exists in this exam (will be skipped on import)
    </span>
  </div>

  <!-- Filter bar -->
  <div class="filter-bar">
    <input type="text" id="qSearch" placeholder="&#128269; Search question text…"
           oninput="applyFilters()" style="flex:1;min-width:200px;max-width:380px;">

    <select id="fExam" onchange="applyFilters()" style="min-width:160px;">
      <option value="">All exams</option>
      <?php foreach ($sourceExams as $se): ?>
      <option value="<?php echo (int)$se['ExamInfoId']; ?>">
        <?php echo htmlspecialchars($se['ExamName']); ?>
      </option>
      <?php endforeach; ?>
    </select>

    <select id="fType" onchange="applyFilters()">
      <option value="">All types</option>
      <option value="MCQ">Multiple Choice</option>
      <option value="DROPDOWN">Sentence Completion</option>
      <option value="YESNO">Yes / No Grid</option>
    </select>

    <select id="fComp" onchange="applyFilters()">
      <option value="">All complexity</option>
      <option value="Low">Low</option>
      <option value="Medium">Medium</option>
      <option value="High">High</option>
    </select>

    <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer;white-space:nowrap;">
      <input type="checkbox" id="hideAlready" onchange="applyFilters()">
      Hide already imported
    </label>

    <span id="filterCount" style="font-size:.82rem;color:#718096;white-space:nowrap;margin-left:auto;"></span>
  </div>

  <!-- The form wraps the table + sticky import bar -->
  <form method="post" id="importForm">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="examId"     value="<?php echo $examId; ?>">

    <!-- Table -->
    <div style="overflow-x:auto;">
      <table class="bank-tbl" id="bankTable">
        <colgroup>
          <col class="col-chk">
          <col class="col-src">
          <col class="col-q">
          <col class="col-type">
          <col class="col-comp">
          <col class="col-corr">
        </colgroup>
        <thead>
          <tr>
            <th class="col-chk">
              <input type="checkbox" id="selectAll" title="Select all visible"
                     onchange="toggleAll(this)" style="transform:scale(1.25);cursor:pointer;">
            </th>
            <th class="col-src">Source Exam</th>
            <th class="col-q">Question</th>
            <th class="col-type">Type</th>
            <th class="col-comp">Complexity</th>
            <th class="col-corr">Correct</th>
          </tr>
        </thead>
        <tbody id="bankBody">
        <?php
        $labels = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
        foreach ($allSourceQ as $i => $q):
            $qid       = (int)$q['QuestionId'];
            $qtype     = $q['QuestionType'] ?? 'MCQ';
            $comp      = $q['Complexity']   ?? 'Medium';
            $already   = $q['_alreadyIn'];
            $correctRaw= ltrim(str_ireplace('Answer', '', $q['CorrectAnswer'] ?? ''));
            $correctLbl= $labels[$correctRaw] ?? '';
            $answerText= $q['Answer'.$correctRaw] ?? '';
            $typeLabel = ['MCQ'=>'Multiple Choice','DROPDOWN'=>'Sentence Comp.','YESNO'=>'Yes/No Grid'][$qtype] ?? $qtype;
            $typeBadge = strtolower(str_replace(' ','',['MCQ'=>'mcq','DROPDOWN'=>'dropdown','YESNO'=>'yesno'][$qtype] ?? 'mcq'));

            // Build YESNO pattern
            $ynPattern = '';
            if ($qtype === 'YESNO') {
                $ns = max(1,min(4,(int)($q['NumStatements']??4)));
                $p  = [];
                for ($s = 1; $s <= $ns; $s++) $p[] = ($q['YesNo'.$s] ?? '?');
                $ynPattern = implode('/',  $p);
            }
        ?>
        <tr class="<?php echo $already ? 'already-in' : ''; ?>"
            data-exam="<?php echo (int)$q['SourceExamId']; ?>"
            data-type="<?php echo htmlspecialchars($qtype); ?>"
            data-comp="<?php echo htmlspecialchars($comp); ?>"
            data-already="<?php echo $already ? '1' : '0'; ?>"
            data-text="<?php echo htmlspecialchars(strtolower($q['QuestionDesc'] ?? ''), ENT_QUOTES); ?>">

          <!-- Checkbox -->
          <td class="col-chk" style="text-align:center;">
            <?php if (!$already): ?>
            <input type="checkbox" name="qids[]" value="<?php echo $qid; ?>"
                   onchange="updateSelCount()" style="transform:scale(1.25);cursor:pointer;">
            <?php else: ?>
            <span title="Already in this exam" style="color:#d97706;font-size:1rem;">&#10003;</span>
            <?php endif; ?>
          </td>

          <!-- Source exam -->
          <td class="col-src" style="font-size:.8rem;color:#4a5568;font-weight:600;">
            <?php echo htmlspecialchars($q['SourceExamName'] ?? ''); ?>
          </td>

          <!-- Question text / image -->
          <td class="col-q">
            <?php if (($q['ImageInd'] ?? 'N') === 'Y' && !empty($q['ImageLoc'])): ?>
              <img src="../Admin/<?php echo htmlspecialchars($q['ImageLoc']); ?>"
                   style="max-height:36px;border-radius:3px;" alt="">
            <?php else: ?>
              <span class="q-text"
                    title="<?php echo htmlspecialchars($q['QuestionDesc'] ?? ''); ?>">
                <?php echo htmlspecialchars($q['QuestionDesc'] ?? ''); ?>
              </span>
              <?php if ($already): ?>
              <span class="badge-already">&#9888; Already in exam</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>

          <!-- Type -->
          <td class="col-type" style="text-align:center;">
            <span class="qbadge badge-<?php echo $typeBadge; ?>"><?php echo $typeLabel; ?></span>
          </td>

          <!-- Complexity -->
          <td class="col-comp" style="text-align:center;">
            <span class="qbadge badge-<?php echo strtolower($comp); ?>"><?php echo $comp; ?></span>
          </td>

          <!-- Correct answer -->
          <td class="col-corr" style="text-align:center;">
            <?php if ($qtype === 'YESNO'): ?>
              <span style="font-size:.78rem;color:#276749;font-weight:700;"><?php echo htmlspecialchars($ynPattern); ?></span>
            <?php elseif ($correctLbl): ?>
              <strong style="color:#276749;"><?php echo $correctLbl; ?></strong>
              <span style="display:block;font-size:.7rem;color:#718096;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:64px;margin:0 auto;">
                <?php echo htmlspecialchars(mb_substr($answerText,0,12).(mb_strlen($answerText)>12?'…':'')); ?>
              </span>
            <?php else: ?>
              <span style="color:#94a3b8;font-size:.78rem;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Empty state after filtering -->
    <div id="noResults" style="display:none;text-align:center;padding:32px;color:#718096;font-size:.875rem;">
      No questions match your filters.
    </div>

    <!-- Sticky import bar -->
    <div class="import-bar">
      <span class="sel-count" id="selCount">0 selected</span>
      <button type="submit" id="importBtn"
              class="btn btn-success"
              style="font-size:.95rem;padding:8px 24px;font-weight:700;"
              disabled
              onclick="return confirmImport()">
        &#128279; Link Selected
      </button>
      <span style="font-size:.8rem;opacity:.75;">
        Links questions into <em><?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?></em> — no data is duplicated.
        Questions already linked are skipped.
      </span>
      <a href="questions.php?examId=<?php echo $examId; ?>"
         class="btn btn-secondary btn-sm" style="margin-left:auto;">
        Cancel
      </a>
    </div>

  </form>

  <?php endif; ?>
</div>

<script>
var totalVisible = <?php echo $totalSource; ?>;

function applyFilters() {
    var q       = document.getElementById('qSearch').value.trim().toLowerCase();
    var fExam   = document.getElementById('fExam').value;
    var fType   = document.getElementById('fType').value;
    var fComp   = document.getElementById('fComp').value;
    var hideAlr = document.getElementById('hideAlready').checked;

    var rows    = document.querySelectorAll('#bankBody tr');
    var visible = 0;

    rows.forEach(function(row) {
        var matchQ    = !q     || row.dataset.text.indexOf(q) !== -1;
        var matchExam = !fExam || row.dataset.exam === fExam;
        var matchType = !fType || row.dataset.type === fType;
        var matchComp = !fComp || row.dataset.comp === fComp;
        var matchAlr  = !hideAlr || row.dataset.already === '0';

        var show = matchQ && matchExam && matchType && matchComp && matchAlr;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    totalVisible = visible;
    var countEl = document.getElementById('filterCount');
    if (countEl) countEl.textContent = 'Showing ' + visible + ' of <?php echo $totalSource; ?>';

    var noRes = document.getElementById('noResults');
    if (noRes) noRes.style.display = visible === 0 ? '' : 'none';

    // Deselect hidden rows
    rows.forEach(function(row) {
        if (row.style.display === 'none') {
            var cb = row.querySelector('input[type=checkbox]');
            if (cb) cb.checked = false;
        }
    });
    updateSelCount();
    document.getElementById('selectAll').checked = false;
}

function toggleAll(masterCb) {
    var rows = document.querySelectorAll('#bankBody tr');
    rows.forEach(function(row) {
        if (row.style.display !== 'none' && row.dataset.already === '0') {
            var cb = row.querySelector('input[type=checkbox][name="qids[]"]');
            if (cb) cb.checked = masterCb.checked;
        }
    });
    updateSelCount();
}

function updateSelCount() {
    var n = document.querySelectorAll('input[name="qids[]"]:checked').length;
    var el  = document.getElementById('selCount');
    var btn = document.getElementById('importBtn');
    if (el)  el.textContent = n + ' question' + (n !== 1 ? 's' : '') + ' selected';
    if (btn) btn.disabled = (n === 0);
}

function confirmImport() {
    var n = document.querySelectorAll('input[name="qids[]"]:checked').length;
    return confirm('Link ' + n + ' question' + (n!==1?'s':'') + ' into "<?php echo addslashes(htmlspecialchars($exam['ExamName'] ?? '')); ?>"?\n\nQuestions will be referenced (not copied). Changes to the originals are reflected automatically.');
}

// Init
applyFilters();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
