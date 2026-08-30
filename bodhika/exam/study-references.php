<?php
/**
 * exam/study-references.php — Student view of study references
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Institute.php';
Auth::requireLogin('../auth/login.php');

$isAdmin = Auth::isAdmin();

/* ── Filters ──────────────────────────────────────────────────────────────── */
$filterType     = trim($_GET['type']     ?? '');
$filterSubject  = (int)($_GET['subject'] ?? 0);
$filterCategory = trim($_GET['category'] ?? '');
$filterSub      = trim($_GET['sub']      ?? '');
$search         = trim($_GET['q']        ?? '');

/* Institute-scoped visibility: admins see everything (including
   ScopeType='None' drafts) so they can preview/manage what each
   institute's students see, including a reference that isn't published
   yet. Students only ever see 'All' or a matching institute — 'None'
   (draft) naturally never matches either branch below, so it's excluded
   without any extra condition. Gated on hasColumn()/tableExists() so a
   production database that hasn't run migration_v56.sql/v57.sql yet just
   behaves exactly as before — everything with Active='Y' stays visible.

   Kept as its own $scopeWhere/$scopeParams (rather than folded straight
   into $where below) because the Category/Sub-Category pill COUNTS need
   this exact same visibility filter too — without it, the pills below
   were counting every reference regardless of who could see it (e.g. a
   student's institute doesn't match, or it's still a draft), so a pill
   could read "(24)" while actually clicking into it showed nothing. */
$scopeWhere  = [];
$scopeParams = [];
if (!$isAdmin && Database::hasColumn('study_references', 'ScopeType')) {
    $myInstituteId = Institute::getInstituteId(Auth::currentUserId());
    $hasInstJoin   = Database::tableExists('study_reference_institutes');
    if ($myInstituteId) {
        if ($hasInstJoin) {
            $scopeWhere[] = "(r.ScopeType = 'All' OR (r.ScopeType = 'Institute' AND EXISTS ("
                     . "SELECT 1 FROM study_reference_institutes sri "
                     . "WHERE sri.RefId = r.RefId AND sri.InstituteId = ?)))";
        } else {
            // Pre-v57 fallback: single-institute column only.
            $scopeWhere[] = "(r.ScopeType = 'All' OR (r.ScopeType = 'Institute' AND r.InstituteId = ?))";
        }
        $scopeParams[] = $myInstituteId;
    } else {
        $scopeWhere[] = "r.ScopeType = 'All'";
    }
}

$where  = array_merge(["r.Active = 'Y'"], $scopeWhere);
$params = $scopeParams;

if ($filterType !== '') {
    $where[]  = 'r.RefType = ?';
    $params[] = $filterType;
}
if ($filterSubject > 0) {
    $where[]  = 'r.SubjectInfoId = ?';
    $params[] = $filterSubject;
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

$refs = Database::fetchAll(
    "SELECT r.*, s.SubjectName
     FROM study_references r
     LEFT JOIN subjectinfo s ON s.SubjectInfoId = r.SubjectInfoId
     WHERE " . implode(' AND ', $where) . "
     ORDER BY r.Category ASC, r.SubCategory ASC, r.SortOrder ASC, r.CreatedAt DESC",
    $params);

$subjects = Database::fetchAll(
    "SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName");

/* Dynamic list of categories that actually have at least one visible
   reference, so the pill row below never shows an empty category. Same
   $scopeWhere/$scopeParams as the main $refs query above — a count must
   only include references this student can actually see, or the pill
   shows a number that then filters down to nothing when clicked. */
$categoryCounts = Database::fetchAll(
    "SELECT r.Category, COUNT(*) AS c FROM study_references r
      WHERE " . implode(' AND ', array_merge(
          ["r.Active='Y'", "r.Category IS NOT NULL", "r.Category <> ''"], $scopeWhere)) . "
      GROUP BY r.Category ORDER BY r.Category",
    $scopeParams);

