<?php
/**
 * Admin/WriteExamAction.php - legacy path result page.
 * CorrectAnswer in DB = "Answer1"…"Answer4"; strip prefix to compare with "1"…"4".
 * Image paths in DB are relative to Admin/ — this file lives in Admin/ so no prefix needed.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ExamSearch.php'); exit; }
Auth::validateCsrf();

$examId       = filter_input(INPUT_POST, 'InfoId',         FILTER_VALIDATE_INT);
$numQues      = filter_input(INPUT_POST, 'number_of_ques', FILTER_VALIDATE_INT);
$marksOutOf   = filter_input(INPUT_POST, 'MarksOutOf',     FILTER_VALIDATE_INT);
$passingMarks = filter_input(INPUT_POST, 'PassingMarks',   FILTER_VALIDATE_INT);
$gradeId      = filter_input(INPUT_POST, 'GradeId',        FILTER_VALIDATE_INT);
$subjectId    = filter_input(INPUT_POST, 'SubjectId',      FILTER_VALIDATE_INT);
if (!$examId || !$numQues) { die('Invalid submission.'); }

$startTime  = (int)($_SESSION['starttime'] ?? $_POST['starttime'] ?? time());
$timeTaken  = max(0, time() - $startTime);
$loginName  = Auth::currentUser();          // display name for logs
$userInfoId = Auth::currentUserId();        // direct from session — no extra DB round-trip

$submittedAnswers = [];
for ($i = 1; $i <= $numQues; $i++) {
    $qid    = filter_input(INPUT_POST, 'QuestionId'.$i, FILTER_VALIDATE_INT);
    $choice = trim($_POST['rdoAnswer'.$i] ?? '');
    if ($qid) $submittedAnswers[$qid] = $choice;
}
if (!$submittedAnswers) { die('No questions in submission.'); }

$qids = array_keys($submittedAnswers);
$ph   = implode(',', array_fill(0, count($qids), '?'));
/* Explicit columns to avoid PDO FETCH_ASSOC ambiguity: if answers table ever
   gains a CorrectAnswer column (even NULL), SELECT q.*,a.* would silently
   return NULL for CorrectAnswer, breaking all score calculations. */
$rows = Database::fetchAll(
    "SELECT q.QuestionId, q.CorrectAnswer, q.OperatorInd,
            q.QuestionDesc, q.ImageInd, q.ImageLoc, q.NumofImages,
            a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
            a.AnsImageInd, a.MultiImageInd
       FROM questions q
       JOIN answers   a ON a.QuestionId = q.QuestionId
      WHERE q.QuestionId IN ($ph)",
    $qids);

$questionData = [];
foreach ($rows as $r) {
    $raw = $r['CorrectAnswer'] ?? '';
    $r['_correct'] = ltrim(str_ireplace('Answer', '', $raw));
    $questionData[(int)$r['QuestionId']] = $r;   // int cast prevents type-mismatch lookups
}

$correct = 0; $wrong = 0; $skipped = 0;
foreach ($submittedAnswers as $qid => $chosen) {
    if ($chosen === '')                                                          $skipped++;
    elseif (isset($questionData[$qid]) && $chosen === $questionData[$qid]['_correct']) $correct++;
    else                                                                          $wrong++;
}
$passed      = ($correct >= (int)$passingMarks);
$description = $passed ? 'Pass' : 'Fail';

$mathQids = [];
foreach ($questionData as $qid => $q) { if ($q['OperatorInd']==='Y') $mathQids[]=$qid; }
$operatorMap = [];
if ($mathQids) {
    $oph = implode(',', array_fill(0, count($mathQids), '?'));
    foreach (Database::fetchAll("SELECT * FROM quesoperators WHERE QuestionId IN ($oph)", $mathQids) as $op)
        $operatorMap[$op['QuestionId']] = $op;
}

