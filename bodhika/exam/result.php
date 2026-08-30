<?php
/**
 * exam/result.php — DB-driven result viewer.
 *
 * Linked from history.php as result.php?id=<StudentExamId>.
 * Reads all data from studentexam + studentexamresults.
 * Never recalculates marks — the stored score is always the displayed score.
 *
 * Access control:
 *   Students may only view their own attempts.
 *   Admins may view any attempt.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

$seId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$seId) { header('Location: history.php'); exit; }

$myUid   = Auth::currentUserId();
$isAdmin = Auth::isAdmin();

/* ── Load the exam attempt header ───────────────────────────────────────── */
$se = Database::fetchOne(
    "SELECT se.*,
            e.ExamName, e.NumOfQuestions, e.MinPassing,
            g.GradeName, s.SubjectName
       FROM studentexam se
  LEFT JOIN examinfo    e ON e.ExamInfoId   = se.ExamInfoId
  LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
      WHERE se.StudentExamId = ? LIMIT 1",
    [$seId]);

if (!$se) { header('Location: history.php'); exit; }

/* Access-control */
if (!$isAdmin && (int)$se['UserInfoId'] !== (int)$myUid) {
    header('Location: history.php'); exit;
}

/* ── Load per-question result rows ──────────────────────────────────────── */
/* MySQL validates ALL column names at prepare() time, so a single query
   that references a missing column always crashes — even inside try/catch
   on some PHP/PDO versions.
   Solution: always run the guaranteed base query first, then layer in
   extended columns with separate queries, each in its own try/catch. */

/* Step 1 — base query: StdAnswerId is the original column, always present.
   ORDER BY QuestionId is always safe; StudentExamResultId may not exist. */
$resultRows = Database::fetchAll(
    "SELECT ser.QuestionId,
            COALESCE(ser.StdAnswerId, '') AS SelectedAnswer,
            ''  AS CorrectAnswer,
            'N' AS IsCorrect,
            0   AS EarnedMarks,
            0   AS MarksPerQ
       FROM studentexamresults ser
      WHERE ser.StudentExamId = ?
      ORDER BY ser.QuestionId",
    [$seId]);

/* Index by QuestionId for fast lookup during merges below */
$resultIndex = [];
foreach ($resultRows as $i => $rr) {
    $resultIndex[(int)$rr['QuestionId']] = $i;
}

/* Step 2 — migration_v3 columns: SelectedAnswer, CorrectAnswer, IsCorrect */
try {
    $ext3 = Database::fetchAll(
        "SELECT ser.QuestionId, ser.SelectedAnswer, ser.CorrectAnswer, ser.IsCorrect
           FROM studentexamresults ser
          WHERE ser.StudentExamId = ?",
        [$seId]);
    foreach ($ext3 as $e) {
        $i = $resultIndex[(int)$e['QuestionId']] ?? null;
        if ($i === null) continue;
        /* SelectedAnswer takes priority over StdAnswerId */
        if (($e['SelectedAnswer'] ?? '') !== '') {
            $resultRows[$i]['SelectedAnswer'] = $e['SelectedAnswer'];
        }
        $resultRows[$i]['CorrectAnswer'] = $e['CorrectAnswer'] ?? '';
        $resultRows[$i]['IsCorrect']     = $e['IsCorrect']     ?? 'N';
    }
} catch (Exception $e) { /* migration_v3 not applied yet — base values remain */ }

/* Step 3 — migration_v7 columns: EarnedMarks, MarksPerQ */
try {
    $ext7 = Database::fetchAll(
        "SELECT ser.QuestionId, ser.EarnedMarks, ser.MarksPerQ
           FROM studentexamresults ser
          WHERE ser.StudentExamId = ?",
        [$seId]);
    foreach ($ext7 as $e) {
        $i = $resultIndex[(int)$e['QuestionId']] ?? null;
        if ($i === null) continue;
        $resultRows[$i]['EarnedMarks'] = $e['EarnedMarks'] ?? 0;
        $resultRows[$i]['MarksPerQ']   = $e['MarksPerQ']   ?? 0;
    }
} catch (Exception $e) { /* migration_v7 not applied yet — 0 values remain */ }

/* ── Fetch question + answer data in batch ──────────────────────────────── */
$qids = array_column($resultRows, 'QuestionId');
if (!$qids) {
    /* No detail rows stored — show minimal summary only */
    $qids = [];
}

