<?php
/**
 * Admin/InstituteStudents.php
 * Admin-only page: all institutes with their registered students.
 *
 *   ?id=0 (default) — searchable, paginated list of institutes with
 *                      student counts (counted by Role='STDNT' only).
 *   ?id=N            — full student roster for institute N, with its
 *                      own name search + pagination + Excel export.
 *
 * Replaces the old "View all" link on ManageInstitutes.php / InstituteReports.php
 * that pointed at the legacy, non-functional SearchStudent.php (mysql_* calls,
 * no institute filter — broken under PHP 7/8).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) {
    header('Location: ../exam/index.php');
    exit;
}

// Results depend entirely on the query string — never serve a stale cached page.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const PAGE_SIZE = 20;

function safeStr(?string $v): string { return trim($v ?? ''); }
function currentPage(string $key = 'p'): int { return max(1, (int)($_GET[$key] ?? 1)); }

function paginator(int $total, int $current, int $pageSize, array $qs, string $pageKey): string {
    if ($total <= $pageSize) return '';
    $pages = (int)ceil($total / $pageSize);
    $html  = '<div class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $url  = '?' . http_build_query(array_merge($qs, [$pageKey => $i]));
        $cls  = $i === $current ? 'pager-active' : 'pager-link';
        $html .= "<a href=\"{$url}\" class=\"{$cls}\">{$i}</a> ";
    }
    return $html . '</div>';
}

$instId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

/* ════════════════════════════════════════════════════════════════════════
   DETAIL VIEW — one institute's student roster
   ════════════════════════════════════════════════════════════════════════ */
