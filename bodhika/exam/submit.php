<?php
/**
 * Processes a submitted exam, scores it, stores results, shows the report.
 * Handles both schema variants:
 *   - Original: studentexamresults(StudentExamId, QuestionId, StdAnswerId)
 *   - Extended: ...+ SelectedAnswer, CorrectAnswer, IsCorrect (migration_v3.sql)
 * CorrectAnswer in the answers table stores "Answer1"…"Answer4";
 * we strip the "Answer" prefix to compare against the radio value "1"…"4".
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Marking.php';
Auth::requireLogin('../auth/login.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: search.php'); exit; }
Auth::validateCsrf();

/* Exam is being submitted — the session-timeout safety net set in
   write.php no longer needs to hold the door open. */
unset($_SESSION['exam_deadline']);

$examId       = filter_input(INPUT_POST, 'InfoId',         FILTER_VALIDATE_INT);
$numQues      = filter_input(INPUT_POST, 'number_of_ques', FILTER_VALIDATE_INT);
$marksOutOf   = filter_input(INPUT_POST, 'MarksOutOf',     FILTER_VALIDATE_INT);
$passingMarks  = filter_input(INPUT_POST, 'PassingMarks',   FILTER_VALIDATE_INT);
// NumOfQuestions no longer needed for pass threshold (MinPassing is now stored as %)
$gradeId      = filter_input(INPUT_POST, 'GradeId',        FILTER_VALIDATE_INT);
$subjectId    = filter_input(INPUT_POST, 'SubjectId',      FILTER_VALIDATE_INT);
$violations   = max(0, (int)(filter_input(INPUT_POST, 'violations', FILTER_VALIDATE_INT) ?? 0));
if (!$examId || !$numQues) { die('Invalid submission.'); }

/* Fetch the exam's authoritative marking-scheme config (and name) directly
   from examinfo — never trust the client-submitted MarksOutOf/PassingMarks
   hidden fields for scoring math, only the visible PassingMarks is still
   used (display/back-compat) and the rest is re-derived here. */
