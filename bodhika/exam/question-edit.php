<?php
/**
 * exam/question-edit.php
 * Add or edit a question — supports MCQ, DROPDOWN, and YESNO question types.
 * Admin / Teacher / Principal only.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');

if (!Auth::isAdmin()) { header('Location: search.php'); exit; }

$examId = filter_input(INPUT_GET,  'examId', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'examId', FILTER_VALIDATE_INT);
$qid    = filter_input(INPUT_GET,  'qid',    FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_POST, 'qid',    FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

/* ── This exam's subject(s) ──────────────────────────────────────────────
   Single-subject exam (the default): questions auto-inherit examinfo's own
   SubjectInfoId, exactly as before — no dropdown needed.
   Multi-subject exam (migration_v54, e.g. a NEET paper): the exam itself
   has no single SubjectInfoId, so the admin must say which of the exam's
   configured sections (Physics/Chemistry/Botany/Zoology, ...) each question
   belongs to — $examSubjectChoices below drives that dropdown. */
$exam = Database::fetchOne(
    "SELECT ExamName, SubjectInfoId, IsMultiSubject FROM examinfo WHERE ExamInfoId=? LIMIT 1", [$examId]);

$isMultiSubjectExam  = (($exam['IsMultiSubject'] ?? 'N') === 'Y');
$examSubjectChoices  = [];   // [SubjectInfoId => SubjectName] for the dropdown
if ($isMultiSubjectExam) {
    try {
        $secRows = Database::fetchAll(
            "SELECT es.SubjectInfoId, COALESCE(es.SectionLabel, sub.SubjectName) AS Label
               FROM exam_sections es
          LEFT JOIN subjectinfo sub ON sub.SubjectInfoId = es.SubjectInfoId
              WHERE es.ExamInfoId = ?
              ORDER BY es.SortOrder, es.ExamSectionId", [$examId]);
        foreach ($secRows as $sr) { $examSubjectChoices[(int)$sr['SubjectInfoId']] = $sr['Label']; }
    } catch (Exception $e) {
        $isMultiSubjectExam = false; // migration_v54 not run — behave as single-subject
    }
}

/* ── Upload helper ───────────────────────────────────────────────────────── */
function handleUpload(string $fieldName, ?string $existing = null): ?string {
    if (empty($_FILES[$fieldName]['tmp_name'])) return $existing;
    $file = $_FILES[$fieldName];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true)) return $existing;
    if ($file['size'] > 5 * 1024 * 1024) return $existing;
    $dir = dirname(__DIR__) . '/Admin/images/exam/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = uniqid('q_', true) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . $name)
        ? 'images/exam/' . $name : $existing;
}

/* ── Load existing question for edit ─────────────────────────────────────── */
/* After migration_v22: questions are no longer bound to one exam.
   Verify the question is assigned to this exam via exam_questions.
   Falls back to ExamInfoId check for pre-migration schemas.            */
$q = null; $ans = null; $ansImgs = null;
if ($qid) {
    try {
        $q = Database::fetchOne(
            "SELECT q.* FROM questions q
              JOIN exam_questions eq ON eq.QuestionId = q.QuestionId AND eq.ExamInfoId = ?
             WHERE q.QuestionId = ? AND COALESCE(q.IsDeleted,'N') = 'N' LIMIT 1",
            [$examId, $qid]);
    } catch (Exception $e) {
        /* exam_questions not yet created — fall back to old ExamInfoId column,
           and/or IsDeleted doesn't exist yet (migration_v43 not run). */
        try {
            $q = Database::fetchOne(
                "SELECT * FROM questions WHERE QuestionId=? AND ExamInfoId=? AND COALESCE(IsDeleted,'N') = 'N' LIMIT 1",
                [$qid, $examId]);
        } catch (Exception $e2) {
            $q = Database::fetchOne(
                "SELECT * FROM questions WHERE QuestionId=? AND ExamInfoId=? LIMIT 1",
                [$qid, $examId]);
        }
    }
    if (!$q) { header('Location: questions.php?examId='.$examId); exit; }
    $ans     = Database::fetchOne("SELECT * FROM answers     WHERE QuestionId=? LIMIT 1", [$qid]);
    $ansImgs = Database::fetchOne("SELECT * FROM answerimages WHERE AnswerId=?    LIMIT 1",
                                   [$ans['AnswerId'] ?? 0]);
}

/* ── Defaults ────────────────────────────────────────────────────────────── */
$defaults = [
    'QuestionType' => $q['QuestionType']  ?? 'MCQ',
    'QuestionDesc' => $q['QuestionDesc']  ?? '',
    'ImageInd'     => $q['ImageInd']      ?? 'N',
    'ImageLoc'     => $q['ImageLoc']      ?? '',
    'CorrectAnswer'=> $q['CorrectAnswer'] ?? '',
    'Complexity'   => $q['Complexity']    ?? 'Medium',
    'IsActive'     => $q['IsActive']      ?? 'Y',
    'ChapterInfoId'=> $q['ChapterInfoId'] ?? 0,
    'CaseStudyId'  => $q['CaseStudyId']   ?? 0,
    'SubjectInfoId'=> $q['SubjectInfoId'] ?? 0,
    'Answer1'      => $ans['Answer1']     ?? '',
    'Answer2'      => $ans['Answer2']     ?? '',
    'Answer3'      => $ans['Answer3']     ?? '',
    'Answer4'      => $ans['Answer4']     ?? '',
    'YesNo1'       => $ans['YesNo1']      ?? '',
    'YesNo2'       => $ans['YesNo2']      ?? '',
    'YesNo3'       => $ans['YesNo3']      ?? '',
    'YesNo4'       => $ans['YesNo4']      ?? '',
    // MATCH (drag & drop): left-side option text re-uses Answer1-4; right-side
    // statement text + the correct-option mapping get their own columns.
    'MatchOption1'    => $ans['Answer1']          ?? '',
    'MatchOption2'    => $ans['Answer2']          ?? '',
    'MatchOption3'    => $ans['Answer3']          ?? '',
    'MatchOption4'    => $ans['Answer4']          ?? '',
    'MatchStatement1' => $ans['MatchStatement1']  ?? '',
    'MatchStatement2' => $ans['MatchStatement2']  ?? '',
    'MatchStatement3' => $ans['MatchStatement3']  ?? '',
    'MatchStatement4' => $ans['MatchStatement4']  ?? '',
    'MatchCorrect1'   => $ans['MatchCorrect1']    ?? '',
    'MatchCorrect2'   => $ans['MatchCorrect2']    ?? '',
    'MatchCorrect3'   => $ans['MatchCorrect3']    ?? '',
    'MatchCorrect4'   => $ans['MatchCorrect4']    ?? '',
    'NumStatements'=> (int)($ans['NumStatements'] ?? 3),
    // NumOptions: number of answer choices for MCQ/DROPDOWN (2-4, default 4)
    // Re-uses NumStatements column; YESNO uses it separately for statement count.
    'NumOptions'   => in_array($q['QuestionType'] ?? 'MCQ', ['MCQ','DROPDOWN'])
                      ? max(2, min(4, (int)($ans['NumStatements'] ?? 4)))
                      : 4,
    'AnsImageInd'  => $ans['AnsImageInd']    ?? 'N',
    'MultiImageInd'=> $ans['MultiImageInd']  ?? 'N',
    'AnsImg1'      => $ansImgs['AnswerImage1Loc'] ?? '',
    'AnsImg2'      => $ansImgs['AnswerImage2Loc'] ?? '',
    'AnsImg3'      => $ansImgs['AnswerImage3Loc'] ?? '',
    'AnsImg4'            => $ansImgs['AnswerImage4Loc'] ?? '',
    'ExpectedAnswerCount' => (int)($q['ExpectedAnswerCount'] ?? 0),
    'Explanation'         => $q['Explanation'] ?? '',
    // How many correct answers this question has (1 for MCQ, 2-3 for multi-correct)
    'CorrectAnswerCount'  => (($q['QuestionType'] ?? 'MCQ') === 'MULTI')
        ? max(1, count(array_filter(array_map('trim', explode(',', $q['CorrectAnswer'] ?? '')))))
        : 1,
    // For pre-filling checkboxes when editing a MULTI question
    'MultiCorrectArr'     => (($q['QuestionType'] ?? 'MCQ') === 'MULTI')
        ? array_filter(array_map('trim', explode(',', $q['CorrectAnswer'] ?? '')))
        : [],
];

$errors = [];

