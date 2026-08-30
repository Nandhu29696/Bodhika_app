<?php
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';
require_once __DIR__ . '/../Lib/Institute.php';
require_once __DIR__ . '/../Lib/ExamType.php';
Auth::requireLogin('../auth/login.php');

$pageTitle  = 'Exam Search';
$isAdmin    = Auth::isAdmin();
$myUid      = (int)Auth::currentUserId();

// Student's registered institute (for scope filtering) — null = no institute
$myInstituteId = $isAdmin ? null : Institute::getInstituteId($myUid);

$grades     = Database::fetchAll("SELECT GradeInfoId, GradeName   FROM gradeinfo   ORDER BY GradeName");
$subjects   = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo ORDER BY SubjectName");
$gradeMap   = array_column($grades,   'GradeName',   'GradeInfoId');
$subjectMap = array_column($subjects, 'SubjectName', 'SubjectInfoId');

/* Languages (migration_v47) — only offered as a filter once more than one
   language is actually in use, so single-language installs see nothing new. */
$languages   = [];
$languageMap = []; // LanguageCode -> LanguageName
try {
    $languages = Database::fetchAll(
        "SELECT LanguageCode, LanguageName, NativeName FROM languages WHERE IsActive = 'Y' ORDER BY SortOrder, LanguageName");
    $languageMap = array_column($languages, 'LanguageName', 'LanguageCode');
} catch (Exception $e) { /* migration_v47 not yet run */ }

/* Groups (education level: Primary/Secondary/UG/PG/...) — an exam's group is
   derived through its Grade (examinfo.GradeInfoId -> gradeinfo.GroupId).
   Gracefully empty if migration_v44 hasn't been run yet. */
try {
    $groups = Database::fetchAll("SELECT GroupId, GroupName FROM groupinfo WHERE Active = 'Y' ORDER BY SortOrder, GroupName");
} catch (Exception $e) {
    $groups = [];
}
$groupMap = array_column($groups, 'GroupName', 'GroupId');
/* GradeInfoId -> GroupName / GroupId, for the results table's group chip and
   for computing which group tabs a student should see below. */
$gradeGroupMap   = []; // GradeInfoId -> GroupName
$gradeGroupIdMap = []; // GradeInfoId -> GroupId
try {
    $ggRows = Database::fetchAll(
        "SELECT gi.GradeInfoId, gi.GroupId, gr.GroupName
           FROM gradeinfo gi LEFT JOIN groupinfo gr ON gr.GroupId = gi.GroupId");
    foreach ($ggRows as $r) {
        $gid = (int)$r['GradeInfoId'];
        $gradeGroupMap[$gid]   = $r['GroupName'];
        $gradeGroupIdMap[$gid] = (int)($r['GroupId'] ?? 0);
    }
} catch (Exception $e) {}

/* ── Parse search filters ─────────────────────────────────────────────────────
   Filters and the group tab are all read from $_GET now (form below is
   method="get"), so every filter combination is a shareable/bookmarkable
   URL and — the reason this changed — so pagination links (?page=2) can
   carry the current filters forward instead of silently dropping them. */
$filterGroup    = (int)($_GET['group']       ?? 0);
$filterGrade    = (int)($_GET['txtGrade']    ?? 0);
$filterSubject  = (int)($_GET['txtSubject']  ?? 0);
$filterChapter  = (int)($_GET['txtChapter']  ?? 0);
$filterName     = trim($_GET['txtExamName']  ?? '');
$filterLanguage = trim($_GET['txtLanguage']  ?? '');
$filterCategory = trim($_GET['txtCategory']  ?? '');
$filterCountry  = trim($_GET['txtCountry']   ?? '');
$filterFee      = trim($_GET['txtFee']       ?? ''); // '', 'free', 'paid'

/* Builds a search.php URL preserving every current filter/tab/page value,
   with the given overrides applied on top — used by the group tabs and
   the pagination controls so neither one clobbers the other. */
function examSearchUrl(array $overrides = []): string {
    $qs = array_merge($_GET, $overrides);
    // Never carry a stale page number when the filters/tab actually change.
    foreach (['group', 'txtGrade', 'txtSubject', 'txtChapter', 'txtExamName', 'txtLanguage', 'txtCategory', 'txtCountry', 'txtFee', 'sort'] as $k) {
        if (array_key_exists($k, $overrides) && !array_key_exists('page', $overrides)) {
            unset($qs['page']);
        }
    }
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return 'search.php' . ($qs ? '?' . http_build_query($qs) : '');
}

/* ── Handle soft delete (admin only) ─────────────────────────────────────── */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_examid'])) {
    Auth::validateCsrf();
    $delId = (int)$_POST['delete_examid'];
    if ($delId > 0) {
        try {
            Database::execute(
                "UPDATE examinfo SET IsDeleted='Y', DeletedAt=NOW(), DeletedBy=? WHERE ExamInfoId=?",
                [Auth::currentUser() ?: 'admin', $delId]
            );
            try {
                $delName = Database::fetchOne("SELECT ExamName FROM examinfo WHERE ExamInfoId=? LIMIT 1", [$delId])['ExamName'] ?? '';
                Database::execute(
                    "INSERT INTO exam_changelog (ExamInfoId,ExamName,Action,ActionBy,Details) VALUES (?,?,?,?,?)",
                    [$delId, $delName, 'DELETE', Auth::currentUser(), 'Soft-deleted from exam list']
                );
            } catch (Exception $eLog) {}
        } catch (Exception $e) { /* migration_v43 not yet run — run it to enable soft delete */ }
    }
    header('Location: search.php'); exit;
}

/* Only filter by language if the column exists (migration_v47) and a
   specific language was actually chosen ('' = All languages). */
$hasLanguageCol = Database::hasColumn('examinfo', 'Language');

/* Question Bank (migration_v65) — a bank exam is a pool other exams get
   built from, never something a student should see as assigned/self-
   enrolled/browsable-to-enroll. Students never see it at all (filtered out
   of both listing paths below); admins see it with a badge instead, plus
   a "Show question banks" toggle, so it doesn't just clutter the normal
   exam list by default. */
$hasQuestionBankCol = Database::hasColumn('examinfo', 'IsQuestionBank');
$showQuestionBanks  = $isAdmin && isset($_GET['showBanks']);

/* Exam type (NEET/JEE/GRE/GMAT/UPSC/custom) — migration_v55, same resilience
   pattern as Language: no filter offered at all if the column isn't there yet. */
$catColAvailable = Database::hasColumn('examinfo', 'ExamCategory');
$examTypes = $catColAvailable ? ExamType::allValues() : [];
/* Country filter (migration_v64) — examinfo.ExamCountry, when an admin has
   explicitly set it, ALWAYS takes priority; exams that only have a Type set
   (or predate migration_v64) still match via the fixed Type->country
   fallback (NEET/JEE/UPSC -> India, GRE/GMAT -> USA) — see
   ExamType::resolveCountry()'s docblock for the full precedence rule. The
   filter dropdown (countryFilterOptions()) only offers a country that would
   actually return something, from either source. */
$countryColAvailable = Database::hasColumn('examinfo', 'ExamCountry');
$countryOptions = ExamType::countryFilterOptions();
/* Precompute once — reused by all three WHERE-builders below (admin /
   assigned / self-enrolled) so the fallback-via-Type resolution can't drift
   between them. */
$countryFallbackTypes = ($filterCountry !== '') ? ExamType::typesForCountry($filterCountry) : [];

/* Builds the Country filter's WHERE fragment for one of the three query
 * paths below (admin / assigned / self-enrolled), appending any needed
 * params to $params by reference so callers just splice the returned
 * fragment into their own $where array. Centralised here so the "explicit
 * ExamCountry wins, fall back to Type only when ExamCountry is blank" rule
 * (ExamType::resolveCountry()) can't drift between the three copies.
 * Returns null when no Country filter is active. $colPrefix is '' or 'e.'
 * depending on which query's column names are being matched. */
function countryWhereFragment(string $colPrefix, bool $countryColAvailable, array $fallbackTypes, string $filterCountry, array &$params): ?string {
    if ($filterCountry === '') return null;
    $countryCol  = $colPrefix . 'ExamCountry';
    $categoryCol = $colPrefix . 'ExamCategory';
    if ($countryColAvailable && $fallbackTypes) {
        $ph = implode(',', array_fill(0, count($fallbackTypes), '?'));
        $params[] = $filterCountry;
        array_push($params, ...$fallbackTypes);
        return "($countryCol = ? OR (($countryCol IS NULL OR TRIM($countryCol) = '') AND $categoryCol IN ($ph)))";
    }
    if ($countryColAvailable) {
        $params[] = $filterCountry;
        return "$countryCol = ?";
    }
    if ($fallbackTypes) {
        $ph = implode(',', array_fill(0, count($fallbackTypes), '?'));
        array_push($params, ...$fallbackTypes);
        return "$categoryCol IN ($ph)";
    }
    return '1=0'; // Country selected but nothing (explicit or Type-derived) can ever match it
}

/* Chapter filter (migration_v49) — chapters belong to a Subject, not
   directly to an Exam; an exam "has" a chapter if any of its linked
   questions (exam_questions -> questions.ChapterInfoId) are tagged with it.
   Grouped by SubjectInfoId here so the filter form below can render one
   <optgroup> per subject and cascade which group is shown as the Subject
   dropdown changes (see filterChaptersBySubject() in the script at the
   bottom of this page). Falls back to no Chapter filter at all if
   migration_v49 hasn't been run yet, same resilience pattern as Language. */