$answerIds = array_column($rows, 'AnswerId');
$answerImages = [];
if ($answerIds) {
    $aph = implode(',', array_fill(0, count($answerIds), '?'));
    foreach (Database::fetchAll(
        "SELECT AnswerId,AnswerImage1Loc,AnswerImage2Loc,AnswerImage3Loc,AnswerImage4Loc
           FROM answerimages WHERE AnswerId IN ($aph)", $answerIds) as $img)
        $answerImages[$img['AnswerId']] = $img;
}

$m = (int)date('m'); $y = (int)date('Y');
$curYear = ($m <= 5) ? $y - 1 : $y;

/* ── Persist exam attempt ─────────────────────────────────────────────────── */
try {
    Database::execute(
        "INSERT INTO studentexam
            (UserInfoId,ExamInfoId,GradeInfoId,SubjectInfoId,Score,MarksOutOf,Description,TimeTaken,ExamYear,ExamDate)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())",
        [$userInfoId,$examId,$gradeId,$subjectId,$correct,$marksOutOf,$description,$timeTaken,$curYear]);
} catch (Exception $e) {
    Database::execute(
        "INSERT INTO studentexam (ExamInfoId,UserInfoId,TimeTaken,CreateDate)
         VALUES (?,?,?,NOW())",
        [$examId,$userInfoId,$timeTaken]);
}
$studentExamId = (int)Database::lastInsertId();

/* Try to store score/result even after fallback insert — silently ignored if
   extended columns don't exist yet (run migration_v3.sql to enable). */
try {
    Database::execute(
        "UPDATE studentexam
            SET Score=?, MarksOutOf=?, Description=?, ExamYear=?, ExamDate=NOW()
          WHERE StudentExamId=?",
        [$correct, $marksOutOf, $description, $curYear, $studentExamId]);
} catch (Exception $e) { /* extended columns not yet added */ }

foreach ($submittedAnswers as $qid => $chosen) {
    $correctRaw  = $questionData[$qid]['CorrectAnswer'] ?? '';
    $correctNorm = $questionData[$qid]['_correct']      ?? '';
    $isCorrect   = ($chosen!=='' && $chosen===$correctNorm) ? 'Y' : 'N';
    try {
        Database::execute(
            "INSERT INTO studentexamresults
                (StudentExamId,QuestionId,StdAnswerId,SelectedAnswer,CorrectAnswer,IsCorrect)
             VALUES (?,?,?,?,?,?)",
            [$studentExamId,$qid,$chosen,$chosen,$correctRaw,$isCorrect]);
    } catch (Exception $e) {
        Database::execute(
            "INSERT INTO studentexamresults (StudentExamId,QuestionId,StdAnswerId)
             VALUES (?,?,?)",
            [$studentExamId,$qid,$chosen]);
    }
}

/* ── Post-storage re-verification ────────────────────────────────────────────
   Re-count IsCorrect='Y' from the rows just inserted. Protects against any
   off-by-one that could occur if a row insert silently failed, and guarantees
   the stored score always matches the actual answer records. */
try {
    $recalc = Database::fetchOne(
        "SELECT SUM(CASE WHEN IsCorrect='Y' THEN 1 ELSE 0 END) AS rc
           FROM studentexamresults WHERE StudentExamId = ?",
        [$studentExamId]);
    if (isset($recalc['rc']) && (int)$recalc['rc'] !== $correct) {
        $correct     = (int)$recalc['rc'];
        $passed      = ($correct >= (int)$passingMarks);
        $description = $passed ? 'Pass' : 'Fail';
        Database::execute(
            "UPDATE studentexam SET Score=?, Description=? WHERE StudentExamId=?",
            [$correct, $description, $studentExamId]);
    }
} catch (Exception $e) {}

try {
    $examInfo = Database::fetchOne("SELECT ExamName FROM examinfo WHERE ExamInfoId=? LIMIT 1", [$examId]);
    Database::execute(
        "INSERT INTO exam_changelog (ExamInfoId,ExamName,Action,ActionBy,Details,Score,MarksOutOf)
         VALUES (?,?,?,?,?,?,?)",
        [$examId,$examInfo['ExamName']??'','TAKEN',$loginName,
         "Score:{$correct}/{$marksOutOf} ".($passed?'PASS':'FAIL'),$correct,$marksOutOf]);
} catch (Exception $e) {}

$exam = Database::fetchOne(
    "SELECT e.ExamName, g.GradeName, s.SubjectName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
      WHERE e.ExamInfoId=? LIMIT 1", [$examId]);

/* ── Image path helper ───────────────────────────────────────────────────── */
/**
 * WriteExamAction.php is inside Admin/, so DB-relative paths resolve directly.
 * We still normalise: strip spaces, pass through absolute/protocol URLs unchanged.
 */
function resolveImgPath(string $raw): string {
    $raw = str_replace(' ', '', trim($raw));
    if ($raw === '') return '';
    // Absolute URL or root-relative: use as-is
    if (strpos($raw, 'http') === 0 || strpos($raw, '//') === 0 || strpos($raw, '/') === 0) return $raw;
    // Already explicitly relative: use as-is
    if (strpos($raw, '../') === 0 || strpos($raw, './') === 0) return $raw;
    // Plain relative path — stored relative to Admin/, so no prefix needed here
    return $raw;
}

/* ── Helper: colour-coded answer cell ───────────────────────────────────── */
function renderResultCell(string $label, array $q, array $answerImages, string $chosen, string $correctNorm): void {
    $isChosen  = ($chosen  === $label);
    $isCorrect = ($correctNorm !== '' && $correctNorm === $label);

    if ($isChosen && $isCorrect) {
        $style = 'background:#c6efce;border:2px solid #276749;';
    } elseif ($isChosen) {
        $style = 'background:#ffc7ce;border:2px solid #c53030;';
    } elseif ($isCorrect) {
        $style = 'background:#fff3cd;border:2px solid #d4a017;';
    } else {
        $style = '';
    }

    echo '<td style="padding:8px 10px;font-size:.82rem;'.$style.'">';
    if ($q['AnsImageInd']==='Y') {
        $loc = ($q['MultiImageInd']==='Y')
            ? ($answerImages[$q['AnswerId']]['AnswerImage'.$label.'Loc'] ?? '')
            : ($q['ImageLoc'] ?? '');
        $src = htmlspecialchars(resolveImgPath($loc ?? ''));
        if ($src) echo '<img src="'.$src.'" alt="" style="max-width:90px;">';
    } else {
        echo htmlspecialchars($q['Answer'.$label] ?? '');
    }
    if ($isChosen && $isCorrect) {
        echo ' <strong style="color:#276749;font-size:1rem;">&#10004;</strong>';
    } elseif ($isChosen) {
        echo ' <strong style="color:#c53030;font-size:1rem;">&#10008;</strong>';
    }
    if ($isCorrect && !$isChosen) {
        echo ' <strong style="color:#b7791f;font-size:.8rem;">&#9733; correct</strong>';
    }
    echo '</td>';
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><title>Exam Result</title>
<link href="<?php echo asset_version('Admin/style.css'); ?>" rel="stylesheet">
<link href="../<?php echo asset_version('assets/style.css'); ?>" rel="stylesheet">
<style>
.result-banner{padding:20px;border-radius:8px;text-align:center;margin:10px 0;}
.result-pass{background:linear-gradient(135deg,#c6efce,#a9dfbf);border:2px solid #276749;}
.result-fail{background:linear-gradient(135deg,#ffc7ce,#f5a0a8);border:2px solid #c53030;}
.result-verdict{font-size:2rem;font-weight:900;letter-spacing:2px;}
.result-pass .result-verdict{color:#276749;}
.result-fail .result-verdict{color:#c53030;}
.stat-chip{display:inline-block;padding:5px 14px;border-radius:20px;font-weight:700;font-size:.875rem;margin:4px;background:rgba(255,255,255,.7);}
.cell-correct{background:#c6efce!important;}
.cell-wrong{background:#ffc7ce!important;}
</style>
</head><body>
<?php include_once('Includes/Top.php'); ?>
<table border="0" cellpadding="0" cellspacing="1" width="1024" align="center">
<tr><td colspan="6">
  <div class="result-banner <?php echo $passed?'result-pass':'result-fail'; ?>">
    <div class="result-verdict"><?php echo $passed?'PASS':'FAIL'; ?></div>
    <div style="margin-top:8px;">
      <span class="stat-chip">Score: <strong><?php echo "$correct / $marksOutOf"; ?></strong></span>
      <span class="stat-chip">Correct: <strong><?php echo $correct; ?></strong></span>
      <span class="stat-chip">Wrong: <strong><?php echo $wrong; ?></strong></span>
      <span class="stat-chip">Skipped: <strong><?php echo $skipped; ?></strong></span>
      <span class="stat-chip">Pass Mark: <strong><?php echo $passingMarks; ?></strong></span>
      <span class="stat-chip">Time: <strong><?php echo floor($timeTaken/60).'m '.($timeTaken%60).'s'; ?></strong></span>
    </div>
  </div>
</td></tr>
<tr class="HeaderStyle">
  <th style="background:#1a365d;color:#fff;">#</th>
  <th style="background:#1a365d;color:#fff;" colspan="2">Question</th>
  <th style="background:#1a365d;color:#fff;">A(1)</th>
  <th style="background:#1a365d;color:#fff;">B(2)</th>
  <th style="background:#1a365d;color:#fff;">C(3)</th>
  <th style="background:#1a365d;color:#fff;">D(4)</th>
</tr>
<?php $rowNum=0; foreach ($submittedAnswers as $qid => $chosen):
  $rowNum++;
  if (!isset($questionData[$qid])) continue;
  $q           = $questionData[$qid];
  $correctNorm = $q['_correct'];
  $isCorr = ($chosen !== '' && $chosen === $correctNorm);
  $rowBg  = $isCorr ? '#f0fff4' : ($chosen==='' ? '#f8f8f8' : '#fff5f5');
  $numBg  = $isCorr ? '#276749' : ($chosen==='' ? '#718096' : '#c53030');
?>
<tr style="background:<?php echo $rowBg; ?>">
  <td style="background:<?php echo $numBg; ?>;color:#fff;text-align:center;font-weight:700;padding:8px 4px;"><?php echo $rowNum; ?></td>
  <td colspan="2" style="padding:8px 12px;font-size:.875rem;">
    <?php if ($q['ImageInd']==='Y' && !empty($q['ImageLoc'])): ?>
      <img src="<?php echo htmlspecialchars(resolveImgPath($q['ImageLoc'] ?? '')); ?>" style="max-width:220px;">
    <?php else: echo htmlspecialchars($q['QuestionDesc']??''); endif; ?>
    <?php if ($q['OperatorInd']==='Y' && isset($operatorMap[$qid])): $op=$operatorMap[$qid]; ?>
      <pre style="font-family:monospace;background:#f7fafc;padding:6px;margin-top:4px;"><?php
        echo htmlspecialchars($op['Number1'] ?? '')."\n"
           . htmlspecialchars($op['Opeartor'] ?? '').'   '.htmlspecialchars($op['Number2'] ?? '');
        if (($op['Number3'] ?? '') !== '') echo "\n".htmlspecialchars($op['Opeartor'] ?? '').'   '.htmlspecialchars($op['Number3'] ?? '');
      ?></pre>
    <?php endif; ?>
  </td>
  <?php foreach (['1','2','3','4'] as $lbl) renderResultCell($lbl,$q,$answerImages,$chosen,$correctNorm); ?>
</tr>
<?php endforeach; ?>
<tr><td colspan="7" align="center" style="padding:12px;">
  <a href="ExamSearch.php" style="background:#3182ce;color:#fff;padding:8px 20px;border-radius:4px;text-decoration:none;margin-right:8px;">&#8592; Back to Exams</a>
  <a href="ExamHistoryList.php?InfoId=<?php echo $examId; ?>" style="background:#718096;color:#fff;padding:8px 20px;border-radius:4px;text-decoration:none;">&#128200; History</a>
</td></tr>
</table>
<?php include_once('Includes/Bottom.php'); ?>
</body></html>
