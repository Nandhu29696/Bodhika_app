<?php
/**
 * exam/history.php — Exam attempt history list.
 * Students see their own attempts; admins can view any exam's history.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Enrollment.php';
Auth::requireLogin('../auth/login.php');

$pageTitle  = 'Exam History';
$isAdmin    = Auth::isAdmin();
$myUid      = Auth::currentUserId();
$filterExam = filter_input(INPUT_GET, 'InfoId', FILTER_VALIDATE_INT);
$filterUser = $isAdmin ? filter_input(INPUT_GET, 'UserInfoId', FILTER_VALIDATE_INT) : null;

/* ── Admin: assign a new exam to this student, right from this page ─────
   Only reachable when an admin has drilled into one specific student
   (same scope as the "Assigned Exams" tab itself — see $showAssignedTab
   below). Writes to exam_assignments (migration_v5), the same table
   exam/assign.php uses, so an assignment made here shows up there too and
   vice versa. Institute-Admins are scoped to their own institute's exams
   and students, mirroring exam/assign.php's guards. Handled here, before
   header.php is included below, so header()-redirect is still possible. */
$isFullAdmin       = $isAdmin;
$isInstAdmin        = Auth::isInstituteAdmin();
$canAssignFromHere  = ($isFullAdmin || $isInstAdmin) && $filterUser;

if ($canAssignFromHere && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_exam_id'])) {
    Auth::validateCsrf();

    $targetUid = $filterUser; // always the student this page is already scoped to
    $newExamId = (int)$_POST['assign_exam_id'];
    $dueDate   = trim($_POST['assign_due_date'] ?? '') ?: null;
    $myInstId  = ($isInstAdmin && !$isFullAdmin) ? Auth::currentInstituteId() : null;

    $examRow = $newExamId ? Database::fetchOne(
        "SELECT ExamInfoId, ExamName, ExamInstituteId, IsQuestionBank
           FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$newExamId]) : null;

    if (!$examRow) {
        $flashMsg = 'error|Please choose an exam.';
    } elseif (($examRow['IsQuestionBank'] ?? 'N') === 'Y') {
        $flashMsg = 'error|That exam is a question bank and can\'t be assigned directly.';
    } elseif ($myInstId && (int)($examRow['ExamInstituteId'] ?? 0) !== $myInstId) {
        $flashMsg = 'error|That exam does not belong to your institute.';
    } elseif ($myInstId && (int)(Database::fetchOne(
                "SELECT InstituteId FROM userinfo WHERE UserInfoId = ? LIMIT 1", [$targetUid]
              )['InstituteId'] ?? 0) !== $myInstId) {
        $flashMsg = 'error|That student does not belong to your institute.';
    } else {
        $existing = Database::fetchOne(
            "SELECT AssignmentId, Status FROM exam_assignments
              WHERE ExamInfoId=? AND UserInfoId=? LIMIT 1", [$newExamId, $targetUid]);
        if ($existing && $existing['Status'] !== 'Completed') {
            $flashMsg = 'error|' . $examRow['ExamName'] . ' is already assigned to this student.';
        } elseif ($existing) {
            // Re-open a completed assignment for a fresh attempt.
            Database::execute(
                "UPDATE exam_assignments
                    SET Status='Assigned', AssignedBy=?, AssignedAt=NOW(), DueDate=?, StudentExamId=NULL
                  WHERE AssignmentId=?",
                [Auth::currentLoginId(), $dueDate, $existing['AssignmentId']]);
            $flashMsg = 'success|Re-assigned ' . $examRow['ExamName'] . '.';
        } else {
            Database::execute(
                "INSERT INTO exam_assignments (ExamInfoId,UserInfoId,AssignedBy,DueDate)
                 VALUES (?,?,?,?)",
                [$newExamId, $targetUid, Auth::currentLoginId(), $dueDate]);
            $flashMsg = 'success|Assigned ' . $examRow['ExamName'] . '.';
        }
    }

    header('Location: history.php?UserInfoId=' . $targetUid . '&filter=assigned&flash=' . urlencode($flashMsg));
    exit;
}

/* includes/sidebar.php links its "Completed" nav item to
   history.php?filter=completed, expecting a completed-only view — but
   this parameter was never actually read anywhere on this page, so that
   link just landed on whichever tab happened to be active (restored from
   sessionStorage), including the "Assigned Exams" tab, which deliberately
   shows BOTH completed and still-open assignments together. That's the
   reported bug: the "Completed" section showing exams that are still
   assigned. "My Attempts" (panel-attempts, built from the studentexam
   table below) is the tab that's actually scoped to real, finished
   attempts only, so that's what ?filter=completed should open. */
$statusFilter = $_GET['filter'] ?? '';
$initialTab   = $statusFilter === 'completed' ? 'attempts'
               : ($statusFilter === 'assigned' ? 'assigned' : 'chart');

$examCols = "e.ExamName, e.NumOfQuestions, e.MinPassing, e.MaxAttempts, g.GradeName, s.SubjectName";
$joins    = "LEFT JOIN examinfo    e ON e.ExamInfoId   = se.ExamInfoId
             LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
             LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId";

/* ── Load rows ──────────────────────────────────────────────────────── */
if ($isAdmin && $filterUser) {
    // Admin viewing one specific student's full history (optionally scoped to one exam)
    $extraWhere = $filterExam ? ' AND se.ExamInfoId = ?' : '';
    $params     = $filterExam ? [$filterUser, $filterExam] : [$filterUser];
    $rows = Database::fetchAll(
        "SELECT se.*, $examCols,
                u.FstName, u.LstName, u.LoginName AS StudentLogin
           FROM studentexam se
                $joins
      LEFT JOIN userinfo u ON u.UserInfoId = se.UserInfoId
          WHERE se.UserInfoId = ?" . $extraWhere . "
          ORDER BY se.StudentExamId DESC",
        $params);
} elseif ($isAdmin && $filterExam) {
    // Admin viewing one exam: order by score DESC so we can rank
    $rows = Database::fetchAll(
        "SELECT se.*, $examCols,
                u.FstName, u.LstName, u.LoginName AS StudentLogin
           FROM studentexam se
                $joins
      LEFT JOIN userinfo u ON u.UserInfoId = se.UserInfoId
          WHERE se.ExamInfoId = ?
          ORDER BY (se.Score / NULLIF(se.MarksOutOf,0)) DESC,
                   se.TimeTaken ASC, se.StudentExamId DESC",
        [$filterExam]);
} elseif ($isAdmin && !$filterExam) {
    $rows = Database::fetchAll(
        "SELECT se.*, $examCols,
                u.FstName, u.LstName, u.LoginName AS StudentLogin
           FROM studentexam se
                $joins
      LEFT JOIN userinfo u ON u.UserInfoId = se.UserInfoId
          ORDER BY se.ExamInfoId, (se.Score / NULLIF(se.MarksOutOf,0)) DESC,
                   se.TimeTaken ASC, se.StudentExamId DESC
          LIMIT 500",
        []);
} else {
    $extraWhere = $filterExam ? ' AND se.ExamInfoId = ?' : '';
    $params     = $filterExam ? [$myUid, $filterExam] : [$myUid];
    $rows = Database::fetchAll(
        "SELECT se.*, $examCols
           FROM studentexam se
                $joins
          WHERE se.UserInfoId = ?" . $extraWhere . "
          ORDER BY se.StudentExamId DESC",
        $params);
}

