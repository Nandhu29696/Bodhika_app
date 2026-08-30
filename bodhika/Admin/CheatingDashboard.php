<?php
/**
 * Admin/CheatingDashboard.php — Cheating Detection Dashboard
 *
 * Aggregated view of suspicious behaviour per student per exam:
 *   • Tab Switches    — left exam tab
 *   • Copy / Paste    — attempted copy-paste during exam
 *   • Browser Refresh — navigated away / refreshed
 *   • Multiple Logins — logged in >1 × on the same day (from logintrackinfo)
 *
 * Filters: exam, date range, severity threshold.
 * Requires: migration_v29.sql (exam_events table).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

/* ── Filters ──────────────────────────────────────────────────────────── */
$fExam      = filter_input(INPUT_GET, 'exam',      FILTER_VALIDATE_INT) ?: 0;
$fDateFrom  = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days')));
$fDateTo    = trim($_GET['date_to']   ?? date('Y-m-d'));
$fThreshold = max(1, (int)($_GET['threshold'] ?? 3));
$fType      = trim($_GET['event_type'] ?? '');

/* ── WHERE for exam_events ────────────────────────────────────────────── */
$where  = ['DATE(ee.LastEventAt) BETWEEN ? AND ?'];
$params = [$fDateFrom, $fDateTo];

