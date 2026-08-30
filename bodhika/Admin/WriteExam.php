<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../index.php');
$userName = Auth::currentUser();

$examId = filter_input(INPUT_GET, 'InfoId', FILTER_VALIDATE_INT);
if (!$examId) { die('Invalid exam ID.'); }

$_SESSION['InfoId'] = $examId;

$exam = Database::fetchOne(
    "SELECT e.ExamInfoId, e.ExamName, e.GradeInfoId, e.SubjectInfoId,
            e.NumOfQuestions, e.MinPassing, e.TimeAlloted,
            g.GradeName, s.SubjectName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId  = e.SubjectInfoId
      WHERE e.ExamInfoId = ? LIMIT 1",
    [$examId]);
if (!$exam) { die('Exam not found.'); }

$numQuestions = (int)$exam['NumOfQuestions'];
$timeMinutes  = (int)($exam['TimeAlloted'] ?? 30);
$startTime    = time();
$_SESSION['starttime'] = $startTime;

$_qSql = "SELECT a.AnswerId,  a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                 a.AnsImageInd, a.MultiImageInd,
                 a.AnsHtml1, a.AnsHtml2, a.AnsHtml3, a.AnsHtml4,
                 q.QuestionId, q.QuestionDesc, q.QuestionHtml, q.ImageInd, q.ImageLoc,
                 q.NumofImages, q.OperatorInd
            FROM answers  a
       JOIN questions q ON a.QuestionId = q.QuestionId
       JOIN examinfo  d ON q.ExamInfoId = d.ExamInfoId AND d.GradeInfoId = ?
           WHERE q.ExamInfoId = ?";
$_qParams = [(int)$exam['GradeInfoId'], $examId, $numQuestions];
try {
    $questions = Database::fetchAll(
        $_qSql . " AND q.IsActive = 'Y' ORDER BY RAND() LIMIT ?", $_qParams);
} catch (Exception $e) {
    // IsActive column not yet added — run migration_v4.sql to enable it
    $questions = Database::fetchAll(
        $_qSql . " ORDER BY RAND() LIMIT ?", $_qParams);
}
unset($_qSql, $_qParams);

$mathQids = [];
foreach ($questions as $q) {
    if ($q['OperatorInd'] === 'Y') { $mathQids[] = $q['QuestionId']; }
}
$operatorMap = [];
if ($mathQids) {
    $ph  = implode(',', array_fill(0, count($mathQids), '?'));
    $ops = Database::fetchAll("SELECT * FROM quesoperators WHERE QuestionId IN ($ph)", $mathQids);
    foreach ($ops as $op) { $operatorMap[$op['QuestionId']] = $op; }
}

