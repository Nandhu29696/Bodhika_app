<?php
/**
 * Admin/CertificatesIssued.php — searchable list of every issued certificate.
 *
 * Lets admin:
 *   - Search/filter by student name, certificate no., course, type, status
 *   - View/reprint any single certificate (opens exam/certificate-print.php)
 *   - Reprint a batch of selected certificates at once (same renderer,
 *     ?batch=1,2,3 — reuses Admin/GenerateCertificates.php's print path)
 *   - Revoke an issued certificate, or restore a revoked one
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Certificate.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../exam/search.php'); exit; }

$flash = '';

/* ── Handle POST — revoke / restore ──────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';
    $certId = (int)($_POST['CertificateId'] ?? 0);

    if ($certId > 0 && in_array($action, ['revoke', 'restore'], true)) {
        $newStatus = $action === 'revoke' ? 'Revoked' : 'Issued';
        $ok = Certificate::setStatus($certId, $newStatus);
        $flash = $ok
            ? 'success|Certificate ' . ($action === 'revoke' ? 'revoked' : 'restored') . '.'
            : 'error|Could not update certificate status.';
    }

    header('Location: CertificatesIssued.php?' . http_build_query(array_filter([
        'search'   => $_GET['search']   ?? null,
        'certType' => $_GET['certType'] ?? null,
        'status'   => $_GET['status']   ?? null,
    ])) . '&flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = urldecode($_GET['flash']);
[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];

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

/* ── Filters ──────────────────────────────────────────────────────────────── */
$filters = [
    'search'   => trim($_GET['search']   ?? ''),
    'certType' => trim($_GET['certType'] ?? ''),
    'status'   => trim($_GET['status']   ?? ''),
];
$page = currentPage('p');

// Certificate::listIssued() used to cap out at a flat 500 rows, no offset —
// fine while certificate volume was small, but a hard ceiling with no paging
// means row 501 onward silently never shows up, and the "Matching" / Issued /
// Revoked counts below it were counting that same capped set instead of the
// real totals. Now it's real LIMIT/OFFSET paging, and the counts come from a
// COUNT(*)/GROUP BY query instead of tallying a truncated row array.
$totalCount   = Certificate::countIssued($filters);
$statusCounts = Certificate::countIssuedByStatus($filters);
$issuedCount  = $statusCounts['Issued'];
$revokedCount = $statusCounts['Revoked'];

$offset = ($page - 1) * PAGE_SIZE;
$certs  = Certificate::listIssued($filters, PAGE_SIZE, $offset);

$qsCerts = array_filter($filters);

