<?php
/**
 * Admin/CountryInfo.php — Manage the country reference list
 *
 * Countries are the unit an exam can optionally be scoped to (examinfo.
 * CountryId, see migrations/migration_v51.sql). NULL/no country on an exam
 * means "Global" — applicable everywhere. Seeded with the standard
 * ISO-3166-1 list; admins just activate/deactivate which ones actually
 * show up in the Add/Edit Exam country picker and the exam list filter.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Countries.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

/* ── Handle quick actions ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';
    $cId    = (int)($_POST['CountryId'] ?? 0);

    if ($action === 'delete' && $cId > 0) {
        // Guard: don't delete a country already referenced by an exam —
        // unlink first (edit those exams back to Global) or refuse.
        $inUse = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM examinfo WHERE CountryId = ?", [$cId])['c'] ?? 0);
        if ($inUse > 0) {
            header('Location: CountryInfo.php?msg=inuse&count=' . $inUse);
            exit;
        }
        Database::execute("DELETE FROM countryinfo WHERE CountryId = ?", [$cId]);
        header('Location: CountryInfo.php?msg=deleted');
        exit;
    }
    if ($action === 'toggle' && $cId > 0) {
        Database::execute(
            "UPDATE countryinfo SET Active = IF(Active='Y','N','Y') WHERE CountryId = ?",
            [$cId]);
        header('Location: CountryInfo.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
        exit;
    }
}

/* ── Filters ──────────────────────────────────────────────────────────────── */
$filterActive = trim($_GET['active'] ?? '');
$search       = trim($_GET['q']      ?? '');

$where  = ['1=1'];
$params = [];
if ($filterActive !== '') { $where[] = 'Active = ?'; $params[] = $filterActive; }
if ($search !== '') { $where[] = '(CountryName LIKE ? OR CountryCode LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }

$sql = "SELECT c.*,
               (SELECT COUNT(*) FROM examinfo e WHERE e.CountryId = c.CountryId) AS ExamCount
          FROM countryinfo c
         WHERE " . implode(' AND ', $where) . "
      ORDER BY c.SortOrder ASC, c.CountryName ASC";
$countries = Database::fetchAll($sql, $params);

$pageTitle = 'Countries';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .ref-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:18px; }
  .ref-filters .form-group { margin:0; }
  .ref-filters label { font-size:.72rem; font-weight:800; color:var(--clr-primary); text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:3px; white-space:nowrap; }
  .ref-filters select,
  .ref-filters input[type=text] { font-size:.85rem; padding:5px 8px; height:32px; }
  .ref-filters .btn { height:32px; padding:0 14px; font-size:.85rem; }
  .act-btn { font-size:.8rem; padding:3px 10px; }
  .tbl th, .tbl td { vertical-align:middle; }
  .inactive-row { opacity:.55; }
  .ecount-badge { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.75rem; font-weight:700; background:#eef2ff; color:#4338ca; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#127760; Countries</span>
    <a href="AddEditCountryInfo.php?CountryId=0" class="btn btn-primary" style="font-size:.85rem;padding:5px 14px;">
      &#43; Add Country
    </a>
  </div>
  <div class="card-body">

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
      <div class="alert alert-success">Country deleted.</div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'added'): ?>
      <div class="alert alert-success">Country added.</div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'inuse'): ?>
      <div class="alert alert-danger">
        Can't delete — <?php echo (int)($_GET['count'] ?? 0); ?> exam(s) are still tagged with this country.
        Re-tag those exams to Global (or another country) first, or just mark it Inactive instead.
      </div>
    <?php endif; ?>

    <div style="font-size:.85rem;color:#6b7280;margin-bottom:14px;">
      Seeded with the standard ISO-3166-1 country list. An exam with no country
      assigned (see Add/Edit Exam) is <strong>Global</strong> — visible everywhere.
      Only <strong>Active</strong> countries appear in the exam country picker and filter.
    </div>

    <form method="get" action="">
      <div class="ref-filters">
        <div class="form-group">
          <label>Search</label>
          <input type="text" name="q" class="form-control" placeholder="Name or code…"
                 value="<?php echo htmlspecialchars($search); ?>" style="min-width:200px;">
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="active" class="form-control">
            <option value="">All</option>
            <option value="Y" <?php echo $filterActive==='Y'?'selected':''; ?>>Active</option>
            <option value="N" <?php echo $filterActive==='N'?'selected':''; ?>>Inactive</option>
          </select>
        </div>
        <div class="form-group">
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-secondary">&#128269; Filter</button>
          <a href="CountryInfo.php" class="btn btn-secondary">&times; Clear</a>
        </div>
      </div>
    </form>

    <?php if (empty($countries)): ?>
      <p style="text-align:center;color:#6b7280;padding:30px 0;">
        No countries found. <a href="AddEditCountryInfo.php?CountryId=0">Add the first one</a>.
      </p>
    <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:8%">Flag</th>
            <th style="width:10%">Code</th>
            <th style="width:30%">Country</th>
            <th style="width:14%">Used By</th>
            <th style="width:10%">Status</th>
            <th style="width:14%">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($countries as $c):
            $inactive = ($c['Active'] === 'N');
            $ec = (int)$c['ExamCount'];
          ?>
          <tr class="<?php echo $inactive ? 'inactive-row' : ''; ?>">
            <td><?php echo Countries::flagImg($c['CountryCode'], $c['CountryName'], 24); ?></td>
            <td><code><?php echo htmlspecialchars($c['CountryCode']); ?></code></td>
            <td><strong><?php echo htmlspecialchars($c['CountryName']); ?></strong></td>
            <td>
              <?php if ($ec > 0): ?>
                <span class="ecount-badge"><?php echo $ec; ?> exam<?php echo $ec !== 1 ? 's' : ''; ?></span>
              <?php else: ?>
                <span style="color:#9ca3af;font-size:.82rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="CountryId" value="<?php echo $c['CountryId']; ?>">
                <button type="submit" class="btn act-btn <?php echo $inactive ? 'btn-secondary' : 'btn-success'; ?>">
                  <?php echo $inactive ? 'Inactive' : 'Active'; ?>
                </button>
              </form>
            </td>
            <td style="white-space:nowrap;">
              <a href="AddEditCountryInfo.php?CountryId=<?php echo $c['CountryId']; ?>"
                 class="btn btn-primary act-btn">&#9998; Edit</a>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete this country?');">
                <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="CountryId" value="<?php echo $c['CountryId']; ?>">
                <button type="submit" class="btn btn-danger act-btn">&#128465;</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:10px;font-size:.82rem;color:#6b7280;">
      <?php echo count($countries); ?> countr<?php echo count($countries) !== 1 ? 'ies' : 'y'; ?> found.
    </div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
