<?php
/**
 * Admin/StudyReferences.php — Manage study references
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Institute.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$hasScopeCols = Database::hasColumn('study_references', 'ScopeType');
$hasInstJoin  = Database::tableExists('study_reference_institutes');
$institutes   = $hasScopeCols ? Institute::listAll() : [];
$instituteNames = [];
foreach ($institutes as $inst) { $instituteNames[(int)$inst['InstituteId']] = $inst['InstituteName']; }

/* ── Handle quick actions (AJAX or redirect) ──────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::validateCsrf();
    $action = $_POST['action'] ?? '';
    $refId  = (int)($_POST['RefId'] ?? 0);

    if ($action === 'delete' && $refId > 0) {
        Database::execute("DELETE FROM study_references WHERE RefId = ?", [$refId]);
        header('Location: StudyReferences.php?msg=deleted');
        exit;
    }
    if ($action === 'toggle' && $refId > 0) {
        Database::execute(
            "UPDATE study_references SET Active = IF(Active='Y','N','Y') WHERE RefId = ?",
            [$refId]);
        header('Location: StudyReferences.php');
        exit;
    }

    /* Bulk visibility change — lets an admin select a batch of references
       (e.g. everything just bulk-imported) and either pull them back to
       Draft/Not-Published (ScopeType='None', invisible to every student
       until re-published — same effect as migrations/migration_v58.sql,
       just self-service from the UI) or publish them to all students at
       once, instead of opening AddEditStudyReference.php one at a time. */
    if ($action === 'bulk_scope' && $hasScopeCols) {
        $newScope = $_POST['scope'] ?? '';
        $refIds   = array_values(array_unique(array_filter(
            array_map('intval', $_POST['ref_ids'] ?? []),
            fn($id) => $id > 0
        )));
        if (in_array($newScope, ['None', 'All'], true) && $refIds) {
            $placeholders = implode(',', array_fill(0, count($refIds), '?'));
            $updated = Database::execute(
                "UPDATE study_references SET ScopeType = ? WHERE RefId IN ($placeholders)",
                array_merge([$newScope], $refIds)
            );
            $label = $newScope === 'None' ? 'moved to Draft (Not Published)' : 'published as All Students';
            header('Location: StudyReferences.php?msg=' . urlencode("bulk|{$updated} reference(s) {$label}."));
            exit;
        }
        header('Location: StudyReferences.php?msg=' . urlencode('bulk_err|No references selected.'));
        exit;
    }
}