$pageTitle = 'Certificates Issued';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .cert-filter-bar { display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px; }
  .cert-filter-bar .form-group { margin-bottom:0;min-width:160px; }
  .cert-no { font-family:monospace;font-size:.82rem;color:#374151; }
  .grade-chip { padding:2px 9px;border-radius:10px;font-size:.74rem;font-weight:700;background:#fef3c7;color:#92400e; }
  .pager        { margin:10px 0; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
  .pager-link   { display:inline-block; padding:3px 10px; border:1px solid #cbd5e1; border-radius:4px;
                   text-decoration:none; color:#475569; }
  .pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
  .pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                   background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }
</style>

<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
  <a href="AppSettings.php?tab=certificate" style="color:#3182ce;text-decoration:none;">&#9881; Certificates</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <a href="CertificateTemplates.php" style="color:#3182ce;text-decoration:none;">Templates</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <a href="GenerateCertificates.php" style="color:#3182ce;text-decoration:none;">Generate</a>
  <span style="margin:0 6px;">&rsaquo;</span>
  <span>Issued</span>
</nav>

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType==='success' ? 'success' : 'danger' ?>" style="margin-bottom:14px;">
  <?= htmlspecialchars($flashMsg) ?>
</div>
<?php endif; ?>

<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
  <div class="card" style="flex:1;min-width:140px;text-align:center;padding:14px;">
    <div style="font-size:1.6rem;font-weight:800;color:#1e3a5f;"><?= $totalCount ?></div>
    <div style="font-size:.78rem;color:#718096;">Matching</div>
  </div>
  <div class="card" style="flex:1;min-width:140px;text-align:center;padding:14px;">
    <div style="font-size:1.6rem;font-weight:800;color:#059669;"><?= $issuedCount ?></div>
    <div style="font-size:.78rem;color:#718096;">Issued</div>
  </div>
  <div class="card" style="flex:1;min-width:140px;text-align:center;padding:14px;">
    <div style="font-size:1.6rem;font-weight:800;color:#dc2626;"><?= $revokedCount ?></div>
    <div style="font-size:.78rem;color:#718096;">Revoked</div>
  </div>
  <a href="GenerateCertificates.php" class="card" style="flex:1;min-width:160px;text-align:center;padding:14px;
     text-decoration:none;display:flex;flex-direction:column;justify-content:center;background:#1e3a5f;color:#fff;">
    <div style="font-size:1.1rem;font-weight:700;">&#10010; Issue New</div>
  </a>
</div>

<form method="get" action="CertificatesIssued.php" class="cert-filter-bar">
  <div class="form-group" style="flex:2;">
    <label class="form-label">Search</label>
    <input type="text" name="search" class="form-control" placeholder="Student, certificate no., or course…"
           value="<?= htmlspecialchars($filters['search']) ?>">
  </div>
  <div class="form-group">
    <label class="form-label">Type</label>
    <select name="certType" class="form-control">
      <option value="">All Types</option>
      <option value="completion" <?= $filters['certType']==='completion'?'selected':'' ?>>&#127891; Completion</option>
      <option value="merit"      <?= $filters['certType']==='merit'?'selected':''      ?>>&#127942; Merit</option>
    </select>
  </div>
  <div class="form-group">
    <label class="form-label">Status</label>
    <select name="status" class="form-control">
      <option value="">All Statuses</option>
      <option value="Issued"  <?= $filters['status']==='Issued'?'selected':''  ?>>Issued</option>
      <option value="Revoked" <?= $filters['status']==='Revoked'?'selected':'' ?>>Revoked</option>
    </select>
  </div>
  <div>
    <button type="submit" class="btn btn-primary">&#128269; Filter</button>
    <a href="CertificatesIssued.php" class="btn btn-secondary" style="margin-left:6px;">Clear</a>
  </div>
</form>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#127942; Issued Certificates (<?= $totalCount ?>)</span>
    <span>
      <span style="font-size:.82rem;color:#718096;margin-right:8px;"><span id="selCount">0</span> selected</span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="printSelected()">&#128424; Print Selected</button>
    </span>
  </div>
  <div class="tbl-wrap">
    <table class="tbl" style="font-size:.85rem;">
      <thead>
        <tr>
          <th><input type="checkbox" onchange="toggleAll(this)"></th>
          <th>Certificate No.</th>
          <th>Student</th>
          <th>Course / Duration</th>
          <th>Type</th>
          <th>Score / Grade</th>
          <th>Issue Date</th>
          <th>Template</th>
          <th class="text-center">Status</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$certs): ?>
        <tr><td colspan="10" style="text-align:center;color:var(--clr-text-muted);padding:24px;">No certificates match these filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($certs as $c): ?>
        <tr>
          <td><input type="checkbox" class="cert-cb" value="<?= (int)$c['CertificateId'] ?>" onchange="updateSelCount()"></td>
          <td class="cert-no"><?= htmlspecialchars($c['CertificateNo']) ?></td>
          <td>
            <?= htmlspecialchars($c['StudentName']) ?>
            <?php if (!empty($c['OwnerLogin'])): ?>
              <br><span style="font-size:.76rem;color:#6b7280;">Login: <?= htmlspecialchars($c['OwnerLogin']) ?><?= $c['OwnerActive'] === 'N' ? ' (inactive)' : '' ?></span>
            <?php else: ?>
              <br><span style="font-size:.76rem;color:#dc2626;">&#9888; No matching account (UserInfoId <?= (int)$c['UserInfoId'] ?>)</span>
            <?php endif; ?>
          </td>
          <td>
            <?= htmlspecialchars($c['CourseName']) ?>
            <?php if ($c['Duration']): ?>
              <br><span style="font-size:.76rem;color:#6b7280;"><?= htmlspecialchars($c['Duration']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= $c['CertType'] === 'merit' ? '&#127942; Merit' : '&#127891; Completion' ?></td>
          <td>
            <?php if ($c['Percentage'] !== null): ?>
              <?= rtrim(rtrim((string)$c['Percentage'], '0'), '.') ?: $c['Percentage'] ?>%
              <?php if ($c['Grade']): ?>
                <br><span class="grade-chip"><?= htmlspecialchars($c['Grade']) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#9ca3af;">—</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars(date('d M Y', strtotime((string)$c['IssueDate']))) ?></td>
          <td><?= htmlspecialchars($c['TemplateName'] ?? '—') ?></td>
          <td class="text-center">
            <span class="badge-<?= $c['Status']==='Issued' ? 'pass' : 'fail' ?>">
              <?= htmlspecialchars($c['Status']) ?>
            </span>
          </td>
          <td class="text-center" style="white-space:nowrap;">
            <a href="../exam/certificate-print.php?id=<?= (int)$c['CertificateId'] ?>"
               target="_blank" class="btn btn-secondary btn-sm">&#128065; View</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?= $c['Status']==='Issued' ? 'Revoke this certificate?' : 'Restore this certificate?' ?>');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
              <input type="hidden" name="action" value="<?= $c['Status']==='Issued' ? 'revoke' : 'restore' ?>">
              <input type="hidden" name="CertificateId" value="<?= (int)$c['CertificateId'] ?>">
              <button type="submit" class="btn btn-<?= $c['Status']==='Issued' ? 'danger' : 'success' ?> btn-sm">
                <?= $c['Status']==='Issued' ? 'Revoke' : 'Restore' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= paginator($totalCount, $page, PAGE_SIZE, $qsCerts, 'p') ?>
</div>

<script>
// "Select all" / "Print Selected" only apply to certificates visible on the
// current page — this mirrors printing/export controls elsewhere in this
// codebase (e.g. Export2_XL_*.php pages) and avoids the complexity of
// cross-page selection persistence for what's fundamentally a "reprint what
// I'm looking at right now" action rather than a batch edit.
function toggleAll(master) {
  document.querySelectorAll('.cert-cb').forEach(function(cb){ cb.checked = master.checked; });
  updateSelCount();
}
function updateSelCount() {
  document.getElementById('selCount').textContent =
    document.querySelectorAll('.cert-cb:checked').length;
}
function printSelected() {
  var ids = Array.from(document.querySelectorAll('.cert-cb:checked')).map(function(cb){ return cb.value; });
  if (!ids.length) { alert('Select at least one certificate first.'); return; }
  window.open('../exam/certificate-print.php?batch=' + ids.join(','), '_blank');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
