<?php
/**
 * exam/export-pdf.php — print-ready question paper and answer key.
 *
 * Same "no PDF library" convention as exam/certificate-print.php: plain HTML
 * + print CSS, no PDF library involved (see that file's header comment) —
 * the browser's native Print → Save as PDF covers the actual PDF output.
 * Deliberately two separate views/downloads rather than one document, so a
 * teacher can hand out the paper without the answer key ever being on the
 * same sheet of paper.
 *
 * Modes:
 *   ?examId=N               Step 1: landing card with two print links.
 *   ?examId=N&mode=paper    Question paper only — no correct answers shown.
 *   ?examId=N&mode=key      Answer key only — Q# + correct answer table.
 *
 * Admin, or Institute Admin for their own institute's exams only (matching
 * exam/export-word.php and exam/questions.php, the page this is linked from).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/AppSettings.php';
Auth::requireLogin('../auth/login.php');
$isFullAdmin = Auth::isAdmin();
$isInstAdmin = Auth::isInstituteAdmin();
if (!$isFullAdmin && !$isInstAdmin) { header('Location: search.php'); exit; }

$examId = filter_input(INPUT_GET, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

$exam = Database::fetchOne(
    "SELECT e.*, g.GradeName, s.SubjectName, i.InstituteName AS ExamInstituteName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
  LEFT JOIN institutes  i ON i.InstituteId   = e.ExamInstituteId
      WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: search.php'); exit; }

// Institute admins may only export exams that belong to their own institute
// — same ownership rule as exam/export-word.php.
if ($isInstAdmin && !$isFullAdmin && (int)($exam['ExamInstituteId'] ?? 0) !== (int)Auth::currentInstituteId()) {
    header('Location: search.php'); exit;
}

/* ── Load active questions — identical fallback cascade to export-word.php /
   questions.php, so this export always matches what's actually assignable. */