/* When a Category is selected, also offer its SubCategory pills (e.g.
   MCQ / Technical under Interview Questions) — computed dynamically from
   whatever the admin has actually created for that category, again scoped
   to what this student can see. */
$subCategoryCounts = [];
if ($filterCategory !== '') {
    $subCategoryCounts = Database::fetchAll(
        "SELECT r.SubCategory, COUNT(*) AS c FROM study_references r
          WHERE " . implode(' AND ', array_merge(
              ["r.Active='Y'", "r.Category = ?", "r.SubCategory IS NOT NULL", "r.SubCategory <> ''"], $scopeWhere)) . "
          GROUP BY r.SubCategory ORDER BY r.SubCategory",
        array_merge([$filterCategory], $scopeParams));
}

/* Group refs for the cards layout: by SubCategory when browsing inside a
   Category (e.g. Interview Questions), otherwise by RefType as before. */
$grouped = [];
foreach ($refs as $r) {
    $groupKey = ($filterCategory !== '') ? ($r['SubCategory'] ?: 'Other') : $r['RefType'];
    $grouped[$groupKey][] = $r;
}

$refTypes = ['Book','Video','Website','PDF','Article','Other'];
$typeIcons = [
    'Book'    => '📖',
    'Video'   => '🎬',
    'Website' => '🌐',
    'PDF'     => '📄',
    'Article' => '📰',
    'Other'   => '🔗',
];