$hasChapterCol = Database::hasColumn('questions', 'ChapterInfoId');
$chapters = [];
if ($hasChapterCol) {
    try {
        $chapters = Database::fetchAll(
            "SELECT ChapterInfoId, SubjectInfoId, ChapterName
               FROM chapterinfo WHERE Active = 'Y'
              ORDER BY SubjectInfoId, ChapterOrder, ChapterName");
    } catch (Exception $e) { $chapters = []; /* migration_v49 not yet run */ }
}
$chaptersBySubject = [];
foreach ($chapters as $c) { $chaptersBySubject[(int)$c['SubjectInfoId']][] = $c; }

/* Fee filter (migration_v51 — examinfo.ExamFee is the only fee source now).
   "Free" mirrors the exact same "$examFee <= 0" rule the row-rendering code
   below already uses to decide access, so what this filter calls Free is
   never out of step with what the list itself shows as Free. */
$hasFeeCol = Database::hasColumn('examinfo', 'ExamFee');

/* ── Sort-by ────────────────────────────────────────────────────────────────
   One whitelist, reused by: the admin query (sorts in SQL), the student
   query (assigned + self-enrolled are two separate queries stitched
   together in PHP below, so they share this same definition via an
   in-memory sort instead of a second ORDER BY), and the dropdown itself —
   so the three can never drift on what a given key means. Every column
   here already exists on examinfo; no new migration needed. There's no
   dedicated "created at" timestamp column, so ExamInfoId (assigned at
   INSERT time, i.e. already a perfect creation-order proxy) doubles as
   "Created" — this is also why the default/blank sort ("Newest first") is
   just an alias for created_desc rather than its own case anywhere below. */
$sortOptions = [
    ''               => ['label' => 'Newest first',     'sql' => 'ExamInfoId DESC',     'field' => 'ExamInfoId',     'dir' => SORT_DESC, 'type' => 'num'],
    'created_asc'    => ['label' => 'Oldest first',      'sql' => 'ExamInfoId ASC',      'field' => 'ExamInfoId',     'dir' => SORT_ASC,  'type' => 'num'],
    'name_asc'       => ['label' => 'Exam Name (A-Z)',   'sql' => 'ExamName ASC',        'field' => 'ExamName',       'dir' => SORT_ASC,  'type' => 'str'],
    'name_desc'      => ['label' => 'Exam Name (Z-A)',   'sql' => 'ExamName DESC',       'field' => 'ExamName',       'dir' => SORT_DESC, 'type' => 'str'],
    'questions_desc' => ['label' => 'Most Questions',    'sql' => 'NumOfQuestions DESC', 'field' => 'NumOfQuestions', 'dir' => SORT_DESC, 'type' => 'num'],
    'questions_asc'  => ['label' => 'Fewest Questions',  'sql' => 'NumOfQuestions ASC',  'field' => 'NumOfQuestions', 'dir' => SORT_ASC,  'type' => 'num'],
    'time_desc'      => ['label' => 'Longest Duration',  'sql' => 'TimeAlloted DESC',    'field' => 'TimeAlloted',    'dir' => SORT_DESC, 'type' => 'num'],
    'time_asc'       => ['label' => 'Shortest Duration', 'sql' => 'TimeAlloted ASC',     'field' => 'TimeAlloted',    'dir' => SORT_ASC,  'type' => 'num'],
];
if ($hasFeeCol) {
    $sortOptions['fee_desc'] = ['label' => 'Fee (High-Low)', 'sql' => 'ExamFee DESC', 'field' => 'ExamFee', 'dir' => SORT_DESC, 'type' => 'num'];
    $sortOptions['fee_asc']  = ['label' => 'Fee (Low-High)', 'sql' => 'ExamFee ASC',  'field' => 'ExamFee', 'dir' => SORT_ASC,  'type' => 'num'];
}
// Whitelist lookup, not a raw value — an unrecognised/tampered ?sort= can never reach SQL.
$filterSort = trim($_GET['sort'] ?? '');
if (!array_key_exists($filterSort, $sortOptions)) { $filterSort = ''; }

/* ── Load exams — admins see all; students see assigned + self-enrolled ──── */
if ($isAdmin) {
    // Admin tabs always show every configured group — admins manage
    // everything, not just what they personally have access to.
    $tabGroups = $groups;

    $where = ["COALESCE(IsDeleted,'N') = 'N'"]; $params = [];
    if ($filterName    !== '') { $where[] = 'ExamName LIKE ?';   $params[] = '%' . $filterName . '%'; }
    if ($filterGroup   > 0) { $where[] = 'GradeInfoId IN (SELECT GradeInfoId FROM gradeinfo WHERE GroupId = ?)'; $params[] = $filterGroup; }
    if ($filterGrade   > 0) { $where[] = 'GradeInfoId = ?';   $params[] = $filterGrade; }
    if ($filterSubject > 0) { $where[] = 'SubjectInfoId = ?'; $params[] = $filterSubject; }
    /* Dedicated view, not an inclusion toggle (migration_v65's intent: banks
       shown "instead of mixed in with real exams") — unchecked = normal
       exams only (default), checked = question banks only. Previously this
       only dropped the exclusion when checked, so a handful of banks got
       mixed into the full exam list instead of isolated, which read as the
       filter doing nothing. */
    if ($hasQuestionBankCol) {
        $where[] = $showQuestionBanks
            ? "COALESCE(IsQuestionBank,'N') = 'Y'"
            : "COALESCE(IsQuestionBank,'N') = 'N'";
    }
    if ($hasLanguageCol && $filterLanguage !== '') { $where[] = 'Language = ?'; $params[] = $filterLanguage; }
    if ($hasChapterCol && $filterChapter > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM exam_questions xeq JOIN questions xq ON xq.QuestionId = xeq.QuestionId
                              WHERE xeq.ExamInfoId = examinfo.ExamInfoId AND xq.ChapterInfoId = ?)';
        $params[] = $filterChapter;
    }
    if ($catColAvailable && $filterCategory !== '') { $where[] = 'ExamCategory = ?'; $params[] = $filterCategory; }
    if (($cf = countryWhereFragment('', $countryColAvailable, $countryFallbackTypes, $filterCountry, $params)) !== null) { $where[] = $cf; }
    if ($hasFeeCol && $filterFee !== '') {
        $where[] = ($filterFee === 'free') ? "COALESCE(ExamFee,0) <= 0" : "COALESCE(ExamFee,0) > 0";
    }
    $orderBySql = $sortOptions[$filterSort]['sql'];
    try {
        $exams = Database::fetchAll(
            'SELECT * FROM examinfo WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBySql,
            $params);
    } catch (Exception $e) {
        // migration_v43 not yet run — IsDeleted column missing, show everything.
        $legacyWhere = array_slice($where, 1);
        $exams = Database::fetchAll(
            'SELECT * FROM examinfo' . ($legacyWhere ? ' WHERE ' . implode(' AND ', $legacyWhere) : '') . ' ORDER BY ' . $orderBySql,
            $params);
    }
} else {
    /* ── 1. Admin-assigned exams ──────────────────────────────────────────── */
    $assignedExams = [];
    $assignedIds   = [];
    $where  = ['ea.UserInfoId = ?', "COALESCE(e.IsActive,'Y') = 'Y'", "COALESCE(e.IsDeleted,'N') = 'N'"];
    $params = [$myUid];
    if ($hasQuestionBankCol) { $where[] = "COALESCE(e.IsQuestionBank,'N') = 'N'"; }
    if ($filterName    !== '') { $where[] = 'e.ExamName LIKE ?';   $params[] = '%' . $filterName . '%'; }
    if ($filterGrade   > 0) { $where[] = 'e.GradeInfoId = ?';   $params[] = $filterGrade; }
    if ($filterSubject > 0) { $where[] = 'e.SubjectInfoId = ?'; $params[] = $filterSubject; }
    if ($hasLanguageCol && $filterLanguage !== '') { $where[] = 'e.Language = ?'; $params[] = $filterLanguage; }
    if ($hasChapterCol && $filterChapter > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM exam_questions xeq JOIN questions xq ON xq.QuestionId = xeq.QuestionId
                              WHERE xeq.ExamInfoId = e.ExamInfoId AND xq.ChapterInfoId = ?)';
        $params[] = $filterChapter;
    }
    if ($catColAvailable && $filterCategory !== '') { $where[] = 'e.ExamCategory = ?'; $params[] = $filterCategory; }
    if (($cf = countryWhereFragment('e.', $countryColAvailable, $countryFallbackTypes, $filterCountry, $params)) !== null) { $where[] = $cf; }
    if ($hasFeeCol && $filterFee !== '') {
        $where[] = ($filterFee === 'free') ? "COALESCE(e.ExamFee,0) <= 0" : "COALESCE(e.ExamFee,0) > 0";
    }
    try {
        $assignedExams = Database::fetchAll(
            "SELECT e.*, ea.AssignmentId, ea.Status AS AssignStatus,
                    ea.DueDate, ea.StudentExamId AS AsgStudentExamId
               FROM examinfo e
               JOIN exam_assignments ea ON ea.ExamInfoId = e.ExamInfoId
              WHERE " . implode(' AND ', $where) . "
              ORDER BY ea.Status ASC, ea.AssignedAt DESC",
            $params);
        $assignedIds = array_column($assignedExams, 'ExamInfoId');
    } catch (Exception $e) {}

    /* Drop assignments that are fully spent — Status='Completed' AND no
       attempts left. "Upcoming Exams" is meant to list exams there's still
       something to do for: not yet attempted, or completed-but-retakeable
       (MaxAttempts > attempts used). A finished, no-attempts-remaining
       assignment isn't upcoming work anymore and was lingering here
       indefinitely; it still shows in full via exam/history.php's
       "Completed"/"Assigned Exams" views, which read exam_assignments
       independently and aren't touched by this filter — this only trims
       what counts as a live to-do item on this page. $assignedIds is left
       as-is (not re-filtered) so the self-enrolled de-dup below still
       treats this exam as "already covered by an assignment" and doesn't
       let it reappear through that path either. */
    $assignedExams = array_values(array_filter($assignedExams, function ($ex) use ($myUid) {
        if (($ex['AssignStatus'] ?? '') !== 'Completed') return true;
        return Enrollment::getAttemptStatus((int)$ex['ExamInfoId'], $myUid)['allowed'];
    }));

    /* ── 2. Self-enrolled exams (migration_v51 — pricing is exam-level only) ──
       A Paid/Waived/Free row in exam_fee_payments for THIS exam specifically
       (there is no more subject-wide enrollment that unlocks every exam under
       a subject — see Lib/Enrollment.php's class docblock). */
    $selfEnrolled = [];
    try {
        $efWhere  = ['efp.UserInfoId = ?',
                     "efp.PaymentStatus IN ('Paid','Waived','Free')",
                     "COALESCE(e.IsActive,'Y') = 'Y'",
                     "COALESCE(e.IsDeleted,'N') = 'N'"];
        $efParams = [$myUid];
        if ($hasQuestionBankCol) { $efWhere[] = "COALESCE(e.IsQuestionBank,'N') = 'N'"; }
        if ($filterName    !== '') { $efWhere[] = 'e.ExamName LIKE ?';   $efParams[] = '%' . $filterName . '%'; }
        if ($filterGrade   > 0) { $efWhere[] = 'e.GradeInfoId = ?';   $efParams[] = $filterGrade; }
        if ($filterSubject > 0) { $efWhere[] = 'e.SubjectInfoId = ?'; $efParams[] = $filterSubject; }
        if ($hasLanguageCol && $filterLanguage !== '') { $efWhere[] = 'e.Language = ?'; $efParams[] = $filterLanguage; }
        if ($hasChapterCol && $filterChapter > 0) {
            $efWhere[] = 'EXISTS (SELECT 1 FROM exam_questions xeq JOIN questions xq ON xq.QuestionId = xeq.QuestionId
                                    WHERE xeq.ExamInfoId = e.ExamInfoId AND xq.ChapterInfoId = ?)';
            $efParams[] = $filterChapter;
        }
        if ($catColAvailable && $filterCategory !== '') { $efWhere[] = 'e.ExamCategory = ?'; $efParams[] = $filterCategory; }
        if (($cf = countryWhereFragment('e.', $countryColAvailable, $countryFallbackTypes, $filterCountry, $efParams)) !== null) { $efWhere[] = $cf; }
        if ($hasFeeCol && $filterFee !== '') {
            $efWhere[] = ($filterFee === 'free') ? "COALESCE(e.ExamFee,0) <= 0" : "COALESCE(e.ExamFee,0) > 0";
        }
        $selfEnrolled = Database::fetchAll(
            "SELECT e.*,
                    NULL  AS AssignmentId,
                    'SelfEnrolled' AS AssignStatus,
                    NULL  AS DueDate,
                    NULL  AS AsgStudentExamId
               FROM examinfo e
               JOIN exam_fee_payments efp ON efp.ExamInfoId = e.ExamInfoId
              WHERE " . implode(' AND ', $efWhere) . "
              ORDER BY e.ExamInfoId DESC",
            $efParams);
        if (!empty($assignedIds)) {
            $selfEnrolled = array_filter($selfEnrolled,
                fn($ex) => !in_array($ex['ExamInfoId'], $assignedIds));
        }
    } catch (Exception $e) { /* migration_v50/v51 not yet run */ }

    /* ── (Removed) "Free exams — visible without any enrollment record" ───────
       Previously, any exam scoped ExamScope='All' whose subject had a ₹0 fee
       was shown here and made instantly accessible to every student with no
       assignment and no action of any kind — effectively auto-assigning it to
       the whole user base. Exams (free or not) now only ever show up here via
       (1) an explicit admin assignment, or (2) an explicit self-enrollment
       record the student actually created (including a ₹0 "Free"
       exam_fee_payments row from clicking Enroll on enroll-exam.php — still
       an explicit action, just a free one). See Lib/Enrollment.php::canAccess(). */

    $exams = array_merge($assignedExams, array_values($selfEnrolled));

    /* Sort-by override: only when the student actually picked one. Left
       alone ($filterSort === '') the curated default order stands —
       pending assignments first (ea.Status ASC, ea.AssignedAt DESC), then
       self-enrolled — which is more useful day-to-day than a flat
       newest-first list. Assigned + self-enrolled are two separate queries
       stitched together above, so this can't be a single ORDER BY the way
       the admin query gets one; same $sortOptions definition either way. */
    if ($filterSort !== '') {
        $sortDef = $sortOptions[$filterSort];
        usort($exams, function ($a, $b) use ($sortDef) {
            $av = $a[$sortDef['field']] ?? ($sortDef['type'] === 'num' ? 0 : '');
            $bv = $b[$sortDef['field']] ?? ($sortDef['type'] === 'num' ? 0 : '');
            $cmp = ($sortDef['type'] === 'num') ? ((float)$av <=> (float)$bv) : strcasecmp((string)$av, (string)$bv);
            return ($sortDef['dir'] === SORT_ASC) ? $cmp : -$cmp;
        });
    }

    /* ── Group tabs: only the groups the student actually has exams in ────────
       Computed from the FULL accessible set (assigned + self-enrolled + free)
       before the group-tab filter below is applied, so every tab a student
       could switch to stays visible while one is selected — not just "All"
       and the currently active tab. */
    $myGroupIds = [];
    foreach ($exams as $ex) {
        $gid = $gradeGroupIdMap[(int)($ex['GradeInfoId'] ?? 0)] ?? 0;
        if ($gid > 0) $myGroupIds[$gid] = true;
    }
    $tabGroups = array_values(array_filter($groups, fn($g) => isset($myGroupIds[(int)$g['GroupId']])));

    /* Now apply the selected tab, if any. */
    if ($filterGroup > 0) {
        $exams = array_values(array_filter($exams, fn($ex) =>
            ($gradeGroupIdMap[(int)($ex['GradeInfoId'] ?? 0)] ?? 0) === $filterGroup
        ));
    }
}