/* ── Resolve filtered student's display name (for header) ────────────── */
$filterUserName = '';
if ($isAdmin && $filterUser) {
    $fu = Database::fetchOne(
        "SELECT FstName, LstName, LoginName FROM userinfo WHERE UserInfoId = ?",
        [$filterUser]);
    if ($fu) {
        $filterUserName = trim(($fu['FstName'] ?? '') . ' ' . ($fu['LstName'] ?? ''));
        $filterUserName = $filterUserName !== '' ? $filterUserName : ($fu['LoginName'] ?? '');
    }
}

/* ── Normalise ──────────────────────────────────────────────────────── */
$results  = [];

foreach ($rows as $r) {
    $r['Score']      = isset($r['Score'])      ? (int)$r['Score']      : 0;
    $r['MarksOutOf'] = isset($r['MarksOutOf']) ? (int)$r['MarksOutOf'] : (int)($r['NumOfQuestions'] ?? 0);
    $r['Description']= $r['Description'] ?? '';

    $minPass = min(100, max(0, (int)($r['MinPassing'] ?? 0)));
    if ($r['Description'] !== '' && $minPass > 0 && $r['MarksOutOf'] > 0) {
        $pct = (int)round($r['Score'] / $r['MarksOutOf'] * 100);
        $r['Description'] = ($pct >= $minPass) ? 'Pass' : 'Fail';
    }

    $r['ScorePercent'] = $r['MarksOutOf'] > 0
        ? round($r['Score'] / $r['MarksOutOf'] * 100, 1) : 0;

    $r['TimeTaken']     = isset($r['TimeTaken'])     ? (int)$r['TimeTaken']     : 0;
    $r['CorrectCount']  = isset($r['CorrectCount'])  ? (int)$r['CorrectCount']  : null;
    $r['WrongCount']    = isset($r['WrongCount'])     ? (int)$r['WrongCount']    : null;
    $r['SkippedCount']  = isset($r['SkippedCount'])  ? (int)$r['SkippedCount']  : null;
    $r['ExamDate']      = (isset($r['ExamDate']) && $r['ExamDate']) ? $r['ExamDate'] : ($r['CreateDate'] ?? null);
    $r['_rank']         = null; // filled in below

    $results[] = $r;
}

/* ── Compute rank per exam, independent of the table's own row order ──────
   BUG FIXED: rank used to just be each row's position within a contiguous
   run of same-ExamInfoId rows, on the assumption "rows already arrive
   sorted by score DESC". That's only true for the admin single-exam
   leaderboard query above (elseif $isAdmin && $filterExam) — every other
   branch (admin drilled into one student, admin's all-exams table's
   secondary sort, and a student's own history) orders by attempt DATE
   (StudentExamId DESC), not score. So e.g. an admin viewing one student's 5
   attempts of the same exam — ordered newest-first — got numbered #1..#5 in
   DATE order and colored gold/silver/bronze for the 3 most RECENT attempts,
   regardless of which actually scored highest (a 60% "Fail" could show as
   silver-medal "#2" while the actual best, 100%, showed as plain "#5").
   Fixed by ranking each ExamInfoId group by score here, independently of
   the order $results/the table displays rows in (which stays date-based —
   useful for browsing chronologically; rank still reflects real standing:
   among all students for that exam when this table spans multiple
   students, or among one student's own repeat attempts when it doesn't).
   Only completed (Pass/Fail) attempts are ranked; an in-progress attempt
   has no score to rank by and is left unranked ('_rank' stays null). */
$byExam = [];
foreach ($results as $idx => $r) {
    if (!in_array($r['Description'], ['Pass', 'Fail'], true)) continue;
    $byExam[(int)$r['ExamInfoId']][] = $idx;
}
foreach ($byExam as $examIdxs) {
    usort($examIdxs, function ($a, $b) use ($results) {
        $ra = $results[$a]; $rb = $results[$b];
        if ($ra['ScorePercent'] !== $rb['ScorePercent']) return $rb['ScorePercent'] <=> $ra['ScorePercent'];
        if ($ra['TimeTaken']    !== $rb['TimeTaken'])    return $ra['TimeTaken']    <=> $rb['TimeTaken'];
        return ($rb['StudentExamId'] ?? 0) <=> ($ra['StudentExamId'] ?? 0);
    });
    foreach ($examIdxs as $pos => $idx) {
        $results[$idx]['_rank'] = $pos + 1;
    }
}

/* ── Attempt-limit data (migration_v36) ───────────────────────────────
   "Used" is counted from the rows already loaded into $results, which are
   always scoped to exactly the exam(s)/student being displayed — no extra
   query needed. Per-student overrides are bulk-loaded in one query for
   whichever exam(s) appear on this page (graceful no-op if the migration
   hasn't been run yet). */
$usedCountMap = [];   // [ExamInfoId][UserInfoId] => count
foreach ($results as $r) {
    $eId = (int)$r['ExamInfoId'];
    $uId = $isAdmin ? (int)($r['UserInfoId'] ?? 0) : $myUid;
    $usedCountMap[$eId][$uId] = ($usedCountMap[$eId][$uId] ?? 0) + 1;
}