$questionData = [];
if ($qids) {
    $ph = implode(',', array_fill(0, count($qids), '?'));
    try {
        $qRows = Database::fetchAll(
            "SELECT q.QuestionId, q.CorrectAnswer, q.OperatorInd,
                    q.QuestionDesc, q.ImageInd, q.ImageLoc, q.NumofImages,
                    COALESCE(q.QuestionType,'MCQ') AS QuestionType,
                    a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                    a.AnsImageInd, a.MultiImageInd,
                    a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                    COALESCE(a.NumStatements,4) AS NumStatements,
                    a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4
               FROM questions q
               JOIN answers   a ON a.QuestionId = q.QuestionId
              WHERE q.QuestionId IN ($ph)",
            $qids);
    } catch (Exception $e) {
        $qRows = Database::fetchAll(
            "SELECT q.QuestionId, q.CorrectAnswer, q.OperatorInd,
                    q.QuestionDesc, q.ImageInd, q.ImageLoc, q.NumofImages,
                    'MCQ' AS QuestionType,
                    a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                    a.AnsImageInd, a.MultiImageInd,
                    NULL AS YesNo1, NULL AS YesNo2, NULL AS YesNo3, NULL AS YesNo4,
                    4    AS NumStatements,
                    NULL AS MatchStatement1, NULL AS MatchStatement2,
                    NULL AS MatchStatement3, NULL AS MatchStatement4
               FROM questions q
               JOIN answers   a ON a.QuestionId = q.QuestionId
              WHERE q.QuestionId IN ($ph)",
            $qids);
    }
    foreach ($qRows as $r) {
        $questionData[(int)$r['QuestionId']] = $r;
    }
}

/* ── Batch-load math operators ──────────────────────────────────────────── */
$mathQids    = [];
foreach ($questionData as $qid => $q) { if (($q['OperatorInd'] ?? '') === 'Y') $mathQids[] = $qid; }
$operatorMap = [];
if ($mathQids) {
    $oph = implode(',', array_fill(0, count($mathQids), '?'));
    foreach (Database::fetchAll("SELECT * FROM quesoperators WHERE QuestionId IN ($oph)", $mathQids) as $op)
        $operatorMap[$op['QuestionId']] = $op;
}