if ($instId > 0) {
    $institute = Database::fetchOne(
        "SELECT InstituteId, InstituteName, InstituteType, State, CityVillage, Active
           FROM institutes WHERE InstituteId = ? LIMIT 1", [$instId]);

    if (!$institute) {
        header('Location: InstituteStudents.php');
        exit;
    }

    $rf_name = safeStr($_GET['rf_name'] ?? '');
    $rosterPage = currentPage('rp');

    $where  = ["u.InstituteId = ?", "l.Role = 'STDNT'"];
    $params = [$instId];
    if ($rf_name !== '') {
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
        $like     = "%{$rf_name}%";
        $params   = array_merge($params, [$like, $like, $like]);
    }
    $whereSQL = implode(' AND ', $where);

    $baseSQL = "FROM userinfo u
                LEFT JOIN logininfo l ON l.LoginName = u.LoginName
                WHERE {$whereSQL}";

    $rosterCount = (int)(Database::fetchOne(
        "SELECT COUNT(DISTINCT u.UserInfoId) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

    $offset = ($rosterPage - 1) * PAGE_SIZE;
    $roster = Database::fetchAll(
        "SELECT DISTINCT
                u.UserInfoId, u.FstName, u.LstName, u.LoginName, u.Mobile, u.EMail, l.Active,
                (SELECT MIN(lt.CreateDtm) FROM logintrackinfo lt
                  WHERE lt.UserId = u.UserInfoId) AS RegisteredAt
         {$baseSQL}
         ORDER BY u.FstName, u.LstName
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );

    $currentQS = array_filter(['id' => $instId, 'rf_name' => $rf_name]);

    $pageTitle = 'Institute Students';
    require_once __DIR__ . '/../includes/header.php';
?>
<style>
.is-wrap{max-width:1100px;margin:0 auto;padding:0 16px;}
.is-title{font-size:1.3rem;font-weight:700;color:var(--clr-primary);margin:0 0 4px;display:flex;align-items:center;gap:8px;}
.is-back{font-size:12px;color:#64748b;text-decoration:none;display:inline-block;margin-bottom:14px;}
.is-back:hover{color:var(--clr-primary);}
.is-subhead{font-size:12px;color:#64748b;margin-bottom:18px;}
.is-search{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:14px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.is-search input[type=text]{height:34px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;padding:0 10px;width:220px;}
.is-search button{background:var(--clr-gold);color:#fff;border:none;padding:0 20px;height:34px;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600;}
.is-table{width:100%;border-collapse:collapse;margin-top:4px;font-size:13px;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.is-table th{background:var(--clr-primary);color:#fff;padding:9px 12px;font-size:12px;text-align:left;white-space:nowrap;}
.is-table td{padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#1e293b;}
.is-table tr.odd td{background:#fff;} .is-table tr.even td{background:#f8fafc;} .is-table tr:hover td{background:#eff6ff;}
.badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;}
.badge-y{background:#dcfce7;color:#15803d;} .badge-n{background:#fee2e2;color:#b91c1c;}
.pager{margin:10px 0;font-size:12px;display:flex;flex-wrap:wrap;gap:4px;}
.pager-link{display:inline-block;padding:3px 10px;border:1px solid #cbd5e1;border-radius:4px;text-decoration:none;color:#475569;}
.pager-link:hover{border-color:var(--clr-primary);color:var(--clr-primary);}
.pager-active{display:inline-block;padding:3px 10px;border-radius:4px;background:var(--clr-primary);color:#fff;border:1px solid var(--clr-primary);}
.result-count{font-size:12px;color:#64748b;margin-bottom:8px;}
</style>

<div class="is-wrap">
  <a href="InstituteStudents.php" class="is-back">&larr; Back to all institutes</a>
  <div class="is-title">&#127979; <?= htmlspecialchars($institute['InstituteName']) ?></div>
  <div class="is-subhead">
    <?= htmlspecialchars($institute['InstituteType'] ?: '—') ?> &middot;
    <?= htmlspecialchars($institute['CityVillage'] ?: '—') ?>, <?= htmlspecialchars($institute['State'] ?: '—') ?> &middot;
    <a href="ManageInstitutes.php?action=view&id=<?= (int)$institute['InstituteId'] ?>">View institute details</a>
  </div>

  <form method="get" class="is-search">
    <input type="hidden" name="id" value="<?= (int)$instId ?>">
    <input type="text" name="rf_name" value="<?= htmlspecialchars($rf_name) ?>" placeholder="Search by name or username…">
    <button type="submit">Search</button>
    <?php if ($rf_name !== ''): ?><a href="?id=<?= (int)$instId ?>" style="font-size:12px;color:#64748b;">Reset</a><?php endif; ?>
  </form>

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
    <div class="result-count"><?= number_format($rosterCount) ?> student<?= $rosterCount===1?'':'s' ?> found</div>
    <a href="../exam/export-excel.php?type=students&<?= http_build_query(array_filter(['sf_institute' => $instId, 'sf_name' => $rf_name])) ?>"
       style="display:inline-flex;align-items:center;gap:6px;background:#16a34a;color:#fff;padding:6px 14px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;">
      &#x1F4E5; Export to Excel
    </a>
  </div>

  <?php if ($roster): ?>
  <?= paginator($rosterCount, $rosterPage, PAGE_SIZE, $currentQS, 'rp') ?>
  <table class="is-table">
    <thead><tr>
      <th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Mobile</th><th>Registered</th><th>Active</th><th></th>
    </tr></thead>
    <tbody>
    <?php
    $rowNum = ($rosterPage - 1) * PAGE_SIZE + 1;
    foreach ($roster as $i => $r):
        $rowClass = $i % 2 === 0 ? 'odd' : 'even';
        $active   = ($r['Active'] ?? 'N') === 'Y';
        $regDate  = !empty($r['RegisteredAt']) ? date('d M Y', strtotime($r['RegisteredAt'])) : '—';
    ?>
      <tr class="<?= $rowClass ?>">
        <td><?= $rowNum++ ?></td>
        <td><?= htmlspecialchars(Pii::name(trim($r['FstName'] . ' ' . $r['LstName']))) ?></td>
        <td><?= htmlspecialchars($r['LoginName'] ?? '') ?></td>
        <td><?= htmlspecialchars(Pii::email($r['EMail'] ?? '')) ?: '—' ?></td>
        <td><?= htmlspecialchars(Pii::mobile($r['Mobile'] ?? '')) ?: '—' ?></td>
        <td style="white-space:nowrap;font-size:11px;"><?= $regDate ?></td>
        <td><span class="badge <?= $active?'badge-y':'badge-n' ?>"><?= $active?'Yes':'No' ?></span></td>
        <td><a href="EditUser.php?id=<?= (int)$r['UserInfoId'] ?>" class="btn btn-xs">&#9999;&#65039; Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= paginator($rosterCount, $rosterPage, PAGE_SIZE, $currentQS, 'rp') ?>
  <?php else: ?>
    <p style="color:#888;font-size:13px;">No students registered to this institute yet<?= $rf_name!==''?' matching “'.htmlspecialchars($rf_name).'”':'' ?>.</p>
  <?php endif; ?>
</div>
<?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   LIST VIEW — all institutes with student counts
   ════════════════════════════════════════════════════════════════════════ */
$search      = safeStr($_GET['q'] ?? '');
$filterState = safeStr($_GET['state'] ?? '');
$filterType  = safeStr($_GET['type']  ?? '');
$listPage    = currentPage('p');

$where  = ["1=1"];
$params = [];
if ($search !== '') {
    $where[]  = "(i.InstituteName LIKE ? OR i.CityVillage LIKE ? OR i.State LIKE ?)";
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($filterState !== '') { $where[] = "i.State = ?";         $params[] = $filterState; }
if ($filterType  !== '') { $where[] = "i.InstituteType = ?"; $params[] = $filterType;  }
$whereSQL = implode(' AND ', $where);

$total = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS cnt FROM institutes i WHERE {$whereSQL}", $params)['cnt'] ?? 0);

$offset = ($listPage - 1) * PAGE_SIZE;
$institutes = Database::fetchAll(
    "SELECT i.InstituteId, i.InstituteName, i.InstituteType, i.State, i.CityVillage, i.Active,
            COUNT(DISTINCT CASE WHEN l.Role = 'STDNT' THEN u.UserInfoId END) AS StudentCount,
            (SELECT ContactName FROM institute_contacts
              WHERE InstituteId=i.InstituteId AND IsPrimary=1 AND Active='Y' LIMIT 1) AS PrimaryContact
       FROM institutes i
       LEFT JOIN userinfo  u ON u.InstituteId = i.InstituteId
       LEFT JOIN logininfo l ON l.LoginName    = u.LoginName
      WHERE {$whereSQL}
      GROUP BY i.InstituteId
      ORDER BY i.State, i.InstituteName
      LIMIT {$offset}, " . PAGE_SIZE,
    $params
);

$states = Database::fetchAll("SELECT DISTINCT State FROM institutes WHERE State IS NOT NULL AND State != '' ORDER BY State");
$types  = Database::fetchAll("SELECT DISTINCT InstituteType FROM institutes WHERE InstituteType IS NOT NULL AND InstituteType != '' ORDER BY InstituteType");

$currentQS = array_filter(['q' => $search, 'state' => $filterState, 'type' => $filterType]);

$pageTitle = 'Institutes & Students';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.is-wrap{max-width:1100px;margin:0 auto;padding:0 16px;}
.is-title{font-size:1.3rem;font-weight:700;color:var(--clr-primary);margin:0 0 16px;display:flex;align-items:center;gap:8px;}
.is-search{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:14px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.is-search input[type=text],.is-search select{height:34px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;padding:0 10px;}
.is-search input[type=text]{width:220px;} .is-search select{width:170px;}
.is-search button{background:var(--clr-gold);color:#fff;border:none;padding:0 20px;height:34px;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600;}
.is-table{width:100%;border-collapse:collapse;margin-top:4px;font-size:13px;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.is-table th{background:var(--clr-primary);color:#fff;padding:9px 12px;font-size:12px;text-align:left;white-space:nowrap;}
.is-table td{padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#1e293b;}
.is-table tr.odd td{background:#fff;} .is-table tr.even td{background:#f8fafc;} .is-table tr:hover td{background:#eff6ff;}
.badge-type{display:inline-block;padding:2px 10px;border-radius:12px;font-size:.72rem;font-weight:600;background:#f1f5f9;color:#475569;}
.badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;}
.badge-y{background:#dcfce7;color:#15803d;} .badge-n{background:#fee2e2;color:#b91c1c;}
.pager{margin:10px 0;font-size:12px;display:flex;flex-wrap:wrap;gap:4px;}
.pager-link{display:inline-block;padding:3px 10px;border:1px solid #cbd5e1;border-radius:4px;text-decoration:none;color:#475569;}
.pager-link:hover{border-color:var(--clr-primary);color:var(--clr-primary);}
.pager-active{display:inline-block;padding:3px 10px;border-radius:4px;background:var(--clr-primary);color:#fff;border:1px solid var(--clr-primary);}
.result-count{font-size:12px;color:#64748b;margin-bottom:8px;}
.is-btn{display:inline-block;background:var(--clr-primary);color:#fff;padding:5px 14px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;}
.is-btn:hover{filter:brightness(1.1);}
</style>

<div class="is-wrap">
  <div class="is-title">&#127979; Institutes &amp; Students</div>

  <form method="get" class="is-search">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search institute, city, state…">
    <select name="state">
      <option value="">— All States —</option>
      <?php foreach ($states as $s): $sv = $s['State']; ?>
        <option value="<?= htmlspecialchars($sv) ?>" <?= $filterState===$sv?'selected':'' ?>><?= htmlspecialchars($sv) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type">
      <option value="">— All Types —</option>
      <?php foreach ($types as $t): $tv = $t['InstituteType']; ?>
        <option value="<?= htmlspecialchars($tv) ?>" <?= $filterType===$tv?'selected':'' ?>><?= htmlspecialchars($tv) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Search</button>
    <?php if ($search!==''||$filterState!==''||$filterType!==''): ?><a href="InstituteStudents.php" style="font-size:12px;color:#64748b;">Reset</a><?php endif; ?>
  </form>

  <div class="result-count"><?= number_format($total) ?> institute<?= $total===1?'':'s' ?> found</div>

  <?php if ($institutes): ?>
  <?= paginator($total, $listPage, PAGE_SIZE, $currentQS, 'p') ?>
  <table class="is-table">
    <thead><tr>
      <th>Institute</th><th>Type</th><th>Location</th><th>Primary Contact</th><th>Students</th><th>Active</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($institutes as $i => $inst): $rowClass = $i % 2 === 0 ? 'odd' : 'even'; ?>
      <tr class="<?= $rowClass ?>">
        <td><?= htmlspecialchars($inst['InstituteName']) ?></td>
        <td><span class="badge-type"><?= htmlspecialchars($inst['InstituteType'] ?: '—') ?></span></td>
        <td><?= htmlspecialchars($inst['CityVillage'] ?: '—') ?>, <?= htmlspecialchars($inst['State'] ?: '—') ?></td>
        <td><?= htmlspecialchars($inst['PrimaryContact'] ?: '—') ?></td>
        <td><strong><?= (int)$inst['StudentCount'] ?></strong></td>
        <td><span class="badge <?= $inst['Active']==='Y'?'badge-y':'badge-n' ?>"><?= $inst['Active']==='Y'?'Yes':'No' ?></span></td>
        <td><a href="?id=<?= (int)$inst['InstituteId'] ?>" class="is-btn">View Students</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= paginator($total, $listPage, PAGE_SIZE, $currentQS, 'p') ?>
  <?php else: ?>
    <p style="color:#888;font-size:13px;">No institutes match the current filters.</p>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
