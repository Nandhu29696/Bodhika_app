<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';
Auth::requireLogin('../auth/login.php');

$examId  = filter_input(INPUT_GET, 'InfoId', FILTER_VALIDATE_INT);
if (!$examId) { die('Invalid exam ID.'); }
$_SESSION['InfoId'] = $examId;

$isAdmin = Auth::isAdmin();
$myUid   = Auth::currentUserId();

/* ── Access control ────────────────────────────────────────────────────
   Two paths reach this page:
     A) Admin-assigned exam  → row exists in exam_assignments
     B) Self-enrolled exam   → row exists in enrollment_payments (incl. free)
   Enrollment::canAccess() is the single authoritative gate for both paths.
   Never redirect solely because exam_assignments has no row.

   NOTE: this used to also hard-redirect to history.php whenever
   exam_assignments.Status was 'Completed' — meant to stop someone re-
   opening an already-finished single-attempt exam, but it fired for
   every exam regardless of MaxAttempts, so a student assigned an exam
   with multiple attempts allowed could never start attempt #2: this page
   bounced them to history before the attempt-limit check below (which
   actually knows how many attempts remain) ever ran. That check is the
   correct authority for "can I (re)start this" — Completed-with-
   attempts-remaining is a real, intended state, not an error — so the
   blanket redirect was removed and attempt-limit gating below now
   decides this on its own.
──────────────────────────────────────────────────────────────────────── */
if (!$isAdmin && $myUid) {

    /* 1. Enrollment / payment gate — covers assigned and self-enrolled exams.
          Enrollment::canAccess() returns true when:
            • an admin explicitly assigned this exam (exam_assignments row)
            • exam-level ExamFreeFor / ScholarshipFlag / institute-free waiver applies
            • this exam's own ExamFee is <= 0 (migration_v51 — pricing is exam-level only)
            • otherwise, an exam_fee_payments row with status Paid/Waived/Free
          NOTE: a ₹0 fee is only sufficient because it's a real, explicit value
          the admin set on THIS exam — see that function's docblock. */
    if (!Enrollment::canAccess((int)$examId, (int)$myUid)) {
        /* Not enrolled — every exam checks out through the same page now
           (migration_v51 retired the subject-level checkout entirely). */
        header('Location: enroll-exam.php?examId=' . (int)$examId);
        exit;
    }

    /* 2. Attempt-limit gate (migration_v36) — exam MaxAttempts, overridable
          per student via exam_attempt_overrides. 0 = unlimited. This is now
          also what stops a REPEAT attempt on a single-attempt exam (used=1,
          max=1 → allowed=false), taking over the job the old blanket
          Completed-status redirect used to do. */
    $attemptStatus = Enrollment::getAttemptStatus((int)$examId, (int)$myUid);
    if (!$attemptStatus['allowed']) {
        $pageTitle = 'Attempt Limit Reached';
        include __DIR__ . '/../includes/header.php';
        ?>
        <div class="page-wrap">
          <div class="card" style="max-width:560px;margin:40px auto;">
            <div class="card-header">Attempt Limit Reached</div>
            <div style="padding:24px;">
              <div class="alert alert-warning" style="margin-bottom:16px;">
                You've used all <?php echo (int)$attemptStatus['max']; ?> of your
                allowed attempts (<?php echo (int)$attemptStatus['used']; ?> taken)
                for this exam.
              </div>
              <p style="margin-bottom:16px;color:var(--clr-text-muted);">
                If you believe this is an error, contact your administrator to
                request an additional attempt.
              </p>
              <a class="btn btn-primary" href="history.php?InfoId=<?php echo $examId; ?>">View My History</a>
              <a class="btn" href="search.php">Back to Exams</a>
            </div>
          </div>
        </div>
        <?php
        include __DIR__ . '/../includes/footer.php';
        exit;
    }
}

/* ── Lazy enrollment for free exams (migration_v51: exam_fee_payments) ───────
   We only create the record at the moment the student actually starts
   writing, not on browse/search page load. Purely bookkeeping — by this
   point Enrollment::canAccess() has already granted access via assignment,
   scholarship, institute-free, or this exam's own ₹0 fee. */
