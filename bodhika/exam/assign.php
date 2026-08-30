<?php
/**
 * exam/assign.php — Admin: assign an exam to one or more users.
 * POST action also handles un-assigning (delete assignment row).
 * Admin / Teacher / Principal only.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/StudentGroup.php';
Auth::requireLogin('../auth/login.php');

$isFullAdmin = Auth::isAdmin();
$isInstAdmin = Auth::isInstituteAdmin();
if (!$isFullAdmin && !$isInstAdmin) { header('Location: search.php'); exit; }

$examId    = filter_input(INPUT_GET,  'examId', FILTER_VALIDATE_INT)
          ?: filter_input(INPUT_POST, 'examId', FILTER_VALIDATE_INT);
if (!$examId) { header('Location: search.php'); exit; }

$adminLoginId = Auth::currentLoginId();
$flash        = '';
$myInstId     = null;
$backToExamsUrl = ($isInstAdmin && !$isFullAdmin) ? '../Admin/InstituteAdminExams.php' : 'search.php';

/* ── Institute-Admin scoping ───────────────────────────────────────────────
   May only assign an exam that belongs to their own institute, and only to
   students who also belong to their own institute — enforced both in the
   student-list query below (WHERE u.InstituteId = ?) and here as an early
   exit so a crafted user_ids[]/examId POST can't cross institute lines. */
if ($isInstAdmin && !$isFullAdmin) {
    $myInstId = Auth::currentInstituteId();
    $examInst = (int)(Database::fetchOne(
        "SELECT ExamInstituteId FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]
    )['ExamInstituteId'] ?? 0);
    if (!$myInstId || $examInst !== $myInstId) { header('Location: search.php'); exit; }
}

/* ── Question Bank guard (migration_v65) ──────────────────────────────────
   A bank exam is a pool, not something a student should ever be granted
   access to as-is — assigning it would hand them a "write" page for
   potentially thousands of questions. Checked before the POST handler
   below so a crafted assign/unassign POST can't route around it either.
   Falls open on a database that hasn't run migration_v65 yet. */
