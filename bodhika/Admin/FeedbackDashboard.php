<?php
/**
 * Admin/FeedbackDashboard.php
 * Analytics dashboard for user feedback.
 * Admin only.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

$pageTitle = 'Feedback Dashboard';

/* ── Filters ──────────────────────────────────────────── */
$dateFrom  = trim($_GET['date_from']  ?? '');
$dateTo    = trim($_GET['date_to']    ?? '');
$minRating = (int)($_GET['min_rating'] ?? 0);
$recommend = trim($_GET['recommend']   ?? '');
$perPage   = 20;
$page      = max(1, (int)($_GET['p'] ?? 1));

$where  = [];
$params = [];
if ($dateFrom) { $where[] = 'DATE(f.CreatedAt) >= ?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(f.CreatedAt) <= ?'; $params[] = $dateTo; }
if ($minRating > 0) { $where[] = 'f.OverallRating >= ?'; $params[] = $minRating; }
if (in_array($recommend, ['Yes','No','Maybe'])) { $where[] = 'f.WouldRecommend = ?'; $params[] = $recommend; }
$wsql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

/* ── Check table exists ──────────────────────────────── */
$tableExists = false;
try {
    Database::fetchOne("SELECT 1 FROM app_feedback LIMIT 1", []);
    $tableExists = true;
} catch (Exception $e) { /* migration not run */ }

/* ── Summary stats ─────────────────────────────────── */
$stats = ['total'=>0,'avg_rating'=>0,'pct_yes'=>0,'pct_no'=>0,'pct_maybe'=>0];
$ratingDist = [1=>0,2=>0,3=>0,4=>0,5=>0];
$subRatings = [];
$topCategories = [];
$timeline = [];
$feedbacks = [];
$totalRows = 0;

if ($tableExists) {
    try {
        $s = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    ROUND(AVG(f.OverallRating),2) AS avg_rating,
                    SUM(f.WouldRecommend='Yes') AS cnt_yes,
                    SUM(f.WouldRecommend='No')  AS cnt_no,
                    SUM(f.WouldRecommend='Maybe') AS cnt_maybe,
                    ROUND(AVG(f.ExamExpRating),2) AS avg_exam,
                    ROUND(AVG(f.UIRating),2)      AS avg_ui,
                    ROUND(AVG(f.PerfRating),2)    AS avg_perf,
                    ROUND(AVG(f.QualityRating),2) AS avg_quality,
                    ROUND(AVG(f.SupportRating),2) AS avg_support
               FROM app_feedback f" . $wsql, $params);
        if ($s) {
            $stats = $s;
            $total = (int)$s['total'];
            $stats['pct_yes']   = $total > 0 ? round((int)$s['cnt_yes']   / $total * 100) : 0;
            $stats['pct_no']    = $total > 0 ? round((int)$s['cnt_no']    / $total * 100) : 0;
            $stats['pct_maybe'] = $total > 0 ? round((int)$s['cnt_maybe'] / $total * 100) : 0;
        }

        // Rating distribution
        $rdRows = Database::fetchAll(
            "SELECT OverallRating, COUNT(*) AS cnt FROM app_feedback f" . $wsql .
            " GROUP BY OverallRating ORDER BY OverallRating", $params);
        foreach ($rdRows as $r) $ratingDist[(int)$r['OverallRating']] = (int)$r['cnt'];

        // Timeline (last 30 days)
        $timeline = Database::fetchAll(
            "SELECT DATE(f.CreatedAt) AS day, COUNT(*) AS cnt, ROUND(AVG(f.OverallRating),1) AS avg
               FROM app_feedback f" . $wsql .
            " GROUP BY DATE(f.CreatedAt) ORDER BY day DESC LIMIT 30", $params);
        $timeline = array_reverse($timeline);

        // Paginated list
        $totalRows = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM app_feedback f".$wsql, $params)['c'] ?? 0);
        $offset    = ($page - 1) * $perPage;
        $feedbacks = Database::fetchAll(
            "SELECT f.*, u.FirstName, u.LastName, u.EmailId, i.InstituteName
               FROM app_feedback f
          LEFT JOIN userinfo u   ON u.UserInfoId   = f.UserInfoId
          LEFT JOIN institutes i ON i.InstituteId  = f.InstituteId
            " . $wsql . "
           ORDER BY f.CreatedAt DESC
           LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset]));
    } catch (Exception $e) {
        // Query error — table may exist but columns differ
    }
}

$totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

include __DIR__ . '/../includes/header.php';