if (!$isAdmin && $myUid) {
    try {
        $lazyFeeRow = Database::fetchOne(
            "SELECT COALESCE(ExamFee, 0) AS ExamFee FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);

        if ($lazyFeeRow && (float)$lazyFeeRow['ExamFee'] <= 0) {
            Database::execute(
                "INSERT IGNORE INTO exam_fee_payments
                 (ExamInfoId, UserInfoId, FeeAtTime, FinalAmount, PaymentStatus, StartDate)
                 VALUES (?, ?, 0, 0, 'Free', CURDATE())",
                [$examId, $myUid]);
        }
    } catch (Exception $e) { /* exam_fee_payments table not yet created — skip */ }
}

/* e.* avoids failing on columns that may not exist in all deployments. */
$exam = Database::fetchOne(
    "SELECT e.*, g.GradeName, s.SubjectName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
      WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { die('Exam not found.'); }

/* ── Multi-subject exam pattern (migration_v54) ──────────────────────────
   examinfo.IsMultiSubject opts an exam into sectioned question serving —
   e.g. a NEET-style paper with separate Physics/Chemistry/Botany/Zoology
   pools (exam_sections) instead of one flat pool. Every existing exam has
   IsMultiSubject='N' and no exam_sections rows, so this no-ops entirely
   for them — the single-subject code path below is untouched. */
$isMultiSubject = false;
$examSections   = [];
try {
    if (($exam['IsMultiSubject'] ?? 'N') === 'Y') {
        $examSections = Database::fetchAll(
            "SELECT es.*, sub.SubjectName
               FROM exam_sections es
          LEFT JOIN subjectinfo sub ON sub.SubjectInfoId = es.SubjectInfoId
              WHERE es.ExamInfoId = ?
              ORDER BY es.SortOrder, es.ExamSectionId",
            [$examId]);
        $isMultiSubject = !empty($examSections);
    }
} catch (Exception $e) { /* migration_v54 not yet run — treat as single-subject */ }

$numQuestions = $isMultiSubject
    ? (int)array_sum(array_column($examSections, 'NumOfQuestions'))
    : (int)$exam['NumOfQuestions'];
$timeMinutes  = (int)($exam['TimeAlloted'] ?? 30);
$startTime    = time();

/* ── Load per-exam autosave settings (migration_v18) ─────────────────── */
$autosaveIntervalMs = 60000; // default 60 s
$autosaveDebounceMs = 3000;  // default 3 s
try {
    $esRow = Database::fetchOne(
        "SELECT AutosaveIntervalSec, AutosaveDebounceMs FROM exam_settings WHERE ExamInfoId = ? LIMIT 1",
        [$examId]);
    if ($esRow) {
        $autosaveIntervalMs = (int)$esRow['AutosaveIntervalSec'] * 1000; // convert to ms for JS
        $autosaveDebounceMs = max(500, (int)$esRow['AutosaveDebounceMs']);
    }
} catch (Exception $e) { /* migration_v18.sql not yet run — use defaults */ }
$_SESSION['starttime'] = $startTime;

/* Session-timeout safety net: while this exam is in progress, Auth::
   requireLogin() won't evict the user before allotted time + grace elapses,
   even if activity-tracking (autosave pings) hiccups for any reason.
   Cleared on submit (submit.php) and on draft-clear (autosave.php). */
$_SESSION['exam_deadline'] = $startTime + ($timeMinutes * 60) + 120; // +2 min grace for submit round-trip

/* ── Load existing draft answers (auto-save restore) ─────────────────────
   Keyed by QuestionId => SelectedAnswer for pre-filling the form on reload. */
$draftAnswers = [];
if ($myUid) {
    try {
        $draftRows = Database::fetchAll(
            "SELECT QuestionId, SelectedAnswer FROM exam_drafts
              WHERE ExamInfoId = ? AND UserInfoId = ?",
            [$examId, $myUid]);
        foreach ($draftRows as $dr) {
            $draftAnswers[(int)$dr['QuestionId']] = $dr['SelectedAnswer'];
        }
    } catch (Exception $e) { /* exam_drafts table not yet created */ }
}
$hasDraft = !empty($draftAnswers);

/* ── Collect question IDs the student saw in their last 5 attempts ─────────
   Used to avoid repeating questions; gracefully no-ops if tables are absent. */
$recentQids    = [];
$excludeSql    = '';
$excludeParams = [];
if ($myUid) {
    try {
        $prevAttempts = Database::fetchAll(
            "SELECT StudentExamId FROM studentexam
              WHERE ExamInfoId = ? AND UserInfoId = ?
              ORDER BY CreateDate DESC LIMIT 5",
            [$examId, $myUid]
        );
        if ($prevAttempts) {
            $seIds    = array_column($prevAttempts, 'StudentExamId');
            $sePh     = implode(',', array_fill(0, count($seIds), '?'));
            $seenRows = Database::fetchAll(
                "SELECT DISTINCT QuestionId FROM studentexamresults
                  WHERE StudentExamId IN ($sePh)",
                $seIds
            );
            $recentQids = array_column($seenRows, 'QuestionId');
        }
    } catch (Exception $e) { /* no history tables yet — skip */ }
}

/* Only apply exclusion when enough unseen questions exist in the pool.
   Uses exam_questions JOIN (migration_v22+); falls back to questions.ExamInfoId. */
if ($recentQids) {
    $exPh = implode(',', array_fill(0, count($recentQids), '?'));
    try {
        /* Primary count: use exam_questions join (migration_v22+).
           COALESCE(q.IsDeleted,'N')='N' excludes soft-deleted questions
           (migration_v43) — without this, a deleted question could still
           count toward the "enough fresh questions" decision below. */
        $freshRow = Database::fetchOne(
            "SELECT COUNT(*) AS cnt
               FROM exam_questions eq
               JOIN questions q ON q.QuestionId = eq.QuestionId
              WHERE eq.ExamInfoId = ?
                AND COALESCE(eq.IsActive,'Y') = 'Y'
                AND COALESCE(q.IsDeleted,'N') = 'N'
                AND q.QuestionId NOT IN ($exPh)",
            array_merge([$examId], $recentQids)
        );
    } catch (Exception $e) {
        /* exam_questions not yet created (or IsDeleted column missing) —
           try legacy ExamInfoId column, still deleted-aware where possible. */
        try {
            $freshRow = Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM questions q
                  WHERE q.ExamInfoId = ? AND COALESCE(q.IsActive,'Y') = 'Y'
                    AND COALESCE(q.IsDeleted,'N') = 'N'
                    AND q.QuestionId NOT IN ($exPh)",
                array_merge([$examId], $recentQids)
            );
        } catch (Exception $e2) { $freshRow = null; }
    }
    if ((int)($freshRow['cnt'] ?? 0) >= $numQuestions) {
        $excludeSql    = " AND q.QuestionId NOT IN ($exPh)";
        $excludeParams = $recentQids;
    }
    // else: not enough fresh questions — use full pool (exclusion stays empty)
}

/* ── Fetch questions — random order, history-aware ──────────────────────────
   After migration_v22: questions fetched via exam_questions join.
   COALESCE on IsActive handles rows where IsActive was not explicitly set.
   Falls back to legacy dual-JOIN query for pre-migration databases.             */

$questions    = [];   // safe default — prevents undefined-variable if all paths fail

$hasCaseStudyCol = Database::hasColumn('questions', 'CaseStudyId');
$caseStudyIdCol  = $hasCaseStudyCol ? 'q.CaseStudyId' : 'NULL AS CaseStudyId';

if ($isMultiSubject) {
    /* ── Sectioned draw (migration_v54) ───────────────────────────────────
       One exam_questions/questions query per section, filtered to that
       section's SubjectInfoId and capped at that section's own
       NumOfQuestions, concatenated in SortOrder — so Physics/Chemistry/
       Botany/Zoology (or any custom set of subjects) each land exactly
       their configured count, grouped and in pattern order, instead of one
       shuffled draw across every subject's pool. The same recent-question
       exclusion computed above is reused for every section; requires
       migration_v22 (exam_questions) + migration_v54 (questions.SubjectInfoId,
       exam_sections) — falls back to an empty section on any query error
       rather than a fatal, so one misconfigured section doesn't blank the
       whole exam. */
    foreach ($examSections as $sec) {
        $secSubjectId = (int)$sec['SubjectInfoId'];
        $secCount     = (int)$sec['NumOfQuestions'];
        if ($secCount < 1) { continue; }
        $secParams = array_merge([$examId, $secSubjectId], $excludeParams, [$secCount]);
        try {
            $secQuestions = Database::fetchAll(
                "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc,
                        q.NumofImages, q.OperatorInd, $caseStudyIdCol,
                        COALESCE(q.QuestionType,        'MCQ') AS QuestionType,
                        COALESCE(q.ExpectedAnswerCount,  0)    AS ExpectedAnswerCount,
                        a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        a.AnsImageInd, a.MultiImageInd,
                        a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                        COALESCE(a.NumStatements, 4) AS NumStatements,
                        a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4
                   FROM exam_questions eq
                   JOIN questions q ON q.QuestionId = eq.QuestionId
              LEFT JOIN answers   a ON a.QuestionId = q.QuestionId
                  WHERE eq.ExamInfoId = ? AND q.SubjectInfoId = ?
                    AND COALESCE(eq.IsActive,'Y') = 'Y'
                    AND COALESCE(q.IsDeleted,'N') = 'N'" . $excludeSql . "
                  ORDER BY RAND() LIMIT ?",
                $secParams);
        } catch (Exception $e) {
            $secQuestions = [];
        }
        $sectionLabel = $sec['SectionLabel'] ?: ($sec['SubjectName'] ?? 'Section');
        foreach ($secQuestions as &$sq) {
            $sq['_SectionId']    = (int)$sec['ExamSectionId'];
            $sq['_SectionLabel'] = $sectionLabel;
        }
        unset($sq);
        $questions = array_merge($questions, $secQuestions);
    }
    unset($excludeSql, $excludeParams, $recentQids, $prevAttempts, $seIds, $sePh, $seenRows, $freshRow, $exPh);
} else {
$_qParams     = array_merge([$examId], $excludeParams, [$numQuestions]);

try {
    /* Primary path: exam_questions join table (migration_v22+).
       COALESCE(q.IsDeleted,'N')='N' keeps soft-deleted questions (migration_v43)
       out of live exams — previously missing here, which let a question that
       exam/questions.php shows as "deleted" still be served to students. */
    $questions = Database::fetchAll(
        "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc,
                q.NumofImages, q.OperatorInd, $caseStudyIdCol,
                COALESCE(q.QuestionType,        'MCQ') AS QuestionType,
                COALESCE(q.ExpectedAnswerCount,  0)    AS ExpectedAnswerCount,
                a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                a.AnsImageInd, a.MultiImageInd,
                a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                COALESCE(a.NumStatements, 4) AS NumStatements,
                a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4
           FROM exam_questions eq
           JOIN questions q ON q.QuestionId = eq.QuestionId
      LEFT JOIN answers   a ON a.QuestionId = q.QuestionId
          WHERE eq.ExamInfoId = ? AND COALESCE(eq.IsActive,'Y') = 'Y'
            AND COALESCE(q.IsDeleted,'N') = 'N'" . $excludeSql . "
          ORDER BY RAND() LIMIT ?",
        $_qParams);
} catch (Exception $e) {
    /* Fallback: exam_questions not yet created (or IsDeleted column missing) —
       legacy dual LEFT JOIN with COALESCE, still deleted-aware where possible.
       NOTE: The old examinfo/GradeInfoId JOIN has been removed. It was redundant
       (ExamInfoId already uniquely identifies the exam) and caused link rows to be
       silently dropped whenever GradeInfoId was NULL or mismatched.               */
    $_qParamsLegacy = array_merge([$examId], $excludeParams, [$numQuestions]);
    try {
        $questions = Database::fetchAll(
            "SELECT q.QuestionId,
                    COALESCE(sq.QuestionDesc,  q.QuestionDesc)               AS QuestionDesc,
                    COALESCE(sq.ImageInd,      q.ImageInd)                   AS ImageInd,
                    COALESCE(sq.ImageLoc,      q.ImageLoc)                   AS ImageLoc,
                    COALESCE(sq.NumofImages,   q.NumofImages)                AS NumofImages,
                    COALESCE(sq.OperatorInd,   q.OperatorInd)                AS OperatorInd,
                    COALESCE(sq.QuestionType,  q.QuestionType,  'MCQ')       AS QuestionType,
                    COALESCE(sq.ExpectedAnswerCount, q.ExpectedAnswerCount, 0) AS ExpectedAnswerCount,
                    COALESCE(sa.AnswerId,      a.AnswerId)                   AS AnswerId,
                    COALESCE(sa.Answer1,       a.Answer1)                    AS Answer1,
                    COALESCE(sa.Answer2,       a.Answer2)                    AS Answer2,
                    COALESCE(sa.Answer3,       a.Answer3)                    AS Answer3,
                    COALESCE(sa.Answer4,       a.Answer4)                    AS Answer4,
                    COALESCE(sa.AnsImageInd,   a.AnsImageInd)                AS AnsImageInd,
                    COALESCE(sa.MultiImageInd, a.MultiImageInd)              AS MultiImageInd,
                    COALESCE(sa.YesNo1,  a.YesNo1)  AS YesNo1,
                    COALESCE(sa.YesNo2,  a.YesNo2)  AS YesNo2,
                    COALESCE(sa.YesNo3,  a.YesNo3)  AS YesNo3,
                    COALESCE(sa.YesNo4,  a.YesNo4)  AS YesNo4,
                    COALESCE(sa.NumStatements, a.NumStatements, 4) AS NumStatements,
                    COALESCE(sa.MatchStatement1, a.MatchStatement1) AS MatchStatement1,
                    COALESCE(sa.MatchStatement2, a.MatchStatement2) AS MatchStatement2,
                    COALESCE(sa.MatchStatement3, a.MatchStatement3) AS MatchStatement3,
                    COALESCE(sa.MatchStatement4, a.MatchStatement4) AS MatchStatement4
               FROM questions q
          LEFT JOIN questions sq ON sq.QuestionId  = q.LinkedFromQuestionId
          LEFT JOIN answers   a  ON a.QuestionId   = q.QuestionId
          LEFT JOIN answers   sa ON sa.QuestionId  = q.LinkedFromQuestionId
              WHERE q.ExamInfoId = ?" . $excludeSql . "
                AND COALESCE(q.IsActive,'Y') = 'Y'
                AND COALESCE(q.IsDeleted,'N') = 'N' ORDER BY RAND() LIMIT ?",
            $_qParamsLegacy);
    } catch (Exception $e2) {
        /* Bare fallback: oldest schema without LinkedFromQuestionId */
        $questions = Database::fetchAll(
            "SELECT a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                    a.AnsImageInd, a.MultiImageInd,
                    q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc, q.NumofImages, q.OperatorInd,
                    'MCQ' AS QuestionType, 0 AS ExpectedAnswerCount,
                    NULL AS YesNo1, NULL AS YesNo2, NULL AS YesNo3, NULL AS YesNo4, 4 AS NumStatements
               FROM answers a
               JOIN questions q ON a.QuestionId = q.QuestionId
              WHERE q.ExamInfoId = ? ORDER BY RAND() LIMIT ?",
            [$examId, $numQuestions]);
    }
}
unset($_qParams, $_qParamsLegacy, $excludeSql, $excludeParams,
      $recentQids, $prevAttempts, $seIds, $sePh, $seenRows, $freshRow, $exPh);
} // end single-subject else branch

/* ── Case studies (migration_v52): complete + group ───────────────────────
   The random draw above (ORDER BY RAND() LIMIT $numQuestions) treats every
   question independently, so it could easily land 2 of a case study's 5
   questions and skip the rest — breaking the "shared scenario" concept.
   Fix: once any question from a case study is drawn, pull in the REST of
   that case study's active questions too (so the group is always complete),
   then reorder $questions so every group's questions sit contiguously —
   this can push the final count above $numQuestions, same as a real cert
   exam's length varies once case studies are involved. Scoring is
   unaffected: each question is still graded independently either way.
   No-ops entirely if migration_v52 hasn't been run. */
$caseStudyData = [];   // CaseStudyId => ['title'=>, 'sections'=>[...]]
if ($hasCaseStudyCol && Database::tableExists('exam_questions') && Database::tableExists('case_studies')) {
    $csIdsInDraw = array_values(array_unique(array_filter(
        array_map(fn($q) => (int)($q['CaseStudyId'] ?? 0), $questions))));

    if ($csIdsInDraw) {
        $drawnQids = array_column($questions, 'QuestionId');
        $csPh      = implode(',', array_fill(0, count($csIdsInDraw), '?'));
        $qPh       = implode(',', array_fill(0, count($drawnQids), '?'));

        try {
            $siblingRows = Database::fetchAll(
                "SELECT q.QuestionId, q.QuestionDesc, q.ImageInd, q.ImageLoc,
                        q.NumofImages, q.OperatorInd, q.CaseStudyId,
                        COALESCE(q.QuestionType,        'MCQ') AS QuestionType,
                        COALESCE(q.ExpectedAnswerCount,  0)    AS ExpectedAnswerCount,
                        a.AnswerId, a.Answer1, a.Answer2, a.Answer3, a.Answer4,
                        a.AnsImageInd, a.MultiImageInd,
                        a.YesNo1, a.YesNo2, a.YesNo3, a.YesNo4,
                        COALESCE(a.NumStatements, 4) AS NumStatements,
                        a.MatchStatement1, a.MatchStatement2, a.MatchStatement3, a.MatchStatement4
                   FROM exam_questions eq
                   JOIN questions q ON q.QuestionId = eq.QuestionId
              LEFT JOIN answers   a ON a.QuestionId = q.QuestionId
                  WHERE eq.ExamInfoId = ? AND COALESCE(eq.IsActive,'Y') = 'Y'
                    AND COALESCE(q.IsDeleted,'N') = 'N'
                    AND q.CaseStudyId IN ($csPh)
                    AND q.QuestionId NOT IN ($qPh)",
                array_merge([$examId], $csIdsInDraw, $drawnQids));
        } catch (Exception $e) {
            $siblingRows = [];
        }

        /* Group-by-first-occurrence: keeps the overall random ordering between
           groups/standalone questions, but makes each group's own questions
           contiguous (originally-drawn members first, then any pulled-in
           siblings), sorted by QuestionId within the group for a stable,
           repeatable reading order across page reloads within one attempt. */
        $orderedKeys = [];
        $byKey       = [];
        foreach ($questions as $q) {
            $csId = (int)($q['CaseStudyId'] ?? 0);
            $key  = $csId > 0 ? 'cs_' . $csId : 'q_' . $q['QuestionId'];
            if (!isset($byKey[$key])) { $byKey[$key] = []; $orderedKeys[] = $key; }
            $byKey[$key][] = $q;
        }
        foreach ($siblingRows as $sib) {
            $key = 'cs_' . (int)$sib['CaseStudyId'];
            if (!isset($byKey[$key])) { $byKey[$key] = []; $orderedKeys[] = $key; } // shouldn't happen, but stay safe
            $byKey[$key][] = $sib;
        }
        foreach ($orderedKeys as $key) {
            if (strpos($key, 'cs_') === 0) {
                usort($byKey[$key], fn($a, $b) => $a['QuestionId'] <=> $b['QuestionId']);
            }
        }

        $questions = [];
        foreach ($orderedKeys as $key) {
            foreach ($byKey[$key] as $q) { $questions[] = $q; }
        }

        /* Load the title + tabbed sections for every case study actually
           being shown, so the render loop can print one panel per group. */
        try {
            $csRows = Database::fetchAll(
                "SELECT CaseStudyId, Title FROM case_studies WHERE CaseStudyId IN ($csPh)",
                $csIdsInDraw);
            foreach ($csRows as $csRow) {
                $caseStudyData[(int)$csRow['CaseStudyId']] = ['title' => $csRow['Title'], 'sections' => []];
            }
            if ($caseStudyData) {
                $secPh = implode(',', array_fill(0, count($caseStudyData), '?'));
                $secRows = Database::fetchAll(
                    "SELECT CaseStudyId, SectionTitle, ContentHtml FROM case_study_sections
                      WHERE CaseStudyId IN ($secPh) ORDER BY CaseStudyId, SectionOrder, SectionId",
                    array_keys($caseStudyData));
                foreach ($secRows as $secRow) {
                    $caseStudyData[(int)$secRow['CaseStudyId']]['sections'][] = $secRow;
                }
            }
        } catch (Exception $e) { $caseStudyData = []; }
    }
}

// Batch-load operators
$mathQids = [];
foreach ($questions as $q) { if ($q['OperatorInd']==='Y') $mathQids[] = $q['QuestionId']; }
$operatorMap = [];
if ($mathQids) {
    $ph = implode(',', array_fill(0, count($mathQids), '?'));
    foreach (Database::fetchAll("SELECT * FROM quesoperators WHERE QuestionId IN ($ph)", $mathQids) as $op)
        $operatorMap[$op['QuestionId']] = $op;
}

// Batch-load answer images
$answerIds    = array_column($questions, 'AnswerId');
$answerImages = [];
if ($answerIds) {
    $ph = implode(',', array_fill(0, count($answerIds), '?'));
    foreach (Database::fetchAll(
        "SELECT AnswerId, AnswerImage1Loc, AnswerImage2Loc, AnswerImage3Loc, AnswerImage4Loc
           FROM answerimages WHERE AnswerId IN ($ph)", $answerIds) as $img)
        $answerImages[$img['AnswerId']] = $img;
}

$m = (int)date('m'); $y = (int)date('Y');
$curYear = ($m <= 5) ? $y - 1 : $y;

/**
 * Image paths in the DB are stored relative to Admin/ directory.
 * From exam/ we need to prepend ../Admin/ for any relative path
 * that doesn't already have a protocol or root-relative prefix.
 */
function resolveImgPath(string $raw): string {
    $raw = str_replace(' ', '', trim($raw));
    if ($raw === '') return '';
    if (strpos($raw, 'http') === 0 || strpos($raw, '//') === 0 || strpos($raw, '/') === 0) {
        return $raw;   // absolute — use as-is
    }
    if (strpos($raw, '../') === 0 || strpos($raw, './') === 0) {
        return $raw;   // already traverses directories — use as-is
    }
    // Relative path stored from Admin/ context → prepend ../Admin/
    return '../Admin/' . $raw;
}

/**
 * Render the question prompt: text (if any) then image (if any).
 * If text is non-empty AND an image exists, both are shown.
 * If text is empty and an image exists, only the image is shown.
 */
function renderQuestionPrompt(array $q, string $textClass = 'q-text'): void {
    $desc   = trim($q['QuestionDesc'] ?? '');
    $hasImg = ($q['ImageInd'] ?? 'N') === 'Y' && !empty($q['ImageLoc']);
    $imgSrc = $hasImg ? resolveImgPath($q['ImageLoc']) : '';

    if ($desc !== '') {
        echo '<div class="' . $textClass . '">' . htmlspecialchars($desc) . '</div>';
    }
    if ($imgSrc !== '') {
        echo '<div class="q-img-wrap">'
           . '<img src="' . htmlspecialchars($imgSrc) . '" alt="" class="q-img">'
           . '</div>';
    }
}

$pageTitle = 'Write Exam: ' . ($exam['ExamName'] ?? '');
$pageHead  = '<style>
  .exam-topbar  { background:#312e81;color:#fff;border-radius:8px 8px 0 0;padding:14px 20px; }
  .exam-meta    { display:flex;gap:24px;flex-wrap:wrap;background:#eef2ff;padding:10px 20px;border-bottom:1px solid #c7d2fe;font-size:.85rem; }
  .exam-meta span { font-weight:700;color:#3730a3; }
  .timer-box    { background:#fef2f2;border:2px solid #fca5a5;border-radius:6px;padding:6px 18px;display:inline-flex;align-items:center;gap:8px; }
  .progress-wrap{ background:#e2e8f0;border-radius:4px;height:8px;margin:4px 0 0; }
  .progress-fill{ height:100%;background:#059669;border-radius:4px;transition:width .3s; }

  /* ── One-question-at-a-time navigator ─────────────────────────────────────
     Everything below just toggles which .q-card (and its owning section
     header / case-study panel, if any) is visible — the underlying form
     fields, autosave, and per-type answer logic are completely untouched,
     so nothing about how an answer is recorded/graded changes. */
  .q-card.q-hidden, .exam-section-hdr.q-hidden, .case-study-panel.q-hidden { display:none !important; }

  .qnav-bar { position:sticky;top:calc(var(--nav-h,60px) + 6px);z-index:40;
              display:flex;align-items:center;justify-content:space-between;gap:14px;
              background:#fff;border:1px solid #e2e8f0;border-radius:10px;
              padding:10px 16px;margin-bottom:14px;box-shadow:0 2px 8px rgba(0,0,0,.06);flex-wrap:wrap; }
  .qnav-side  { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
  /* Labeled, boxed Previous/Next controls — grouped together on the right
     side of the bar (replaces the old left-arrow / right-arrow layout,
     which testers found easy to miss during an exam). Next is styled as
     the primary (filled) action since it gets most of the clicks; Previous
     is a secondary (outlined) action right next to it. */
  .qnav-box   { display:inline-flex;align-items:center;gap:6px;padding:10px 20px;
                border-radius:8px;font-weight:700;font-size:.85rem;cursor:pointer;
                flex-shrink:0;transition:.15s;white-space:nowrap;border:2px solid transparent; }
  .qnav-box .qnav-ic { font-size:1.05rem;line-height:1; }
  .qnav-prev  { background:#fff;border-color:#312e81;color:#312e81; }
  .qnav-prev:hover:not(:disabled) { background:#eef2ff; }
  .qnav-next  { background:#312e81;color:#fff; }
  .qnav-next:hover:not(:disabled) { background:#4338ca; }
  .qnav-prev:disabled, .qnav-next:disabled { background:#f1f5f9;border-color:#cbd5e1;color:#94a3b8;cursor:not-allowed; }
  .qnav-skip  { padding:9px 16px;border-radius:8px;border:2px solid #f59e0b;background:#fffbeb;color:#92400e;
                font-weight:700;font-size:.82rem;cursor:pointer;transition:.15s;white-space:nowrap; }
  .qnav-skip:hover { background:#fef3c7; }
  .qnav-count { font-size:.85rem;font-weight:800;color:#312e81;white-space:nowrap; }
  .qnav-actions { display:flex;align-items:center;gap:8px;flex-wrap:wrap; }

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
  .qpalette-btn.qp-current  { border-color:#312e81;background:#312e81;color:#fff;box-shadow:0 0 0 3px #c7d2fe; }
  .qpalette-btn.qp-answered { border-color:#059669;background:#ecfdf5;color:#065f46; }
  .qpalette-btn.qp-skipped  { border-color:#f59e0b;background:#fffbeb;color:#92400e; }
  @media(max-width:640px){
    .qnav-bar   { top:4px; }
    .qnav-count { width:100%;order:3;text-align:center; }
    .qnav-actions { width:100%;justify-content:center; }
    .qnav-box   { flex:1;justify-content:center; }
  }

  /* ── Layout toggle (Stacked / Side-by-side) ───────────────────────────────
     Compare-and-decide feature: everything below is inert unless #examBody
     has .layout-split — Stacked mode (default) renders exactly as before. */
  .layout-toggle { display:inline-flex;border:2px solid #cbd5e1;border-radius:8px;overflow:hidden;margin-top:2px; }
  .layout-toggle button { padding:6px 13px;font-size:.78rem;font-weight:700;border:none;background:#fff;
                           color:#475569;cursor:pointer;transition:.15s; }
  .layout-toggle button:hover { background:#f1f5f9; }
  .layout-toggle button.active { background:#312e81;color:#fff; }

  /* Split 1: case-study passage (col 1) vs. the current question (col 2).
     minmax(0,auto) means column 1 has zero width — no empty gap — for any
     question that is not part of a case study, since nothing occupies it. */
  #examBody.layout-split .qbody-grid {
    display:grid;grid-template-columns:minmax(0,auto) 1fr;gap:18px;align-items:start;
  }
  #examBody.layout-split .case-study-panel { grid-column:1;max-width:380px;margin-bottom:0; }
  #examBody.layout-split .exam-section-hdr  { grid-column:1 / -1; }
  #examBody.layout-split .q-card             { grid-column:2;margin-bottom:0; }
  @media (max-width:860px) {
    #examBody.layout-split .qbody-grid       { grid-template-columns:1fr; }
    #examBody.layout-split .case-study-panel { grid-column:1;max-width:none;margin-bottom:14px; }
    #examBody.layout-split .q-card            { grid-column:1; }
  }

  /* Split 2: prompt/image (left) vs. the answer controls (right) —
     now applied uniformly to every question type (MCQ, MULTI, YESNO, MATCH,
     and DROPDOWN-with-image) so Side-by-side always means "two columns" and
     Stacked always means "one column, top to bottom", regardless of what
     kind of question is on screen.

     Stacked (default, no #examBody.layout-split ancestor): .q-split-row is
     forced to a plain block and the prompt/answer halves get a visible
     divider + spacing between them, so the vertical stacking is obvious
     even for a plain single-panel MCQ with no case study attached. */
  .q-split-row   { display:block; }
  .q-split-left  { margin-bottom:14px; }
  .q-split-right { padding-top:14px;border-top:1px dashed #cbd5e1; }

  /* Side-by-side mode: real two-column flex layout. The left (prompt) pane
     gets a tinted card background so it reads as a distinct panel next to
     the answer controls — an unmistakable visual break from Stacked mode. */
  #examBody.layout-split .q-split-row   { display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap; }
  #examBody.layout-split .q-split-left  { flex:1 1 280px;min-width:0;margin-bottom:0;
                                           background:#f8fafc;border:1px solid #e2e8f0;
                                           border-radius:8px;padding:14px; }
  #examBody.layout-split .q-split-right { flex:1 1 280px;min-width:0;padding-top:0;border-top:none; }

  /* Multi-subject section header (migration_v54) — e.g. the Physics/
     Chemistry/Botany/Zoology dividers in a NEET-pattern exam. */
  .exam-section-hdr { background:#312e81;color:#fff;font-weight:800;font-size:1.05rem;
                       text-align:center;letter-spacing:.3px;border-radius:8px;
                       padding:10px 16px;margin:0 0 14px; }
  .exam-section-hdr:not(:first-child) { margin-top:20px; }

  /* ── Question cards ───────────────────────────────────────────────────── */
  .q-card { border:2px solid #e2e8f0;border-radius:8px;margin-bottom:12px;overflow:hidden;transition:.2s; }
  .q-card.answered { border-color:#059669; }
  .q-card-hdr { background:#1e1b4b;color:#fff;padding:9px 14px;display:flex;align-items:center;gap:10px; }
  .q-num { font-weight:900;font-size:.88rem;min-width:30px;height:30px;border-radius:50%;
            background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;
            flex-shrink:0;transition:.2s; }
  .q-num.answered { background:#059669; }
  .q-type-tag { font-size:.72rem;padding:2px 9px;border-radius:10px;background:rgba(255,255,255,.18);font-weight:600; }
  .q-card-body { padding:14px 16px;background:#fff; }
  .q-text { font-size:.9rem;line-height:1.5;color:#1e1b4b;margin-bottom:8px; }
  .q-img-wrap { margin:6px 0 12px; }
  .q-img { max-width:100%;max-height:280px;border-radius:6px;border:1px solid #e2e8f0;display:block; }

  /* MCQ option grid */
  .mcq-grid { display:grid;grid-template-columns:1fr 1fr;gap:8px; }
  @media(max-width:640px){ .mcq-grid { grid-template-columns:1fr; } }
  .mcq-opt { display:flex;align-items:center;gap:10px;padding:10px 12px;border:2px solid #e2e8f0;
              border-radius:6px;cursor:pointer;transition:.15s;background:#fff;user-select:none; }
  .mcq-opt:hover { border-color:#a5b4fc;background:#eef2ff; }
  .mcq-opt.selected { border-color:#059669;background:#ecfdf5; }
  .mcq-opt.selected .opt-badge { background:#059669; }
  .opt-badge { font-weight:800;min-width:30px;height:30px;border-radius:50%;background:#312e81;
               color:#fff;display:flex;align-items:center;justify-content:center;
               font-size:.82rem;flex-shrink:0;transition:.2s; }

  /* DROPDOWN — question text left, select control right (mirrors YESNO layout) */
  .dropdown-wrap  { display:flex;align-items:center;justify-content:space-between;
                    gap:16px;flex-wrap:wrap; }
  .dropdown-stem  { font-size:.9rem;font-weight:600;color:#1e1b4b;flex:1;min-width:0; }
  .dropdown-select{ padding:8px 14px;border:2px solid #cbd5e1;border-radius:6px;font-size:.88rem;
                    min-width:200px;cursor:pointer;background:#fff;transition:.15s;flex-shrink:0; }
  .dropdown-select:focus { border-color:#6366f1;outline:none;box-shadow:0 0 0 3px rgba(99,102,241,.18); }

  /* YESNO */
  .yesno-instr { font-size:.85rem;color:#475569;margin-bottom:10px;font-style:italic; }
  .yesno-tbl   { width:100%;border-collapse:collapse;font-size:.875rem; }
  .yesno-tbl th{ background:#1e1b4b;color:#e0e7ff;padding:7px 12px;text-align:center;font-size:.82rem; }
  .yesno-tbl th:first-child { text-align:left; }
  .yesno-tbl td{ padding:8px 12px;border-bottom:1px solid #e2e8f0;vertical-align:middle; }
  .yesno-tbl tr:last-child td { border-bottom:none; }
  .yesno-tbl tr:nth-child(even) td { background:#f8fafc; }
  .yn-radio-lbl { display:inline-flex;align-items:center;justify-content:center;
                  width:36px;height:36px;border-radius:50%;border:2px solid #cbd5e1;
                  cursor:pointer;font-weight:700;font-size:.82rem;color:#475569;
                  transition:.15s;user-select:none; }
  .yn-radio-lbl:hover { border-color:#6366f1;background:#eef2ff; }
  .yn-radio-lbl.yn-checked-y { background:#dcfce7;border-color:#059669;color:#065f46; }
  .yn-radio-lbl.yn-checked-n { background:#fee2e2;border-color:#dc2626;color:#991b1b; }

  /* MATCH — drag & drop matching */
  .match-wrap   { display:flex;gap:20px;flex-wrap:wrap; }
  .match-pool   { flex:0 0 220px;display:flex;flex-direction:column;gap:8px; }
  @media(max-width:640px){ .match-pool { flex:1 1 100%; } }
  .match-pool-title { font-size:.78rem;font-weight:700;color:#475569;margin-bottom:2px; }
  .match-chip   { display:flex;align-items:center;gap:8px;padding:9px 12px;border:2px solid #6366f1;
                  border-radius:6px;background:#eef2ff;cursor:grab;font-size:.85rem;font-weight:600;
                  color:#312e81;user-select:none;transition:.15s; }
  .match-chip:hover { background:#e0e7ff; }
  .match-chip.match-chip-used { opacity:.35;cursor:not-allowed;border-style:dashed; }
  .match-chip.match-chip-selected { box-shadow:0 0 0 3px #facc15; }
  .match-chip-badge { font-weight:800;min-width:24px;height:24px;border-radius:50%;background:#4f46e5;
                  color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0; }
  .match-targets{ flex:1;min-width:240px;display:flex;flex-direction:column;gap:8px; }
  .match-stmt-row{ display:flex;align-items:center;gap:12px;padding:8px 10px;border-radius:6px;
                  background:#f8fafc;border:1px solid #e2e8f0;flex-wrap:wrap; }
  .match-stmt-text{ flex:1;min-width:140px;font-size:.86rem;color:#1e1b4b; }
  .match-drop   { flex:0 0 160px;min-height:40px;border:2px dashed #94a3b8;border-radius:6px;
                  display:flex;align-items:center;justify-content:center;gap:6px;font-size:.82rem;
                  color:#94a3b8;cursor:pointer;transition:.15s;padding:4px 8px;text-align:center;background:#fff; }
  .match-drop.match-drop-filled{ border-style:solid;border-color:#059669;background:#ecfdf5;color:#065f46;font-weight:600; }
  .match-drop.match-drop-over  { border-color:#6366f1;background:#eef2ff; }

  /* ── Exam Instructions banner (shown at the very top when the exam starts) ─ */
  .exam-instructions-banner { border:1px solid #c7d2fe;border-radius:8px;background:#fff;margin-bottom:14px;overflow:hidden; }
  .ei-header      { display:flex;align-items:center;gap:12px;padding:14px 18px;background:#eef2ff;border-bottom:1px solid #c7d2fe; }
  .ei-icon        { font-size:1.4rem; }
  .ei-title       { font-weight:800;color:#312e81;font-size:1rem; }
  .ei-sub         { font-size:.8rem;color:#4338ca;margin-top:1px; }
  .ei-header      { cursor:pointer; }
  .ei-toggle-hint { margin-left:auto;font-size:.78rem;font-weight:700;color:#4338ca;white-space:nowrap;flex-shrink:0; }
  .ei-dismiss     { background:none;border:1px solid #a5b4fc;color:#4338ca;padding:5px 12px;
                    border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;flex-shrink:0; }
  .ei-dismiss:hover { background:#e0e7ff; }
  .ei-grid        { display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;padding:16px 18px; }
  .ei-item        { display:flex;gap:10px;align-items:flex-start; }
  .ei-item-icon   { width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;
                    font-size:1.05rem;flex-shrink:0; }
  .ei-item-title  { font-weight:700;color:#1e1b4b;font-size:.87rem; }
  .ei-item-desc   { font-size:.82rem;color:#4b5563;margin-top:2px;line-height:1.4; }
  .ei-notes       { padding:0 18px 16px; }
  .ei-notes ul    { margin:0;padding-left:18px;font-size:.82rem;color:#4b5563;line-height:1.6; }

  /* Case study panel (migration_v52) — shared background info shown once
     above each group of related questions, collapsible so it does not eat
     scroll space while answering. */
  .case-study-panel { border:1px solid #a5b4fc;border-radius:8px;background:#fff;margin-bottom:14px;overflow:hidden; }
  .cs-panel-hdr   { display:flex;justify-content:space-between;align-items:center;gap:10px;
                    padding:12px 16px;background:#312e81;color:#fff;cursor:pointer;font-weight:700;font-size:.92rem; }
  .cs-toggle-hint { font-size:.76rem;font-weight:600;color:#c7d2fe; }
  .cs-panel-body  { padding:14px 16px;border-top:1px solid #e0e7ff; }
  .cs-tabs        { display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:8px; }
  .cs-tab-btn     { padding:6px 14px;border-radius:16px;border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3;
                    font-size:.8rem;font-weight:700;cursor:pointer;transition:.15s; }
  .cs-tab-btn:hover { background:#e0e7ff; }
  .cs-tab-btn.active { background:#4f46e5;color:#fff;border-color:#4f46e5; }
  .cs-tab-content { display:none; }
  .cs-tab-content.active { display:block; }
  .cs-tab-content h4 { margin:0 0 8px;font-size:.85rem;color:#312e81; }
  .cs-tab-text    { font-size:.85rem;line-height:1.6;color:#334155;white-space:pre-line; }
</style>';
include __DIR__ . '/../includes/header.php';
?>

<form id="frmWriteExam" method="post" action="submit.php" onsubmit="return validateExam(event)">
  <input type="hidden" name="csrf_token"     value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
  <input type="hidden" name="InfoId"         value="<?php echo $examId; ?>">
  <input type="hidden" name="starttime"      value="<?php echo $startTime; ?>">
  <input type="hidden" name="number_of_ques" value="<?php echo count($questions); ?>">
  <input type="hidden" name="SubjectId"      value="<?php echo (int)$exam['SubjectInfoId']; ?>">
  <input type="hidden" name="MarksOutOf"     value="100">
  <input type="hidden" name="GradeId"        value="<?php echo (int)$exam['GradeInfoId']; ?>">
  <input type="hidden" name="PassingMarks"   value="<?php echo (int)$exam['MinPassing']; ?>">
  <input type="hidden" name="violations"     value="0" id="hdnViolations">

  <!-- ── Exam Instructions banner — shown at the very top when the exam starts ── -->
  <div class="exam-instructions-banner" id="examInstructionsBanner">
    <div class="ei-header" onclick="toggleExamInstructions()">
      <span class="ei-icon">&#128203;</span>
      <div>
        <div class="ei-title">Exam Instructions</div>
        <div class="ei-sub" id="eiSub">Tap to view duration, question count, and important notes before you begin.</div>
      </div>
      <span class="ei-toggle-hint" id="eiToggleHint">Show &#9662;</span>
      <button type="button" class="ei-dismiss"
              onclick="event.stopPropagation(); document.getElementById('examInstructionsBanner').style.display='none';">
        Got it &times;
      </button>
    </div>
    <div class="ei-body" id="eiBody" style="display:none;">
    <div class="ei-grid">
      <div class="ei-item">
        <span class="ei-item-icon" style="background:#dbeafe;color:#1d4ed8;">&#9201;</span>
        <div>
          <div class="ei-item-title">Exam Duration</div>
          <div class="ei-item-desc">The total duration of this exam is <strong><?php echo $timeMinutes; ?> minutes</strong>.</div>
        </div>
      </div>
      <div class="ei-item">
        <span class="ei-item-icon" style="background:#dcfce7;color:#15803d;">&#10067;</span>
        <div>
          <div class="ei-item-title">Total Questions</div>
          <div class="ei-item-desc">There are <strong><?php echo count($questions); ?></strong> question<?php echo count($questions) !== 1 ? 's' : ''; ?> in this exam.</div>
        </div>
      </div>
      <div class="ei-item">
        <span class="ei-item-icon" style="background:#fef3c7;color:#b45309;">&#127919;</span>
        <div>
          <div class="ei-item-title">Passing Score</div>
          <div class="ei-item-desc">You need <strong><?php echo (int)$exam['MinPassing']; ?>%</strong> or higher to pass.</div>
        </div>
      </div>
      <?php if (!$isAdmin && isset($attemptStatus)): ?>
      <div class="ei-item">
        <span class="ei-item-icon" style="background:#ede9fe;color:#6d28d9;">&#128260;</span>
        <div>
          <div class="ei-item-title">Attempts</div>
          <div class="ei-item-desc">
            <?php if ((int)$attemptStatus['max'] === 0): ?>
              Unlimited attempts are allowed for this exam.
            <?php else: ?>
              You have used <strong><?php echo (int)$attemptStatus['used']; ?></strong> of
              <strong><?php echo (int)$attemptStatus['max']; ?></strong> allowed attempts.
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div class="ei-notes">
      <div class="ei-item-title" style="margin-bottom:6px;">&#128221; Important Notes</div>
      <ul>
        <li>Once started, the timer cannot be paused — plan your time accordingly.</li>
        <li>Do not refresh or close this browser tab while the exam is in progress.</li>
        <li>Make sure you have a stable internet connection; your answers are auto-saved as you go.</li>
        <?php if ($hasDraft): ?>
        <li>You have a previously saved draft for this exam — your earlier answers have been restored below.</li>
        <?php endif; ?>
      </ul>
    </div>
    </div><!-- /ei-body -->
  </div>

  <?php if ($hasDraft): ?>
  <div style="background:#fefce8;border:1px solid #fde047;border-radius:6px;padding:10px 16px;
              margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:.875rem;color:#713f12;">
    <span style="font-size:1.1rem;">&#9888;</span>
    <strong>Draft restored</strong> &mdash; your previous answers have been reloaded automatically.
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="exam-topbar">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:1.1rem;font-weight:700;"><?php echo htmlspecialchars($exam['ExamName'] ?? ''); ?></div>
          <div style="font-size:.8rem;color:#bee3f8;margin-top:2px;"><?php echo $curYear; ?>&ndash;<?php echo $curYear+1; ?></div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
          <div class="timer-box">
            <span style="font-size:.85rem;color:#e53e3e;">&#9203; Time Left:</span>
            <span id="countdown" style="font-weight:900;font-size:1.1rem;color:#c53030;letter-spacing:1px;min-width:80px;"></span>
          </div>
          <span id="autoSaveStatus" style="font-size:.72rem;color:#93c5fd;opacity:.85;"></span>
        </div>
      </div>
    </div>

    <div class="exam-meta">
      <div><span>Grade:</span> <?php echo htmlspecialchars($exam['GradeName'] ?? ''); ?></div>
      <div><span>Subject:</span> <?php echo $isMultiSubject
        ? htmlspecialchars(implode(' + ', array_map(
              fn($s) => $s['SectionLabel'] ?: ($s['SubjectName'] ?? 'Subject'), $examSections)))
        : htmlspecialchars($exam['SubjectName'] ?? ''); ?></div>
      <div><span>Questions:</span> <?php echo count($questions); ?><?php
        // Case-study completion (migration_v52) can pull in a few extra
        // questions beyond the exam's configured NumOfQuestions so a drawn
        // case study is never shown half-finished — flag that here so the
        // count on screen always matches what's actually rendered below.
        if (count($questions) !== $numQuestions): ?>
          <span style="font-size:.72rem;color:#c7d2fe;" title="A case study was included, so a few extra related questions were added">
            (incl. case study)
          </span>
      <?php endif; ?></div>
      <div><span>Pass Mark:</span> <?php echo (int)$exam['MinPassing']; ?>%</div>
      <div>
        <span>Layout:</span>
        <div class="layout-toggle">
          <button type="button" id="layoutStackedBtn" class="active" onclick="setExamLayout('stacked')"
                  title="Prompt above options, one column">Stacked</button>
          <button type="button" id="layoutSplitBtn" onclick="setExamLayout('split')"
                  title="Prompt / case study on the left, options on the right">Side-by-side</button>
        </div>
      </div>
      <div style="margin-left:auto;text-align:right;">
        <span>Answered: <strong id="answeredCount" style="color:#276749;">0</strong> / <?php echo count($questions); ?></strong></span>
        <div class="progress-wrap" style="width:150px;">
          <div class="progress-fill" id="progressBar" style="width:0%"></div>
        </div>
      </div>
    </div>

    <div style="padding:14px;" id="examBody">

      <!-- ── One-question-at-a-time navigator ─────────────────────────────
           Palette first (overview, jumps straight to any question by number
           and color-codes it: current / answered / skipped / not yet
           visited), then Prev/Next/Skip immediately above the question card
           itself, since that's the control used on nearly every click while
           actually working through the exam. -->
      <div class="qpalette-wrap">
        <div class="qpalette-legend">
          <b><i style="background:#312e81;border-color:#312e81;"></i> Current</b>
          <b><i style="background:#ecfdf5;border-color:#059669;"></i> Answered</b>
          <b><i style="background:#fffbeb;border-color:#f59e0b;"></i> Skipped</b>
          <b><i style="background:#fff;border-color:#cbd5e1;"></i> Not visited</b>
        </div>
        <div class="qpalette" id="qPalette">
          <?php foreach ($questions as $pi => $pq): $pIdx = $pi + 1; ?>
            <button type="button" class="qpalette-btn" data-idx="<?php echo $pIdx; ?>"
                    onclick="qGoto(<?php echo $pIdx; ?>)"><?php echo $pIdx; ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="qnav-bar">
        <div class="qnav-side">
          <span class="qnav-count"></span>
        </div>
        <div class="qnav-side qnav-actions">
          <button type="button" class="qnav-skip" onclick="qSkip()">&#8942; Skip for now</button>
          <button type="button" class="qnav-box qnav-prev" onclick="qPrev()" title="Previous question">
            <span class="qnav-ic">&#8249;</span> Previous
          </button>
          <button type="button" class="qnav-box qnav-next" onclick="qNext()" title="Next question">
            Next <span class="qnav-ic">&#8250;</span>
          </button>
        </div>
      </div>

      <!-- Grid wrapper for the Side-by-side layout: column 1 holds whichever
           case-study panel belongs to the current question (collapses to 0
           width when there isn't one), column 2 holds the current question
           itself. In Stacked mode (default) this is inert — a plain block
           container, so nothing here changes how things look today. -->
      <div class="qbody-grid" id="qbodyGrid">
      <?php $lastCaseStudyId = 0; ?>
      <?php $lastSectionId = null; ?>
      <?php foreach ($questions as $rowNum => $q):
          $qIdx      = $rowNum + 1;
          $curCaseStudyId = (int)($q['CaseStudyId'] ?? 0);
          $curSectionId   = $q['_SectionId'] ?? null;
      ?>
      <?php if ($isMultiSubject && $curSectionId !== null && $curSectionId !== $lastSectionId): ?>
      <div class="exam-section-hdr" id="secHdr_<?php echo htmlspecialchars((string)$curSectionId); ?>"><?php echo htmlspecialchars($q['_SectionLabel'] ?? 'Section'); ?></div>
      <?php endif; ?>
      <?php $lastSectionId = $curSectionId; ?>
      <?php if ($curCaseStudyId > 0 && $curCaseStudyId !== $lastCaseStudyId && isset($caseStudyData[$curCaseStudyId])):
          $csInfo = $caseStudyData[$curCaseStudyId];
          $csPanelId = 'csPanel_' . $curCaseStudyId;
      ?>
      <div class="case-study-panel" id="csWrap_<?php echo $curCaseStudyId; ?>">
        <div class="cs-panel-hdr" onclick="toggleCsPanel('<?php echo $csPanelId; ?>')">
          <span>&#128220; <?php echo htmlspecialchars($csInfo['title']); ?></span>
          <span class="cs-toggle-hint" id="<?php echo $csPanelId; ?>_hint">Show background info &#9662;</span>
        </div>
        <div class="cs-panel-body" id="<?php echo $csPanelId; ?>" style="display:none;">
          <?php if (count($csInfo['sections']) > 1): ?>
          <div class="cs-tabs">
            <?php foreach ($csInfo['sections'] as $si => $sec): ?>
              <button type="button" class="cs-tab-btn<?php echo $si === 0 ? ' active' : ''; ?>"
                      onclick="switchCsTab('<?php echo $csPanelId; ?>', <?php echo $si; ?>)">
                <?php echo htmlspecialchars($sec['SectionTitle']); ?>
              </button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php foreach ($csInfo['sections'] as $si => $sec): ?>
            <div class="cs-tab-content<?php echo $si === 0 ? ' active' : ''; ?>" id="<?php echo $csPanelId; ?>_tab<?php echo $si; ?>">
              <?php if (count($csInfo['sections']) > 1): ?>
                <h4><?php echo htmlspecialchars($sec['SectionTitle']); ?></h4>
              <?php endif; ?>
              <div class="cs-tab-text"><?php echo htmlspecialchars($sec['ContentHtml'] ?? ''); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php $lastCaseStudyId = $curCaseStudyId; ?>
      <?php
          $qType     = $q['QuestionType'] ?? 'MCQ';
          /* Auto-detect YESNO: older questions may have QuestionType=NULL/MCQ
             but still have YesNo answer data stored — treat those as YESNO. */
          if ($qType === 'MCQ' && !empty($q['YesNo1'])) { $qType = 'YESNO'; }
          $typeLabel  = ($qType === 'DROPDOWN') ? 'Dropdown'
                      : ($qType === 'YESNO'  ? 'Yes / No Grid'
                      : ($qType === 'MULTI'  ? 'Multi-Select'
                      : ($qType === 'MATCH'  ? 'Match the Pairs' : 'Multiple Choice')));
          $numStmt   = max(2, min(4, (int)($q['NumStatements'] ?? 4)));
          /* Draft restore helpers for this question */
          $draftVal  = $draftAnswers[(int)$q['QuestionId']] ?? '';
          $draftSet  = ($qType === 'MULTI' && $draftVal !== '')
                       ? array_map('trim', explode(',', $draftVal)) : [];
          $draftYN   = ($qType === 'YESNO' && $draftVal !== '')
                       ? explode(',', $draftVal) : [];
          /* MATCH draft: comma list of option-number-per-statement-position, e.g. "2,0,3" */
          $draftMatch = ($qType === 'MATCH' && $draftVal !== '')
                       ? array_map('intval', explode(',', $draftVal)) : [];
      ?>
      <input type="hidden" name="QuestionId<?php echo $qIdx; ?>"   value="<?php echo (int)$q['QuestionId']; ?>">
      <input type="hidden" name="QuestionType<?php echo $qIdx; ?>" value="<?php echo htmlspecialchars($qType); ?>">

      <div class="q-card" id="qrow<?php echo $qIdx; ?>"
           data-section="<?php echo htmlspecialchars((string)($curSectionId ?? '')); ?>"
           data-casestudy="<?php echo (int)$curCaseStudyId; ?>">
        <div class="q-card-hdr">
          <span class="q-num" id="qnum<?php echo $qIdx; ?>"><?php echo $qIdx; ?></span>
          <span class="q-type-tag"><?php echo htmlspecialchars($typeLabel); ?></span>
          <?php if ($curCaseStudyId > 0 && isset($caseStudyData[$curCaseStudyId])): ?>
            <span class="q-type-tag" style="background:rgba(255,255,255,.28);" title="Refer to the case study above">
              &#128220; <?php echo htmlspecialchars($caseStudyData[$curCaseStudyId]['title']); ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="q-card-body">

        <?php if ($qType === 'YESNO'): ?>
          <div class="q-split-row">
          <div class="q-split-left"><?php renderQuestionPrompt($q, 'yesno-instr'); ?></div>
          <div class="q-split-right">
          <table class="yesno-tbl">
            <thead>
              <tr>
                <th>Statement</th>
                <th style="width:70px;">Yes</th>
                <th style="width:70px;">No</th>
              </tr>
            </thead>
            <tbody>
            <?php for ($s = 1; $s <= $numStmt; $s++):
                $stmtText = $q['Answer'.$s] ?? '';
                if ($stmtText === '') continue;
            ?>
            <tr>
              <td><?php echo htmlspecialchars($stmtText); ?></td>
              <td style="text-align:center;">
                <label class="yn-radio-lbl" id="ynYes_<?php echo $qIdx.'_'.$s; ?>"
                     onclick="setYNAnswer(<?php echo $qIdx; ?>, <?php echo $s; ?>, 'Y')">
                  <input type="radio" name="rdoAnswer<?php echo $qIdx.'_'.$s; ?>" value="Y" style="display:none;"
                         id="ynInput_<?php echo $qIdx.'_'.$s; ?>_Y"
                         <?php echo (($draftYN[$s-1] ?? '') === 'Y') ? 'checked' : ''; ?>>
                  Y
                </label>
              </td>
              <td style="text-align:center;">
                <label class="yn-radio-lbl" id="yNo_<?php echo $qIdx.'_'.$s; ?>"
                     onclick="setYNAnswer(<?php echo $qIdx; ?>, <?php echo $s; ?>, 'N')">
                  <input type="radio" name="rdoAnswer<?php echo $qIdx.'_'.$s; ?>" value="N" style="display:none;"
                         id="ynInput_<?php echo $qIdx.'_'.$s; ?>_N"
                         <?php echo (($draftYN[$s-1] ?? '') === 'N') ? 'checked' : ''; ?>>
                  N
                </label>
              </td>
            </tr>
            <?php endfor; ?>
            </tbody>
          </table>
          </div><!-- /q-split-right -->
          </div><!-- /q-split-row -->

        <?php elseif ($qType === 'MATCH'): ?>
          <?php
            $matchLettersPhp = ['A', 'B', 'C', 'D'];
          ?>
          <div class="q-split-row">
          <div class="q-split-left"><?php renderQuestionPrompt($q, 'yesno-instr'); ?></div>
          <div class="q-split-right">
          <div class="match-wrap">
            <div class="match-pool" id="matchPool_<?php echo $qIdx; ?>">
              <div class="match-pool-title">Drag an option into its match:</div>
              <?php for ($o = 1; $o <= $numStmt; $o++):
                  $optText = $q['Answer'.$o] ?? '';
                  if ($optText === '') continue;
                  $usedOpt = in_array($o, $draftMatch, true);
              ?>
              <div class="match-chip<?php echo $usedOpt ? ' match-chip-used' : ''; ?>"
                   id="matchChip_<?php echo $qIdx.'_'.$o; ?>"
                   draggable="<?php echo $usedOpt ? 'false' : 'true'; ?>"
                   data-text="<?php echo htmlspecialchars($optText); ?>"
                   ondragstart="matchDragStart(event, <?php echo $qIdx; ?>, <?php echo $o; ?>)"
                   onclick="matchChipClick(<?php echo $qIdx; ?>, <?php echo $o; ?>)">
                <span class="match-chip-badge"><?php echo $matchLettersPhp[$o-1]; ?></span>
                <span><?php echo htmlspecialchars($optText); ?></span>
              </div>
              <?php endfor; ?>
            </div>
            <div class="match-targets">
              <?php for ($s = 1; $s <= $numStmt; $s++):
                  $stmtText  = $q['MatchStatement'.$s] ?? '';
                  if ($stmtText === '') continue;
                  $filledOpt = $draftMatch[$s-1] ?? 0;
              ?>
              <div class="match-stmt-row">
                <div class="match-stmt-text"><?php echo htmlspecialchars($stmtText); ?></div>
                <div class="match-drop<?php echo $filledOpt ? ' match-drop-filled' : ''; ?>"
                     id="matchDrop_<?php echo $qIdx.'_'.$s; ?>"
                     data-filled-opt="<?php echo $filledOpt ?: '0'; ?>"
                     ondragover="matchDragOver(event)"
                     ondragleave="matchDragLeave(event)"
                     ondrop="matchDrop(event, <?php echo $qIdx; ?>, <?php echo $s; ?>)"
                     onclick="matchDropClick(<?php echo $qIdx; ?>, <?php echo $s; ?>)">
                  <?php if ($filledOpt): ?>
                    <span class="opt-badge" style="width:22px;height:22px;min-width:22px;font-size:.7rem;"><?php echo $matchLettersPhp[$filledOpt-1] ?? '?'; ?></span>
                    <span><?php echo htmlspecialchars($q['Answer'.$filledOpt] ?? ''); ?></span>
                  <?php else: ?>
                    <span class="match-drop-placeholder">Answer</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endfor; ?>
            </div>
          </div>
          </div><!-- /q-split-right -->
          </div><!-- /q-split-row -->
          <!-- Hidden field that submit.php reads for MATCH — option number per
               statement position, comma-separated (e.g. "2,1,3"); "0" = unfilled -->
          <input type="hidden" name="rdoAnswer<?php echo $qIdx; ?>" id="matchVal_<?php echo $qIdx; ?>"
                 value="<?php echo htmlspecialchars($draftVal); ?>"
                 data-pairs="<?php echo $numStmt; ?>">

        <?php elseif ($qType === 'MULTI'): ?>
          <?php $expCount = (int)($q['ExpectedAnswerCount'] ?? 0); ?>
          <div class="q-split-row">
          <div class="q-split-left"><?php renderQuestionPrompt($q); ?></div>
          <div class="q-split-right">
          <?php if ($expCount >= 2): ?>
          <div style="font-size:.8rem;font-weight:700;color:#6d28d9;background:#ede9fe;
                      border-radius:6px;padding:5px 12px;margin-bottom:10px;display:inline-flex;
                      align-items:center;gap:6px;">
            &#9745; Select exactly <strong><?php echo $expCount; ?></strong> answer<?php echo $expCount>1?'s':''; ?>
            <span id="multiSel_<?php echo $qIdx; ?>" style="margin-left:4px;font-weight:400;color:#7c3aed;">
              (<?php echo count($draftSet); ?> selected)
            </span>
          </div>
          <?php else: ?>
          <div style="font-size:.8rem;color:#718096;margin-bottom:10px;">
            &#9745; Select all that apply
          </div>
          <?php endif; ?>
          <div class="mcq-grid">
          <?php foreach (['1'=>'A','2'=>'B','3'=>'C','4'=>'D'] as $optNum => $optLtr):
              $optText   = $q['Answer'.$optNum] ?? '';
              $hasAnsImg = ($q['AnsImageInd'] ?? 'N') === 'Y';
              $imgSrc    = '';
              if ($hasAnsImg) {
                  // MultiImageInd='N' means every option shares the question's own
                  // diagram (e.g. a single labelled figure); only when MultiImageInd='Y'
                  // does each option have its own distinct image.
                  $imgLoc = ($q['MultiImageInd'] === 'Y')
                      ? ($answerImages[$q['AnswerId']]['AnswerImage'.$optNum.'Loc'] ?? '')
                      : ($q['ImageLoc'] ?? '');
                  $imgSrc = resolveImgPath($imgLoc ?? '');
              }
              // Skip slots with nothing to show — keeps NumOptions<4 working for
              // image answers too, since AnsImageInd is a question-level flag.
              if ($hasAnsImg ? $imgSrc === '' : $optText === '') continue;
          ?>
            <label class="mcq-opt" id="mopt_<?php echo $qIdx.'_'.$optNum; ?>"
                   onclick="toggleMultiOpt(<?php echo $qIdx; ?>, <?php echo $optNum; ?>, this)">
              <span class="opt-badge" style="border-radius:4px;"><?php echo $optLtr; ?></span>
              <input type="checkbox" name="chkAnswer<?php echo $qIdx; ?>[]" value="<?php echo $optNum; ?>"
                     id="chk_<?php echo $qIdx.'_'.$optNum; ?>" style="display:none;"
                     <?php echo in_array((string)$optNum, $draftSet, true) ? 'checked' : ''; ?>>
              <?php if ($hasAnsImg): ?>
                <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                     style="max-width:110px;vertical-align:middle;border-radius:3px;" alt="">
              <?php else: ?>
                <span style="font-size:.9rem;"><?php echo htmlspecialchars($optText); ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
          </div>
          </div><!-- /q-split-right -->
          </div><!-- /q-split-row -->
          <!-- Hidden field that submit.php reads for MULTI — updated by JS -->
          <input type="hidden" name="rdoAnswer<?php echo $qIdx; ?>" id="multiVal_<?php echo $qIdx; ?>"
                 value="<?php echo htmlspecialchars($draftVal); ?>"
                 data-maxsel="<?php echo $expCount >= 2 ? (int)$expCount : 0; ?>">

        <?php elseif ($qType === 'DROPDOWN'): ?>
          <?php
            // The stem and its inline "— Select —" dropdown form one fill-in-the-
            // blank sentence, so they always stay together as a single unit —
            // splitting them into separate left/right panes would break the
            // sentence apart. When a diagram is attached, though, that image
            // genuinely is a distinct "prompt" element, so it still gets its
            // own pane in Side-by-side mode, same as every other question type.
            $hasDropdownImg = ($q['ImageInd'] ?? 'N') === 'Y' && !empty($q['ImageLoc']);
          ?>
          <?php if ($hasDropdownImg): ?>
          <div class="q-split-row">
          <div class="q-split-left">
            <div class="q-img-wrap">
              <img src="<?php echo htmlspecialchars(resolveImgPath($q['ImageLoc'])); ?>" alt="" class="q-img">
            </div>
          </div>
          <div class="q-split-right">
          <?php endif; ?>
          <div class="dropdown-wrap">
            <span class="dropdown-stem"><?php echo htmlspecialchars($q['QuestionDesc'] ?? ''); ?></span>
            <select class="dropdown-select" name="rdoAnswer<?php echo $qIdx; ?>"
                    onchange="markAnswered(<?php echo $qIdx; ?>, this.value !== '')">
              <option value="">— Select —</option>
              <?php foreach (['1'=>'A','2'=>'B','3'=>'C','4'=>'D'] as $optNum => $optLtr):
                  $optText = $q['Answer'.$optNum] ?? '';
                  if ($optText === '') continue;
              ?>
              <option value="<?php echo $optNum; ?>"><?php echo htmlspecialchars($optText); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($hasDropdownImg): ?>
          </div><!-- /q-split-right -->
          </div><!-- /q-split-row -->
          <?php endif; ?>

        <?php else: /* MCQ */ ?>
          <div class="q-split-row">
          <div class="q-split-left"><?php renderQuestionPrompt($q); ?></div>
          <div class="q-split-right">
          <div class="mcq-grid">
          <?php foreach (['1'=>'A','2'=>'B','3'=>'C','4'=>'D'] as $optNum => $optLtr):
              $optText   = $q['Answer'.$optNum] ?? '';
              $hasAnsImg = ($q['AnsImageInd'] ?? 'N') === 'Y';
              $imgSrc    = '';
              if ($hasAnsImg) {
                  // MultiImageInd='N' means every option shares the question's own
                  // diagram (e.g. a single labelled figure); only when MultiImageInd='Y'
                  // does each option have its own distinct image.
                  $imgLoc = ($q['MultiImageInd'] === 'Y')
                      ? ($answerImages[$q['AnswerId']]['AnswerImage'.$optNum.'Loc'] ?? '')
                      : ($q['ImageLoc'] ?? '');
                  $imgSrc = resolveImgPath($imgLoc ?? '');
              }
              // Skip slots with nothing to show — keeps NumOptions<4 working for
              // image answers too, since AnsImageInd is a question-level flag.
              if ($hasAnsImg ? $imgSrc === '' : $optText === '') continue;
          ?>
            <label class="mcq-opt" id="opt_<?php echo $qIdx.'_'.$optNum; ?>">
              <span class="opt-badge"><?php echo $optLtr; ?></span>
              <input type="radio" name="rdoAnswer<?php echo $qIdx; ?>" value="<?php echo $optNum; ?>"
                     style="display:none;"
                     <?php echo (($draftAnswers[$q['QuestionId']] ?? '') === $optNum) ? 'checked' : ''; ?>>
              <?php if ($hasAnsImg): ?>
                <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                     style="max-width:110px;vertical-align:middle;border-radius:3px;" alt="">
              <?php else: ?>
                <span style="font-size:.9rem;"><?php echo htmlspecialchars($optText); ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
          </div>
          </div><!-- /q-split-right -->
          </div><!-- /q-split-row -->

        <?php endif; ?>
        </div><!-- /q-card-body -->
      </div><!-- /q-card -->
      <?php endforeach; ?>
      </div><!-- /qbody-grid -->

      <div style="text-align:center;padding:20px 0 8px;">
        <button type="submit" class="btn btn-success" style="padding:12px 40px;font-size:1rem;">
          &#10003; Submit Exam
        </button>
      </div>
    </div><!-- /padding -->
  </div><!-- /card -->
</form>

<script>
/* ── Exam Instructions banner: collapsible, collapsed by default ─────────────
   Was previously always expanded, eating a large chunk of vertical space
   above the question navigator on every load. Same collapse pattern as the
   case-study panel below — header toggles the body, "Got it x" still fully
   dismisses the whole banner for the rest of this attempt. */
function toggleExamInstructions() {
  var body = document.getElementById('eiBody');
  var hint = document.getElementById('eiToggleHint');
  var sub  = document.getElementById('eiSub');
  if (!body) return;
  var showing = body.style.display !== 'none';
  body.style.display = showing ? 'none' : 'block';
  if (hint) hint.innerHTML = showing ? 'Show &#9662;' : 'Hide &#9652;';
  if (sub) sub.textContent = showing
    ? 'Tap to view duration, question count, and important notes before you begin.'
    : 'Please read the following details carefully before you begin.';
}

/* Arriving via the header's "Exam Instructions" link (#examInstructionsBanner)
   should actually show the instructions, not just scroll to a collapsed
   header — auto-expand when that's how this page was reached. */
if (window.location.hash === '#examInstructionsBanner') {
  toggleExamInstructions();
  document.getElementById('examInstructionsBanner').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ── Case study panel: collapsible + tabbed sections ──────────────────────── */
function toggleCsPanel(panelId) {
  var body = document.getElementById(panelId);
  var hint = document.getElementById(panelId + '_hint');
  if (!body) return;
  var showing = body.style.display !== 'none';
  body.style.display = showing ? 'none' : 'block';
  if (hint) hint.innerHTML = showing ? 'Show background info &#9662;' : 'Hide background info &#9652;';
}
function switchCsTab(panelId, idx) {
  var body = document.getElementById(panelId);
  if (!body) return;
  body.querySelectorAll('.cs-tab-btn').forEach(function(btn, i) {
    btn.classList.toggle('active', i === idx);
  });
  body.querySelectorAll('.cs-tab-content').forEach(function(tab) {
    tab.classList.toggle('active', tab.id === panelId + '_tab' + idx);
  });
}

/* ── Answer tracking ─────────────────────────────────────────────────────── */
var totalQ     = <?php echo count($questions); ?>;
var answered   = 0;
var answeredSet = {};

function markAnswered(idx, isAnswered) {
  var wasAnswered = !!answeredSet[idx];
  answeredSet[idx] = isAnswered;
  if (isAnswered && !wasAnswered)  answered++;
  if (!isAnswered && wasAnswered)  answered--;
  document.getElementById('answeredCount').textContent = answered;
  document.getElementById('progressBar').style.width   = (answered / totalQ * 100) + '%';
  var card = document.getElementById('qrow'+idx);
  var num  = document.getElementById('qnum'+idx);
  if (card) card.classList.toggle('answered', isAnswered);
  if (num)  num.classList.toggle('answered',  isAnswered);
}

/* ── MCQ: radio via styled divs ─────────────────────────────────────────── */
document.querySelectorAll('.mcq-opt').forEach(function(opt) {
  opt.addEventListener('click', function() {
    var radio = this.querySelector('input[type=radio]');
    if (!radio) return;
    var name = radio.name;
    document.querySelectorAll('input[name="'+name+'"]').forEach(function(r) {
      r.closest('.mcq-opt').classList.remove('selected');
      r.closest('.mcq-opt').querySelector('.opt-badge').style.background='#312e81';
    });
    radio.checked = true;
    this.classList.add('selected');
    this.querySelector('.opt-badge').style.background='#059669';
    // extract index from name "rdoAnswerN"
    var idx = parseInt(name.replace('rdoAnswer',''));
    markAnswered(idx, true);
  });
});

/* ── MULTI: checkbox toggle ─────────────────────────────────────────────── */
function toggleMultiOpt(qIdx, optNum, labelEl) {
  var chk    = document.getElementById('chk_'+qIdx+'_'+optNum);
  var hidFld = document.getElementById('multiVal_'+qIdx);
  var maxSel = hidFld ? parseInt(hidFld.getAttribute('data-maxsel') || 0) : 0;
  var isChecked = chk.checked;   // state BEFORE toggle

  if (!isChecked && maxSel >= 2) {
    // User wants to check — enforce max
    var alreadyCount = document.querySelectorAll('input[name="chkAnswer'+qIdx+'[]"]:checked').length;
    if (alreadyCount >= maxSel) {
      // At limit — flash the label as feedback, block
      labelEl.style.transition = 'box-shadow .1s';
      labelEl.style.boxShadow  = '0 0 0 3px #f87171';
      setTimeout(function(){ labelEl.style.boxShadow = ''; }, 300);
      return;
    }
  }

  chk.checked = !isChecked;
  labelEl.classList.toggle('selected', chk.checked);
  labelEl.querySelector('.opt-badge').style.background = chk.checked ? '#059669' : '#312e81';

  var vals = [];
  document.querySelectorAll('input[name="chkAnswer'+qIdx+'[]"]:checked').forEach(function(c){
    vals.push(c.value);
  });
  if (hidFld) hidFld.value = vals.join(',');

  // Update "N selected" counter badge
  var selBadge = document.getElementById('multiSel_'+qIdx);
  if (selBadge && maxSel >= 2) {
    selBadge.textContent = '(' + vals.length + ' selected)';
    selBadge.style.color = (vals.length === maxSel) ? '#059669' : '#7c3aed';
  }

  markAnswered(qIdx, vals.length > 0);
}

/* ── MATCH: drag & drop matching (+ click-to-place fallback) ──────────────
   Each statement slot stores the matched option number on the drop target's
   data-filled-opt attribute (0 = unfilled). The hidden #matchVal_{qIdx} field
   is rebuilt from those attributes after every change and is what
   collectAnswers()/submit.php actually reads — a comma list of option
   numbers ordered by statement position, e.g. "2,1,3".               ─── */
var matchLetters    = ['A', 'B', 'C', 'D'];
var _matchSelected  = {};   // qIdx -> currently click-selected option number (or null)

function matchChipLabel(qIdx, optNum) {
  var chip = document.getElementById('matchChip_' + qIdx + '_' + optNum);
  return chip ? chip.getAttribute('data-text') : '';
}

function matchSyncHidden(qIdx) {
  var hid = document.getElementById('matchVal_' + qIdx);
  if (!hid) return;
  var n = parseInt(hid.getAttribute('data-pairs') || '0');
  var vals = [], anyFilled = false;
  for (var s = 1; s <= n; s++) {
    var drop = document.getElementById('matchDrop_' + qIdx + '_' + s);
    var opt  = drop ? (drop.getAttribute('data-filled-opt') || '0') : '0';
    if (opt !== '0') anyFilled = true;
    vals.push(opt);
  }
  /* Empty string (not "0,0,0") when nothing is matched yet, so autosave's
     truthiness check in collectAnswers() correctly treats it as unanswered. */
  hid.value = anyFilled ? vals.join(',') : '';
  markAnswered(qIdx, anyFilled);
}

function matchSetChipUsed(qIdx, optNum, used) {
  var chip = document.getElementById('matchChip_' + qIdx + '_' + optNum);
  if (!chip) return;
  chip.classList.toggle('match-chip-used', used);
  chip.setAttribute('draggable', used ? 'false' : 'true');
}

function matchFindSlotForOpt(qIdx, optNum) {
  var hid = document.getElementById('matchVal_' + qIdx);
  var n = hid ? parseInt(hid.getAttribute('data-pairs') || '0') : 0;
  for (var s = 1; s <= n; s++) {
    var drop = document.getElementById('matchDrop_' + qIdx + '_' + s);
    if (drop && drop.getAttribute('data-filled-opt') === String(optNum)) return s;
  }
  return null;
}

function matchClearSlot(qIdx, stmtIdx) {
  var drop = document.getElementById('matchDrop_' + qIdx + '_' + stmtIdx);
  if (!drop) return;
  var prevOpt = drop.getAttribute('data-filled-opt');
  drop.setAttribute('data-filled-opt', '0');
  drop.classList.remove('match-drop-filled');
  drop.innerHTML = '<span class="match-drop-placeholder">Answer</span>';
  if (prevOpt && prevOpt !== '0') matchSetChipUsed(qIdx, prevOpt, false);
}

function matchPlaceOption(qIdx, optNum, stmtIdx) {
  /* If this option is already placed in another slot, vacate that slot first */
  var prevSlot = matchFindSlotForOpt(qIdx, optNum);
  if (prevSlot !== null && prevSlot !== stmtIdx) matchClearSlot(qIdx, prevSlot);

  var drop = document.getElementById('matchDrop_' + qIdx + '_' + stmtIdx);
  if (!drop) return;

  /* If the target slot already holds a different option, return it to the pool */
  var existing = drop.getAttribute('data-filled-opt');
  if (existing && existing !== '0' && existing !== String(optNum)) {
    matchSetChipUsed(qIdx, existing, false);
  }

  drop.setAttribute('data-filled-opt', String(optNum));
  drop.classList.add('match-drop-filled');
  var letter = matchLetters[optNum - 1] || optNum;
  drop.innerHTML =
    '<span class="opt-badge" style="width:22px;height:22px;min-width:22px;font-size:.7rem;">' +
    letter + '</span><span>' + matchChipLabel(qIdx, optNum) + '</span>';
  matchSetChipUsed(qIdx, optNum, true);

  /* Clear click-to-select state for this question */
  document.querySelectorAll('.match-chip.match-chip-selected[id^="matchChip_' + qIdx + '_"]')
    .forEach(function (c) { c.classList.remove('match-chip-selected'); });
  _matchSelected[qIdx] = null;

  matchSyncHidden(qIdx);
}

function matchDragStart(ev, qIdx, optNum) {
  if (ev.target.classList.contains('match-chip-used')) { ev.preventDefault(); return; }
  ev.dataTransfer.setData('text/plain', qIdx + ':' + optNum);
  ev.dataTransfer.effectAllowed = 'move';
}
function matchDragOver(ev) {
  ev.preventDefault();
  ev.currentTarget.classList.add('match-drop-over');
}
function matchDragLeave(ev) {
  ev.currentTarget.classList.remove('match-drop-over');
}
function matchDrop(ev, qIdx, stmtIdx) {
  ev.preventDefault();
  ev.currentTarget.classList.remove('match-drop-over');
  var data = ev.dataTransfer.getData('text/plain');
  var parts = data.split(':');
  if (parts.length !== 2) return;
  var dQIdx = parseInt(parts[0]), optNum = parseInt(parts[1]);
  if (dQIdx !== qIdx) return;   // ignore drops dragged from a different question
  matchPlaceOption(qIdx, optNum, stmtIdx);
}

/* Click-to-select / click-to-place fallback for touch & accessibility */
function matchChipClick(qIdx, optNum) {
  var chip = document.getElementById('matchChip_' + qIdx + '_' + optNum);
  if (!chip || chip.classList.contains('match-chip-used')) return;
  var already = _matchSelected[qIdx] === optNum;
  document.querySelectorAll('.match-chip.match-chip-selected[id^="matchChip_' + qIdx + '_"]')
    .forEach(function (c) { c.classList.remove('match-chip-selected'); });
  _matchSelected[qIdx] = already ? null : optNum;
  if (!already) chip.classList.add('match-chip-selected');
}
function matchDropClick(qIdx, stmtIdx) {
  var drop = document.getElementById('matchDrop_' + qIdx + '_' + stmtIdx);
  if (!drop) return;
  var filled = drop.getAttribute('data-filled-opt');
  if (filled && filled !== '0') {
    /* Already filled — clicking it removes the answer and frees the chip */
    matchClearSlot(qIdx, stmtIdx);
    matchSyncHidden(qIdx);
    return;
  }
  var sel = _matchSelected[qIdx];
  if (sel) matchPlaceOption(qIdx, sel, stmtIdx);
}

/* ── YESNO ───────────────────────────────────────────────────────────────── */
function setYNAnswer(qIdx, stmtIdx, val) {
  var yLbl = document.getElementById('ynYes_'+qIdx+'_'+stmtIdx);
  var nLbl = document.getElementById('yNo_'+qIdx+'_'+stmtIdx);
  if (yLbl) yLbl.classList.toggle('yn-checked-y', val==='Y');
  if (nLbl) nLbl.classList.toggle('yn-checked-n', val==='N');
  if (val==='Y' && yLbl) yLbl.classList.remove('yn-checked-n');
  if (val==='N' && nLbl) nLbl.classList.remove('yn-checked-y');
  var inp = document.getElementById('ynInput_'+qIdx+'_'+stmtIdx+'_'+val);
  if (inp) inp.checked = true;
  // mark question answered when at least one statement answered
  markAnswered(qIdx, true);
}

/* ── Countdown timer ─────────────────────────────────────────────────────── */
var timeLeft = <?php echo $timeMinutes * 60; ?>;
function tick() {
  var m = Math.floor(timeLeft/60), s = timeLeft % 60;
  document.getElementById('countdown').textContent =
    (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  if (timeLeft <= 0) { document.getElementById('frmWriteExam').submit(); return; }
  if (timeLeft <= 300) document.getElementById('countdown').style.color = '#c53030';
  timeLeft--;
  setTimeout(tick, 1000);
}
tick();

/* ── Validate before submit ──────────────────────────────────────────────── */
function validateExam(e) {
  var unanswered = totalQ - answered;
  if (unanswered > 0) {
    return confirm('You have ' + unanswered + ' unanswered question(s). Submit anyway?');
  }
  return true;
}

/* ── Restore visual selection state from draft on page load ────────────── */
(function restoreDraftVisuals() {
  /* MCQ: mark any pre-checked radio's parent label as .selected */
  document.querySelectorAll('input[type=radio][name^=rdoAnswer]').forEach(function(r) {
    if (r.checked) {
      var lbl = r.closest('.mcq-opt');
      if (lbl) {
        lbl.classList.add('selected');
        var badge = lbl.querySelector('.opt-badge');
        if (badge) badge.style.background = '#059669';
        /* Count it as answered */
        var name = r.name;          /* rdoAnswerN */
        var idx  = parseInt(name.replace('rdoAnswer',''));
        if (!isNaN(idx)) markAnswered(idx, true);
      }
    }
  });

  /* MULTI: mark pre-checked checkboxes' parent labels as .selected */
  document.querySelectorAll('input[type=checkbox][name^=chkAnswer]').forEach(function(cb) {
    if (cb.checked) {
      var lbl = cb.closest('.mcq-opt');
      if (lbl) {
        lbl.classList.add('selected');
        var badge = lbl.querySelector('.opt-badge');
        if (badge) badge.style.background = '#059669';
      }
    }
  });

  /* MULTI: re-sync hidden multiVal field and answered count from checked boxes */
  document.querySelectorAll('input[type=hidden][id^=multiVal_]').forEach(function(hid) {
    var qIdx = parseInt(hid.id.replace('multiVal_', ''));
    if (!isNaN(qIdx) && hid.value !== '') {
      markAnswered(qIdx, true);
      /* Sync toggleMultiOpt internal state by marking checked boxes */
      document.querySelectorAll('input[type=checkbox][name=chkAnswer'+qIdx+'\[\]]:checked').forEach(function(cb) {
        /* already checked in HTML — visual state handled above */
      });
    }
  });

  /* YESNO: restore visual highlight from pre-checked radios */
  document.querySelectorAll('input[type=radio][value=Y]:checked, input[type=radio][value=N]:checked').forEach(function(r) {
    if (!r.name.startsWith('rdoAnswer')) return;
    var parts = r.name.replace('rdoAnswer','').split('_');
    if (parts.length >= 2) {
      var qIdx  = parseInt(parts[0]);
      var sIdx  = parseInt(parts[1]);
      setYNAnswer(qIdx, sIdx, r.value);
    }
  });

  /* MATCH: server already rendered any pre-filled drop targets (and greyed
     out their matching chips) from the saved draft — just feed the progress
     counter so the header's "Answered" tally reflects it on load. */
  document.querySelectorAll('.match-drop[data-filled-opt]').forEach(function(drop) {
    var opt = drop.getAttribute('data-filled-opt');
    if (!opt || opt === '0') return;
    var m = drop.id.match(/^matchDrop_(\d+)_(\d+)$/);
    if (m) markAnswered(parseInt(m[1]), true);
  });
})();

/* ── Auto-save (60 s interval + 3 s after answer change) ────────────────── */
var EXAM_ID      = <?php echo $examId; ?>;
var _saveTimer   = null;
var _saveIndicator = document.getElementById('autoSaveStatus');

function collectAnswers() {
  var answers = {};
  var form    = document.getElementById('frmWriteExam');
  if (!form) return answers;
  var inputs  = form.elements;
  for (var i = 0; i < inputs.length; i++) {
    var el = inputs[i];
    if (!el.name) continue;
    var skip = ['csrf_token','InfoId','starttime','number_of_ques',
                'SubjectId','MarksOutOf','GradeId','PassingMarks'];
    if (skip.indexOf(el.name) !== -1) continue;

    if (el.type === 'radio' && el.checked && el.name.startsWith('rdoAnswer')) {
      /* MCQ/DROPDOWN — name=rdoAnswerN, value=optNum (1-4) */
      var parts = el.name.match(/rdoAnswer(\d+)$/);
      if (parts) answers[_questionIdForIdx(parseInt(parts[1]))] = el.value;
      continue;
    }
    if (el.type === 'hidden' && el.id && el.id.startsWith('multiVal_') && el.value) {
      /* MULTI — hidden field holds "1,3" (comma-separated option numbers) */
      var qIdx = parseInt(el.id.replace('multiVal_',''));
      answers[_questionIdForIdx(qIdx)] = el.value;
      continue;
    }
    if (el.type === 'hidden' && el.id && el.id.startsWith('matchVal_') && el.value) {
      /* MATCH — hidden field holds "2,1,3" (option number per statement position) */
      var qIdxMatch = parseInt(el.id.replace('matchVal_',''));
      answers[_questionIdForIdx(qIdxMatch)] = el.value;
      continue;
    }
    if (el.type === 'hidden' && el.name.startsWith('rdoAnswer') && el.value &&
        el.name !== el.id) {
      /* YESNO accumulated hidden */
      var parts2 = el.name.match(/rdoAnswer(\d+)$/);
      if (parts2) answers[_questionIdForIdx(parseInt(parts2[1]))] = el.value;
    }
  }
  return answers;
}

/* Map question index → QuestionId from hidden QuestionId{n} fields */
function _questionIdForIdx(idx) {
  var hid = document.querySelector('input[name=QuestionId'+idx+']');
  return hid ? hid.value : idx;
}

function doAutoSave() {
  var answers = collectAnswers();
  if (_saveIndicator) { _saveIndicator.textContent = 'Saving…'; _saveIndicator.style.color = '#93c5fd'; }
  fetch('autosave.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ examId: EXAM_ID, action: 'save', answers: answers })
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    // A successful autosave proves the student is actively taking the
    // exam even if they haven't moved the mouse — count it as activity
    // for the session-expiry countdown banner (see includes/header.php).
    if (d.ok && typeof window.__resetSessionCountdown === 'function') {
      window.__resetSessionCountdown();
    }
    if (_saveIndicator) {
      _saveIndicator.textContent = d.ok
        ? ('Draft saved ✓ ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}))
        : 'Save failed';
      _saveIndicator.style.color = d.ok ? '#86efac' : '#fca5a5';
    }
  })
  .catch(function() {
    if (_saveIndicator) { _saveIndicator.textContent = 'Offline — not saved'; _saveIndicator.style.color = '#fca5a5'; }
  });
}

/* ═══════════════════════════════════════════════════════════════════════════
   LOCKDOWN / PROCTORING  (only active when proctor_lock = 1 on this exam)
   ═══════════════════════════════════════════════════════════════════════════
   Behaviour:
     • On exam load: request fullscreen automatically.
     • Any focus loss (alt-tab, another app) or tab-switch triggers a violation.
     • Violation 1 & 2: show a warning overlay — student clicks Resume to continue.
       Exam timer keeps running during the overlay.
     • Violation 3: overlay says the exam is being submitted, form auto-submits.
     • Every violation is logged server-side via proctor-violation.php.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  var PROCTOR_ACTIVE = <?php echo (!empty($exam['proctor_lock'])) ? 'true' : 'false'; ?>;
  if (!PROCTOR_ACTIVE) return;

  var MAX_VIOLATIONS  = 3;
  var violationCount  = 0;
  var overlayVisible  = false;
  var _ignoreBlur     = false;   // set true briefly after programmatic fullscreen changes

  /* ── Inject overlay HTML ──────────────────────────────────────────────── */
  var overlay = document.createElement('div');
  overlay.id  = 'proctorOverlay';
  overlay.innerHTML = [
    '<div id="proctorBox">',
    '  <div id="proctorIcon">&#9888;</div>',
    '  <h2 id="proctorTitle">Focus Lost</h2>',
    '  <p  id="proctorMsg"></p>',
    '  <div id="proctorStrike"></div>',
    '  <button id="proctorResume" onclick="proctorResume()">Resume Exam</button>',
    '</div>'
  ].join('');
  overlay.style.cssText = [
    'display:none',
    'position:fixed',
    'inset:0',
    'background:rgba(15,10,40,0.92)',
    'z-index:99999',
    'align-items:center',
    'justify-content:center',
    'font-family:system-ui,sans-serif'
  ].join(';');
  document.body.appendChild(overlay);

  /* Inline styles for the inner box */
  var style = document.createElement('style');
  style.textContent = [
    '#proctorBox{background:#1e1b4b;border:2px solid #7c3aed;border-radius:16px;',
    'padding:40px 48px;max-width:480px;text-align:center;color:#e0e7ff;}',
    '#proctorIcon{font-size:3.5rem;margin-bottom:8px;}',
    '#proctorTitle{font-size:1.6rem;font-weight:700;color:#f5d0fe;margin:0 0 12px;}',
    '#proctorMsg{font-size:1rem;color:#c4b5fd;line-height:1.6;margin:0 0 16px;}',
    '#proctorStrike{font-size:.85rem;color:#a78bfa;margin-bottom:24px;letter-spacing:.5px;}',
    '#proctorResume{background:#7c3aed;color:#fff;border:none;border-radius:8px;',
    'padding:12px 32px;font-size:1rem;font-weight:600;cursor:pointer;}',
    '#proctorResume:hover{background:#6d28d9;}'
  ].join('');
  document.head.appendChild(style);

  /* ── Request fullscreen on load ───────────────────────────────────────── */
  function enterFullscreen() {
    _ignoreBlur = true;
    var el = document.documentElement;
    var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen || el.msRequestFullscreen;
    if (fn) {
      fn.call(el).catch(function() {}).finally(function() {
        setTimeout(function() { _ignoreBlur = false; }, 800);
      });
    } else {
      setTimeout(function() { _ignoreBlur = false; }, 800);
    }
  }

  /* Delay slightly so page is interactive before we request fullscreen */
  setTimeout(enterFullscreen, 600);

  /* ── Show warning / submit overlay ───────────────────────────────────── */
  function showOverlay(type) {
    if (overlayVisible) return;    // don't stack overlays
    overlayVisible = true;

    var strikes = '';
    for (var i = 0; i < MAX_VIOLATIONS; i++) {
      strikes += '<span style="font-size:1.4rem;margin:0 3px;color:' +
                 (i < violationCount ? '#f87171' : '#4b5563') + ';">&#9632;</span>';
    }
    document.getElementById('proctorStrike').innerHTML = 'Violations: ' + strikes;

    if (type === 'autosubmit') {
      document.getElementById('proctorIcon').textContent  = '🚫';
      document.getElementById('proctorTitle').textContent = 'Exam Terminated';
      document.getElementById('proctorMsg').textContent   =
        'You switched away from the exam 3 times. Your answers are being submitted now.';
      document.getElementById('proctorResume').style.display = 'none';
    } else {
      document.getElementById('proctorIcon').textContent  = '⚠️';
      document.getElementById('proctorTitle').textContent = 'You Left the Exam Window';
      document.getElementById('proctorMsg').innerHTML     =
        'Switching to other applications or tabs is not allowed during a lockdown exam.<br>' +
        '<strong style="color:#fbbf24;">Warning ' + violationCount + ' of ' + MAX_VIOLATIONS + '</strong> — ' +
        'one more violation after this will auto-submit your exam.';
      document.getElementById('proctorResume').style.display = '';
    }

    overlay.style.display = 'flex';
  }

  /* ── Resume button ────────────────────────────────────────────────────── */
  window.proctorResume = function() {
    overlay.style.display = 'none';
    overlayVisible = false;
    enterFullscreen();
  };

  /* ── Log violation to server ──────────────────────────────────────────── */
  function logViolation(vtype) {
    var hdnViolations = document.getElementById('hdnViolations');
    if (hdnViolations) hdnViolations.value = violationCount;

    var csrfInput = document.querySelector('input[name=csrf_token]');
    fetch('proctor-violation.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        examId:       EXAM_ID,
        type:         vtype,
        violationNum: violationCount,
        csrf_token:   csrfInput ? csrfInput.value : ''
      })
    }).catch(function() {});    // fire-and-forget; never block the UI
  }

  /* ── Core violation handler ───────────────────────────────────────────── */
  function handleViolation(vtype) {
    if (overlayVisible) return;
    violationCount++;
    logViolation(vtype);

    if (violationCount >= MAX_VIOLATIONS) {
      showOverlay('autosubmit');
      /* Give the student 2 seconds to read the message, then submit */
      setTimeout(function() {
        document.getElementById('frmWriteExam').submit();
      }, 2000);
    } else {
      showOverlay('warning');
    }
  }

  /* ── Event listeners ──────────────────────────────────────────────────── */

  /* Window blur: fires when the browser window loses focus entirely
     (alt-tab to another app, clicking another window, etc.)             */
  window.addEventListener('blur', function() {
    if (_ignoreBlur) return;
    handleViolation('focus_lost');
  });

  /* Page Visibility API: fires when switching browser tabs             */
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
      handleViolation('tab_switch');
    }
  });

  /* Fullscreen exit: fires when the user presses Escape or otherwise
     leaves fullscreen without using the Resume button                   */
  document.addEventListener('fullscreenchange', function() {
    if (!document.fullscreenElement && !overlayVisible) {
      handleViolation('fullscreen_exit');
    }
  });
  document.addEventListener('webkitfullscreenchange', function() {
    if (!document.webkitFullscreenElement && !overlayVisible) {
      handleViolation('fullscreen_exit');
    }
  });

  /* Block common keyboard shortcuts that bypass the browser
     (these are best-effort; the OS can intercept before the browser)   */
  document.addEventListener('keydown', function(e) {
    /* Alt+Tab, Alt+F4, Win key (Meta), Cmd+Tab (Mac) */
    if ((e.altKey && (e.key === 'Tab' || e.key === 'F4')) ||
         e.key === 'Meta' ||
        (e.metaKey && e.key === 'Tab')) {
      e.preventDefault();
    }
    /* F11 fullscreen toggle — re-enter fullscreen instead */
    if (e.key === 'F11') {
      e.preventDefault();
      enterFullscreen();
    }
  });

  /* Block right-click context menu during lockdown */
  document.addEventListener('contextmenu', function(e) { e.preventDefault(); });

})();
/* ── End proctoring ─────────────────── */

/* ── Autosave timer setup ───────────────────────────────────────────────
   Intervals driven by exam_settings (migration_v18).
   AUTOSAVE_INTERVAL_MS = 0 means periodic timer is disabled for this exam.
───────────────────────────────────────────────────────────────────────────── */
var AUTOSAVE_INTERVAL_MS = <?php echo $autosaveIntervalMs; ?>;
var AUTOSAVE_DEBOUNCE_MS = <?php echo $autosaveDebounceMs; ?>;

if (AUTOSAVE_INTERVAL_MS > 0) {
  _saveTimer = setInterval(doAutoSave, AUTOSAVE_INTERVAL_MS);
}

document.getElementById('frmWriteExam').addEventListener('change', function() {
  clearTimeout(window._chgDebounce);
  window._chgDebounce = setTimeout(doAutoSave, AUTOSAVE_DEBOUNCE_MS);
});

/* Clear draft on submit */
document.getElementById('frmWriteExam').addEventListener('submit', function() {
  try {
    navigator.sendBeacon('autosave.php', JSON.stringify({ examId: EXAM_ID, action: 'clear' }));
  } catch(ex) {}
});

/* ═══════════════════════════════════════════════════════════════════════════
   BEHAVIOUR TRACKING  (always active — logs to exam_events for admin review)
   Complements proctor mode: proctor enforces rules, this just records counts.
   All calls are fire-and-forget and never interrupt the student experience.
   ═══════════════════════════════════════════════════════════════════════════ */
(function behaviorTracker() {
  var LOG_URL = 'log-event.php';

  function logEvent(type) {
    try {
      var payload = JSON.stringify({ examId: EXAM_ID, eventType: type });
      /* sendBeacon is available in all modern browsers and works on page unload */
      if (navigator.sendBeacon) {
        navigator.sendBeacon(LOG_URL, payload);
      } else {
        fetch(LOG_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: payload,
          keepalive: true
        }).catch(function(){});
      }
    } catch(e) {}
  }

  /* Tab switch — Page Visibility API */
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) logEvent('tab_switch');
  });

  /* Copy / Cut on the exam page */
  document.addEventListener('copy', function() { logEvent('copy'); });
  document.addEventListener('cut',  function() { logEvent('copy'); }); // treat cut same as copy

  /* Paste on the exam page */
  document.addEventListener('paste', function() { logEvent('paste'); });

  /* Browser refresh / navigate away */
  window.addEventListener('beforeunload', function() { logEvent('browser_refresh'); });

})();
/* ── End behaviour tracking ─────────────────────────────── */

/* ═══════════════════════════════════════════════════════════════════════════
   LAYOUT TOGGLE — Stacked (default, unchanged) vs. Side-by-side (comparison)
   Purely visual: swaps a CSS class on #examBody. Remembered via localStorage
   so flipping back and forth to compare survives a page reload/timer resume.
   ═══════════════════════════════════════════════════════════════════════════ */
function setExamLayout(mode) {
  var body = document.getElementById('examBody');
  if (!body) return;
  body.classList.toggle('layout-split', mode === 'split');
  var stackedBtn = document.getElementById('layoutStackedBtn');
  var splitBtn   = document.getElementById('layoutSplitBtn');
  if (stackedBtn) stackedBtn.classList.toggle('active', mode !== 'split');
  if (splitBtn)   splitBtn.classList.toggle('active',   mode === 'split');
  try { localStorage.setItem('examLayoutMode_' + <?php echo (int)$examId; ?>, mode); } catch (e) {}
}
(function () {
  var saved = null;
  try { saved = localStorage.getItem('examLayoutMode_' + <?php echo (int)$examId; ?>); } catch (e) {}
  if (saved === 'split') setExamLayout('split');
})();

/* ═══════════════════════════════════════════════════════════════════════════
   ONE-QUESTION-AT-A-TIME NAVIGATOR
   Purely a display layer on top of everything above: it only toggles which
   .q-card (plus its owning section header / case-study panel, via the
   data-section/data-casestudy attributes rendered on each card) has the
   .q-hidden class. All form fields stay in the DOM and submit exactly as
   before — collectAnswers()/autosave/validateExam/proctoring are untouched.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  var qOrder = [];
  document.querySelectorAll('.q-card').forEach(function (card) {
    var m = card.id.match(/^qrow(\d+)$/);
    if (m) qOrder.push(parseInt(m[1], 10));
  });
  if (!qOrder.length) return; // no questions — nothing to navigate

  var curPos     = 0;
  var skippedSet = {};

  function qShow(pos) {
    if (pos < 0) pos = 0;
    if (pos > qOrder.length - 1) pos = qOrder.length - 1;
    curPos = pos;
    var curIdx = qOrder[curPos];

    document.querySelectorAll('.q-card, .exam-section-hdr, .case-study-panel')
      .forEach(function (el) { el.classList.add('q-hidden'); });

    var card = document.getElementById('qrow' + curIdx);
    if (card) {
      card.classList.remove('q-hidden');
      var sec = card.getAttribute('data-section');
      var cs  = card.getAttribute('data-casestudy');
      if (sec) {
        var secEl = document.getElementById('secHdr_' + sec);
        if (secEl) secEl.classList.remove('q-hidden');
      }
      if (cs && cs !== '0') {
        var csEl = document.getElementById('csWrap_' + cs);
        if (csEl) csEl.classList.remove('q-hidden');
      }
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    updateNavUI();
  }

  function updateNavUI() {
    var curIdx = qOrder[curPos];
    document.querySelectorAll('.qnav-count').forEach(function (el) {
      el.textContent = 'Question ' + (curPos + 1) + ' of ' + qOrder.length;
    });
    document.querySelectorAll('.qnav-prev').forEach(function (b) { b.disabled = curPos === 0; });
    document.querySelectorAll('.qnav-next').forEach(function (b) { b.disabled = curPos === qOrder.length - 1; });
    document.querySelectorAll('.qnav-skip').forEach(function (b) {
      b.style.visibility = curPos === qOrder.length - 1 ? 'hidden' : 'visible';
    });
    document.querySelectorAll('.qpalette-btn').forEach(function (btn) {
      var idx = parseInt(btn.getAttribute('data-idx'), 10);
      btn.classList.toggle('qp-current',  idx === curIdx);
      btn.classList.toggle('qp-answered', !!answeredSet[idx]);
      btn.classList.toggle('qp-skipped',  !!skippedSet[idx] && !answeredSet[idx]);
    });
  }

  window.qGoto = function (idx) {
    var pos = qOrder.indexOf(idx);
    if (pos !== -1) qShow(pos);
  };
  window.qPrev = function () { qShow(curPos - 1); };
  window.qNext = function () { qShow(curPos + 1); };
  window.qSkip = function () {
    var curIdx = qOrder[curPos];
    if (!answeredSet[curIdx]) skippedSet[curIdx] = true;
    qShow(curPos + 1);
  };

  /* Wrap markAnswered (declared earlier in this file) so that actually
     answering a question — even one previously marked Skipped — clears its
     skipped flag and refreshes the palette color immediately. */
  var _origMarkAnswered = markAnswered;
  markAnswered = function (idx, isAnswered) {
    _origMarkAnswered(idx, isAnswered);
    if (isAnswered && skippedSet[idx]) delete skippedSet[idx];
    updateNavUI();
  };

  qShow(0);
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
