<?php
/**
 * Admin/InstituteAdminStudents.php
 * Institute-Admin: roster of students belonging to their own institute
 * (Auth::currentInstituteId()). Read-only — links through to
 * InstituteAdminStudentDetail.php for performance / assigned / forthcoming
 * exams, and to ResetStudentPassword.php for a password reset.
 *
 * A full Admin may also open this page for support, via ?instId=.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isInstituteAdmin() && !Auth::isAdmin()) {
    header('Location: ../exam/search.php');
    exit;
}

$isFullAdmin = Auth::isAdmin() && !Auth::isInstituteAdmin();
$instId = $isFullAdmin
    ? (filter_input(INPUT_GET, 'instId', FILTER_VALIDATE_INT) ?: 0)
    : (Auth::currentInstituteId() ?? 0);

if ($instId <= 0) {
    header('Location: InstituteAdminHome.php');
    exit;
}

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

$institute = Database::fetchOne(
    "SELECT InstituteId, InstituteName, InstituteType, State, CityVillage
       FROM institutes WHERE InstituteId = ? LIMIT 1", [$instId]);

if (!$institute) {
    header('Location: InstituteAdminHome.php');
    exit;
}

$search = safeStr($_GET['q'] ?? '');
$page   = currentPage('p');

$where  = ["u.InstituteId = ?", "l.Role = 'STDNT'"];
$params = [$instId];
if ($search !== '') {
    $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like]);
}
$whereSQL = implode(' AND ', $where);

$baseSQL = "FROM userinfo u
            LEFT JOIN logininfo l ON l.LoginName = u.LoginName
            WHERE {$whereSQL}";

$total = (int)(Database::fetchOne(
    "SELECT COUNT(DISTINCT u.UserInfoId) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

$offset = ($page - 1) * PAGE_SIZE;
$roster = Database::fetchAll(
    "SELECT DISTINCT u.UserInfoId, u.FstName, u.LstName, u.LoginName, u.Mobile, u.EMail, l.Active,
            (SELECT COUNT(*) FROM exam_assignments ea WHERE ea.UserInfoId = u.UserInfoId) AS AssignedCount,
            (SELECT COUNT(*) FROM exam_assignments ea WHERE ea.UserInfoId = u.UserInfoId AND ea.Status = 'Assigned') AS ForthcomingCount,
            (SELECT COUNT(*) FROM studentexam se WHERE se.UserInfoId = u.UserInfoId) AS AttemptCount
     {$baseSQL}
     ORDER BY u.FstName, u.LstName
     LIMIT {$offset}, " . PAGE_SIZE,
    $params
);

$currentQS = array_filter(['q' => $search] + ($isFullAdmin ? ['instId' => $instId] : []));

$pageTitle = 'Students — ' . $institute['InstituteName'];
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
.badge-fc{background:#fef3c7;color:#92400e;}
.pager{margin:10px 0;font-size:12px;display:flex;flex-wrap:wrap;gap:4px;}
.pager-link{display:inline-block;padding:3px 10px;border:1px solid #cbd5e1;border-radius:4px;text-decoration:none;color:#475569;}
.pager-link:hover{border-color:var(--clr-primary);color:var(--clr-primary);}
.pager-active{display:inline-block;padding:3px 10px;border-radius:4px;background:var(--clr-primary);color:#fff;border:1px solid var(--clr-primary);}
.result-count{font-size:12px;color:#64748b;margin-bottom:8px;}
</style>

<div class="is-wrap">
  <a href="InstituteAdminHome.php<?= $isFullAdmin ? '?instId='.$instId : '' ?>" class="is-back">&larr; Back to Dashboard</a>
  <div class="is-title">&#128101; <?= htmlspecialchars($institute['InstituteName']) ?> — Students</div>
  <div class="is-subhead"><?= htmlspecialchars($institute['CityVillage'] ?: '—') ?>, <?= htmlspecialchars($institute['State'] ?: '—') ?></div>

  <form method="get" class="is-search">
    <?php if ($isFullAdmin): ?><input type="hidden" name="instId" value="<?= (int)$instId ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or username…">
    <button type="submit">Search</button>
    <?php if ($search !== ''): ?>
      <a href="?<?= $isFullAdmin ? 'instId='.$instId : '' ?>" style="font-size:12px;color:#64748b;">Reset</a>
    <?php endif; ?>
  </form>

  <div class="result-count"><?= number_format($total) ?> student<?= $total===1?'':'s' ?> found</div>

  <?php if ($roster): ?>
  <?= paginator($total, $page, PAGE_SIZE, $currentQS, 'p') ?>
  <table class="is-table">
    <thead><tr>
      <th>#</th><th>Name</th><th>Username</th><th>Contact</th>
      <th>Forthcoming</th><th>Assigned Total</th><th>Attempts</th><th>Active</th><th></th>
    </tr></thead>
    <tbody>
    <?php
    $rowNum = ($page - 1) * PAGE_SIZE + 1;
    foreach ($roster as $i => $r):
        $rowClass = $i % 2 === 0 ? 'odd' : 'even';
        $active   = ($r['Active'] ?? 'N') === 'Y';
    ?>
      <tr class="<?= $rowClass ?>">
        <td><?= $rowNum++ ?></td>
        <td><?= htmlspecialchars(Pii::name(trim($r['FstName'] . ' ' . $r['LstName']))) ?></td>
        <td><?= htmlspecialchars($r['LoginName'] ?? '') ?></td>
        <td>
          <?= htmlspecialchars(Pii::email($r['EMail'] ?? '')) ?: '—' ?>
          <?php if (!empty($r['Mobile'])): ?><div style="color:#64748b;font-size:11px;"><?= htmlspecialchars(Pii::mobile($r['Mobile'])) ?></div><?php endif; ?>
        </td>
        <td><?php if ((int)$r['ForthcomingCount'] > 0): ?><span class="badge badge-fc"><?= (int)$r['ForthcomingCount'] ?></span><?php else: ?>—<?php endif; ?></td>
        <td><?= (int)$r['AssignedCount'] ?></td>
        <td><?= (int)$r['AttemptCount'] ?></td>
        <td><span class="badge <?= $active?'badge-y':'badge-n' ?>"><?= $active?'Yes':'No' ?></span></td>
        <td style="white-space:nowrap;">
          <a href="InstituteAdminStudentDetail.php?id=<?= (int)$r['UserInfoId'] ?><?= $isFullAdmin ? '&instId='.$instId : '' ?>" class="btn btn-xs">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= paginator($total, $page, PAGE_SIZE, $currentQS, 'p') ?>
  <?php else: ?>
    <p style="color:#888;font-size:13px;">No students found<?= $search!==''?' matching “'.htmlspecialchars($search).'”':' for this institute yet' ?>.</p>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