function epdf_load_questions(int $examId): array
{
    try {
        return Database::fetchAll(
            "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc,
                    q.CorrectAnswer, q.SubjectInfoId, qs.SubjectName,
                    COALESCE(q.Complexity,   'Medium') AS Complexity,
                    COALESCE(eq.IsActive,    'Y')      AS IsActive,
                    COALESCE(q.QuestionType, 'MCQ')    AS QuestionType,
                    q.Explanation,
                    a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                    a.YesNo1,  a.YesNo2,  a.YesNo3,  a.YesNo4,
                    COALESCE(a.NumStatements, 4)        AS NumStatements,
                    ai.AnswerImage1Loc, ai.AnswerImage2Loc,
                    ai.AnswerImage3Loc, ai.AnswerImage4Loc
               FROM exam_questions eq
               JOIN questions q  ON q.QuestionId = eq.QuestionId
          LEFT JOIN subjectinfo qs ON qs.SubjectInfoId = q.SubjectInfoId
          LEFT JOIN answers   a  ON a.QuestionId = q.QuestionId
          LEFT JOIN answerimages ai ON ai.QuestionId = q.QuestionId
              WHERE eq.ExamInfoId = ? AND COALESCE(q.IsDeleted,'N') = 'N'
                AND COALESCE(eq.IsActive,'Y') = 'Y'
              ORDER BY q.QuestionId",
            [$examId]);
    } catch (Exception $e) {
        try {
            return Database::fetchAll(
                "SELECT q.QuestionId,
                        COALESCE(sq.QuestionDesc,  q.QuestionDesc)          AS QuestionDesc,
                        COALESCE(sq.ImageInd,      q.ImageInd)              AS ImageInd,
                        COALESCE(sq.ImageLoc,      q.ImageLoc)              AS ImageLoc,
                        COALESCE(sq.CorrectAnswer, q.CorrectAnswer)         AS CorrectAnswer,
                        COALESCE(sq.SubjectInfoId, q.SubjectInfoId)         AS SubjectInfoId,
                        qs.SubjectName,
                        COALESCE(sq.Complexity,    q.Complexity,  'Medium') AS Complexity,
                        COALESCE(sq.IsActive,      q.IsActive,    'Y')      AS IsActive,
                        COALESCE(sq.QuestionType,  q.QuestionType,'MCQ')    AS QuestionType,
                        COALESCE(sq.Explanation,   q.Explanation)           AS Explanation,
                        a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        a.YesNo1,  a.YesNo2,  a.YesNo3,  a.YesNo4,
                        COALESCE(a.NumStatements, 4) AS NumStatements,
                        NULL AS AnswerImage1Loc, NULL AS AnswerImage2Loc,
                        NULL AS AnswerImage3Loc, NULL AS AnswerImage4Loc
                   FROM questions q
              LEFT JOIN questions sq ON sq.QuestionId = q.LinkedFromQuestionId
              LEFT JOIN subjectinfo qs ON qs.SubjectInfoId = COALESCE(sq.SubjectInfoId, q.SubjectInfoId)
              LEFT JOIN answers   a  ON a.QuestionId  = COALESCE(q.LinkedFromQuestionId, q.QuestionId)
                  WHERE q.ExamInfoId = ? AND COALESCE(q.IsDeleted,'N') = 'N'
                    AND COALESCE(q.IsActive,'Y') = 'Y'
                  ORDER BY q.QuestionId",
                [$examId]);
        } catch (Exception $e2) {
            return Database::fetchAll(
                "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc, q.CorrectAnswer,
                        q.SubjectInfoId, qs.SubjectName,
                        COALESCE(q.Complexity,'Medium') AS Complexity,
                        COALESCE(q.IsActive,'Y') AS IsActive, 'MCQ' AS QuestionType,
                        q.Explanation,
                        a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        NULL AS YesNo1, NULL AS YesNo2, NULL AS YesNo3, NULL AS YesNo4, 4 AS NumStatements,
                        NULL AS AnswerImage1Loc, NULL AS AnswerImage2Loc,
                        NULL AS AnswerImage3Loc, NULL AS AnswerImage4Loc
                   FROM questions q
              LEFT JOIN subjectinfo qs ON qs.SubjectInfoId = q.SubjectInfoId
              LEFT JOIN answers a ON a.QuestionId = q.QuestionId
                  WHERE q.ExamInfoId = ? AND COALESCE(q.IsActive,'Y') = 'Y'
                  ORDER BY q.QuestionId",
                [$examId]);
        }
    }
}

/** Same convention as export-word.php's ewp_resolve_image(). */
function epdf_resolve_image(?string $loc): ?string
{
    $loc = trim((string)$loc);
    if ($loc === '') return null;
    return '../Admin/' . ltrim($loc, '/');
}