/* ── Handle POST ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    /* AJAX: delete a question from the "Saved This Session" list — a
       lightweight action distinct from the full save flow below, so a
       question added by mistake this session can be removed with one
       click without leaving this page. Soft-delete, matching the same
       convention already used by exam/questions.php's own delete handler. */
    if (isset($_POST['ajax_delete_qid'])) {
        header('Content-Type: application/json');
        $delQid = (int)$_POST['ajax_delete_qid'];
        if ($delQid <= 0) {
            echo json_encode(['success' => false, 'errors' => ['Invalid question ID.']]);
            exit;
        }
        try {
            Database::execute(
                "UPDATE questions SET IsDeleted='Y', DeletedAt=NOW(), DeletedBy=? WHERE QuestionId=?",
                [Auth::currentUser() ?: 'admin', $delQid]
            );
        } catch (Exception $e) { /* migration_v43 not yet run — nothing to do until it is */ }
        echo json_encode(['success' => true]);
        exit;
    }

    $qType      = $_POST['QuestionType'] ?? 'MCQ';
    $qDesc      = trim($_POST['QuestionDesc'] ?? '');
    $imageInd   = isset($_POST['hasQImage'])  ? 'Y' : 'N';
    $ansImgInd  = isset($_POST['hasAnsImage'])? 'Y' : 'N';
    $multiImg   = isset($_POST['multiImage']) ? 'Y' : 'N';
    $complexity = $_POST['Complexity']   ?? 'Medium';
    $isActive   = isset($_POST['IsActive']) ? 'Y' : 'N';
    $chapterInfoId = (int)($_POST['ChapterInfoId'] ?? 0) ?: null;
    $caseStudyId   = (int)($_POST['CaseStudyId']   ?? 0) ?: null;

    /* Subject (migration_v54): a multi-subject exam has no single
       examinfo.SubjectInfoId, so the admin must pick which of the exam's
       configured sections this question belongs to — validated against
       $examSubjectChoices so a tampered/stale value can't slip in.
       Single-subject exams keep inheriting the exam's own subject, exactly
       as before (handled in the save block below). */
    $questionSubjectId = null;
    if ($isMultiSubjectExam) {
        $postedSubjectId = (int)($_POST['SubjectInfoId'] ?? 0);
        if ($postedSubjectId && isset($examSubjectChoices[$postedSubjectId])) {
            $questionSubjectId = $postedSubjectId;
        } else {
            $errors[] = 'Please select which subject this question belongs to.';
        }
    }

    if (!in_array($qType, ['MCQ','DROPDOWN','YESNO','MULTI','MATCH'], true)) $qType = 'MCQ';

    // Auto-upgrade MCQ → MULTI when admin chose multiple correct answers
    $corrCount = max(1, min(3, (int)($_POST['CorrectAnswerCount'] ?? 1)));
    if ($qType === 'MCQ' && $corrCount > 1) { $qType = 'MULTI'; }

    // MATCH-specific columns — default to null so the shared SAVE block below
    // always has a value to bind, regardless of which type branch ran.
    $matchStmt1 = $matchStmt2 = $matchStmt3 = $matchStmt4 = null;
    $matchCorr1 = $matchCorr2 = $matchCorr3 = $matchCorr4 = null;

    /* ── Type-specific field collection & validation ── */
    if ($qType === 'MULTI') {
        /* Multi-select: collect answer texts + which are correct + expected count.
           Accepts both MultiAnswer1-4 (old separate section) and Answer1-4 (MCQ section). */
        $a1 = trim($_POST['MultiAnswer1'] ?? $_POST['Answer1'] ?? '');
        $a2 = trim($_POST['MultiAnswer2'] ?? $_POST['Answer2'] ?? '');
        $a3 = trim($_POST['MultiAnswer3'] ?? $_POST['Answer3'] ?? '');
        $a4 = trim($_POST['MultiAnswer4'] ?? $_POST['Answer4'] ?? '');
        $numStmt = 4;
        $yn1 = $yn2 = $yn3 = $yn4 = null;
        $multiCorrectRaw = $_POST['MultiCorrect'] ?? [];
        $validOpts = ['1','2','3','4'];
        $multiCorrects = array_values(array_filter($multiCorrectRaw, fn($v) => in_array($v, $validOpts)));
        $correct  = implode(',', $multiCorrects);   // e.g. "1,3"
        // ExpectedAnswerCount: from dedicated field or from CorrectAnswerCount stepper
        $expCount = (int)($_POST['ExpectedAnswerCount'] ?? $_POST['CorrectAnswerCount'] ?? count($multiCorrects));
        if ($expCount < 2) $expCount = count($multiCorrects);   // fallback to actual correct count
        if ($qDesc === '' && $imageInd !== 'Y')
            $errors[] = 'Question text is required (or upload an image).';
        if (count($multiCorrects) < 2)
            $errors[] = 'Select at least 2 correct answers for a multi-correct question.';
        if ($a1===''||$a2===''||$a3===''||$a4==='')
            $errors[] = 'All four answer options are required.';
    } elseif ($qType === 'YESNO') {
        $numStmt = max(2, min(4, (int)($_POST['NumStatements'] ?? 3)));
        $stmts   = [];
        $ynCorr  = [];
        for ($s = 1; $s <= $numStmt; $s++) {
            $stmts[$s]  = trim($_POST['Statement'.$s] ?? '');
            $ynCorr[$s] = $_POST['YesNo'.$s] ?? '';
        }
        // Validation
        if ($qDesc === '') $errors[] = 'Instructions/context text is required.';
        for ($s = 1; $s <= $numStmt; $s++) {
            if ($stmts[$s] === '') $errors[] = "Statement {$s} text is required.";
            if (!in_array($ynCorr[$s], ['Y','N'], true))
                $errors[] = "Select Yes or No for Statement {$s}.";
        }
        $correct   = '';   // not used for YESNO — '' (not null) avoids NOT NULL violations on legacy schemas
        $a1 = $stmts[1] ?? ''; $a2 = $stmts[2] ?? '';
        $a3 = $stmts[3] ?? ''; $a4 = $stmts[4] ?? '';
        $yn1 = $ynCorr[1] ?? null; $yn2 = $ynCorr[2] ?? null;
        $yn3 = $ynCorr[3] ?? null; $yn4 = $ynCorr[4] ?? null;
        $expCount  = $numStmt;   // avoid NOT NULL violation on ExpectedAnswerCount
    } elseif ($qType === 'MATCH') {
        /* Drag & drop matching: NumStatements column re-used as "number of pairs".
           Answer1-4 hold the draggable option text; MatchStatement1-4 hold the
           right-hand statement text; MatchCorrect1-4 hold which option number
           (1-4) is the correct match for that statement position. */
        $numStmt = max(2, min(4, (int)($_POST['MatchPairs'] ?? 3)));
        $opts = []; $stmts = []; $corrOpt = [];
        for ($s = 1; $s <= $numStmt; $s++) {
            $opts[$s]    = trim($_POST['MatchOption'.$s] ?? '');
            $stmts[$s]   = trim($_POST['MatchStatement'.$s] ?? '');
            $corrOpt[$s] = (int)($_POST['MatchCorrect'.$s] ?? 0);
        }
        if ($qDesc === '') $errors[] = 'Instructions/context text is required.';
        for ($s = 1; $s <= $numStmt; $s++) {
            if ($opts[$s]  === '') $errors[] = "Option {$s} text is required.";
            if ($stmts[$s] === '') $errors[] = "Statement {$s} text is required.";
            if ($corrOpt[$s] < 1 || $corrOpt[$s] > $numStmt)
                $errors[] = "Select the correct option for Statement {$s}.";
        }
        $correct = '';   // not used for MATCH — see MatchCorrect1-4 instead — '' avoids NOT NULL violations on legacy schemas
        $a1 = $opts[1] ?? ''; $a2 = $opts[2] ?? '';
        $a3 = $opts[3] ?? ''; $a4 = $opts[4] ?? '';
        $yn1 = $yn2 = $yn3 = $yn4 = null;
        $matchStmt1 = $stmts[1] ?? null; $matchStmt2 = $stmts[2] ?? null;
        $matchStmt3 = $stmts[3] ?? null; $matchStmt4 = $stmts[4] ?? null;
        $matchCorr1 = $corrOpt[1] ?? null; $matchCorr2 = $corrOpt[2] ?? null;
        $matchCorr3 = $corrOpt[3] ?? null; $matchCorr4 = $corrOpt[4] ?? null;
        $expCount   = $numStmt;   // 0 was risky on NOT NULL cols; pairs count is meaningful here too
    } else {
        /* MCQ or DROPDOWN — variable number of options (2, 3, or 4) */
        $numOptions = max(2, min(4, (int)($_POST['NumOptions'] ?? 4)));
        $correct    = $_POST['CorrectAnswer'] ?? '';
        $a1 = trim($_POST['Answer1'] ?? '');
        $a2 = trim($_POST['Answer2'] ?? '');
        $a3 = $numOptions >= 3 ? trim($_POST['Answer3'] ?? '') : '';
        $a4 = $numOptions >= 4 ? trim($_POST['Answer4'] ?? '') : '';
        $numStmt    = $numOptions;   // stored in NumStatements column
        $yn1 = $yn2 = $yn3 = $yn4 = null;
        $expCount   = 1;   // single correct answer — avoid NOT NULL violation on ExpectedAnswerCount

        $validCorrects = array_slice(['1','2','3','4'], 0, $numOptions);

        if ($qDesc === '' && $imageInd !== 'Y')
            $errors[] = 'Question text is required (or upload an image).';
        if (!in_array($correct, $validCorrects, true))
            $errors[] = 'Please select the correct answer (only options shown are valid).';
        if ($ansImgInd === 'N') {
            $optLabels = ['A','B','C','D'];
            for ($i = 1; $i <= $numOptions; $i++) {
                $aVal = ($i === 1) ? $a1 : (($i === 2) ? $a2 : (($i === 3) ? $a3 : $a4));
                if ($aVal === '')
                    $errors[] = 'Option ' . $optLabels[$i-1] . ' is required.';
            }
        }
    }

    if (!in_array($complexity, ['Low','Medium','High'], true)) $errors[] = 'Invalid complexity.';

    if (!$errors) {
        try {
        $qImgLoc = handleUpload('qImage', $defaults['ImageLoc']);

        /* ── Save question ───────────────────────────────────────────────────
           After migration_v22 the questions table has no ExamInfoId.
           New questions are inserted into questions (pure data), then a row
           is added to exam_questions to assign them to this exam.
           The try/catch falls back to the legacy ExamInfoId-based INSERT for
           pre-migration databases.                                           */
        $explanation = trim($_POST['Explanation'] ?? '') ?: null;
        $eqIsActive  = $isActive;   // exam-level active flag (goes to exam_questions)
        try {
            if ($qid) {
                /* Edit existing question — update content only, not exam assignment.
                   SubjectInfoId only changes here for a multi-subject exam, where
                   $questionSubjectId was just validated above against this exam's
                   configured sections; for a single-subject exam we leave the
                   question's inherited subject alone (COALESCE keeps its current
                   value when $questionSubjectId is null). */
                try {
                    Database::execute(
                        "UPDATE questions
                            SET QuestionDesc=?, ImageInd=?, ImageLoc=?,
                                CorrectAnswer=?, Complexity=?, QuestionType=?,
                                ExpectedAnswerCount=?, Explanation=?, ChapterInfoId=?, CaseStudyId=?,
                                SubjectInfoId=COALESCE(?, SubjectInfoId)
                          WHERE QuestionId=?",
                        [$qDesc, $imageInd, $qImgLoc, $correct, $complexity, $qType,
                         $expCount ?? null, $explanation, $chapterInfoId, $caseStudyId, $questionSubjectId, $qid]);
                } catch (Exception $e) {
                    /* migration_v54 not yet run — questions.SubjectInfoId doesn't exist yet */
                    Database::execute(
                        "UPDATE questions
                            SET QuestionDesc=?, ImageInd=?, ImageLoc=?,
                                CorrectAnswer=?, Complexity=?, QuestionType=?,
                                ExpectedAnswerCount=?, Explanation=?, ChapterInfoId=?, CaseStudyId=?
                          WHERE QuestionId=?",
                        [$qDesc, $imageInd, $qImgLoc, $correct, $complexity, $qType,
                         $expCount ?? null, $explanation, $chapterInfoId, $caseStudyId, $qid]);
                }
                /* Update IsActive on the exam_questions row, not on the question itself */
                try {
                    Database::execute(
                        "UPDATE exam_questions SET IsActive=? WHERE ExamInfoId=? AND QuestionId=?",
                        [$eqIsActive, $examId, $qid]);
                } catch (Exception $e) { /* exam_questions not yet created — skip */ }
            } else {
                /* New question's subject: a multi-subject exam uses the admin's
                   explicit choice ($questionSubjectId, validated above); a
                   single-subject exam keeps auto-inheriting the exam's own
                   SubjectInfoId, exactly as before. */
                if ($questionSubjectId !== null) {
                    $subjectId = $questionSubjectId;
                } else {
                    $subjectRow = Database::fetchOne(
                        "SELECT SubjectInfoId FROM examinfo WHERE ExamInfoId=? LIMIT 1", [$examId]);
                    $subjectId  = (int)($subjectRow['SubjectInfoId'] ?? 0) ?: null;
                }

                Database::execute(
                    "INSERT INTO questions
                        (SubjectInfoId, ChapterInfoId, CaseStudyId, QuestionDesc, ImageInd, ImageLoc, NumofImages,
                         OperatorInd, CorrectAnswer, Complexity, IsActive, QuestionType,
                         ExpectedAnswerCount, Explanation)
                     VALUES (?,?,?,?,?,?,1,'N',?,?,?,?,?,?)",
                    [$subjectId, $chapterInfoId, $caseStudyId, $qDesc, $imageInd, $qImgLoc, $correct, $complexity,
                     $eqIsActive, $qType, $expCount ?? null, $explanation]);
                $qid = (int)Database::lastInsertId();

                /* Assign to this exam */
                try {
                    Database::execute(
                        "INSERT IGNORE INTO exam_questions (ExamInfoId, QuestionId, IsActive)
                         VALUES (?, ?, ?)",
                        [$examId, $qid, $eqIsActive]);
                } catch (Exception $e) {
                    /* exam_questions insert failed — log why, then also write ExamInfoId
                       the old way so the question is at least findable via legacy lookups. */
                    error_log('exam_questions insert failed for QuestionId=' . $qid . ': ' . $e->getMessage());
                    try {
                        Database::execute(
                            "UPDATE questions SET ExamInfoId=? WHERE QuestionId=?",
                            [$examId, $qid]);
                    } catch (Exception $e2) {}
                }
            }
        } catch (Exception $e) {
            /* Fallback: pre-migration schema (no ExpectedAnswerCount/Explanation columns,
               or ExamInfoId still lives on questions). Log the real reason so this is
               diagnosable instead of silently degrading every time. */
            error_log('Primary question save failed (QuestionId=' . ($qid ?: 'new') . '): ' . $e->getMessage());
            if ($qid) {
                Database::execute(
                    "UPDATE questions SET QuestionDesc=?,ImageInd=?,ImageLoc=?,CorrectAnswer=?,
                                         Complexity=?,IsActive=?,QuestionType=?
                      WHERE QuestionId=?",
                    [$qDesc, $imageInd, $qImgLoc, $correct, $complexity, $eqIsActive, $qType, $qid]);
            } else {
                Database::execute(
                    "INSERT INTO questions (ExamInfoId,QuestionDesc,ImageInd,ImageLoc,NumofImages,
                                            OperatorInd,CorrectAnswer,Complexity,IsActive,QuestionType)
                     VALUES (?,?,?,?,1,'N',?,?,?,?)",
                    [$examId, $qDesc, $imageInd, $qImgLoc, $correct, $complexity, $eqIsActive, $qType]);
                $qid = (int)Database::lastInsertId();
                /* Self-heal: still link via exam_questions so the question shows up
                   in the (current) exam_questions-based listing, not just the legacy column. */
                try {
                    Database::execute(
                        "INSERT IGNORE INTO exam_questions (ExamInfoId, QuestionId, IsActive) VALUES (?, ?, ?)",
                        [$examId, $qid, $eqIsActive]);
                } catch (Exception $e3) {}
            }
        }

        /* Save answers */
        $existingAns = Database::fetchOne("SELECT AnswerId FROM answers WHERE QuestionId=? LIMIT 1", [$qid]);
        try {
            if ($existingAns) {
                Database::execute(
                    "UPDATE answers
                        SET Answer1=?,Answer2=?,Answer3=?,Answer4=?,
                            AnsImageInd=?,MultiImageInd=?,
                            YesNo1=?,YesNo2=?,YesNo3=?,YesNo4=?,NumStatements=?,
                            MatchStatement1=?,MatchStatement2=?,MatchStatement3=?,MatchStatement4=?,
                            MatchCorrect1=?,MatchCorrect2=?,MatchCorrect3=?,MatchCorrect4=?
                      WHERE QuestionId=?",
                    [$a1,$a2,$a3,$a4,$ansImgInd,$multiImg,
                     $yn1,$yn2,$yn3,$yn4,$numStmt,
                     $matchStmt1,$matchStmt2,$matchStmt3,$matchStmt4,
                     $matchCorr1,$matchCorr2,$matchCorr3,$matchCorr4,$qid]);
                $answerId = (int)$existingAns['AnswerId'];
            } else {
                Database::execute(
                    "INSERT INTO answers
                        (QuestionId,Answer1,Answer2,Answer3,Answer4,
                         AnsImageInd,MultiImageInd,YesNo1,YesNo2,YesNo3,YesNo4,NumStatements,
                         MatchStatement1,MatchStatement2,MatchStatement3,MatchStatement4,
                         MatchCorrect1,MatchCorrect2,MatchCorrect3,MatchCorrect4)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$qid,$a1,$a2,$a3,$a4,$ansImgInd,$multiImg,
                     $yn1,$yn2,$yn3,$yn4,$numStmt,
                     $matchStmt1,$matchStmt2,$matchStmt3,$matchStmt4,
                     $matchCorr1,$matchCorr2,$matchCorr3,$matchCorr4]);
                $answerId = (int)Database::lastInsertId();
            }
        } catch (Exception $e) {
            /* YesNo columns not yet added — run migration_v6.sql; save base columns only */
            if ($existingAns) {
                Database::execute(
                    "UPDATE answers SET Answer1=?,Answer2=?,Answer3=?,Answer4=?,AnsImageInd=?,MultiImageInd=?
                      WHERE QuestionId=?",
                    [$a1,$a2,$a3,$a4,$ansImgInd,$multiImg,$qid]);
                $answerId = (int)$existingAns['AnswerId'];
            } else {
                Database::execute(
                    "INSERT INTO answers (QuestionId,Answer1,Answer2,Answer3,Answer4,AnsImageInd,MultiImageInd)
                     VALUES (?,?,?,?,?,?,?)",
                    [$qid,$a1,$a2,$a3,$a4,$ansImgInd,$multiImg]);
                $answerId = (int)Database::lastInsertId();
            }
        }

        /* Answer images */
        if ($ansImgInd === 'Y' && $multiImg === 'Y') {
            $img1 = handleUpload('ansImg1', $defaults['AnsImg1']);
            $img2 = handleUpload('ansImg2', $defaults['AnsImg2']);
            $img3 = handleUpload('ansImg3', $defaults['AnsImg3']);
            $img4 = handleUpload('ansImg4', $defaults['AnsImg4']);
            $existingAI = Database::fetchOne("SELECT AnswerId FROM answerimages WHERE AnswerId=? LIMIT 1", [$answerId]);
            if ($existingAI) {
                Database::execute(
                    "UPDATE answerimages SET AnswerImage1Loc=?,AnswerImage2Loc=?,AnswerImage3Loc=?,AnswerImage4Loc=? WHERE AnswerId=?",
                    [$img1,$img2,$img3,$img4,$answerId]);
            } else {
                Database::execute(
                    "INSERT INTO answerimages (AnswerId,AnswerImage1Loc,AnswerImage2Loc,AnswerImage3Loc,AnswerImage4Loc)
                     VALUES (?,?,?,?,?)",
                    [$answerId,$img1,$img2,$img3,$img4]);
            }
        }

        /* ── AJAX response (multi-add session) ─────────────────────────────── */
        if (!empty($_POST['_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'qid'     => $qid,
                'qText'   => $qDesc ?: '(image question)',
                'qType'   => $qType,
            ]);
            exit;
        }
        header('Location: questions.php?examId='.$examId.'&saved=1'); exit;
        } catch (\Throwable $e) {
            /* Any uncaught DB/PHP error during save lands here instead of crashing
               the script — this is what previously produced a broken/non-JSON
               response and the client's generic "Network error" message. */
            error_log('Question save failed (QuestionId=' . ($qid ?: 'new') . '): ' . $e->getMessage());
            $msg = 'Save failed: ' . $e->getMessage();
            if (!empty($_POST['_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'errors' => [$msg]]);
                exit;
            }
            $errors[] = $msg;
        }
    }

    /* ── AJAX validation-error response ────────────────────────────────────── */
    if (!empty($_POST['_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    /* Re-populate defaults from POST on validation error */
    $defaults = array_merge($defaults, [
        'QuestionType' => $qType,
        'QuestionDesc' => trim($_POST['QuestionDesc'] ?? ''),
        'CorrectAnswer'=> $_POST['CorrectAnswer'] ?? '',
        'Complexity'   => $_POST['Complexity']    ?? 'Medium',
        'IsActive'     => isset($_POST['IsActive']) ? 'Y' : 'N',
        'ChapterInfoId'=> (int)($_POST['ChapterInfoId'] ?? 0),
        'CaseStudyId'  => (int)($_POST['CaseStudyId']   ?? 0),
        'SubjectInfoId'=> (int)($_POST['SubjectInfoId'] ?? 0),
        'Answer1'      => trim($_POST['Answer1'] ?? ''),
        'Answer2'      => trim($_POST['Answer2'] ?? ''),
        'Answer3'      => trim($_POST['Answer3'] ?? ''),
        'Answer4'      => trim($_POST['Answer4'] ?? ''),
        'NumStatements'=> max(2, min(4, (int)($_POST['NumStatements'] ?? $_POST['MatchPairs'] ?? 3))),
        'NumOptions'   => max(2, min(4, (int)($_POST['NumOptions'] ?? 4))),
        'YesNo1'       => $_POST['YesNo1'] ?? '',
        'YesNo2'       => $_POST['YesNo2'] ?? '',
        'YesNo3'       => $_POST['YesNo3'] ?? '',
        'YesNo4'       => $_POST['YesNo4'] ?? '',
        // YESNO statements stored in Answer slots on error
        'Answer1'      => trim($_POST['Statement1'] ?? $_POST['Answer1'] ?? ''),
        'Answer2'      => trim($_POST['Statement2'] ?? $_POST['Answer2'] ?? ''),
        'Answer3'      => trim($_POST['Statement3'] ?? $_POST['Answer3'] ?? ''),
        'Answer4'      => trim($_POST['Statement4'] ?? $_POST['Answer4'] ?? ''),
        // MATCH fields re-populated from POST on error
        'MatchOption1'    => trim($_POST['MatchOption1'] ?? ''),
        'MatchOption2'    => trim($_POST['MatchOption2'] ?? ''),
        'MatchOption3'    => trim($_POST['MatchOption3'] ?? ''),
        'MatchOption4'    => trim($_POST['MatchOption4'] ?? ''),
        'MatchStatement1' => trim($_POST['MatchStatement1'] ?? ''),
        'MatchStatement2' => trim($_POST['MatchStatement2'] ?? ''),
        'MatchStatement3' => trim($_POST['MatchStatement3'] ?? ''),
        'MatchStatement4' => trim($_POST['MatchStatement4'] ?? ''),
        'MatchCorrect1'   => $_POST['MatchCorrect1'] ?? '',
        'MatchCorrect2'   => $_POST['MatchCorrect2'] ?? '',
        'MatchCorrect3'   => $_POST['MatchCorrect3'] ?? '',
        'MatchCorrect4'   => $_POST['MatchCorrect4'] ?? '',
    ]);
}

/* Chapters for this exam's subject — lets the admin tag a question with a
   syllabus chapter (chapterinfo, migrations/migration_v49.sql) so students
   can eventually filter/practice chapter-wise, not just by subject.
   For a multi-subject exam there's no single SubjectInfoId to filter by
   here (it varies per question) — chapter tagging just won't offer options
   in that case, same as any other exam whose subject isn't set. */
$chapters = [];
if (!empty($exam['SubjectInfoId'])) {
    try {
        $chapters = Database::fetchAll(
            "SELECT ChapterInfoId, ChapterName FROM chapterinfo
              WHERE SubjectInfoId = ? AND Active = 'Y'
           ORDER BY ChapterOrder, ChapterName", [(int)$exam['SubjectInfoId']]);
    } catch (Exception $e) {
        // migration_v49 not yet run — chapter dropdown just won't show any options.
        $chapters = [];
    }
}

/* Case studies for this exam — lets the admin group this question under a
   shared scenario (case_studies, migrations/migration_v52.sql). A question
   with no case study renders standalone, exactly as before. */
$caseStudiesForDropdown = [];
try {
    $caseStudiesForDropdown = Database::fetchAll(
        "SELECT CaseStudyId, Title FROM case_studies
          WHERE ExamInfoId = ? AND IsActive = 'Y'
       ORDER BY DisplayOrder, Title", [$examId]);
} catch (Exception $e) {
    // migration_v52 not yet run — case study dropdown just won't show any options.
    $caseStudiesForDropdown = [];
}

$pageTitle = ($qid ? 'Edit' : 'Add') . ' Question';
$pageHead  = '<style>
.q-section{background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin-bottom:16px;}
.q-section h3{margin:0 0 14px;font-size:1rem;color:#1a365d;}
.form-row{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:12px;}
.form-row label{font-weight:600;font-size:.875rem;color:#4a5568;display:block;margin-bottom:4px;}
.form-row input[type=text],.form-row textarea,.form-row select{
  width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.9rem;}
.form-row textarea{min-height:80px;resize:vertical;}

/* MCQ / DROPDOWN answer rows — 3-col grid: radio | text+img | badge */
.answer-row{display:grid;grid-template-columns:36px 1fr 44px;gap:12px;align-items:start;
            padding:12px 14px;border-radius:6px;margin-bottom:8px;
            border:2px solid #e2e8f0;background:#fff;}
.answer-row.correct-row{border-color:#276749;background:#f0fff4;}
.answer-row .ans-text-col{display:flex;flex-direction:column;gap:6px;}
.answer-row .ans-text-col input[type=text]{
  width:100%;padding:9px 12px;border:1px solid #cbd5e0;border-radius:5px;
  font-size:.92rem;box-sizing:border-box;}
.answer-row .ans-text-col input[type=text]:focus{border-color:#3182ce;outline:none;}
.ans-img-col{margin-top:2px;}
.ans-label{font-weight:800;font-size:1rem;text-align:center;
           width:36px;height:36px;border-radius:50%;background:#1a365d;color:#fff;
           display:flex;align-items:center;justify-content:center;
           flex-shrink:0;margin-top:2px;align-self:center;}

/* YESNO statement rows */
.stmt-row{display:grid;grid-template-columns:28px 1fr 140px;gap:10px;align-items:center;
          padding:10px 12px;border-radius:6px;margin-bottom:8px;border:2px solid #e2e8f0;background:#fff;}
.stmt-row:hover{border-color:#90cdf4;}
.yn-group{display:flex;gap:6px;}
.yn-btn{padding:5px 16px;border-radius:20px;border:2px solid #cbd5e0;cursor:pointer;font-weight:700;
        font-size:.85rem;background:#fff;transition:.15s;}
.yn-btn:hover{border-color:#3182ce;}
.yn-yes.selected{background:#c6efce;border-color:#276749;color:#276749;}
.yn-no.selected {background:#ffc7ce;border-color:#c53030;color:#c53030;}

/* MATCH (drag & drop) pair rows */
.match-row{display:grid;grid-template-columns:28px 1fr 1fr 170px;gap:10px;align-items:center;
          padding:10px 12px;border-radius:6px;margin-bottom:8px;border:2px solid #e2e8f0;background:#fff;}
.match-row:hover{border-color:#90cdf4;}
.match-row input[type=text]{width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;
          font-size:.9rem;box-sizing:border-box;}
.match-row select{width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.85rem;}

/* Question-type tabs — card style, no overflow */
.type-tab{
  display:block;min-width:180px;padding:14px 18px;border-radius:8px;
  border:2px solid #cbd5e0;cursor:pointer;font-size:.9rem;
  background:#fff;color:#4a5568;transition:.15s;text-align:center;line-height:1.3;
}
.type-tab:hover{border-color:#3182ce;background:#ebf8ff;}
.type-tab.active{background:#1a365d;color:#fff;border-color:#1a365d;}
.type-tab .type-title{display:block;font-weight:700;font-size:.9rem;}
.type-tab .type-desc{display:block;font-weight:400;font-size:.75rem;margin-top:4px;
                     color:#718096;white-space:normal;}
.type-tab.active .type-desc{color:rgba(255,255,255,.75);}

.comp-btn{padding:6px 16px;border-radius:20px;border:2px solid transparent;cursor:pointer;
          font-weight:700;font-size:.85rem;}
.comp-Low   {border-color:#276749;color:#276749;}
.comp-Medium{border-color:#d4a017;color:#b7791f;}
.comp-High  {border-color:#c53030;color:#c53030;}
.img-preview{max-height:60px;border-radius:4px;margin-top:4px;display:block;}
.error-box{background:#fff5f5;border:1px solid #c53030;color:#c53030;padding:12px;border-radius:6px;margin-bottom:16px;}

/* Option-count selector (2 / 3 / 4) */
.numopt-btn{display:inline-flex;align-items:center;justify-content:center;
            width:36px;height:36px;border-radius:6px;border:2px solid #cbd5e0;
            cursor:pointer;font-weight:700;font-size:.95rem;color:#4a5568;
            background:#fff;transition:.15s;}
.numopt-btn:hover{border-color:#3182ce;color:#3182ce;}
.numopt-btn.active{background:#1a365d;color:#fff;border-color:#1a365d;}

/* ── Multi-add session UI ───────────────────────────────── */
#sessionCounter {
  display:inline-flex;align-items:center;gap:6px;
  background:#276749;color:#fff;padding:4px 12px;border-radius:20px;
  font-size:.82rem;font-weight:700;
}
#sessionCounter.zero { background:#718096; }

#savedList {
  background:#f0fff4;border:1px solid #9ae6b4;border-radius:8px;
  padding:12px 16px;margin-bottom:18px;
}
#savedList h4 { margin:0 0 10px;font-size:.9rem;color:#276749;display:flex;justify-content:space-between;align-items:center; }
#savedList table { width:100%;border-collapse:collapse;font-size:.82rem; }
#savedList th { text-align:left;color:#276749;padding:3px 8px;border-bottom:1px solid #9ae6b4; }
#savedList td { padding:4px 8px;border-bottom:1px solid #e2e8f0;vertical-align:middle; }
#savedList tr:last-child td { border-bottom:none; }

/* Toast notification */
#saveToast {
  position:fixed;top:80px;right:20px;z-index:9999;
  background:#276749;color:#fff;padding:12px 18px;border-radius:8px;
  font-size:.9rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.2);
  display:none;align-items:center;gap:8px;max-width:360px;
  animation:slideIn .3s ease;
}
@keyframes slideIn { from{transform:translateX(120%);opacity:0} to{transform:translateX(0);opacity:1} }
</style>';
$useMathJax = true;
include __DIR__ . '/../includes/header.php';
?>

<!-- Breadcrumb -->
<nav style="font-size:.85rem;color:#718096;margin-bottom:10px;">
  <a href="search.php"        style="color:#3182ce;text-decoration:none;">&#128196; Exams</a>
  <span style="margin:0 6px;">›</span>
  <a href="questions-hub.php" style="color:#3182ce;text-decoration:none;">&#10067; Manage Questions</a>
  <span style="margin:0 6px;">›</span>
  <a href="questions.php?examId=<?php echo $examId; ?>" style="color:#3182ce;text-decoration:none;">
    <?php echo htmlspecialchars($exam['ExamName'] ?? 'Exam'); ?>
  </a>
  <span style="margin:0 6px;">›</span>
  <span><?php echo $qid ? 'Edit Question' : 'Add Question'; ?></span>
</nav>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <span>&#10067; <?php echo $qid ? 'Edit' : 'Add'; ?> Question
        — <em style="font-weight:400;font-size:.9rem;"><?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?></em>
      </span>
      <?php if (!$qid): ?>
      <span id="sessionCounter" class="zero" title="Questions added in this session">
        &#10003; <span id="savedCount">0</span> saved
      </span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if (!$qid): ?>
      <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-success btn-sm"
         id="doneBtn">&#10003; Done — View Questions</a>
      <?php endif; ?>
      <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-secondary btn-sm">&#8592; Back</a>
      <a href="questions-hub.php"                           class="btn btn-secondary btn-sm">&#10067; All Exams</a>
    </div>
  </div>

  <div class="card-body">

    <!-- Toast notification (AJAX save feedback) -->
    <div id="saveToast" role="alert"></div>

    <?php if ($errors): ?>
    <div class="error-box" id="errorBox">
      <?php foreach ($errors as $e) echo '<div>&#9888; '.htmlspecialchars($e).'</div>'; ?>
    </div>
    <?php endif; ?>

    <!-- Saved this session (hidden until first save) -->
    <?php if (!$qid): ?>
    <div id="savedList" style="display:none;">
      <h4>
        <span>&#10003; Saved This Session</span>
        <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-success btn-xs">
          View All Questions &#8594;
        </a>
      </h4>
      <table>
        <thead><tr><th>#</th><th>Type</th><th>Question</th><th></th></tr></thead>
        <tbody id="savedTbody"></tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Inline error box for AJAX errors -->
    <div id="ajaxErrorBox" class="error-box" style="display:none;"></div>

    <form method="post" enctype="multipart/form-data" id="qForm">
      <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
      <input type="hidden" name="examId"     value="<?php echo $examId; ?>">
      <?php if ($qid): ?><input type="hidden" name="qid" value="<?php echo $qid; ?>"><?php endif; ?>
      <?php if (!$qid): ?><input type="hidden" name="_ajax" value="1"><?php endif; ?>

      <!-- ── Question Type Selector ─────────────────────────────────────── -->
      <div class="q-section">
        <h3>&#127381; Question Type</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:stretch;">
          <?php foreach ([
            'MCQ'      => ['&#9711; Multiple Choice (A/B/C/D)', 'Standard 4-option question'],
            'DROPDOWN' => ['&#9660; Sentence Completion',       'Dropdown to complete a sentence'],
            'YESNO'    => ['&#10003; Yes / No Grid',            'Statements answered Yes or No'],
            'MATCH'    => ['&#128279; Drag &amp; Drop Match',   'Drag options to match statements'],
          ] as $typeVal => [$typeLabel, $typeDesc]): ?>
          <label style="cursor:pointer;display:block;">
            <input type="radio" name="QuestionType" value="<?php echo $typeVal; ?>"
                   id="type_<?php echo $typeVal; ?>"
                   <?php echo $defaults['QuestionType']===$typeVal?'checked':''; ?>
                   onchange="switchType('<?php echo $typeVal; ?>')"
                   style="display:none;">
            <div class="type-tab <?php echo $defaults['QuestionType']===$typeVal?'active':''; ?>"
                 id="tab_<?php echo $typeVal; ?>">
              <span class="type-title"><?php echo $typeLabel; ?></span>
              <span class="type-desc"><?php echo $typeDesc; ?></span>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ── Question Text / Context ────────────────────────────────────── -->
      <div class="q-section">
        <h3 id="qTextHeader">&#128221; Question</h3>
        <div class="form-row">
          <div style="flex:1;min-width:260px;">
            <label id="qTextLabel">Question Text</label>
            <textarea name="QuestionDesc" id="qDescField"
                      placeholder="Enter the question or sentence stem…"><?php
              echo htmlspecialchars($defaults['QuestionDesc']);
            ?></textarea>
          </div>
          <div style="min-width:200px;" id="qImageCol">
            <label>Question Image <span style="font-weight:400;color:#718096;">(optional)</span></label>
            <input type="file" name="qImage" accept="image/*" onchange="previewImg(this,'qPreview')">
            <?php if ($defaults['ImageLoc']): ?>
            <img id="qPreview" src="../Admin/<?php echo htmlspecialchars($defaults['ImageLoc']); ?>"
                 class="img-preview" alt="">
            <?php else: ?>
            <img id="qPreview" class="img-preview" style="display:none;" alt="">
            <?php endif; ?>
            <label style="margin-top:8px;font-weight:400;display:flex;align-items:center;gap:6px;">
              <input type="checkbox" name="hasQImage"
                     <?php echo $defaults['ImageInd']==='Y'?'checked':''; ?>>
              Attach image to question
            </label>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════
           SECTION: MCQ / DROPDOWN answers (shared — only display differs)
           ══════════════════════════════════════════════════════════════════ -->
      <?php
        // Pre-compute which options are correct for MULTI questions
        $multiCorrectArr = $defaults['MultiCorrectArr'];
        $corrAnswerCount = $defaults['CorrectAnswerCount'];
        // For display, treat MULTI as MCQ with corrAnswerCount > 1
        $displayType = in_array($defaults['QuestionType'], ['MCQ','DROPDOWN','MULTI']) ? 'MCQ' : $defaults['QuestionType'];
      ?>
      <input type="hidden" id="hdnCorrectCount" name="CorrectAnswerCount"
             value="<?php echo (int)$corrAnswerCount; ?>">

      <div id="section_mcq" class="q-section">
        <h3 id="ansHeader">&#9998; Answer Options</h3>
        <p style="font-size:.85rem;color:#718096;margin:0 0 12px;" id="ansHint">
          Select the radio button next to the <strong>correct</strong> answer.
        </p>

        <!-- Number of options (2 / 3 / 4) -->
        <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <label style="font-weight:600;font-size:.875rem;color:#4a5568;margin:0;">Number of options:</label>
          <div style="display:flex;gap:6px;" role="group" aria-label="Number of answer options">
            <?php foreach ([2, 3, 4] as $no): ?>
            <label style="margin:0;cursor:pointer;">
              <input type="radio" name="NumOptions" value="<?php echo $no; ?>"
                     id="numopt<?php echo $no; ?>"
                     <?php echo (int)$defaults['NumOptions']===$no ? 'checked' : ''; ?>
                     onchange="updateAnswerRows(<?php echo $no; ?>)"
                     style="display:none;">
              <span class="numopt-btn <?php echo (int)$defaults['NumOptions']===$no ? 'active' : ''; ?>"
                    id="numopt_btn<?php echo $no; ?>"
                    onclick="document.getElementById('numopt<?php echo $no; ?>').checked=true;updateAnswerRows(<?php echo $no; ?>)">
                <?php echo $no; ?>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
          <span style="font-size:.8rem;color:#718096;">options A–<?php echo ['','','B','C','D'][$defaults['NumOptions']]; ?> will appear</span>
        </div>

        <!-- Number of correct answers (1 / 2 / 3) -->
        <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <label style="font-weight:600;font-size:.875rem;color:#4a5568;margin:0;">Correct answers:</label>
          <div style="display:flex;gap:6px;" role="group" aria-label="Number of correct answers">
            <?php foreach ([1, 2, 3] as $cc): ?>
            <span class="numopt-btn <?php echo (int)$corrAnswerCount===$cc ? 'active' : ''; ?>"
                  id="corrcount_btn<?php echo $cc; ?>"
                  onclick="updateCorrectCount(<?php echo $cc; ?>)"
                  style="<?php echo $cc>1?'background:#7c3aed;color:#fff;border-color:#7c3aed;':''; ?><?php echo (int)$corrAnswerCount===$cc&&$cc===1?'':''; ?>">
              <?php echo $cc; ?>
            </span>
            <?php endforeach; ?>
          </div>
          <span id="corrCountHint" style="font-size:.8rem;color:#718096;">
            <?php echo $corrAnswerCount > 1 ? 'student must select '.(int)$corrAnswerCount.' options' : 'single correct answer'; ?>
          </span>
        </div>
        <!-- Multi-correct info banner (shown when count > 1) -->
        <div id="multiCorrBanner" style="display:<?php echo $corrAnswerCount > 1 ? 'flex' : 'none'; ?>;
             align-items:center;gap:8px;background:#ede9fe;border:1px solid #a78bfa;
             border-radius:7px;padding:8px 12px;margin-bottom:12px;font-size:.83rem;color:#5b21b6;">
          &#9745; <strong>Multi-correct mode</strong> — tick the checkboxes next to <strong id="multiCorrCount"><?php echo (int)$corrAnswerCount; ?></strong> correct options. Students will see checkboxes and must select exactly that many.
        </div>

        <label style="font-weight:600;font-size:.875rem;color:#4a5568;display:flex;align-items:center;gap:8px;margin-bottom:12px;">
          <input type="checkbox" name="hasAnsImage" id="hasAnsImage"
                 <?php echo $defaults['AnsImageInd']==='Y'?'checked':''; ?>
                 onchange="toggleAnsImages()">
          Answers use images
        </label>

        <?php
        $labels  = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
        $ansKeys = ['1'=>'Answer1','2'=>'Answer2','3'=>'Answer3','4'=>'Answer4'];
        $imgKeys = ['1'=>'AnsImg1','2'=>'AnsImg2','3'=>'AnsImg3','4'=>'AnsImg4'];
        $imgFlds = ['1'=>'ansImg1','2'=>'ansImg2','3'=>'ansImg3','4'=>'ansImg4'];
        foreach (['1','2','3','4'] as $n):
            $val         = $defaults[$ansKeys[$n]];
            $imgVal      = $defaults[$imgKeys[$n]];
            $isCorr      = (string)$defaults['CorrectAnswer'] === (string)$n;
            $isMultiCorr = in_array($n, $multiCorrectArr, true);
        ?>
        <div class="answer-row <?php echo ($isCorr||$isMultiCorr)?'correct-row':''; ?>" id="arow<?php echo $n; ?>">

          <!-- col 1: correct-answer selector — radio (single) or checkbox (multi) -->
          <!-- Radio: shown when CorrectAnswerCount = 1 -->
          <input type="radio" name="CorrectAnswer" value="<?php echo $n; ?>"
                 id="corr<?php echo $n; ?>"
                 <?php echo $isCorr?'checked':''; ?>
                 onchange="markCorrect(<?php echo $n; ?>)"
                 class="corr-radio"
                 style="transform:scale(1.4);cursor:pointer;margin-top:10px;<?php echo $corrAnswerCount>1?'display:none;':''; ?>"
                 title="Mark as correct">
          <!-- Checkbox: shown when CorrectAnswerCount > 1 -->
          <input type="checkbox" name="MultiCorrect[]" value="<?php echo $n; ?>"
                 id="corrchk<?php echo $n; ?>"
                 <?php echo $isMultiCorr?'checked':''; ?>
                 onchange="markCorrectMulti(<?php echo $n; ?>)"
                 class="corr-checkbox"
                 style="transform:scale(1.4);cursor:pointer;margin-top:10px;<?php echo $corrAnswerCount<=1?'display:none;':''; ?>"
                 title="Mark as correct">

          <!-- col 2: label + text input + (optional) image upload below -->
          <div class="ans-text-col">
            <label for="corr<?php echo $n; ?>" style="font-weight:700;color:#1a365d;cursor:pointer;font-size:.85rem;">
              Option <?php echo $labels[$n]; ?>
            </label>
            <input type="text" name="<?php echo $ansKeys[$n]; ?>"
                   value="<?php echo htmlspecialchars($val); ?>"
                   placeholder="Type answer option <?php echo $labels[$n]; ?> here…">
            <!-- image upload lives inside col 2 — hidden until toggled -->
            <div class="ans-img-col" style="display:none;">
              <label style="font-size:.8rem;color:#718096;font-weight:600;">Image (optional)</label>
              <input type="file" name="<?php echo $imgFlds[$n]; ?>" accept="image/*"
                     onchange="previewImg(this,'ansPreview<?php echo $n; ?>')">
              <?php if ($imgVal): ?>
              <img id="ansPreview<?php echo $n; ?>" src="../Admin/<?php echo htmlspecialchars($imgVal); ?>"
                   class="img-preview" alt="">
              <?php else: ?>
              <img id="ansPreview<?php echo $n; ?>" class="img-preview" style="display:none;" alt="">
              <?php endif; ?>
            </div>
          </div>

          <!-- col 3: A/B/C/D badge (right-aligned by grid) -->
          <div class="ans-label" style="background:<?php echo $isCorr?'#276749':'#1a365d'; ?>;"
               id="albl<?php echo $n; ?>">
            <?php echo $labels[$n]; ?>
          </div>

        </div>
        <?php endforeach; ?>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════
           SECTION: YESNO statements grid
           ══════════════════════════════════════════════════════════════════ -->
      <div id="section_yesno" class="q-section" style="display:none;">
        <h3>&#10003; Statements &amp; Correct Answers</h3>
        <p style="font-size:.85rem;color:#718096;margin:0 0 12px;">
          Enter each statement and mark whether the correct answer is <strong>Yes</strong> or <strong>No</strong>.
        </p>

        <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;">
          <label style="font-weight:600;font-size:.875rem;color:#4a5568;">Number of statements:</label>
          <select name="NumStatements" id="numStmtSelect" onchange="updateStmtRows()"
                  style="padding:6px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.9rem;">
            <?php for ($n = 2; $n <= 4; $n++): ?>
            <option value="<?php echo $n; ?>"
                    <?php echo (int)$defaults['NumStatements']===$n?'selected':''; ?>>
              <?php echo $n; ?> statements
            </option>
            <?php endfor; ?>
          </select>
        </div>

        <?php for ($s = 1; $s <= 4; $s++):
          $stmtVal = $defaults['Answer'.$s];   // statements stored in Answer1-4
          $ynVal   = $defaults['YesNo'.$s];
        ?>
        <div class="stmt-row" id="stmtRow<?php echo $s; ?>">
          <div style="font-weight:800;color:#2b6cb0;font-size:1rem;text-align:center;"><?php echo $s; ?></div>
          <div>
            <input type="text" name="Statement<?php echo $s; ?>"
                   value="<?php echo htmlspecialchars($stmtVal); ?>"
                   placeholder="Enter statement <?php echo $s; ?>…"
                   style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.9rem;">
          </div>
          <div>
            <div class="yn-group">
              <label style="margin:0;cursor:pointer;">
                <input type="radio" name="YesNo<?php echo $s; ?>" value="Y"
                       id="yn<?php echo $s; ?>_Y"
                       <?php echo $ynVal==='Y'?'checked':''; ?>
                       onchange="highlightYN(<?php echo $s; ?>,'Y')"
                       style="display:none;">
                <span class="yn-btn yn-yes <?php echo $ynVal==='Y'?'selected':''; ?>"
                      id="yn<?php echo $s; ?>_Ybtn" onclick="document.getElementById('yn<?php echo $s; ?>_Y').checked=true;highlightYN(<?php echo $s; ?>,'Y')">
                  Yes
                </span>
              </label>
              <label style="margin:0;cursor:pointer;">
                <input type="radio" name="YesNo<?php echo $s; ?>" value="N"
                       id="yn<?php echo $s; ?>_N"
                       <?php echo $ynVal==='N'?'checked':''; ?>
                       onchange="highlightYN(<?php echo $s; ?>,'N')"
                       style="display:none;">
                <span class="yn-btn yn-no <?php echo $ynVal==='N'?'selected':''; ?>"
                      id="yn<?php echo $s; ?>_Nbtn" onclick="document.getElementById('yn<?php echo $s; ?>_N').checked=true;highlightYN(<?php echo $s; ?>,'N')">
                  No
                </span>
              </label>
            </div>
          </div>
        </div>
        <?php endfor; ?>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════
           SECTION: MATCH (drag & drop) pairs
           ══════════════════════════════════════════════════════════════════ -->
      <div id="section_match" class="q-section" style="display:none;">
        <h3>&#128279; Matching Pairs</h3>
        <p style="font-size:.85rem;color:#718096;margin:0 0 12px;">
          Enter each draggable option and the statement it belongs with, then pick which option is the
          <strong>correct</strong> match for that statement. Students will drag options from the left into
          the answer boxes on the right.
        </p>

        <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <label style="font-weight:600;font-size:.875rem;color:#4a5568;margin:0;">Number of pairs:</label>
          <div style="display:flex;gap:6px;" role="group" aria-label="Number of matching pairs">
            <?php
              $matchPairsDefault = ($defaults['QuestionType'] === 'MATCH') ? (int)$defaults['NumStatements'] : 3;
              foreach ([2, 3, 4] as $np):
            ?>
            <label style="margin:0;cursor:pointer;">
              <input type="radio" name="MatchPairs" value="<?php echo $np; ?>"
                     id="matchpairs<?php echo $np; ?>"
                     <?php echo $matchPairsDefault===$np ? 'checked' : ''; ?>
                     onchange="updateMatchRows(<?php echo $np; ?>)"
                     style="display:none;">
              <span class="numopt-btn <?php echo $matchPairsDefault===$np ? 'active' : ''; ?>"
                    id="matchpairs_btn<?php echo $np; ?>"
                    onclick="document.getElementById('matchpairs<?php echo $np; ?>').checked=true;updateMatchRows(<?php echo $np; ?>)">
                <?php echo $np; ?>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:28px 1fr 1fr 170px;gap:10px;padding:0 12px;margin-bottom:6px;">
          <div></div>
          <label style="font-weight:600;font-size:.8rem;color:#4a5568;margin:0;">Option (left, draggable)</label>
          <label style="font-weight:600;font-size:.8rem;color:#4a5568;margin:0;">Statement (right)</label>
          <label style="font-weight:600;font-size:.8rem;color:#4a5568;margin:0;">Correct match</label>
        </div>

        <?php
          $matchLetters = ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'];
          for ($s = 1; $s <= 4; $s++):
            $optVal  = $defaults['MatchOption'.$s]    ?? '';
            $stmtVal = $defaults['MatchStatement'.$s] ?? '';
            $corrVal = $defaults['MatchCorrect'.$s]   ?? '';
        ?>
        <div class="match-row" id="matchRow<?php echo $s; ?>"
             style="<?php echo $s <= $matchPairsDefault ? '' : 'display:none;'; ?>">
          <div style="font-weight:800;color:#2b6cb0;font-size:1rem;text-align:center;"><?php echo $s; ?></div>
          <input type="text" name="MatchOption<?php echo $s; ?>"
                 value="<?php echo htmlspecialchars($optVal); ?>"
                 placeholder="Option <?php echo $matchLetters[$s]; ?> text…">
          <input type="text" name="MatchStatement<?php echo $s; ?>"
                 value="<?php echo htmlspecialchars($stmtVal); ?>"
                 placeholder="Statement <?php echo $s; ?> text…">
          <select name="MatchCorrect<?php echo $s; ?>" id="matchCorr<?php echo $s; ?>">
            <option value="" <?php echo $corrVal===''?'selected':''; ?>>-- Select --</option>
            <?php for ($o = 1; $o <= $matchPairsDefault; $o++): ?>
            <option value="<?php echo $o; ?>" <?php echo (string)$corrVal===(string)$o?'selected':''; ?>>
              Option <?php echo $matchLetters[$o]; ?>
            </option>
            <?php endfor; ?>
          </select>
        </div>
        <?php endfor; ?>
      </div>

      <!-- ── Explanation ──────────────────────────────────────────────────── -->
      <div class="q-section">
        <h3>&#128161; Explanation <span style="font-weight:400;font-size:.82rem;color:#718096;">(shown to students after submission)</span></h3>
        <textarea name="Explanation" rows="4"
                  style="width:100%;padding:10px 12px;border:1px solid #cbd5e0;border-radius:6px;
                         font-size:.9rem;resize:vertical;box-sizing:border-box;"
                  placeholder="Explain why the correct answer is right, provide context, references, or tips…"><?php echo htmlspecialchars($defaults['Explanation'] ?? ''); ?></textarea>
      </div>

      <?php if ($isMultiSubjectExam): ?>
      <!-- ── Subject (migration_v54 — multi-subject exam only) ───────────── -->
      <div class="q-section">
        <h3>&#128218; Subject <span style="font-weight:400;font-size:.82rem;color:#dc2626;">(required — this exam has multiple subject sections)</span></h3>
        <select name="SubjectInfoId" required class="form-row" style="width:100%;max-width:420px;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.9rem;">
          <option value="0">— Select subject —</option>
          <?php foreach ($examSubjectChoices as $sid => $sLabel): ?>
            <option value="<?php echo (int)$sid; ?>"
              <?php echo ((int)$defaults['SubjectInfoId'] === (int)$sid) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($sLabel); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="field-hint" style="font-size:.78rem;color:#6b7280;margin-top:4px;">
          Which of this exam's sections (set on the exam's Exam Pattern tab) this question is drawn into.
        </div>
      </div>
      <?php endif; ?>

      <!-- ── Chapter ────────────────────────────────────────────────────── -->
      <div class="q-section">
        <h3>&#128213; Chapter <span style="font-weight:400;font-size:.82rem;color:#718096;">(optional — enables chapter-wise practice)</span></h3>
        <select name="ChapterInfoId" class="form-row" style="width:100%;max-width:420px;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.9rem;">
          <option value="0">— No chapter / unclassified —</option>
          <?php foreach ($chapters as $c): ?>
            <option value="<?php echo (int)$c['ChapterInfoId']; ?>"
              <?php echo ((int)$defaults['ChapterInfoId'] === (int)$c['ChapterInfoId']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['ChapterName']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (empty($chapters)): ?>
          <div class="field-hint" style="font-size:.78rem;color:#6b7280;margin-top:4px;">
            No chapters found for this exam's subject yet —
            <a href="../Admin/AddEditChapterInfo.php?ChapterInfoId=0">add one</a>.
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Case Study ─────────────────────────────────────────────────── -->
      <div class="q-section">
        <h3>&#128220; Case Study <span style="font-weight:400;font-size:.82rem;color:#718096;">(optional — groups this question with shared background info)</span></h3>
        <select name="CaseStudyId" class="form-row" style="width:100%;max-width:420px;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:.9rem;">
          <option value="0">— Standalone question, no case study —</option>
          <?php foreach ($caseStudiesForDropdown as $cs): ?>
            <option value="<?php echo (int)$cs['CaseStudyId']; ?>"
              <?php echo ((int)$defaults['CaseStudyId'] === (int)$cs['CaseStudyId']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($cs['Title']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (empty($caseStudiesForDropdown)): ?>
          <div class="field-hint" style="font-size:.78rem;color:#6b7280;margin-top:4px;">
            No case studies for this exam yet —
            <a href="case-studies.php?examId=<?php echo $examId; ?>">create one</a>.
          </div>
        <?php else: ?>
          <div class="field-hint" style="font-size:.78rem;color:#6b7280;margin-top:4px;">
            Every question tagged to the same case study shares its background info panel and is
            still scored independently. Manage case studies from
            <a href="case-studies.php?examId=<?php echo $examId; ?>">Case Studies</a>.
          </div>
        <?php endif; ?>
      </div>

      <!-- ── Complexity ─────────────────────────────────────────────────── -->
      <div class="q-section">
        <h3>&#127919; Complexity</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          <?php foreach (['Low','Medium','High'] as $c): ?>
          <label style="cursor:pointer;display:flex;align-items:center;gap:6px;">
            <input type="radio" name="Complexity" value="<?php echo $c; ?>"
                   <?php echo $defaults['Complexity']===$c?'checked':''; ?>>
            <span class="comp-btn comp-<?php echo $c; ?>"><?php echo $c; ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ── Status ─────────────────────────────────────────────────────── -->
      <div class="q-section" style="display:flex;align-items:center;gap:16px;padding:14px 18px;">
        <h3 style="margin:0;">&#128994; Status</h3>
        <label style="cursor:pointer;display:flex;align-items:center;gap:8px;font-weight:600;">
          <input type="checkbox" name="IsActive" id="isActive"
                 <?php echo $defaults['IsActive']==='Y'?'checked':''; ?>
                 style="transform:scale(1.4);">
          <span id="activeLabel" style="color:<?php echo $defaults['IsActive']==='Y'?'#276749':'#718096'; ?>;">
            <?php echo $defaults['IsActive']==='Y' ? 'Active — included in exam draw' : 'Inactive — excluded from exam draw'; ?>
          </span>
        </label>
      </div>

      <div style="display:flex;gap:12px;margin-top:8px;flex-wrap:wrap;align-items:center;">
        <button type="submit" id="saveBtn" class="btn btn-primary" style="font-size:1rem;padding:10px 28px;">
          <?php if ($qid): ?>
            &#10003; Update Question
          <?php else: ?>
            &#43; Save &amp; Add Another
          <?php endif; ?>
        </button>
        <?php if (!$qid): ?>
        <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-success" style="font-size:1rem;padding:10px 22px;">
          &#10003; Done — View Questions
        </a>
        <?php endif; ?>
        <a href="questions.php?examId=<?php echo $examId; ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
var currentType = '<?php echo htmlspecialchars($defaults['QuestionType']); ?>';

function switchType(type) {
    // MULTI is stored as QuestionType but displayed using the MCQ tab
    if (type === 'MULTI') type = 'MCQ';
    currentType = type;
    // Update tab styling
    ['MCQ','DROPDOWN','YESNO','MATCH'].forEach(function(t) {
        document.getElementById('tab_'+t).classList.toggle('active', t===type);
    });
    // Show/hide sections
    document.getElementById('section_mcq').style.display   = (type !== 'YESNO' && type !== 'MATCH') ? '' : 'none';
    document.getElementById('section_yesno').style.display = (type === 'YESNO') ? '' : 'none';
    document.getElementById('section_match').style.display = (type === 'MATCH') ? '' : 'none';
    // Update labels
    var header = document.getElementById('ansHeader');
    var hint   = document.getElementById('ansHint');
    var qLabel = document.getElementById('qTextLabel');
    var qHdr   = document.getElementById('qTextHeader');
    if (type === 'DROPDOWN') {
        if (header) header.textContent = 'Answer Options (Dropdown)';
        if (hint)   hint.innerHTML = 'Select the radio button next to the <strong>correct</strong> option shown in the dropdown.';
        if (qLabel) qLabel.textContent = 'Sentence Stem (e.g. "Descriptive analytics tells you …")';
        if (qHdr)   qHdr.innerHTML     = '&#128172; Sentence Stem';
    } else if (type === 'YESNO') {
        if (qLabel) qLabel.textContent = 'Instructions / Context (e.g. "For each statement, select Yes or No")';
        if (qHdr)   qHdr.innerHTML     = '&#128203; Instructions / Context';
    } else if (type === 'MATCH') {
        if (qLabel) qLabel.textContent = 'Instructions / Context (e.g. "Match each term to its correct definition")';
        if (qHdr)   qHdr.innerHTML     = '&#128203; Instructions / Context';
    } else {
        if (header) header.textContent = 'Answer Options';
        if (hint)   hint.innerHTML = 'Select the radio button next to the <strong>correct</strong> answer.';
        if (qLabel) qLabel.textContent = 'Question Text';
        if (qHdr)   qHdr.innerHTML     = '&#128221; Question';
    }
    updateStmtRows();
    // Re-apply answer row visibility for MCQ/DROPDOWN
    if (type !== 'YESNO' && type !== 'MATCH') {
        var optSel = document.querySelector('input[name="NumOptions"]:checked');
        var n = optSel ? parseInt(optSel.value) : 4;
        updateAnswerRows(n);
    }
    if (type === 'MATCH') {
        var pairSel = document.querySelector('input[name="MatchPairs"]:checked');
        updateMatchRows(pairSel ? parseInt(pairSel.value) : 3);
    }
}

function updateStmtRows() {
    var n = parseInt(document.getElementById('numStmtSelect').value || 3);
    for (var i = 1; i <= 4; i++) {
        var row = document.getElementById('stmtRow'+i);
        if (row) row.style.display = (i <= n) ? '' : 'none';
    }
}

function updateAnswerRows(n) {
    // Highlight the active count button
    [2,3,4].forEach(function(i) {
        var btn = document.getElementById('numopt_btn'+i);
        if (btn) btn.classList.toggle('active', i === n);
    });
    // Show/hide answer rows
    for (var i = 1; i <= 4; i++) {
        var row = document.getElementById('arow'+i);
        if (row) row.style.display = (i <= n) ? '' : 'none';
    }
    // If the currently-correct answer is now hidden, deselect it
    var corr = document.querySelector('input[name="CorrectAnswer"]:checked');
    if (corr) {
        var corrN = parseInt(corr.value);
        if (corrN > n) {
            corr.checked = false;
            for (var i = 1; i <= 4; i++) {
                var row = document.getElementById('arow'+i);
                var lbl = document.getElementById('albl'+i);
                if (row) row.classList.remove('correct-row');
                if (lbl) lbl.style.background = '#1a365d';
            }
        }
    }
}

function updateMatchRows(n) {
    // Highlight the active pair-count button
    [2,3,4].forEach(function(i) {
        var btn = document.getElementById('matchpairs_btn'+i);
        if (btn) btn.classList.toggle('active', i === n);
    });
    // Show/hide pair rows
    for (var i = 1; i <= 4; i++) {
        var row = document.getElementById('matchRow'+i);
        if (row) row.style.display = (i <= n) ? '' : 'none';
    }
    rebuildMatchCorrectSelects(n);
}

function rebuildMatchCorrectSelects(n) {
    var letters = ['A','B','C','D'];
    for (var s = 1; s <= 4; s++) {
        var sel = document.getElementById('matchCorr'+s);
        if (!sel) continue;
        var current = sel.value;
        sel.innerHTML = '';
        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '-- Select --';
        sel.appendChild(blank);
        for (var i = 1; i <= n; i++) {
            var opt = document.createElement('option');
            opt.value = i;
            opt.textContent = 'Option ' + letters[i-1];
            sel.appendChild(opt);
        }
        if (current && parseInt(current, 10) <= n) sel.value = current;
        else sel.value = '';
    }
}

function highlightYN(s, val) {
    var yBtn = document.getElementById('yn'+s+'_Ybtn');
    var nBtn = document.getElementById('yn'+s+'_Nbtn');
    if (yBtn) yBtn.classList.toggle('selected', val==='Y');
    if (nBtn) nBtn.classList.toggle('selected', val==='N');
}

function previewImg(input, previewId) {
    var img = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) { img.src = e.target.result; img.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    }
}

function markCorrect(n) {
    var optSel  = document.querySelector('input[name="NumOptions"]:checked');
    var numOpts = optSel ? parseInt(optSel.value) : 4;
    for (var i = 1; i <= 4; i++) {
        var row = document.getElementById('arow'+i);
        var lbl = document.getElementById('albl'+i);
        var isC = (i === n) && (i <= numOpts);
        if (row) row.classList.toggle('correct-row', isC);
        if (lbl) lbl.style.background = isC ? '#276749' : '#1a365d';
    }
}

function markCorrectMulti(changedN) {
    var corrCount  = parseInt(document.getElementById('hdnCorrectCount').value || 1);
    var optSel     = document.querySelector('input[name="NumOptions"]:checked');
    var numOpts    = optSel ? parseInt(optSel.value) : 4;
    // Count how many are now checked
    var checkedBoxes = [];
    for (var i = 1; i <= numOpts; i++) {
        var chk = document.getElementById('corrchk'+i);
        if (chk && chk.checked) checkedBoxes.push(i);
    }
    // Enforce max: if over limit, uncheck the one just changed
    if (checkedBoxes.length > corrCount) {
        var overChk = document.getElementById('corrchk'+changedN);
        if (overChk) overChk.checked = false;
        checkedBoxes = checkedBoxes.filter(function(x){ return x !== changedN; });
    }
    // Update row highlighting
    for (var i = 1; i <= 4; i++) {
        var row = document.getElementById('arow'+i);
        var lbl = document.getElementById('albl'+i);
        var isC = checkedBoxes.indexOf(i) !== -1;
        if (row) row.classList.toggle('correct-row', isC);
        if (lbl) lbl.style.background = isC ? '#276749' : '#1a365d';
    }
}

function updateCorrectCount(n) {
    document.getElementById('hdnCorrectCount').value = n;
    // Update stepper button styles
    [1,2,3].forEach(function(i) {
        var btn = document.getElementById('corrcount_btn'+i);
        if (!btn) return;
        if (i === n) {
            btn.classList.add('active');
            btn.style.background = (n === 1) ? '' : '#7c3aed';
            btn.style.color      = (n === 1) ? '' : '#fff';
            btn.style.borderColor= (n === 1) ? '' : '#7c3aed';
        } else {
            btn.classList.remove('active');
            btn.style.background  = '';
            btn.style.color       = '';
            btn.style.borderColor = '';
        }
    });
    // Show/hide radios vs checkboxes
    document.querySelectorAll('.corr-radio').forEach(function(el){
        el.style.display = (n === 1) ? '' : 'none';
    });
    document.querySelectorAll('.corr-checkbox').forEach(function(el){
        el.style.display = (n > 1) ? '' : 'none';
        if (n === 1) el.checked = false; // clear checkboxes when reverting to single
    });
    // Show/hide multi-correct banner
    var banner = document.getElementById('multiCorrBanner');
    if (banner) banner.style.display = (n > 1) ? 'flex' : 'none';
    var cnt = document.getElementById('multiCorrCount');
    if (cnt) cnt.textContent = n;
    // Update hint text
    var hint = document.getElementById('corrCountHint');
    if (hint) hint.textContent = (n === 1) ? 'single correct answer' : 'student must select '+n+' options';
    // Clear single radio if switching to multi
    if (n > 1) {
        document.querySelectorAll('.corr-radio').forEach(function(el){ el.checked = false; });
        // Reset row highlights then re-apply from checkboxes
        for (var i = 1; i <= 4; i++) {
            var row = document.getElementById('arow'+i);
            var lbl = document.getElementById('albl'+i);
            if (row) row.classList.remove('correct-row');
            if (lbl) lbl.style.background = '#1a365d';
        }
    } else {
        // Clear all checkboxes and row highlights
        document.querySelectorAll('.corr-checkbox').forEach(function(el){ el.checked = false; });
        for (var i = 1; i <= 4; i++) {
            var row = document.getElementById('arow'+i);
            var lbl = document.getElementById('albl'+i);
            if (row) row.classList.remove('correct-row');
            if (lbl) lbl.style.background = '#1a365d';
        }
    }
}

function toggleAnsImages() {
    var show = document.getElementById('hasAnsImage').checked;
    document.querySelectorAll('.ans-img-col').forEach(function(el) {
        el.style.display = show ? '' : 'none';
    });
}

document.getElementById('isActive').addEventListener('change', function() {
    var lbl = document.getElementById('activeLabel');
    lbl.textContent = this.checked ? 'Active — included in exam draw' : 'Inactive — excluded from exam draw';
    lbl.style.color = this.checked ? '#276749' : '#718096';
});

/* ══════════════════════════════════════════════════════════════
   MULTI-ADD SESSION — AJAX submit (add mode only, not edit)
   ══════════════════════════════════════════════════════════════ */
<?php if (!$qid): ?>
var _sessionCount = 0;
var _typeLabels   = { MCQ:'MCQ', DROPDOWN:'Dropdown', YESNO:'Yes/No', MULTI:'Multi', MATCH:'Match' };

/* ── AJAX form submit ─────────────────────────────────────── */
document.getElementById('qForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Saving…';

    var fd = new FormData(this);

    fetch(window.location.href, { method:'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '&#43; Save &amp; Add Another';

            if (data.success) {
                _sessionCount++;
                updateCounter(_sessionCount);
                addToSavedList(_sessionCount, data.qType, data.qText, data.qid);
                showToast('&#10003; Question #' + _sessionCount + ' saved!');
                hideErrors();
                resetQForm();
            } else {
                showErrors(data.errors || ['An unknown error occurred.']);
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '&#43; Save &amp; Add Another';
            showErrors(['Network error — please try again.']);
        });
});

/* ── Counter badge ───────────────────────────────────────── */
function updateCounter(n) {
    var el  = document.getElementById('savedCount');
    var ctr = document.getElementById('sessionCounter');
    if (el)  el.textContent = n;
    if (ctr) { ctr.classList.toggle('zero', n === 0); }
}

/* ── Append row to saved-list table ─────────────────────── */
function addToSavedList(seq, qType, qText, qid) {
    var list  = document.getElementById('savedList');
    var tbody = document.getElementById('savedTbody');
    if (!list || !tbody) return;
    list.style.display = '';
    var label  = _typeLabels[qType] || qType;
    var short  = qText.length > 80 ? qText.substring(0, 80) + '…' : qText;
    var editUrl = 'question-edit.php?examId=<?php echo $examId; ?>&qid=' + qid;
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td style="width:30px;color:#276749;font-weight:700;">' + seq + '</td>' +
        '<td style="width:80px;"><span style="font-size:.75rem;background:#e9d8fd;color:#553c9a;' +
        'padding:2px 8px;border-radius:10px;font-weight:600;">' + label + '</span></td>' +
        '<td style="color:#2d3748;">' + escHtml(short) + '</td>' +
        '<td style="width:100px;white-space:nowrap;">' +
          '<a href="javascript:void(0)" onclick="deleteSavedQuestion(' + qid + ', this)" ' +
             'style="font-size:.78rem;color:#c53030;margin-right:12px;">Delete</a>' +
          '<a href="' + editUrl + '" style="font-size:.78rem;color:#3182ce;">Edit</a>' +
        '</td>';
    tbody.insertBefore(tr, tbody.firstChild);  // newest first
}

/* ── Delete a just-added question from this session's saved list ────── */
function deleteSavedQuestion(qid, link) {
    if (!confirm('Delete this question? This cannot be undone.')) return;
    var tr   = link.closest('tr');
    var csrfEl = document.querySelector('#qForm input[name="csrf_token"]');
    var fd   = new FormData();
    fd.append('csrf_token', csrfEl ? csrfEl.value : '');
    fd.append('examId', '<?php echo $examId; ?>');
    fd.append('ajax_delete_qid', qid);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (tr && tr.parentNode) tr.parentNode.removeChild(tr);
                _sessionCount = Math.max(0, _sessionCount - 1);
                updateCounter(_sessionCount);
                showToast('&#10003; Question removed.');
            } else {
                alert((data.errors && data.errors[0]) || 'Could not delete this question.');
            }
        })
        .catch(function() {
            alert('Network error — please try again.');
        });
}

/* ── Toast ───────────────────────────────────────────────── */
var _toastTimer;
function showToast(msg) {
    var t = document.getElementById('saveToast');
    if (!t) return;
    clearTimeout(_toastTimer);
    t.innerHTML = msg;
    t.style.display = 'flex';
    _toastTimer = setTimeout(function() { t.style.display = 'none'; }, 3000);
}

/* ── Inline errors ───────────────────────────────────────── */
function showErrors(errs) {
    var box = document.getElementById('ajaxErrorBox');
    if (!box) return;
    box.innerHTML = errs.map(function(e) {
        return '<div>&#9888; ' + escHtml(e) + '</div>';
    }).join('');
    box.style.display = '';
    box.scrollIntoView({ behavior:'smooth', block:'nearest' });
}
function hideErrors() {
    var box = document.getElementById('ajaxErrorBox');
    if (box) box.style.display = 'none';
}

/* ── Full form reset ─────────────────────────────────────── */
function resetQForm() {
    /* Question text & image */
    var desc = document.getElementById('qDescField');
    if (desc) desc.value = '';

    var qPrev = document.getElementById('qPreview');
    if (qPrev) { qPrev.src = ''; qPrev.style.display = 'none'; }

    var qImgChk = document.querySelector('[name="hasQImage"]');
    if (qImgChk) qImgChk.checked = false;

    var qImgFile = document.querySelector('[name="qImage"]');
    if (qImgFile) qImgFile.value = '';

    /* Answer text fields */
    ['Answer1','Answer2','Answer3','Answer4'].forEach(function(n) {
        var el = document.querySelector('[name="' + n + '"]');
        if (el) el.value = '';
    });

    /* Reset correct-answer radio */
    document.querySelectorAll('.corr-radio').forEach(function(r) { r.checked = false; });
    document.querySelectorAll('.corr-checkbox').forEach(function(c) { c.checked = false; });
    for (var i = 1; i <= 4; i++) {
        var row = document.getElementById('arow'  + i);
        var lbl = document.getElementById('albl'  + i);
        if (row) row.classList.remove('correct-row');
        if (lbl) lbl.style.background = '#1a365d';
    }

    /* Explanation */
    var expl = document.querySelector('[name="Explanation"]');
    if (expl) expl.value = '';

    /* Complexity → Medium */
    var medRadio = document.querySelector('[name="Complexity"][value="Medium"]');
    if (medRadio) medRadio.checked = true;

    /* Answer image uploads */
    ['ansImg1','ansImg2','ansImg3','ansImg4'].forEach(function(n, idx) {
        var el = document.querySelector('[name="' + n + '"]');
        if (el) el.value = '';
        var prev = document.getElementById('ansPreview' + (idx + 1));
        if (prev) { prev.src = ''; prev.style.display = 'none'; }
    });

    /* Reset type back to MCQ */
    var mcqRadio = document.getElementById('type_MCQ');
    if (mcqRadio) { mcqRadio.checked = true; switchType('MCQ'); }

    /* Reset num-options to 4 */
    var opt4 = document.getElementById('numopt4');
    if (opt4) { opt4.checked = true; updateAnswerRows(4); }

    /* Reset correct-count to 1 */
    updateCorrectCount(1);

    /* YESNO statements */
    for (var s = 1; s <= 4; s++) {
        var stmtEl = document.querySelector('[name="Statement' + s + '"]');
        if (stmtEl) stmtEl.value = '';
        highlightYN(s, '');
        var ynY = document.getElementById('yn' + s + '_Y');
        var ynN = document.getElementById('yn' + s + '_N');
        if (ynY) ynY.checked = false;
        if (ynN) ynN.checked = false;
    }

    /* MATCH pairs */
    for (var m = 1; m <= 4; m++) {
        var optEl  = document.querySelector('[name="MatchOption' + m + '"]');
        var stEl   = document.querySelector('[name="MatchStatement' + m + '"]');
        if (optEl) optEl.value = '';
        if (stEl)  stEl.value  = '';
    }
    var mp3 = document.getElementById('matchpairs3');
    if (mp3) { mp3.checked = true; updateMatchRows(3); }

    /* IsActive → checked (Y) */
    var activeEl = document.getElementById('isActive');
    if (activeEl) { activeEl.checked = true; activeEl.dispatchEvent(new Event('change')); }

    /* Refresh CSRF token to prevent double-submit issues */
    fetch(window.location.href + '&_csrfRefresh=1', { method:'GET' })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            var m = html.match(/name="csrf_token"\s+value="([^"]+)"/);
            if (m) {
                var t = document.querySelector('[name="csrf_token"]');
                if (t) t.value = m[1];
            }
        }).catch(function(){});

    /* Scroll back to top of form */
    var card = document.querySelector('.card-body');
    if (card) card.scrollIntoView({ behavior:'smooth', block:'start' });
}

/* ── HTML escape helper ─────────────────────────────────── */
function escHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
<?php endif; ?>

// Initialise
// Treat MULTI as MCQ for tab display (multi-correct is handled in-section)
var displayType = (currentType === 'MULTI') ? 'MCQ' : currentType;
switchType(displayType);
toggleAnsImages();
(function() {
    // Apply saved option count on page load
    var optSel = document.querySelector('input[name="NumOptions"]:checked');
    if (optSel && displayType !== 'YESNO' && displayType !== 'MATCH') updateAnswerRows(parseInt(optSel.value));
    // Restore correct-answer highlight (single or multi)
    var corrCount = parseInt(document.getElementById('hdnCorrectCount').value || 1);
    if (corrCount > 1) {
        // Multi-correct: checkboxes are already pre-checked via PHP; just update row highlights
        var optSel2 = document.querySelector('input[name="NumOptions"]:checked');
        var numOpts = optSel2 ? parseInt(optSel2.value) : 4;
        for (var i = 1; i <= numOpts; i++) {
            var chk = document.getElementById('corrchk'+i);
            if (chk && chk.checked) {
                var row = document.getElementById('arow'+i);
                var lbl = document.getElementById('albl'+i);
                if (row) row.classList.add('correct-row');
                if (lbl) lbl.style.background = '#276749';
            }
        }
    } else {
        var checked = document.querySelector('input[name="CorrectAnswer"]:checked');
        if (checked) markCorrect(parseInt(checked.value));
    }
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
