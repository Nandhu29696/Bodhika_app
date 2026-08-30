<?php
/**
 * exam/questions.php — Admin: list & manage questions for a specific exam.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$isFullAdmin = Auth::isAdmin();
$isInstAdmin = Auth::isInstituteAdmin();
if (!$isFullAdmin && !$isInstAdmin) { header('Location: search.php'); exit; }

$examId = filter_input(INPUT_GET, 'examId', FILTER_VALIDATE_INT)
       ?: filter_input(INPUT_POST, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

/* ── Institute-Admin scoping ───────────────────────────────────────────────
   An Institute-Admin may only VIEW questions for an exam that belongs to
   their own institute (Auth::currentInstituteId()) — never another
   institute's or a global exam. Question mutation (toggle/delete/restore
   below) stays full-Admin-only regardless: many questions here are linked
   from shared question banks, not owned by any one institute, so letting
   an Institute-Admin delete/deactivate one could silently break every
   OTHER exam (across every other institute) that links the same question. */
if ($isInstAdmin && !$isFullAdmin) {
    $myInstId = Auth::currentInstituteId();
    $examInst = (int)(Database::fetchOne(
        "SELECT ExamInstituteId FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]
    )['ExamInstituteId'] ?? 0);
    if (!$myInstId || $examInst !== $myInstId) { header('Location: search.php'); exit; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        (isset($_POST['toggle_qid']) || isset($_POST['delete_qid']) || isset($_POST['restore_qid']))) {
        http_response_code(403);
        die('Only a full Admin can edit or remove questions here. Institute-Admins can view questions and use "Build from Bank" to add more.');
    }
}

/* ── Handle toggle active/inactive ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_qid'])) {
    Auth::validateCsrf();
    $qid      = (int)$_POST['toggle_qid'];
    $newState = $_POST['new_state'] === 'Y' ? 'Y' : 'N';
    /* After migration_v22: IsActive lives on exam_questions (per-exam flag).
       Fall back to updating questions.IsActive for pre-migration schemas.  */
    try {
        Database::execute(
            "UPDATE exam_questions SET IsActive=? WHERE ExamInfoId=? AND QuestionId=?",
            [$newState, $examId, $qid]);
    } catch (Exception $e) {
        try {
            Database::execute(
                "UPDATE questions SET IsActive=? WHERE QuestionId=? AND ExamInfoId=?",
                [$newState, $qid, $examId]);
        } catch (Exception $e2) { /* run migration_v4.sql */ }
    }
    $pg = (int)($_POST['page'] ?? 1);
    header('Location: questions.php?examId='.$examId.'&page='.$pg); exit;
}

/* ── Handle soft delete ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_qid'])) {
    Auth::validateCsrf();
    $qid = (int)$_POST['delete_qid'];
    try {
        Database::execute(
            "UPDATE questions SET IsDeleted='Y', DeletedAt=NOW(), DeletedBy=? WHERE QuestionId=?",
            [Auth::currentUser() ?: 'admin', $qid]
        );
    } catch (Exception $e) { /* migration_v43 not yet run — run it to enable soft delete */ }
    $pg = (int)($_POST['page'] ?? 1);
    header('Location: questions.php?examId='.$examId.'&page='.$pg.'&deleted=1'); exit;
}

/* ── Handle restore (from the trash view) ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_qid'])) {
    Auth::validateCsrf();
    $qid = (int)$_POST['restore_qid'];
    try {
        Database::execute(
            "UPDATE questions SET IsDeleted='N', DeletedAt=NULL, DeletedBy=NULL WHERE QuestionId=?",
            [$qid]
        );
    } catch (Exception $e) {}
    header('Location: questions.php?examId='.$examId.'&trash=1&restored=1'); exit;
}

/* ── Trash view toggle: show soft-deleted questions instead of active ones ── */
$showTrash = (bool)filter_input(INPUT_GET, 'trash', FILTER_VALIDATE_INT);

