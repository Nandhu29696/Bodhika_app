<?php
/**
 * Admin/ExamAttemptOverrides.php
 * Manage per-student max-attempt overrides for an exam (migration_v36).
 *
 * Precedence (highest wins):
 *   1. exam_attempt_overrides row for (ExamInfoId, UserInfoId) — set here
 *   2. examinfo.MaxAttempts — set in exam/manage.php
 *   3. hard default of 5
 * MaxAttempts = 0 means unlimited attempts.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$msg     = '';
$msgType = 'success';
$action  = $_POST['action'] ?? '';
$adminName = Auth::currentUser();

/* ── Handle set/update override ──────────────────────────────────────────── */
if ($action === 'set_override') {
    Auth::validateCsrf();
    $examId = (int)($_POST['ExamInfoId'] ?? 0);
    $userId = (int)($_POST['UserInfoId'] ?? 0);
    $maxAtt = max(0, (int)($_POST['MaxAttempts'] ?? 0));
    $note   = trim($_POST['OverrideNote'] ?? '');

    if ($examId > 0 && $userId > 0) {
        try {
            Database::execute(
                "INSERT INTO exam_attempt_overrides (ExamInfoId, UserInfoId, MaxAttempts, OverrideBy, OverrideNote)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     MaxAttempts  = VALUES(MaxAttempts),
                     OverrideBy   = VALUES(OverrideBy),
                     OverrideNote = VALUES(OverrideNote),
                     UpdatedAt    = CURRENT_TIMESTAMP",
                [$examId, $userId, $maxAtt, $adminName, $note]);
            $msg = 'Override saved.';
        } catch (Exception $e) {
            $msg = 'Could not save override — has migration_v36.sql been run? (' . $e->getMessage() . ')';
            $msgType = 'danger';
        }
    } else {
        $msg = 'Please select both an exam and a student.'; $msgType = 'danger';
    }
} elseif ($action === 'remove_override') {
    Auth::validateCsrf();
    $overrideId = (int)($_POST['OverrideId'] ?? 0);
    if ($overrideId > 0) {
        try {
            Database::execute("DELETE FROM exam_attempt_overrides WHERE OverrideId = ?", [$overrideId]);
            $msg = 'Override removed — student reverts to the exam default.';
        } catch (Exception $e) {
            $msg = 'Error removing override: ' . $e->getMessage(); $msgType = 'danger';
        }
    }
}

/* ── Pagination helpers (mirrors AdminUsers.php / StudentGroupMembers.php) ── */
const PAGE_SIZE = 25;
function currentPage(string $key): int {
    return max(1, (int)($_GET[$key] ?? 1));
}
function paginator(int $total, int $current, int $pageSize, array $qs, string $pageKey): string {
    if ($total <= $pageSize) return '';
    $pages = (int)ceil($total / $pageSize);
    $html  = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $q    = array_merge($qs, [$pageKey => $i]);
        $url  = '?' . http_build_query($q);
        $cls  = $i === $current ? 'pager-active' : 'pager-link';
        $html .= "<a href=\"{$url}\" class=\"{$cls}\">{$i}</a> ";
    }
    return $html . '</div>';
}

/* ── Lookups ──────────────────────────────────────────────────────────────── */
$exams = Database::fetchAll(
    "SELECT ExamInfoId, ExamName, MaxAttempts FROM examinfo ORDER BY ExamName");

$hasOverridesTable = Database::tableExists('exam_attempt_overrides');

$filterExamId = (int)($_GET['examId'] ?? 0);
$filterName   = trim($_GET['name'] ?? '');
$rowsPage     = currentPage('p');

$selectedExam = null;
foreach ($exams as $e) {
    if ((int)$e['ExamInfoId'] === $filterExamId) { $selectedExam = $e; break; }
}