/* ── helpers ── */
function starBar(float $v): string {
    $pct = round($v / 5 * 100);
    $col = $v >= 4 ? '#059669' : ($v >= 3 ? '#d97706' : '#dc2626');
    return '<div style="display:flex;align-items:center;gap:8px;">
              <div style="flex:1;height:8px;background:#e5e7eb;border-radius:4px;">
                <div style="width:'.$pct.'%;height:100%;background:'.$col.';border-radius:4px;"></div>
              </div>
              <span style="font-size:.8rem;font-weight:700;color:'.$col.';">'.number_format($v,1).'</span>
            </div>';
}
function ratingBadge(int $v): string {
    if (!$v) return '<span style="color:#9ca3af">—</span>';
    $cols = [1=>'#dc2626',2=>'#f97316',3=>'#d97706',4=>'#65a30d',5=>'#059669'];
    $col  = $cols[$v] ?? '#6b7280';
    return '<span style="background:'.str_replace('#','',$col).'20;color:'.$col.
           ';padding:2px 8px;border-radius:10px;font-weight:700;font-size:.78rem;">'.
           str_repeat('★',$v).'</span>';
}
?>
<style>
.fd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;}
.fd-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;text-align:center;}
.fd-card .val{font-size:2rem;font-weight:800;margin:8px 0 4px;}
.fd-card .lbl{font-size:.8rem;color:#6b7280;font-weight:600;}
.fd-chart-bar{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.fd-chart-bar .bar-label{width:24px;text-align:right;font-size:.85rem;font-weight:700;color:#374151;}
.fd-chart-bar .bar-track{flex:1;height:22px;background:#f3f4f6;border-radius:4px;overflow:hidden;}
.fd-chart-bar .bar-fill{height:100%;border-radius:4px;transition:width .6s ease;}
.fd-chart-bar .bar-val{width:40px;font-size:.8rem;font-weight:700;color:#374151;}
.fd-filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;}
.fd-filter-row label{font-size:.72rem;font-weight:800;color:var(--clr-primary);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:3px;white-space:nowrap;}
.fd-filter-row input,.fd-filter-row select{
  padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;}
.fd-tbl{width:100%;border-collapse:collapse;font-size:.83rem;}
.fd-tbl th{background:#4a6fa5;color:#fff;padding:8px 10px;text-align:left;white-space:nowrap;}
.fd-tbl td{padding:7px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top;}
.fd-tbl tr:nth-child(even) td{background:#f9fafb;}
.fd-tbl tr:hover td{background:#ede9fe;}
.nps-yes{color:#059669;font-weight:800;} .nps-no{color:#dc2626;font-weight:800;}
.nps-maybe{color:#d97706;font-weight:800;}
.timeline-wrap{overflow-x:auto;}
.timeline-bars{display:flex;align-items:flex-end;gap:3px;height:80px;padding-bottom:4px;}
.tl-bar{flex:1;min-width:8px;max-width:30px;background:#7c3aed;border-radius:2px 2px 0 0;
        cursor:pointer;transition:opacity .15s;position:relative;}
.tl-bar:hover{opacity:.7;}
.tl-bar .tl-tip{display:none;position:absolute;bottom:110%;left:50%;transform:translateX(-50%);
                 white-space:nowrap;background:#1f2937;color:#fff;font-size:.7rem;
                 padding:4px 8px;border-radius:4px;pointer-events:none;z-index:10;}
.tl-bar:hover .tl-tip{display:block;}
.paginate{display:flex;gap:6px;justify-content:center;margin-top:16px;flex-wrap:wrap;}
.paginate a,.paginate span{padding:6px 12px;border-radius:6px;font-size:.85rem;font-weight:600;
  text-decoration:none;border:1px solid #e5e7eb;color:#374151;}
.paginate a:hover{background:#ede9fe;border-color:#7c3aed;color:#7c3aed;}
.paginate .cur{background:#7c3aed;color:#fff;border-color:#7c3aed;}
.section-heading{font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px;
                  color:#6b7280;padding:16px 0 10px;}
</style>

<div class="page-inner" style="max-width:1100px;margin:0 auto;padding:20px 16px;">

<!-- Page header -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <span>💬 Feedback Dashboard</span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($tableExists): ?>
      <a href="?export=csv<?php
          echo ($dateFrom ? '&date_from='.urlencode($dateFrom) : '');
          echo ($dateTo   ? '&date_to='.urlencode($dateTo)     : '');
        ?>" class="btn btn-secondary btn-sm">⬇ Export CSV</a>
      <?php endif; ?>
      <a href="../exam/feedback.php" class="btn btn-success btn-sm" target="_blank">💬 Submit Feedback</a>
    </div>
  </div>
</div>

<?php
/* ── CSV Export ──────────────────────────────── */
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $tableExists) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="feedback_export_' . date('Ymd') . '.csv"');
    $exportRows = Database::fetchAll(
        "SELECT f.FeedbackId, f.CreatedAt, u.FirstName, u.LastName, u.EmailId,
                f.OverallRating, f.ExamExpRating, f.UIRating, f.PerfRating,
                f.QualityRating, f.SupportRating, f.Categories,
                f.LikedMost, f.Improvements, f.FeatureRequests,
                f.WouldRecommend, f.UserRole, i.InstituteName
           FROM app_feedback f
      LEFT JOIN userinfo u   ON u.UserInfoId   = f.UserInfoId
      LEFT JOIN institutes i ON i.InstituteId  = f.InstituteId
            " . $wsql . "
           ORDER BY f.CreatedAt DESC", $params);
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Date','First Name','Last Name','Email','Overall','Exam','UI','Perf',
                   'Quality','Support','Categories','Liked Most','Improvements',
                   'Feature Requests','Recommend','Role','Institute']);
    foreach ($exportRows as $row) {
        fputcsv($out, [
            $row['FeedbackId'], $row['CreatedAt'],
            $row['FirstName']??'', $row['LastName']??'', $row['EmailId']??'',
            $row['OverallRating'], $row['ExamExpRating']??'', $row['UIRating']??'',
            $row['PerfRating']??'', $row['QualityRating']??'', $row['SupportRating']??'',
            $row['Categories']??'', $row['LikedMost']??'', $row['Improvements']??'',
            $row['FeatureRequests']??'', $row['WouldRecommend']??'',
            $row['UserRole']??'', $row['InstituteName']??'',
        ]);
    }
    fclose($out);
    exit;
}
?>

<?php if (!$tableExists): ?>
<!-- Migration warning -->
<div class="card" style="border-color:#fca5a5;background:#fef2f2;">
  <div class="card-body" style="padding:28px;text-align:center;">
    <div style="font-size:2rem;margin-bottom:12px;">⚠️</div>
    <h3 style="color:#b91c1c;margin:0 0 8px;">Migration Required</h3>
    <p style="color:#6b7280;margin:0 0 16px;">
      The <code>app_feedback</code> table does not exist yet.<br>
      Run <strong>migrations/migration_v25.sql</strong> on your database first.
    </p>
    <a href="../Admin/db-export.php" class="btn btn-warning btn-sm">Go to DB Export / Migration</a>
  </div>
</div>
<?php else: ?>

<!-- ── Filters ──────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="get" action="FeedbackDashboard.php">
      <div class="fd-filter-row">
        <div><label>From Date</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>"></div>
        <div><label>To Date</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>"></div>
        <div>
          <label>Min Rating</label>
          <select name="min_rating">
            <option value="0">All ratings</option>
            <?php for ($i=1;$i<=5;$i++): ?>
            <option value="<?php echo $i; ?>" <?php echo $minRating===$i?'selected':''; ?>><?php echo $i; ?>+ ★</option>
            <?php endfor; ?>
          </select>
        </div>
        <div>
          <label>Would Recommend</label>
          <select name="recommend">
            <option value="">All</option>
            <option value="Yes"   <?php echo $recommend==='Yes'   ?'selected':''; ?>>Yes</option>
            <option value="Maybe" <?php echo $recommend==='Maybe' ?'selected':''; ?>>Maybe</option>
            <option value="No"    <?php echo $recommend==='No'    ?'selected':''; ?>>No</option>
          </select>
        </div>
        <div style="padding-top:20px;">
          <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
          <a href="FeedbackDashboard.php" class="btn btn-secondary btn-sm">Reset</a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Summary cards ────────────────────────────────── -->
<?php
$total   = (int)($stats['total'] ?? 0);
$avgRat  = (float)($stats['avg_rating'] ?? 0);
$avgCol  = $avgRat >= 4 ? '#059669' : ($avgRat >= 3 ? '#d97706' : '#dc2626');
?>
<div class="fd-grid">
  <div class="fd-card">
    <div class="lbl">Total Responses</div>
    <div class="val" style="color:#7c3aed;"><?php echo number_format($total); ?></div>
  </div>
  <div class="fd-card">
    <div class="lbl">Average Rating</div>
    <div class="val" style="color:<?php echo $avgCol; ?>;"><?php echo $total ? number_format($avgRat,1).'★' : '—'; ?></div>
  </div>
  <div class="fd-card">
    <div class="lbl">Would Recommend</div>
    <div class="val nps-yes"><?php echo $stats['pct_yes']; ?>%</div>
    <div class="lbl">👍 Yes</div>
  </div>
  <div class="fd-card">
    <div class="lbl">Neutral</div>
    <div class="val nps-maybe"><?php echo $stats['pct_maybe']; ?>%</div>
    <div class="lbl">🤔 Maybe</div>
  </div>
  <div class="fd-card">
    <div class="lbl">Not Recommend</div>
    <div class="val nps-no"><?php echo $stats['pct_no']; ?>%</div>
    <div class="lbl">👎 No</div>
  </div>
</div>

<!-- ── Rating distribution + Sub-ratings ────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
  <!-- Rating distribution -->
  <div class="card">
    <div class="card-header">⭐ Rating Distribution</div>
    <div class="card-body" style="padding:16px 20px;">
      <?php
      $maxCnt = max(1, max($ratingDist));
      $barColors = [5=>'#059669',4=>'#65a30d',3=>'#d97706',2=>'#f97316',1=>'#dc2626'];
      foreach (array_reverse([1,2,3,4,5]) as $star):
        $cnt = $ratingDist[$star];
        $pct = round($cnt / $maxCnt * 100);
      ?>
      <div class="fd-chart-bar">
        <div class="bar-label"><?php echo $star; ?>★</div>
        <div class="bar-track">
          <div class="bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $barColors[$star]; ?>;"></div>
        </div>
        <div class="bar-val"><?php echo $cnt; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Sub-ratings -->
  <div class="card">
    <div class="card-header">🎯 Area Ratings (avg)</div>
    <div class="card-body" style="padding:16px 20px;">
      <?php
      $areas = [
        'avg_exam'    => '📝 Exam Experience',
        'avg_ui'      => '🎨 Interface Design',
        'avg_perf'    => '⚡ Speed & Performance',
        'avg_quality' => '❓ Question Quality',
        'avg_support' => '🤝 Support / Help',
      ];
      foreach ($areas as $key => $label):
        $v = (float)($stats[$key] ?? 0);
      ?>
      <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;font-size:.82rem;font-weight:600;margin-bottom:4px;">
          <span><?php echo $label; ?></span>
          <span style="color:#6b7280;"><?php echo $v ? number_format($v,1) : 'n/a'; ?></span>
        </div>
        <?php if ($v): echo starBar($v); endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── Timeline ──────────────────────────────────── -->
<?php if (!empty($timeline)): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">📅 Submission Timeline (last 30 days)</div>
  <div class="card-body" style="padding:16px 20px;">
    <div class="timeline-wrap">
      <div class="timeline-bars" id="tlBars">
        <?php
        $maxTl = max(1, max(array_column($timeline, 'cnt')));
        foreach ($timeline as $tl):
          $h = round((int)$tl['cnt'] / $maxTl * 100);
        ?>
        <div class="tl-bar" style="height:<?php echo $h; ?>%">
          <div class="tl-tip"><?php echo htmlspecialchars($tl['day']); ?><br><?php echo $tl['cnt']; ?> responses<br>Avg: <?php echo $tl['avg']; ?>★</div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:.7rem;color:#9ca3af;margin-top:4px;">
        <span><?php echo htmlspecialchars($timeline[0]['day'] ?? ''); ?></span>
        <span><?php echo htmlspecialchars($timeline[count($timeline)-1]['day'] ?? ''); ?></span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Individual feedback list ─────────────────── -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>📋 Individual Responses
      <?php if ($totalRows > 0): ?><span style="font-size:.8rem;font-weight:400;margin-left:8px;color:#9ca3af;">(<?php echo number_format($totalRows); ?> total)</span><?php endif; ?>
    </span>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <?php if (empty($feedbacks)): ?>
    <div style="padding:32px;text-align:center;color:#9ca3af;">
      No feedback found<?php echo $where ? ' matching filters' : ' yet'; ?>.
      <?php if (!$where): ?>
      <br><br><a href="../exam/feedback.php" target="_blank">Be the first to submit feedback ↗</a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <table class="fd-tbl">
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>User</th><th>Overall</th>
          <th>Exam</th><th>UI</th><th>Perf</th><th>Quality</th>
          <th>Recommend</th><th>Role</th><th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($feedbacks as $fb):
          $name = trim(($fb['FirstName']??'').' '.($fb['LastName']??'')) ?: 'Anonymous';
          $recClass = ['Yes'=>'nps-yes','No'=>'nps-no','Maybe'=>'nps-maybe'][$fb['WouldRecommend']??''] ?? '';
          $recLabel = ['Yes'=>'👍 Yes','No'=>'👎 No','Maybe'=>'🤔 Maybe'][$fb['WouldRecommend']??''] ?? '—';
        ?>
        <tr>
          <td style="color:#9ca3af;font-size:.75rem;"><?php echo (int)$fb['FeedbackId']; ?></td>
          <td style="white-space:nowrap;font-size:.78rem;"><?php echo date('d M y', strtotime($fb['CreatedAt'])); ?></td>
          <td>
            <div style="font-weight:600;font-size:.82rem;"><?php echo htmlspecialchars($name); ?></div>
            <?php if ($fb['InstituteName']): ?>
            <div style="font-size:.72rem;color:#6b7280;">🏫 <?php echo htmlspecialchars($fb['InstituteName']); ?></div>
            <?php endif; ?>
          </td>
          <td><?php echo ratingBadge((int)$fb['OverallRating']); ?></td>
          <td><?php echo ratingBadge((int)($fb['ExamExpRating']??0)); ?></td>
          <td><?php echo ratingBadge((int)($fb['UIRating']??0)); ?></td>
          <td><?php echo ratingBadge((int)($fb['PerfRating']??0)); ?></td>
          <td><?php echo ratingBadge((int)($fb['QualityRating']??0)); ?></td>
          <td><span class="<?php echo $recClass; ?>"><?php echo $recLabel; ?></span></td>
          <td style="font-size:.78rem;color:#6b7280;"><?php echo htmlspecialchars($fb['UserRole']??''); ?></td>
          <td>
            <button class="btn btn-sm btn-secondary" style="font-size:.72rem;padding:3px 8px;"
                    onclick="toggleDetail('fb<?php echo (int)$fb['FeedbackId']; ?>')">View</button>
          </td>
        </tr>
        <!-- Expandable detail row -->
        <tr id="fb<?php echo (int)$fb['FeedbackId']; ?>" style="display:none;">
          <td colspan="11" style="background:#f5f3ff;padding:16px 20px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
              <?php if ($fb['Categories']): ?>
              <div>
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:#7c3aed;margin-bottom:6px;">Categories</div>
                <div><?php
                  foreach (explode(',', $fb['Categories']) as $cat):
                    if (trim($cat)):
                ?><span style="display:inline-block;margin:2px;padding:2px 8px;background:#ede9fe;color:#7c3aed;border-radius:10px;font-size:.75rem;font-weight:600;"><?php echo htmlspecialchars(trim($cat)); ?></span><?php endif; endforeach; ?></div>
              </div>
              <?php endif; ?>
              <?php if ($fb['LikedMost']): ?>
              <div>
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:#059669;margin-bottom:6px;">👍 Liked Most</div>
                <div style="font-size:.84rem;color:#374151;"><?php echo nl2br(htmlspecialchars($fb['LikedMost'])); ?></div>
              </div>
              <?php endif; ?>
              <?php if ($fb['Improvements']): ?>
              <div>
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:#d97706;margin-bottom:6px;">🔧 Improvements</div>
                <div style="font-size:.84rem;color:#374151;"><?php echo nl2br(htmlspecialchars($fb['Improvements'])); ?></div>
              </div>
              <?php endif; ?>
              <?php if ($fb['FeatureRequests']): ?>
              <div>
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:#7c3aed;margin-bottom:6px;">🚀 Feature Requests</div>
                <div style="font-size:.84rem;color:#374151;"><?php echo nl2br(htmlspecialchars($fb['FeatureRequests'])); ?></div>
              </div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="paginate" style="padding:12px;">
      <?php
      $qStr = http_build_query(array_filter([
        'date_from'  => $dateFrom,
        'date_to'    => $dateTo,
        'min_rating' => $minRating ?: null,
        'recommend'  => $recommend,
      ]));
      for ($i = 1; $i <= $totalPages; $i++):
        $cls = ($i === $page) ? 'cur' : '';
      ?>
      <a class="<?php echo $cls; ?>" href="?<?php echo $qStr; ?>&p=<?php echo $i; ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php endif; /* tableExists */ ?>
</div><!-- .page-inner -->

<script>
function toggleDetail(id) {
  var row = document.getElementById(id);
  if (row) row.style.display = (row.style.display === 'none' || !row.style.display) ? 'table-row' : 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