/* ── Filters ──────────────────────────────────────────────────────────────── */
$filterType     = trim($_GET['type']     ?? '');
$filterSubject  = (int)($_GET['subject'] ?? 0);
$filterActive   = trim($_GET['active']   ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterSub      = trim($_GET['sub']      ?? '');
$search         = trim($_GET['q']        ?? '');
$filterInstitute = (int)($_GET['institute'] ?? 0);

$where  = ['1=1'];
$params = [];

if ($filterType !== '') {
    $where[]  = 'r.RefType = ?';
    $params[] = $filterType;
}
if ($filterSubject > 0) {
    $where[]  = 'r.SubjectInfoId = ?';
    $params[] = $filterSubject;
}
if ($filterActive !== '') {
    $where[]  = 'r.Active = ?';
    $params[] = $filterActive;
}
if ($filterCategory !== '') {
    $where[]  = 'r.Category = ?';
    $params[] = $filterCategory;
}
if ($filterSub !== '') {
    $where[]  = 'r.SubCategory = ?';
    $params[] = $filterSub;
}
if ($search !== '') {
    $where[]  = '(r.Title LIKE ? OR r.Description LIKE ? OR r.Author LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($hasScopeCols && $filterInstitute > 0) {
    if ($hasInstJoin) {
        $where[]  = "r.ScopeType = 'Institute' AND EXISTS ("
                  . "SELECT 1 FROM study_reference_institutes sri "
                  . "WHERE sri.RefId = r.RefId AND sri.InstituteId = ?)";
    } else {
        $where[]  = "r.ScopeType = 'Institute' AND r.InstituteId = ?"; // pre-v57 fallback
    }
    $params[] = $filterInstitute;
}

$sql = "SELECT r.*, s.SubjectName
        FROM study_references r
        LEFT JOIN subjectinfo s ON s.SubjectInfoId = r.SubjectInfoId
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.Category ASC, r.SubCategory ASC, r.SortOrder ASC, r.CreatedAt DESC";

$refs     = Database::fetchAll($sql, $params);
$subjects = Database::fetchAll("SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

/* Per-reference institute names for the Visibility column, computed in one
   grouped query rather than per-row, so a page of 50 references doesn't
   trigger 50 extra queries. */
$scopeInstitutesByRef = [];
if ($hasScopeCols && $hasInstJoin) {
    $sriRows = Database::fetchAll(
        "SELECT sri.RefId, i.InstituteName
           FROM study_reference_institutes sri
           JOIN institutes i ON i.InstituteId = sri.InstituteId
          ORDER BY i.InstituteName");
    foreach ($sriRows as $sr) {
        $scopeInstitutesByRef[(int)$sr['RefId']][] = $sr['InstituteName'];
    }
}

/* Dynamic Category / SubCategory dropdown options — reads whatever admins
   have actually created, so a brand new category shows up here automatically. */
$categoryOptions = array_values(array_filter(array_column(
    Database::fetchAll(
        "SELECT DISTINCT Category FROM study_references
          WHERE Category IS NOT NULL AND Category <> '' ORDER BY Category"),
    'Category')));
$subCategoryOptions = array_values(array_filter(array_column(
    Database::fetchAll(
        "SELECT DISTINCT SubCategory FROM study_references
          WHERE SubCategory IS NOT NULL AND SubCategory <> '' ORDER BY SubCategory"),
    'SubCategory')));

$pageTitle = 'Study References';
include __DIR__ . '/../includes/header.php';
?>
<style>
  .ref-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:18px; }
  .ref-filters .form-group { margin:0; }
  .ref-filters label { font-size:.72rem; font-weight:800; color:var(--clr-primary); text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:3px; white-space:nowrap; }
  .ref-filters select,
  .ref-filters input[type=text] { font-size:.85rem; padding:5px 8px; height:32px; }
  .ref-filters .btn { height:32px; padding:0 14px; font-size:.85rem; }

  .ref-badge {
    display:inline-block; padding:2px 8px; border-radius:20px;
    font-size:.72rem; font-weight:700; letter-spacing:.3px; white-space:nowrap;
  }
  .ref-badge-Book    { background:#dbeafe; color:#1e40af; }
  .ref-badge-Video   { background:#fce7f3; color:#9d174d; }
  .ref-badge-Website { background:#d1fae5; color:#065f46; }
  .ref-badge-PDF     { background:#fee2e2; color:#991b1b; }
  .ref-badge-Article { background:#ede9fe; color:#5b21b6; }
  .ref-badge-Other   { background:#f3f4f6; color:#374151; }

  .ref-url a { color:#4f46e5; font-size:.82rem; word-break:break-all; }
  .ref-url a:hover { text-decoration:underline; }

  .act-btn { font-size:.8rem; padding:3px 10px; }
  .tbl th, .tbl td { vertical-align:middle; }
  .inactive-row { opacity:.55; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>&#128218; Study References</span>
    <a href="AddEditStudyReference.php?RefId=0" class="btn btn-primary" style="font-size:.85rem;padding:5px 14px;">
      &#43; Add Reference
    </a>
  </div>
  <div class="card-body">

    <?php
      $msgRaw = $_GET['msg'] ?? '';
      if ($msgRaw === 'deleted') {
          echo '<div class="alert alert-success">Reference deleted.</div>';
      } elseif (strpos($msgRaw, '|') !== false) {
          [$msgKind, $msgText] = explode('|', $msgRaw, 2);
          $msgClass = $msgKind === 'bulk_err' ? 'alert-danger' : 'alert-success';
          echo '<div class="alert ' . $msgClass . '">' . htmlspecialchars($msgText) . '</div>';
      }
    ?>

    <!-- Filters -->
    <form method="get" action="">
      <div class="ref-filters">
        <div class="form-group">
          <label>Search</label>
          <input type="text" name="q" class="form-control" placeholder="Title / author…"
                 value="<?php echo htmlspecialchars($search); ?>" style="min-width:180px;">
        </div>
        <div class="form-group">
          <label>Type</label>
          <select name="type" class="form-control">
            <option value="">All Types</option>
            <?php foreach (['Book','Video','Website','PDF','Article','Other'] as $t): ?>
              <option value="<?php echo $t; ?>" <?php echo $filterType===$t?'selected':''; ?>><?php echo $t; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Subject</label>
          <select name="subject" class="form-control">
            <option value="0">All Subjects</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo $s['SubjectInfoId']; ?>"
                <?php echo $filterSubject==(int)$s['SubjectInfoId']?'selected':''; ?>>
                <?php echo htmlspecialchars($s['SubjectName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
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
          <label>Category</label>
          <select name="category" class="form-control">
            <option value="">All Categories</option>
            <?php foreach ($categoryOptions as $c): ?>
              <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filterCategory===$c?'selected':''; ?>>
                <?php echo htmlspecialchars($c); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Sub-Category</label>
          <select name="sub" class="form-control">
            <option value="">All</option>
            <?php foreach ($subCategoryOptions as $c): ?>
              <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filterSub===$c?'selected':''; ?>>
                <?php echo htmlspecialchars($c); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($hasScopeCols && !empty($institutes)): ?>
        <div class="form-group">
          <label>Institute</label>
          <select name="institute" class="form-control">
            <option value="0">All / Not Restricted</option>
            <?php foreach ($institutes as $inst): ?>
              <option value="<?php echo (int)$inst['InstituteId']; ?>"
                <?php echo $filterInstitute===(int)$inst['InstituteId']?'selected':''; ?>>
                <?php echo htmlspecialchars($inst['InstituteName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label>&nbsp;</label>
          <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-secondary">&#128269; Filter</button>
            <a href="StudyReferences.php" class="btn btn-secondary">&times; Clear</a>
          </div>
        </div>
      </div>
    </form>

    <?php if (empty($refs)): ?>
      <p style="text-align:center;color:#6b7280;padding:30px 0;">No references found. <a href="AddEditStudyReference.php?RefId=0">Add the first one</a>.</p>
    <?php else: ?>

    <?php if ($hasScopeCols): ?>
    <!-- Bulk visibility actions — checkboxes below aren't inside a <form>
         (the per-row Toggle/Delete forms already use one each; nesting a
         table-wide form around them would break those), so selection is
         collected via JS into #bulkScopeForm just before submit. -->
    <div style="padding:10px 14px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;
                display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
      <span style="font-size:.82rem;color:#475569;"><span id="refSelCount">0</span> selected</span>
      <button type="button" onclick="refSelectAll(true)"  class="btn btn-secondary act-btn">Select All</button>
      <button type="button" onclick="refSelectAll(false)" class="btn btn-secondary act-btn">Clear</button>
      <button type="button" onclick="refBulkScope('None')" class="btn act-btn" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;">
        &#128683; Set to Draft (Not Published)
      </button>
      <button type="button" onclick="refBulkScope('All')" class="btn act-btn" style="background:#e0e7ff;color:#3730a3;border:1px solid #a5b4fc;">
        &#127760; Publish: All Students
      </button>
    </div>
    <form method="post" id="bulkScopeForm" style="display:none;">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="action" value="bulk_scope">
      <input type="hidden" name="scope" id="bulkScopeValue" value="">
      <div id="bulkScopeIds"></div>
    </form>
    <?php endif; ?>

    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <?php if ($hasScopeCols): ?><th style="width:3%"><input type="checkbox" onchange="refSelectAll(this.checked)" title="Select all"></th><?php endif; ?>
            <th style="width:20%">Title / Author</th>
            <th style="width:8%">Type</th>
            <th style="width:14%">Category / Sub</th>
            <th style="width:12%">Subject</th>
            <?php if ($hasScopeCols): ?><th style="width:9%">Visibility</th><?php endif; ?>
            <th style="width:21%">URL / Description</th>
            <th style="width:6%">Visible</th>
            <th style="width:6%">Content</th>
            <th style="width:6%">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($refs as $r):
            $inactive = ($r['Active'] === 'N');
          ?>
          <tr class="<?php echo $inactive ? 'inactive-row' : ''; ?>">
            <?php if ($hasScopeCols): ?>
            <td class="text-center">
              <input type="checkbox" class="ref-bulk-cb" value="<?php echo (int)$r['RefId']; ?>" onchange="updateRefSelCount()">
            </td>
            <?php endif; ?>
            <td>
              <strong><?php echo htmlspecialchars($r['Title']); ?></strong>
              <?php if ($r['Author']): ?>
                <div style="font-size:.78rem;color:#6b7280;">by <?php echo htmlspecialchars($r['Author']); ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="ref-badge ref-badge-<?php echo htmlspecialchars($r['RefType']); ?>">
                <?php echo htmlspecialchars($r['RefType']); ?>
              </span>
            </td>
            <td style="font-size:.82rem;">
              <?php if (!empty($r['Category'])): ?>
                <div><strong><?php echo htmlspecialchars($r['Category']); ?></strong></div>
                <?php if (!empty($r['SubCategory'])): ?>
                  <div style="color:#6b7280;"><?php echo htmlspecialchars($r['SubCategory']); ?></div>
                <?php endif; ?>
              <?php else: ?>
                <em style="color:#9ca3af;">—</em>
              <?php endif; ?>
            </td>
            <td style="font-size:.85rem;">
              <?php echo $r['SubjectName'] ? htmlspecialchars($r['SubjectName']) : '<em style="color:#9ca3af;">—</em>'; ?>
            </td>
            <?php if ($hasScopeCols):
              $scope = $r['ScopeType'] ?? 'All';
              $refInstNames = $scopeInstitutesByRef[(int)$r['RefId']] ?? (
                  !$hasInstJoin && !empty($r['InstituteId'])
                      ? array_filter([$instituteNames[(int)$r['InstituteId']] ?? null]) : []);
            ?>
            <td style="font-size:.8rem;">
              <?php if ($scope === 'None'): ?>
                <span class="ref-badge" style="background:#fee2e2;color:#991b1b;">&#128683; Draft</span>
              <?php elseif ($scope === 'Institute'): ?>
                <?php if (!empty($refInstNames)): ?>
                  <span class="ref-badge" style="background:#fef3c7;color:#92400e;" title="<?php echo htmlspecialchars(implode(', ', $refInstNames)); ?>">
                    &#127982; <?php echo htmlspecialchars($refInstNames[0]); ?><?php echo count($refInstNames) > 1 ? ' +' . (count($refInstNames) - 1) : ''; ?>
                  </span>
                <?php else: ?>
                  <span class="ref-badge" style="background:#fef3c7;color:#92400e;">&#127982; No institute set</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="ref-badge" style="background:#e0e7ff;color:#3730a3;">&#127760; All Students</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
              <?php if ($r['URL']):
                $isAbsolute = (bool)preg_match('~^https?://~i', $r['URL']);
                $href       = $isAbsolute ? $r['URL'] : '../' . $r['URL']; // relative to Admin/
                $label      = $isAbsolute ? (parse_url($r['URL'], PHP_URL_HOST) ?: $r['URL']) : basename($r['URL']);
              ?>
                <div class="ref-url"><a href="<?php echo htmlspecialchars($href); ?>" target="_blank" rel="noopener">
                  &#128279; <?php echo htmlspecialchars($label); ?>
                </a></div>
              <?php endif; ?>
              <?php if ($r['Description']): ?>
                <div style="font-size:.8rem;color:#6b7280;margin-top:3px;">
                  <?php echo htmlspecialchars(mb_substr($r['Description'], 0, 100)) . (mb_strlen($r['Description']) > 100 ? '…' : ''); ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="RefId" value="<?php echo $r['RefId']; ?>">
                <button type="submit" class="btn act-btn <?php echo $inactive ? 'btn-secondary' : 'btn-success'; ?>"
                        title="Toggle visible / hidden">
                  <?php echo $inactive ? 'Hidden' : 'Visible'; ?>
                </button>
              </form>
            </td>
            <td>
              <?php $noContent = ($r['ShowContent'] ?? 'Y') === 'N'; ?>
              <span title="<?php echo $noContent ? 'Description hidden from students' : 'Description visible to students'; ?>"
                    style="font-size:.82rem;font-weight:600;color:<?php echo $noContent ? '#b91c1c' : '#065f46'; ?>;">
                <?php echo $noContent ? '&#128683; Hidden' : '&#10003; Shown'; ?>
              </span>
            </td>
            <td style="white-space:nowrap;">
              <a href="AddEditStudyReference.php?RefId=<?php echo $r['RefId']; ?>"
                 class="btn btn-primary act-btn">&#9998; Edit</a>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete this reference?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="RefId" value="<?php echo $r['RefId']; ?>">
                <button type="submit" class="btn btn-danger act-btn">&#128465;</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:10px;font-size:.82rem;color:#6b7280;">
      <?php echo count($refs); ?> reference<?php echo count($refs) !== 1 ? 's' : ''; ?> found.
    </div>
    <?php endif; ?>

  </div>
</div>

<?php if ($hasScopeCols): ?>
<script>
function updateRefSelCount() {
  document.getElementById('refSelCount').textContent =
    document.querySelectorAll('.ref-bulk-cb:checked').length;
}
function refSelectAll(checked) {
  document.querySelectorAll('.ref-bulk-cb').forEach(function(cb){ cb.checked = checked; });
  updateRefSelCount();
}
function refBulkScope(scope) {
  var ids = Array.from(document.querySelectorAll('.ref-bulk-cb:checked')).map(function(cb){ return cb.value; });
  if (ids.length === 0) { alert('Select at least one reference first.'); return; }
  var label = scope === 'None' ? 'move ' + ids.length + ' reference(s) to Draft (Not Published)?' :
                                  'publish ' + ids.length + ' reference(s) as All Students?';
  if (!confirm('Are you sure you want to ' + label)) return;

  var form = document.getElementById('bulkScopeForm');
  document.getElementById('bulkScopeValue').value = scope;
  var container = document.getElementById('bulkScopeIds');
  container.innerHTML = '';
  ids.forEach(function(id){
    var inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'ref_ids[]';
    inp.value = id;
    container.appendChild(inp);
  });
  form.submit();
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
