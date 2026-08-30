<?php
/**
 * Admin/InstituteReports.php — Institute-level reports and dashboard.
 *
 * Sections:
 *   1. Summary dashboard (KPI cards)
 *   2. Students per institute (with state/type filter)
 *   3. Exam performance by institute
 *   4. Enrollment & payment summary by institute
 *   5. Top institutes by student count / pass rate
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$tab         = $_GET['tab']     ?? 'dashboard';
$filterInst  = filter_input(INPUT_GET, 'inst',  FILTER_VALIDATE_INT) ?: 0;
$filterState = trim($_GET['state'] ?? '');
$filterType  = trim($_GET['type']  ?? '');
$dateFrom    = trim($_GET['date_from'] ?? '');
$dateTo      = trim($_GET['date_to']   ?? '');

/* ── KPI Cards ────────────────────────────────────────────────────────────── */
$kpi = Database::fetchOne(
    "SELECT
       COUNT(DISTINCT i.InstituteId)                         AS TotalInstitutes,
       COUNT(DISTINCT CASE WHEN i.Active='Y' THEN i.InstituteId END) AS ActiveInstitutes,
       COUNT(DISTINCT u.UserInfoId)                          AS TotalStudents,
       COUNT(DISTINCT CASE WHEN se.StudentExamId IS NOT NULL
             THEN se.StudentExamId END)                      AS TotalAttempts,
       ROUND(AVG(CASE WHEN se.Description='Pass' THEN 100.0 ELSE 0 END),1) AS OverallPassRate
     FROM institutes i
LEFT JOIN userinfo u  ON u.InstituteId = i.InstituteId
LEFT JOIN studentexam se ON se.UserInfoId = u.UserInfoId");

/* ── Filter dropdowns ─────────────────────────────────────────────────────── */
$allInstitutes = Database::fetchAll(
    "SELECT InstituteId, InstituteName FROM institutes WHERE Active='Y' ORDER BY InstituteName");
$allStates = Database::fetchAll(
    "SELECT DISTINCT State FROM institutes WHERE Active='Y' ORDER BY State");
$allTypes  = ['Private','Govt','Semi-Govt','Autonomous','Trust','Other'];

/* ── Build common WHERE ───────────────────────────────────────────────────── */
function buildInstWhere(int $filterInst, string $filterState, string $filterType): array {
    $where = []; $params = [];
    if ($filterInst)  { $where[] = 'i.InstituteId = ?';    $params[] = $filterInst; }
    if ($filterState) { $where[] = 'i.State = ?';          $params[] = $filterState; }
    if ($filterType)  { $where[] = 'i.InstituteType = ?';  $params[] = $filterType; }
    $where[] = "i.Active = 'Y'";
    return ['WHERE ' . implode(' AND ', $where), $params];
}

/* ── Students per institute ───────────────────────────────────────────────── */
$studentRows = [];
if ($tab === 'students' || $tab === 'dashboard') {
    [$wSQL, $wParams] = buildInstWhere($filterInst, $filterState, $filterType);
    $studentRows = Database::fetchAll(
        "SELECT i.InstituteId, i.InstituteName, i.InstituteType, i.State, i.CityVillage,
                COUNT(DISTINCT u.UserInfoId)  AS StudentCount,
                COUNT(DISTINCT CASE WHEN li.Active='Y' THEN u.UserInfoId END) AS ActiveStudents,
                (SELECT ContactName FROM institute_contacts
                  WHERE InstituteId=i.InstituteId AND IsPrimary=1 AND Active='Y' LIMIT 1) AS PrimaryContact,
                (SELECT Phone FROM institute_contacts
                  WHERE InstituteId=i.InstituteId AND IsPrimary=1 AND Active='Y' LIMIT 1) AS PrimaryPhone
           FROM institutes i
      LEFT JOIN userinfo  u  ON u.InstituteId = i.InstituteId
      LEFT JOIN logininfo li ON li.LoginName  = u.LoginName
         $wSQL
          GROUP BY i.InstituteId
          ORDER BY StudentCount DESC",
        $wParams);
}

/* ── Exam performance per institute ──────────────────────────────────────── */
$perfRows = [];
if ($tab === 'performance') {
    [$wSQL, $wParams] = buildInstWhere($filterInst, $filterState, $filterType);
    $dateWhere = '';
    if ($dateFrom) { $dateWhere .= ' AND COALESCE(se.ExamDate,se.CreateDate) >= ?'; $wParams[] = $dateFrom.' 00:00:00'; }
    if ($dateTo)   { $dateWhere .= ' AND COALESCE(se.ExamDate,se.CreateDate) <= ?'; $wParams[] = $dateTo.' 23:59:59'; }
    $perfRows = Database::fetchAll(
        "SELECT i.InstituteId, i.InstituteName, i.InstituteType, i.State,
                COUNT(DISTINCT se.StudentExamId)           AS TotalAttempts,
                COUNT(DISTINCT u.UserInfoId)               AS UniqueStudents,
                SUM(CASE WHEN se.Description='Pass' THEN 1 ELSE 0 END)  AS Passed,
                SUM(CASE WHEN se.Description='Fail' THEN 1 ELSE 0 END)  AS Failed,
                ROUND(AVG(CASE WHEN se.MarksOutOf > 0
                     THEN se.Score * 100.0 / se.MarksOutOf END), 1)     AS AvgScore,
                ROUND(SUM(CASE WHEN se.Description='Pass' THEN 1.0 ELSE 0 END)
                      / NULLIF(COUNT(se.StudentExamId),0) * 100, 1)     AS PassRate
           FROM institutes i
      LEFT JOIN userinfo   u  ON u.InstituteId = i.InstituteId
      LEFT JOIN studentexam se ON se.UserInfoId = u.UserInfoId
                               AND se.Description IS NOT NULL
                               AND se.Description != ''
         $wSQL $dateWhere
          GROUP BY i.InstituteId
          ORDER BY PassRate DESC, TotalAttempts DESC",
        $wParams);
}

/* ── Enrollment / payment summary ────────────────────────────────────────── */
$payRows = [];
if ($tab === 'payments') {
    [$wSQL, $wParams] = buildInstWhere($filterInst, $filterState, $filterType);
    $dateWhere = '';
    if ($dateFrom) { $dateWhere .= ' AND ep.PaidAt >= ?'; $wParams[] = $dateFrom.' 00:00:00'; }
    if ($dateTo)   { $dateWhere .= ' AND ep.PaidAt <= ?'; $wParams[] = $dateTo.' 23:59:59'; }
    $payRows = Database::fetchAll(
        "SELECT i.InstituteId, i.InstituteName, i.InstituteType, i.State,
                COUNT(ep.PaymentId)                        AS TotalEnrollments,
                SUM(CASE WHEN ep.PaymentStatus='Paid'   THEN 1 ELSE 0 END) AS Paid,
                SUM(CASE WHEN ep.PaymentStatus='Free'   THEN 1 ELSE 0 END) AS Free,
                SUM(CASE WHEN ep.PaymentStatus='Waived' THEN 1 ELSE 0 END) AS Waived,
                SUM(CASE WHEN ep.PaymentStatus='Pending' THEN 1 ELSE 0 END) AS Pending,
                COALESCE(SUM(ep.FinalAmount),0)            AS TotalRevenue,
                COALESCE(SUM(ep.InstituteDiscountAmt),0)   AS InstDiscountGiven
           FROM institutes i
      LEFT JOIN userinfo u  ON u.InstituteId  = i.InstituteId
      LEFT JOIN enrollment_payments ep ON ep.UserInfoId = u.UserInfoId
         $wSQL $dateWhere
          GROUP BY i.InstituteId
          ORDER BY TotalRevenue DESC",
        $wParams);
}

/* ── Top institutes ───────────────────────────────────────────────────────── */
$topRows = [];
if ($tab === 'top') {
    $topRows = Database::fetchAll(
        "SELECT i.InstituteId, i.InstituteName, i.InstituteType, i.State,
                COUNT(DISTINCT u.UserInfoId) AS StudentCount,
                COUNT(DISTINCT se.StudentExamId) AS Attempts,
                ROUND(SUM(CASE WHEN se.Description='Pass' THEN 1.0 ELSE 0 END)
                      / NULLIF(COUNT(CASE WHEN se.Description IS NOT NULL
                               AND se.Description!='' THEN 1 END),0)*100,1) AS PassRate,
                COALESCE(SUM(ep.FinalAmount),0) AS Revenue
           FROM institutes i
      LEFT JOIN userinfo u  ON u.InstituteId = i.InstituteId
      LEFT JOIN studentexam se ON se.UserInfoId = u.UserInfoId
      LEFT JOIN enrollment_payments ep ON ep.UserInfoId = u.UserInfoId AND ep.PaymentStatus='Paid'
          WHERE i.Active = 'Y'
          GROUP BY i.InstituteId
          ORDER BY StudentCount DESC
          LIMIT 20");
}

$pageTitle = 'Institute Reports';
include __DIR__ . '/../includes/header.php';
?>
<style>
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;}
.kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px 20px;text-align:center;}
.kpi-val{font-size:2rem;font-weight:700;color:#1e40af;}
.kpi-lbl{font-size:.8rem;color:#6b7280;margin-top:4px;}
.tab-bar{display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid #e2e8f0;padding-bottom:0;}
.tab-btn{padding:8px 18px;border:none;background:none;cursor:pointer;font-size:.9rem;color:#6b7280;border-bottom:3px solid transparent;margin-bottom:-2px;}
.tab-btn.active{color:#1e40af;border-bottom-color:#1e40af;font-weight:600;}
.tbl th{background:#1e40af;color:#fff;padding:8px 10px;font-size:.8rem;white-space:nowrap;}
.tbl td{padding:7px 10px;border-bottom:1px solid #f1f5f9;font-size:.83rem;vertical-align:middle;}
.tbl tr:hover td{background:#f0f7ff;}
.bar{height:10px;border-radius:5px;background:#e2e8f0;overflow:hidden;}
.bar-fill{height:100%;border-radius:5px;background:#22c55e;}
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
</style>

<h2>Institute Reports & Dashboard</h2>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card"><div class="kpi-val"><?php echo (int)$kpi['TotalInstitutes']; ?></div><div class="kpi-lbl">Total Institutes</div></div>
  <div class="kpi-card"><div class="kpi-val"><?php echo (int)$kpi['ActiveInstitutes']; ?></div><div class="kpi-lbl">Active</div></div>
  <div class="kpi-card"><div class="kpi-val"><?php echo number_format((int)$kpi['TotalStudents']); ?></div><div class="kpi-lbl">Institute Students</div></div>
  <div class="kpi-card"><div class="kpi-val"><?php echo number_format((int)$kpi['TotalAttempts']); ?></div><div class="kpi-lbl">Exam Attempts</div></div>
  <div class="kpi-card"><div class="kpi-val"><?php echo $kpi['OverallPassRate'] ?? '—'; ?>%</div><div class="kpi-lbl">Overall Pass Rate</div></div>
</div>

<!-- Tab bar -->
<div class="tab-bar">
  <?php $tabs = ['dashboard'=>'Overview','students'=>'Students','performance'=>'Performance','payments'=>'Payments','top'=>'Top Institutes'];
  foreach ($tabs as $k => $label): ?>
    <a href="?tab=<?php echo $k; ?>&inst=<?php echo $filterInst; ?>&state=<?php echo urlencode($filterState); ?>&type=<?php echo urlencode($filterType); ?>">
      <button class="tab-btn <?php echo $tab===$k?'active':''; ?>"><?php echo $label; ?></button>
    </a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="get" class="filters">
  <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
  <select name="inst" class="form-control" style="min-width:200px;">
    <option value="">All Institutes</option>
    <?php foreach ($allInstitutes as $i): ?>
      <option value="<?php echo $i['InstituteId']; ?>" <?php echo $filterInst==$i['InstituteId']?'selected':''; ?>>
        <?php echo htmlspecialchars($i['InstituteName']); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="state" class="form-control" style="min-width:160px;">
    <option value="">All States</option>
    <?php foreach ($allStates as $s): ?>
      <option value="<?php echo $s['State']; ?>" <?php echo $filterState===$s['State']?'selected':''; ?>>
        <?php echo htmlspecialchars($s['State']); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select name="type" class="form-control" style="min-width:130px;">
    <option value="">All Types</option>
    <?php foreach ($allTypes as $t): ?>
      <option value="<?php echo $t; ?>" <?php echo $filterType===$t?'selected':''; ?>><?php echo $t; ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (in_array($tab, ['performance','payments'])): ?>
    <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="form-control" style="width:140px;" title="From date">
    <input type="date" name="date_to"   value="<?php echo $dateTo; ?>"   class="form-control" style="width:140px;" title="To date">
  <?php endif; ?>
  <button type="submit" class="btn btn-secondary">Apply</button>
  <a href="?tab=<?php echo $tab; ?>" class="btn btn-secondary">Reset</a>
</form>

<?php /* ═══════════════ DASHBOARD / STUDENTS ══════════════════════════ */ ?>
<?php if (in_array($tab, ['dashboard','students'])): ?>
<table class="tbl" style="width:100%;border-collapse:collapse;">
  <thead><tr>
    <th>Institute</th><th>Type</th><th>State</th><th>City/Village</th>
    <th>Primary Contact</th><th style="text-align:right;">Total Students</th>
    <th style="text-align:right;">Active</th><th>Actions</th>
  </tr></thead>
  <tbody>
  <?php if (empty($studentRows)): ?>
    <tr><td colspan="8" style="text-align:center;padding:24px;color:#888;">No data found.</td></tr>
  <?php endif; ?>
  <?php foreach ($studentRows as $r): ?>
  <tr>
    <td><a href="?tab=students&inst=<?php echo $r['InstituteId']; ?>"><?php echo htmlspecialchars($r['InstituteName']); ?></a></td>
    <td><span style="font-size:.75rem;padding:2px 8px;border-radius:10px;background:#f1f5f9;color:#334155;"><?php echo $r['InstituteType']; ?></span></td>
    <td><?php echo htmlspecialchars($r['State']); ?></td>
    <td><?php echo htmlspecialchars($r['CityVillage']); ?></td>
    <td>
      <?php if ($r['PrimaryContact']): ?>
        <div><?php echo htmlspecialchars($r['PrimaryContact']); ?></div>
        <div style="font-size:.78rem;color:#6b7280;"><?php echo htmlspecialchars($r['PrimaryPhone']??''); ?></div>
      <?php else: ?>—<?php endif; ?>
    </td>
    <td style="text-align:right;font-weight:700;"><?php echo (int)$r['StudentCount']; ?></td>
    <td style="text-align:right;"><?php echo (int)$r['ActiveStudents']; ?></td>
    <td>
      <a href="ManageInstitutes.php?action=view&id=<?php echo $r['InstituteId']; ?>" class="btn btn-sm btn-secondary">View</a>
      <a href="InstituteDiscounts.php?id=<?php echo $r['InstituteId']; ?>" class="btn btn-sm" style="background:#7c3aed;color:#fff;">Discounts</a>
      <a href="InstituteStudents.php?id=<?php echo $r['InstituteId']; ?>" class="btn btn-sm btn-primary">Students</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ PERFORMANCE ════════════════════════════════════ */ ?>
<?php if ($tab === 'performance'): ?>
<table class="tbl" style="width:100%;border-collapse:collapse;">
  <thead><tr>
    <th>Institute</th><th>Type</th><th>State</th>
    <th style="text-align:right;">Attempts</th>
    <th style="text-align:right;">Students</th>
    <th style="text-align:right;">Passed</th>
    <th style="text-align:right;">Failed</th>
    <th style="text-align:right;">Avg Score</th>
    <th>Pass Rate</th>
  </tr></thead>
  <tbody>
  <?php if (empty($perfRows)): ?>
    <tr><td colspan="9" style="text-align:center;padding:24px;color:#888;">No exam data found.</td></tr>
  <?php endif; ?>
  <?php foreach ($perfRows as $r): $pr = (float)($r['PassRate'] ?? 0); ?>
  <tr>
    <td><a href="?tab=performance&inst=<?php echo $r['InstituteId']; ?>"><?php echo htmlspecialchars($r['InstituteName']); ?></a></td>
    <td><span style="font-size:.75rem;padding:2px 8px;border-radius:10px;background:#f1f5f9;"><?php echo $r['InstituteType']; ?></span></td>
    <td><?php echo htmlspecialchars($r['State']); ?></td>
    <td style="text-align:right;"><?php echo (int)$r['TotalAttempts']; ?></td>
    <td style="text-align:right;"><?php echo (int)$r['UniqueStudents']; ?></td>
    <td style="text-align:right;color:#065f46;"><?php echo (int)$r['Passed']; ?></td>
    <td style="text-align:right;color:#991b1b;"><?php echo (int)$r['Failed']; ?></td>
    <td style="text-align:right;"><?php echo $r['AvgScore'] !== null ? $r['AvgScore'].'%' : '—'; ?></td>
    <td style="min-width:120px;">
      <div style="display:flex;align-items:center;gap:8px;">
        <div class="bar" style="flex:1;"><div class="bar-fill" style="width:<?php echo min(100,$pr); ?>%;background:<?php echo $pr>=60?'#22c55e':($pr>=40?'#f59e0b':'#ef4444'); ?>;"></div></div>
        <span style="font-size:.8rem;font-weight:600;min-width:38px;"><?php echo $pr; ?>%</span>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ PAYMENTS ════════════════════════════════════════ */ ?>
<?php if ($tab === 'payments'): ?>
<table class="tbl" style="width:100%;border-collapse:collapse;">
  <thead><tr>
    <th>Institute</th><th>Type</th><th>State</th>
    <th style="text-align:right;">Enrollments</th>
    <th style="text-align:right;">Paid</th>
    <th style="text-align:right;">Free</th>
    <th style="text-align:right;">Waived</th>
    <th style="text-align:right;">Pending</th>
    <th style="text-align:right;">Revenue (₹)</th>
    <th style="text-align:right;">Inst. Disc. Given (₹)</th>
  </tr></thead>
  <tbody>
  <?php if (empty($payRows)): ?>
    <tr><td colspan="10" style="text-align:center;padding:24px;color:#888;">No payment data found.</td></tr>
  <?php endif; ?>
  <?php foreach ($payRows as $r): ?>
  <tr>
    <td><?php echo htmlspecialchars($r['InstituteName']); ?></td>
    <td><span style="font-size:.75rem;padding:2px 8px;border-radius:10px;background:#f1f5f9;"><?php echo $r['InstituteType']; ?></span></td>
    <td><?php echo htmlspecialchars($r['State']); ?></td>
    <td style="text-align:right;"><?php echo (int)$r['TotalEnrollments']; ?></td>
    <td style="text-align:right;color:#065f46;"><?php echo (int)$r['Paid']; ?></td>
    <td style="text-align:right;color:#1e40af;"><?php echo (int)$r['Free']; ?></td>
    <td style="text-align:right;color:#92400e;"><?php echo (int)$r['Waived']; ?></td>
    <td style="text-align:right;color:#991b1b;"><?php echo (int)$r['Pending']; ?></td>
    <td style="text-align:right;font-weight:700;">₹<?php echo number_format((float)$r['TotalRevenue'],2); ?></td>
    <td style="text-align:right;color:#7c3aed;">₹<?php echo number_format((float)$r['InstDiscountGiven'],2); ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!empty($payRows)):
    $totRev  = array_sum(array_column($payRows,'TotalRevenue'));
    $totDisc = array_sum(array_column($payRows,'InstDiscountGiven'));
  ?>
  <tr style="background:#f0f7ff;font-weight:700;">
    <td colspan="8" style="text-align:right;">TOTAL</td>
    <td style="text-align:right;">₹<?php echo number_format($totRev,2); ?></td>
    <td style="text-align:right;color:#7c3aed;">₹<?php echo number_format($totDisc,2); ?></td>
  </tr>
  <?php endif; ?>
  </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ TOP INSTITUTES ════════════════════════════════ */ ?>
<?php if ($tab === 'top'): ?>
<table class="tbl" style="width:100%;border-collapse:collapse;">
  <thead><tr>
    <th>#</th><th>Institute</th><th>Type</th><th>State</th>
    <th style="text-align:right;">Students</th>
    <th style="text-align:right;">Attempts</th>
    <th>Pass Rate</th>
    <th style="text-align:right;">Revenue (₹)</th>
    <th>Actions</th>
  </tr></thead>
  <tbody>
  <?php foreach ($topRows as $rank => $r): $pr = (float)($r['PassRate'] ?? 0); ?>
  <tr>
    <td style="font-weight:700;color:#6b7280;"><?php echo $rank+1; ?></td>
    <td><strong><?php echo htmlspecialchars($r['InstituteName']); ?></strong></td>
    <td><span style="font-size:.75rem;padding:2px 8px;border-radius:10px;background:#f1f5f9;"><?php echo $r['InstituteType']; ?></span></td>
    <td><?php echo htmlspecialchars($r['State']); ?></td>
    <td style="text-align:right;font-weight:700;"><?php echo (int)$r['StudentCount']; ?></td>
    <td style="text-align:right;"><?php echo (int)$r['Attempts']; ?></td>
    <td style="min-width:110px;">
      <div style="display:flex;align-items:center;gap:6px;">
        <div class="bar" style="flex:1;"><div class="bar-fill" style="width:<?php echo min(100,$pr); ?>%;background:<?php echo $pr>=60?'#22c55e':($pr>=40?'#f59e0b':'#ef4444'); ?>;"></div></div>
        <span style="font-size:.8rem;font-weight:600;"><?php echo $pr; ?>%</span>
      </div>
    </td>
    <td style="text-align:right;">₹<?php echo number_format((float)$r['Revenue'],2); ?></td>
    <td>
      <a href="ManageInstitutes.php?action=view&id=<?php echo $r['InstituteId']; ?>" class="btn btn-sm btn-secondary">View</a>
      <a href="?tab=performance&inst=<?php echo $r['InstituteId']; ?>" class="btn btn-sm btn-primary">Stats</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