/* ── Pagination (10 exams per page) ──────────────────────────────────────
   This page had NO paging logic before — that's the actual bug: an earlier
   pass added pagination to Admin/ExamSearch.php, a different/legacy screen,
   while this one (the real, in-use Exam List) kept rendering every matching
   row on one page. Applied here, after the full filtered/merged list is
   known (for students that's after the assigned+self-enrolled merge and the
   group-tab filter above), so $totalExams/$totalPages reflect exactly what
   the user searched for. Slicing BEFORE the per-exam enrichment queries
   below (fee status, attempt limits, sibling languages, assignment counts)
   means those only run for the current page's rows, not the full result
   set — real savings once a filter/tab matches hundreds of exams. */
const EXAMS_PER_PAGE = 10;
$totalExams = count($exams);
$totalPages = max(1, (int)ceil($totalExams / EXAMS_PER_PAGE));
$page       = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$exams      = array_slice($exams, ($page - 1) * EXAMS_PER_PAGE, EXAMS_PER_PAGE);

/* ── Load enrollment + fee status for students (migration_v51: exam-level) ── */
$subjectIds = array_values(array_unique(array_column($exams, 'SubjectInfoId')));
$scholarshipUser = false;
if (!$isAdmin && !empty($exams)) {
    try {
        $uRow = Database::fetchOne(
            "SELECT ScholarshipFlag FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$myUid]);
        $scholarshipUser = (($uRow['ScholarshipFlag'] ?? 'N') === 'Y');
    } catch (Exception $e) {}
}

/* ── Per-exam fee payments (migration_v50/v51) — ExamInfoId → row. Every
   exam prices and gates itself now; there is no subject-wide fallback. */
$examPayMap = [];
if (!$isAdmin && !empty($exams)) {
    $examIdsForFee = array_values(array_unique(array_column($exams, 'ExamInfoId')));
    $eph = implode(',', array_fill(0, count($examIdsForFee), '?'));
    try {
        $efRows = Database::fetchAll(
            "SELECT ExamInfoId, PaymentStatus, FeeAtTime, EndDate
               FROM exam_fee_payments
              WHERE UserInfoId = ? AND ExamInfoId IN ($eph)",
            array_merge([$myUid], $examIdsForFee));
        foreach ($efRows as $er) {
            $examPayMap[(int)$er['ExamInfoId']] = $er;
        }
    } catch (Exception $e) { /* migration_v50/v51 not yet run */ }
}

/* ── Institute-level free-subject discount — bulk-loaded once per unique
   subject (not per exam row), mirrors Enrollment::canAccess()'s institute
   check so the list's Access badge / Start-Exam gating never drifts from
   the authoritative server-side gate enforced in write.php.            */
$instituteFreeMap = []; // subjectId => bool
if (!$isAdmin && !empty($exams)) {
    foreach ($subjectIds as $sidLoop) {
        try {
            $instituteFreeMap[$sidLoop] = Institute::isFreeForStudent($myUid, $sidLoop);
        } catch (Exception $e) {
            $instituteFreeMap[$sidLoop] = false;
        }
    }
}

/* ── Attempt limits (migration_v36) — students only ──────────────────────────
   Bulk-loaded (not per-row) for performance: one GROUP BY for attempts used,
   one lookup for per-student overrides. examinfo.MaxAttempts is already
   present on each $exam row since the queries above select e.*.            */
$attemptUsedMap     = []; // ExamInfoId => completed-attempt count
$attemptOverrideMap = []; // ExamInfoId => per-student MaxAttempts override
if (!$isAdmin && !empty($exams)) {
    $examIds = array_values(array_unique(array_column($exams, 'ExamInfoId')));
    $eph     = implode(',', array_fill(0, count($examIds), '?'));
    try {
        $usedRows = Database::fetchAll(
            "SELECT ExamInfoId, COUNT(*) AS c FROM studentexam
              WHERE UserInfoId = ? AND ExamInfoId IN ($eph)
              GROUP BY ExamInfoId",
            array_merge([$myUid], $examIds));
        foreach ($usedRows as $ur) { $attemptUsedMap[(int)$ur['ExamInfoId']] = (int)$ur['c']; }
    } catch (Exception $e) {}
    try {
        $ovRows = Database::fetchAll(
            "SELECT ExamInfoId, MaxAttempts FROM exam_attempt_overrides
              WHERE UserInfoId = ? AND ExamInfoId IN ($eph)",
            array_merge([$myUid], $examIds));
        foreach ($ovRows as $or) { $attemptOverrideMap[(int)$or['ExamInfoId']] = (int)$or['MaxAttempts']; }
    } catch (Exception $e) { /* migration_v36 not yet run — table missing, skip */ }
}

/* ── Sibling-language exams (migration_v47) ──────────────────────────────────
   Bulk-loaded map: TranslationGroupId -> [ {ExamInfoId, Language, ExamName}, ... ]
   used to render "Also available in: HI, MR" links per exam card. Students
   only see siblings that are Active (a translation still under admin review
   stays hidden); admins see every sibling so they can jump straight to it. */
$siblingsByExam = []; // ExamInfoId -> array of sibling rows (excluding itself)
if ($hasLanguageCol && !empty($exams)) {
    $groupIds = array_values(array_unique(array_filter(
        array_column($exams, 'TranslationGroupId')
    )));
    if (!empty($groupIds)) {
        $gph = implode(',', array_fill(0, count($groupIds), '?'));
        $activeClause = $isAdmin ? '' : " AND COALESCE(IsActive,'Y') = 'Y'";
        try {
            $sibRows = Database::fetchAll(
                "SELECT ExamInfoId, ExamName, Language, TranslationGroupId
                   FROM examinfo
                  WHERE TranslationGroupId IN ($gph)
                    AND COALESCE(IsDeleted,'N') = 'N'" . $activeClause,
                $groupIds);
            $byGroup = [];
            foreach ($sibRows as $sr) { $byGroup[(int)$sr['TranslationGroupId']][] = $sr; }
            foreach ($exams as $ex) {
                $gid = (int)($ex['TranslationGroupId'] ?? 0);
                if (!$gid || empty($byGroup[$gid])) continue;
                $siblingsByExam[(int)$ex['ExamInfoId']] = array_values(array_filter(
                    $byGroup[$gid],
                    fn($s) => (int)$s['ExamInfoId'] !== (int)$ex['ExamInfoId']
                ));
            }
        } catch (Exception $e) {}
    }
}

/* ── For admin: load per-exam assignment counts ──────────────────────────── */
$assignCounts = [];
if ($isAdmin) {
    try {
        $rows = Database::fetchAll(
            "SELECT ExamInfoId,
                    SUM(CASE WHEN Status='Assigned' THEN 1 ELSE 0 END)   AS Pending,
                    SUM(CASE WHEN Status='Completed' THEN 1 ELSE 0 END)  AS Done,
                    COUNT(*)                 AS Total
               FROM exam_assignments
              GROUP BY ExamInfoId", []);
        foreach ($rows as $r) $assignCounts[(int)$r['ExamInfoId']] = $r;
    } catch (Exception $e) { /* table not yet created */ }
}

include __DIR__ . '/../includes/header.php';
?>
<style>
/* ── Search page local styles ───────────────────────────── */
.assign-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;
              border-radius:12px;font-size:.78rem;font-weight:700;white-space:nowrap;}
.assign-pending  {background:#ebf8ff;color:#2b6cb0;}
.assign-completed{background:#c6efce;color:#276749;}
.assign-overdue  {background:#fff5f5;color:#c53030;}

/* Combined action + filter bar */
.action-filter-bar{display:flex;align-items:center;gap:12px;
                   flex-wrap:wrap;padding:14px 20px;}
.action-filter-bar .action-group{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.action-filter-bar .filter-group{display:flex;align-items:flex-end;gap:8px;
                                  flex-wrap:wrap;}
.action-filter-bar .filter-group .form-group{margin:0;}
/* Match the Exam Name / Grade / Subject boxes to the Search button's height
   (.btn-sm is padding:6px + min-height:34px) — .form-control's site-wide
   default (padding:10px, no min-height) renders visibly taller otherwise. */
.action-filter-bar .filter-group .form-control{padding:6px 10px;min-height:34px;
                                                font-size:.85rem;}
.action-filter-bar .filter-group select.form-control{min-width:140px;padding-right:32px;}
.action-filter-bar .admin-hint{color:#718096;font-size:.78rem;flex:1 1 100%;
                                padding-top:4px;border-top:1px solid #f1f5f9;margin-top:4px;}

/* Card-header with add-button */
.card-header-row{display:flex;flex-wrap:wrap;align-items:center;
                 justify-content:space-between;gap:8px;}

@media(max-width:768px){
  .action-filter-bar .filter-group{margin-left:0;width:100%;}
}

/* Exam-name cell: show lock icon inline */
.exam-name-cell{display:flex;align-items:center;gap:6px;font-weight:600;}
.lock-icon{font-size:.85rem;color:#6d28d9;flex-shrink:0;}

/* Group chip — always visible under the exam name regardless of viewport,
   so admins can identify an exam's education level even when the Grade/
   Subject columns are hidden on narrow screens. */
.group-chip{display:inline-block;margin-top:3px;padding:1px 8px;border-radius:9px;
            font-size:.7rem;font-weight:700;background:#eef2ff;color:#4338ca;
            white-space:nowrap;}
.group-chip-none{background:#f1f5f9;color:#94a3b8;}

/* Group tabs — quick one-click category switch above the exam list.
   Admin sees every configured group; students only see tabs for groups
   they actually have an exam in (computed server-side per user). */
.group-tabs-card{margin-bottom:16px;padding:0;}
.group-tabs-wrap{display:flex;gap:8px;flex-wrap:wrap;padding:12px 16px;overflow-x:auto;}
.group-tab{display:inline-block;padding:7px 18px;border-radius:20px;font-size:.83rem;
           font-weight:700;color:#4a5568;background:#f1f5f9;text-decoration:none;
           white-space:nowrap;border:1.5px solid transparent;transition:.15s;}
.group-tab:hover{background:#e2e8f0;text-decoration:none;color:#1a365d;}
.group-tab.active{background:#4338ca;border-color:#4338ca;color:#fff;}

/* On narrow screens, collapse secondary columns */
@media(max-width:640px){
  .col-hide-xs{display:none !important;}
  .tbl td,.tbl th{padding:8px 10px;}
}
@media(max-width:480px){
  .col-hide-sm{display:none !important;}
  .admin-bar .admin-bar-hint{display:none;}
}

/* Admin row now has 8 action buttons (Preview/Hist/Edit/Qs/Add Q/Bulk/Assign/Delete).
   The shared .btn-group.nowrap rule only wraps below 640px, which let this row force
   the whole table wider than the viewport on tablet/phone widths between ~640-1024px,
   clipping the Actions column. Wrap earlier, just for this table, instead of relying
   on horizontal scroll. */
@media(max-width:1024px){
  .tbl .btn-group.nowrap{flex-wrap:wrap;}
}
.tbl .btn-group.nowrap form{display:inline-flex;}

/* Student exam list (.tbl-examlist): below 640px only Exam Name / Status /
   Actions remain (see $hideBp above), but a long exam name or the inline
   min-width:155px on the Actions header could still push the table wider
   than the screen and drag the buttons off to the right, needing a
   horizontal scroll just to tap Start/Retake/History. Let the name wrap
   onto multiple lines instead of stretching the column, drop that inline
   min-width (both header and body — inline style has higher specificity
   than a plain class, hence !important), and let the already-stacked
   (.btn-group.stack-mobile) action buttons fill the cell so every button
   is reachable without scrolling — column-mode flex items already stretch
   to the container's full width by default (no explicit align-items
   override needed). */
@media(max-width:640px){
  .tbl-examlist .exam-name-cell{flex-wrap:wrap;word-break:break-word;}
  .tbl-examlist th:last-child,.tbl-examlist td:last-child{min-width:0 !important;}
}

/* Type-to-filter (searchable) select — progressive enhancement, see script
   at the bottom of this page. The real <select> (name=txtGrade/txtSubject/
   txtLanguage) stays in the DOM and keeps driving form submission; only its
   presentation is replaced by the text input + dropdown below. */
.ss-wrap{position:relative;min-width:140px;}
.ss-wrap .ss-native{position:absolute;opacity:0;width:1px;height:1px;
                     padding:0;margin:0;border:0;pointer-events:none;}
.ss-input{cursor:text;background:#fff;}
/* position/left/top/width are set inline by JS (fixed, viewport-relative)
   so the menu escapes the filter bar's .card (overflow:hidden) and always
   renders above the rest of the page, including the group tabs below it. */
.ss-menu{position:fixed;z-index:2000;
         max-height:260px;overflow-y:auto;background:#fff;border:1px solid #d1d5db;
         border-radius:8px;box-shadow:0 8px 24px rgba(15,23,42,.18);padding:4px;}
.ss-option{padding:6px 10px;border-radius:6px;font-size:.85rem;cursor:pointer;color:#1f2937;}
.ss-option:hover,.ss-option.ss-active{background:#eef2ff;color:#3730a3;}
.ss-option-selected{font-weight:700;}
.ss-empty{padding:6px 10px;font-size:.82rem;color:#94a3b8;font-style:italic;}
.ss-wrap.ss-open .ss-input{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15);}
</style>

<?php if (isset($_GET['enrolled'])): ?>
<div class="alert alert-success">&#10004; You are now enrolled! You can start the exam below.</div>
<?php endif; ?>

<!-- Combined admin actions + filter bar (single card, full-width) -->
<div class="card" style="margin-bottom:16px;">
  <form method="get" action="">
    <div class="action-filter-bar">

      <!-- Left: action buttons (admin only) or page label (student) -->
      <div class="action-group">
        <?php if ($isAdmin): ?>
          <strong style="color:#1a365d;font-size:.88rem;white-space:nowrap;">&#9881; Admin:</strong>
          <a href="manage.php?InfoId=0"
             class="btn btn-success btn-sm">&#10010; Add Exam</a>
          <a href="questions-hub.php"
             class="btn btn-sm" style="background:#6b46c1;color:#fff;">&#10067; Questions</a>
          <a href="log.php"
             class="btn btn-secondary btn-sm">&#128203; Log</a>
          <a href="../Admin/ExamResults.php"
             class="btn btn-sm" style="background:#0891b2;color:#fff;">&#128202; Results</a>
          <a href="../Admin/ManageTeachers.php"
             class="btn btn-sm" style="background:#7c3aed;color:#fff;">&#127891; Teachers</a>
          <a href="trash.php"
             class="btn btn-sm" style="background:#64748b;color:#fff;" title="Restore deleted exams / questions">&#128465; Trash</a>
          <a href="groups.php"
             class="btn btn-sm" style="background:#334155;color:#fff;" title="Manage education-level groups (Primary/Secondary/UG/PG...)">&#127959; Groups</a>
        <?php else: ?>
          <span style="font-weight:700;color:#1a365d;font-size:.95rem;">&#128196; Exams</span>
          <span style="color:var(--clr-text-muted);font-size:.82rem;">
            <?php echo $totalExams; ?> available
          </span>
        <?php endif; ?>
      </div>

      <!-- Right: filter controls (always shown) -->
      <div class="filter-group">
        <div class="form-group">
          <label for="txtExamName" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Exam Name</label>
          <input type="text" id="txtExamName" name="txtExamName" class="form-control"
                 placeholder="Search by name…" style="min-width:160px;"
                 value="<?php echo htmlspecialchars($filterName); ?>">
        </div>
        <?php if ($isAdmin && $hasQuestionBankCol): ?>
        <div class="form-group" style="align-self:flex-end;">
          <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:#6b7280;cursor:pointer;white-space:nowrap;">
            <input type="checkbox" name="showBanks" value="1" onchange="this.form.submit()"
                   <?php echo $showQuestionBanks ? 'checked' : ''; ?>>
            &#128218; Question banks only
          </label>
        </div>
        <?php endif; ?>
        <!-- Group is chosen via the tabs above the exam list, not this form —
             carry the active tab through so it survives a Name/Grade/Subject search. -->
        <input type="hidden" name="group" value="<?php echo (int)$filterGroup; ?>">
        <div class="form-group">
          <label for="txtGrade" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Grade</label>
          <select id="txtGrade" name="txtGrade" class="form-control js-searchable-select" data-placeholder="All Grades">
            <option value="0">— All Grades —</option>
            <?php foreach ($grades as $g): ?>
              <option value="<?php echo (int)$g['GradeInfoId']; ?>"
                <?php echo ($filterGrade===(int)$g['GradeInfoId'])?'selected':''; ?>>
                <?php echo htmlspecialchars($g['GradeName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="txtSubject" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Subject</label>
          <select id="txtSubject" name="txtSubject" class="form-control js-searchable-select" data-placeholder="All Subjects" onchange="filterChaptersBySubject()">
            <option value="0">— All Subjects —</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo (int)$s['SubjectInfoId']; ?>"
                <?php echo ($filterSubject===(int)$s['SubjectInfoId'])?'selected':''; ?>>
                <?php echo htmlspecialchars($s['SubjectName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!empty($examTypes)): ?>
        <div class="form-group">
          <label for="txtCategory" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Type</label>
          <select id="txtCategory" name="txtCategory" class="form-control js-searchable-select" data-placeholder="All Types">
            <option value="">— All Types —</option>
            <?php foreach ($examTypes as $t): ?>
              <option value="<?php echo htmlspecialchars($t); ?>"
                <?php echo ($filterCategory === $t) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($t); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if (!empty($countryOptions)): ?>
        <div class="form-group">
          <label for="txtCountry" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Country</label>
          <select id="txtCountry" name="txtCountry" class="form-control js-searchable-select" data-placeholder="All Countries">
            <option value="">— All Countries —</option>
            <?php foreach ($countryOptions as $c): ?>
              <option value="<?php echo htmlspecialchars($c); ?>"
                <?php echo ($filterCountry === $c) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($c); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if ($hasFeeCol): ?>
        <div class="form-group">
          <label for="txtFee" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Fee</label>
          <select id="txtFee" name="txtFee" class="form-control js-searchable-select" data-placeholder="Paid &amp; Free">
            <option value="">— Paid &amp; Free —</option>
            <option value="free" <?php echo ($filterFee === 'free') ? 'selected' : ''; ?>>Free only</option>
            <option value="paid" <?php echo ($filterFee === 'paid') ? 'selected' : ''; ?>>Paid only</option>
          </select>
        </div>
        <?php endif; ?>
        <?php if (!empty($chapters)): ?>
        <div class="form-group">
          <label for="txtChapter" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Chapter</label>
          <select id="txtChapter" name="txtChapter" class="form-control" onchange="filterChaptersBySubject()">
            <option value="0">— All Chapters —</option>
            <?php foreach ($chaptersBySubject as $sid => $chList): ?>
              <optgroup label="<?php echo htmlspecialchars($subjectMap[$sid] ?? ('Subject #' . $sid)); ?>" data-subject="<?php echo (int)$sid; ?>">
                <?php foreach ($chList as $c): ?>
                  <option value="<?php echo (int)$c['ChapterInfoId']; ?>"
                    <?php echo ($filterChapter === (int)$c['ChapterInfoId']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['ChapterName']); ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if (!empty($languages)): ?>
        <div class="form-group">
          <label for="txtLanguage" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Language</label>
          <select id="txtLanguage" name="txtLanguage" class="form-control js-searchable-select" data-placeholder="All Languages">
            <option value="">— All Languages —</option>
            <?php foreach ($languages as $l): ?>
              <option value="<?php echo htmlspecialchars($l['LanguageCode']); ?>"
                <?php echo ($filterLanguage === $l['LanguageCode']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($l['LanguageName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label for="sort" style="font-size:.78rem;color:#6b7280;margin-bottom:3px;display:block;">Sort By</label>
          <select id="sort" name="sort" class="form-control js-searchable-select" data-placeholder="Newest first">
            <?php foreach ($sortOptions as $sk => $sv): ?>
              <option value="<?php echo htmlspecialchars($sk); ?>"
                <?php echo ($filterSort === $sk) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($sv['label']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:6px;align-self:flex-end;">
          <button type="submit" name="Search" class="btn btn-primary btn-sm">&#128269; Search</button>
          <a href="search.php" class="btn btn-secondary btn-sm">&#10005;</a>
        </div>
      </div>

      <?php if ($isAdmin): ?>
      <div class="admin-hint">
        &#128161; Use <strong>Assign</strong> per row to assign exams to students.
        &nbsp;|&nbsp; Use <strong>Bulk</strong> to upload questions from Excel.
      </div>
      <?php endif; ?>

    </div>
  </form>
</div>

<script>
// Type-to-filter enhancement for the Grade/Subject/Language filter selects.
// Scoped to this page only. Each <select class="js-searchable-select"> is
// wrapped with a text input + dropdown; the original <select> stays in the
// DOM (visually hidden, still focusable/submittable) and remains the single
// source of truth, so the existing $_GET['txtGrade']/['txtSubject']/
// ['txtLanguage'] handling on this page needs no changes.
(() => {
  const enhance = (select) => {
    if (select.dataset.ssEnhanced) return;
    select.dataset.ssEnhanced = '1';

    const wrap = document.createElement('div');
    wrap.className = 'ss-wrap';
    select.parentNode.insertBefore(wrap, select);

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control ss-input';
    input.autocomplete = 'off';
    input.placeholder = select.dataset.placeholder ? `Search ${select.dataset.placeholder}…` : 'Type to search…';

    const menu = document.createElement('div');
    menu.className = 'ss-menu';
    menu.hidden = true;
    // Appended to <body> (not wrap) and positioned with fixed coordinates so
    // it always renders above everything — the filter bar sits inside a
    // .card, and .card clips overflow (rounded corners), which was cutting
    // the dropdown off / letting later page content paint over it.
    document.body.appendChild(menu);

    wrap.append(input, select);
    select.classList.add('ss-native');
    select.tabIndex = -1; // input is the real focus target; select stays submittable

    const options = Array.from(select.options).map((o) => ({
      value: o.value,
      label: o.textContent.trim(),
    }));

    let visible = [];
    let activeIndex = -1;

    const currentLabel = () => select.options[select.selectedIndex]?.textContent.trim() ?? '';
    const syncInputFromSelect = () => { input.value = currentLabel(); };

    const highlight = (i) => {
      menu.querySelectorAll('.ss-option').forEach((el) => el.classList.remove('ss-active'));
      const items = menu.querySelectorAll('.ss-option');
      if (i >= 0 && i < items.length) {
        items[i].classList.add('ss-active');
        items[i].scrollIntoView({ block: 'nearest' });
      }
      activeIndex = i;
    };

    const choose = (opt) => {
      select.value = opt.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      input.value = opt.label;
      closeMenu();
    };

    const renderMenu = (filterText) => {
      const q = (filterText || '').trim().toLowerCase();
      visible = options.filter((o) => !q || o.label.toLowerCase().startsWith(q));
      menu.innerHTML = '';
      activeIndex = -1;
      if (!visible.length) {
        const empty = document.createElement('div');
        empty.className = 'ss-empty';
        empty.textContent = 'No matches';
        menu.append(empty);
        return;
      }
      visible.forEach((o) => {
        const item = document.createElement('div');
        item.className = 'ss-option' + (o.value === select.value ? ' ss-option-selected' : '');
        item.textContent = o.label;
        item.addEventListener('mousedown', (e) => { e.preventDefault(); choose(o); });
        menu.append(item);
      });
    };

    const positionMenu = () => {
      const r = input.getBoundingClientRect();
      menu.style.position = 'fixed';
      menu.style.left = `${r.left}px`;
      menu.style.top = `${r.bottom + 4}px`;
      menu.style.width = `${r.width}px`;
    };
    const onViewportChange = () => { if (!menu.hidden) positionMenu(); };

    const openMenu = (filterText = '') => {
      renderMenu(filterText);
      positionMenu();
      menu.hidden = false;
      wrap.classList.add('ss-open');
      window.addEventListener('scroll', onViewportChange, true);
      window.addEventListener('resize', onViewportChange);
    };

    const closeMenu = () => {
      menu.hidden = true;
      wrap.classList.remove('ss-open');
      activeIndex = -1;
      window.removeEventListener('scroll', onViewportChange, true);
      window.removeEventListener('resize', onViewportChange);
    };

    input.addEventListener('focus', () => openMenu());
    input.addEventListener('input', () => openMenu(input.value));
    input.addEventListener('keydown', (e) => {
      if (menu.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { openMenu(); return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); highlight(Math.min(activeIndex + 1, visible.length - 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(activeIndex - 1, 0)); }
      else if (e.key === 'Enter') { if (!menu.hidden && visible[activeIndex]) { e.preventDefault(); choose(visible[activeIndex]); } }
      else if (e.key === 'Escape') { closeMenu(); syncInputFromSelect(); }
    });
    input.addEventListener('blur', () => {
      setTimeout(() => {
        closeMenu();
        if (input.value !== currentLabel()) syncInputFromSelect();
      }, 120);
    });
    select.addEventListener('focus', () => input.focus());

    syncInputFromSelect();
  };

  document.querySelectorAll('select.js-searchable-select').forEach(enhance);
})();

// Chapter <select> is grouped into one <optgroup> per subject (server-
// rendered). Cascades with the Subject filter above: only the matching
// subject's optgroup stays visible, and a previously-chosen chapter that no
// longer matches the newly-selected subject is cleared back to "All
// Chapters" rather than silently submitting a stale, hidden selection.
function filterChaptersBySubject() {
  const subjSel = document.getElementById('txtSubject');
  const chapSel = document.getElementById('txtChapter');
  if (!subjSel || !chapSel) return;
  const subjId = subjSel.value;
  let resetNeeded = false;
  chapSel.querySelectorAll('optgroup').forEach((og) => {
    const match = (subjId === '0' || og.dataset.subject === subjId);
    og.hidden = !match;
    if (!match) {
      og.querySelectorAll('option').forEach((opt) => { if (opt.selected) resetNeeded = true; });
    }
  });
  if (resetNeeded) chapSel.value = '0';
}
filterChaptersBySubject();
</script>

<!-- Group tabs — students only see tabs for groups they actually have exams in -->
<?php if (!empty($tabGroups)): ?>
<div class="card group-tabs-card">
  <div class="group-tabs-wrap">
    <a href="<?php echo htmlspecialchars(examSearchUrl(['group' => 0])); ?>" class="group-tab<?php echo $filterGroup === 0 ? ' active' : ''; ?>">
      All<?php echo $isAdmin ? ' Groups' : ''; ?>
    </a>
    <?php foreach ($tabGroups as $gr): ?>
      <a href="<?php echo htmlspecialchars(examSearchUrl(['group' => (int)$gr['GroupId']])); ?>"
         class="group-tab<?php echo $filterGroup === (int)$gr['GroupId'] ? ' active' : ''; ?>">
        <?php echo htmlspecialchars($gr['GroupName']); ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Exam list card -->
<div class="card">
  <div class="card-header">
    <div class="card-header-row">
      <span>&#128196; Examination List
        <span style="display:inline-block;font-size:.82rem;font-weight:700;color:#1a365d;background:#fff;
                     border-radius:12px;padding:2px 10px;margin-left:8px;vertical-align:middle;">
          <?php echo $totalExams; ?> exam<?php echo $totalExams!==1?'s':''; ?><?php if ($totalPages > 1): ?>
          &nbsp;&middot;&nbsp;page <?php echo $page; ?> of <?php echo $totalPages; ?>
          <?php endif; ?>
        </span>
      </span>
      <?php if ($isAdmin):
        // Carries the current filters over to whichever view the admin switches to.
        $viewToggleQs = array_filter($_GET, fn($v) => $v !== '' && $v !== null);
        $viewToggleQs = $viewToggleQs ? '?' . http_build_query($viewToggleQs) : '';
      ?>
      <span style="display:inline-flex;border:1px solid var(--clr-border-2);border-radius:8px;overflow:hidden;">
        <a href="search.php<?php echo $viewToggleQs; ?>"
           style="padding:6px 12px;font-size:.78rem;font-weight:700;text-decoration:none;color:#fff;background:var(--clr-btn-accent);">&#9776; List View</a>
        <a href="search-grid.php<?php echo $viewToggleQs; ?>"
           style="padding:6px 12px;font-size:.78rem;font-weight:700;text-decoration:none;color:var(--clr-text-muted);background:#fff;">&#9638; Grid View</a>
      </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body" style="padding:0;">
    <?php if (empty($exams)): ?>
      <p style="padding:24px;color:#718096;text-align:center;">
        <?php if ($isAdmin): ?>
          No exams found. <a href="manage.php?InfoId=0">Add one now</a>.
        <?php else: ?>
          You have no exams assigned yet.
          <a href="browse-subjects.php" style="font-weight:700;">Browse &amp; enroll in exams &rarr;</a>
        <?php endif; ?>
      </p>
    <?php else: ?>
    <?php
      /* On the student view, "Lang"/"Qs"/"Pass%" (shared with admin) used to
         only hide below 480px (col-hide-sm) while Grade/Subject/Time already
         hid below 640px (col-hide-xs). Between 480-640px that left up to 7
         columns visible at once — Exam Name, Lang, Qs, Pass%, Status, Access,
         Attempts, Actions — which forced the table wider than the viewport
         and pushed the Actions buttons off-screen, requiring a horizontal
         scroll just to tap Start/Retake/History. Dropping those shared
         columns to the same col-hide-xs breakpoint FOR STUDENTS ONLY (admin
         keeps col-hide-sm — its own Actions cell is already handled
         separately via the 1024px btn-group.nowrap wrap rule below) means
         only Exam Name, Status, and Actions remain below 640px, which fits
         on every phone width without scrolling. */
      $hideBp = $isAdmin ? 'col-hide-sm' : 'col-hide-xs';
    ?>
    <div class="tbl-wrap">
    <table class="tbl<?php echo $isAdmin ? '' : ' tbl-examlist'; ?>">
      <thead>
        <tr>
          <th>Exam Name</th>
          <th class="col-hide-xs">Grade</th>
          <th class="col-hide-xs">Subject</th>
          <?php if (!empty($languages)): ?><th class="text-center <?php echo $hideBp; ?>" style="width:64px;">Lang</th><?php endif; ?>
          <th class="text-center <?php echo $hideBp; ?>" style="width:72px;">Qs</th>
          <th class="text-center <?php echo $hideBp; ?>" style="width:72px;">Pass%</th>
          <th class="text-center col-hide-xs" style="width:72px;">Time</th>
          <?php if ($isAdmin): ?>
            <th class="text-center col-hide-xs" style="width:80px;">Assigned</th>
          <?php else: ?>
            <th class="text-center" style="width:88px;">Status</th>
            <th class="text-center col-hide-xs" style="width:96px;">Due</th>
            <th class="text-center col-hide-xs" style="width:80px;">Access</th>
            <th class="text-center col-hide-xs" style="width:80px;">Attempts</th>
          <?php endif; ?>
          <th style="min-width:<?php echo $isAdmin ? '350px' : '155px'; ?>;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($exams as $i => $exam):
          $eid          = (int)$exam['ExamInfoId'];
          $counts       = $assignCounts[$eid] ?? null;
          $assignStatus = $exam['AssignStatus'] ?? null;
          $dueDate      = $exam['DueDate']      ?? null;
          $asgSeId      = (int)($exam['AsgStudentExamId'] ?? 0);
          $isOverdue    = ($assignStatus === 'Assigned' && $dueDate && $dueDate < date('Y-m-d'));
          $isLocked     = !empty($exam['proctor_lock']);

          /* migration_v51: pricing is exam-level only. ExamFee/ExamDiscountPct
             come for free on $exam (the exams query selects e.* / SELECT *);
             ?? fallbacks cover an environment where that migration hasn't
             run yet. */
          $sid          = (int)$exam['SubjectInfoId'];
          $examFee      = (float)($exam['ExamFee'] ?? 0);
          $examDisc     = (float)($exam['ExamDiscountPct'] ?? 0);
          $discFee      = $examDisc > 0
              ? max(0, $examFee - round($examFee * $examDisc / 100, 2))
              : $examFee;

          /* Exam-level free-for-all/institute override (highest priority —
             same precedence as Enrollment::canAccess()). */
          $examFreeFor    = $exam['ExamFreeFor'] ?? 'None';
          $examInstituteId = (int)($exam['ExamInstituteId'] ?? 0);
          $freeByExam     = ($examFreeFor === 'All')
              || ($examFreeFor === 'Institute' && $examInstituteId > 0 && $examInstituteId === (int)$myInstituteId);
          $freeByInstitute = $instituteFreeMap[$sid] ?? false;

          /* Explicit admin assignment (exam_assignments) always grants access
             regardless of fee — mirrors Enrollment::canAccess()'s assignment
             short-circuit. 'SelfEnrolled' is the sentinel used for the
             self-enrolled query above, so it does NOT count as an assignment. */
          $isAssigned = ($assignStatus !== null && $assignStatus !== 'SelfEnrolled');

          $examPayRow    = $examPayMap[$eid] ?? null;
          $examPayStatus = $examPayRow['PaymentStatus'] ?? '';
          /* A Paid/Waived/Free payment only counts if it hasn't expired —
             Enrollment::canAccess() enforces the same EndDate check, so a
             lapsed enrollment must show Locked here too, not a stale "Paid". */
          $examPaymentActive = in_array($examPayStatus, ['Paid', 'Waived', 'Free'], true)
              && (empty($examPayRow['EndDate']) || $examPayRow['EndDate'] >= date('Y-m-d'));

          /* NOTE: "$examFee <= 0" IS enough on its own here — unlike a fee
             that's merely unset/inherited, this is a real value an admin set
             (or left at the ₹0 backfilled default) on THIS exam's own Edit
             page, i.e. an explicit per-exam decision, not an invisible
             fallback. See Lib/Enrollment.php::canAccess() for the same rule. */
          $isEnrolled   = $isAdmin
              || $isAssigned
              || $freeByExam
              || $scholarshipUser
              || $freeByInstitute
              || $examFee <= 0
              || $examPaymentActive;
          $isPending    = (!$isEnrolled && $examPayStatus === 'Pending');
          $enrollHref   = 'enroll-exam.php?examId=' . $eid . '&from=search';

          /* Attempt limit (migration_v36): student override > exam.MaxAttempts > 5 */
          $attemptsMax     = $attemptOverrideMap[$eid] ?? (isset($exam['MaxAttempts']) ? (int)$exam['MaxAttempts'] : 5);
          $attemptsUsed    = $attemptUsedMap[$eid] ?? 0;
          $attemptsUnlimited = ($attemptsMax <= 0);
          $attemptsAllowed   = $attemptsUnlimited || $attemptsUsed < $attemptsMax;
        ?>
        <tr class="<?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">

          <!-- Exam name + optional lockdown badge + group chip -->
          <td>
            <div class="exam-name-cell">
              <?php if ($isLocked): ?>
                <span class="lock-icon" title="Lockdown mode: fullscreen enforced">&#128274;</span>
              <?php endif; ?>
              <?php echo htmlspecialchars($exam['ExamName']); ?>
            </div>
            <?php $examGroup = $gradeGroupMap[$exam['GradeInfoId']] ?? null; ?>
            <?php if ($examGroup): ?>
              <span class="group-chip"><?php echo htmlspecialchars($examGroup); ?></span>
            <?php elseif (!empty($groups)): ?>
              <span class="group-chip group-chip-none" title="Assign a group to this exam's grade on the Grades page">Uncategorized</span>
            <?php endif; ?>
            <?php if ($catColAvailable && !empty($exam['ExamCategory'])):
              $rowCountry = ExamType::resolveCountry($exam);
              $badgeTitle = $exam['ExamCategory'] . ($rowCountry !== '' ? ' — ' . $rowCountry : '');
              $badgeFlag  = ExamType::resolveFlagIconHtml($exam); // '' for India — see Lib/ExamType.php::SUPPRESS_FLAG_FOR
            ?>
              <span class="group-chip" style="background:#eef2ff;color:#3730a3;"
                    title="<?php echo htmlspecialchars($badgeTitle); ?>">
                <?php echo $badgeFlag !== '' ? $badgeFlag . ' ' : ''; ?><?php echo htmlspecialchars($exam['ExamCategory']); ?>
              </span>
            <?php endif; ?>
            <?php if ($isAdmin && ($exam['IsQuestionBank'] ?? 'N') === 'Y'): ?>
              <span class="group-chip" style="background:#fef3c7;color:#92400e;"
                    title="Pool of questions other exams get built from — not directly attemptable, assignable, or self-enrollable.">
                &#128218; Question Bank
              </span>
            <?php endif; ?>
          </td>

          <td class="col-hide-xs"><?php echo htmlspecialchars($gradeMap[$exam['GradeInfoId']] ?? '—'); ?></td>
          <td class="col-hide-xs"><?php echo htmlspecialchars($subjectMap[$exam['SubjectInfoId']] ?? '—'); ?></td>
          <?php if (!empty($languages)):
            $examLang = $exam['Language'] ?? 'en';
            $siblings = $siblingsByExam[$eid] ?? [];
          ?>
          <td class="text-center <?php echo $hideBp; ?>">
            <span class="group-chip" title="<?php echo htmlspecialchars($languageMap[$examLang] ?? $examLang); ?>">
              <?php echo htmlspecialchars(strtoupper($examLang)); ?>
            </span>
            <?php if (!empty($siblings)): ?>
              <div style="margin-top:3px;font-size:.7rem;white-space:nowrap;">
                <?php foreach ($siblings as $sib):
                  $sibHref = $isAdmin ? 'manage.php?InfoId=' . (int)$sib['ExamInfoId']
                                      : 'write.php?InfoId=' . (int)$sib['ExamInfoId'];
                ?>
                  <a href="<?php echo $sibHref; ?>" title="<?php echo htmlspecialchars($sib['ExamName']); ?>"
                     style="color:#4338ca;font-weight:700;"><?php echo htmlspecialchars(strtoupper($sib['Language'])); ?></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td class="text-center <?php echo $hideBp; ?>"><?php echo (int)$exam['NumOfQuestions']; ?></td>
          <td class="text-center <?php echo $hideBp; ?>"><?php echo min(100, max(0, (int)$exam['MinPassing'])); ?>%</td>
          <td class="text-center col-hide-xs"><?php echo htmlspecialchars($exam['TimeAlloted'] ?? '—'); ?></td>

          <?php if ($isAdmin): ?>
          <td class="text-center col-hide-xs">
            <?php if ($counts && $counts['Total'] > 0): ?>
              <span title="<?php echo (int)$counts['Pending']; ?> pending / <?php echo (int)$counts['Done']; ?> done"
                    style="font-size:.8rem;font-weight:700;color:#2b6cb0;">
                &#128101;&nbsp;<?php echo (int)$counts['Total']; ?>
              </span>
            <?php else: ?>
              <span style="font-size:.78rem;color:#a0aec0;">—</span>
            <?php endif; ?>
          </td>

          <?php else: ?>
          <td class="text-center">
            <?php if ($assignStatus === 'SelfEnrolled'): ?>
              <span class="assign-badge" style="background:#ede9fe;color:#6d28d9;">&#128218; Self</span>
            <?php elseif ($assignStatus === 'Completed'): ?>
              <span class="assign-badge assign-completed">&#10004; Done</span>
            <?php elseif ($isOverdue): ?>
              <span class="assign-badge assign-overdue">&#9888; Overdue</span>
            <?php else: ?>
              <span class="assign-badge assign-pending">&#9679; Pending</span>
            <?php endif; ?>
          </td>

          <td class="text-center col-hide-xs"
              style="font-size:.82rem;color:<?php echo $isOverdue ? '#c53030' : '#718096'; ?>;">
            <?php echo $dueDate ? date('d M y', strtotime($dueDate)) : '—'; ?>
          </td>

          <td class="text-center col-hide-xs">
            <?php if ($isAssigned): ?>
              <span class="assign-badge" style="background:#ede9fe;color:#6d28d9;">&#128101; Assigned</span>
            <?php elseif ($examFee <= 0 || $scholarshipUser || $freeByExam || $freeByInstitute): ?>
              <span class="assign-badge" style="background:#e0f2fe;color:#0369a1;">&#127995; Free</span>
            <?php elseif ($isEnrolled): ?>
              <span class="assign-badge assign-completed">&#10003; Paid</span>
            <?php elseif ($isPending): ?>
              <span class="assign-badge" style="background:#fef9c3;color:#92400e;">&#9203; Pending</span>
            <?php else: ?>
              <span class="assign-badge" style="background:#fee2e2;color:#b91c1c;">&#128274; Locked</span>
            <?php endif; ?>
          </td>

          <td class="text-center col-hide-xs">
            <?php if ($attemptsUnlimited): ?>
              <span style="font-size:.78rem;color:#718096;">&#8734; Unlimited</span>
            <?php else: ?>
              <span style="font-size:.8rem;font-weight:700;color:<?php echo $attemptsAllowed ? '#2b6cb0' : '#c53030'; ?>;"
                    title="<?php echo $attemptsUsed; ?> of <?php echo $attemptsMax; ?> attempts used">
                <?php echo $attemptsUsed; ?>/<?php echo $attemptsMax; ?>
              </span>
            <?php endif; ?>
          </td>
          <?php endif; ?>

          <!-- Action buttons -->
          <td style="white-space:nowrap;">
            <div class="btn-group <?php echo $isAdmin ? 'nowrap' : 'stack-mobile'; ?>">
              <?php if ($isAdmin): ?>
                <a href="write.php?InfoId=<?php echo $eid; ?>"    class="btn btn-primary btn-xs"   title="Preview exam">&#9998; Preview</a>
                <a href="history.php?InfoId=<?php echo $eid; ?>"  class="btn btn-secondary btn-xs" title="Attempt history">&#128200; Hist</a>
                <a href="manage.php?InfoId=<?php echo $eid; ?>"   class="btn btn-warning btn-xs"   title="Edit exam">&#9881; Edit</a>
                <a href="questions.php?examId=<?php echo $eid; ?>"
                   class="btn btn-xs" style="background:#6b46c1;color:#fff;font-weight:700;" title="View questions">&#10067; Qs</a>
                <a href="question-edit.php?examId=<?php echo $eid; ?>"
                   class="btn btn-success btn-xs" title="Add a question">&#10010; Add Q</a>
                <a href="../Admin/BulkUploadQuestions.php?examId=<?php echo $eid; ?>"
                   class="btn btn-xs" style="background:#7c3aed;color:#fff;font-weight:700;"
                   title="Bulk upload questions from Excel / CSV">&#11014; Bulk</a>
                <a href="assign.php?examId=<?php echo $eid; ?>"
                   class="btn btn-xs" style="background:#1d4ed8;color:#fff;font-weight:700;" title="Assign to students">&#128101; Assign</a>
                <?php if (!empty($languages)): ?>
                <a href="../Admin/TranslateExam.php?examId=<?php echo $eid; ?>"
                   class="btn btn-xs" style="background:#0d9488;color:#fff;font-weight:700;" title="Save as a different language">&#127760; Translate</a>
                <?php endif; ?>
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Delete exam &quot;<?php echo addslashes(htmlspecialchars($exam['ExamName'])); ?>&quot;?\n\nIt will be hidden everywhere but can be restored from Trash.');">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                  <input type="hidden" name="delete_examid" value="<?php echo $eid; ?>">
                  <button type="submit" class="btn btn-xs" style="background:#dc2626;color:#fff;font-weight:700;" title="Soft-delete this exam">&#128465; Delete</button>
                </form>

              <?php elseif ($assignStatus === 'Completed'): ?>
                <?php if ($asgSeId): ?>
                  <a href="result.php?id=<?php echo $asgSeId; ?>" class="btn btn-secondary btn-xs">&#128200; Result</a>
                <?php endif; ?>
                <a href="history.php?InfoId=<?php echo $eid; ?>" class="btn btn-secondary btn-xs">&#128200; History</a>
                <?php if ($attemptsAllowed): ?>
                  <!-- Completed doesn't mean "done forever" — this exam allows
                       more attempts than the student has used, so retaking it
                       stays available right alongside the completed result,
                       instead of the row looking like a dead end. -->
                  <a href="write.php?InfoId=<?php echo $eid; ?>"
                     class="btn btn-primary btn-xs" style="font-weight:700;"
                     title="<?php echo $attemptsUnlimited ? 'Unlimited attempts' : ($attemptsMax - $attemptsUsed) . ' attempt(s) remaining'; ?>">
                    &#9998; Retake
                    <?php if (!$attemptsUnlimited): ?>
                      (<?php echo $attemptsUsed; ?>/<?php echo $attemptsMax; ?>)
                    <?php endif; ?>
                  </a>
                <?php endif; ?>

              <?php elseif (!$isEnrolled && $examFee > 0): ?>
                <?php if ($isPending): ?>
                  <a href="<?php echo htmlspecialchars($enrollHref); ?>"
                     class="btn btn-xs" style="background:#1d4ed8;color:#fff;font-weight:700;">
                    &#9203; Complete Payment
                  </a>
                <?php else: ?>
                  <a href="<?php echo htmlspecialchars($enrollHref); ?>"
                     class="btn btn-xs" style="background:#dc2626;color:#fff;font-weight:700;">
                    &#128274; Enroll &#8377;<?php echo number_format($discFee, 2); ?>
                  </a>
                <?php endif; ?>
                <a href="history.php?InfoId=<?php echo $eid; ?>" class="btn btn-secondary btn-xs">&#128200; History</a>

              <?php elseif (!$isEnrolled && $examFee <= 0): ?>
                <!-- Free but not yet self-enrolled: still requires an explicit
                     click (creates a ₹0 enrollment record) — no more silent
                     auto-access for free exams. -->
                <a href="<?php echo htmlspecialchars($enrollHref); ?>"
                   class="btn btn-xs" style="background:#059669;color:#fff;font-weight:700;">
                  &#9998; Enroll (Free)
                </a>
                <a href="history.php?InfoId=<?php echo $eid; ?>" class="btn btn-secondary btn-xs">&#128200; History</a>

              <?php elseif (!$attemptsAllowed): ?>
                <span class="btn btn-xs" style="background:#fee2e2;color:#b91c1c;font-weight:700;cursor:not-allowed;"
                      title="Attempt limit reached (<?php echo $attemptsUsed; ?>/<?php echo $attemptsMax; ?>)">
                  &#128683; Limit Reached
                </span>
                <a href="history.php?InfoId=<?php echo $eid; ?>" class="btn btn-secondary btn-xs">&#128200; History</a>

              <?php else: ?>
                <a href="write.php?InfoId=<?php echo $eid; ?>"
                   class="btn btn-primary btn-xs" style="font-weight:700;">&#9998; Start Exam</a>
                <a href="history.php?InfoId=<?php echo $eid; ?>" class="btn btn-secondary btn-xs">&#128200; History</a>
              <?php endif; ?>
            </div>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div><!-- /.tbl-wrap -->

    <?php if ($totalPages > 1):
      $winStart = max(1, $page - 2);
      $winEnd   = min($totalPages, $winStart + 4);
      $winStart = max(1, min($winStart, $winEnd - 4));
    ?>
    <div style="display:flex;align-items:center;justify-content:center;gap:4px;
                flex-wrap:wrap;padding:14px;border-top:1px solid #f1f5f9;">
      <a href="<?php echo htmlspecialchars(examSearchUrl(['page' => 1])); ?>"
         class="btn btn-secondary btn-sm"<?php echo $page<=1?' style="pointer-events:none;opacity:.5;"':''; ?>>&laquo; First</a>
      <a href="<?php echo htmlspecialchars(examSearchUrl(['page' => max(1,$page-1)])); ?>"
         class="btn btn-secondary btn-sm"<?php echo $page<=1?' style="pointer-events:none;opacity:.5;"':''; ?>>&lsaquo; Prev</a>
      <?php for ($p = $winStart; $p <= $winEnd; $p++): ?>
        <a href="<?php echo htmlspecialchars(examSearchUrl(['page' => $p])); ?>"
           class="btn btn-sm"
           style="<?php echo $p===$page ? 'background:#4338ca;color:#fff;' : 'background:#f1f5f9;color:#334155;'; ?>min-width:34px;text-align:center;">
          <?php echo $p; ?>
        </a>
      <?php endfor; ?>
      <a href="<?php echo htmlspecialchars(examSearchUrl(['page' => min($totalPages,$page+1)])); ?>"
         class="btn btn-secondary btn-sm"<?php echo $page>=$totalPages?' style="pointer-events:none;opacity:.5;"':''; ?>>Next &rsaquo;</a>
      <a href="<?php echo htmlspecialchars(examSearchUrl(['page' => $totalPages])); ?>"
         class="btn btn-secondary btn-sm"<?php echo $page>=$totalPages?' style="pointer-events:none;opacity:.5;"':''; ?>>Last &raquo;</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