/* ── Build the per-student row set for the selected exam ─────────────────────
   Was: fetch EVERY registered student (1600+, no LIMIT) plus every attempt
   row and every override row for this exam, then intersect/search/sort in
   PHP. Now: the candidate set (attempted OR overridden for this exam),
   search, and sort all happen in SQL, with LIMIT/OFFSET doing the paging —
   this page never has to hold more than PAGE_SIZE student rows in memory. */
$rows      = [];
$rowsTotal = 0;
if ($selectedExam) {
    $defaultMax = (int)($selectedExam['MaxAttempts'] ?? 5);

    $candidateSQL = $hasOverridesTable
        ? "(SELECT UserInfoId FROM studentexam WHERE ExamInfoId = ?
            UNION SELECT UserInfoId FROM exam_attempt_overrides WHERE ExamInfoId = ?)"
        : "(SELECT UserInfoId FROM studentexam WHERE ExamInfoId = ?)";
    $params = $hasOverridesTable ? [$filterExamId, $filterExamId] : [$filterExamId];

    $overrideCols  = $hasOverridesTable
        ? ", eao.OverrideId, eao.MaxAttempts AS OverrideMax, eao.OverrideBy, eao.OverrideNote"
        : ", NULL AS OverrideId, NULL AS OverrideMax, NULL AS OverrideBy, NULL AS OverrideNote";
    $overrideJoin  = '';
    $overrideOrder = '';

    $sql = "FROM {$candidateSQL} relevant
             JOIN userinfo   u  ON u.UserInfoId = relevant.UserInfoId
             JOIN logininfo  li ON li.LoginName = u.LoginName
        LEFT JOIN (SELECT UserInfoId, COUNT(*) AS c FROM studentexam WHERE ExamInfoId = ? GROUP BY UserInfoId) se_cnt
               ON se_cnt.UserInfoId = u.UserInfoId";
    $params[] = $filterExamId;

    if ($hasOverridesTable) {
        $sql .= " LEFT JOIN exam_attempt_overrides eao ON eao.ExamInfoId = ? AND eao.UserInfoId = u.UserInfoId";
        $params[]      = $filterExamId;
        $overrideOrder = '(eao.OverrideId IS NOT NULL) DESC, ';
    }

    if ($filterName !== '') {
        $sql     .= " WHERE (u.FstName LIKE ? OR u.LstName LIKE ? OR li.LoginName LIKE ?)";
        $like     = "%{$filterName}%";
        array_push($params, $like, $like, $like);
    }

    $rowsTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$sql}", $params)['cnt'] ?? 0);

    $offset = ($rowsPage - 1) * PAGE_SIZE;
    $result = Database::fetchAll(
        "SELECT u.UserInfoId, u.FstName, u.LstName, li.LoginName,
                COALESCE(se_cnt.c, 0) AS UsedCount {$overrideCols}
         {$sql}
         ORDER BY {$overrideOrder} UsedCount DESC, u.FstName, u.LstName
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );

    foreach ($result as $r) {
        $ov  = $r['OverrideId'] !== null
            ? ['OverrideId' => $r['OverrideId'], 'MaxAttempts' => $r['OverrideMax'], 'OverrideBy' => $r['OverrideBy'], 'OverrideNote' => $r['OverrideNote']]
            : null;
        $eff = $ov ? (int)$ov['MaxAttempts'] : $defaultMax;
        $rows[] = [
            'student'      => ['UserInfoId' => $r['UserInfoId'], 'FstName' => $r['FstName'], 'LstName' => $r['LstName'], 'LoginName' => $r['LoginName']],
            'used'         => (int)$r['UsedCount'],
            'override'     => $ov,
            'effectiveMax' => $eff,
            'unlimited'    => ($eff <= 0),
        ];
    }
}
$qsRows = array_filter(['examId' => $filterExamId, 'name' => $filterName]);

/* ── "Select student" dropdown for pre-emptively granting an override to a
   student with no attempt yet — reuses the Student Name/Login search box
   above so it's never an unbounded 1600+-option <select>; with no search
   typed yet it's capped at 100 so the page still loads fast. */
