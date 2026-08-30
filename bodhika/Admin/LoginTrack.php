<?php
/**
 * Admin/LoginTrack.php — Login History
 *
 * Shows every login event with: Name, Login, Role, Institute,
 * Login Time, Last Login (from logininfo), IP Address, Device.
 * Filters: name/login search, role, institute, date range.
 * Export to CSV/Excel via export-excel.php?type=login_history.
 *
 * Requires: migration_v28.sql (IpAddress, UserAgent columns on logintrackinfo).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

/* ── Helpers ──────────────────────────────────────────────────────────── */

/** Parse a User-Agent string into a short device/browser description. */
function parseDevice(?string $ua): string {
    if (!$ua) return '—';
    $ua = (string)$ua;

    // Device type
    if (preg_match('/\b(iPad)\b/i', $ua))           $device = 'Tablet (iPad)';
    elseif (preg_match('/\b(Android)\b/i', $ua) && preg_match('/\b(Mobile)\b/i', $ua)) $device = 'Mobile (Android)';
    elseif (preg_match('/\b(iPhone|iPod)\b/i', $ua)) $device = 'Mobile (iOS)';
    elseif (preg_match('/\b(Android)\b/i', $ua))     $device = 'Tablet (Android)';
    elseif (preg_match('/\b(Windows Phone)\b/i', $ua)) $device = 'Mobile (Windows)';
    elseif (preg_match('/\b(Windows)\b/i', $ua))     $device = 'Desktop (Windows)';
    elseif (preg_match('/\b(Macintosh|Mac OS X)\b/i', $ua)) $device = 'Desktop (Mac)';
    elseif (preg_match('/\b(Linux)\b/i', $ua))       $device = 'Desktop (Linux)';
    elseif (preg_match('/\b(CrOS)\b/i', $ua))        $device = 'Chromebook';
    else                                              $device = 'Unknown';

    // Browser
    if     (preg_match('/\bEdg(?:e|\/)\b/i', $ua))          $browser = 'Edge';
    elseif (preg_match('/\bOPR\/|Opera\b/i', $ua))           $browser = 'Opera';
    elseif (preg_match('/\bChrome\/(\d+)/i', $ua, $m))       $browser = 'Chrome '.$m[1];
    elseif (preg_match('/\bFirefox\/(\d+)/i', $ua, $m))      $browser = 'Firefox '.$m[1];
    elseif (preg_match('/\bSafari\/\d+/i', $ua) && !preg_match('/\bChrome\b/i', $ua))
                                                              $browser = 'Safari';
    elseif (preg_match('/\bMSIE (\d+)|Trident\//i', $ua))    $browser = 'IE';
    else                                                      $browser = '';

    return $browser ? "$device · $browser" : $device;
}

/* ── Filters ──────────────────────────────────────────────────────────── */
$fSearch    = trim($_GET['q']           ?? '');
$fRole      = trim($_GET['role']        ?? '');
$fInstitute = filter_input(INPUT_GET, 'institute', FILTER_VALIDATE_INT) ?: 0;
$fDateFrom  = trim($_GET['date_from']   ?? '');
$fDateTo    = trim($_GET['date_to']     ?? '');

$perPage    = 50;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $perPage;

/* ── Build WHERE ──────────────────────────────────────────────────────── */
$where  = ['1=1'];
$params = [];