/* ── Load exam info ───────────────────────────────────────────────────────── */
$exam = Database::fetchOne(
    "SELECT e.*, g.GradeName, s.SubjectName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId  = e.SubjectInfoId
      WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: search.php'); exit; }

/* ── Load questions via exam_questions join table (migration_v22+) ───────────
   This mirrors the query exam/write.php uses to serve the exam to students,
   so a question a student sees is always a question the admin can see here.
   $showTrash flips the IsDeleted filter: normal view shows only 'N' (not
   deleted); the trash view (?trash=1) shows only 'Y' (soft-deleted) so
   admins can review and restore them.

   IMPORTANT: this used to be a try/catch cascade that treated *any*
   PDOException (a genuinely missing optional column, a typo, anything) as
   "old pre-migration schema" and silently fell back to filtering on the
   legacy single-exam `questions.ExamInfoId` column instead of the
   `exam_questions` link table. That fallback is wrong for any question
   linked to this exam via Bulk Upload or "Import from Bank" — those only
   ever create a row in `exam_questions`; `questions.ExamInfoId` still
   points at whichever exam the question was originally created for. So an
   exam built entirely from bulk-uploaded / bank-imported questions would
   silently show "No questions yet" here, while exam/write.php (which reads
   `exam_questions` first and doesn't reference the optional columns below)
   correctly served all of them to students.

   Fix: detect which optional columns actually exist (once, memoized) and
   build the query around what's really there, instead of guessing schema
   age from a caught exception. */
$deletedFlag = $showTrash ? 'Y' : 'N';

$hasExamQuestions = Database::tableExists('exam_questions');
$hasQuestionHtml  = Database::hasColumn('questions', 'QuestionHtml');
$hasIsDeleted     = Database::hasColumn('questions', 'IsDeleted');
$hasDeletedMeta   = $hasIsDeleted
                  && Database::hasColumn('questions', 'DeletedAt')
                  && Database::hasColumn('questions', 'DeletedBy');
$hasMatchCorrect  = Database::hasColumn('answers', 'MatchCorrect1');
$hasCaseStudy     = Database::hasColumn('questions', 'CaseStudyId') && Database::tableExists('case_studies');
$hasSubjectInfoId = Database::hasColumn('questions', 'SubjectInfoId'); // migration_v54
$subjectInfoIdCol = $hasSubjectInfoId ? 'q.SubjectInfoId' : 'NULL AS SubjectInfoId';
$isMultiSubjectExam = $hasSubjectInfoId && (($exam['IsMultiSubject'] ?? 'N') === 'Y');

// Subject sections (for the filter dropdown + per-row subject label below)
$examSubjectNames = [];   // SubjectInfoId => name, only populated for a multi-subject exam
if ($isMultiSubjectExam) {
    try {
        foreach (Database::fetchAll(
            "SELECT es.SubjectInfoId, COALESCE(es.SectionLabel, sub.SubjectName) AS Label
               FROM exam_sections es
          LEFT JOIN subjectinfo sub ON sub.SubjectInfoId = es.SubjectInfoId
              WHERE es.ExamInfoId = ?
              ORDER BY es.SortOrder, es.ExamSectionId", [$examId]) as $sr) {
            $examSubjectNames[(int)$sr['SubjectInfoId']] = $sr['Label'];
        }
    } catch (Exception $e) { $isMultiSubjectExam = false; }
}

if ($hasExamQuestions) {
    $questionHtmlCol = $hasQuestionHtml ? 'q.QuestionHtml' : 'NULL AS QuestionHtml';
    $deletedMetaCols = $hasDeletedMeta  ? 'q.DeletedAt, q.DeletedBy' : 'NULL AS DeletedAt, NULL AS DeletedBy';
    $matchCorrectCols= $hasMatchCorrect
        ? 'a.MatchCorrect1, a.MatchCorrect2, a.MatchCorrect3, a.MatchCorrect4'
        : 'NULL AS MatchCorrect1, NULL AS MatchCorrect2, NULL AS MatchCorrect3, NULL AS MatchCorrect4';
    $isDeletedExpr   = $hasIsDeleted ? "COALESCE(q.IsDeleted,'N')" : "'N'";
    $caseStudyCols   = $hasCaseStudy
        ? 'q.CaseStudyId, cs.Title AS CaseStudyTitle'
        : 'NULL AS CaseStudyId, NULL AS CaseStudyTitle';
    $caseStudyJoin   = $hasCaseStudy ? 'LEFT JOIN case_studies cs ON cs.CaseStudyId = q.CaseStudyId' : '';

    try {
        $questions = Database::fetchAll(
            "SELECT q.QuestionId,
                    NULL                                     AS LinkedFromQuestionId,
                    NULL                                     AS SourceExamInfoId,
                    q.QuestionDesc, $questionHtmlCol,
                    q.ImageInd, q.ImageLoc, q.OperatorInd,
                    q.CorrectAnswer, $subjectInfoIdCol,
                    COALESCE(q.Complexity,    'Medium')      AS Complexity,
                    COALESCE(eq.IsActive,     'Y')           AS IsActive,
                    COALESCE(q.QuestionType,  'MCQ')         AS QuestionType,
                    $deletedMetaCols,
                    $caseStudyCols,
                    a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                    a.YesNo1,  a.YesNo2,  a.YesNo3,  a.YesNo4,
                    COALESCE(a.NumStatements, 4)             AS NumStatements,
                    $matchCorrectCols
               FROM exam_questions eq
               JOIN questions q  ON q.QuestionId  = eq.QuestionId
          LEFT JOIN answers   a  ON a.QuestionId  = q.QuestionId
                    $caseStudyJoin
              WHERE eq.ExamInfoId = ? AND $isDeletedExpr = ?
              ORDER BY q.QuestionId",
            [$examId, $deletedFlag]);
    } catch (Exception $e) {
        /* A real, unexpected DB error (not a schema-age guess) — log it and
           show an empty list rather than silently rendering wrong data. */
        error_log('questions.php: exam_questions query failed for ExamInfoId=' . $examId . ': ' . $e->getMessage());
        $questions = [];
    }
} elseif (Database::hasColumn('questions', 'LinkedFromQuestionId')) {
    /* exam_questions table not yet created (migration_v22 not run) — legacy
       dual-JOIN, still deleted-aware where possible. */
    try {
        $questions = Database::fetchAll(
            "SELECT q.QuestionId,
                    q.LinkedFromQuestionId,
                    sq.ExamInfoId                                        AS SourceExamInfoId,
                    COALESCE(sq.QuestionDesc,  q.QuestionDesc)          AS QuestionDesc,
                    COALESCE(sq.ImageInd,      q.ImageInd)              AS ImageInd,
                    COALESCE(sq.ImageLoc,      q.ImageLoc)              AS ImageLoc,
                    COALESCE(sq.OperatorInd,   q.OperatorInd)           AS OperatorInd,
                    COALESCE(sq.CorrectAnswer, q.CorrectAnswer)         AS CorrectAnswer,
                    $subjectInfoIdCol,
                    COALESCE(sq.Complexity,    q.Complexity,  'Medium') AS Complexity,
                    COALESCE(sq.IsActive,      q.IsActive,    'Y')      AS IsActive,
                    COALESCE(sq.QuestionType,  q.QuestionType,'MCQ')    AS QuestionType,
                    q.DeletedAt, q.DeletedBy,
                    a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                    a.YesNo1,  a.YesNo2,  a.YesNo3,  a.YesNo4,
                    COALESCE(a.NumStatements, 4) AS NumStatements,
                    a.MatchCorrect1, a.MatchCorrect2, a.MatchCorrect3, a.MatchCorrect4
               FROM questions q
          LEFT JOIN questions sq ON sq.QuestionId = q.LinkedFromQuestionId
          LEFT JOIN answers   a  ON a.QuestionId  = COALESCE(q.LinkedFromQuestionId, q.QuestionId)
              WHERE q.ExamInfoId = ? AND COALESCE(q.IsDeleted,'N') = ?
              ORDER BY q.QuestionId",
            [$examId, $deletedFlag]);
    } catch (Exception $e2) {
        error_log('questions.php: legacy dual-JOIN query failed for ExamInfoId=' . $examId . ': ' . $e2->getMessage());
        $questions = [];
    }
} else {
    /* Oldest possible schema: single-exam FK on questions, no IsDeleted concept. */
    if ($showTrash) {
        $questions = [];
    } else {
        $questions = Database::fetchAll(
            "SELECT q.QuestionId,
                    NULL AS LinkedFromQuestionId, NULL AS SourceExamInfoId,
                    q.QuestionDesc, q.ImageInd, q.ImageLoc, q.OperatorInd, q.CorrectAnswer,
                    $subjectInfoIdCol,
                    COALESCE(q.Complexity,'Medium') AS Complexity,
                    COALESCE(q.IsActive,'Y') AS IsActive, 'MCQ' AS QuestionType,
                    NULL AS DeletedAt, NULL AS DeletedBy,
                    a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                    NULL AS YesNo1, NULL AS YesNo2, NULL AS YesNo3, NULL AS YesNo4, 4 AS NumStatements
               FROM questions q
          LEFT JOIN answers a ON a.QuestionId = q.QuestionId
              WHERE q.ExamInfoId = ?  ORDER BY q.QuestionId",
            [$examId]);
    }
}

/* ── Optional filter: only this case study's questions (linked from
   case-studies.php's "view" action) ────────────────────────────────────── */
$filterCaseStudyId = filter_input(INPUT_GET, 'caseStudy', FILTER_VALIDATE_INT) ?: 0;
$filterCaseStudyTitle = '';
if ($filterCaseStudyId > 0) {
    $questions = array_values(array_filter(
        $questions, fn($q) => (int)($q['CaseStudyId'] ?? 0) === $filterCaseStudyId));
    $csRow = Database::fetchOne(
        "SELECT Title FROM case_studies WHERE CaseStudyId = ? AND ExamInfoId = ? LIMIT 1",
        [$filterCaseStudyId, $examId]);
    $filterCaseStudyTitle = $csRow['Title'] ?? '';
}

/* ── Optional filter: only this subject's questions (migration_v54 —
   multi-subject exam only; a normal exam has one subject for every
   question, so the filter dropdown doesn't even render for it) ─────────── */
$filterSubjectId = filter_input(INPUT_GET, 'subject', FILTER_VALIDATE_INT) ?: 0;
if ($filterSubjectId > 0) {
    $questions = array_values(array_filter(
        $questions, fn($q) => (int)($q['SubjectInfoId'] ?? 0) === $filterSubjectId));
}

/* ── Stats ───────────────────────────────────────────────────────────────── */
$total   = count($questions);
$active  = count(array_filter($questions, fn($q) => ($q['IsActive'] ?? 'Y') === 'Y'));
$byComp  = ['Low' => 0, 'Medium' => 0, 'High' => 0];
foreach ($questions as $q) {
    $c = $q['Complexity'] ?? 'Medium';
    if (isset($byComp[$c])) $byComp[$c]++;
}

/* ── Pagination ──────────────────────────────────────────────────────────── */
$perPage    = 30;
$totalPages = max(1, (int)ceil($total / $perPage));
$curPage    = max(1, min($totalPages, (int)filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1));
$offset     = ($curPage - 1) * $perPage;
$pageRows   = array_slice($questions, $offset, $perPage);

$pageTitle = 'Manage Questions';
$pageHead  = '<style>
  .badge-low     {background:#c6efce;color:#276749;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;}
  .badge-medium  {background:#fff3cd;color:#b7791f;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;}
  .badge-high    {background:#ffc7ce;color:#c53030;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;}
  .badge-active  {background:#c6efce;color:#276749;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;}
  .badge-inactive{background:#f0f0f0;color:#718096;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;}
  .action-btn    {padding:3px 9px;border-radius:4px;font-size:.78rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;display:inline-block;white-space:nowrap;}
  .btn-edit  {background:#3182ce;color:#fff;}
  .btn-on    {background:#276749;color:#fff;}
  .btn-off   {background:#c53030;color:#fff;}

  /* Fixed-layout table so columns never exceed their declared widths */
  .q-tbl          {width:100%;border-collapse:collapse;table-layout:fixed;}
  .q-tbl th,
  .q-tbl td       {padding:8px 10px;vertical-align:middle;overflow:hidden;}
  .q-tbl thead th {background:#1a365d;color:#fff;font-size:.82rem;text-align:left;}
  .q-tbl tbody tr {border-bottom:1px solid #e2e8f0;}
  .q-tbl tbody tr:last-child {border-bottom:none;}
  .q-tbl tbody tr.odd  {background:#fff;}
  .q-tbl tbody tr.even {background:#f7fafc;}
  .q-tbl tbody tr:hover{background:#ebf8ff;}

  /* Column widths */
  .col-num   {width:42px;  text-align:center;}
  .col-q     {width:auto;}   /* takes remaining space */
  .col-corr  {width:115px; text-align:center;}
  .col-comp  {width:90px;  text-align:center;}
  .col-stat  {width:82px;  text-align:center;}
  .col-act   {width:190px; text-align:center; overflow:visible; white-space:normal;}
  .col-act .action-btn {margin:2px;}

  /* Truncated text with tooltip */
  .q-text    {white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:100%;font-size:.85rem;}

  /* Pagination */
  .pg-btn    {padding:5px 11px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;cursor:pointer;font-size:.82rem;color:#4a5568;text-decoration:none;}
  .pg-btn:hover {background:#ebf8ff;border-color:#90cdf4;}
  .pg-btn.active{background:#3182ce;color:#fff;border-color:#3182ce;font-weight:700;}
  .pg-btn.disabled{opacity:.45;pointer-events:none;}
</style>';
$useMathJax = true;
include __DIR__ . '/../includes/header.php';
?>

<?php
$imported = filter_input(INPUT_GET, 'imported', FILTER_VALIDATE_INT);
if ($imported > 0): ?>
<div style="background:#f0fff4;border:1px solid #9ae6b4;color:#276749;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;">
  &#10003; <?php echo $imported; ?> question<?php echo $imported!==1?'s':''; ?> imported from the question bank successfully.
</div>
<?php endif; ?>

<?php if (filter_input(INPUT_GET, 'deleted', FILTER_VALIDATE_INT)): ?>
<div style="background:#fff5f5;border:1px solid #feb2b2;color:#c53030;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;">
  &#128465; Question deleted. It's hidden from this exam now — restore it anytime from
  <a href="questions.php?examId=<?php echo $examId; ?>&trash=1" style="color:#c53030;text-decoration:underline;">Deleted Questions</a>.
</div>
<?php endif; ?>

<?php if (filter_input(INPUT_GET, 'restored', FILTER_VALIDATE_INT)): ?>
<div style="background:#f0fff4;border:1px solid #9ae6b4;color:#276749;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;">
  &#10003; Question restored successfully.
</div>
<?php endif; ?>

<?php $__flash = $_GET['flash'] ?? ''; if ($__flash === 'translated'): ?>
<div style="background:#f0fff4;border:1px solid #9ae6b4;color:#276749;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;">
  &#127760; Translated copy created and machine-translated. Review each question below, then
  activate the exam from <a href="manage.php?InfoId=<?php echo $examId; ?>" style="color:#276749;text-decoration:underline;">Edit Exam</a> when it's ready for students.
</div>
<?php elseif ($__flash === 'translated_unconfigured'): ?>
<div style="background:#fffbeb;border:1px solid #fbbf24;color:#92400e;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;">
  &#9888; Translated copy created, but no translation service is configured — every question/option
  below is tagged <code>[TRANSLATE:xx]</code> and still needs to be edited by hand. Activate the
  exam from <a href="manage.php?InfoId=<?php echo $examId; ?>" style="color:#92400e;text-decoration:underline;">Edit Exam</a> once translated.
</div>
<?php endif; ?>

<!-- Breadcrumb -->
<nav style="font-size:.85rem;color:#718096;margin-bottom:10px;">
  <a href="search.php"        style="color:#3182ce;text-decoration:none;">&#128196; Exams</a>
  <span style="margin:0 6px;">›</span>
  <a href="questions-hub.php" style="color:#3182ce;text-decoration:none;">&#10067; Manage Questions</a>
  <span style="margin:0 6px;">›</span>
  <span><?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?></span>
</nav>

<?php if ($filterCaseStudyId > 0): ?>
<div style="background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;
            display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
  <span>&#128220; Showing <?php echo count($questions); ?> question<?php echo count($questions)!==1?'s':''; ?>
    in case study <strong><?php echo htmlspecialchars($filterCaseStudyTitle ?: '#'.$filterCaseStudyId); ?></strong></span>
  <a href="questions.php?examId=<?php echo $examId; ?>" style="color:#065f46;text-decoration:underline;">Clear filter</a>
</div>
<?php endif; ?>

<?php if ($isMultiSubjectExam): ?>
<div style="background:#f0fdfa;border:1px solid #99f6e4;color:#115e59;
            padding:10px 16px;border-radius:6px;margin-bottom:12px;font-weight:600;
            display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <span>&#129513; Subject:</span>
  <form method="get" style="display:inline;">
    <input type="hidden" name="examId" value="<?php echo $examId; ?>">
    <?php if ($showTrash): ?><input type="hidden" name="trash" value="1"><?php endif; ?>
    <select name="subject" class="form-control" style="font-weight:600;padding:4px 8px;"
            onchange="this.form.submit()">
      <option value="0">All Subjects</option>
      <?php foreach ($examSubjectNames as $sid => $sName): ?>
        <option value="<?php echo (int)$sid; ?>" <?php echo $filterSubjectId === (int)$sid ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($sName); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php if ($filterSubjectId > 0): ?>
    <a href="questions.php?examId=<?php echo $examId; ?><?php echo $showTrash ? '&trash=1' : ''; ?>"
       style="color:#115e59;text-decoration:underline;font-weight:400;">Clear</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <!-- Header -->
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#10067; <?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?></span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($isFullAdmin): ?>
      <a href="question-edit.php?examId=<?php echo $examId; ?>"
         class="btn btn-success btn-sm" style="font-weight:700;">&#10010; Add Question</a>
      <a href="question-bank.php?examId=<?php echo $examId; ?>"
         class="btn btn-sm" style="background:#0891b2;color:#fff;font-weight:700;"
         title="Hand-pick individual questions from other exams with the same subject">
        &#128218; Import from Bank
      </a>
      <?php endif; ?>
      <a href="question-bank-builder.php?examId=<?php echo $examId; ?>"
         class="btn btn-sm" style="background:#b45309;color:#fff;font-weight:700;"
         title="Randomly add a chosen number of questions per Subject/Chapter from every Question-Bank exam — e.g. build a NEET paper from Physics/Chemistry/Botany/Zoology banks in one go">
        &#127922; Build from Bank
      </a>
      <?php if ($isFullAdmin): ?>
      <a href="../Admin/BulkUploadQuestions.php?examId=<?php echo $examId; ?>"
         class="btn btn-sm" style="background:#7c3aed;color:#fff;font-weight:700;"
         title="Import multiple questions from an Excel or CSV file">
        &#11014; Bulk Upload
      </a>
      <?php endif; ?>
      <a href="export-word.php?examId=<?php echo $examId; ?>"
         class="btn btn-sm" style="background:#2563eb;color:#fff;font-weight:700;"
         title="Generate a fully editable Word (.docx) question paper, ready to print">
        &#128196; Export as Word
      </a>
      <a href="export-pdf.php?examId=<?php echo $examId; ?>"
         class="btn btn-sm" style="background:#dc2626;color:#fff;font-weight:700;"
         title="Print-ready PDF of the question paper, and a separate PDF answer key">
        &#128196; Export as PDF
      </a>
      <?php if ($isFullAdmin): ?>
      <a href="case-studies.php?examId=<?php echo $examId; ?>"
         class="btn btn-sm" style="background:#0f766e;color:#fff;font-weight:700;"
         title="Manage case studies — shared scenarios that group several questions together">
        &#128220; Case Studies
      </a>
      <?php endif; ?>
      <a href="assign.php?examId=<?php echo $examId; ?>"
         class="btn btn-sm" style="background:#4338ca;color:#fff;font-weight:700;"
         title="Assign this exam to students or a student group">
        &#128101; Assign
      </a>
      <?php if ($isFullAdmin): ?>
      <a href="questions.php?examId=<?php echo $examId; ?><?php echo $showTrash ? '' : '&trash=1'; ?>"
         class="btn btn-sm" style="background:<?php echo $showTrash ? '#334155' : '#64748b'; ?>;color:#fff;"
         title="<?php echo $showTrash ? 'Back to active questions' : 'View soft-deleted questions'; ?>">
        <?php echo $showTrash ? '&#8592; Active Questions' : '&#128465; Deleted Questions'; ?>
      </a>
      <?php endif; ?>
      <?php if ($isFullAdmin): ?>
      <a href="questions-hub.php" class="btn btn-secondary btn-sm">&#10067; All Exams</a>
      <a href="search.php"        class="btn btn-secondary btn-sm">&#128196; Exam List</a>
      <?php else: ?>
      <a href="../Admin/InstituteAdminExams.php" class="btn btn-secondary btn-sm">&#8592; My Exams</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($showTrash): ?>
  <div style="padding:10px 16px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;font-size:.85rem;color:#334155;">
    &#128465; Showing <strong><?php echo $total; ?></strong> deleted question<?php echo $total !== 1 ? 's' : ''; ?> for this exam.
    Restore a question to bring it back into the active list.
  </div>
  <?php else: ?>
  <!-- Stats bar -->
  <div style="padding:10px 16px;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;flex-wrap:wrap;background:#f7fafc;">
    <span style="padding:4px 12px;border-radius:16px;font-weight:700;font-size:.82rem;background:#ebf8ff;color:#2b6cb0;">Total: <?php echo $total; ?></span>
    <span style="padding:4px 12px;border-radius:16px;font-weight:700;font-size:.82rem;background:#f0fff4;color:#276749;">Active: <?php echo $active; ?></span>
    <span style="padding:4px 12px;border-radius:16px;font-weight:700;font-size:.82rem;background:#fff5f5;color:#c53030;">Inactive: <?php echo $total - $active; ?></span>
    <span style="padding:4px 12px;border-radius:16px;font-weight:700;font-size:.82rem;background:#c6efce;color:#276749;">Low: <?php echo $byComp['Low']; ?></span>
    <span style="padding:4px 12px;border-radius:16px;font-weight:700;font-size:.82rem;background:#fff3cd;color:#b7791f;">Medium: <?php echo $byComp['Medium']; ?></span>
    <span style="padding:4px 12px;border-radius:16px;font-weight:700;font-size:.82rem;background:#ffc7ce;color:#c53030;">High: <?php echo $byComp['High']; ?></span>
    <span style="padding:4px 12px;border-radius:16px;font-weight:700;font-size:.82rem;background:#edf2f7;color:#4a5568;">Exam needs: <?php echo (int)($exam['NumOfQuestions'] ?? 0); ?> active</span>
    <?php if ($totalPages > 1): ?>
    <span style="margin-left:auto;padding:4px 12px;border-radius:16px;font-weight:700;font-size:.82rem;background:#e9d8fd;color:#6b46c1;">
      Page <?php echo $curPage; ?> of <?php echo $totalPages; ?>
    </span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (empty($questions)): ?>
  <div style="text-align:center;padding:40px;color:#718096;">
    <?php if ($showTrash): ?>
      No deleted questions. Everything in this exam is active.
    <?php else: ?>
      No questions yet. <a href="question-edit.php?examId=<?php echo $examId; ?>" style="color:#3182ce;">Add the first question</a>.
    <?php endif; ?>
  </div>
  <?php else: ?>

  <!-- Table -->
  <div style="overflow-x:auto;">
    <table class="q-tbl">
      <colgroup>
        <col class="col-num">
        <col class="col-q">
        <?php if ($isMultiSubjectExam): ?><col class="col-subj"><?php endif; ?>
        <col class="col-corr">
        <col class="col-comp">
        <col class="col-stat">
        <col class="col-act">
      </colgroup>
      <thead>
        <tr>
          <th class="col-num">#</th>
          <th class="col-q">Question</th>
          <?php if ($isMultiSubjectExam): ?><th class="col-subj">Subject</th><?php endif; ?>
          <th class="col-corr">Correct</th>
          <th class="col-comp">Complexity</th>
          <th class="col-stat">Status</th>
          <th class="col-act">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pageRows as $i => $q):
        $rowNum    = $offset + $i + 1;
        $isActive  = ($q['IsActive'] ?? 'Y') === 'Y';
        $comp      = $q['Complexity'] ?? 'Medium';
        $qtype     = $q['QuestionType'] ?? 'MCQ';
        $correctRaw= ltrim(str_ireplace('Answer', '', $q['CorrectAnswer'] ?? ''));  // strip legacy prefix if present
        $labels    = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
        $correctLbl= $labels[$correctRaw] ?? '';
        $answerText= $q['Answer'.$correctRaw] ?? '';

        /* Build YESNO display string from YesNo1-4 columns */
        $ynPattern = '';
        if ($qtype === 'YESNO') {
            $numStmt = max(1, min(4, (int)($q['NumStatements'] ?? 4)));
            $parts = [];
            for ($s = 1; $s <= $numStmt; $s++) {
                $v = $q['YesNo'.$s] ?? '';
                $parts[] = ($v !== '') ? $v : '?';
            }
            $ynPattern = implode(' / ', $parts);
        }

        /* Build MATCH display string from MatchCorrect1-4 columns (e.g. "B / A / D") */
        $matchPattern = '';
        if ($qtype === 'MATCH') {
            $numStmt = max(1, min(4, (int)($q['NumStatements'] ?? 4)));
            $mLabels = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
            $parts = [];
            for ($s = 1; $s <= $numStmt; $s++) {
                $v = (int)($q['MatchCorrect'.$s] ?? 0);
                $parts[] = $v ? ($mLabels[$v] ?? '?') : '?';
            }
            $matchPattern = implode(' / ', $parts);
        }

        /* Build MULTI display string from comma-separated CorrectAnswer (e.g. "2,4" -> "B, D").
           Single-answer MCQ/DROPDOWN use $correctLbl below; MULTI stores >=2 option numbers
           in one column, which doesn't match that single-letter lookup. */
        $multiPattern = '';
        if ($qtype === 'MULTI') {
            $multiParts = array_filter(array_map('trim', explode(',', $q['CorrectAnswer'] ?? '')));
            $multiPattern = implode(', ', array_map(fn($p) => $labels[$p] ?? '?', $multiParts));
        }
      ?>
      <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>"
          style="<?php echo !$isActive ? 'opacity:.5;' : ''; ?>">

        <!-- # -->
        <td class="col-num" style="font-weight:700;color:#718096;"><?php echo $rowNum; ?></td>

        <!-- Question text / image -->
        <td class="col-q">
          <?php if (!empty($q['CaseStudyId'])): ?>
            <span style="display:inline-block;background:#e0e7ff;color:#3730a3;font-size:.68rem;font-weight:700;
                         padding:1px 7px;border-radius:8px;margin-bottom:2px;" title="Part of this case study">
              &#128220; <?php echo htmlspecialchars($q['CaseStudyTitle'] ?: 'Case study #'.$q['CaseStudyId']); ?>
            </span><br>
          <?php endif; ?>
          <?php if (($q['ImageInd'] ?? 'N') === 'Y' && !empty($q['ImageLoc'])): ?>
            <img src="../Admin/<?php echo htmlspecialchars(str_replace(' ','',$q['ImageLoc'])); ?>"
                 style="max-height:40px;border-radius:3px;" alt="">
          <?php elseif (!empty($q['QuestionHtml'])): ?>
            <span class="q-text"><?php echo $q['QuestionHtml']; ?></span>
          <?php else: ?>
            <span class="q-text" title="<?php echo htmlspecialchars($q['QuestionDesc'] ?? ''); ?>">
              <?php echo htmlspecialchars($q['QuestionDesc'] ?? ''); ?>
            </span>
          <?php endif; ?>
        </td>

        <?php if ($isMultiSubjectExam): ?>
        <!-- Subject (migration_v54) -->
        <td class="col-subj">
          <?php $qSubjId = (int)($q['SubjectInfoId'] ?? 0); ?>
          <?php if ($qSubjId && isset($examSubjectNames[$qSubjId])): ?>
            <span style="display:inline-block;background:#ccfbf1;color:#115e59;font-size:.75rem;font-weight:700;
                         padding:2px 9px;border-radius:10px;"><?php echo htmlspecialchars($examSubjectNames[$qSubjId]); ?></span>
          <?php else: ?>
            <span style="color:#c53030;font-size:.75rem;font-weight:700;" title="Edit this question and pick a subject">&#9888; Unset</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>

        <!-- Correct answer -->
        <td class="col-corr">
          <?php if ($qtype === 'YESNO'): ?>
            <?php if ($ynPattern && strpos($ynPattern,'?') === false): ?>
              <strong style="color:#276749;font-size:.85rem;letter-spacing:1px;"><?php echo htmlspecialchars($ynPattern); ?></strong>
              <span style="font-size:.7rem;color:#718096;display:block;">Yes/No grid</span>
            <?php else: ?>
              <span style="color:#c53030;font-size:.78rem;">Not set</span>
            <?php endif; ?>
          <?php elseif ($qtype === 'MATCH'): ?>
            <?php if ($matchPattern && strpos($matchPattern,'?') === false): ?>
              <strong style="color:#276749;font-size:.85rem;letter-spacing:1px;"><?php echo htmlspecialchars($matchPattern); ?></strong>
              <span style="font-size:.7rem;color:#718096;display:block;">Match pairs</span>
            <?php else: ?>
              <span style="color:#c53030;font-size:.78rem;">Not set</span>
            <?php endif; ?>
          <?php elseif ($qtype === 'MULTI'): ?>
            <?php if ($multiPattern && strpos($multiPattern,'?') === false): ?>
              <strong style="color:#276749;font-size:.85rem;letter-spacing:1px;"><?php echo htmlspecialchars($multiPattern); ?></strong>
              <span style="font-size:.7rem;color:#718096;display:block;">Multi-correct</span>
            <?php else: ?>
              <span style="color:#c53030;font-size:.78rem;">Not set</span>
            <?php endif; ?>
          <?php elseif ($correctLbl): ?>
            <strong style="color:#276749;font-size:.95rem;"><?php echo $correctLbl; ?></strong>
            <span style="font-size:.72rem;color:#718096;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:90px;margin:0 auto;">
              <?php echo htmlspecialchars(mb_substr($answerText,0,18).(mb_strlen($answerText)>18?'…':'')); ?>
            </span>
          <?php else: ?>
            <span style="color:#c53030;font-size:.78rem;">Not set</span>
          <?php endif; ?>
        </td>

        <!-- Complexity -->
        <td class="col-comp">
          <span class="badge-<?php echo strtolower($comp); ?>"><?php echo $comp; ?></span>
        </td>

        <!-- Status -->
        <td class="col-stat">
          <span class="<?php echo $isActive?'badge-active':'badge-inactive'; ?>">
            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
          </span>
        </td>

        <!-- Actions -->
        <td class="col-act">
          <?php if ($showTrash): ?>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Restore this question back into the active list?');">
              <input type="hidden" name="csrf_token"  value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="restore_qid" value="<?php echo $q['QuestionId']; ?>">
              <button type="submit" class="action-btn btn-on">&#8634; Restore</button>
            </form>
            <?php if (!empty($q['DeletedAt'])): ?>
              <div style="font-size:.68rem;color:#94a3b8;margin-top:3px;">
                Deleted <?php echo htmlspecialchars(date('d M y', strtotime($q['DeletedAt']))); ?><?php echo !empty($q['DeletedBy']) ? ' by ' . htmlspecialchars($q['DeletedBy']) : ''; ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <a href="question-edit.php?examId=<?php echo $examId; ?>&qid=<?php echo $q['QuestionId']; ?>"
               class="action-btn btn-edit">Edit</a>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('<?php echo $isActive?'Deactivate':'Activate'; ?> this question?');">
              <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="toggle_qid" value="<?php echo $q['QuestionId']; ?>">
              <input type="hidden" name="new_state"   value="<?php echo $isActive ? 'N' : 'Y'; ?>">
              <input type="hidden" name="page"        value="<?php echo $curPage; ?>">
              <button type="submit" class="action-btn <?php echo $isActive?'btn-off':'btn-on'; ?>">
                <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
              </button>
            </form>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Delete this question?\n\nIt will be hidden everywhere but can be restored from the Deleted Questions view.');">
              <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="delete_qid" value="<?php echo $q['QuestionId']; ?>">
              <input type="hidden" name="page"       value="<?php echo $curPage; ?>">
              <button type="submit" class="action-btn" style="background:#dc2626;color:#fff;">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="padding:14px 16px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;border-top:1px solid #e2e8f0;">
    <a href="?examId=<?php echo $examId; ?>&page=<?php echo max(1,$curPage-1); ?>"
       class="pg-btn <?php echo $curPage<=1?'disabled':''; ?>">&#8592; Prev</a>

    <?php
    $start = max(1, $curPage - 3);
    $end   = min($totalPages, $curPage + 3);
    if ($start > 1): ?><a href="?examId=<?php echo $examId; ?>&page=1" class="pg-btn">1</a><?php
      if ($start > 2): ?><span style="padding:0 4px;color:#a0aec0;">…</span><?php endif;
    endif;
    for ($p = $start; $p <= $end; $p++): ?>
      <a href="?examId=<?php echo $examId; ?>&page=<?php echo $p; ?>"
         class="pg-btn <?php echo $p===$curPage?'active':''; ?>"><?php echo $p; ?></a>
    <?php endfor;
    if ($end < $totalPages):
      if ($end < $totalPages - 1): ?><span style="padding:0 4px;color:#a0aec0;">…</span><?php endif; ?>
      <a href="?examId=<?php echo $examId; ?>&page=<?php echo $totalPages; ?>" class="pg-btn"><?php echo $totalPages; ?></a>
    <?php endif; ?>

    <a href="?examId=<?php echo $examId; ?>&page=<?php echo min($totalPages,$curPage+1); ?>"
       class="pg-btn <?php echo $curPage>=$totalPages?'disabled':''; ?>">Next &#8594;</a>

    <span style="margin-left:auto;font-size:.82rem;color:#718096;">
      Showing <?php echo $offset+1; ?>–<?php echo min($total,$offset+$perPage); ?> of <?php echo $total; ?> questions
    </span>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