$pageTitle = 'Study References';
include __DIR__ . '/../includes/header.php';
?>
<style>
  /* ── filter bar ── */
  .sref-filter { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:24px; }
  .sref-filter .form-group { margin:0; }
  .sref-filter label { font-size:.72rem; font-weight:800; color:var(--clr-primary); text-transform:uppercase; letter-spacing:.06em; display:block; margin-bottom:3px; white-space:nowrap; }
  .sref-filter select, .sref-filter input[type=text] { height:34px; font-size:.85rem; padding:4px 9px; }
  .sref-filter .btn { height:34px; padding:0 14px; font-size:.85rem; }

  /* ── type filter pills ── */
  .type-pills { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:18px; }
  .type-pill {
    padding:4px 14px; border-radius:20px; font-size:.82rem; font-weight:600;
    background:#f3f4f6; color:#374151; text-decoration:none; border:1px solid #e5e7eb;
    transition:background .15s,color .15s;
  }
  .type-pill:hover  { background:#e0e7ff; color:#4f46e5; border-color:#a5b4fc; }
  .type-pill.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }

  /* ── reference cards ── */
  .ref-section-title {
    font-size:1rem; font-weight:700; color:#374151; margin:24px 0 10px;
    display:flex; align-items:center; gap:6px;
    border-bottom:2px solid #e5e7eb; padding-bottom:5px;
  }
  .ref-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(290px,1fr)); gap:14px; }
  .ref-card {
    border:1px solid #e5e7eb; border-radius:8px; padding:14px 16px;
    background:#fff; display:flex; flex-direction:column; gap:6px;
    box-shadow:0 1px 3px rgba(0,0,0,.06); transition:box-shadow .15s;
  }
  .ref-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.1); }
  .ref-card-title { font-weight:700; font-size:.95rem; color:#111827; }
  .ref-card-author { font-size:.78rem; color:#6b7280; }
  .ref-card-subject {
    font-size:.75rem; font-weight:600; background:#f0fdf4; color:#166534;
    padding:2px 8px; border-radius:10px; align-self:flex-start;
  }
  .ref-card-desc { font-size:.82rem; color:#4b5563; line-height:1.5; flex:1; }
  .ref-card-link {
    display:inline-flex; align-items:center; gap:5px;
    font-size:.82rem; font-weight:600; color:#4f46e5;
    text-decoration:none; margin-top:4px;
  }
  .ref-card-link:hover { text-decoration:underline; }

  .no-refs { text-align:center; padding:40px 20px; color:#9ca3af; font-size:.95rem; }
</style>

<div class="card">
  <div class="card-header">&#128218; Study References</div>
  <div class="card-body">

    <!-- Search + Subject filter -->
    <form method="get" action="">
      <?php if ($filterType !== ''): ?>
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
      <?php endif; ?>
      <div class="sref-filter">
        <div class="form-group">
          <label>Search</label>
          <input type="text" name="q" class="form-control" placeholder="Keyword, title, author…"
                 value="<?php echo htmlspecialchars($search); ?>" style="min-width:200px;">
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
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-primary">&#128269; Search</button>
          <?php
            /* Clear should reset the search/subject/category filters but
               stay within whichever section (type=Video, type=Book, …)
               the user is currently in — it previously always linked to
               the bare page, dropping "type" along with everything else
               and bouncing back to "All References". Preserving just
               "type" here (search box below is submitted fresh each time
               via the form's own hidden field, so this doesn't compound
               into subsequent searches losing the section either). */
            $clearHref = 'study-references.php' . ($filterType !== '' ? '?type=' . urlencode($filterType) : '');
          ?>
          <a href="<?php echo htmlspecialchars($clearHref); ?>" class="btn btn-secondary">&times; Clear</a>
        </div>
      </div>
    </form>

    <!-- Category pills (top-level grouping, e.g. "Interview Questions") -->
    <?php if (!empty($categoryCounts)): ?>
    <div class="type-pills">
      <a href="?<?php echo http_build_query(['q'=>$search,'subject'=>$filterSubject]); ?>"
         class="type-pill <?php echo $filterCategory===''?'active':''; ?>">&#128218; All References</a>
      <?php foreach ($categoryCounts as $cc): ?>
        <a href="?<?php echo http_build_query(['category'=>$cc['Category'],'q'=>$search,'subject'=>$filterSubject]); ?>"
           class="type-pill <?php echo $filterCategory===$cc['Category']?'active':''; ?>">
          &#128193; <?php echo htmlspecialchars($cc['Category']); ?>
          <span style="opacity:.65;">(<?php echo (int)$cc['c']; ?>)</span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Sub-category pills (e.g. MCQ / Technical) — only once a Category is selected -->
    <?php if ($filterCategory !== '' && !empty($subCategoryCounts)): ?>
    <div class="type-pills">
      <a href="?<?php echo http_build_query(['category'=>$filterCategory,'q'=>$search,'subject'=>$filterSubject]); ?>"
         class="type-pill <?php echo $filterSub===''?'active':''; ?>">All <?php echo htmlspecialchars($filterCategory); ?></a>
      <?php foreach ($subCategoryCounts as $sc): ?>
        <a href="?<?php echo http_build_query(['category'=>$filterCategory,'sub'=>$sc['SubCategory'],'q'=>$search,'subject'=>$filterSubject]); ?>"
           class="type-pill <?php echo $filterSub===$sc['SubCategory']?'active':''; ?>">
          <?php echo $sc['SubCategory'] === 'MCQ' ? '&#10067;' : '&#128295;'; ?>
          <?php echo htmlspecialchars($sc['SubCategory']); ?>
          <span style="opacity:.65;">(<?php echo (int)$sc['c']; ?>)</span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Type pills (only shown outside a Category, to avoid a redundant second row) -->
    <?php if ($filterCategory === ''): ?>
    <div class="type-pills">
      <a href="?<?php echo http_build_query(['q'=>$search,'subject'=>$filterSubject]); ?>"
         class="type-pill <?php echo $filterType===''?'active':''; ?>">All Types</a>
      <?php foreach ($refTypes as $t): ?>
        <?php $count = count(array_filter($refs, fn($r) => $r['RefType']===$t)); ?>
        <?php if ($count > 0 || $filterType === $t): ?>
        <a href="?<?php echo http_build_query(['type'=>$t,'q'=>$search,'subject'=>$filterSubject]); ?>"
           class="type-pill <?php echo $filterType===$t?'active':''; ?>">
          <?php echo $typeIcons[$t]; ?> <?php echo $t; ?>
          <span style="opacity:.65;">(<?php echo $count; ?>)</span>
        </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
      $isFlatFiltered = ($filterType !== '' || $search !== '' || $filterSubject > 0 || $filterSub !== '');
    ?>

    <?php if (empty($refs)): ?>
      <div class="no-refs">&#128218; No study references found. Check back later or try different filters.</div>
    <?php elseif ($isFlatFiltered): ?>
      <!-- Flat list when filtered down to a specific type / sub-category / search -->
      <div class="ref-grid">
        <?php foreach ($refs as $r): ?>
          <?php echo _render_ref_card($r, $typeIcons, $_root); ?>
        <?php endforeach; ?>
      </div>
    <?php elseif ($filterCategory !== ''): ?>
      <!-- Browsing a whole Category with no Sub-Category chosen yet: group by Sub-Category -->
      <?php foreach ($grouped as $groupName => $items): ?>
        <div class="ref-section-title">
          <?php echo $groupName === 'MCQ' ? '&#10067;' : ($groupName === 'Technical' ? '&#128295;' : '&#128218;'); ?>
          <?php echo htmlspecialchars($groupName); ?>
          <span style="font-weight:400;color:#9ca3af;font-size:.85rem;">(<?php echo count($items); ?>)</span>
        </div>
        <div class="ref-grid">
          <?php foreach ($items as $r): ?>
            <?php echo _render_ref_card($r, $typeIcons, $_root); ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <!-- Grouped by type when no filter -->
      <?php foreach ($refTypes as $type): ?>
        <?php if (empty($grouped[$type])) continue; ?>
        <div class="ref-section-title">
          <?php echo $typeIcons[$type]; ?> <?php echo $type; ?>s
          <span style="font-weight:400;color:#9ca3af;font-size:.85rem;">(<?php echo count($grouped[$type]); ?>)</span>
        </div>
        <div class="ref-grid">
          <?php foreach ($grouped[$type] as $r): ?>
            <?php echo _render_ref_card($r, $typeIcons, $_root); ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>

<?php
/* Inline card renderer — avoids a separate partial file.
   $_root is passed in so locally-uploaded documents (relative paths like
   "assets/study-references/…") resolve correctly regardless of the page
   they're rendered from; external https:// links are used as-is. */
function _render_ref_card(array $r, array $icons, string $root): string {
    $title   = htmlspecialchars($r['Title']);
    $author  = htmlspecialchars($r['Author'] ?? '');
    $subject = htmlspecialchars($r['SubjectName'] ?? '');
    $desc        = htmlspecialchars($r['Description'] ?? '');
    $rawUrl      = $r['URL'] ?? '';
    $isAbsolute  = (bool)preg_match('~^https?://~i', $rawUrl);
    $url         = htmlspecialchars($rawUrl !== '' ? ($isAbsolute ? $rawUrl : $root . $rawUrl) : '');
    $type        = htmlspecialchars($r['RefType']);
    $icon        = $icons[$r['RefType']] ?? '🔗';
    $showContent = ($r['ShowContent'] ?? 'Y') === 'Y';

    $html  = '<div class="ref-card">';
    $html .= '<div class="ref-card-title">' . $icon . ' ' . $title . '</div>';
    if ($author)              $html .= '<div class="ref-card-author">by ' . $author . '</div>';
    if ($subject)             $html .= '<div class="ref-card-subject">' . $subject . '</div>';
    if ($desc && $showContent) $html .= '<div class="ref-card-desc">' . nl2br($desc) . '</div>';
    if ($url) {
        $label = $isAbsolute ? (parse_url($rawUrl, PHP_URL_HOST) ?: $rawUrl) : basename($rawUrl);
        $verb  = $isAbsolute ? 'Visit' : 'Open';
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="ref-card-link">'
               . '&#128279; ' . htmlspecialchars($verb . ': ' . $label) . ' &#8599;</a>';
    }
    $html .= '</div>';
    return $html;
}

include __DIR__ . '/../includes/footer.php';
?>