$dropdownStudents = [];
if ($selectedExam) {
    $ddWhere  = ["li.Role = 'STDNT'"];
    $ddParams = [];
    if ($filterName !== '') {
        $ddWhere[] = '(u.FstName LIKE ? OR u.LstName LIKE ? OR li.LoginName LIKE ?)';
        $like      = "%{$filterName}%";
        array_push($ddParams, $like, $like, $like);
    }
    $dropdownStudents = Database::fetchAll(
        "SELECT u.UserInfoId, u.FstName, u.LstName, li.LoginName
           FROM userinfo u JOIN logininfo li ON li.LoginName = u.LoginName
          WHERE " . implode(' AND ', $ddWhere) . "
          ORDER BY u.FstName, u.LstName
          LIMIT 100",
        $ddParams
    );
}

/* ── Global overrides overview (shown when no exam is selected) ──────────── */
$allOverrides      = [];
$allOverridesTotal = 0;
$ovSearch          = trim($_GET['oq'] ?? '');
$ovPage            = currentPage('op');
if (!$selectedExam && $hasOverridesTable) {
    $where  = [];
    $params = [];
    if ($ovSearch !== '') {
        $where[]  = '(e.ExamName LIKE ? OR u.FstName LIKE ? OR u.LstName LIKE ? OR li.LoginName LIKE ?)';
        $like     = "%{$ovSearch}%";
        array_push($params, $like, $like, $like, $like);
    }
    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $baseSQL  = "FROM exam_attempt_overrides eao
                   JOIN examinfo   e  ON e.ExamInfoId  = eao.ExamInfoId
              LEFT JOIN userinfo   u  ON u.UserInfoId  = eao.UserInfoId
              LEFT JOIN logininfo  li ON li.LoginName  = u.LoginName
                 {$whereSQL}";

    $allOverridesTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

    $offset       = ($ovPage - 1) * PAGE_SIZE;
    $allOverrides = Database::fetchAll(
        "SELECT eao.*, e.ExamName, u.FstName, u.LstName, li.LoginName
         {$baseSQL}
         ORDER BY eao.UpdatedAt DESC
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}
$qsOverrides = array_filter(['oq' => $ovSearch]);

$pageTitle = 'Exam Attempt Overrides';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .limit-badge   { display:inline-block;padding:2px 10px;border-radius:10px;font-size:.76rem;font-weight:700; }
  .filter-bar    { display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px; }
  .filter-bar .form-group { margin-bottom:0;min-width:180px; }
  .section-card  { margin-bottom:20px; }
  details summary { cursor:pointer;font-weight:600;color:#1e3a5f;user-select:none; }
  details[open] summary { margin-bottom:12px; }
  .pager        { margin:10px 0; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
  .pager-link   { display:inline-block; padding:3px 10px; border:1px solid #cbd5e1; border-radius:4px;
                   text-decoration:none; color:#475569; }
  .pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
  .pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                   background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }
</style>

<?php if ($msg): ?>
  <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:16px;">
    <?php echo htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<!-- ── Exam selector ──────────────────────────────────────────────────────── -->
<form method="get" action="ExamAttemptOverrides.php" class="filter-bar">
  <div class="form-group" style="flex:1;">
    <label>Exam</label>
    <select name="examId" class="form-control" onchange="this.form.submit()">
      <option value="0">— Select an exam to manage —</option>
      <?php foreach ($exams as $e): ?>
        <option value="<?php echo (int)$e['ExamInfoId']; ?>"
          <?php echo $filterExamId === (int)$e['ExamInfoId'] ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($e['ExamName']); ?>
          (default: <?php echo (int)($e['MaxAttempts'] ?? 5) ?: 'Unlimited'; ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($selectedExam): ?>
    <div class="form-group">
      <label>Student Name / Login</label>
      <input type="text" name="name" class="form-control" placeholder="Search…"
             value="<?php echo htmlspecialchars($filterName); ?>">
    </div>
    <div>
      <button type="submit" class="btn btn-primary">&#128269; Filter</button>
      <a href="ExamAttemptOverrides.php?examId=<?php echo $filterExamId; ?>" class="btn btn-secondary" style="margin-left:6px;">Clear</a>
    </div>
  <?php endif; ?>
</form>

<?php if (!$selectedExam): ?>

  <div class="card section-card">
    <div class="card-header">&#128260; All Active Overrides
      <span style="font-size:.8rem;font-weight:400;color:#718096;">(across every exam)</span>
    </div>

    <?php if (!$hasOverridesTable): ?>
      <p style="padding:20px;color:#718096;text-align:center;">
        Overrides aren't set up yet — has migration_v36.sql been run?
      </p>
    <?php else: ?>
    <form method="get" action="ExamAttemptOverrides.php" style="padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#f7fafc;">
      <input type="text" name="oq" value="<?php echo htmlspecialchars($ovSearch); ?>" class="form-control"
             placeholder="&#128269; Search by exam, student, or login…" style="flex:1;min-width:220px;max-width:360px;">
      <button type="submit" class="btn btn-secondary btn-sm">Search</button>
      <?php if ($ovSearch !== ''): ?>
        <a href="ExamAttemptOverrides.php" class="btn btn-secondary btn-sm">Clear</a>
      <?php endif; ?>
    </form>
    <?php if ($ovSearch !== ''): ?>
      <p style="padding:8px 16px 0;font-size:.8rem;color:#718096;">Found <?php echo $allOverridesTotal; ?> override(s) matching “<?php echo htmlspecialchars($ovSearch); ?>”.</p>
    <?php endif; ?>
    <div class="card-body" style="padding:0;">
      <?php if (empty($allOverrides)): ?>
        <p style="padding:20px;color:#718096;text-align:center;">
          <?php echo $ovSearch !== '' ? 'No overrides match your search.' : 'No per-student overrides set yet. Select an exam above to add one.'; ?>
        </p>
      <?php else: ?>
      <table class="tbl" style="font-size:.82rem;">
        <thead>
          <tr>
            <th>Exam</th>
            <th>Student</th>
            <th class="text-center">Max Attempts</th>
            <th>Note</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allOverrides as $i => $ov):
            $uid = (int)$ov['UserInfoId'];
          ?>
          <tr class="<?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">
            <td>
              <a href="ExamAttemptOverrides.php?examId=<?php echo (int)$ov['ExamInfoId']; ?>">
                <?php echo htmlspecialchars($ov['ExamName']); ?>
              </a>
            </td>
            <td>
              <?php echo $ov['FstName'] !== null ? htmlspecialchars(trim($ov['FstName'] . ' ' . $ov['LstName'])) : 'User #' . $uid; ?>
              <?php if ($ov['FstName'] !== null): ?>
                <br><span style="color:#6b7280;font-size:.74rem;"><?php echo htmlspecialchars($ov['LoginName'] ?? ''); ?></span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="limit-badge" style="background:#eef2ff;color:#3730a3;">
                <?php echo ((int)$ov['MaxAttempts'] <= 0) ? 'Unlimited' : (int)$ov['MaxAttempts']; ?>
              </span>
            </td>
            <td style="color:#6b7280;font-size:.78rem;"><?php echo htmlspecialchars($ov['OverrideNote'] ?: '—'); ?></td>
            <td style="font-size:.76rem;color:#6b7280;white-space:nowrap;">
              <?php echo date('d M y', strtotime($ov['UpdatedAt'])); ?>
            </td>
            <td>
              <form method="post" action="ExamAttemptOverrides.php" style="display:inline;"
                    onsubmit="return confirm('Remove this override?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="remove_override">
                <input type="hidden" name="OverrideId" value="<?php echo (int)$ov['OverrideId']; ?>">
                <button type="submit" class="btn btn-secondary btn-xs">&#10005; Remove</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php echo paginator($allOverridesTotal, $ovPage, PAGE_SIZE, $qsOverrides, 'op'); ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="card section-card">
    <div class="card-header">
      &#10010; Add / Update Override — <?php echo htmlspecialchars($selectedExam['ExamName']); ?>
    </div>
    <div class="card-body">
      <p style="font-size:.82rem;color:#6b7280;margin-top:0;">
        Exam default is currently
        <strong><?php echo ((int)($selectedExam['MaxAttempts'] ?? 5) <= 0) ? 'Unlimited' : (int)$selectedExam['MaxAttempts']; ?></strong>
        attempts. An override here applies only to the chosen student, for this exam, and wins over the default.
      </p>
      <form method="post" action="ExamAttemptOverrides.php?examId=<?php echo $filterExamId; ?>" style="max-width:680px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
        <input type="hidden" name="action" value="set_override">
        <input type="hidden" name="ExamInfoId" value="<?php echo $filterExamId; ?>">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:0 16px;">
          <div class="form-group">
            <label>Student
              <small style="font-weight:400;color:#6b7280;">
                <?php echo $filterName !== ''
                    ? '(filtered by the search box above)'
                    : '(showing first 100 — use the search box above to find someone else)'; ?>
              </small>
            </label>
            <select name="UserInfoId" class="form-control" required>
              <option value="">— Select student —</option>
              <?php foreach ($dropdownStudents as $st): ?>
                <option value="<?php echo (int)$st['UserInfoId']; ?>">
                  <?php echo htmlspecialchars(trim($st['FstName'] . ' ' . $st['LstName'])); ?>
                  (<?php echo htmlspecialchars($st['LoginName']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Max Attempts <span style="color:#6b7280;font-size:.75rem;">(0 = unlimited)</span></label>
            <input type="number" name="MaxAttempts" class="form-control" min="0" max="999" value="5" required>
          </div>
        </div>
        <div class="form-group">
          <label>Note</label>
          <input type="text" name="OverrideNote" class="form-control" maxlength="255"
                 placeholder="e.g. Granted extra attempt after technical issue">
        </div>
        <button type="submit" class="btn btn-success btn-sm">&#128190; Save Override</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      &#128202; Students — <?php echo htmlspecialchars($selectedExam['ExamName']); ?>
      <span style="font-size:.8rem;font-weight:400;color:#718096;">(<?php echo $rowsTotal; ?> total — students with an override or at least one attempt<?php echo $filterName !== '' ? ', matching “' . htmlspecialchars($filterName) . '”' : ''; ?>)</span>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if (empty($rows)): ?>
        <p style="padding:20px;color:#718096;text-align:center;">
          <?php echo $filterName !== ''
              ? 'No students match your search.'
              : 'No students have attempted this exam yet, and no overrides are set. Use the form above to add one.'; ?>
        </p>
      <?php else: ?>
      <table class="tbl" style="font-size:.82rem;">
        <thead>
          <tr>
            <th>Student</th>
            <th class="text-center">Attempts Used</th>
            <th class="text-center">Current Limit</th>
            <th>Set By / Note</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $r):
            $s  = $r['student'];
            $ov = $r['override'];
          ?>
          <tr class="<?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">
            <td>
              <strong><?php echo htmlspecialchars(trim($s['FstName'] . ' ' . $s['LstName'])); ?></strong>
              <br><span style="color:#6b7280;font-size:.76rem;"><?php echo htmlspecialchars($s['LoginName']); ?></span>
            </td>
            <td class="text-center">
              <span style="font-weight:700;color:<?php echo (!$r['unlimited'] && $r['used'] >= $r['effectiveMax']) ? '#c53030' : '#374151'; ?>;">
                <?php echo (int)$r['used']; ?>
              </span>
            </td>
            <td class="text-center">
              <?php if ($ov): ?>
                <span class="limit-badge" style="background:#eef2ff;color:#3730a3;" title="Per-student override">
                  &#9733; <?php echo $r['unlimited'] ? 'Unlimited' : $r['effectiveMax']; ?>
                </span>
              <?php else: ?>
                <span class="limit-badge" style="background:#f3f4f6;color:#4b5563;" title="Exam default — no override set">
                  <?php echo $r['unlimited'] ? 'Unlimited' : $r['effectiveMax']; ?> (default)
                </span>
              <?php endif; ?>
            </td>
            <td style="font-size:.76rem;color:#6b7280;">
              <?php if ($ov): ?>
                <?php echo htmlspecialchars($ov['OverrideBy'] ?: '—'); ?>
                <?php if ($ov['OverrideNote']): ?>
                  <br><span title="<?php echo htmlspecialchars($ov['OverrideNote']); ?>">
                    &#128196; <?php echo htmlspecialchars(mb_substr($ov['OverrideNote'], 0, 30)); ?>
                  </span>
                <?php endif; ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <button type="button" class="btn btn-warning btn-xs"
                      onclick="openOverride(<?php echo (int)$s['UserInfoId']; ?>,
                                             '<?php echo addslashes(trim($s['FstName'] . ' ' . $s['LstName'])); ?>',
                                             <?php echo $ov ? (int)$ov['MaxAttempts'] : (int)($selectedExam['MaxAttempts'] ?? 5); ?>,
                                             '<?php echo $ov ? addslashes($ov['OverrideNote'] ?? '') : ''; ?>')">
                &#9998; <?php echo $ov ? 'Edit' : 'Override'; ?>
              </button>
              <?php if ($ov): ?>
                <form method="post" action="ExamAttemptOverrides.php?examId=<?php echo $filterExamId; ?>" style="display:inline;"
                      onsubmit="return confirm('Remove this override? The student will revert to the exam default.');">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                  <input type="hidden" name="action" value="remove_override">
                  <input type="hidden" name="OverrideId" value="<?php echo (int)$ov['OverrideId']; ?>">
                  <button type="submit" class="btn btn-secondary btn-xs">&#10005; Remove</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php echo paginator($rowsTotal, $rowsPage, PAGE_SIZE, $qsRows, 'p'); ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Edit modal ───────────────────────────────────────────────────────── -->
  <div id="overrideModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;
       align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:28px 32px;max-width:440px;width:94%;
                box-shadow:0 8px 32px rgba(0,0,0,.22);">
      <h3 id="modalTitle" style="margin:0 0 16px;font-size:1.05rem;color:#1e3a5f;">&#9998; Set Override</h3>
      <form method="post" action="ExamAttemptOverrides.php?examId=<?php echo $filterExamId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
        <input type="hidden" name="action"      value="set_override">
        <input type="hidden" name="ExamInfoId"  value="<?php echo $filterExamId; ?>">
        <input type="hidden" name="UserInfoId"  id="modalUserId" value="">

        <div class="form-group">
          <label>Max Attempts <span style="color:#6b7280;font-size:.75rem;">(0 = unlimited)</span></label>
          <input type="number" name="MaxAttempts" id="modalMaxAttempts" class="form-control" min="0" max="999" required>
        </div>
        <div class="form-group">
          <label>Note</label>
          <input type="text" name="OverrideNote" id="modalNote" class="form-control" maxlength="255"
                 placeholder="Reason for override…">
        </div>
        <div style="display:flex;gap:10px;margin-top:16px;">
          <button type="submit" class="btn btn-primary">&#128190; Save</button>
          <button type="button" class="btn btn-secondary" onclick="closeOverride()">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  function openOverride(userId, name, currentMax, note) {
    document.getElementById('modalTitle').textContent       = '✎ Set Override — ' + name;
    document.getElementById('modalUserId').value             = userId;
    document.getElementById('modalMaxAttempts').value        = currentMax;
    document.getElementById('modalNote').value                = note;
    document.getElementById('overrideModal').style.display   = 'flex';
  }
  function closeOverride() {
    document.getElementById('overrideModal').style.display = 'none';
  }
  document.getElementById('overrideModal').addEventListener('click', function (e) {
    if (e.target === this) closeOverride();
  });
  </script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