if ($fExam > 0) {
    $where[]  = 'ee.ExamInfoId = ?';
    $params[] = $fExam;
}
if ($fType !== '' && in_array($fType, ['tab_switch','copy','paste','browser_refresh'], true)) {
    $where[]  = 'ee.EventType = ?';
    $params[] = $fType;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

/* ── Main query: aggregate per (student, exam) ────────────────────────── */
$rows = [];
try {
    $rows = Database::fetchAll(
        "SELECT
             ee.UserId,
             ee.ExamInfoId,
             TRIM(CONCAT(COALESCE(u.FstName,''),' ',COALESCE(u.LstName,''))) AS StudentName,
             COALESCE(u.LoginName, '—')     AS LoginName,
             COALESCE(e.ExamName,  '—')     AS ExamName,
             COALESCE(s.SubjectName,'')     AS SubjectName,
             MAX(CASE WHEN ee.EventType = 'tab_switch'      THEN ee.EventCount ELSE 0 END) AS TabSwitches,
             MAX(CASE WHEN ee.EventType = 'copy'            THEN ee.EventCount ELSE 0 END) AS CopyEvents,
             MAX(CASE WHEN ee.EventType = 'paste'           THEN ee.EventCount ELSE 0 END) AS PasteEvents,
             MAX(CASE WHEN ee.EventType = 'browser_refresh' THEN ee.EventCount ELSE 0 END) AS Refreshes,
             SUM(ee.EventCount)              AS TotalEvents,
             MIN(ee.CreatedAt)               AS FirstSeen,
             MAX(ee.LastEventAt)             AS LastSeen
         FROM exam_events ee
         LEFT JOIN userinfo    u ON u.UserInfoId   = ee.UserId
         LEFT JOIN examinfo    e ON e.ExamInfoId   = ee.ExamInfoId
         LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
         $whereSQL
         GROUP BY ee.UserId, ee.ExamInfoId
         HAVING TotalEvents >= ?
         ORDER BY TotalEvents DESC, LastSeen DESC",
        array_merge($params, [$fThreshold])
    );
} catch (Exception $e) {
    $dbError = 'exam_events table not found — please run migration_v29.sql first.';
}

/* ── Multiple login detection (same day) ─────────────────────────────── */
$multiLoginMap = [];
try {
    $mlRows = Database::fetchAll(
        "SELECT lt.UserId, DATE(lt.CreateDtm) AS LoginDay, COUNT(*) AS LoginCount
           FROM logintrackinfo lt
          WHERE DATE(lt.CreateDtm) BETWEEN ? AND ?
            AND lt.UserId > 0
          GROUP BY lt.UserId, DATE(lt.CreateDtm)
         HAVING LoginCount >= 2",
        [$fDateFrom, $fDateTo]
    );
    foreach ($mlRows as $ml) {
        $multiLoginMap[$ml['UserId']] = max(
            $multiLoginMap[$ml['UserId']] ?? 0,
            (int)$ml['LoginCount']
        );
    }
} catch (Exception $e) {}

/* ── Exam dropdown data ────────────────────────────────────────────────── */
$exams = [];
try {
    $exams = Database::fetchAll(
        "SELECT DISTINCT e.ExamInfoId, e.ExamName
           FROM examinfo e
           JOIN exam_events ee ON ee.ExamInfoId = e.ExamInfoId
          ORDER BY e.ExamName", []);
} catch (Exception $e) {}

/* ── Export to CSV ────────────────────────────────────────────────────── */
if (isset($_GET['export'])) {
    $exportQS = http_build_query(array_filter([
        'type'       => 'cheating',
        'exam'       => $fExam ?: '',
        'date_from'  => $fDateFrom,
        'date_to'    => $fDateTo,
        'threshold'  => $fThreshold,
        'event_type' => $fType,
    ]));
    header('Location: ../exam/export-excel.php?' . $exportQS);
    exit;
}

/* ── Severity helper ─────────────────────────────────────────────────── */
function severityBadge(int $total): string {
    if ($total >= 20) return '<span style="background:#dc2626;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75em;font-weight:700;">HIGH</span>';
    if ($total >= 10) return '<span style="background:#d97706;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75em;font-weight:700;">MEDIUM</span>';
    return '<span style="background:#059669;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75em;font-weight:700;">LOW</span>';
}

$pageTitle = 'Cheating Detection Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;">
    <h2 style="margin:0;font-size:1.1rem;">🔍 Cheating Detection Dashboard</h2>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
      <a href="LiveExamCenter.php" class="btn btn-secondary btn-sm">← Live Center</a>
      <a href="?<?= htmlspecialchars(http_build_query(array_filter([
          'exam'       => $fExam ?: '',
          'date_from'  => $fDateFrom,
          'date_to'    => $fDateTo,
          'threshold'  => $fThreshold,
          'event_type' => $fType,
          'export'     => '1',
      ]))) ?>" class="btn btn-success btn-sm">↓ Export CSV</a>
    </div>
  </div>

  <div class="card-body">

    <!-- Filter bar -->
    <form method="get" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin-bottom:1.2rem;">
      <div class="form-group" style="flex:1 1 200px;">
        <label class="form-label">Exam</label>
        <select class="form-control" name="exam">
          <option value="">All exams</option>
          <?php foreach ($exams as $ex): ?>
            <option value="<?= (int)$ex['ExamInfoId'] ?>" <?= $fExam === (int)$ex['ExamInfoId'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($ex['ExamName']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:0 0 auto;">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($fDateFrom) ?>">
      </div>
      <div class="form-group" style="flex:0 0 auto;">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($fDateTo) ?>">
      </div>
      <div class="form-group" style="flex:0 0 120px;">
        <label class="form-label">Min Events</label>
        <input type="number" class="form-control" name="threshold" value="<?= $fThreshold ?>" min="1" max="100">
      </div>
      <div class="form-group" style="flex:0 0 150px;">
        <label class="form-label">Event Type</label>
        <select class="form-control" name="event_type">
          <option value="">All types</option>
          <option value="tab_switch"      <?= $fType==='tab_switch'      ? 'selected' : '' ?>>Tab Switch</option>
          <option value="copy"            <?= $fType==='copy'            ? 'selected' : '' ?>>Copy</option>
          <option value="paste"           <?= $fType==='paste'           ? 'selected' : '' ?>>Paste</option>
          <option value="browser_refresh" <?= $fType==='browser_refresh' ? 'selected' : '' ?>>Refresh</option>
        </select>
      </div>
      <div style="display:flex;gap:.4rem;align-items:flex-end;">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="CheatingDashboard.php" class="btn btn-secondary btn-sm">Clear</a>
      </div>
    </form>

    <?php if (!empty($dbError)): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php elseif (empty($rows)): ?>
      <div class="alert alert-info">No suspicious events found for the selected filters.</div>
    <?php else: ?>

    <!-- Summary bar -->
    <p style="font-size:.85rem;color:var(--tx-muted);margin-bottom:.8rem;">
      <?= count($rows) ?> student–exam pair<?= count($rows) !== 1 ? 's' : '' ?> with ≥<?= $fThreshold ?> events
      from <?= date('d M Y', strtotime($fDateFrom)) ?> to <?= date('d M Y', strtotime($fDateTo)) ?>
    </p>

    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Student</th>
            <th>Login</th>
            <th>Exam</th>
            <th style="text-align:center;" title="Tab Switches">Tabs ↔</th>
            <th style="text-align:center;" title="Copy Events">Copy</th>
            <th style="text-align:center;" title="Paste Events">Paste</th>
            <th style="text-align:center;" title="Browser Refreshes">Refresh</th>
            <th style="text-align:center;" title="Multiple logins same day">Multi-Login</th>
            <th style="text-align:center;">Total</th>
            <th>Severity</th>
            <th>Last Event</th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1; foreach ($rows as $row):
            $multiLogin = $multiLoginMap[$row['UserId']] ?? 0;
            $totalAdj   = (int)$row['TotalEvents'] + ($multiLogin >= 2 ? $multiLogin : 0);
          ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($row['StudentName'] ?: '—') ?></td>
            <td style="font-family:monospace;font-size:.85em;"><?= htmlspecialchars($row['LoginName']) ?></td>
            <td>
              <div><?= htmlspecialchars($row['ExamName']) ?></div>
              <?php if ($row['SubjectName']): ?>
                <div style="font-size:.75rem;color:var(--tx-muted);"><?= htmlspecialchars($row['SubjectName']) ?></div>
              <?php endif; ?>
            </td>
            <td style="text-align:center;">
              <?php $v = (int)$row['TabSwitches']; ?>
              <span style="font-weight:<?= $v >= 5 ? '700' : '400' ?>;color:<?= $v >= 5 ? '#dc2626' : 'inherit' ?>;"><?= $v ?></span>
            </td>
            <td style="text-align:center;">
              <?php $v = (int)$row['CopyEvents']; ?>
              <span style="font-weight:<?= $v >= 3 ? '700' : '400' ?>;color:<?= $v >= 3 ? '#d97706' : 'inherit' ?>;"><?= $v ?></span>
            </td>
            <td style="text-align:center;">
              <?php $v = (int)$row['PasteEvents']; ?>
              <span style="font-weight:<?= $v >= 3 ? '700' : '400' ?>;color:<?= $v >= 3 ? '#d97706' : 'inherit' ?>;"><?= $v ?></span>
            </td>
            <td style="text-align:center;">
              <?php $v = (int)$row['Refreshes']; ?>
              <span style="font-weight:<?= $v >= 5 ? '700' : '400' ?>;color:<?= $v >= 5 ? '#dc2626' : 'inherit' ?>;"><?= $v ?></span>
            </td>
            <td style="text-align:center;">
              <?php if ($multiLogin >= 2): ?>
                <span style="background:#fef2f2;color:#dc2626;padding:2px 8px;border-radius:4px;font-size:.8em;font-weight:700;"><?= $multiLogin ?>×</span>
              <?php else: ?>
                <span style="color:var(--tx-muted);">—</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center;font-weight:700;"><?= (int)$row['TotalEvents'] ?></td>
            <td><?= severityBadge((int)$row['TotalEvents'] + ($multiLogin >= 2 ? 5 : 0)) ?></td>
            <td style="white-space:nowrap;font-size:.8rem;">
              <?= $row['LastSeen'] ? date('d M, H:i', strtotime($row['LastSeen'])) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php endif; ?>

  </div><!-- /card-body -->
</div><!-- /card -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