$isQuestionBankExam = false;
try {
    if (Database::hasColumn('examinfo', 'IsQuestionBank')) {
        $isQuestionBankExam = (Database::fetchOne(
            "SELECT IsQuestionBank FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]
        )['IsQuestionBank'] ?? 'N') === 'Y';
    }
} catch (Exception $e) { /* migration_v65 not yet run */ }
if ($isQuestionBankExam) {
    $examNameForNotice = Database::fetchOne(
        "SELECT ExamName FROM examinfo WHERE ExamInfoId = ? LIMIT 1", [$examId]
    )['ExamName'] ?? 'This exam';
    $pageTitle = 'Assign Exam';
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="card" style="max-width:560px;margin:40px auto;">
      <div class="card-header">&#128218; Question Bank — Not Assignable</div>
      <div style="padding:24px;">
        <div class="alert alert-warning" style="margin-bottom:16px;">
          <strong><?php echo htmlspecialchars($examNameForNotice); ?></strong> is a question bank, not
          a live exam, so it can't be assigned to students directly.
        </div>
        <p style="margin-bottom:16px;color:var(--clr-text-muted);">
          Use <a href="question-bank-builder.php?examId=<?php echo (int)$examId; ?>">Build from Question Bank</a>
          to pull questions from it into a real exam, then assign that exam instead.
        </p>
        <a class="btn btn-primary" href="<?php echo htmlspecialchars($backToExamsUrl); ?>">Back to Exams</a>
      </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

/* ── Handle POST — assign / unassign ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();

    /* Institute-Admin: every UserInfoId touched by this request (assign or
       unassign) must belong to their own institute — checked once here into
       a lookup set, rather than re-querying per row below. */
    $instScopedUserIds = null; // null = no restriction (full Admin)
    if ($isInstAdmin && !$isFullAdmin) {
        $instScopedUserIds = array_column(
            Database::fetchAll("SELECT UserInfoId FROM userinfo WHERE InstituteId = ?", [$myInstId]),
            'UserInfoId'
        );
        $instScopedUserIds = array_flip(array_map('intval', $instScopedUserIds));
    }

    /* Assign this exam to an ENTIRE student group — a durable, membership-
       independent grant (migration_v67, student_group_direct_assignments),
       not the per-user checkbox flow below. Works even when the group
       currently has zero members: the group-level row is written regardless,
       and StudentGroup::syncDirectAssignments() simply fans out zero
       exam_assignments rows in that case (no error). Any student added to
       the group later automatically receives this exam via the same bridge,
       called from Admin/StudentGroupMembers.php. */
    if (isset($_POST['assign_group'])) {
        $gid = (int)$_POST['assign_group'];
        $groupDueDate = trim($_POST['group_due_date'] ?? '') ?: null;
        $grp = $gid > 0 ? Database::fetchOne(
            "SELECT StudentGroupId, GroupName FROM student_groups WHERE StudentGroupId = ? LIMIT 1", [$gid]) : null;
        if (!$grp) {
            $flash = 'error|Please choose a student group first.';
        } else {
            Database::execute(
                "INSERT INTO student_group_direct_assignments (StudentGroupId, ExamInfoId, DueDate, AssignedBy)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE DueDate = VALUES(DueDate), AssignedAt = NOW(), AssignedBy = VALUES(AssignedBy)",
                [$gid, $examId, $groupDueDate, Auth::currentUser() ?: 'admin']);
            $createdCount = StudentGroup::syncDirectAssignments($gid, null, $adminLoginId);
            $flash = 'success|Assigned to group "' . $grp['GroupName'] . '".'
                . ($createdCount > 0
                    ? " {$createdCount} current member(s) assigned now."
                    : ' No current members yet — they\'ll be assigned automatically as students join this group.');
        }
        header('Location: assign.php?examId='.$examId.'&flash='.urlencode($flash)); exit;
    }

    /* Unassign this exam from an entire group — removes the durable
       group-level grant and revokes it for any member who hasn't attempted
       the exam yet (StudentGroup::pruneOrphanedDirectAssignments()).
       Members who already attempted it keep their result untouched. */
    if (isset($_POST['unassign_group'])) {
        $gid = (int)$_POST['unassign_group'];
        $grp = $gid > 0 ? Database::fetchOne(
            "SELECT StudentGroupId, GroupName FROM student_groups WHERE StudentGroupId = ? LIMIT 1", [$gid]) : null;
        if ($grp) {
            Database::execute(
                "DELETE FROM student_group_direct_assignments WHERE StudentGroupId=? AND ExamInfoId=?", [$gid, $examId]);
            $memberIds = array_map('intval', array_column(
                Database::fetchAll("SELECT UserInfoId FROM student_group_members WHERE StudentGroupId = ?", [$gid]),
                'UserInfoId'
            ));
            $revokedCount = $memberIds ? StudentGroup::pruneOrphanedDirectAssignments($memberIds, [$examId]) : 0;
            $flash = 'success|Unassigned from group "' . $grp['GroupName'] . '".'
                . ($revokedCount > 0 ? " {$revokedCount} un-attempted individual assignment(s) auto-revoked." : '');
        } else {
            $flash = 'error|Group not found.';
        }
        header('Location: assign.php?examId='.$examId.'&flash='.urlencode($flash)); exit;
    }

    /* Unassign a single user */
    if (isset($_POST['unassign_id'])) {
        $aid = (int)$_POST['unassign_id'];
        try {
            $extraWhere = '';
            $params = [$aid, $examId];
            if ($instScopedUserIds !== null) {
                $extraWhere = " AND UserInfoId IN (SELECT UserInfoId FROM userinfo WHERE InstituteId = ?)";
                $params[] = $myInstId;
            }
            Database::execute(
                "DELETE FROM exam_assignments WHERE AssignmentId=? AND ExamInfoId=?" . $extraWhere,
                $params);
            $flash = 'success|Assignment removed.';
        } catch (Exception $e) {
            $flash = 'error|Could not remove assignment: ' . htmlspecialchars($e->getMessage());
        }
        header('Location: assign.php?examId='.$examId.'&flash='.urlencode($flash)); exit;
    }

    /* Bulk unassign selected users */
    if (isset($_POST['unassign_ids'])) {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $_POST['unassign_ids']),
            fn($id) => $id > 0
        )));
        if (!$ids) {
            $flash = 'error|No assignments selected.';
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $extraWhere = '';
                $params = array_merge([$examId], $ids);
                if ($instScopedUserIds !== null) {
                    $extraWhere = " AND UserInfoId IN (SELECT UserInfoId FROM userinfo WHERE InstituteId = ?)";
                    $params[] = $myInstId;
                }
                $removed = Database::execute(
                    "DELETE FROM exam_assignments WHERE ExamInfoId=? AND AssignmentId IN ($placeholders)" . $extraWhere,
                    $params);
                $flash = 'success|Removed ' . $removed . ' assignment(s).';
            } catch (Exception $e) {
                $flash = 'error|Could not remove assignments: ' . htmlspecialchars($e->getMessage());
            }
        }
        header('Location: assign.php?examId='.$examId.'&flash='.urlencode($flash)); exit;
    }

    /* Bulk assign selected users */
    $rawIds  = $_POST['user_ids'] ?? [];
    $dueDate = trim($_POST['due_date'] ?? '') ?: null;

    if (!$rawIds) {
        $flash = 'error|No users selected.';
    } else {
        $assigned = 0; $skipped = 0;
        foreach ($rawIds as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) continue;
            // Institute-Admin: silently skip any UserInfoId outside their
            // own institute rather than assigning it — same "never trust the
            // client" reasoning as the unassign paths above.
            if ($instScopedUserIds !== null && !isset($instScopedUserIds[$uid])) { $skipped++; continue; }
            // Check if already assigned (any status)
            $existing = Database::fetchOne(
                "SELECT AssignmentId, Status FROM exam_assignments
                  WHERE ExamInfoId=? AND UserInfoId=? LIMIT 1",
                [$examId, $uid]);
            if ($existing) {
                // Re-open a completed assignment if admin explicitly re-assigns
                if ($existing['Status'] === 'Completed') {
                    Database::execute(
                        "UPDATE exam_assignments
                            SET Status='Assigned', AssignedBy=?, AssignedAt=NOW(), DueDate=?, StudentExamId=NULL
                          WHERE AssignmentId=?",
                        [$adminLoginId, $dueDate, $existing['AssignmentId']]);
                    $assigned++;
                } else {
                    $skipped++; // already assigned
                }
            } else {
                try {
                    Database::execute(
                        "INSERT INTO exam_assignments (ExamInfoId,UserInfoId,AssignedBy,DueDate)
                         VALUES (?,?,?,?)",
                        [$examId, $uid, $adminLoginId, $dueDate]);
                    $assigned++;
                } catch (Exception $e) { $skipped++; }
            }
        }
        $msg = "Assigned to {$assigned} user(s).";
        if ($skipped) $msg .= " {$skipped} already assigned (skipped).";
        $flash = 'success|' . $msg;
    }
    header('Location: assign.php?examId='.$examId.'&flash='.urlencode($flash)); exit;
}