$answerIds    = array_column($questions, 'AnswerId');
$answerImages = [];
if ($answerIds) {
    $ph   = implode(',', array_fill(0, count($answerIds), '?'));
    $imgs = Database::fetchAll(
        "SELECT AnswerId, AnswerImage1Loc, AnswerImage2Loc, AnswerImage3Loc, AnswerImage4Loc
           FROM answerimages WHERE AnswerId IN ($ph)", $answerIds);
    foreach ($imgs as $img) { $answerImages[$img['AnswerId']] = $img; }
}

$m = (int)date('m'); $y = (int)date('Y');
$curYear = ($m <= 5) ? $y - 1 : $y;

function renderAnswer(int $n, string $label, array $q, array $answerImages): void {
    $radioName = 'rdoAnswer' . $n;
    $ansKey    = 'Answer'    . $label;
    $htmlKey   = 'AnsHtml'  . $label;
    if ($q['AnsImageInd'] === 'Y') {
        $loc = ($q['MultiImageInd'] === 'Y')
            ? ($answerImages[$q['AnswerId']]['AnswerImage' . $label . 'Loc'] ?? '')
            : ($q['ImageLoc'] ?? '');
        $src = htmlspecialchars(str_replace(' ', '', $loc));
        echo '<input type="radio" name="' . $radioName . '" value="' . $label . '">';
        if ($src) { echo '<img src="' . $src . '" alt="Answer ' . $label . '" style="max-width:120px;">'; }
    } elseif (!empty($q[$htmlKey])) {
        echo '<input type="radio" name="' . $radioName . '" value="' . $label . '"> ';
        echo $q[$htmlKey];   // HTML/LaTeX rendered by MathJax
    } else {
        echo '<input type="radio" name="' . $radioName . '" value="' . $label . '"> ';
        echo htmlspecialchars($q[$ansKey] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Write Exam</title>
  <link href="style.css" rel="stylesheet" type="text/css">
  <style>
    #countdown { font-weight:bold; color:#c00; font-size:14px; }
    td { font-size:12px; vertical-align:top; padding:4px; }
  </style>
  <!-- MathJax v3: renders \( inline \) and \[ block \] LaTeX in question/answer text -->
  <script>
    window.MathJax = {
      tex: { inlineMath: [['\\(','\\)']], displayMath: [['\\[','\\]']], processEscapes: true },
      options: { skipHtmlTags: ['script','noscript','style','textarea','pre'] }
    };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.min.js" async></script>
  <script>
  var totalSeconds = <?php echo $timeMinutes * 60; ?>;
  function pad(n){ return n < 10 ? '0'+n : n; }
  function tick(){
    if(totalSeconds <= 0){
      clearInterval(tid);
      document.getElementById('countdown').textContent = 'Time up!';
      document.getElementById('frmWriteExam').submit(); return;
    }
    var h=Math.floor(totalSeconds/3600), m=Math.floor((totalSeconds%3600)/60), s=totalSeconds%60;
    document.getElementById('countdown').textContent = (h?pad(h)+':':'') + pad(m) + ':' + pad(s);
    totalSeconds--;
  }
  window.addEventListener('DOMContentLoaded', function(){ tick(); var tid=setInterval(tick,1000); });
  history.pushState(null,null,location.href);
  window.addEventListener('popstate',function(){ history.pushState(null,null,location.href); });
  </script>
</head>
<body>
<?php include_once('Includes/Top.php'); ?>

<form id="frmWriteExam" name="frmWriteExam" method="post" action="WriteExamAction.php">
<input type="hidden" name="csrf_token"     value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
<input type="hidden" name="InfoId"         value="<?php echo $examId; ?>">
<input type="hidden" name="starttime"      value="<?php echo $startTime; ?>">
<input type="hidden" name="number_of_ques" value="<?php echo count($questions); ?>">
<input type="hidden" name="SubjectId"      value="<?php echo (int)$exam['SubjectInfoId']; ?>">
<input type="hidden" name="MarksOutOf"     value="<?php echo $numQuestions; ?>">
<input type="hidden" name="GradeId"        value="<?php echo (int)$exam['GradeInfoId']; ?>">
<input type="hidden" name="PassingMarks"   value="<?php echo (int)$exam['MinPassing']; ?>">

<table border="0" cellpadding="0" cellspacing="1" width="1024" align="center">
  <tr>
    <td class="tblhdr" colspan="5">Exam: <?php echo htmlspecialchars($exam['ExamName']); ?> &mdash; <?php echo $curYear; ?>&ndash;<?php echo $curYear+1; ?></td>
    <td class="tblhdr" colspan="4" align="right">Time left: <span id="countdown"></span></td>
  </tr>
  <tr>
    <td colspan="2" class="tbldt">Grade</td>
    <td colspan="3" class="tbldt"><input type="text" value="<?php echo htmlspecialchars($exam['GradeName'] ?? ''); ?>" disabled></td>
    <td colspan="2" class="tbldt">Subject</td>
    <td colspan="2" class="tbldt"><input type="text" value="<?php echo htmlspecialchars($exam['SubjectName'] ?? ''); ?>" disabled></td>
  </tr>
  <tr>
    <td colspan="2" class="tbldt">Total Questions</td>
    <td colspan="3" class="tbldt"><input type="text" value="<?php echo $numQuestions; ?>" disabled></td>
    <td colspan="2" class="tbldt">Pass Mark (%)</td>
    <td colspan="2" class="tbldt"><input type="text" value="<?php echo min(100, max(0, (int)$exam['MinPassing'])); ?>%" disabled></td>
  </tr>
  <tr class="HeaderStyle">
    <th>No.</th><th colspan="4">Question</th><th>A</th><th>B</th><th>C</th><th>D</th>
  </tr>

  <?php foreach ($questions as $rowNum => $q): ?>
  <?php $qIdx = $rowNum + 1; ?>
  <input type="hidden" name="QuestionId<?php echo $qIdx; ?>" value="<?php echo (int)$q['QuestionId']; ?>">
  <tr>
    <td align="center" bgcolor="#EEEEEE"><?php echo $qIdx; ?></td>
    <td align="left" bgcolor="#EEEEEE" colspan="4">
      <?php if ($q['ImageInd'] === 'Y' && !empty($q['ImageLoc'])): ?>
        <?php for ($k=0; $k<(int)$q['NumofImages']; $k++): ?>
          <img src="<?php echo htmlspecialchars(str_replace(' ','',$q['ImageLoc'])); ?>" alt="" style="max-width:260px;">
        <?php endfor; ?>
      <?php elseif (!empty($q['QuestionHtml'])): ?>
        <?php echo $q['QuestionHtml']; ?>
      <?php else: ?>
        <?php echo htmlspecialchars($q['QuestionDesc'] ?? ''); ?>
      <?php endif; ?>
      <?php if ($q['OperatorInd'] === 'Y' && isset($operatorMap[$q['QuestionId']])): ?>
        <?php $op = $operatorMap[$q['QuestionId']]; ?>
        <pre style="margin:2px 0;font-family:monospace;">
    <?php echo htmlspecialchars($op['Number1']); ?>
<?php echo htmlspecialchars($op['Opeartor']); ?>   <?php echo htmlspecialchars($op['Number2']); ?>
<?php if ($op['Number3'] !== ''): ?><?php echo htmlspecialchars($op['Opeartor']); ?>   <?php echo htmlspecialchars($op['Number3']); ?><?php endif; ?>
        </pre>
      <?php endif; ?>
    </td>
    <td align="left" bgcolor="#EEEEEE"><?php renderAnswer($qIdx, '1', $q, $answerImages); ?></td>
    <td align="left" bgcolor="#EEEEEE"><?php renderAnswer($qIdx, '2', $q, $answerImages); ?></td>
    <td align="left" bgcolor="#EEEEEE"><?php renderAnswer($qIdx, '3', $q, $answerImages); ?></td>
    <td align="left" bgcolor="#EEEEEE"><?php renderAnswer($qIdx, '4', $q, $answerImages); ?></td>
  </tr>
  <?php endforeach; ?>

  <tr>
    <td align="center" colspan="9" style="padding:10px;">
      <input type="submit" name="save" value="Submit Exam"
             style="background-image:url('../Images/btnsmall2.gif');width:120px;height:26px;border:0" class="btnbg">
      <input type="button" value="Back to Search" onclick="location.href='ExamSearch.php'"
             style="background-image:url('../Images/btnsmall2.gif');width:120px;height:26px;border:0" class="btnbg">
    </td>
  </tr>
</table>
</form>
<?php include_once('Includes/Bottom.php'); ?>
</body>
</html>