if ($fSearch !== '') {
    $like = '%' . $fSearch . '%';
    $where[]  = '(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ? OR li_neg.LoginName LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($fRole !== '') {
    $where[]  = 'COALESCE(li_pos.Role, li_neg.Role) LIKE ?';
    $params[] = '%' . $fRole . '%';
}
if ($fInstitute > 0) {
    $where[]  = 'u.InstituteId = ?';
    $params[] = $fInstitute;
}
if ($fDateFrom !== '') {
    $where[]  = 'DATE(lt.CreateDtm) >= ?';
    $params[] = $fDateFrom;
}
if ($fDateTo !== '') {
    $where[]  = 'DATE(lt.CreateDtm) <= ?';
    $params[] = $fDateTo;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$joinSQL = "FROM logintrackinfo lt
            LEFT JOIN userinfo  u       ON u.UserInfoId   = lt.UserId AND lt.UserId > 0
            LEFT JOIN logininfo li_pos  ON li_pos.LoginName = u.LoginName AND lt.UserId > 0
            LEFT JOIN logininfo li_neg  ON li_neg.LoginInfoId = -lt.UserId AND lt.UserId < 0
            LEFT JOIN institutes inst   ON inst.InstituteId = u.InstituteId";

/* ── Counts for pagination ────────────────────────────────────────────── */
$total   = (int)(Database::fetchOne("SELECT COUNT(*) AS n $joinSQL $whereSQL", $params)['n'] ?? 0);
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

/* ── Fetch rows ───────────────────────────────────────────────────────── */
$rows = Database::fetchAll(
    "SELECT
         lt.UserId,
         lt.CreateDtm,
         lt.IpAddress,
         lt.UserAgent,
         COALESCE(u.FstName, '')          AS FstName,
         COALESCE(u.LstName, '')          AS LstName,
         COALESCE(u.LoginName, li_neg.LoginName, '') AS LoginName,
         COALESCE(li_pos.Role, li_neg.Role, '')      AS Role,
         COALESCE(li_pos.last_login, li_neg.last_login) AS LastLogin,
         COALESCE(inst.InstituteName, '')             AS InstituteName
     $joinSQL $whereSQL
     ORDER BY lt.CreateDtm DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

/* ── Dropdown data ────────────────────────────────────────────────────── */
$roles      = Database::fetchAll("SELECT DISTINCT Role FROM logininfo WHERE Role IS NOT NULL AND Role != '' ORDER BY Role", []);
$institutes = Database::fetchAll("SELECT InstituteId, InstituteName FROM institutes ORDER BY InstituteName", []);

/* ── Export query string (pass all active filters) ────────────────────── */
$exportQS = http_build_query(array_filter([
    'type'      => 'login_history',
    'q'         => $fSearch,
    'role'      => $fRole,
    'institute' => $fInstitute ?: '',
    'date_from' => $fDateFrom,
    'date_to'   => $fDateTo,
]));

/* ── Page layout ──────────────────────────────────────────────────────── */
$pageTitle = 'Login History';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
    <h2 style="margin:0;font-size:1.1rem;">Login History
      <?php if ($total > 0): ?>
        <span style="font-size:.8rem;font-weight:400;color:var(--tx-muted);margin-left:.5rem;"><?= number_format($total) ?> event<?= $total !== 1 ? 's' : '' ?></span>
      <?php endif; ?>
    </h2>
    <a href="../exam/export-excel.php?<?= htmlspecialchars($exportQS) ?>"
       class="btn btn-success btn-sm" title="Download as CSV / Excel">
      ↓ Export to Excel
    </a>
  </div>

  <div class="card-body">

    <!-- Filter bar -->
    <form method="get" action="" class="form-inline-row" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin-bottom:1.2rem;">

      <div class="form-group" style="flex:1 1 180px;min-width:140px;">
        <label class="form-label">Name / Login</label>
        <input class="form-control" name="q" value="<?= htmlspecialchars($fSearch) ?>" placeholder="Search…">
      </div>

      <div class="form-group" style="flex:1 1 140px;min-width:120px;">
        <label class="form-label">Role</label>
        <select class="form-control" name="role">
          <option value="">All roles</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= htmlspecialchars($r['Role']) ?>"
              <?= $fRole === $r['Role'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($r['Role']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="flex:1 1 160px;min-width:120px;">
        <label class="form-label">Institute</label>
        <select class="form-control" name="institute">
          <option value="">All institutes</option>
          <?php foreach ($institutes as $inst): ?>
            <option value="<?= (int)$inst['InstituteId'] ?>"
              <?= $fInstitute === (int)$inst['InstituteId'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($inst['InstituteName']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="flex:0 0 auto;">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="date_from"
               value="<?= htmlspecialchars($fDateFrom) ?>">
      </div>

      <div class="form-group" style="flex:0 0 auto;">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="date_to"
               value="<?= htmlspecialchars($fDateTo) ?>">
      </div>

      <div style="display:flex;gap:.4rem;align-items:flex-end;">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="LoginTrack.php" class="btn btn-secondary btn-sm">Clear</a>
      </div>

    </form>

    <?php if (empty($rows)): ?>
      <div class="alert alert-info">No login events found for the selected filters.</div>
    <?php else: ?>

      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Login</th>
              <th>Role</th>
              <th>Institute</th>
              <th>Login Time</th>
              <th>Last Login</th>
              <th>IP Address</th>
              <th>Device</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $rowNum = $offset + 1;
            foreach ($rows as $row):
                $name    = trim($row['FstName'] . ' ' . $row['LstName']) ?: ($row['LoginName'] ?: '—');
                $login   = $row['LoginName'] ?: '—';
                $role    = $row['Role']       ?: '—';
                $inst    = $row['InstituteName'] ?: '—';
                $loginAt = $row['CreateDtm']  ? date('d M Y, H:i', strtotime($row['CreateDtm'])) : '—';
                $lastLog = $row['LastLogin']  ? date('d M Y, H:i', strtotime($row['LastLogin'])) : '—';
                $ip      = htmlspecialchars($row['IpAddress'] ?? '—');
                $device  = htmlspecialchars(parseDevice($row['UserAgent']));
            ?>
            <tr>
              <td><?= $rowNum++ ?></td>
              <td><?= htmlspecialchars($name) ?></td>
              <td style="font-family:monospace;font-size:.85em;"><?= htmlspecialchars($login) ?></td>
              <td>
                <span class="badge" style="
                  background:<?= match(strtoupper($role)) {
                    'ADMIN','ADMN','PRCIPAL','PRINCIPAL' => 'var(--clr-primary)',
                    'TEACHER','TEACH' => '#0d6efd',
                    'STDNT' => '#198754',
                    default => '#6c757d'
                  } ?>;color:#fff;padding:2px 7px;border-radius:3px;font-size:.75em;">
                  <?= htmlspecialchars($role) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($inst) ?></td>
              <td style="white-space:nowrap;"><?= $loginAt ?></td>
              <td style="white-space:nowrap;"><?= $lastLog ?></td>
              <td style="font-family:monospace;font-size:.85em;"><?= $ip ?></td>
              <td style="font-size:.85em;"><?= $device ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($pages > 1): ?>
      <div style="display:flex;justify-content:center;gap:.4rem;flex-wrap:wrap;margin-top:1rem;">
        <?php
        $qBase = array_filter([
            'q'         => $fSearch,
            'role'      => $fRole,
            'institute' => $fInstitute ?: '',
            'date_from' => $fDateFrom,
            'date_to'   => $fDateTo,
        ]);
        $rangeStart = max(1, $page - 3);
        $rangeEnd   = min($pages, $page + 3);
        if ($page > 1):
            $qs = http_build_query($qBase + ['page' => $page - 1]);
        ?>
          <a href="?<?= $qs ?>" class="btn btn-secondary btn-sm">‹ Prev</a>
        <?php endif;
        for ($p = $rangeStart; $p <= $rangeEnd; $p++):
            $qs = http_build_query($qBase + ['page' => $p]);
        ?>
          <a href="?<?= $qs ?>" class="btn <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= $p ?></a>
        <?php endfor;
        if ($page < $pages):
            $qs = http_build_query($qBase + ['page' => $page + 1]);
        ?>
          <a href="?<?= $qs ?>" class="btn btn-secondary btn-sm">Next ›</a>
        <?php endif; ?>
      </div>
      <p style="text-align:center;font-size:.8rem;color:var(--tx-muted);margin-top:.5rem;">
        Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> events
      </p>
      <?php endif; ?>

    <?php endif; ?>

  </div><!-- /card-body -->
</div><!-- /card -->

<?php include __DIR__ . '/../includes/footer.php'; ?>
