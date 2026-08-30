<?php
/**
 * Admin/LiveExamCenter.php — Live Exam Command Center
 *
 * Shows real-time stats (auto-refresh every 30 s):
 *   • Exams Running      — exams with recorded behaviour events in the last 2 h
 *   • Students Online    — users with a login event in the last 30 min
 *   • Suspicious Activity — students with any cheating event today
 *
 * Requires: migration_v28.sql (logintrackinfo.IpAddress)
 *           migration_v29.sql (exam_events)
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

/* ── Live stat queries ────────────────────────────────────────────────── */

/* Students who had a login event in the last 30 minutes */
$studentsOnline = 0;
try {
    $r = Database::fetchOne(
        "SELECT COUNT(DISTINCT lt.UserId) AS n
           FROM logintrackinfo lt
          WHERE lt.CreateDtm >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            AND lt.UserId > 0", []);
    $studentsOnline = (int)($r['n'] ?? 0);
} catch (Exception $e) {}

/* Exams with behaviour events in the last 2 hours */
$examsRunning = 0;
$liveExams    = [];
try {
    $r = Database::fetchOne(
        "SELECT COUNT(DISTINCT ExamInfoId) AS n FROM exam_events
          WHERE LastEventAt >= DATE_SUB(NOW(), INTERVAL 2 HOUR)", []);
    $examsRunning = (int)($r['n'] ?? 0);

    $liveExams = Database::fetchAll(
        "SELECT ee.ExamInfoId,
                COALESCE(e.ExamName,'(unknown)') AS ExamName,
                COALESCE(s.SubjectName,'')        AS SubjectName,
                COUNT(DISTINCT ee.UserId)          AS ActiveStudents,
                SUM(ee.EventCount)                 AS TotalEvents,
                MAX(ee.LastEventAt)                AS LastActivity
           FROM exam_events ee
           LEFT JOIN examinfo    e ON e.ExamInfoId    = ee.ExamInfoId
           LEFT JOIN subjectinfo s ON s.SubjectInfoId = e.SubjectInfoId
          WHERE ee.LastEventAt >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
          GROUP BY ee.ExamInfoId
          ORDER BY LastActivity DESC", []);
} catch (Exception $e) {}

/* Students with suspicious activity today (any event) */
$suspiciousCount = 0;
$suspiciousRows  = [];
$SUSPICIOUS_THRESHOLD = 3; // tab switches + refreshes above this = suspicious
try {
    $r = Database::fetchOne(
        "SELECT COUNT(DISTINCT UserId) AS n
           FROM exam_events
          WHERE DATE(LastEventAt) = CURDATE()
            AND EventType IN ('tab_switch','browser_refresh')
          GROUP BY UserId
         HAVING SUM(EventCount) >= $SUSPICIOUS_THRESHOLD
          LIMIT 1", []);
    // Count properly with subquery
    $rr = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM (
             SELECT UserId FROM exam_events
              WHERE DATE(LastEventAt) = CURDATE()
                AND EventType IN ('tab_switch','browser_refresh')
              GROUP BY UserId
             HAVING SUM(EventCount) >= $SUSPICIOUS_THRESHOLD
         ) sub", []);
    $suspiciousCount = (int)($rr['n'] ?? 0);

    $suspiciousRows = Database::fetchAll(
        "SELECT ee.UserId, ee.ExamInfoId,
                COALESCE(CONCAT(u.FstName,' ',u.LstName), u.LoginName, '—') AS StudentName,
                COALESCE(u.LoginName,'—')    AS LoginName,
                COALESCE(e.ExamName,'—')     AS ExamName,
                MAX(CASE WHEN ee.EventType='tab_switch'      THEN ee.EventCount ELSE 0 END) AS TabSwitches,
                MAX(CASE WHEN ee.EventType='copy'            THEN ee.EventCount ELSE 0 END) AS CopyEvents,
                MAX(CASE WHEN ee.EventType='paste'           THEN ee.EventCount ELSE 0 END) AS PasteEvents,
                MAX(CASE WHEN ee.EventType='browser_refresh' THEN ee.EventCount ELSE 0 END) AS Refreshes,
                SUM(ee.EventCount)            AS TotalEvents,
                MAX(ee.LastEventAt)           AS LastSeen
           FROM exam_events ee
           LEFT JOIN userinfo u ON u.UserInfoId = ee.UserId
           LEFT JOIN examinfo e ON e.ExamInfoId = ee.ExamInfoId
          WHERE DATE(ee.LastEventAt) = CURDATE()
          GROUP BY ee.UserId, ee.ExamInfoId
         HAVING (TabSwitches + Refreshes) >= $SUSPICIOUS_THRESHOLD
          ORDER BY TotalEvents DESC
          LIMIT 50", []);
} catch (Exception $e) {}

/* Recent login events (last 15 min) — who's actively browsing */
$recentLogins = [];
try {
    $recentLogins = Database::fetchAll(
        "SELECT DISTINCT lt.UserId, lt.IpAddress, lt.CreateDtm,
                COALESCE(CONCAT(u.FstName,' ',u.LstName), u.LoginName, '?') AS Name,
                COALESCE(u.LoginName,'')    AS LoginName,
                COALESCE(li.Role,'')        AS Role
           FROM logintrackinfo lt
           LEFT JOIN userinfo  u  ON u.UserInfoId  = lt.UserId AND lt.UserId > 0
           LEFT JOIN logininfo li ON li.LoginName  = u.LoginName
          WHERE lt.CreateDtm >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            AND lt.UserId > 0
          ORDER BY lt.CreateDtm DESC
          LIMIT 20", []);
} catch (Exception $e) {}

$pageTitle = 'Live Exam Command Center';
include __DIR__ . '/../includes/header.php';
?>

<style>
  /* ── Command Center ─────────────────────────────────────────────────── */
  .cc-grid   { display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px; }
  @media(max-width:700px){ .cc-grid { grid-template-columns:1fr; } }

  .cc-stat { border-radius:10px;padding:22px 20px;display:flex;align-items:center;gap:16px;
             box-shadow:0 2px 8px rgba(0,0,0,.09); }
  .cc-stat-icon { font-size:2.2rem;line-height:1;flex-shrink:0; }
  .cc-stat-val  { font-size:2rem;font-weight:900;line-height:1;letter-spacing:-1px; }
  .cc-stat-lbl  { font-size:.78rem;font-weight:600;opacity:.8;margin-top:3px; }

  .cc-running  { background:linear-gradient(135deg,#312e81,#4f46e5);color:#fff; }
  .cc-online   { background:linear-gradient(135deg,#065f46,#059669);color:#fff; }
  .cc-alert    { background:linear-gradient(135deg,#7f1d1d,#dc2626);color:#fff; }

  .refresh-badge { font-size:.72rem;color:var(--tx-muted);font-style:italic;margin-bottom:8px;text-align:right; }
  .live-dot { display:inline-block;width:8px;height:8px;background:#22c55e;border-radius:50%;
              margin-right:6px;animation:pulse-dot 1.5s ease-in-out infinite; }
  @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.3} }
</style>

<!-- Auto-refresh meta -->
<meta http-equiv="refresh" content="30">

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <h2 style="margin:0;font-size:1.1rem;">
      <span class="live-dot"></span>Live Exam Command Center
    </h2>
    <div style="display:flex;align-items:center;gap:.8rem;flex-wrap:wrap;">
      <span style="font-size:.75rem;color:var(--tx-muted);">
        As of <?= date('H:i:s') ?> · Auto-refresh every 30 s
      </span>
      <a href="CheatingDashboard.php" class="btn btn-secondary btn-sm">🔍 Cheating Dashboard</a>
      <button onclick="location.reload()" class="btn btn-primary btn-sm">↻ Refresh Now</button>
    </div>
  </div>

  <div class="card-body">

    <!-- ── Stat cards ── -->
    <div class="cc-grid">
      <div class="cc-stat cc-running">
        <div class="cc-stat-icon">📋</div>
        <div>
          <div class="cc-stat-val"><?= $examsRunning ?></div>
          <div class="cc-stat-lbl">EXAMS RUNNING<br><small style="font-weight:400;">(events in last 2 h)</small></div>
        </div>
      </div>
      <div class="cc-stat cc-online">
        <div class="cc-stat-icon">👥</div>
        <div>
          <div class="cc-stat-val"><?= $studentsOnline ?></div>
          <div class="cc-stat-lbl">STUDENTS ONLINE<br><small style="font-weight:400;">(login in last 30 min)</small></div>
        </div>
      </div>
      <div class="cc-stat cc-alert">
        <div class="cc-stat-icon">⚠️</div>
        <div>
          <div class="cc-stat-val"><?= $suspiciousCount ?></div>
          <div class="cc-stat-lbl">SUSPICIOUS TODAY<br><small style="font-weight:400;">(≥<?= $SUSPICIOUS_THRESHOLD ?> tab/refresh events)</small></div>
        </div>
      </div>
    </div>

    <!-- ── Active exams ── -->
    <?php if ($liveExams): ?>
    <h3 style="font-size:.95rem;margin-bottom:8px;color:var(--clr-primary);">Active Exams (last 2 hours)</h3>
    <div class="tbl-wrap" style="margin-bottom:1.5rem;">
      <table class="tbl">
        <thead>
          <tr>
            <th>Exam</th>
            <th>Subject</th>
            <th style="text-align:center;">Students Active</th>
            <th style="text-align:center;">Events Logged</th>
            <th>Last Activity</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($liveExams as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['ExamName']) ?></td>
            <td><?= htmlspecialchars($row['SubjectName']) ?></td>
            <td style="text-align:center;font-weight:700;"><?= (int)$row['ActiveStudents'] ?></td>
            <td style="text-align:center;"><?= (int)$row['TotalEvents'] ?></td>
            <td style="white-space:nowrap;"><?= $row['LastActivity'] ? date('H:i:s', strtotime($row['LastActivity'])) : '—' ?></td>
            <td>
              <a href="CheatingDashboard.php?exam=<?= (int)$row['ExamInfoId'] ?>" class="btn btn-secondary btn-xs">View</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="alert alert-info" style="margin-bottom:1.5rem;">No exam activity recorded in the last 2 hours.</div>
    <?php endif; ?>

    <!-- ── Suspicious students today ── -->
    <?php if ($suspiciousRows): ?>
    <h3 style="font-size:.95rem;margin-bottom:8px;color:#dc2626;">⚠️ Suspicious Activity Today</h3>
    <div class="tbl-wrap" style="margin-bottom:1.5rem;">
      <table class="tbl">
        <thead>
          <tr>
            <th>Student</th>
            <th>Login</th>
            <th>Exam</th>
            <th style="text-align:center;" title="Tab Switches">Tabs</th>
            <th style="text-align:center;" title="Copy Events">Copy</th>
            <th style="text-align:center;" title="Paste Events">Paste</th>
            <th style="text-align:center;" title="Browser Refreshes">Refresh</th>
            <th style="text-align:center;">Total</th>
            <th>Last Seen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($suspiciousRows as $row):
            $total = (int)$row['TotalEvents'];
            $severity = $total >= 20 ? '#dc2626' : ($total >= 10 ? '#d97706' : '#059669');
          ?>
          <tr>
            <td><?= htmlspecialchars($row['StudentName']) ?></td>
            <td style="font-family:monospace;font-size:.85em;"><?= htmlspecialchars($row['LoginName']) ?></td>
            <td><?= htmlspecialchars($row['ExamName']) ?></td>
            <td style="text-align:center;"><?= (int)$row['TabSwitches'] ?></td>
            <td style="text-align:center;"><?= (int)$row['CopyEvents'] ?></td>
            <td style="text-align:center;"><?= (int)$row['PasteEvents'] ?></td>
            <td style="text-align:center;"><?= (int)$row['Refreshes'] ?></td>
            <td style="text-align:center;">
              <strong style="color:<?= $severity ?>;"><?= $total ?></strong>
            </td>
            <td style="white-space:nowrap;font-size:.82rem;">
              <?= $row['LastSeen'] ? date('H:i:s', strtotime($row['LastSeen'])) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- ── Recent logins (last 15 min) ── -->
    <?php if ($recentLogins): ?>
    <h3 style="font-size:.95rem;margin-bottom:8px;color:var(--clr-primary);">
      Recent Logins (last 15 minutes)
    </h3>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr><th>Name</th><th>Login</th><th>Role</th><th>IP</th><th>Time</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentLogins as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['Name']) ?></td>
            <td style="font-family:monospace;font-size:.85em;"><?= htmlspecialchars($row['LoginName']) ?></td>
            <td><?= htmlspecialchars($row['Role']) ?></td>
            <td style="font-family:monospace;font-size:.82em;"><?= htmlspecialchars($row['IpAddress'] ?? '—') ?></td>
            <td style="white-space:nowrap;font-size:.82rem;">
              <?= $row['CreateDtm'] ? date('H:i:s', strtotime($row['CreateDtm'])) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p style="color:var(--tx-muted);font-size:.85rem;">No logins in the last 15 minutes.</p>
    <?php endif; ?>

  </div><!-- /card-body -->
</div><!-- /card -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