function epdf_safe_filename(string $s): string
{
    $s = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

$mode = $_GET['mode'] ?? '';
$mode = in_array($mode, ['paper', 'key'], true) ? $mode : '';

$questions = ($mode !== '') ? epdf_load_questions($examId) : [];
$questionCount = ($mode !== '') ? count($questions) : count(epdf_load_questions($examId));

// Prefer the exam's OWN institute (examinfo.ExamInstituteId -> institutes.InstituteName)
// so a paper printed for, say, an Institute Admin's exam is headed with that
// institute's name rather than the platform-wide default. Only exams that
// aren't owned by any institute (ExamInstituteId NULL — plain Admin-created
// exams) fall back to the global cert_institute_name app setting, and from
// there to APP_NAME, same as before.
$instituteName = trim((string)($exam['ExamInstituteName'] ?? ''));
if ($instituteName === '') {
    $instituteName = AppSettings::get('cert_institute_name', defined('APP_NAME') ? APP_NAME : 'Riyatrix Systems');
}
$examName      = $exam['ExamName'] ?? 'Question Paper';
$optionLabels  = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D'];

/* ═══════════════════════════════════════════════════════════════════════
   Landing card — no ?mode= yet
   ═══════════════════════════════════════════════════════════════════════ */
if ($mode === '') {
    $pageTitle = 'Export Question Paper — PDF';
    include __DIR__ . '/../includes/header.php';
    ?>
    <nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
      <a href="questions.php?examId=<?= (int)$examId ?>" style="color:#3182ce;text-decoration:none;">&#10067; <?= htmlspecialchars($examName) ?></a>
      <span style="margin:0 6px;">&rsaquo;</span>
      <span>Export as PDF</span>
    </nav>

    <div class="card" style="max-width:560px;">
      <div class="card-header">&#128196; Export Question Paper (PDF)</div>
      <div class="card-body">
        <p style="font-size:.88rem;color:#4a5568;margin-top:0;">
          Opens a print-ready view of <strong><?= htmlspecialchars($examName) ?></strong>
          (<?= $questionCount ?> active question<?= $questionCount === 1 ? '' : 's' ?>) —
          use your browser's <strong>Print &rarr; Save as PDF</strong> to download it.
          The question paper and answer key are kept as two separate documents on purpose,
          so the paper can be handed out without ever exposing the answers.
        </p>

        <?php if ($questionCount === 0): ?>
          <div class="alert alert-warning">This exam has no active questions yet.</div>
        <?php else: ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <a href="export-pdf.php?examId=<?= (int)$examId ?>&mode=paper" target="_blank"
               class="btn btn-sm" style="background:#2563eb;color:#fff;font-weight:700;">
              &#128196; View / Print Question Paper
            </a>
            <a href="export-pdf.php?examId=<?= (int)$examId ?>&mode=key" target="_blank"
               class="btn btn-sm" style="background:#dc2626;color:#fff;font-weight:700;">
              &#128273; View / Print Answer Key
            </a>
          </div>
          <p style="font-size:.78rem;color:#94a3b8;margin-top:12px;">
            Each opens in a new tab with a Print button — choose "Save as PDF" as the destination in your browser's print dialog.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
    <?php
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════
   Printable view — mode=paper or mode=key
   ═══════════════════════════════════════════════════════════════════════ */
$docTitle = epdf_safe_filename($examName) . ' - ' . ($mode === 'key' ? 'Answer Key' : 'Question Paper');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($docTitle); ?></title>
<style>
  @page { size: A4; margin: 18mm 16mm; }
  * { box-sizing: border-box; }
  html, body {
    margin: 0; padding: 0; background: #e2e8f0;
    font-family: Georgia, 'Times New Roman', serif; color: #1a202c;
  }
  .toolbar {
    position: sticky; top: 0; z-index: 100;
    display: flex; justify-content: space-between; align-items: center;
    background: #1e293b; color: #fff; padding: 10px 20px;
  }
  .toolbar a { color: #cbd5e1; text-decoration: none; font-size: .85rem; }
  .toolbar button {
    background: #2563eb; color: #fff; border: none; border-radius: 6px;
    padding: 8px 18px; font-weight: 700; cursor: pointer; font-size: .88rem;
  }
  .sheet {
    max-width: 800px; margin: 20px auto; background: #fff;
    padding: 28px 34px; box-shadow: 0 2px 12px rgba(0,0,0,.15); border-radius: 4px;
  }
  .hdr-inst { text-align: center; font-weight: 700; font-size: 1.15rem; margin-bottom: 2px; }
  .hdr-title { text-align: center; font-weight: 700; font-size: 1.4rem; margin-bottom: 6px; }
  .hdr-meta { text-align: center; font-size: .85rem; color: #4a5568; margin-bottom: 14px; }
  .info-line { font-size: .92rem; margin-bottom: 8px; }
  .info-bar {
    font-size: .88rem; padding-bottom: 8px; margin-bottom: 10px;
    border-bottom: 1px solid #a0aec0; display: flex; gap: 24px; flex-wrap: wrap;
  }
  .instructions { font-size: .82rem; font-style: italic; color: #4a5568; margin-bottom: 18px; }
  /* Section header printed whenever the subject changes between consecutive
     questions in a mixed/multi-subject exam, so the paper reads as clearly
     divided sections rather than one undifferentiated question list. */
  .subj-hdr {
    font-weight: 700; font-size: 1rem; text-transform: uppercase; letter-spacing: .04em;
    margin: 18px 0 10px; padding-bottom: 4px; border-bottom: 2px solid #1a202c;
    break-inside: avoid; break-after: avoid; page-break-inside: avoid; page-break-after: avoid;
  }
  .sheet > .subj-hdr:first-of-type { margin-top: 0; }
  .key-grid .subj-hdr { column-span: all; font-size: .9rem; margin: 14px 0 6px; }
  .key-grid .subj-hdr:first-child { margin-top: 0; }
  .q-block { margin-bottom: 16px; break-inside: avoid; }
  .q-text { font-weight: 700; font-size: .98rem; margin-bottom: 6px; }
  .q-opts { margin-left: 22px; }
  .q-opt { font-size: .92rem; margin-bottom: 3px; }
  .q-img, .opt-img { max-width: 100%; max-height: 260px; display: block; margin: 6px 0 6px 22px; }
  .opt-img { max-height: 130px; }
  /* Answer key — a compact multi-column grid instead of one Q#/Answer row
     per question. A one-column table put ~30 questions per page (150
     questions = 5 pages); flowing short "Q# Answer" chips into CSS columns
     packs the same content into 1-2 pages. `columns: 130px 6` asks for
     6 columns of >=130px each — the browser narrows the column count on its
     own if the page is too small to fit that many, so this holds up for
     both A4 print and a plain browser window. */
  .key-grid { columns: 130px 6; column-gap: 14px; margin-top: 10px; }
  .key-item {
    display: flex; justify-content: space-between; align-items: baseline; gap: 8px;
    padding: 4px 8px; font-size: .82rem; border-bottom: 1px dotted #cbd5e1;
    break-inside: avoid; page-break-inside: avoid;
  }
  .key-q { font-weight: 700; }
  .key-a { color: #2d3748; }
  .no-active { font-style: italic; color: #718096; }
  @media print {
    .toolbar { display: none !important; }
    html, body { background: #fff; }
    .sheet { margin: 0; box-shadow: none; max-width: none; padding: 0; }
  }
</style>
</head>
<body>

<div class="toolbar no-print">
  <a href="export-pdf.php?examId=<?php echo (int)$examId; ?>">&larr; Back to Export Options</a>
  <button onclick="window.print()">&#128424; Print / Save as PDF</button>
</div>

<div class="sheet">
  <div class="hdr-inst"><?php echo htmlspecialchars($instituteName); ?></div>
  <div class="hdr-title"><?php echo htmlspecialchars($examName); ?><?php echo $mode === 'key' ? ' — Answer Key' : ''; ?></div>
  <?php
    $metaBits = [];
    if (!empty($exam['SubjectName'])) $metaBits[] = 'Subject: ' . $exam['SubjectName'];
    if (!empty($exam['GradeName']))   $metaBits[] = 'Grade: '   . $exam['GradeName'];
    if ($metaBits):
  ?>
  <div class="hdr-meta"><?php echo htmlspecialchars(implode('   |   ', $metaBits)); ?></div>
  <?php endif; ?>

  <?php if ($mode === 'paper'): ?>
    <div class="info-line">Name: ______________________________&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Date: ____________</div>
    <div class="info-bar">
      <span>Duration: <?php echo (int)($exam['TimeAlloted'] ?? 0); ?> min</span>
      <span>Maximum Marks: <?php echo htmlspecialchars(rtrim(rtrim(number_format((float)($exam['TotalMarks'] ?? 0), 2), '0'), '.')); ?></span>
      <span>Total Questions: <?php echo $questionCount; ?></span>
    </div>
    <div class="instructions">Instructions: Answer all questions. Each question carries equal marks unless stated otherwise.</div>

    <?php if (!$questions): ?>
      <p class="no-active">No active questions found for this exam.</p>
    <?php else: ?>
      <?php $qNum = 0; $lastSubjectId = -1; foreach ($questions as $q): $qNum++; $qType = $q['QuestionType'] ?? 'MCQ';
        $curSubjectId   = $q['SubjectInfoId'] ?? 0;
        $curSubjectName = trim((string)($q['SubjectName'] ?? ''));
        if ($curSubjectName !== '' && $curSubjectId != $lastSubjectId):
          $lastSubjectId = $curSubjectId; ?>
        <div class="subj-hdr"><?php echo htmlspecialchars($curSubjectName); ?></div>
      <?php endif; ?>
        <div class="q-block">
          <div class="q-text"><?php echo $qNum; ?>. <?php echo nl2br(htmlspecialchars($q['QuestionDesc'] ?? '')); ?></div>
          <?php if (($q['ImageInd'] ?? 'N') === 'Y'):
            $imgPath = epdf_resolve_image($q['ImageLoc'] ?? null); ?>
            <?php if ($imgPath): ?>
              <img class="q-img" src="<?php echo htmlspecialchars($imgPath); ?>" alt="">
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($qType === 'YESNO'): ?>
            <div class="q-opts">
              <?php $numStatements = max(1, min(4, (int)($q['NumStatements'] ?? 4)));
              for ($s = 1; $s <= $numStatements; $s++):
                $stmt = $q['YesNo' . $s] ?? '';
                if ($stmt === '' || $stmt === null) continue; ?>
                <div class="q-opt">(<?php echo $s; ?>) <?php echo htmlspecialchars($stmt); ?> &nbsp;—&nbsp; Yes / No</div>
              <?php endfor; ?>
            </div>
          <?php elseif ($qType === 'MCQ' || $qType === 'DROPDOWN'): ?>
            <div class="q-opts">
              <?php foreach ($optionLabels as $optNum => $letter):
                $optText   = $q['Answer' . $optNum] ?? '';
                $optImgLoc = $q['AnswerImage' . $optNum . 'Loc'] ?? null;
                if (($optText === '' || $optText === null) && !$optImgLoc) continue; ?>
                <div class="q-opt"><?php echo $letter; ?>. <?php echo htmlspecialchars($optText ?? ''); ?></div>
                <?php if ($optImgLoc):
                  $optImgPath = epdf_resolve_image($optImgLoc); ?>
                  <?php if ($optImgPath): ?><img class="opt-img" src="<?php echo htmlspecialchars($optImgPath); ?>" alt=""><?php endif; ?>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php else: /* mode === 'key' */ ?>
    <div class="info-bar">
      <span>Total Questions: <?php echo $questionCount; ?></span>
    </div>
    <?php if (!$questions): ?>
      <p class="no-active">No active questions found for this exam.</p>
    <?php else: ?>
      <div class="key-grid">
        <?php $qNum = 0; $lastSubjectId = -1; foreach ($questions as $q): $qNum++; $qType = $q['QuestionType'] ?? 'MCQ';
          $curSubjectId   = $q['SubjectInfoId'] ?? 0;
          $curSubjectName = trim((string)($q['SubjectName'] ?? ''));
          if ($curSubjectName !== '' && $curSubjectId != $lastSubjectId):
            $lastSubjectId = $curSubjectId; ?>
          <div class="subj-hdr"><?php echo htmlspecialchars($curSubjectName); ?></div>
        <?php endif; ?>
          <div class="key-item">
            <span class="key-q">Q<?php echo $qNum; ?></span>
            <span class="key-a">
              <?php if ($qType === 'YESNO'):
                $numStatements = max(1, min(4, (int)($q['NumStatements'] ?? 4)));
                $pattern = [];
                for ($s = 1; $s <= $numStatements; $s++) { $pattern[] = $q['YesNo' . $s] ?? '?'; }
                echo htmlspecialchars(implode('/', $pattern));
              else:
                $correctRaw    = ltrim(str_ireplace('Answer', '', $q['CorrectAnswer'] ?? ''));
                $correctLetter = $optionLabels[$correctRaw] ?? '';
                echo htmlspecialchars($correctLetter !== '' ? $correctLetter : '—');
              endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

</body>
</html>