$attemptOverrideMap = []; // [ExamInfoId][UserInfoId] => MaxAttempts
$_examIdsOnPage = array_values(array_unique(array_map(fn($r) => (int)$r['ExamInfoId'], $results)));
if (!empty($_examIdsOnPage)) {
    try {
        $placeholders = implode(',', array_fill(0, count($_examIdsOnPage), '?'));
        foreach (Database::fetchAll(
            "SELECT ExamInfoId, UserInfoId, MaxAttempts FROM exam_attempt_overrides
              WHERE ExamInfoId IN ($placeholders)", $_examIdsOnPage) as $ov) {
            $attemptOverrideMap[(int)$ov['ExamInfoId']][(int)$ov['UserInfoId']] = (int)$ov['MaxAttempts'];
        }
    } catch (Exception $e) { /* migration_v36 not yet run */ }
}

/** Resolves [used, max, unlimited] for a result row using the bulk-loaded maps above. */
function _attemptInfo(array $r, int $uid, array $usedCountMap, array $attemptOverrideMap): array {
    $eId  = (int)$r['ExamInfoId'];
    $max  = $attemptOverrideMap[$eId][$uid] ?? (isset($r['MaxAttempts']) ? (int)$r['MaxAttempts'] : 5);
    $used = $usedCountMap[$eId][$uid] ?? 1;
    return ['used' => $used, 'max' => $max, 'unlimited' => ($max <= 0)];
}

/* ── Student's own rank (when student views their history for one exam) */
$myRank = null; $totalInExam = 0;
if (!$isAdmin && $filterExam) {
    // Count all completed attempts for this exam, ranked by score%
    $allForExam = Database::fetchAll(
        "SELECT se.StudentExamId, se.UserInfoId,
                se.Score / NULLIF(se.MarksOutOf,0) AS Pct,
                se.TimeTaken
           FROM studentexam se
          WHERE se.ExamInfoId = ?
            AND se.Description IN ('Pass','Fail')
          ORDER BY Pct DESC, se.TimeTaken ASC, se.StudentExamId DESC",
        [$filterExam]);
    $totalInExam = count($allForExam);
    foreach ($allForExam as $pos => $a) {
        if ((int)$a['UserInfoId'] === $myUid) { $myRank = $pos + 1; break; }
    }
}

$totalTaken  = count($results);
$totalPassed = count(array_filter($results, fn($r) => $r['Description'] === 'Pass'));

/* ── Assigned exams (may not have been attempted yet) ─────────────────
   Inherently a single-student view, so only shown for a student looking
   at their own history, or an admin drilled into one specific student —
   an admin's multi-student list already covers every attempt below. */
$showAssignedTab = !$isAdmin || ($isAdmin && $filterUser);
$assignedUid     = $isAdmin ? $filterUser : $myUid;
$assignedRows    = [];
if ($showAssignedTab && $assignedUid) {
    try {
        $aWhere  = 'ea.UserInfoId = ?';
        $aParams = [$assignedUid];
        if ($filterExam) { $aWhere .= ' AND ea.ExamInfoId = ?'; $aParams[] = $filterExam; }
        $assignedRows = Database::fetchAll(
            "SELECT ea.AssignmentId, ea.ExamInfoId, ea.DueDate, ea.Status, ea.AssignedAt, ea.StudentExamId,
                    e.ExamName, g.GradeName, s.SubjectName
               FROM exam_assignments ea
               JOIN examinfo e ON e.ExamInfoId = ea.ExamInfoId
          LEFT JOIN gradeinfo g   ON g.GradeInfoId   = e.GradeInfoId
          LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
              WHERE $aWhere
              ORDER BY (ea.Status = 'Assigned') DESC, ea.DueDate IS NULL, ea.DueDate ASC, ea.AssignedAt DESC",
            $aParams);
    } catch (Exception $e) { /* exam_assignments not yet created — pre-migration_v5 */ }
}

$today = date('Y-m-d');
foreach ($assignedRows as &$ar) {
    $ar['IsOverdue']     = ($ar['Status'] === 'Assigned' && !empty($ar['DueDate']) && $ar['DueDate'] < $today);
    $ar['AttemptStatus'] = Enrollment::getAttemptStatus((int)$ar['ExamInfoId'], (int)$assignedUid);
}
unset($ar);

$pendingAssignedCount = count(array_filter($assignedRows, fn($a) => $a['Status'] === 'Assigned'));

/* ── Flash message from the assign-exam POST redirect above ───────────── */
$histFlash = isset($_GET['flash']) ? urldecode($_GET['flash']) : '';
[$histFlashType, $histFlashMsg] = $histFlash ? explode('|', $histFlash, 2) : ['', ''];

/* ── Exams this admin can assign to this student (dropdown) ───────────── */
$assignableExams = [];
if ($canAssignFromHere) {
    $aeWhere  = ["COALESCE(e.IsActive,'Y') = 'Y'", "COALESCE(e.IsDeleted,'N') = 'N'"];
    $aeParams = [];
    if (Database::hasColumn('examinfo', 'IsQuestionBank')) {
        $aeWhere[] = "COALESCE(e.IsQuestionBank,'N') = 'N'";
    }
    if ($isInstAdmin && !$isFullAdmin) {
        $aeWhere[]  = 'e.ExamInstituteId = ?';
        $aeParams[] = Auth::currentInstituteId();
    }
    try {
        $assignableExams = Database::fetchAll(
            "SELECT e.ExamInfoId, e.ExamName, g.GradeName, s.SubjectName
               FROM examinfo e
          LEFT JOIN gradeinfo   g ON g.GradeInfoId   = e.GradeInfoId
          LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
              WHERE " . implode(' AND ', $aeWhere) . "
              ORDER BY e.ExamName",
            $aeParams);
    } catch (Exception $e) { $assignableExams = []; } // pre-migration_v43 (IsDeleted) DBs
}

/* Exams already actively (non-Completed) assigned to this student — greyed
   out in the dropdown below so an admin doesn't re-submit a duplicate that
   the POST handler above would just reject anyway. */
$activeAssignedExamIds = array_column(
    array_filter($assignedRows, fn($a) => $a['Status'] !== 'Completed'),
    'ExamInfoId'
);

/* ── Chart / filter data — full dataset sent to JS ──────────────────── */
define('CHART_LIMIT', 10);
$completedResults = array_values(array_filter($results,
    fn($r) => in_array($r['Description'], ['Pass','Fail'])));
$chartExamName    = ($filterExam && !empty($results)) ? ($results[0]['ExamName'] ?? '') : '';

// All attempts (including in-progress) for stat cards
$jsAttempts = [];
foreach ($results as $r) {
    $dt = $r['ExamDate'] ?? null;
    $dateLabel = $dt ? date('d M H:i', strtotime($dt)) : '';

    // Admins viewing attempts are looking at multiple different students on the
    // same exam(s) — label each bar with the student's name instead of the
    // attempt date/time (the date is still shown in the tooltip).
    $studentName = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
    $studentName = $studentName ?: ($r['StudentLogin'] ?? '');

    $jsAttempts[] = [
        'subject'   => trim($r['SubjectName'] ?? ''),
        'examName'  => $r['ExamName']   ?? '',
        'label'     => $isAdmin ? ($studentName ?: $dateLabel) : $dateLabel,
        'dateLabel' => $dateLabel,
        'ts'        => $dt ? strtotime($dt) : 0,
        'score'     => $r['MarksOutOf'] > 0 ? (int)round($r['Score'] / $r['MarksOutOf'] * 100) : 0,
        'passing'   => (int)($r['MinPassing'] ?? 0) > 0 ? min(100, max(0, (int)$r['MinPassing'])) : null,
        'timeTaken' => (int)$r['TimeTaken'],
        'passed'    => $r['Description'] === 'Pass',
        'completed' => in_array($r['Description'], ['Pass','Fail']),
    ];
}

// Unique subject list (sorted) from all results
$_subjectSet = [];
foreach ($results as $r) {
    $s = trim($r['SubjectName'] ?? '');
    if ($s !== '') $_subjectSet[$s] = true;
}
$subjectList = array_keys($_subjectSet);
sort($subjectList);

/* ── Export URL ─────────────────────────────────────────────────────── */
$exportUrl = 'export-excel.php?type=history' . ($filterExam ? '&InfoId=' . $filterExam : '');

$pageHead = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
include __DIR__ . '/../includes/header.php';
?>

<style>
.hist-tabs{display:flex;gap:0;border-bottom:2px solid var(--clr-border);padding:0 20px;background:var(--clr-bg,#fff);}
.hist-tab{padding:11px 22px;font-size:.9rem;font-weight:600;cursor:pointer;border:none;background:transparent;
          color:var(--clr-text-muted,#718096);border-bottom:3px solid transparent;margin-bottom:-2px;
          transition:color .15s,border-color .15s;white-space:nowrap;}
.hist-tab:hover{color:#4f46e5;}
.hist-tab.active{color:#4f46e5;border-bottom-color:#4f46e5;}
.hist-panel{display:none;}.hist-panel.active{display:block;}

/* stat cards inside chart tab */
.stat-grid{display:flex;flex-wrap:wrap;gap:12px;padding:16px 20px 0;}
.stat-card{flex:1;min-width:110px;background:var(--clr-bg-secondary,#f8f9fa);
           border:1px solid var(--clr-border);border-radius:10px;padding:12px 16px;text-align:center;}
.stat-card .stat-val{font-size:1.6rem;font-weight:700;line-height:1.1;}
.stat-card .stat-lbl{font-size:.75rem;color:var(--clr-text-muted,#718096);margin-top:4px;text-transform:uppercase;letter-spacing:.04em;}

/* best/avg/worst cards inside chart tab */
.mini-stat-row{display:flex;flex-wrap:wrap;gap:10px;padding:0 20px 16px;}
.mini-stat{flex:1;min-width:90px;border-radius:8px;padding:10px 14px;text-align:center;}
.mini-stat .ms-val{font-size:1.2rem;font-weight:700;}
.mini-stat .ms-lbl{font-size:.72rem;margin-top:2px;}

/* Assign Exam modal */
.assign-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:1000;
                       align-items:center;justify-content:center;padding:16px;}
.assign-modal-overlay.open{display:flex;}
.assign-modal-card{background:#fff;border-radius:10px;width:100%;max-width:420px;box-shadow:0 10px 40px rgba(0,0,0,.25);overflow:hidden;}
.assign-modal-hdr{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;
                   background:#4f46e5;color:#fff;font-weight:700;font-size:.95rem;}
.assign-modal-close{background:transparent;border:none;color:#fff;font-size:1.4rem;line-height:1;cursor:pointer;padding:0 4px;}
.assign-modal-body{padding:18px;}
.assign-modal-ftr{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid #e2e8f0;background:#f8fafc;}
</style>

<div class="card">
  <!-- ── Card header ──────────────────────────────────────────────────── -->
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div>
      <span>&#128200; <?php echo $filterUser ? 'Student Exam History' : ($filterExam ? 'Exam Attempt History' : 'My Exam History'); ?></span>
      <?php if ($filterUser && $filterUserName !== ''): ?>
        <span style="margin-left:10px;font-size:.85rem;font-weight:400;opacity:.85;">
          — <?php echo htmlspecialchars($filterUserName); ?>
        </span>
      <?php elseif ($chartExamName !== ''): ?>
        <span style="margin-left:10px;font-size:.85rem;font-weight:400;opacity:.85;">
          — <?php echo htmlspecialchars($chartExamName); ?>
        </span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <?php if ($isAdmin && !$filterUser && !empty($results)): ?>
        <a href="<?php echo htmlspecialchars($exportUrl); ?>"
           class="btn btn-sm" style="background:#217346;color:#fff;" title="Export to Excel">
          &#128190; Export XL
        </a>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <a href="../Admin/ExamResults.php<?php echo $filterExam ? '?exam='.$filterExam : ''; ?>"
           class="btn btn-sm" style="background:#0891b2;color:#fff;">&#128202; Results Dashboard</a>
      <?php endif; ?>
      <?php if ($filterUser): ?>
        <a href="../Admin/AdminUsers.php?tab=students" class="btn btn-secondary btn-sm">&#8592; Back to Users</a>
      <?php else: ?>
        <a href="search.php" class="btn btn-secondary btn-sm">&#8592; Back to Exams</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Tab nav ──────────────────────────────────────────────────────── -->
  <div class="hist-tabs" role="tablist">
    <button class="hist-tab<?php echo $initialTab === 'chart' ? ' active' : ''; ?>" id="tab-btn-chart" role="tab"
            aria-selected="<?php echo $initialTab === 'chart' ? 'true' : 'false'; ?>" aria-controls="panel-chart"
            onclick="switchTab('chart')">
      &#128200; Chart &amp; Summary
    </button>
    <button class="hist-tab<?php echo $initialTab === 'attempts' ? ' active' : ''; ?>" id="tab-btn-attempts" role="tab"
            aria-selected="<?php echo $initialTab === 'attempts' ? 'true' : 'false'; ?>" aria-controls="panel-attempts"
            onclick="switchTab('attempts')">
      <?php echo $isAdmin ? '&#128101; Student Results' : '&#128203; My Attempts'; ?>
      <span style="margin-left:6px;background:#ede9fe;color:#4f46e5;border-radius:12px;
                   padding:1px 8px;font-size:.75rem;font-weight:700;">
        <?php echo $totalTaken; ?>
      </span>
    </button>
    <?php if ($showAssignedTab): ?>
    <button class="hist-tab<?php echo $initialTab === 'assigned' ? ' active' : ''; ?>" id="tab-btn-assigned" role="tab"
            aria-selected="<?php echo $initialTab === 'assigned' ? 'true' : 'false'; ?>" aria-controls="panel-assigned"
            onclick="switchTab('assigned')">
      &#128221; Assigned Exams
      <span style="margin-left:6px;background:#fef3c7;color:#b45309;border-radius:12px;
                   padding:1px 8px;font-size:.75rem;font-weight:700;">
        <?php echo $pendingAssignedCount; ?>
      </span>
    </button>
    <?php endif; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════ -->
  <!-- TAB 1: Chart & Summary                                            -->
  <!-- ══════════════════════════════════════════════════════════════════ -->
  <div class="hist-panel<?php echo $initialTab === 'chart' ? ' active' : ''; ?>" id="panel-chart" role="tabpanel" aria-labelledby="tab-btn-chart">

    <!-- Stat cards row -->
    <?php
      $avgScore = $totalTaken > 0
        ? round(array_sum(array_column($results,'ScorePercent')) / $totalTaken, 1) : 0;
      $scores_only = array_column($results,'ScorePercent');
      $bestScore   = $scores_only ? max($scores_only) : 0;
      $worstScore  = $scores_only ? min($scores_only) : 0;
      $passRate    = $totalTaken > 0 ? round($totalPassed / $totalTaken * 100) : 0;
    ?>
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-val" style="color:#4f46e5;" id="sv-total"><?php echo $totalTaken; ?></div>
        <div class="stat-lbl">Total Attempts</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:#059669;" id="sv-passed"><?php echo $totalPassed; ?></div>
        <div class="stat-lbl">Passed</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:#ef4444;" id="sv-failed"><?php echo $totalTaken - $totalPassed; ?></div>
        <div class="stat-lbl">Failed</div>
      </div>
      <div class="stat-card">
        <div class="stat-val" style="color:#0891b2;" id="sv-passrate"><?php echo $passRate; ?>%</div>
        <div class="stat-lbl">Pass Rate</div>
      </div>
      <?php if (!$isAdmin && $filterExam && $myRank !== null): ?>
      <div class="stat-card">
        <div class="stat-val" style="color:#d97706;">&#127942; <?php echo $myRank; ?>/<?php echo $totalInExam; ?></div>
        <div class="stat-lbl">Your Rank</div>
      </div>
      <?php endif; ?>
      <?php if (!$isAdmin && $filterExam):
        $myAttemptStatus = Enrollment::getAttemptStatus($filterExam, $myUid);
      ?>
      <div class="stat-card">
        <div class="stat-val" style="color:<?php echo $myAttemptStatus['allowed'] ? '#4338ca' : '#c53030'; ?>;">
          &#128260; <?php echo $myAttemptStatus['unlimited'] ? $myAttemptStatus['used'] . '/&infin;' : $myAttemptStatus['used'] . '/' . $myAttemptStatus['max']; ?>
        </div>
        <div class="stat-lbl">Attempts Used</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Best / Avg / Worst mini row -->
    <?php if ($totalTaken > 0): ?>
    <div class="mini-stat-row" style="margin-top:12px;">
      <div class="mini-stat" style="background:#ecfdf5;">
        <div class="ms-val" style="color:#059669;" id="ms-best"><?php echo $bestScore; ?>%</div>
        <div class="ms-lbl" style="color:#065f46;">Best score</div>
      </div>
      <div class="mini-stat" style="background:#eff6ff;">
        <div class="ms-val" style="color:#2563eb;" id="ms-avg"><?php echo $avgScore; ?>%</div>
        <div class="ms-lbl" style="color:#1e40af;">Average score</div>
      </div>
      <div class="mini-stat" style="background:#fef2f2;">
        <div class="ms-val" style="color:#ef4444;" id="ms-worst"><?php echo $worstScore; ?>%</div>
        <div class="ms-lbl" style="color:#991b1b;">Worst score</div>
      </div>
      <?php if (count($scores_only) > 0):
        $times = array_filter(array_column($results,'TimeTaken'), fn($t) => $t > 0);
        $avgTime = $times ? (int)round(array_sum($times) / count($times)) : 0;
      ?>
      <div class="mini-stat" style="background:#faf5ff;">
        <div class="ms-val" style="color:#7c3aed;" id="ms-time"><?php echo floor($avgTime/60).'m '.($avgTime%60).'s'; ?></div>
        <div class="ms-lbl" style="color:#5b21b6;">Avg time taken</div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Score trend chart -->
    <?php if (!empty($results)): ?>
    <div class="card-body" style="padding:16px 20px 20px;">

      <!-- Subject filter row -->
      <?php if (count($subjectList) > 0): ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
        <label for="subjectFilter"
               style="font-size:.85rem;font-weight:700;color:#4a5568;white-space:nowrap;">
          &#128218; Subject:
        </label>
        <select id="subjectFilter" onchange="filterBySubject(this.value)"
                style="padding:6px 28px 6px 10px;border:1px solid #cbd5e0;border-radius:6px;
                       font-size:.875rem;background:#fff;cursor:pointer;min-width:160px;">
          <option value="">All Subjects</option>
          <?php foreach ($subjectList as $s): ?>
          <option value="<?php echo htmlspecialchars($s, ENT_QUOTES); ?>"
                  <?php echo count($subjectList) === 1 ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($s); ?>
          </option>
          <?php endforeach; ?>
        </select>
        <span id="subj-badge" style="font-size:.78rem;color:#6b7280;"></span>
      </div>
      <?php endif; ?>

      <!-- Chart header -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
        <span style="font-size:.8rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.05em;">
          &#128200; Score Trend (% of total marks)
        </span>
        <span id="chart-count-note" style="font-size:.75rem;color:#94a3b8;"></span>
      </div>

      <?php if ($chartExamName !== ''): ?>
        <div style="font-size:.9rem;font-weight:600;color:#312e81;margin-bottom:10px;">
          <?php echo htmlspecialchars($chartExamName); ?>
        </div>
      <?php endif; ?>

      <div id="chart-wrap" style="position:relative;height:280px;">
        <canvas id="historyChart"></canvas>
      </div>
      <p id="chart-empty-msg" style="display:none;padding:20px 0;color:#718096;text-align:center;font-size:.875rem;">
        Complete at least 2 attempts in this subject to see the trend chart.
      </p>

    </div>
    <?php else: ?>
      <p style="padding:32px;color:#718096;text-align:center;">No attempts yet.</p>
    <?php endif; ?>

  </div><!-- /panel-chart -->

  <!-- ══════════════════════════════════════════════════════════════════ -->
  <!-- TAB 2: Attempts table                                             -->
  <!-- ══════════════════════════════════════════════════════════════════ -->
  <div class="hist-panel<?php echo $initialTab === 'attempts' ? ' active' : ''; ?>" id="panel-attempts" role="tabpanel" aria-labelledby="tab-btn-attempts">
    <?php if (empty($results)): ?>
      <p style="padding:32px;color:#718096;text-align:center;">
        No exam attempts found.
        <?php if ($showAssignedTab && !empty($assignedRows)): ?>
          <br><span style="font-size:.85rem;">
            <a href="javascript:void(0)" onclick="switchTab('assigned')">See Assigned Exams &rarr;</a>
          </span>
        <?php endif; ?>
      </p>
    <?php else: ?>

    <?php if ($isAdmin): ?>
    <!-- Student name search bar -->
    <div style="padding:10px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <div style="position:relative;flex:1;min-width:220px;max-width:380px;">
        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);
                     color:#9ca3af;pointer-events:none;font-size:1rem;">&#128269;</span>
        <input type="text" id="studentSearch"
               placeholder="Search student name or username…"
               oninput="filterStudents(this.value)"
               autocomplete="off"
               style="width:100%;padding:7px 12px 7px 32px;border:1px solid #d1d5db;
                      border-radius:6px;font-size:.875rem;outline:none;
                      box-sizing:border-box;font-family:inherit;">
      </div>
      <span id="searchCount" style="font-size:.82rem;color:#6b7280;white-space:nowrap;"></span>
      <button onclick="clearSearch()" id="clearBtn"
              style="display:none;padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;
                     background:#fff;font-size:.82rem;cursor:pointer;color:#374151;">
        ✕ Clear
      </button>
    </div>
    <?php endif; ?>

    <div class="tbl-wrap">
    <table class="tbl" id="attemptsTable">
      <thead>
        <tr>
          <th style="width:48px;text-align:center;">Rank</th>
          <?php if ($isAdmin): ?><th>Student</th><?php endif; ?>
          <th>Exam</th><th>Grade</th><th>Subject</th>
          <th style="text-align:center;">Score</th>
          <th style="text-align:center;">Score %</th>
          <th style="text-align:center;">&#10004;&nbsp;/&nbsp;&#10008;&nbsp;/&nbsp;&#8212;</th>
          <th style="text-align:center;">Result</th>
          <th style="text-align:center;" title="Attempts used / max allowed for this exam">Attempts</th>
          <th style="text-align:center;">Duration</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $i => $r):
          $rank     = $r['_rank'];
          $isPassed = ($r['Description'] === 'Pass');
          $medal = '';
          if ($isAdmin && $filterExam) {
              $medals = [1 => '&#127947;', 2 => '&#129352;', 3 => '&#129353;'];
              $medal  = isset($medals[$rank]) ? $medals[$rank] : '';
          }
          $rowUid = $isAdmin ? (int)($r['UserInfoId'] ?? 0) : $myUid;
          $attemptInfo = _attemptInfo($r, $rowUid, $usedCountMap, $attemptOverrideMap);
        ?>
        <tr>
          <td class="text-center" style="font-weight:700;font-size:.95rem;
            color:<?php echo $rank===1?'#d97706':($rank===2?'#6b7280':($rank===3?'#92400e':'var(--clr-text-muted)')); ?>;">
            <?php echo $rank === null ? '<span style="color:#a0aec0;font-weight:400;" title="In progress — not yet scored">—</span>' : ($medal ?: '#'.$rank); ?>
          </td>

          <?php if ($isAdmin): ?>
          <?php
            $name = trim(($r['FstName']??'').' '.($r['LstName']??''));
            $displayName = $name ?: ($r['StudentLogin'] ?? '—');
            $searchKey = strtolower($displayName . ' ' . ($r['StudentLogin'] ?? ''));
          ?>
          <td style="font-weight:500;" data-student="<?php echo htmlspecialchars($searchKey); ?>">
            <?php echo htmlspecialchars($displayName); ?>
          </td>
          <?php endif; ?>

          <td><?php echo htmlspecialchars($r['ExamName'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($r['GradeName'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($r['SubjectName'] ?? '—'); ?></td>

          <td class="text-center">
            <strong><?php echo $r['Score']; ?> / <?php echo $r['MarksOutOf']; ?></strong>
          </td>

          <td class="text-center">
            <div class="score-bar-wrap" style="min-width:80px;">
              <div class="score-bar-bg">
                <div class="score-bar-fill"
                     style="width:<?php echo $r['ScorePercent']; ?>%;
                            background:<?php echo $isPassed ? 'var(--clr-success)' : 'var(--clr-danger)'; ?>;"></div>
              </div>
              <span style="font-size:.8rem;font-weight:700;min-width:36px;">
                <?php echo $r['ScorePercent']; ?>%
              </span>
            </div>
          </td>

          <td class="text-center" style="white-space:nowrap;">
            <?php if ($r['CorrectCount'] !== null): ?>
              <span style="color:#059669;font-weight:700;"><?php echo $r['CorrectCount']; ?></span>
              &nbsp;/&nbsp;
              <span style="color:#ef4444;font-weight:700;"><?php echo $r['WrongCount']; ?></span>
              &nbsp;/&nbsp;
              <span style="color:#94a3b8;font-weight:700;"><?php echo $r['SkippedCount']; ?></span>
            <?php else: echo '<span style="color:#94a3b8;">—</span>'; endif; ?>
          </td>

          <td class="text-center">
            <?php if ($r['Description'] !== ''): ?>
              <span class="badge <?php echo $isPassed ? 'badge-pass' : 'badge-fail'; ?>">
                <?php echo htmlspecialchars($r['Description']); ?>
              </span>
            <?php else: echo '—'; endif; ?>
          </td>

          <td class="text-center" style="white-space:nowrap;">
            <?php if ($attemptInfo['unlimited']): ?>
              <span style="font-size:.78rem;color:#718096;"><?php echo $attemptInfo['used']; ?>/&infin;</span>
            <?php else: ?>
              <span style="font-size:.8rem;font-weight:700;color:<?php echo $attemptInfo['used'] >= $attemptInfo['max'] ? '#c53030' : '#2b6cb0'; ?>;"
                    title="<?php echo $attemptInfo['used']; ?> of <?php echo $attemptInfo['max']; ?> attempts used">
                <?php echo $attemptInfo['used']; ?>/<?php echo $attemptInfo['max']; ?>
              </span>
            <?php endif; ?>
          </td>

          <td class="text-center" style="white-space:nowrap;">
            <?php $t = $r['TimeTaken']; echo $t > 0 ? floor($t/60).'m '.($t%60).'s' : '—'; ?>
          </td>

          <td style="white-space:nowrap;">
            <?php $dt = $r['ExamDate'] ?? '';
              echo $dt ? htmlspecialchars(date('d M Y H:i', strtotime($dt))) : '—'; ?>
          </td>

          <td>
            <a href="result.php?id=<?php echo (int)$r['StudentExamId']; ?>"
               class="btn btn-secondary btn-sm" style="white-space:nowrap;">
              &#128196; View
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div><!-- /panel-attempts -->

  <!-- ══════════════════════════════════════════════════════════════════ -->
  <!-- TAB 3: Assigned exams                                             -->
  <!-- ══════════════════════════════════════════════════════════════════ -->
  <?php if ($showAssignedTab): ?>
  <div class="hist-panel<?php echo $initialTab === 'assigned' ? ' active' : ''; ?>" id="panel-assigned" role="tabpanel" aria-labelledby="tab-btn-assigned">

    <?php if ($histFlashMsg): ?>
    <div style="margin:14px 16px 0;padding:10px 16px;border-radius:6px;font-weight:600;font-size:.88rem;
         background:<?php echo $histFlashType==='success'?'#c6efce':'#ffc7ce'; ?>;
         color:<?php echo $histFlashType==='success'?'#276749':'#c53030'; ?>;">
      <?php echo htmlspecialchars($histFlashMsg); ?>
    </div>
    <?php endif; ?>

    <?php if ($canAssignFromHere): ?>
    <div style="padding:14px 16px 0;display:flex;justify-content:flex-end;">
      <button type="button" onclick="openAssignExamModal()" class="btn btn-primary btn-sm">
        &#10133; Assign Exam
      </button>
    </div>
    <?php endif; ?>

    <?php if (empty($assignedRows)): ?>
      <p style="padding:32px;color:#718096;text-align:center;">
        No assigned exams found.
        <?php if (!$isAdmin): ?>
          <br><a href="search.php" class="btn btn-primary btn-sm" style="margin-top:10px;">&#128269; Browse Exams</a>
        <?php elseif ($canAssignFromHere): ?>
          <br><button type="button" onclick="openAssignExamModal()" class="btn btn-primary btn-sm" style="margin-top:10px;">
            &#10133; Assign an Exam
          </button>
        <?php endif; ?>
      </p>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Exam</th><th>Grade</th><th>Subject</th>
          <th style="text-align:center;">Status</th>
          <th style="text-align:center;" title="Attempts used / max allowed for this exam">Attempts</th>
          <th>Assigned</th><th>Due</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($assignedRows as $a):
          $isDone  = ($a['Status'] === 'Completed');
          $attInfo = $a['AttemptStatus'];
        ?>
        <tr>
          <td><?php echo htmlspecialchars($a['ExamName'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($a['GradeName'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($a['SubjectName'] ?? '—'); ?></td>

          <td class="text-center">
            <?php if ($isDone): ?>
              <span class="badge badge-pass">Completed</span>
            <?php elseif ($a['IsOverdue']): ?>
              <span class="badge badge-fail">Overdue</span>
            <?php else: ?>
              <span class="badge" style="background:#ebf8ff;color:#2b6cb0;">Assigned</span>
            <?php endif; ?>
          </td>

          <td class="text-center" style="white-space:nowrap;">
            <?php if ($attInfo['unlimited']): ?>
              <span style="font-size:.78rem;color:#718096;"><?php echo $attInfo['used']; ?>/&infin;</span>
            <?php else: ?>
              <span style="font-size:.8rem;font-weight:700;color:<?php echo $attInfo['used'] >= $attInfo['max'] ? '#c53030' : '#2b6cb0'; ?>;"
                    title="<?php echo $attInfo['used']; ?> of <?php echo $attInfo['max']; ?> attempts used">
                <?php echo $attInfo['used']; ?>/<?php echo $attInfo['max']; ?>
              </span>
            <?php endif; ?>
          </td>

          <td style="white-space:nowrap;">
            <?php echo !empty($a['AssignedAt']) ? htmlspecialchars(date('d M Y', strtotime($a['AssignedAt']))) : '—'; ?>
          </td>
          <td style="white-space:nowrap;">
            <?php echo !empty($a['DueDate']) ? htmlspecialchars(date('d M Y', strtotime($a['DueDate']))) : '—'; ?>
          </td>

          <td style="white-space:nowrap;">
            <?php if ($isDone && !empty($a['StudentExamId'])): ?>
              <a href="result.php?id=<?php echo (int)$a['StudentExamId']; ?>" class="btn btn-secondary btn-sm">&#128196; View</a>
            <?php elseif (!$isAdmin && $attInfo['allowed']): ?>
              <a href="write.php?InfoId=<?php echo (int)$a['ExamInfoId']; ?>" class="btn btn-primary btn-sm">&#9654; Start</a>
            <?php elseif (!$isAdmin): ?>
              <span style="font-size:.78rem;color:#c53030;">Limit reached</span>
            <?php else: ?>
              <span style="color:#94a3b8;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($canAssignFromHere): ?>
    <!-- ── Assign Exam modal ────────────────────────────────────────────── -->
    <div id="assignExamOverlay" class="assign-modal-overlay" onclick="if(event.target===this) closeAssignExamModal();">
      <div class="assign-modal-card" role="dialog" aria-modal="true" aria-labelledby="assignExamTitle">
        <div class="assign-modal-hdr">
          <span id="assignExamTitle">&#10133; Assign Exam<?php echo $filterUserName !== '' ? ' — ' . htmlspecialchars($filterUserName) : ''; ?></span>
          <button type="button" onclick="closeAssignExamModal()" aria-label="Close" class="assign-modal-close">&times;</button>
        </div>
        <form method="post" action="history.php?UserInfoId=<?php echo (int)$filterUser; ?>&filter=assigned">
          <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
          <div class="assign-modal-body">
            <?php if (empty($assignableExams)): ?>
              <p style="color:#718096;font-size:.88rem;">No assignable exams found<?php echo ($isInstAdmin && !$isFullAdmin) ? ' for your institute.' : '.'; ?></p>
            <?php else: ?>
              <label for="assign_exam_id" style="display:block;font-size:.85rem;font-weight:700;color:#4a5568;margin-bottom:6px;">Exam</label>
              <select name="assign_exam_id" id="assign_exam_id" required
                      style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:6px;font-size:.9rem;margin-bottom:14px;">
                <option value="">&#128269; Choose an exam…</option>
                <?php foreach ($assignableExams as $ae):
                  $alreadyActive = in_array((int)$ae['ExamInfoId'], $activeAssignedExamIds, true);
                  $labelParts = array_filter([$ae['GradeName'] ?? '', $ae['SubjectName'] ?? '']);
                  $label = $ae['ExamName'] . ($labelParts ? ' (' . implode(' · ', $labelParts) . ')' : '');
                ?>
                <option value="<?php echo (int)$ae['ExamInfoId']; ?>" <?php echo $alreadyActive ? 'disabled' : ''; ?>>
                  <?php echo htmlspecialchars($label); ?><?php echo $alreadyActive ? ' — already assigned' : ''; ?>
                </option>
                <?php endforeach; ?>
              </select>

              <label for="assign_due_date" style="display:block;font-size:.85rem;font-weight:700;color:#4a5568;margin-bottom:6px;">Due Date (optional)</label>
              <input type="date" name="assign_due_date" id="assign_due_date"
                     style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:6px;font-size:.9rem;">
            <?php endif; ?>
          </div>
          <div class="assign-modal-ftr">
            <button type="button" onclick="closeAssignExamModal()" class="btn btn-secondary btn-sm">Cancel</button>
            <?php if (!empty($assignableExams)): ?>
            <button type="submit" class="btn btn-success btn-sm" style="font-weight:700;">&#10003; Assign</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /panel-assigned -->
  <?php endif; ?>

</div><!-- /card -->

<script>
/* ── Assign Exam modal ────────────────────────────────────────────────── */
function openAssignExamModal() {
  var el = document.getElementById('assignExamOverlay');
  if (!el) return;
  el.classList.add('open');
  var sel = document.getElementById('assign_exam_id');
  if (sel) setTimeout(function(){ sel.focus(); }, 50);
}
function closeAssignExamModal() {
  var el = document.getElementById('assignExamOverlay');
  if (el) el.classList.remove('open');
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeAssignExamModal();
});

/* ── Tab switching ────────────────────────────────────────────────────── */
function switchTab(name) {
  // Tab-agnostic: derives each tab's name from its own aria-controls
  // attribute rather than a hardcoded list, so panels can be added
  // (e.g. Assigned Exams) without touching this function.
  document.querySelectorAll('.hist-tab[aria-controls]').forEach(function(btn) {
    var panel  = document.getElementById(btn.getAttribute('aria-controls'));
    var active = (btn.getAttribute('aria-controls') === 'panel-' + name);
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-selected', active ? 'true' : 'false');
    if (panel) panel.classList.toggle('active', active);
  });
  /* Persist choice across page reloads */
  try { sessionStorage.setItem('histTab', name); } catch(e) {}
}

/* Restore last-used tab — but only when the page itself didn't ask for a
   specific one (e.g. the sidebar's "Completed" link opens this page with
   ?filter=completed, which the server already rendered onto the correct
   tab below). Letting a stale sessionStorage value override that explicit
   navigation was the bug: clicking "Completed" could still land on
   whichever tab (often "Assigned Exams", which mixes completed and
   not-yet-completed rows together) was last open in an earlier visit. */
var HIST_EXPLICIT_TAB = <?php echo json_encode($statusFilter !== '' ? $initialTab : null); ?>;
(function() {
  if (HIST_EXPLICIT_TAB) { return; } // server already rendered the right tab active
  try {
    var saved = sessionStorage.getItem('histTab');
    // Never silently auto-restore into "Assigned Exams" — that tab
    // deliberately mixes completed and not-yet-completed rows together,
    // which is fine when a user explicitly clicks over to it, but wrong
    // to land on out of nowhere. Without this, a fresh, unrelated
    // navigation into this page (e.g. the "Recent Results" nav link,
    // which never passes ?filter=) would restore whatever tab happened to
    // be open in an earlier visit, including "Assigned Exams" — so
    // "Recent Results" could show exams that are only assigned, never
    // actually attempted. "chart" and "attempts" are both genuine results
    // views, so restoring between those two is still fine.
    if (saved && saved !== 'chart' && saved !== 'assigned') switchTab(saved);
  } catch(e) {}
})();

/* ── Student name search ───────────────────────────────────────── */
function filterStudents(q) {
  q = q.trim().toLowerCase();
  var rows     = document.querySelectorAll('#attemptsTable tbody tr');
  var clearBtn = document.getElementById('clearBtn');
  var countEl  = document.getElementById('searchCount');
  var visible  = 0;

  rows.forEach(function(row) {
    var cell = row.querySelector('td[data-student]');
    if (!cell) { row.style.display = ''; visible++; return; }
    var match = (q === '' || cell.getAttribute('data-student').indexOf(q) !== -1);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });

  if (clearBtn) clearBtn.style.display = q ? 'inline-block' : 'none';
  if (countEl)  countEl.textContent    = q ? visible + ' result' + (visible !== 1 ? 's' : '') : '';
}

function clearSearch() {
  var inp = document.getElementById('studentSearch');
  if (inp) { inp.value = ''; inp.focus(); }
  filterStudents('');
}

/* Auto-open Student Results tab and focus search when URL has #search */
(function() {
  if (window.location.hash === '#search') {
    switchTab('attempts');
    var inp = document.getElementById('studentSearch');
    if (inp) setTimeout(function(){ inp.focus(); }, 100);
  }
})();
</script>

<?php if (!empty($results)): ?>
<script>
(function(){
  var LIMIT      = <?php echo CHART_LIMIT; ?>;
  var singleExam = <?php echo json_encode($chartExamName !== ''); ?>;
  var allAttempts= <?php echo json_encode(array_values($jsAttempts), JSON_UNESCAPED_UNICODE); ?>;

  // Subject colour palette (cycles for multiple subjects on "All" view)
  var SUBJ_COLOURS = ['#4f46e5','#0891b2','#059669','#d97706','#7c3aed','#db2777','#b45309'];
  var subjectColourMap = {};
  var _ci = 0;
  function subjectColour(subj) {
    if (!subjectColourMap[subj]) subjectColourMap[subj] = SUBJ_COLOURS[_ci++ % SUBJ_COLOURS.length];
    return subjectColourMap[subj];
  }

  /* ── Chart instance ──────────────────────────────────────────────── */
  var ctx   = document.getElementById('historyChart');
  var chart = ctx ? new Chart(ctx.getContext('2d'), {
    type: 'bar',
    data: { labels: [], datasets: [
      { label:'Score %', data:[], backgroundColor:[], borderColor:[], borderWidth:2, borderRadius:4, order:2 },
      { label:'Pass Mark %', data:[], type:'line',
        borderColor:'#d4a017', backgroundColor:'transparent',
        borderWidth:2, borderDash:[6,3],
        pointBackgroundColor:'#d4a017', pointRadius:4, tension:0, order:1 }
    ]},
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{
        legend:{ position:'top', labels:{ boxWidth:14, font:{size:11} } },
        tooltip:{
          callbacks:{
            title:function(items){
              var i = items[0].dataIndex;
              var a = chart._currentSlice ? chart._currentSlice[i] : null;
              // Use the raw date/name string (a.label) rather than the axis
              // tick value, since "All Subjects" ticks are 2-line arrays.
              var lbl = a ? (a.label || '—') : items[0].label;
              if (!a) return lbl;
              return singleExam
                ? lbl
                : (a.examName ? a.examName + ' — ' + a.subject + '\n' + lbl : lbl);
            },
            label:function(c){
              return ' ' + c.dataset.label + ': ' + (c.raw !== null ? c.raw + '%' : 'N/A');
            },
            afterLabel:function(c){
              if (c.datasetIndex !== 0) return '';
              var i = c.dataIndex;
              var a = chart._currentSlice ? chart._currentSlice[i] : null;
              if (!a) return '';
              var lines = [' Subject: ' + (a.subject || '—')];
              if (a.dateLabel) lines.push(' Date: ' + a.dateLabel);
              return lines;
            }
          }
        }
      },
      scales:{
        y:{ min:0, max:100, ticks:{callback:function(v){return v+'%';}, font:{size:11}}, grid:{color:'#f0f4f8'} },
        x:{ ticks:{font:{size:11}, maxRotation:45, minRotation:30, autoSkip:false}, grid:{display:false} }
      }
    }
  }) : null;

  /* ── Core filter + render function ──────────────────────────────── */
  window.filterBySubject = function(subj) {
    // 1. Filter all attempts by subject
    var filtered = subj === ''
      ? allAttempts
      : allAttempts.filter(function(a){ return a.subject === subj; });

    // 2. Update stat cards
    var total   = filtered.length;
    var passed  = filtered.filter(function(a){ return a.passed; }).length;
    var failed  = filtered.filter(function(a){ return a.completed && !a.passed; }).length;
    var rate    = total > 0 ? Math.round(passed / total * 100) : 0;

    var completedF = filtered.filter(function(a){ return a.completed; });
    var scores = completedF.map(function(a){ return a.score; });
    var best   = scores.length ? Math.max.apply(null, scores) : 0;
    var worst  = scores.length ? Math.min.apply(null, scores) : 0;
    var avg    = scores.length ? Math.round(scores.reduce(function(s,v){return s+v;},0) / scores.length * 10) / 10 : 0;
    var times  = completedF.map(function(a){return a.timeTaken;}).filter(function(t){return t>0;});
    var avgT   = times.length ? Math.round(times.reduce(function(s,v){return s+v;},0) / times.length) : 0;

    function set(id, v) { var el=document.getElementById(id); if(el) el.textContent=v; }
    set('sv-total',   total);
    set('sv-passed',  passed);
    set('sv-failed',  failed);
    set('sv-passrate',rate + '%');
    set('ms-best',    best  + '%');
    set('ms-avg',     avg   + '%');
    set('ms-worst',   worst + '%');
    set('ms-time',    Math.floor(avgT/60)+'m '+(avgT%60)+'s');

    // 3. Update subject badge
    var badge = document.getElementById('subj-badge');
    if (badge) badge.textContent = subj ? '(' + completedF.length + ' completed attempt' + (completedF.length!==1?'s':'') + ')' : '';

    // 4. Update chart
    var note  = document.getElementById('chart-count-note');
    var wrap  = document.getElementById('chart-wrap');
    var emptyMsg = document.getElementById('chart-empty-msg');

    // Order chronologically (oldest → newest) then take the most recent LIMIT,
    // so the chart always reads left-to-right in date order with the latest
    // attempt on the right.
    var byDateAsc = completedF.slice().sort(function(a, b){ return (a.ts || 0) - (b.ts || 0); });
    var chartSlice = byDateAsc.slice(-LIMIT);
    chart._currentSlice = chartSlice;

    if (!chart || chartSlice.length < 2) {
      if (wrap)     wrap.style.display    = 'none';
      if (emptyMsg) emptyMsg.style.display = '';
      if (note)     note.textContent = '';
      return;
    }
    if (wrap)     wrap.style.display    = '';
    if (emptyMsg) emptyMsg.style.display = 'none';

    var total_completed = completedF.length;
    if (note) note.textContent = total_completed > LIMIT
      ? 'Showing last ' + LIMIT + ' of ' + total_completed + ' completed attempts'
      : total_completed + ' completed attempt' + (total_completed !== 1 ? 's' : '');

    // When viewing "All Subjects", stack the subject name as a second
    // tick-label line under each bar (Chart.js renders string-array
    // labels as multiple lines) so subjects are visible without hovering.
    var labels = chartSlice.map(function(a){
      var base = a.label || '—';
      return subj === '' ? [base, a.subject || ''] : base;
    });
    var scores2 = chartSlice.map(function(a){ return a.score; });
    var passing = chartSlice.map(function(a){ return a.passing; });
    var bgColors = chartSlice.map(function(a){
      var p = a.passing;
      if (subj !== '') {
        // Single subject: green = pass, red = fail
        return (p !== null && a.score >= p) ? 'rgba(56,161,105,.75)' : 'rgba(229,62,62,.75)';
      } else {
        // Multi-subject: colour by subject, opacity by pass/fail
        var c = subjectColour(a.subject);
        return hexToRgba(c, (p !== null && a.score >= p) ? 0.75 : 0.45);
      }
    });
    var bdColors = chartSlice.map(function(a){
      var p = a.passing;
      if (subj !== '') {
        return (p !== null && a.score >= p) ? '#276749' : '#c53030';
      } else {
        return subjectColour(a.subject);
      }
    });

    chart.data.labels              = labels;
    chart.data.datasets[0].data   = scores2;
    chart.data.datasets[0].backgroundColor = bgColors;
    chart.data.datasets[0].borderColor     = bdColors;
    chart.data.datasets[1].data   = passing;
    chart.update();
  };

  /* ── Hex → rgba helper ───────────────────────────────────────────── */
  function hexToRgba(hex, alpha) {
    var r = parseInt(hex.slice(1,3),16),
        g = parseInt(hex.slice(3,5),16),
        b = parseInt(hex.slice(5,7),16);
    return 'rgba('+r+','+g+','+b+','+alpha+')';
  }

  /* ── Init: auto-select single subject if only one ────────────────── */
  (function(){
    var sel = document.getElementById('subjectFilter');
    var initSubj = sel ? sel.value : '';
    filterBySubject(initSubj);
  })();

})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
