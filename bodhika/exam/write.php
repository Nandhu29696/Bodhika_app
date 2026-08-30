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

/* ── Question Bank hard gate (migration_v65) ──────────────────────────────
   A Question Bank exam (IsQuestionBank='Y') is a pool other exams get
   built from — potentially hundreds/thousands of questions — never a live
   test itself. Blocked for EVERYONE here, admins included: there is no
   legitimate reason to "write" one, and ExamEngine::pickQuestions() below
   has no idea it should behave differently for a pool this large. Checked
   before the enrollment gate so a stray exam_assignments/self-enrollment
   row against a bank exam (which should never exist, but might on an
   older database) can't route around this. Falls open (does nothing) on a
   database that hasn't run migration_v65 yet. */
try {
    if (Database::hasColumn('examinfo', 'IsQuestionBank')) {
        $bankRow = Database::fetchOne(
            "SELECT ExamName, IsQuestionBank FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]);
        if ($bankRow && $bankRow['IsQuestionBank'] === 'Y') {
            $pageTitle = 'Question Bank';
            include __DIR__ . '/../includes/header.php';
            ?>
            <div class="page-wrap">
              <div class="card" style="max-width:560px;margin:40px auto;">
                <div class="card-header">&#128218; This is a Question Bank</div>
                <div style="padding:24px;">
                  <div class="alert alert-warning" style="margin-bottom:16px;">
                    <strong><?php echo htmlspecialchars($bankRow['ExamName']); ?></strong> is a question
                    pool, not a live exam — it isn't meant to be attempted directly.
                  </div>
                  <?php if ($isAdmin): ?>
                  <p style="margin-bottom:16px;color:var(--clr-text-muted);">
                    Use <a href="question-bank-builder.php?examId=<?php echo (int)$examId; ?>">Build from Question Bank</a>
                    to pull a chosen number of questions from it into a real exam instead.
                  </p>
                  <?php else: ?>
                  <p style="margin-bottom:16px;color:var(--clr-text-muted);">
                    If you were expecting a real exam here, contact your administrator.
                  </p>
                  <?php endif; ?>
                  <a class="btn btn-primary" href="search.php">Back to Exams</a>
                </div>
              </div>
            </div>
            <?php
            include __DIR__ . '/../includes/footer.php';
            exit;
        }
    }
} catch (Exception $e) { /* migration_v65 not yet run — fall through, unaffected */ }

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

/* ── Subject grouping/tabs (generalized) ──────────────────────────────────
   The sectioned draw above (migration_v54, $isMultiSubject) already tags
   every question with _SectionId/_SectionLabel and already lands them in
   contiguous per-section blocks. But an exam can also end up spanning more
   than one subject WITHOUT ever going through that Exam Pattern — most
   notably one assembled via exam/question-bank-builder.php, which links
   rows straight into exam_questions across whichever subjects/chapters the
   admin picked, with no exam_sections rows at all. This block generalizes
   the same _SectionId/_SectionLabel tagging (and the same "group into
   contiguous blocks" treatment) to that flat draw path too, purely by
   looking at questions.SubjectInfoId — so the render loop and the subject
   tabs UI further below never need to know which path produced $questions,
   and a normal single-subject exam (the overwhelming majority) is left
   completely untouched. */
if (!$isMultiSubject && $questions) {
    $qids = array_column($questions, 'QuestionId');
    $subjByQid = [];
    try {
        $ph = implode(',', array_fill(0, count($qids), '?'));
        $subjRows = Database::fetchAll(
            "SELECT q.QuestionId, q.SubjectInfoId, s.SubjectName
               FROM questions q
          LEFT JOIN subjectinfo s ON s.SubjectInfoId = q.SubjectInfoId
              WHERE q.QuestionId IN ($ph)", $qids);
        foreach ($subjRows as $sr) {
            $subjByQid[(int)$sr['QuestionId']] = [
                'id'    => (int)($sr['SubjectInfoId'] ?? 0),
                'label' => $sr['SubjectName'] ?? '',
            ];
        }
    } catch (Exception $e) { $subjByQid = []; }

    $fallbackLabel = $exam['SubjectName'] ?? 'General';
    foreach ($questions as &$q) {
        $info = $subjByQid[(int)$q['QuestionId']] ?? ['id' => 0, 'label' => ''];
        $q['_SectionId']    = $info['id'];
        $q['_SectionLabel'] = $info['label'] !== '' ? $info['label'] : $fallbackLabel;
    }
    unset($q);

    // Only reorder into subject blocks when the drawn pool actually spans
    // more than one subject — otherwise $questions stays byte-for-byte in
    // its original (random / case-study-grouped) order. Bucketing by
    // first-seen subject (rather than usort) keeps every existing ordering
    // decision made above — including case-study contiguity — intact
    // within each bucket.
    if (count(array_unique(array_column($questions, '_SectionId'))) > 1) {
        $bySubject = []; $subjectOrder = [];
        foreach ($questions as $q) {
            $sid = $q['_SectionId'];
            if (!isset($bySubject[$sid])) { $bySubject[$sid] = []; $subjectOrder[] = $sid; }
            $bySubject[$sid][] = $q;
        }
        $questions = [];
        foreach ($subjectOrder as $sid) {
            foreach ($bySubject[$sid] as $q) { $questions[] = $q; }
        }
    }
}

/* Whether to show subject tabs / section headers at all — true for both a
   configured multi-subject Exam Pattern with 2+ sections, and the
   generalized flat-pool case just above once it detects 2+ real subjects. */
$hasSubjectSections = count(array_unique(array_column($questions, '_SectionId'))) > 1;

/* Ordered list of {id, label, startIdx (0-based), count} — one entry per
   distinct subject/section in final display order — drives the subject
   tabs UI rendered above the question palette further down. */
$subjectTabs = [];
if ($hasSubjectSections) {
    $tabCounts = array_count_values(array_map(
        fn($q) => (string)($q['_SectionId'] ?? ''), $questions));
    $seenKeys = [];
    foreach ($questions as $pi => $q) {
        $key = (string)($q['_SectionId'] ?? '');
        if (isset($seenKeys[$key])) { continue; }
        $seenKeys[$key] = true;
        $subjectTabs[] = [
            'id'       => $q['_SectionId'] ?? null,
            'label'    => $q['_SectionLabel'] ?? 'Subject',
            'startIdx' => $pi,
            'count'    => $tabCounts[$key],
        ];
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
        /* Lazy-loaded: every question's markup is rendered into the page up
           front (the one-at-a-time navigator only toggles a CSS class), so a
           real src= here would make the browser eagerly fetch every
           question's image on page load — for an image-heavy exam that's
           dozens of downloads for images the student may never even scroll
           to. data-src + write.js's resolveLazyImages() (called from qShow())
           swaps the real src in only when a question is actually revealed. */
        echo '<div class="q-img-wrap">'
           . '<img data-src="' . htmlspecialchars($imgSrc) . '" alt="" class="q-img" loading="lazy">'
           . '</div>';
    }
}

$pageTitle = 'Write Exam: ' . ($exam['ExamName'] ?? '');
/* CSS/JS for this page used to be inlined here (~53KB combined: a <style>
   block plus one giant <script> block) and re-sent raw on every single page
   load, never cached even across repeat visits to the same exam.
   Externalized to assets/write.css / assets/write.js; ?v= is each file's
   own mtime, so any future edit to either busts client caches automatically
   without anyone needing to remember to bump a version by hand. */
$pageHead = '<link rel="stylesheet" href="../' . asset_version('assets/write.css') . '">';
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
          <?php /* MinPassing is stored as a 0-100 percent; clamp defensively like
                   exam/submit.php:297 and Lib/ExamEngine.php do when scoring, so a
                   legacy/misconfigured value (e.g. imported as raw marks) can never
                   render as an impossible ">100%" or negative pass requirement. */
                $displayPassPct = min(100, max(0, (int)($exam['MinPassing'] ?? 0))); ?>
          <div class="ei-item-desc">You need <strong><?php echo $displayPassPct; ?>%</strong> or higher to pass.</div>
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
      <div><span>Subject:</span> <?php echo $hasSubjectSections
        ? htmlspecialchars(implode(' + ', array_map(fn($st) => $st['label'], $subjectTabs)))
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
      <div><span>Pass Mark:</span> <?php echo min(100, max(0, (int)$exam['MinPassing'])); ?>%</div>
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
        <span>Answered: <strong id="answeredCount" class="js-answered-count" style="color:#276749;">0</strong> / <?php echo count($questions); ?></strong></span>
        <div class="progress-wrap" style="width:150px;">
          <div class="progress-fill js-progress-fill" id="progressBar" style="width:0%"></div>
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
        <?php if ($hasSubjectSections): ?>
        <!-- ── Subject tabs ──────────────────────────────────────────────
             Only rendered when the drawn pool actually spans more than one
             subject (a configured multi-subject Exam Pattern, or an exam
             built via question-bank-builder.php across several subjects) —
             an ordinary single-subject exam never shows this row. Clicking
             a tab filters the palette below to that subject's numbers and
             jumps straight to its first question; "All" clears the filter
             without moving the current question. -->
        <div class="qsubject-tabs" id="qSubjectTabs">
          <button type="button" class="qsubject-tab active" data-section="all"
                  onclick="qFilterSubject('all', this)">
            All <span class="qsubject-tab-count">(<?php echo count($questions); ?>)</span>
          </button>
          <?php
          /* Light per-subject tab colour, cycled by position — purely visual,
             so each subject is easy to tell apart at a glance. Set as CSS
             custom properties (read by .qsubject-tab in write.css) rather
             than a fixed class per subject, since the number/order of
             subjects varies per exam. "All" is left uncoloured on purpose —
             it keeps the existing indigo look. */
          $qTabPalette = [
              ['bg' => '#fff7ed', 'border' => '#fb923c', 'text' => '#c2410c'], // amber
              ['bg' => '#ecfdf5', 'border' => '#34d399', 'text' => '#047857'], // green
              ['bg' => '#eff6ff', 'border' => '#60a5fa', 'text' => '#1d4ed8'], // blue
              ['bg' => '#fdf2f8', 'border' => '#f472b6', 'text' => '#be185d'], // pink
              ['bg' => '#fefce8', 'border' => '#facc15', 'text' => '#a16207'], // yellow
              ['bg' => '#f5f3ff', 'border' => '#a78bfa', 'text' => '#6d28d9'], // purple
          ];
          foreach ($subjectTabs as $sti => $st):
              $qc = $qTabPalette[$sti % count($qTabPalette)];
          ?>
          <button type="button" class="qsubject-tab" data-section="<?php echo htmlspecialchars((string)$st['id']); ?>"
                  data-start="<?php echo (int)$st['startIdx'] + 1; ?>"
                  style="--tab-bg:<?php echo $qc['bg']; ?>;--tab-border:<?php echo $qc['border']; ?>;--tab-text:<?php echo $qc['text']; ?>;"
                  onclick="qFilterSubject('<?php echo htmlspecialchars((string)$st['id'], ENT_QUOTES); ?>', this)">
            <?php echo htmlspecialchars($st['label']); ?> <span class="qsubject-tab-count">(<?php echo (int)$st['count']; ?>)</span>
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="qpalette-legend">
          <b><i style="background:#312e81;border-color:#312e81;"></i> Current</b>
          <b><i style="background:#ecfdf5;border-color:#059669;"></i> Answered</b>
          <b><i style="background:#fffbeb;border-color:#f59e0b;"></i> Skipped</b>
          <b><i style="background:#fff;border-color:#cbd5e1;"></i> Not visited</b>
        </div>
        <div class="qpalette" id="qPalette">
          <?php foreach ($questions as $pi => $pq): $pIdx = $pi + 1; ?>
            <button type="button" class="qpalette-btn" data-idx="<?php echo $pIdx; ?>"
                    data-section="<?php echo htmlspecialchars((string)($pq['_SectionId'] ?? '')); ?>"
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
      <?php if ($hasSubjectSections && $curSectionId !== null && $curSectionId !== $lastSectionId): ?>
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
                <img data-src="<?php echo htmlspecialchars($imgSrc); ?>" loading="lazy"
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
              <img data-src="<?php echo htmlspecialchars(resolveImgPath($q['ImageLoc'])); ?>" alt="" class="q-img" loading="lazy">
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
                <img data-src="<?php echo htmlspecialchars($imgSrc); ?>" loading="lazy"
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

      <!-- Inline (not externally-fetched) safety net: every question is
           rendered into the page above; write.js is what actually hides all
           but the current one via the .q-hidden class (write.css:13). That
           means the one-question-at-a-time view depends entirely on the
           external assets/write.js file loading and running successfully —
           if it's ever blocked, errors out, or a browser is stuck on a
           long-gone cached copy from before this feature existed, a student
           sees every question dumped on one page instead. This tiny inline
           script runs immediately, in-document, with no separate network
           request of its own, so it can't be affected by any JS caching/
           loading problem — it hides everything after the first question
           right away. If assets/write.js then loads normally a moment
           later, its own qShow(0) call re-does the identical hiding via the
           same .q-hidden class, so this is a no-op in the normal case and
           only ever matters as the fallback. -->
      <script>
      (function () {
        var scope = document.getElementById('qbodyGrid');
        if (!scope) return;
        var firstQ = scope.querySelector('.q-card');
        if (!firstQ) return; // no questions — nothing to hide
        var reachedFirst = false;
        scope.querySelectorAll('.q-card, .exam-section-hdr, .case-study-panel').forEach(function (el) {
          if (el === firstQ) { reachedFirst = true; return; }
          if (!reachedFirst) return; // section header / case-study panel belonging to Q1 — leave visible
          el.classList.add('q-hidden');
        });
      })();
      </script>

      <!-- Bottom nav bar -- mirrors the sticky Prev/Next/Skip bar above the
           question so it's never necessary to scroll back up just to move
           on. Same classes as the top bar (qnav-count/qnav-prev/qnav-next/
           qnav-skip), so write.js's existing querySelectorAll-based
           updateNavUI() keeps both bars in sync automatically. -->
      <div class="qnav-bar qnav-bar-bottom">
        <div class="qnav-bottom-progress">
          <span>Answered: <strong class="js-answered-count" style="color:#276749;">0</strong> / <?php echo count($questions); ?></span>
          <div class="progress-wrap" style="width:120px;">
            <div class="progress-fill js-progress-fill" style="width:0%"></div>
          </div>
        </div>
        <div class="qnav-side qnav-actions">
          <span class="qnav-count"></span>
          <button type="button" class="qnav-skip" onclick="qSkip()">&#8942; Skip for now</button>
          <button type="button" class="qnav-box qnav-prev" onclick="qPrev()" title="Previous question">
            <span class="qnav-ic">&#8249;</span> Previous
          </button>
          <button type="button" class="qnav-box qnav-next" onclick="qNext()" title="Next question">
            Next <span class="qnav-ic">&#8250;</span>
          </button>
        </div>
      </div>

      <div style="text-align:center;padding:20px 0 8px;">
        <button type="submit" class="btn btn-success" style="padding:12px 40px;font-size:1rem;">
          &#10003; Submit Exam
        </button>
      </div>
    </div><!-- /padding -->
  </div><!-- /card -->
</form>

<script>
/* Per-page dynamic values write.js needs (kept inline, ~7 lines — these
   change per exam/attempt so can't be cached). The actual logic that reads
   them lives in the external, cacheable assets/write.js below, which used
   to be inlined here as a ~37KB <script> block re-sent raw on every load. */
var EXAM_CONFIG = {
  examId:             <?php echo (int)$examId; ?>,
  totalQuestions:     <?php echo (int)count($questions); ?>,
  timeLeftSeconds:    <?php echo (int)($timeMinutes * 60); ?>,
  proctorActive:      <?php echo (!empty($exam['proctor_lock'])) ? 'true' : 'false'; ?>,
  autosaveIntervalMs: <?php echo (int)$autosaveIntervalMs; ?>,
  autosaveDebounceMs: <?php echo (int)$autosaveDebounceMs; ?>
};
</script>
<script src="../<?php echo asset_version('assets/write.js'); ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