/* ── Flash message from redirect ────────────────────────────────────────── */
if (isset($_GET['flash'])) {
    $flash = urldecode($_GET['flash']);
}

/* ── Load exam ───────────────────────────────────────────────────────────── */
$exam = Database::fetchOne(
    "SELECT e.ExamInfoId, e.ExamName, e.NumOfQuestions, e.MinPassing,
            g.GradeName, s.SubjectName
       FROM examinfo e
  LEFT JOIN gradeinfo   g ON g.GradeInfoId  = e.GradeInfoId
  LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
      WHERE e.ExamInfoId = ? LIMIT 1", [$examId]);
if (!$exam) { header('Location: search.php'); exit; }
/* IsQuestionBank already checked/blocked above, before the POST handler. */

/* ── Load students (non-admin users) ─────────────────────────────────────
   Institute-Admin sees ONLY their own institute's students — never the
   full user base a plain Admin sees. */
$studentWhere    = ['l.LoginInfoId IS NOT NULL'];
$studentExtraParams = [];
if ($isInstAdmin && !$isFullAdmin) {
    $studentWhere[] = 'u.InstituteId = ?';
    $studentExtraParams[] = $myInstId;
}
$allUsers = Database::fetchAll(
    "SELECT u.UserInfoId, u.FstName, u.LstName,
            l.LoginName, l.Role,
            i.InstituteId, i.InstituteName,
            ea.AssignmentId, ea.Status AS AssignStatus, ea.AssignedAt, ea.DueDate,
            ea.StudentExamId
       FROM userinfo    u
  LEFT JOIN logininfo   l  ON l.LoginName = u.LoginName
  LEFT JOIN institutes  i  ON i.InstituteId = u.InstituteId
  LEFT JOIN exam_assignments ea
         ON ea.UserInfoId = u.UserInfoId AND ea.ExamInfoId = ?
      WHERE " . implode(' AND ', $studentWhere) . "
      ORDER BY ea.Status ASC, u.LstName, u.FstName",
    array_merge([$examId], $studentExtraParams));

/* Separate into assigned vs unassigned for easier rendering */
$assigned   = array_filter($allUsers, fn($u) => $u['AssignStatus'] !== null);
$unassigned = array_filter($allUsers, fn($u) => $u['AssignStatus'] === null);

/* ── Historical attempt summary, per user, for THIS exam ──────────────────
   `studentexam` is an append-only attempts log (unlike exam_assignments,
   which is just a single reusable "current assignment" row per user) — so
   even after a completed assignment is re-opened and StudentExamId is
   cleared, every past attempt is still sitting here. One query, aggregated
   in PHP, avoids an N+1 query per row in the tables below. */
$historyByUser = [];
try {
    $historyRows = Database::fetchAll(
        "SELECT UserInfoId, Score, MarksOutOf, Description,
                COALESCE(ExamDate, CreateDate) AS AttemptDate
           FROM studentexam
          WHERE ExamInfoId = ?
          ORDER BY UserInfoId, AttemptDate DESC, StudentExamId DESC",
        [$examId]);
    foreach ($historyRows as $hr) {
        $uid = (int)$hr['UserInfoId'];
        $mo  = (float)($hr['MarksOutOf'] ?? 0);
        $sc  = (float)($hr['Score'] ?? 0);
        $pct = $mo > 0 ? round($sc / $mo * 100, 1) : null;

        if (!isset($historyByUser[$uid])) {
            /* First row seen per user (thanks to the ORDER BY above) is
               their most recent attempt. */
            $historyByUser[$uid] = [
                'count'      => 0,
                'best'       => null,
                'lastPct'    => $pct,
                'lastResult' => $hr['Description'] ?? '',
                'lastDate'   => $hr['AttemptDate'] ?? null,
            ];
        }
        $historyByUser[$uid]['count']++;
        if ($pct !== null && ($historyByUser[$uid]['best'] === null || $pct > $historyByUser[$uid]['best'])) {
            $historyByUser[$uid]['best'] = $pct;
        }
    }
} catch (Exception $e) { /* studentexam always exists; defensive no-op only */ }

/** Render the "Attempt History" cell for a given user: a summary of every
 *  past studentexam row for this exam (survives across re-assignment
 *  cycles), plus a link to the full per-student history page. */
function assign_render_history(int $examId, int $uid, array $historyByUser): string
{
    if (!isset($historyByUser[$uid])) {
        return '<span style="color:#a0aec0;font-size:.78rem;">No prior attempts</span>';
    }
    $h           = $historyByUser[$uid];
    $lastPctStr  = $h['lastPct'] !== null ? $h['lastPct'] . '%' : '—';
    $lastResult  = $h['lastResult'] !== '' ? ' (' . htmlspecialchars($h['lastResult']) . ')' : '';
    $lastDateStr = $h['lastDate'] ? date('d M Y', strtotime($h['lastDate'])) : '—';
    $bestStr     = $h['best'] !== null ? $h['best'] . '%' : '—';

    return '<div style="font-size:.78rem;line-height:1.45;color:#4a5568;">'
         . '<strong>' . (int)$h['count'] . '</strong> past attempt' . ((int)$h['count'] !== 1 ? 's' : '') . '<br>'
         . 'Last: ' . $lastPctStr . $lastResult . ' &middot; ' . htmlspecialchars($lastDateStr) . '<br>'
         . 'Best: ' . $bestStr . ' &middot; '
         . '<a href="history.php?InfoId=' . (int)$examId . '&UserInfoId=' . (int)$uid . '" style="color:#3182ce;text-decoration:none;font-weight:600;">View all &rarr;</a>'
         . '</div>';
}

/* All active institutes, for the filter dropdown (not just ones already
   represented among unassigned students — keeps the list stable/complete
   regardless of how many of an institute's students are already assigned). */
try {
    $instituteOptions = array_column(
        Database::fetchAll("SELECT InstituteName FROM institutes WHERE Active='Y' ORDER BY InstituteName"),
        'InstituteName'
    );
} catch (Exception $e) {
    $instituteOptions = []; // institutes table/migration not present yet
}

/* Student Groups (Admin/StudentGroups.php) — picking one from the dropdown
   below auto-checks every member's row via the existing filter+selectAll
   JS, so an admin can assign a whole group in one click without a separate
   bulk-assign code path. Note this is a genuine assignment (grants access,
   optional due date) — different from Admin/StudentGroupExams.php's
   "Recommended + discount" feature, which never grants access on its own. */
$studentGroups = [];
$userGroupsMap = []; // UserInfoId => [StudentGroupId, ...]
if (Database::tableExists('student_groups') && Database::tableExists('student_group_members')) {
    try {
        $studentGroups = Database::fetchAll(
            "SELECT StudentGroupId, GroupName FROM student_groups ORDER BY GroupName");
        $memberRows = Database::fetchAll("SELECT StudentGroupId, UserInfoId FROM student_group_members");
        foreach ($memberRows as $mr) {
            $userGroupsMap[(int)$mr['UserInfoId']][] = (int)$mr['StudentGroupId'];
        }
    } catch (Exception $e) { $studentGroups = []; $userGroupsMap = []; }
}

/* Groups that directly-assigned (real access grant, migration_v67) this
   exam — shown in its own panel below so an admin can see/undo a
   whole-group assignment without hunting through the per-user list, and so
   it's clear this is a durable group-level grant, not a snapshot. */
$directGroups = StudentGroup::getDirectAssigningGroups($examId);

$pageTitle = 'Assign Exam';
$pageHead  = '<style>
  .user-cb-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #e2e8f0;transition:background .1s;}
  .user-cb-row:hover{background:#f7fafc;}
  .user-cb-row:last-child{border-bottom:none;}
  .badge-assigned {background:#ebf8ff;color:#2b6cb0;padding:3px 10px;border-radius:12px;font-size:.78rem;font-weight:700;}
  .badge-completed{background:#c6efce;color:#276749;padding:3px 10px;border-radius:12px;font-size:.78rem;font-weight:700;}
  .user-name{font-weight:600;color:#1a365d;}
  .user-meta{font-size:.8rem;color:#718096;}
  #selCount{font-weight:700;color:#2b6cb0;}
  .section-hdr{background:#edf2f7;padding:10px 16px;font-weight:700;font-size:.85rem;color:#4a5568;border-bottom:1px solid #e2e8f0;}
</style>';
include __DIR__ . '/../includes/header.php';

[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];
?>

<!-- Breadcrumb -->
<nav style="font-size:.85rem;color:#718096;margin-bottom:10px;">
  <a href="<?php echo htmlspecialchars($backToExamsUrl); ?>" style="color:#3182ce;text-decoration:none;">&#128196; Exams</a>
  <span style="margin:0 6px;">›</span>
  <a href="assign.php?examId=<?php echo $examId; ?>" style="color:#3182ce;text-decoration:none;">&#128101; Assign</a>
  <span style="margin:0 6px;">›</span>
  <span><?php echo htmlspecialchars($exam['ExamName']); ?></span>
</nav>

<?php if ($flashMsg): ?>
<div style="padding:12px 18px;border-radius:6px;margin-bottom:14px;font-weight:600;
     background:<?php echo $flashType==='success'?'#c6efce':'#ffc7ce'; ?>;
     color:<?php echo $flashType==='success'?'#276749':'#c53030'; ?>;">
  <?php echo $flashMsg; ?>
</div>
<?php endif; ?>

<!-- Exam info card -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128101; Assign Exam: <strong><?php echo htmlspecialchars($exam['ExamName']); ?></strong></span>
    <a href="<?php echo htmlspecialchars($backToExamsUrl); ?>" class="btn btn-secondary btn-sm">&#8592; Back to Exams</a>
  </div>
  <div class="card-body" style="display:flex;gap:16px;flex-wrap:wrap;padding:14px 20px;">
    <span style="padding:4px 12px;border-radius:16px;background:#ebf8ff;color:#2b6cb0;font-weight:700;font-size:.85rem;">
      Grade: <?php echo htmlspecialchars($exam['GradeName'] ?? '—'); ?>
    </span>
    <span style="padding:4px 12px;border-radius:16px;background:#faf5ff;color:#6b46c1;font-weight:700;font-size:.85rem;">
      Subject: <?php echo htmlspecialchars($exam['SubjectName'] ?? '—'); ?>
    </span>
    <span style="padding:4px 12px;border-radius:16px;background:#f0fff4;color:#276749;font-weight:700;font-size:.85rem;">
      Questions: <?php echo (int)$exam['NumOfQuestions']; ?>
    </span>
    <span style="padding:4px 12px;border-radius:16px;background:#fff5f5;color:#c53030;font-weight:700;font-size:.85rem;">
      Pass Mark: <?php echo min(100, max(0, (int)$exam['MinPassing'])); ?>%
    </span>
  </div>
</div>

<!-- ── Directly-assigned groups ─────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#128101; Assigned to Groups (<?php echo count($directGroups); ?>)</span>
  </div>
  <div style="padding:10px 20px;font-size:.82rem;color:#718096;border-bottom:1px solid #e2e8f0;">
    A group assignment is durable — it works even before any students have joined, and any student
    added to the group afterwards is assigned this exam automatically.
  </div>
  <?php if (empty($directGroups)): ?>
    <div style="padding:16px 20px;color:#a0aec0;font-size:.85rem;">Not assigned to any group yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="tbl">
      <thead><tr><th>Group</th><th style="width:110px;">Members</th><th style="width:120px;">Assigned On</th><th style="width:110px;">Due Date</th><th style="width:120px;">Action</th></tr></thead>
      <tbody>
        <?php foreach ($directGroups as $i => $dg): ?>
        <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>">
          <td class="user-name"><?php echo htmlspecialchars($dg['GroupName']); ?></td>
          <td class="text-center"><?php echo (int)$dg['MemberCount']; ?></td>
          <td class="text-center" style="font-size:.82rem;color:#718096;">
            <?php echo $dg['AssignedAt'] ? date('d M Y', strtotime($dg['AssignedAt'])) : '—'; ?>
          </td>
          <td class="text-center" style="font-size:.82rem;color:#718096;">
            <?php echo $dg['DueDate'] ? date('d M Y', strtotime($dg['DueDate'])) : '—'; ?>
          </td>
          <td class="text-center">
            <form method="post" onsubmit="return confirm('Unassign this exam from the whole group? Members who haven\'t attempted it yet will lose access.');">
              <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="examId" value="<?php echo $examId; ?>">
              <input type="hidden" name="unassign_group" value="<?php echo (int)$dg['StudentGroupId']; ?>">
              <button type="submit" style="padding:3px 10px;border-radius:4px;border:none;cursor:pointer;background:#e53e3e;color:#fff;font-size:.78rem;font-weight:700;">Unassign Group</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── Already-assigned users ──────────────────────────────────────────── -->
<?php if ($assigned): ?>
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#10004; Currently Assigned (<?php echo count($assigned); ?>)</span>
  </div>
  <form method="post" id="assignedForm"
        onsubmit="return confirmBulkRemove();">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="examId"     value="<?php echo $examId; ?>">

    <div style="padding:10px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#f7fafc;">
      <span style="font-size:.85rem;color:#718096;"><span id="assignedSelCount">0</span> selected</span>
      <button type="button" onclick="selectAllAssigned(true)"  class="btn btn-secondary btn-sm">Select All</button>
      <button type="button" onclick="selectAllAssigned(false)" class="btn btn-secondary btn-sm">Clear</button>
      <button type="submit" style="padding:6px 14px;border-radius:4px;border:none;cursor:pointer;background:#e53e3e;color:#fff;font-size:.82rem;font-weight:700;">
        Remove Selected
      </button>
    </div>

    <div style="overflow-x:auto;">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:36px;"></th>
            <th>Name</th>
            <th>Login</th>
            <th style="width:100px;">Current Status</th>
            <th style="width:170px;">Attempt History</th>
            <th style="width:120px;">Assigned On</th>
            <th style="width:110px;">Due Date</th>
            <th style="width:150px;">Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($assigned as $i => $u): ?>
        <tr class="<?php echo ($i%2===0)?'odd':'even'; ?>">
          <td class="text-center">
            <?php if ($u['AssignStatus'] !== 'Completed'): ?>
            <input type="checkbox" name="unassign_ids[]" value="<?php echo (int)$u['AssignmentId']; ?>"
                   onchange="updateAssignedCount()" style="transform:scale(1.2);accent-color:#e53e3e;">
            <?php endif; ?>
          </td>
          <td class="user-name">
            <?php echo htmlspecialchars(trim($u['FstName'].' '.$u['LstName'])); ?>
          </td>
          <td class="user-meta"><?php echo htmlspecialchars($u['LoginName'] ?? ''); ?></td>
          <td class="text-center">
            <?php if ($u['AssignStatus']==='Completed'): ?>
              <span class="badge-completed">&#10004; Completed</span>
            <?php else: ?>
              <span class="badge-assigned">&#9679; Assigned</span>
            <?php endif; ?>
          </td>
          <td>
            <?php echo assign_render_history($examId, (int)$u['UserInfoId'], $historyByUser); ?>
          </td>
          <td class="text-center" style="font-size:.82rem;color:#718096;">
            <?php echo $u['AssignedAt'] ? date('d M Y', strtotime($u['AssignedAt'])) : '—'; ?>
          </td>
          <td class="text-center" style="font-size:.82rem;color:<?php echo ($u['DueDate'] && $u['DueDate'] < date('Y-m-d')) ? '#c53030' : '#718096'; ?>;">
            <?php echo $u['DueDate'] ? date('d M Y', strtotime($u['DueDate'])) : '—'; ?>
          </td>
          <td class="text-center">
            <?php if ($u['AssignStatus'] !== 'Completed'): ?>
            <button type="button"
                    onclick="removeSingle(<?php echo (int)$u['AssignmentId']; ?>, '<?php echo htmlspecialchars(addslashes($u['FstName'].' '.$u['LstName']), ENT_QUOTES); ?>')"
                    style="padding:3px 10px;border-radius:4px;border:none;cursor:pointer;background:#e53e3e;color:#fff;font-size:.78rem;font-weight:700;">
              Remove
            </button>
            <?php else: ?>
              <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                <?php if ($u['StudentExamId']): ?>
                <a href="result.php?id=<?php echo (int)$u['StudentExamId']; ?>"
                   style="padding:3px 10px;border-radius:4px;font-size:.78rem;font-weight:700;background:#3182ce;color:#fff;text-decoration:none;">
                  View
                </a>
                <?php endif; ?>
                <button type="button"
                        onclick="reassignSingle(<?php echo (int)$u['UserInfoId']; ?>, '<?php echo htmlspecialchars(addslashes($u['FstName'].' '.$u['LstName']), ENT_QUOTES); ?>')"
                        title="Re-assign this exam for a new attempt — their previous result stays in Attempt History"
                        style="padding:3px 10px;border-radius:4px;border:none;cursor:pointer;background:#276749;color:#fff;font-size:.78rem;font-weight:700;">
                  &#8635; Re-assign
                </button>
              </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Hidden single-user re-assign form — reuses the same bulk-assign POST
     branch (user_ids[]) that already knows how to re-open a Completed
     assignment; only the UI to reach it for a single row was missing. -->
<form method="post" id="reassignForm" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
  <input type="hidden" name="examId"     value="<?php echo $examId; ?>">
  <input type="hidden" name="user_ids[]" id="reassignUserId" value="">
</form>

<!-- Standalone form for "Assign Entire Group" — deliberately separate from
     #assignForm (user_ids[]) and NOT nested inside it (nested <form> is
     invalid HTML): it never depends on which rows are currently
     checked/visible, so it works even when the selected group has zero
     current members. -->
<form method="post" id="assignGroupForm" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
  <input type="hidden" name="examId" value="<?php echo $examId; ?>">
  <input type="hidden" name="assign_group" id="assignGroupId" value="">
  <input type="hidden" name="group_due_date" id="assignGroupDueDate" value="">
</form>

<!-- ── Assign to more users ─────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">&#128101; Assign to Users</div>
  <form method="post" id="assignForm">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="examId"     value="<?php echo $examId; ?>">

    <!-- Controls bar -->
    <div style="padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#f7fafc;">
      <label style="font-size:.85rem;font-weight:600;color:#4a5568;">
        Due Date (optional):
        <input type="date" name="due_date" style="margin-left:6px;padding:4px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:.85rem;">
      </label>
      <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <span style="font-size:.85rem;color:#718096;"><span id="selCount">0</span> selected</span>
        <button type="button" onclick="selectAll(true)"  class="btn btn-secondary btn-sm">Select All</button>
        <button type="button" onclick="selectAll(false)" class="btn btn-secondary btn-sm">Clear</button>
        <button type="submit" class="btn btn-success" style="font-weight:700;">
          &#10003; Assign Selected
        </button>
      </div>
    </div>

    <!-- Search box -->
    <div style="padding:10px 16px;border-bottom:1px solid #e2e8f0;display:flex;gap:10px;flex-wrap:wrap;">
      <input type="text" id="userSearch" placeholder="&#128269; Search by name or login…"
             oninput="filterUsers()"
             style="flex:1;min-width:220px;max-width:360px;padding:7px 12px;border:1px solid #cbd5e0;border-radius:6px;font-size:.88rem;">
      <select id="instituteFilter" onchange="filterUsers()"
              style="padding:7px 12px;border:1px solid #cbd5e0;border-radius:6px;font-size:.88rem;min-width:200px;">
        <option value="">&#127979; All Institutes</option>
        <?php foreach ($instituteOptions as $name): ?>
        <option value="<?php echo strtolower(htmlspecialchars($name)); ?>"><?php echo htmlspecialchars($name); ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($studentGroups)): ?>
      <select id="groupFilter" onchange="filterUsers(); selectAll(true); toggleAssignGroupBtn();"
              style="padding:7px 12px;border:1px solid #a5b4fc;border-radius:6px;font-size:.88rem;min-width:220px;background:#eef2ff;color:#3730a3;font-weight:600;"
              title="Filters the list to this group's current members and checks all of them for a one-off assignment.">
        <option value="">&#128101; Assign a Student Group…</option>
        <?php foreach ($studentGroups as $g): ?>
        <option value="<?php echo (int)$g['StudentGroupId']; ?>"><?php echo htmlspecialchars($g['GroupName']); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" id="assignGroupBtn" onclick="submitAssignGroup()" disabled
              title="Assigns the exam to the whole group as a durable grant — works even with zero current members, and future members are covered automatically."
              style="padding:7px 14px;border-radius:6px;border:none;font-size:.85rem;font-weight:700;
                     background:#4f46e5;color:#fff;cursor:pointer;opacity:.5;">
        &#10003; Assign Entire Group
      </button>
      <?php endif; ?>
    </div>

    <!-- User list -->
    <?php if (empty($unassigned)): ?>
    <div style="padding:30px;text-align:center;color:#718096;">
      All users have already been assigned this exam.
    </div>
    <?php else: ?>
    <div id="userList">
      <?php foreach ($unassigned as $u):
        $fullName    = trim($u['FstName'].' '.$u['LstName']);
        $instituteNm = trim($u['InstituteName'] ?? '');
      ?>
      <div class="user-cb-row user-item"
           data-search="<?php echo strtolower(htmlspecialchars($fullName.' '.$u['LoginName'])); ?>"
           data-institute="<?php echo strtolower(htmlspecialchars($instituteNm)); ?>"
           data-groups="<?php echo implode(',', $userGroupsMap[(int)$u['UserInfoId']] ?? []); ?>">
        <input type="checkbox" name="user_ids[]" value="<?php echo (int)$u['UserInfoId']; ?>"
               id="uid<?php echo (int)$u['UserInfoId']; ?>"
               onchange="updateCount()"
               style="transform:scale(1.3);accent-color:#3182ce;flex-shrink:0;">
        <label for="uid<?php echo (int)$u['UserInfoId']; ?>" style="cursor:pointer;flex:1;display:flex;align-items:center;gap:12px;">
          <div style="width:36px;height:36px;border-radius:50%;background:#ebf8ff;color:#2b6cb0;
                      display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.9rem;flex-shrink:0;">
            <?php echo strtoupper(substr($u['FstName'],0,1).substr($u['LstName'],0,1)); ?>
          </div>
          <div>
            <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
            <div class="user-meta">
              <?php echo htmlspecialchars($u['LoginName'] ?? ''); ?>
              <?php if ($u['Role']): ?>
                &middot; <em><?php echo htmlspecialchars($u['Role']); ?></em>
              <?php endif; ?>
              <?php if ($instituteNm): ?>
                &middot; &#127979; <?php echo htmlspecialchars($instituteNm); ?>
              <?php endif; ?>
              <?php $uh = $historyByUser[(int)$u['UserInfoId']] ?? null; if ($uh): ?>
                &middot; <a href="history.php?InfoId=<?php echo (int)$examId; ?>&UserInfoId=<?php echo (int)$u['UserInfoId']; ?>"
                   style="color:#b7791f;font-weight:600;text-decoration:none;"
                   title="Attempted this exam before, under an earlier assignment"
                   onclick="event.stopPropagation();">
                  &#128340; <?php echo (int)$uh['count']; ?> prior attempt<?php echo (int)$uh['count'] !== 1 ? 's' : ''; ?>
                  <?php if ($uh['lastPct'] !== null): ?>(last <?php echo $uh['lastPct']; ?>%)<?php endif; ?>
                </a>
              <?php endif; ?>
            </div>
          </div>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </form>
</div>

<script>
function updateCount() {
  var n = document.querySelectorAll('#userList input[type=checkbox]:checked').length;
  document.getElementById('selCount').textContent = n;
}
function selectAll(checked) {
  // NOTE: was `:not([style*="display:none"])` — a CSS attribute *substring*
  // match. Browsers serialize `el.style.display = 'none'` back into the
  // inline style attribute as "display: none;" WITH a space after the
  // colon, so that selector's literal "display:none" (no space) never
  // matched anything — :not(...) was therefore always true, and this
  // selected EVERY checkbox in #userList regardless of search/institute/
  // group filtering. That's why picking a Student Group (which auto-calls
  // selectAll(true) on change) selected all unassigned users instead of
  // just that group's members. Fixed by checking the actual DOM display
  // state in JS instead of pattern-matching the serialized style string.
  document.querySelectorAll('#userList .user-item').forEach(function(row){
    if (row.style.display === 'none') return; // hidden by the current filter — skip
    var cb = row.querySelector('input[type=checkbox]');
    if (cb) cb.checked = checked;
  });
  updateCount();
}
function filterUsers() {
  var q     = document.getElementById('userSearch').value.toLowerCase();
  var inst  = document.getElementById('instituteFilter').value;
  var grpEl = document.getElementById('groupFilter');
  var grp   = grpEl ? grpEl.value : '';
  document.querySelectorAll('.user-item').forEach(function(row){
    var matchText  = !q || row.dataset.search.includes(q);
    var matchInst  = !inst || row.dataset.institute === inst;
    var matchGroup = !grp || (row.dataset.groups || '').split(',').indexOf(grp) !== -1;
    row.style.display = (matchText && matchInst && matchGroup) ? '' : 'none';
  });
  updateCount();
}

/* ── Assign Entire Group (durable, works with zero current members) ──── */
function toggleAssignGroupBtn() {
  var grpEl = document.getElementById('groupFilter');
  var btn   = document.getElementById('assignGroupBtn');
  if (!grpEl || !btn) return;
  var has = !!grpEl.value;
  btn.disabled = !has;
  btn.style.opacity = has ? '1' : '.5';
}
function submitAssignGroup() {
  var grpEl = document.getElementById('groupFilter');
  if (!grpEl || !grpEl.value) return;
  var groupName = grpEl.options[grpEl.selectedIndex].text;
  if (!confirm('Assign this exam to the entire group "' + groupName + '"?\n\nThis works even if the group has no members yet — anyone added to it later will be assigned automatically.')) return;
  var dueDateEl = document.querySelector('input[name="due_date"]');
  document.getElementById('assignGroupId').value = grpEl.value;
  document.getElementById('assignGroupDueDate').value = dueDateEl ? dueDateEl.value : '';
  document.getElementById('assignGroupForm').submit();
}

/* ── Bulk-remove (Currently Assigned) ───────────────────────────────── */
function assignedCheckboxes() {
  return document.querySelectorAll('#assignedForm input[name="unassign_ids[]"]');
}
function updateAssignedCount() {
  var n = document.querySelectorAll('#assignedForm input[name="unassign_ids[]"]:checked').length;
  document.getElementById('assignedSelCount').textContent = n;
}
function selectAllAssigned(checked) {
  assignedCheckboxes().forEach(function(cb){ cb.checked = checked; });
  updateAssignedCount();
}
function confirmBulkRemove() {
  var n = document.querySelectorAll('#assignedForm input[name="unassign_ids[]"]:checked').length;
  if (n === 0) { alert('Select at least one user to remove.'); return false; }
  return confirm('Remove ' + n + ' selected assignment(s)?');
}
function removeSingle(assignmentId, name) {
  if (!confirm('Remove assignment for ' + name + '?')) return;
  var form = document.getElementById('assignedForm');
  // Uncheck everything else so only this one is submitted, bypassing the bulk-confirm.
  assignedCheckboxes().forEach(function(cb){ cb.checked = (cb.value == assignmentId); });
  form.submit(); // programmatic submit does NOT fire onsubmit, so confirmBulkRemove() is skipped
}

/* ── Re-assign a Completed student (any number of times) ─────────────── */
function reassignSingle(userId, name) {
  if (!confirm('Re-assign this exam to ' + name + ' for a new attempt?\n\nTheir previous result stays available in Attempt History.')) return;
  document.getElementById('reassignUserId').value = userId;
  document.getElementById('reassignForm').submit();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