/* ── Batch-load answer images ───────────────────────────────────────────── */
$answerIds    = array_unique(array_filter(array_column(array_values($questionData), 'AnswerId')));
$answerImages = [];
if ($answerIds) {
    $aph = implode(',', array_fill(0, count($answerIds), '?'));
    foreach (Database::fetchAll(
        "SELECT AnswerId, AnswerImage1Loc, AnswerImage2Loc, AnswerImage3Loc, AnswerImage4Loc
           FROM answerimages WHERE AnswerId IN ($aph)", array_values($answerIds)) as $img)
        $answerImages[$img['AnswerId']] = $img;
}

/* ── Summary values ─────────────────────────────────────────────────────── */
$totalScore  = (int)($se['Score']          ?? 0);
$marksOutOf  = (int)($se['MarksOutOf']     ?? 100);
$description = $se['Description']          ?? '';
$timeTaken   = (int)($se['TimeTaken']      ?? 0);
$totalQ      = (int)($se['TotalQuestions'] ?? count($resultRows));
$correct     = (int)($se['CorrectCount']   ?? 0);
$wrong       = (int)($se['WrongCount']     ?? 0);
$skipped     = (int)($se['SkippedCount']   ?? 0);
$passMark    = (int)($se['MinPassing']     ?? 0);
/* Pass threshold: MinPassing is now a 0–100 percentage.
   Recalculate at display time so stale stored Description values
   (from before the MinPassing semantics change) are corrected. */
$passThreshold = min(100, max(0, $passMark));
$scorePercent  = $marksOutOf > 0 ? (int)round($totalScore / $marksOutOf * 100) : 0;
$passed        = ($passThreshold > 0 && $description !== '')
               ? ($scorePercent >= $passThreshold)
               : ($description === 'Pass');

/* If v7 counts not stored yet, derive from result rows using IsCorrect flag */
if ($correct + $wrong + $skipped === 0 && count($resultRows) > 0) {
    foreach ($resultRows as $rr) {
        $ans = $rr['SelectedAnswer'] ?? '';
        if ($ans === '') {
            $skipped++;
        } elseif (($rr['IsCorrect'] ?? 'N') === 'Y') {
            $correct++;
        } else {
            $wrong++;
        }
    }
}
/* If CorrectCount IS stored in studentexam but still 0 (edge case: all skipped
   or old schema), trust IsCorrect-derived counts over the stored zeros. */
if ($correct === 0 && $wrong === 0 && $skipped === 0 && count($resultRows) > 0) {
    foreach ($resultRows as $rr) {
        $ans = $rr['SelectedAnswer'] ?? '';
        if ($ans === '') $skipped++;
        elseif (($rr['IsCorrect'] ?? 'N') === 'Y') $correct++;
        else $wrong++;
    }
}

/* marksPerQ: prefer stored (most accurate when TotalQ exists), else compute */
$marksPerQ = ($totalQ > 0) ? (100.0 / $totalQ) : ($marksOutOf > 0 ? $marksOutOf : 0);

$examDate = (isset($se['ExamDate']) && $se['ExamDate']) ? $se['ExamDate'] : ($se['CreateDate'] ?? null);

/* ── Image path resolver ─────────────────────────────────────────────────── */
function resolveImgPath(string $raw): string {
    $raw = str_replace(' ', '', trim($raw));
    if ($raw === '') return '';
    if (strpos($raw, 'http') === 0 || strpos($raw, '//') === 0 || strpos($raw, '/') === 0) return $raw;
    if (strpos($raw, '../') === 0  || strpos($raw, './') === 0) return $raw;
    return '../Admin/' . $raw;
}

$pageTitle = 'Exam Result';
$pageHead  = '<style>
  .result-banner{display:flex;align-items:center;gap:14px;padding:10px 18px;
                 border-radius:8px;flex-wrap:wrap;margin-bottom:16px;}
  .result-pass{background:#ecfdf5;border:2px solid #059669;}
  .result-fail{background:#fef2f2;border:2px solid #ef4444;}
  .result-verdict{font-size:.95rem;font-weight:900;letter-spacing:3px;
                  padding:6px 16px;border-radius:6px;flex-shrink:0;}
  .result-pass .result-verdict{background:#059669;color:#fff;}
  .result-fail .result-verdict{background:#ef4444;color:#fff;}
  .stat-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .stat-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 11px;
             border-radius:14px;font-size:.8rem;font-weight:600;
             background:rgba(255,255,255,.85);border:1px solid rgba(0,0,0,.08);color:#334155;}
  .stat-chip strong{color:#0f172a;}

  .res-card{border:2px solid #e2e8f0;border-radius:8px;margin-bottom:14px;overflow:hidden;}
  .res-card.q-correct{border-color:#059669;}
  .res-card.q-partial{border-color:#d97706;}
  .res-card.q-wrong  {border-color:#ef4444;}
  .res-card.q-skipped{border-color:#94a3b8;}
  .res-card-hdr{padding:9px 14px;display:flex;align-items:center;gap:10px;color:#fff;flex-wrap:wrap;}
  .res-card.q-correct .res-card-hdr{background:#059669;}
  .res-card.q-partial .res-card-hdr{background:#d97706;}
  .res-card.q-wrong   .res-card-hdr{background:#ef4444;}
  .res-card.q-skipped .res-card-hdr{background:#64748b;}
  .res-q-num{font-weight:900;font-size:.88rem;min-width:28px;height:28px;border-radius:50%;
             background:rgba(255,255,255,.25);display:flex;align-items:center;
             justify-content:center;flex-shrink:0;}
  .res-q-type{font-size:.7rem;padding:2px 9px;border-radius:10px;
              background:rgba(255,255,255,.2);font-weight:600;}
  .res-status{font-weight:700;font-size:.88rem;}
  .res-marks{margin-left:auto;font-size:.82rem;font-weight:700;white-space:nowrap;}
  .res-card-body{padding:14px 16px;background:#fff;}
  .res-q-text{font-size:.9rem;line-height:1.5;color:#1e1b4b;margin-bottom:12px;}
  .res-q-img-wrap{margin:0 0 12px;}
  .res-q-img{max-width:100%;max-height:280px;border-radius:6px;border:1px solid #e2e8f0;display:block;}
  .res-opt-img{max-width:100px;vertical-align:middle;border-radius:3px;border:1px solid #e2e8f0;}

  .res-mcq-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
  @media(max-width:640px){.res-mcq-grid{grid-template-columns:1fr;}}
  .res-opt{display:flex;align-items:center;gap:10px;padding:10px 12px;
           border:2px solid #e2e8f0;border-radius:6px;background:#fff;font-size:.88rem;}
  .res-opt.res-correct{border-color:#059669;background:#dcfce7;}
  .res-opt.res-wrong  {border-color:#ef4444;background:#fee2e2;}
  .res-opt.res-miss   {border-color:#d97706;background:#fffbeb;}
  .res-opt-badge{font-weight:800;min-width:28px;height:28px;border-radius:50%;
                 background:#1e1b4b;color:#fff;display:flex;align-items:center;
                 justify-content:center;font-size:.82rem;flex-shrink:0;}
  .res-opt.res-correct .res-opt-badge{background:#059669;}
  .res-opt.res-wrong   .res-opt-badge{background:#ef4444;}
  .res-opt.res-miss    .res-opt-badge{background:#d97706;}

  .yn-res-tbl{width:100%;border-collapse:collapse;font-size:.875rem;}
  .yn-res-tbl th{background:#1e1b4b;color:#e0e7ff;padding:7px 12px;font-size:.82rem;}
  .yn-res-tbl th:first-child{text-align:left;}
  .yn-res-tbl td{padding:8px 12px;border-bottom:1px solid #e2e8f0;vertical-align:middle;}
  .yn-res-tbl tr:last-child td{border-bottom:none;}
  .yn-badge{display:inline-flex;align-items:center;justify-content:center;
            width:30px;height:30px;border-radius:50%;font-weight:700;font-size:.8rem;}
  .yn-badge.yn-correct{background:#dcfce7;color:#065f46;border:2px solid #059669;}
  .yn-badge.yn-wrong  {background:#fee2e2;color:#991b1b;border:2px solid #ef4444;}

  .explanation-block{margin-top:12px;border-radius:6px;overflow:hidden;
                     border:1px solid #c7d2fe;background:#eef2ff;}
  .explanation-toggle{list-style:none;padding:9px 14px;cursor:pointer;font-weight:600;
                      font-size:.85rem;color:#3730a3;user-select:none;display:flex;
                      align-items:center;gap:6px;}
  .explanation-toggle::-webkit-details-marker{display:none;}
  .explanation-toggle::after{content:"\25be";margin-left:auto;transition:transform .2s;}
  details[open] .explanation-toggle::after{transform:rotate(-180deg);}
  .explanation-body{padding:10px 14px 12px;font-size:.875rem;line-height:1.6;
                    color:#1e40af;border-top:1px solid #c7d2fe;}

  /* ── One-question-at-a-time review navigator (same Prev/Next pattern as
     the live exam in write.php) — replaces scrolling through all N cards
     with a single card at a time plus a jump-to-any-question palette. */
  .q-hidden { display:none !important; }
  .qnav-bar { position:sticky;top:calc(var(--nav-h,60px) + 6px);z-index:40;
              display:flex;align-items:center;justify-content:space-between;gap:14px;
              background:#fff;border:1px solid #e2e8f0;border-radius:10px;
              padding:10px 16px;margin-bottom:14px;box-shadow:0 2px 8px rgba(0,0,0,.06);flex-wrap:wrap; }
  .qnav-side  { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
  .qnav-box   { display:inline-flex;align-items:center;gap:6px;padding:10px 20px;
                border-radius:8px;font-weight:700;font-size:.85rem;cursor:pointer;
                flex-shrink:0;transition:.15s;white-space:nowrap;border:2px solid transparent; }
  .qnav-box .qnav-ic { font-size:1.05rem;line-height:1; }
  .qnav-prev  { background:#fff;border-color:#312e81;color:#312e81; }
  .qnav-prev:hover:not(:disabled) { background:#eef2ff; }
  .qnav-next  { background:#312e81;color:#fff; }
  .qnav-next:hover:not(:disabled) { background:#4338ca; }
  .qnav-prev:disabled, .qnav-next:disabled { background:#f1f5f9;border-color:#cbd5e1;color:#94a3b8;cursor:not-allowed; }
  .qnav-count { font-size:.85rem;font-weight:800;color:#312e81;white-space:nowrap; }
  .qnav-actions { display:flex;align-items:center;gap:8px;flex-wrap:wrap; }
  /* Bottom copy of the nav bar, repeated inside each card so Prev/Next is
     reachable right after reading the explanation — no scroll back to top. */
  .qnav-bar-bottom { position:static;margin-top:16px;margin-bottom:0;box-shadow:none; }

  .qpalette-wrap { margin-bottom:14px; }
  .qpalette-legend { display:flex;gap:14px;flex-wrap:wrap;font-size:.74rem;color:#64748b;margin-bottom:8px;align-items:center; }
  .qpalette-legend b { display:inline-flex;align-items:center;gap:5px;font-weight:600; }
  .qpalette-legend i { width:13px;height:13px;border-radius:3px;display:inline-block;border:2px solid; }
  .qpalette { display:flex;flex-wrap:wrap;gap:6px;max-height:170px;overflow-y:auto;
              padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px; }
  .qpalette-btn { width:34px;height:34px;border-radius:6px;border:2px solid #cbd5e1;background:#fff;color:#475569;
                  font-weight:700;font-size:.78rem;cursor:pointer;display:flex;align-items:center;justify-content:center;
                  transition:.15s;flex-shrink:0; }
  .qpalette-btn:hover { border-color:#6366f1; }
  .qpalette-btn.qp-correct { border-color:#059669;background:#ecfdf5;color:#065f46; }
  .qpalette-btn.qp-wrong   { border-color:#ef4444;background:#fee2e2;color:#991b1b; }
  .qpalette-btn.qp-partial { border-color:#d97706;background:#fffbeb;color:#92400e; }
  .qpalette-btn.qp-skipped { border-color:#94a3b8;background:#f1f5f9;color:#475569; }
  .qpalette-btn.qp-current { border-color:#312e81;background:#312e81;color:#fff;box-shadow:0 0 0 3px #c7d2fe; }
  @media(max-width:640px){
    .qnav-bar   { top:4px; }
    .qnav-count { width:100%;order:3;text-align:center; }
    .qnav-actions { width:100%;justify-content:center; }
    .qnav-box   { flex:1;justify-content:center; }
  }
</style>';

/* Batch-load Explanation */
$explanations = [];
if ($qids) {
    $ph2 = implode(',', array_fill(0, count($qids), '?'));
    try {
        foreach (Database::fetchAll(
            "SELECT QuestionId, Explanation FROM questions WHERE QuestionId IN ($ph2)", $qids) as $ex)
            $explanations[(int)$ex['QuestionId']] = $ex['Explanation'] ?? '';
    } catch (Exception $e) { /* migration_v12 not yet applied */ }
}

function renderExplanation(string $text, int $qNum): void {
    if (trim($text) === '') return;
    echo '<details class="explanation-block" id="exp_' . $qNum . '" open>';
    echo '<summary class="explanation-toggle">&#128161; Explanation</summary>';
    echo '<div class="explanation-body">' . nl2br(htmlspecialchars($text)) . '</div>';
    echo '</details>';
}

$useMathJax = true;
include __DIR__ . '/../includes/header.php';
?>

<!-- Score Banner -->
<div class="result-banner <?php echo $passed ? 'result-pass' : 'result-fail'; ?>">
  <span class="result-verdict"><?php echo $passed ? 'PASS' : 'FAIL'; ?></span>
  <div>
    <div style="font-size:1.3rem;font-weight:900;color:<?php echo $passed?'#065f46':'#991b1b';?>;">
      <?php echo $totalScore; ?><span style="font-size:.9rem;font-weight:500;">/<?php echo $marksOutOf; ?></span>
    </div>
    <?php if ($se['ExamName'] ?? ''): ?>
    <div style="font-size:.82rem;color:#475569;margin-bottom:4px;"><?php echo htmlspecialchars($se['ExamName']); ?></div>
    <?php endif; ?>
    <div class="stat-chips">
      <span class="stat-chip">&#9989; <strong><?php echo $correct; ?></strong> correct</span>
      <span class="stat-chip">&#10060; <strong><?php echo $wrong; ?></strong> wrong</span>
      <span class="stat-chip">&#9644; <strong><?php echo $skipped; ?></strong> skipped</span>
      <?php if ($timeTaken > 0): ?>
      <span class="stat-chip">&#9201; <?php echo floor($timeTaken/60); ?>m <?php echo $timeTaken%60; ?>s</span>
      <?php endif; ?>
      <?php if ($passThreshold > 0): ?>
      <span class="stat-chip">Pass mark: <?php echo $passThreshold; ?>%</span>
      <?php endif; ?>
      <?php if ($examDate): ?>
      <span class="stat-chip">&#128197; <?php echo date('d M Y', strtotime($examDate)); ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div style="margin-left:auto;">
    <a href="history.php" class="btn btn-secondary btn-sm">&#8592; History</a>
  </div>
</div>

<?php if ($resultRows): ?>
<div class="card" style="margin-top:12px;">
  <div class="card-header">&#128203; Question Review (<?php echo count($resultRows); ?> questions)</div>
  <div class="card-body">

    <!-- Jump-to-any-question palette, colored by outcome — filled in by JS
         below from the rendered cards' status classes, then Prev/Next moves
         one question at a time, same pattern as the live exam navigator. -->
    <div class="qpalette-wrap">
      <div class="qpalette-legend">
        <b><i style="background:#312e81;border-color:#312e81;"></i> Current</b>
        <b><i style="background:#ecfdf5;border-color:#059669;"></i> Correct</b>
        <b><i style="background:#fee2e2;border-color:#ef4444;"></i> Wrong</b>
        <b><i style="background:#fffbeb;border-color:#d97706;"></i> Partial</b>
        <b><i style="background:#f1f5f9;border-color:#94a3b8;"></i> Skipped</b>
      </div>
      <div class="qpalette" id="qPalette"></div>
    </div>

    <div class="qnav-bar">
      <div class="qnav-side"><span class="qnav-count"></span></div>
      <div class="qnav-side qnav-actions">
        <button type="button" class="qnav-box qnav-prev" onclick="qPrev()" title="Previous question">
          <span class="qnav-ic">&#8249;</span> Previous
        </button>
        <button type="button" class="qnav-box qnav-next" onclick="qNext()" title="Next question">
          Next <span class="qnav-ic">&#8250;</span>
        </button>
      </div>
    </div>

<?php
$qNum = 0;
foreach ($resultRows as $rr):
    $qNum++;
    $qid    = (int)$rr['QuestionId'];
    $chosen = (string)($rr['SelectedAnswer'] ?? '');
    $corr   = (string)($rr['CorrectAnswer']  ?? '');
    $earned = (float)($rr['EarnedMarks']     ?? 0);
    $mqp    = (float)($rr['MarksPerQ']       ?? 0);
    $mqp    = $mqp > 0 ? $mqp : $marksPerQ;

    $qd      = $questionData[$qid] ?? null;
    $qtype   = $qd['QuestionType'] ?? 'MCQ';
    $expText = $explanations[$qid] ?? '';

    /* ── Recalculate earned marks when EarnedMarks is missing (migration_v7
       not yet applied). SelectedAnswer and CorrectAnswer are always stored
       (migration_v3), so we can derive the correct score from them.
       This fixes:
         (a) MCQ/DROPDOWN showing Wrong when answer was correct
         (b) YESNO partial credit showing 0 instead of proportional marks    */
    if ($earned === 0.0 && $chosen !== '') {
        $isCorrectFlag = ($rr['IsCorrect'] ?? 'N') === 'Y';

        if ($isCorrectFlag) {
            // Fully correct — award full marks
            $earned = $mqp;
        } elseif ($qtype === 'YESNO' && $corr !== '') {
            // YESNO partial credit: count matching Y/N statements
            $chosenParts  = explode(',', $chosen);
            $correctParts = explode(',', $corr);
            $numStmt      = max(1, count($correctParts));
            $correctStmts = 0;
            for ($s = 0; $s < $numStmt; $s++) {
                if (isset($chosenParts[$s]) && $chosenParts[$s] !== ''
                    && $chosenParts[$s] === ($correctParts[$s] ?? '')) {
                    $correctStmts++;
                }
            }
            if ($correctStmts > 0) {
                $earned = round($correctStmts / $numStmt * $mqp, 1);
            }
        } elseif ($qtype === 'MATCH' && $corr !== '') {
            // MATCH partial credit: count statement positions whose dragged-in
            // option number matches the correct option number for that slot.
            // "0" means that statement was left unmatched — never counts as correct.
            $chosenParts  = explode(',', $chosen);
            $correctParts = explode(',', $corr);
            $numStmt      = max(1, count($correctParts));
            $correctStmts = 0;
            for ($s = 0; $s < $numStmt; $s++) {
                $cVal = $chosenParts[$s] ?? '0';
                if ($cVal !== '0' && $cVal === ($correctParts[$s] ?? '')) {
                    $correctStmts++;
                }
            }
            if ($correctStmts > 0) {
                $earned = round($correctStmts / $numStmt * $mqp, 1);
            }
        } elseif (in_array($qtype, ['MCQ','DROPDOWN'], true) && $corr !== '') {
            // MCQ: normalise both sides and compare directly
            $toIdx = fn(string $v): int => is_numeric($v) ? (int)$v
                : (int)ltrim(str_ireplace('answer', '', strtolower(trim($v))));
            if ($toIdx($chosen) === $toIdx($corr)) {
                $earned = $mqp;
            }
        }
    } else {
        $isCorrectFlag = ($rr['IsCorrect'] ?? 'N') === 'Y';
    }

    if ($chosen === '') {
        $cardClass = 'q-skipped'; $statusLabel = 'Skipped';
    } elseif ($earned >= $mqp - 0.01) {
        $cardClass = 'q-correct'; $statusLabel = 'Correct';
    } elseif ($earned > 0) {
        $cardClass = 'q-partial'; $statusLabel = 'Partial';
    } else {
        $cardClass = 'q-wrong';   $statusLabel = 'Wrong';
    }
?>
<div class="res-card <?php echo $cardClass; ?>" id="qrow<?php echo $qNum; ?>">
  <div class="res-card-hdr">
    <span class="res-q-num"><?php echo $qNum; ?></span>
    <span class="res-status"><?php echo $statusLabel; ?></span>
    <span class="res-q-type"><?php echo $qtype; ?></span>
    <span class="res-marks">+<?php echo number_format($earned,1); ?> / <?php echo number_format($mqp,1); ?> pts</span>
  </div>
  <div class="res-card-body">
    <?php if ($qd): ?>
    <div class="res-q-text"><?php echo htmlspecialchars($qd['QuestionDesc'] ?? ''); ?></div>
    <?php if (($qd['ImageInd'] ?? 'N') === 'Y' && !empty($qd['ImageLoc'])): ?>
    <div class="res-q-img-wrap">
      <img src="<?php echo htmlspecialchars(resolveImgPath($qd['ImageLoc'])); ?>" alt="" class="res-q-img">
    </div>
    <?php endif; ?>

    <?php if ($qtype === 'MULTI'): ?>
      <?php
        $chosenSet  = $chosen !== '' ? array_map('trim', explode(',', $chosen)) : [];
        $correctSet = $corr   !== '' ? array_map('trim', explode(',', $corr))   : [];
        $ltrMap = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
      ?>
      <div class="res-mcq-grid">
      <?php foreach (['1','2','3','4'] as $n):
          $text      = $qd['Answer'.$n] ?? '';
          $hasAnsImg = ($qd['AnsImageInd'] ?? 'N') === 'Y';
          $imgSrc    = '';
          if ($hasAnsImg) {
              // MultiImageInd='N' -> every option shares the question's own image.
              $imgLoc = (($qd['MultiImageInd'] ?? 'N') === 'Y')
                  ? ($answerImages[$qd['AnswerId']]['AnswerImage'.$n.'Loc'] ?? '')
                  : ($qd['ImageLoc'] ?? '');
              $imgSrc = resolveImgPath($imgLoc ?? '');
          }
          // Skip slots with nothing to show — keeps NumOptions<4 honest for
          // image answers too, since AnsImageInd is a question-level flag.
          if ($hasAnsImg ? $imgSrc === '' : $text === '') continue;
          $isC  = in_array($n, $correctSet, true);
          $wasC = in_array($n, $chosenSet,  true);
          $cls  = ($isC && $wasC) ? 'res-correct' : (!$isC && $wasC ? 'res-wrong' : ($isC ? 'res-miss' : ''));
          $icon = ($isC && $wasC) ? '&#10004;'    : (!$isC && $wasC ? '&#10008;'  : ($isC ? '&#9733;' : ''));
      ?>
      <div class="res-opt <?php echo $cls; ?>">
        <span class="res-opt-badge"><?php echo $ltrMap[$n]; ?></span>
        <?php if ($hasAnsImg): ?>
          <?php if ($imgSrc): ?><img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="" class="res-opt-img"><?php endif; ?>
        <?php else: ?>
          <span><?php echo htmlspecialchars($text); ?></span>
        <?php endif; ?>
        <?php if ($icon): ?><span style="margin-left:auto;font-weight:900;"><?php echo $icon; ?></span><?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
      <?php if ($correctSet): ?>
      <div style="font-size:.8rem;color:#64748b;margin-top:4px;">
        &#9745; Correct: <?php echo implode(', ', array_map(fn($a) => $ltrMap[$a] ?? $a, $correctSet)); ?>
      </div>
      <?php endif; ?>

    <?php elseif ($qtype === 'YESNO'): ?>
      <?php
        $cParts  = $chosen !== '' ? explode(',', $chosen) : [];
        $rParts  = $corr   !== '' ? explode(',', $corr)   : [];
        $numStmt = max(1, (int)($qd['NumStatements'] ?? count($rParts)));
      ?>
      <table class="yn-res-tbl">
        <thead><tr>
          <th>Statement</th>
          <th style="text-align:center;width:90px;">Your Answer</th>
          <th style="text-align:center;width:90px;">Correct</th>
        </tr></thead>
        <tbody>
        <?php for ($s = 1; $s <= $numStmt; $s++):
            $stmtText = $qd['Answer'.$s] ?? '';
            if ($stmtText === '') continue;
            $stuAns   = trim($cParts[$s-1] ?? '');
            $corAns   = trim($rParts[$s-1] ?? '');
            $match    = ($stuAns !== '' && $stuAns === $corAns);
        ?>
        <tr>
          <td><?php echo htmlspecialchars($stmtText); ?></td>
          <td style="text-align:center;">
            <span class="yn-badge <?php echo $match ? 'yn-correct' : 'yn-wrong'; ?>">
              <?php echo $stuAns !== '' ? htmlspecialchars($stuAns) : '&mdash;'; ?>
            </span>
          </td>
          <td style="text-align:center;">
            <span class="yn-badge yn-correct"><?php echo htmlspecialchars($corAns); ?></span>
          </td>
        </tr>
        <?php endfor; ?>
        </tbody>
      </table>

    <?php elseif ($qtype === 'MATCH'): ?>
      <?php
        $chosenParts  = $chosen !== '' ? explode(',', $chosen) : [];
        $correctParts = $corr   !== '' ? explode(',', $corr)   : [];
        $numStmt   = max(1, count($correctParts));
        $matchLtrs = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
      ?>
      <table class="yn-res-tbl">
        <thead><tr><th>Statement</th><th>Your Match</th><th>Correct Match</th></tr></thead>
        <tbody>
        <?php for ($s = 1; $s <= $numStmt; $s++):
            $stmtText   = $qd['MatchStatement'.$s] ?? '';
            if ($stmtText === '') continue;
            $studentOpt = (int)($chosenParts[$s-1] ?? 0);
            $correctOpt = (int)($correctParts[$s-1] ?? 0);
            $match      = ($studentOpt > 0 && $studentOpt === $correctOpt);
            $studentLbl = $studentOpt > 0
                        ? (($matchLtrs[$studentOpt] ?? '?') . ' — ' . ($qd['Answer'.$studentOpt] ?? ''))
                        : '—';
            $correctLbl = $correctOpt > 0
                        ? (($matchLtrs[$correctOpt] ?? '?') . ' — ' . ($qd['Answer'.$correctOpt] ?? ''))
                        : '—';
        ?>
        <tr>
          <td><?php echo htmlspecialchars($stmtText); ?></td>
          <td style="text-align:center;">
            <span class="yn-badge <?php echo $match ? 'yn-correct' : 'yn-wrong'; ?>"
                  style="width:auto;border-radius:6px;padding:4px 10px;">
              <?php echo htmlspecialchars($studentLbl); ?>
            </span>
          </td>
          <td style="text-align:center;">
            <span class="yn-badge yn-correct" style="width:auto;border-radius:6px;padding:4px 10px;">
              <?php echo htmlspecialchars($correctLbl); ?>
            </span>
          </td>
        </tr>
        <?php endfor; ?>
        </tbody>
      </table>

    <?php else: /* MCQ / DROPDOWN */
        $toIdx = fn(string $v): int => is_numeric($v) ? (int)$v
            : (int)ltrim(str_ireplace('answer', '', strtolower(trim($v))));
        $chosenIdx  = $toIdx($chosen);
        $correctIdx = $toIdx($corr);
    ?>
      <div class="res-mcq-grid">
      <?php foreach (['1'=>'A','2'=>'B','3'=>'C','4'=>'D'] as $n => $ltr):
          $text      = $qd['Answer'.$n] ?? '';
          $hasAnsImg = ($qd['AnsImageInd'] ?? 'N') === 'Y';
          $imgSrc    = '';
          if ($hasAnsImg) {
              $imgLoc = (($qd['MultiImageInd'] ?? 'N') === 'Y')
                  ? ($answerImages[$qd['AnswerId']]['AnswerImage'.$n.'Loc'] ?? '')
                  : ($qd['ImageLoc'] ?? '');
              $imgSrc = resolveImgPath($imgLoc ?? '');
          }
          // Skip slots with nothing to show — keeps NumOptions<4 honest for
          // image answers too, since AnsImageInd is a question-level flag.
          if ($hasAnsImg ? $imgSrc === '' : $text === '') continue;
          $isC = ((int)$n === $correctIdx);
          $isS = ((int)$n === $chosenIdx);
          $cls = ($isC && $isS) ? 'res-correct' : (!$isC && $isS ? 'res-wrong' : ($isC ? 'res-miss' : ''));
      ?>
      <div class="res-opt <?php echo $cls; ?>">
        <span class="res-opt-badge"><?php echo $ltr; ?></span>
        <?php if ($hasAnsImg): ?>
          <?php if ($imgSrc): ?><img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="" class="res-opt-img"><?php endif; ?>
        <?php else: ?>
          <span><?php echo htmlspecialchars($text); ?></span>
        <?php endif; ?>
        <?php if ($isC && $isS): ?><span style="margin-left:auto;">&#10004;</span>
        <?php elseif (!$isC && $isS): ?><span style="margin-left:auto;">&#10008;</span>
        <?php elseif ($isC): ?><span style="margin-left:auto;color:#d97706;">&#9733;</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php renderExplanation($expText, $qNum); ?>

    <?php else: ?>
    <div style="color:#94a3b8;font-size:.85rem;font-style:italic;">Question data no longer available.</div>
    <?php endif; ?>

    <div class="qnav-bar qnav-bar-bottom">
      <div class="qnav-side"><span class="qnav-count"></span></div>
      <div class="qnav-side qnav-actions">
        <button type="button" class="qnav-box qnav-prev" onclick="qPrev()" title="Previous question">
          <span class="qnav-ic">&#8249;</span> Previous
        </button>
        <button type="button" class="qnav-box qnav-next" onclick="qNext()" title="Next question">
          Next <span class="qnav-ic">&#8250;</span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
  </div>
</div>

<script>
(function () {
  var cards = Array.prototype.slice.call(document.querySelectorAll('.res-card'));
  if (!cards.length) return;

  var STATUS_CLASSES = { 'q-correct': 'correct', 'q-partial': 'partial', 'q-wrong': 'wrong', 'q-skipped': 'skipped' };
  var palette = document.getElementById('qPalette');

  cards.forEach(function (card, i) {
    var status = 'wrong';
    for (var cls in STATUS_CLASSES) {
      if (card.classList.contains(cls)) { status = STATUS_CLASSES[cls]; break; }
    }
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'qpalette-btn qp-' + status;
    btn.textContent = String(i + 1);
    btn.title = 'Question ' + (i + 1);
    btn.addEventListener('click', function () { qShow(i); });
    palette.appendChild(btn);
  });

  var paletteBtns = Array.prototype.slice.call(palette.children);
  var curPos = 0;

  function qShow(pos) {
    if (pos < 0) pos = 0;
    if (pos > cards.length - 1) pos = cards.length - 1;
    curPos = pos;

    cards.forEach(function (c) { c.classList.add('q-hidden'); });
    cards[curPos].classList.remove('q-hidden');
    cards[curPos].scrollIntoView({ behavior: 'smooth', block: 'start' });

    document.querySelectorAll('.qnav-count').forEach(function (el) {
      el.textContent = 'Question ' + (curPos + 1) + ' of ' + cards.length;
    });
    document.querySelectorAll('.qnav-prev').forEach(function (b) { b.disabled = curPos === 0; });
    document.querySelectorAll('.qnav-next').forEach(function (b) { b.disabled = curPos === cards.length - 1; });
    paletteBtns.forEach(function (btn, i) { btn.classList.toggle('qp-current', i === curPos); });
  }

  window.qPrev = function () { qShow(curPos - 1); };
  window.qNext = function () { qShow(curPos + 1); };

  qShow(0);
})();
</script>
<?php else: ?>
<div class="card" style="margin-top:12px;">
  <div class="card-body" style="text-align:center;padding:32px;color:#718096;">
    No detailed question data was recorded for this attempt.
  </div>
</div>
<?php endif; ?>

<div style="text-align:center;padding:8px 0 24px;">
  <a href="history.php" class="btn btn-primary">&#8592; Back to History</a>
  <?php if ($isAdmin): ?>
  <a href="../Admin/Index.php" class="btn btn-secondary" style="margin-left:8px;">Admin Home</a>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