$exam = ['ExamName' => ''];
try {
    $examRow = Database::fetchOne(
        "SELECT ExamName, MarkingScheme, TotalMarks, MarksPerQuestion, NegativeMarks
           FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
    if ($examRow) $exam = $examRow;
} catch (Exception $e) {
    /* migration_v38.sql not yet run — fall back to just the exam name */
    try {
        $examRow = Database::fetchOne("SELECT ExamName FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
        if ($examRow) $exam = $examRow;
    } catch (Exception $e2) {}
}

$startTime  = (int)($_SESSION['starttime'] ?? $_POST['starttime'] ?? time());
$timeTaken  = max(0, time() - $startTime);
$userInfoId = Auth::currentUserId();   // no login-name lookup needed
$actionBy   = Auth::currentUser();     // display name for audit log

/* ── Collect answers from POST ───────────────────────────────────────────── */
$submittedAnswers = [];   // [ questionId => "1"|"2"|"3"|"4"|"" or "Y,N,Y,N" for YESNO, "2,0,3" for MATCH ]
$questionTypes    = [];   // [ questionId => 'MCQ'|'DROPDOWN'|'YESNO'|'MULTI'|'MATCH' ]
for ($i = 1; $i <= $numQues; $i++) {
    $qid   = filter_input(INPUT_POST, 'QuestionId'.$i, FILTER_VALIDATE_INT);
    $qtype = in_array($_POST['QuestionType'.$i] ?? '', ['MCQ','DROPDOWN','YESNO','MULTI','MATCH'])
           ? $_POST['QuestionType'.$i] : 'MCQ';
    if (!$qid) continue;
    $questionTypes[$qid] = $qtype;
    if ($qtype === 'YESNO') {
        // Collect Y/N per statement (up to 4); encode as comma-separated ("Y,N,Y,N")
        $parts = [];
        for ($s = 1; $s <= 4; $s++) {
            $v = $_POST['rdoAnswer'.$i.'_'.$s] ?? '';
            if ($v !== '') $parts[] = ($v === 'Y') ? 'Y' : 'N';
        }
        $submittedAnswers[$qid] = implode(',', $parts);
    } elseif ($qtype === 'MATCH') {
        // rdoAnswerN holds the option number matched to each statement position,
        // comma-separated and set by the drag-and-drop JS in write.php, e.g. "2,0,3"
        // ("0" = that statement position was left unmatched). Sanitise to digits/commas only.
        $rawMatch = trim($_POST['rdoAnswer'.$i] ?? '');
        // Map any malformed/out-of-range token to "0" (unmatched) instead of
        // dropping it, so a tampered value can't shift later positions out of
        // alignment with their statements.
        $parts = array_map(
            fn($v) => (ctype_digit($v) && $v >= 0 && $v <= 4) ? $v : '0',
            array_map('trim', explode(',', $rawMatch))
        );
        // Treat "all zero" (nothing actually matched) the same as no answer at all
        $submittedAnswers[$qid] = array_filter($parts, fn($v) => $v !== '0')
                                ? implode(',', $parts) : '';
    } elseif ($qtype === 'MULTI') {
        // rdoAnswerN is a comma-separated list of option numbers set by JS, e.g. "1,3"
        // Normalise to sorted plain digits ("1,3") — CorrectAnswer in DB has no
        // "Answer" prefix, so the stored/compared format must match it exactly.
        $rawMulti = trim($_POST['rdoAnswer'.$i] ?? '');
        if ($rawMulti === '') {
            $submittedAnswers[$qid] = '';
        } else {
            $selectedNums = array_filter(array_map('trim', explode(',', $rawMulti)),
                                         fn($v) => ctype_digit($v) && $v >= 1 && $v <= 4);
            sort($selectedNums, SORT_NUMERIC);
            $submittedAnswers[$qid] = implode(',', $selectedNums);
        }
    } else {
        $submittedAnswers[$qid] = trim($_POST['rdoAnswer'.$i] ?? '');
    }
}
if (!$submittedAnswers) { die('No questions in submission.'); }

/* ── Batch-fetch question + answer data ─────────────────────────────────── */
$qids = array_keys($submittedAnswers);
$ph   = implode(',', array_fill(0, count($qids), '?'));
/* IMPORTANT: Use explicit q.CorrectAnswer — never use SELECT q.*,a.* for scoring
   because if the answers table happens to have a CorrectAnswer column (even NULL),
   PDO FETCH_ASSOC would return that NULL value and break all comparisons.
   Also resolves linked questions (LinkedFromQuestionId): data is read from the
   source question transparently so scoring is always against the original content.
   Try with migration_v6+ columns first; fall back if they don't exist yet. */
/* After migration_v22 questions are pure data rows — no COALESCE joins needed.
   The question IDs in $qids are always canonical (direct question row IDs).    */
try {
    $rows = Database::fetchAll(
        "SELECT q.QuestionId,
                q.CorrectAnswer, q.OperatorInd, q.QuestionDesc,
                q.ImageInd, q.ImageLoc, q.NumofImages,
                COALESCE(q.QuestionType, 'MCQ') AS QuestionType,
                a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                a.AnsImageInd, a.MultiImageInd,
                a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                COALESCE(a.NumStatements, 4) AS NumStatements,
                a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4,
                a.MatchCorrect1, a.MatchCorrect2, a.MatchCorrect3, a.MatchCorrect4
           FROM questions q
      LEFT JOIN answers a ON a.QuestionId = q.QuestionId
          WHERE q.QuestionId IN ($ph)",
        $qids);
} catch (Exception $e) {
    /* Bare fallback for very old schema (predates MATCH — these rows can
       never actually be QuestionType=MATCH, so NULLs here are safe). */
    $rows = Database::fetchAll(
        "SELECT q.QuestionId, q.CorrectAnswer, q.OperatorInd,
                q.QuestionDesc, q.ImageInd, q.ImageLoc, q.NumofImages,
                'MCQ' AS QuestionType,
                a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                a.AnsImageInd, a.MultiImageInd,
                NULL AS YesNo1, NULL AS YesNo2, NULL AS YesNo3, NULL AS YesNo4, 4 AS NumStatements,
                NULL AS MatchStatement1, NULL AS MatchStatement2, NULL AS MatchStatement3, NULL AS MatchStatement4,
                NULL AS MatchCorrect1, NULL AS MatchCorrect2, NULL AS MatchCorrect3, NULL AS MatchCorrect4
           FROM questions q JOIN answers a ON a.QuestionId = q.QuestionId
          WHERE q.QuestionId IN ($ph)",
        $qids);
}

/* Index by QuestionId.
   For MCQ/DROPDOWN: CorrectAnswer in DB = "1"–"4" (stored as plain digit).
   For MULTI: CorrectAnswer stored as "1,3" etc. — sorted canonical form.
   For YESNO: build a comma-separated correct string from YesNo1-4 columns. */
$questionData = [];
foreach ($rows as $r) {
    $qid   = (int)$r['QuestionId'];
    $qtype = $questionTypes[$qid] ?? ($r['QuestionType'] ?? 'MCQ');
    if ($qtype === 'YESNO') {
        $numStmt = max(1, min(4, (int)($r['NumStatements'] ?? 4)));
        $parts = [];
        for ($s = 1; $s <= $numStmt; $s++) {
            $parts[] = $r['YesNo'.$s] ?? 'Y';
        }
        $r['_correct'] = implode(',', $parts);
    } elseif ($qtype === 'MATCH') {
        // Build "<correct option #>,<correct option #>,..." — one entry per
        // statement position, taken from the dedicated MatchCorrect1-4 columns.
        $numStmt = max(1, min(4, (int)($r['NumStatements'] ?? 4)));
        $parts = [];
        for ($s = 1; $s <= $numStmt; $s++) {
            $parts[] = (string)((int)($r['MatchCorrect'.$s] ?? 0));
        }
        $r['_correct'] = implode(',', $parts);
    } elseif ($qtype === 'MULTI') {
        // CorrectAnswer stored as "1,3" — normalise to sorted canonical form
        $rawParts = array_filter(array_map('trim', explode(',', $r['CorrectAnswer'] ?? '')));
        // Strip legacy "Answer" prefix if still present (pre-migration rows)
        $rawParts = array_map(fn($p) => ltrim(str_ireplace('Answer', '', $p)), $rawParts);
        usort($rawParts, fn($a,$b) => strcmp($a,$b));
        $r['_correct'] = implode(',', $rawParts);  // e.g. "1,3"
    } else {
        // CorrectAnswer stored as "1"–"4"; strip legacy "Answer" prefix if present
        $raw = trim($r['CorrectAnswer'] ?? '');
        $r['_correct'] = ltrim(str_ireplace('Answer', '', $raw));
    }
    $questionData[$qid] = $r;
}

/* ── Score using the exam's configured marking scheme (Lib/Marking.php) ──── */
$totalQ            = count($submittedAnswers);
$marking           = Marking::resolve($exam, $totalQ);
$marksPerQ         = $marking['marksPerQuestion'];   // marks per fully-correct question
$negativeMarks     = $marking['negativeMarks'];      // marks deducted per fully-wrong question
$totalMarksForExam = $marking['totalMarks'];
$totalScore  = 0.0;
$correct     = 0;   // fully-correct question count
$wrong       = 0;   // wrong or partially-correct count
$skipped     = 0;   // no answer given
$qScores     = [];  // [qid => float marks earned]

foreach ($submittedAnswers as $qid => $chosen) {
    if (!isset($questionData[$qid])) { $skipped++; $qScores[$qid] = 0; continue; }
    $qtype       = $questionData[$qid]['QuestionType'] ?? $questionTypes[$qid] ?? 'MCQ';
    $correctNorm = $questionData[$qid]['_correct'];

    if ($chosen === '') {
        $skipped++;
        $qScores[$qid] = 0;
    } elseif ($qtype === 'MULTI') {
        // Multi-select partial credit:
        //   score = max(0, correct_selected - wrong_selected) / total_correct
        // This penalises wild over-selection while rewarding partial knowledge.
        if ($chosen === '') {
            $skipped++;
            $qScores[$qid] = 0;
        } else {
            $chosenSet  = array_filter(array_map('trim', explode(',', $chosen)));
            $correctSet = array_filter(array_map('trim', explode(',', $correctNorm)));
            $totalCorrect = max(1, count($correctSet));
            $correctSelected = count(array_intersect($chosenSet, $correctSet));
            $wrongSelected   = count(array_diff($chosenSet, $correctSet));
            $netCorrect      = max(0, $correctSelected - $wrongSelected);
            if ($correctSelected === $totalCorrect && $wrongSelected === 0) {
                $correct++;
                $earned = round($marksPerQ);
            } else {
                $wrong++;
                // No partial credit earned (fully wrong selection) -> apply negative marking
                $earned = ($netCorrect === 0) ? -round($negativeMarks)
                                               : round($netCorrect / $totalCorrect * $marksPerQ);
            }
            $qScores[$qid] = $earned;
            $totalScore   += $earned;
        }
    } elseif ($qtype === 'YESNO') {
        // Partial credit: award marks proportional to correctly-answered statements
        $chosenParts  = explode(',', $chosen);
        $correctParts = ($correctNorm !== '') ? explode(',', $correctNorm) : [];
        $numStmt      = max(1, count($correctParts));
        $correctStmts = 0;
        for ($s = 0; $s < $numStmt; $s++) {
            if (($chosenParts[$s] ?? '') !== '' && ($chosenParts[$s] ?? '') === ($correctParts[$s] ?? ''))
                $correctStmts++;
        }
        if ($correctStmts === $numStmt) {
            $correct++;
            $earned = round($marksPerQ);
        } elseif ($correctStmts === 0) {
            $wrong++;
            $earned = -round($negativeMarks);   // fully wrong -> apply negative marking
        } else {
            $wrong++;   // partial counts as wrong for question-count purposes
            $earned = round($correctStmts / $numStmt * $marksPerQ);
        }
        $qScores[$qid] = $earned;
        $totalScore   += $earned;
    } elseif ($qtype === 'MATCH') {
        // Drag & drop matching — partial credit proportional to how many
        // statement positions got the correct option dragged into them.
        // "0" in a position means the student left that statement unmatched.
        $chosenParts  = explode(',', $chosen);
        $correctParts = ($correctNorm !== '') ? explode(',', $correctNorm) : [];
        $numStmt      = max(1, count($correctParts));
        $correctStmts = 0;
        for ($s = 0; $s < $numStmt; $s++) {
            $cVal = $chosenParts[$s] ?? '0';
            if ($cVal !== '0' && $cVal === ($correctParts[$s] ?? ''))
                $correctStmts++;
        }
        if ($correctStmts === $numStmt) {
            $correct++;
            $earned = round($marksPerQ);
        } elseif ($correctStmts === 0) {
            $wrong++;
            $earned = -round($negativeMarks);   // fully wrong -> apply negative marking
        } else {
            $wrong++;   // partial counts as wrong for question-count purposes
            $earned = round($correctStmts / $numStmt * $marksPerQ);
        }
        $qScores[$qid] = $earned;
        $totalScore   += $earned;
    } else {
        // MCQ and DROPDOWN: all-or-nothing
        if ($chosen === $correctNorm) {
            $correct++;
            $earned = round($marksPerQ);
        } else {
            $wrong++;
            $earned = -round($negativeMarks);   // wrong answer -> apply negative marking
        }
        $qScores[$qid] = $earned;
        $totalScore   += $earned;
    }
}
$totalScore    = (int)round($totalScore);
/* Pass threshold: MinPassing is stored as a 0–100 percentage of TotalMarks.
   Clamp to valid range in case of legacy or misconfigured data. */
$passThreshold = min(100, max(0, (int)$passingMarks));
$percentScore  = $totalMarksForExam > 0 ? ($totalScore / $totalMarksForExam) * 100 : 0;
$passed        = ($percentScore >= $passThreshold);
$description   = $passed ? 'Pass' : 'Fail';

/* ── Batch-load operators ────────────────────────────────────────────────── */
$mathQids = [];
foreach ($questionData as $qid => $q) { if ($q['OperatorInd']==='Y') $mathQids[]=$qid; }
$operatorMap = [];
if ($mathQids) {
    $oph = implode(',', array_fill(0, count($mathQids), '?'));
    foreach (Database::fetchAll("SELECT * FROM quesoperators WHERE QuestionId IN ($oph)", $mathQids) as $op)
        $operatorMap[$op['QuestionId']] = $op;
}

/* ── Batch-load answer images ────────────────────────────────────────────── */
$answerIds    = array_column($rows, 'AnswerId');
$answerImages = [];
if ($answerIds) {
    $aph = implode(',', array_fill(0, count($answerIds), '?'));
    foreach (Database::fetchAll(
        "SELECT AnswerId, AnswerImage1Loc, AnswerImage2Loc, AnswerImage3Loc, AnswerImage4Loc
           FROM answerimages WHERE AnswerId IN ($aph)", $answerIds) as $img)
        $answerImages[$img['AnswerId']] = $img;
}

$m = (int)date('m'); $y = (int)date('Y');
$curYear = ($m <= 5) ? $y - 1 : $y;

/* ── Persist exam attempt — ACID transaction ─────────────────────────────── */
/* All core writes (studentexam + studentexamresults) run inside a single
   PDO transaction.  If any write fails the whole attempt is rolled back so
   the student is never left with a partial / corrupt record.
   The changelog and assignment-status updates happen AFTER commit because
   they are non-critical audit rows — a failure there must not erase results. */

$studentExamId = 0;
Database::beginTransaction();
try {
    /* 1. Insert the exam attempt header row.
          Try the fully-extended schema first (migration_v3 + v7 columns).
          Fall back to the minimal original schema if columns are missing. */
    $insertedFull = false;
    try {
        Database::execute(
            "INSERT INTO studentexam
                (UserInfoId,ExamInfoId,GradeInfoId,SubjectInfoId,
                 Score,MarksOutOf,Description,
                 TotalQuestions,CorrectCount,WrongCount,SkippedCount,
                 TimeTaken,ExamYear,ExamDate)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
            [$userInfoId,$examId,$gradeId,$subjectId,
             $totalScore,$totalMarksForExam,$description,
             $totalQ,$correct,$wrong,$skipped,
             $timeTaken,$curYear]);
        $insertedFull = true;
    } catch (Exception $e) {
        /* Extended columns not yet present — insert with migration_v3 columns only */
        try {
            Database::execute(
                "INSERT INTO studentexam
                    (UserInfoId,ExamInfoId,GradeInfoId,SubjectInfoId,
                     Score,MarksOutOf,Description,TimeTaken,ExamYear,ExamDate)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW())",
                [$userInfoId,$examId,$gradeId,$subjectId,
                 $totalScore,$totalMarksForExam,$description,$timeTaken,$curYear]);
        } catch (Exception $e2) {
            /* Absolute fallback — original schema only */
            Database::execute(
                "INSERT INTO studentexam (ExamInfoId,UserInfoId,TimeTaken,CreateDate)
                 VALUES (?,?,?,NOW())",
                [$examId,$userInfoId,$timeTaken]);
        }
    }
    $studentExamId = (int)Database::lastInsertId();

    /* 1b. Save proctoring violation count (migration_proctor.sql adds this column).
           Wrapped in try/catch so it never breaks submission on older schemas. */
    if ($violations > 0) {
        try {
            Database::execute(
                "UPDATE studentexam SET violations = ? WHERE StudentExamId = ?",
                [$violations, $studentExamId]);
        } catch (Exception $e) { /* column not yet added — run migration_proctor.sql */ }
    }

    /* 2. If we could not write extended columns via INSERT, try UPDATE.
          This handles the case where migration_v3 was applied after the first
          fallback insert. */
    if (!$insertedFull) {
        /* Update v3 columns */
        try {
            Database::execute(
                "UPDATE studentexam
                    SET Score=?,MarksOutOf=?,Description=?,ExamYear=?,ExamDate=NOW()
                  WHERE StudentExamId=?",
                [$totalScore,$totalMarksForExam,$description,$curYear,$studentExamId]);
        } catch (Exception $e) {}
        /* Update v7 count columns */
        try {
            Database::execute(
                "UPDATE studentexam
                    SET TotalQuestions=?,CorrectCount=?,WrongCount=?,SkippedCount=?
                  WHERE StudentExamId=?",
                [$totalQ,$correct,$wrong,$skipped,$studentExamId]);
        } catch (Exception $e) { /* run migration_v7.sql to enable dashboard counts */ }
    }

    /* 3. Insert one row per question into studentexamresults.
          Try with EarnedMarks/MarksPerQ (migration_v7) first; fall back if needed. */
    foreach ($submittedAnswers as $qid => $chosen) {
        $qtype       = $questionData[$qid]['QuestionType'] ?? $questionTypes[$qid] ?? 'MCQ';
        $correctNorm = $questionData[$qid]['_correct'] ?? '';
        $correctRaw  = in_array($qtype, ['YESNO', 'MATCH'], true)
                     ? $correctNorm
                     : ($questionData[$qid]['CorrectAnswer'] ?? '');
        $earned      = (float)($qScores[$qid] ?? 0);
        /* IsCorrect = Y only for fully-correct answers; partial YESNO = N */
        $isCorrect   = ($earned >= $marksPerQ - 0.01 && $chosen !== '') ? 'Y' : 'N';

        try {
            /* Full schema (migration_v3 + v7) */
            Database::execute(
                "INSERT INTO studentexamresults
                    (StudentExamId,QuestionId,StdAnswerId,
                     SelectedAnswer,CorrectAnswer,IsCorrect,
                     EarnedMarks,MarksPerQ)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$studentExamId,$qid,$chosen,
                 $chosen,$correctRaw,$isCorrect,
                 $earned,round($marksPerQ,4)]);
        } catch (Exception $e) {
            try {
                /* migration_v3 columns only */
                Database::execute(
                    "INSERT INTO studentexamresults
                        (StudentExamId,QuestionId,StdAnswerId,SelectedAnswer,CorrectAnswer,IsCorrect)
                     VALUES (?,?,?,?,?,?)",
                    [$studentExamId,$qid,$chosen,$chosen,$correctRaw,$isCorrect]);
            } catch (Exception $e2) {
                /* Original minimal schema */
                Database::execute(
                    "INSERT INTO studentexamresults (StudentExamId,QuestionId,StdAnswerId)
                     VALUES (?,?,?)",
                    [$studentExamId,$qid,$chosen]);
            }
        }
    }

    /* All core writes succeeded — commit the transaction */
    Database::commit();
} catch (Exception $ex) {
    Database::rollBack();
    error_log('submit.php transaction failed: ' . $ex->getMessage());
    die('An error occurred saving your results. Please try again.');
}

/* ── Non-critical: update assignment status ──────────────────────────────
   Also stamps StudentExamId so search.php's "Completed" row can link
   straight to *this* attempt's result — without it, a retake would leave
   the assignment pointing at a stale or missing StudentExamId. Falls back
   to a bare Status update on a schema that predates those columns, and
   logs a real failure instead of swallowing it silently: previously any
   error here (this whole block is best-effort, since the student's score
   is already committed above) left the exam looking permanently stuck on
   "Assigned"/"Pending" on My Exams with no trace of why. */
try {
    if ($userInfoId) {
        try {
            Database::execute(
                "UPDATE exam_assignments SET Status='Completed', CompletedAt=NOW(), StudentExamId=?
                  WHERE ExamInfoId=? AND UserInfoId=?",
                [$studentExamId, $examId, $userInfoId]);
        } catch (Exception $eCols) {
            // Older schema missing CompletedAt/StudentExamId — status flip alone
            // is still enough to get the exam out of "Assigned"/"Pending".
            Database::execute(
                "UPDATE exam_assignments SET Status='Completed' WHERE ExamInfoId=? AND UserInfoId=?",
                [$examId, $userInfoId]);
        }
    }
} catch (Exception $e) {
    error_log('submit.php: could not mark exam_assignments Completed for ExamInfoId='
        . $examId . ' UserInfoId=' . $userInfoId . ': ' . $e->getMessage());
}

/* ── Non-critical: exam changelog ─────────────────────────────────────── */
try {
    Database::execute(
        "INSERT INTO exam_changelog (ExamInfoId,ExamName,Action,ActionBy,Details)
         VALUES (?,?,?,?,?)",
        [$examId, $exam['ExamName'] ?? '', 'SUBMIT', $actionBy,
         "Score:{$totalScore} Correct:{$correct} Wrong:{$wrong} Skipped:{$skipped}"]);
} catch (Exception $e) {}

/* ── Load Explanation for each question ───────────────────────────────── */
$explanations = [];
if ($qids) {
    $ph = implode(',', array_fill(0, count($qids), '?'));
    try {
        foreach (Database::fetchAll(
            "SELECT QuestionId, Explanation FROM questions WHERE QuestionId IN ($ph)", $qids) as $ex)
            $explanations[(int)$ex['QuestionId']] = $ex['Explanation'] ?? '';
    } catch (Exception $e) { /* migration_v12 not yet applied */ }
}

/* ── Helper: resolve image path (relative paths are stored from Admin/) ── */
function resolveImgPath(string $raw): string {
    $raw = str_replace(' ', '', trim($raw));
    if ($raw === '') return '';
    if (strpos($raw, 'http') === 0 || strpos($raw, '//') === 0 || strpos($raw, '/') === 0) return $raw;
    if (strpos($raw, '../') === 0  || strpos($raw, './') === 0) return $raw;
    return '../Admin/' . $raw;
}

/* ── Helper: render explanation accordion ─────────────────────────────── */
function renderExplanation(string $text, int $qNum): void {
    if (trim($text) === '') return;
    $id = 'exp_' . $qNum;
    echo '<details class="explanation-block" id="' . $id . '" open>';
    echo '<summary class="explanation-toggle">&#128161; Explanation</summary>';
    echo '<div class="explanation-body">' . nl2br(htmlspecialchars($text)) . '</div>';
    echo '</details>';
}

/* ── HTML result page ─────────────────────────────────────────────────── */
$pageTitle = 'Exam Result';
$pageHead  = '
<style>
  .result-banner{display:flex;align-items:center;gap:14px;padding:12px 18px;border-radius:8px;flex-wrap:wrap;margin-bottom:16px;}
  .result-pass{background:#ecfdf5;border:2px solid #059669;}
  .result-fail{background:#fef2f2;border:2px solid #ef4444;}
  .result-verdict{font-size:.95rem;font-weight:900;letter-spacing:3px;padding:6px 18px;border-radius:6px;}
  .result-pass .result-verdict{background:#059669;color:#fff;}
  .result-fail .result-verdict{background:#ef4444;color:#fff;}
  .stat-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;}
  .stat-chip{padding:4px 12px;border-radius:14px;font-size:.8rem;font-weight:600;
             background:rgba(255,255,255,.8);border:1px solid rgba(0,0,0,.1);color:#334155;}

  .res-card{border:2px solid #e2e8f0;border-radius:8px;margin-bottom:12px;overflow:hidden;}
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
  .res-status{font-weight:700;font-size:.88rem;}
  .res-marks{margin-left:auto;font-size:.82rem;font-weight:700;}
  .res-card-body{padding:14px 16px;background:#fff;}
  .res-q-text{font-size:.9rem;line-height:1.5;color:#1e1b4b;margin-bottom:10px;}

  .res-mcq-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;}
  @media(max-width:600px){.res-mcq-grid{grid-template-columns:1fr;}}
  .res-opt{display:flex;align-items:center;gap:10px;padding:9px 12px;
           border:2px solid #e2e8f0;border-radius:6px;font-size:.88rem;}
  .res-opt.res-correct{border-color:#059669;background:#dcfce7;}
  .res-opt.res-wrong  {border-color:#ef4444;background:#fee2e2;}
  .res-opt.res-miss   {border-color:#d97706;background:#fffbeb;}
  .res-opt-badge{font-weight:800;min-width:28px;height:28px;border-radius:50%;background:#1e1b4b;
                 color:#fff;display:flex;align-items:center;justify-content:center;
                 font-size:.82rem;flex-shrink:0;}
  .res-opt.res-correct .res-opt-badge{background:#059669;}
  .res-opt.res-wrong   .res-opt-badge{background:#ef4444;}
  .res-opt.res-miss    .res-opt-badge{background:#d97706;}

  .res-q-img-wrap{margin:0 0 12px;}
  .res-q-img{max-width:100%;max-height:280px;border-radius:6px;border:1px solid #e2e8f0;display:block;}
  .res-opt-img{max-width:100px;vertical-align:middle;border-radius:3px;border:1px solid #e2e8f0;}

  .yn-res-tbl{width:100%;border-collapse:collapse;font-size:.875rem;margin-bottom:8px;}
  .yn-res-tbl th{background:#1e1b4b;color:#e0e7ff;padding:7px 12px;font-size:.82rem;}
  .yn-res-tbl th:first-child{text-align:left;}
  .yn-res-tbl td{padding:8px 12px;border-bottom:1px solid #e2e8f0;}
  .yn-res-tbl tr:last-child td{border-bottom:none;}
  .yn-badge{display:inline-flex;align-items:center;justify-content:center;
            width:30px;height:30px;border-radius:50%;font-weight:700;font-size:.8rem;}
  .yn-badge.yn-correct{background:#dcfce7;color:#065f46;border:2px solid #059669;}
  .yn-badge.yn-wrong  {background:#fee2e2;color:#991b1b;border:2px solid #ef4444;}

  /* ── Explanation accordion ───────────────────────────────────────────── */
  .explanation-block{margin-top:12px;border-radius:6px;overflow:hidden;
                     border:1px solid #c7d2fe;background:#eef2ff;}
  .explanation-toggle{list-style:none;padding:9px 14px;cursor:pointer;font-weight:600;
                      font-size:.85rem;color:#3730a3;user-select:none;display:flex;
                      align-items:center;gap:6px;}
  .explanation-toggle::-webkit-details-marker{display:none;}
  .explanation-toggle::after{content:"▾";margin-left:auto;transition:transform .2s;}
  details[open] .explanation-toggle::after{transform:rotate(-180deg);}
  .explanation-body{padding:10px 14px 12px;font-size:.875rem;line-height:1.6;
                    color:#1e40af;border-top:1px solid #c7d2fe;}

  /* ── One-question-at-a-time review navigator (same Prev/Next pattern as
     the live exam in write.php, and the same one added to result.php) —
     replaces scrolling through all N cards with a single card at a time
     plus a jump-to-any-question palette. */
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
include __DIR__ . '/../includes/header.php';
?>

<!-- Score Banner -->
<div class="result-banner <?php echo $passed ? 'result-pass' : 'result-fail'; ?>">
  <span class="result-verdict"><?php echo $passed ? 'PASS' : 'FAIL'; ?></span>
  <div>
    <div style="font-size:1.3rem;font-weight:900;color:<?php echo $passed?'#065f46':'#991b1b';?>;">
      <?php echo $totalScore; ?><span style="font-size:.9rem;font-weight:500;">/ <?php echo rtrim(rtrim(number_format($totalMarksForExam, 2), '0'), '.'); ?></span>
    </div>
    <div class="stat-chips">
      <span class="stat-chip">&#9989; <?php echo $correct; ?> correct</span>
      <span class="stat-chip">&#10060; <?php echo $wrong; ?> wrong</span>
      <span class="stat-chip">&#9644; <?php echo $skipped; ?> skipped</span>
      <span class="stat-chip">&#9201; <?php echo gmdate('i:s', $timeTaken); ?></span>
      <span class="stat-chip">Pass mark: <?php echo $passThreshold; ?>%</span>
    </div>
  </div>
  <div style="margin-left:auto;text-align:right;">
    <a href="search.php" class="btn btn-secondary btn-sm">&#128196; Back to Exams</a>
  </div>
</div>

<!-- Per-question review -->
<div class="card">
  <div class="card-header">&#128203; Question Review</div>
  <div class="card-body">

    <!-- Jump-to-any-question palette, colored by outcome — filled in by JS
         below from the rendered cards' status classes, then Prev/Next moves
         one question at a time, same pattern as write.php and result.php. -->
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
foreach ($submittedAnswers as $qid => $chosen):
    $qNum++;
    $qd      = $questionData[$qid] ?? null;
    $earned  = (float)($qScores[$qid] ?? 0);
    $qtype   = $questionTypes[$qid] ?? ($qd['QuestionType'] ?? 'MCQ');
    $corr    = $qd['_correct'] ?? '';
    $expText = $explanations[$qid] ?? '';

    /* Card class */
    if ($chosen === '') {
        $cardClass = 'q-skipped'; $statusLabel = 'Skipped';
    } elseif ($earned >= $marksPerQ - 0.01) {
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
    <span class="res-marks"><?php echo ($earned >= 0 ? '+' : '') . number_format($earned,1); ?> / <?php echo number_format($marksPerQ,1); ?> pts</span>
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
        $chosenSet  = $chosen !== '' ? array_filter(explode(',', $chosen)) : [];
        $correctSet = $corr  !== '' ? array_filter(explode(',', $corr))   : [];
        $answerMap  = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
      ?>
      <div class="res-mcq-grid">
      <?php foreach (['1','2','3','4'] as $n):
          $ltr       = $answerMap[$n];
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
          // Skip slots with nothing to show — keeps option count honest for
          // image answers too, since AnsImageInd is a question-level flag.
          if ($hasAnsImg ? $imgSrc === '' : $text === '') continue;
          $isCorrect  = in_array($n, $correctSet);
          $wasChosen  = in_array($n, $chosenSet);
          $cls = $isCorrect && $wasChosen ? 'res-correct'
               : (!$isCorrect && $wasChosen ? 'res-wrong'
               : ($isCorrect && !$wasChosen ? 'res-miss' : ''));
          $icon = $isCorrect && $wasChosen ? '✓' : (!$isCorrect && $wasChosen ? '✗' : ($isCorrect ? '★' : ''));
      ?>
      <div class="res-opt <?php echo $cls; ?>">
        <span class="res-opt-badge"><?php echo $ltr; ?></span>
        <?php if ($hasAnsImg): ?>
          <?php if ($imgSrc): ?><img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="" class="res-opt-img"><?php endif; ?>
        <?php else: ?>
          <span><?php echo htmlspecialchars($text); ?></span>
        <?php endif; ?>
        <?php if ($icon): ?><span style="margin-left:auto;font-weight:900;"><?php echo $icon; ?></span><?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
      <div style="font-size:.8rem;color:#64748b;margin-top:4px;">
        &#9745; Correct answers: <?php echo implode(', ', array_map(fn($a)=>$answerMap[$a]??$a, $correctSet)); ?>
      </div>

    <?php elseif ($qtype === 'YESNO'): ?>
      <?php
        $chosenParts  = $chosen !== '' ? explode(',', $chosen) : [];
        $correctParts = $corr   !== '' ? explode(',', $corr)   : [];
        $numStmt = max(1, count($correctParts));
      ?>
      <table class="yn-res-tbl">
        <thead><tr><th>Statement</th><th>Your Answer</th><th>Correct</th></tr></thead>
        <tbody>
        <?php for ($s = 1; $s <= $numStmt; $s++):
            $stmtText = $qd['Answer'.$s] ?? '';
            if ($stmtText === '') continue;
            $studentAns = $chosenParts[$s-1] ?? '';
            $correctAns = $correctParts[$s-1] ?? '';
            $match = ($studentAns === $correctAns && $studentAns !== '');
        ?>
        <tr>
          <td><?php echo htmlspecialchars($stmtText); ?></td>
          <td style="text-align:center;">
            <span class="yn-badge <?php echo $match?'yn-correct':'yn-wrong'; ?>">
              <?php echo $studentAns !== '' ? $studentAns : '—'; ?>
            </span>
          </td>
          <td style="text-align:center;">
            <span class="yn-badge yn-correct"><?php echo $correctAns; ?></span>
          </td>
        </tr>
        <?php endfor; ?>
        </tbody>
      </table>

    <?php elseif ($qtype === 'MATCH'): ?>
      <?php
        $chosenParts  = $chosen !== '' ? explode(',', $chosen) : [];
        $correctParts = $corr   !== '' ? explode(',', $corr)   : [];
        $numStmt = max(1, count($correctParts));
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
            <span class="yn-badge <?php echo $match?'yn-correct':'yn-wrong'; ?>"
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
        $chosenNum  = is_numeric($chosen) ? (int)$chosen : (int)ltrim(str_ireplace('answer','',$chosen));
        $correctNum = is_numeric($corr)   ? (int)$corr   : (int)ltrim(str_ireplace('answer','',$corr));
        $labels = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
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
          // Skip slots with nothing to show — keeps option count honest for
          // image answers too, since AnsImageInd is a question-level flag.
          if ($hasAnsImg ? $imgSrc === '' : $text === '') continue;
          $isC = ((int)$n === $correctNum);
          $isS = ((int)$n === $chosenNum);
          $cls = $isC && $isS ? 'res-correct' : (!$isC && $isS ? 'res-wrong' : ($isC ? 'res-miss' : ''));
      ?>
      <div class="res-opt <?php echo $cls; ?>">
        <span class="res-opt-badge"><?php echo $ltr; ?></span>
        <?php if ($hasAnsImg): ?>
          <?php if ($imgSrc): ?><img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="" class="res-opt-img"><?php endif; ?>
        <?php else: ?>
          <span><?php echo htmlspecialchars($text); ?></span>
        <?php endif; ?>
        <?php if ($isC && $isS): ?><span style="margin-left:auto;">✓</span>
        <?php elseif (!$isC && $isS): ?><span style="margin-left:auto;">✗</span>
        <?php elseif ($isC): ?><span style="margin-left:auto;color:#d97706;">★</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php renderExplanation($expText, $qNum); ?>

    <?php endif; /* $qd */ ?>

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

<div style="text-align:center;padding:8px 0 20px;">
  <a href="search.php" class="btn btn-primary">&#128196; Back to Exams</a>
  <?php if (Auth::isAdmin()): ?>
  <a href="../Admin/Index.php" class="btn btn-secondary" style="margin-left:8px;">&#128100; Admin Home</a>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
