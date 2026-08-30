<?php
/**
 * Admin/StudentGroupMembers.php — bulk add/remove students in one group.
 * Mirrors BulkAssignInstitute.php's search + checkbox multi-select pattern,
 * and AdminUsers.php's tab / server-side pagination pattern (PAGE_SIZE,
 * currentPage(), paginator()) — see that file for the canonical version of
 * this house style.
 *
 * Layout: "Current Members" and "Add Students" are side-by-side tabs
 * (?tab=members default, ?tab=add) instead of stacked cards. Both lists are
 * searched (GET, server-side) and paginated (GET, server-side) rather than
 * loading the full member/candidate list into the DOM and filtering with
 * JS — with 1600+ members in some groups, rendering everything client-side
 * doesn't scale.
 *
 * Add Students selections persist across pagination/search via
 * sessionStorage (keyed per group), so paging through candidates doesn't
 * silently drop a partial selection — there's no other cross-page-selection
 * precedent elsewhere in this codebase, but dropping selections on page 2
 * would be a real usability regression for a bulk-add screen.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/StudentGroup.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) { header('Location: ../auth/login.php'); exit; }

$gid = filter_input(INPUT_GET, 'gid', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'gid', FILTER_VALIDATE_INT);
if (!$gid) { header('Location: StudentGroups.php'); exit; }

$group = Database::fetchOne("SELECT * FROM student_groups WHERE StudentGroupId = ? LIMIT 1", [$gid]);
if (!$group) { header('Location: StudentGroups.php'); exit; }

$adminId = Auth::currentUser() ?: 'admin';
$flash   = '';

/* ── Handle bulk add ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    Auth::validateCsrf();
    $userIds = array_values(array_unique(array_filter(
        array_map('intval', $_POST['user_ids'] ?? []), fn($id) => $id > 0)));

    if (!$userIds) {
        $flash = 'error|Please select at least one student.';
    } else {
        foreach ($userIds as $uid) {
            Database::execute(
                "INSERT IGNORE INTO student_group_members (StudentGroupId, UserInfoId, AddedBy) VALUES (?,?,?)",
                [$gid, $uid, $adminId]);
        }
        // Bridge: give each newly-added student a real exam_assignments row
        // for every exam already recommended to this group, so admins don't
        // have to individually assign them — the whole point of the group.
        // Two independent bridges fire here: syncAssignments() for the
        // Recommended/discount catalog (migration_v53), and
        // syncDirectAssignments() for exams REALLY assigned to the whole
        // group via exam/assign.php's "Assign Entire Group" action
        // (migration_v67) — a student added to the group picks up both.
        $loginId      = Auth::currentLoginId();
        $createdCount = 0;
        foreach ($userIds as $uid) {
            $createdCount += StudentGroup::syncAssignments($gid, $uid, $loginId);
            $createdCount += StudentGroup::syncDirectAssignments($gid, $uid, $loginId);
        }
        $flash = 'success|Added ' . count($userIds) . ' student(s) to "' . $group['GroupName'] . '".'
            . ($createdCount > 0 ? " {$createdCount} individual exam assignment(s) auto-created." : '');
    }
    header('Location: StudentGroupMembers.php?gid=' . $gid . '&flash=' . urlencode($flash)); exit;
}

/* ── Handle single remove ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_uid'])) {
    Auth::validateCsrf();
    $uid = (int)$_POST['remove_uid'];
    Database::execute(
        "DELETE FROM student_group_members WHERE StudentGroupId=? AND UserInfoId=?", [$gid, $uid]);

    // Bridge: revoke this student's individual assignments for exams that
    // were only recommended via this group, but only where never attempted
    // and no other active group they're still in recommends the same exam.
    // Mirrors for BOTH bridges — Recommended/discount (migration_v53) and
    // real direct group assignment (migration_v67) — so leaving a group
    // revokes access it granted, same as leaving revokes the discount.
    $groupExamIds = array_map('intval', array_column(
        Database::fetchAll("SELECT ExamInfoId FROM student_group_exam_assignments WHERE StudentGroupId = ?", [$gid]),
        'ExamInfoId'
    ));
    $revokedCount = $groupExamIds ? StudentGroup::pruneOrphanedAssignments([$uid], $groupExamIds) : 0;

    $groupDirectExamIds = array_map('intval', array_column(
        Database::fetchAll("SELECT ExamInfoId FROM student_group_direct_assignments WHERE StudentGroupId = ?", [$gid]),
        'ExamInfoId'
    ));
    $revokedCount += $groupDirectExamIds ? StudentGroup::pruneOrphanedDirectAssignments([$uid], $groupDirectExamIds) : 0;

    $backTab  = in_array($_POST['tab'] ?? '', ['members', 'add'], true) ? $_POST['tab'] : 'members';
    $backPage = max(1, (int)($_POST['mp'] ?? 1));
    $msg = 'Student removed from group.'
        . ($revokedCount > 0 ? " {$revokedCount} un-attempted individual assignment(s) auto-revoked." : '');
    header('Location: StudentGroupMembers.php?gid=' . $gid . '&tab=' . $backTab . '&mp=' . $backPage
        . '&flash=' . urlencode('success|' . $msg)); exit;
}

if (isset($_GET['flash'])) $flash = urldecode($_GET['flash']);
[$flashType, $flashMsg] = $flash ? explode('|', $flash, 2) : ['', ''];

/* ── Active tab ───────────────────────────────────────────────────────────── */
$tab = in_array($_GET['tab'] ?? '', ['members', 'add'], true) ? $_GET['tab'] : 'members';

/* ── Pagination helpers (mirrors AdminUsers.php) ─────────────────────────── */
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

/* ── Every current member's UserInfoId — unfiltered, unpaginated ────────────
   Needed to (a) show the true group total on the tab label regardless of
   search/paging, and (b) exclude members from the Add-Students candidate
   list regardless of what page/search the Members tab happens to be on. */
$allMemberIds = array_map('intval', array_column(
    Database::fetchAll("SELECT UserInfoId FROM student_group_members WHERE StudentGroupId = ?", [$gid]),
    'UserInfoId'
));
$groupTotalCount = count($allMemberIds);

/* ── Current members: search + sort + paginate ─────────────────────────────
   Search is server-side (SQL LIKE across name/login/email — already covers
   "last name, login name, etc." per-request), but it's now driven live via
   a debounced AJAX fetch (see the JS + the ajax=1 short-circuit below)
   instead of requiring a Search-button click, since a 1600+ row roster
   makes click-to-search too slow an iteration loop when hunting for someone. */
$memberSearch = trim($_GET['mq'] ?? '');
$memberPage   = currentPage('mp');
$memberSort   = in_array($_GET['msort'] ?? '', ['name', 'recent'], true) ? $_GET['msort'] : 'name';
$members      = [];
$memberTotal  = 0;

if ($tab === 'members') {
    $where  = ["m.StudentGroupId = ?"];
    $params = [$gid];
    if ($memberSearch !== '') {
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.EMail LIKE ? OR li.LoginName LIKE ?)";
        $like     = "%{$memberSearch}%";
        array_push($params, $like, $like, $like, $like);
    }
    $whereSQL = implode(' AND ', $where);
    $baseSQL  = "FROM student_group_members m
                   JOIN userinfo  u  ON u.UserInfoId = m.UserInfoId
              LEFT JOIN logininfo li ON li.LoginName = u.LoginName
                  WHERE {$whereSQL}";

    $memberTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

    $orderSQL = $memberSort === 'recent' ? 'm.AddedAt DESC, u.FstName, u.LstName' : 'u.FstName, u.LstName';
    $offset   = ($memberPage - 1) * PAGE_SIZE;
    $members  = Database::fetchAll(
        "SELECT u.UserInfoId, u.FstName, u.LstName, u.EMail, li.LoginName, m.AddedAt
         {$baseSQL}
         ORDER BY {$orderSQL}
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

/* ── Add students: candidates = everyone not already a member, search + paginate ── */
$addSearch  = trim($_GET['aq'] ?? '');
$addPage    = currentPage('ap');
$candidates = [];
$candTotal  = 0;

if ($tab === 'add') {
    $where  = ["li.Role = 'STDNT'"];
    $params = [];
    if ($allMemberIds) {
        $ph      = implode(',', array_fill(0, count($allMemberIds), '?'));
        $where[] = "u.UserInfoId NOT IN ($ph)";
        array_push($params, ...$allMemberIds);
    }
    if ($addSearch !== '') {
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR li.LoginName LIKE ?)";
        $like     = "%{$addSearch}%";
        array_push($params, $like, $like, $like);
    }
    $whereSQL = implode(' AND ', $where);
    $baseSQL  = "FROM userinfo  u
            LEFT JOIN logininfo li ON li.LoginName = u.LoginName
                 WHERE {$whereSQL}";

    $candTotal = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);

    $offset     = ($addPage - 1) * PAGE_SIZE;
    $candidates = Database::fetchAll(
        "SELECT u.UserInfoId, u.FstName, u.LstName, li.LoginName
         {$baseSQL}
         ORDER BY u.FstName, u.LstName
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

/* Querystrings for paginators/tab links (preserve gid + the active tab's own search) */
$qsMembers = array_filter(['gid' => $gid, 'tab' => 'members', 'mq' => $memberSearch, 'msort' => $memberSort === 'recent' ? 'recent' : null]);
$qsAdd     = array_filter(['gid' => $gid, 'tab' => 'add',     'aq' => $addSearch]);

/* ── Current Members table+pager fragment — shared by the full page render
   and the AJAX live-search endpoint below, so the two can never drift. ── */
function renderMembersFragment(array $members, int $memberTotal, int $groupTotalCount, string $memberSearch,
                                int $memberPage, int $memberPageSize, array $qsMembers): void {
    if ($memberSearch !== '') {
        echo '<div class="sgm-result-count">Showing ' . $memberTotal . ' of ' . $groupTotalCount
           . ' members matching “' . htmlspecialchars($memberSearch) . '”.</div>';
    }
    if (empty($members)) {
        echo '<div style="padding:24px;text-align:center;color:#718096;">'
           . ($memberSearch !== '' ? 'No members match your search.' : 'No members yet — add some from the "Add Students" tab.')
           . '</div>';
        return;
    }
    ?>
  <div style="overflow-x:auto;">
    <table class="tbl" style="width:100%;font-size:.85rem;">
      <thead><tr><th>Name</th><th>Login</th><th>Email</th><th>Added</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($members as $i => $m): ?>
        <tr class="<?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>">
          <td><?php echo htmlspecialchars(trim($m['FstName'] . ' ' . $m['LstName'])); ?></td>
          <td><?php echo htmlspecialchars($m['LoginName'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($m['EMail'] ?? ''); ?></td>
          <td style="font-size:.78rem;color:#718096;"><?php echo $m['AddedAt'] ? date('d M Y', strtotime($m['AddedAt'])) : ''; ?></td>
          <td>
            <form method="post" style="display:inline;" onsubmit="return confirm('Remove this student from the group?');">
              <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
              <input type="hidden" name="gid" value="<?php echo $qsMembers['gid']; ?>">
              <input type="hidden" name="tab" value="members">
              <input type="hidden" name="mp" value="<?php echo $memberPage; ?>">
              <input type="hidden" name="remove_uid" value="<?php echo (int)$m['UserInfoId']; ?>">
              <button type="submit" class="action-btn" style="background:#c53030;color:#fff;padding:3px 9px;border-radius:4px;font-size:.78rem;font-weight:600;border:none;cursor:pointer;">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php echo paginator($memberTotal, $memberPage, $memberPageSize, $qsMembers, 'mp');
}

/* ── Live search endpoint: same page, ?tab=members&ajax=1 ──────────────────
   Returns just the fragment above (result-count + table/empty-state +
   pager) as an HTML partial so the JS below can swap it in without a full
   page reload. No separate endpoint file — mirrors how every other action
   on this page already round-trips through StudentGroupMembers.php itself. */
if ($tab === 'members' && ($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    renderMembersFragment($members, $memberTotal, $groupTotalCount, $memberSearch, $memberPage, PAGE_SIZE, $qsMembers);
    exit;
}

$pageTitle = 'Manage Members — ' . $group['GroupName'];
$pageHead  = '<style>
  .sgm-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #e2e8f0;}
  .sgm-row:hover{background:#f7fafc;}
  .sgm-row:last-child{border-bottom:none;}

  /* ── Tab navigation (mirrors AdminUsers.php .au-tabs) ── */
  .sgm-tabs      { margin:0 0 16px; border-bottom:2px solid var(--clr-primary); display:flex; gap:4px; }
  .sgm-tab       { display:inline-block; padding:8px 22px; cursor:pointer;
                    background:#f1f5f9; border:1px solid #cbd5e1; border-bottom:none;
                    border-radius:6px 6px 0 0;
                    font-weight:600; font-size:13px; text-decoration:none; color:#475569; }
  .sgm-tab.active{ background:var(--clr-primary); color:#fff; border-color:var(--clr-primary); }
  .sgm-tab:hover:not(.active) { background:#e2e8f0; color:var(--clr-primary); }

  /* ── Search bar ── */
  .sgm-search    { padding:10px 16px;border-bottom:1px solid #e2e8f0;display:flex;
                    align-items:center;gap:10px;flex-wrap:wrap;background:#f7fafc; }
  .sgm-search input[type=text] { flex:1;min-width:220px;max-width:360px;padding:7px 12px;
                    border:1px solid #cbd5e0;border-radius:6px;font-size:.88rem; }
  .sgm-result-count { font-size:.8rem; color:#718096; padding:8px 16px 0; }

  /* ── Pager (mirrors AdminUsers.php .pager) ── */
  .pager        { margin:10px 16px; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
  .pager-link   { display:inline-block; padding:3px 10px; border:1px solid #cbd5e1; border-radius:4px;
                   text-decoration:none; color:#475569; }
  .pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
  .pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                   background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }
</style>';
include __DIR__ . '/../includes/header.php';
?>

<nav style="font-size:.85rem;color:#718096;margin-bottom:14px;">
  <a href="AdminUsers.php?tab=students" style="color:#3182ce;text-decoration:none;">&#128101; Users</a>
  <span style="margin:0 6px;">›</span>
  <a href="StudentGroups.php" style="color:#3182ce;text-decoration:none;">Student Groups</a>
  <span style="margin:0 6px;">›</span>
  <span><?php echo htmlspecialchars($group['GroupName']); ?> — Members</span>
</nav>

<?php if ($flashMsg): ?>
<div class="alert alert-<?php echo $flashType === 'success' ? 'success' : 'danger'; ?>" style="margin-bottom:14px;">
  <?php echo htmlspecialchars($flashMsg); ?>
</div>
<?php endif; ?>

<!-- ── Tab bar ──────────────────────────────────────────────────────────── -->
<div class="sgm-tabs">
  <a href="?gid=<?php echo $gid; ?>&tab=members" class="sgm-tab <?php echo $tab === 'members' ? 'active' : ''; ?>">
    &#128101; Current Members (<?php echo $groupTotalCount; ?>)
  </a>
  <a href="?gid=<?php echo $gid; ?>&tab=add" class="sgm-tab <?php echo $tab === 'add' ? 'active' : ''; ?>">
    &#10010; Add Students
  </a>
</div>

<?php if ($tab === 'members'): ?>
<!-- ── Current members ──────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-header">&#128101; Current Members (<?php echo $groupTotalCount; ?>)</div>

  <form method="get" class="sgm-search" id="memberSearchForm">
    <input type="hidden" name="gid" value="<?php echo $gid; ?>">
    <input type="hidden" name="tab" value="members">
    <input type="text" name="mq" id="memberSearchInput" autocomplete="off"
           value="<?php echo htmlspecialchars($memberSearch); ?>"
           placeholder="&#128269; Search current members by name, last name, login, or email…">
    <select name="msort" id="memberSortSelect" class="form-control" style="width:auto;">
      <option value="name"   <?php echo $memberSort === 'name'   ? 'selected' : ''; ?>>Sort: Name (A–Z)</option>
      <option value="recent" <?php echo $memberSort === 'recent' ? 'selected' : ''; ?>>Sort: Recently Added</option>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($memberSearch !== ''): ?>
      <a href="?gid=<?php echo $gid; ?>&tab=members" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
    <span id="memberSearchSpinner" style="display:none;font-size:.78rem;color:#718096;">Searching…</span>
  </form>

  <div id="membersFragment">
    <?php renderMembersFragment($members, $memberTotal, $groupTotalCount, $memberSearch, $memberPage, PAGE_SIZE, $qsMembers); ?>
  </div>
</div>

<?php else: /* $tab === 'add' */ ?>
<!-- ── Add members ──────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span>&#10010; Add Students</span>
    <span style="font-size:.85rem;color:#718096;"><span id="selCount">0</span> selected</span>
  </div>

  <!-- Standalone GET search form — must NOT be nested inside the POST
       "add students" form below (nested <form> is invalid HTML; browsers
       drop the inner <form> tag, so its Search button ends up submitting
       the OUTER form instead — POSTing action=add with no user_ids[],
       which fails validation and redirects to the default "members" tab,
       since that redirect doesn't carry a &tab=add. That was the bug. -->
  <form method="get" class="sgm-search" style="border-bottom:none;">
    <input type="hidden" name="gid" value="<?php echo $gid; ?>">
    <input type="hidden" name="tab" value="add">
    <input type="text" name="aq" value="<?php echo htmlspecialchars($addSearch); ?>"
           placeholder="&#128269; Search by name or login…">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($addSearch !== ''): ?>
      <a href="?gid=<?php echo $gid; ?>&tab=add" class="btn btn-secondary btn-sm">Clear search</a>
    <?php endif; ?>
  </form>
  <div class="sgm-search">
    <button type="button" onclick="selectAll(true)"  class="btn btn-secondary btn-sm">Select All (this page)</button>
    <button type="button" onclick="clearAllSelections()" class="btn btn-secondary btn-sm">Clear Selection</button>
  </div>

  <?php if ($addSearch !== ''): ?>
    <div class="sgm-result-count">Found <?php echo $candTotal; ?> student(s) matching “<?php echo htmlspecialchars($addSearch); ?>”.</div>
  <?php endif; ?>

  <?php if (empty($candidates)): ?>
    <div style="padding:30px;text-align:center;color:#718096;">
      <?php echo $addSearch !== '' ? 'No students match your search.' : 'Every student is already in this group.'; ?>
    </div>
  <?php else: ?>
  <form method="post" id="addStudentsForm">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
    <input type="hidden" name="gid" value="<?php echo $gid; ?>">
    <input type="hidden" name="action" value="add">
    <div id="studentList">
      <?php foreach ($candidates as $c):
        $fullName = trim($c['FstName'] . ' ' . $c['LstName']);
        $uid = (int)$c['UserInfoId'];
      ?>
      <div class="sgm-row student-item">
        <input type="checkbox" name="user_ids[]" value="<?php echo $uid; ?>" id="uid<?php echo $uid; ?>"
               onchange="toggleSelected(this.value, this.checked)"
               style="transform:scale(1.3);accent-color:#3182ce;flex-shrink:0;">
        <label for="uid<?php echo $uid; ?>" style="cursor:pointer;flex:1;">
          <div style="font-weight:600;"><?php echo htmlspecialchars($fullName); ?></div>
          <div style="font-size:.78rem;color:#718096;"><?php echo htmlspecialchars($c['LoginName'] ?? ''); ?></div>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <?php echo paginator($candTotal, $addPage, PAGE_SIZE, $qsAdd, 'ap'); ?>
    <div style="padding:14px 16px;border-top:1px solid #e2e8f0;background:#f7fafc;">
      <button type="submit" class="btn btn-success" style="font-weight:700;">&#128101; Add Selected Students</button>
      <a href="StudentGroups.php" class="btn btn-secondary">&#8592; Back to Groups</a>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
// ── Current Members: live search-as-you-type + instant sort ────────────────
// Debounced AJAX fetch against this same page (?tab=members&ajax=1) swaps in
// just the table+pager fragment — no Search-button click needed, and no full
// page reload, which matters once a group has 1000+ members. Falls back to a
// normal GET submit (the <form> is still real and unchanged) if JS is off.
(function () {
  var fragment = document.getElementById('membersFragment');
  var input    = document.getElementById('memberSearchInput');
  var sortSel  = document.getElementById('memberSortSelect');
  var spinner  = document.getElementById('memberSearchSpinner');
  if (!fragment || !input || !sortSel) return; // Add Students tab is active — nothing to wire up

  var gid          = <?php echo (int)$gid; ?>;
  var debounceTimer = null;
  var inFlight      = null;

  function fetchPage(page) {
    if (inFlight) inFlight.abort();
    var controller = new AbortController();
    inFlight = controller;

    var params = new URLSearchParams({
      gid: gid, tab: 'members', ajax: '1',
      mq: input.value, msort: sortSel.value, mp: page || 1
    });

    spinner.style.display = 'inline';
    fetch('?' + params.toString(), { signal: controller.signal })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        fragment.innerHTML = html;
        spinner.style.display = 'none';
        // Keep the address bar (and refresh/back-button) in sync without reloading.
        var shareParams = new URLSearchParams({ gid: gid, tab: 'members' });
        if (input.value)          shareParams.set('mq', input.value);
        if (sortSel.value !== 'name') shareParams.set('msort', sortSel.value);
        if ((page || 1) > 1)      shareParams.set('mp', page);
        history.replaceState(null, '', '?' + shareParams.toString());
      })
      .catch(function (err) {
        if (err.name !== 'AbortError') spinner.style.display = 'none';
      });
  }

  input.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () { fetchPage(1); }, 300);
  });
  sortSel.addEventListener('change', function () { fetchPage(1); });

  // Event delegation: pager links are re-rendered on every fetch, so one
  // listener on the stable container covers all of them, past and future.
  fragment.addEventListener('click', function (e) {
    var a = e.target.closest('.pager a');
    if (!a) return;
    e.preventDefault();
    var url = new URL(a.href, window.location.href);
    fetchPage(url.searchParams.get('mp') || 1);
  });

  document.getElementById('memberSearchForm').addEventListener('submit', function (e) {
    e.preventDefault();
    fetchPage(1);
  });
})();

// Selections persist across search/pagination on the Add Students tab (sessionStorage,
// scoped per group) so paging through candidates never silently drops a partial pick.
var SGM_KEY = 'sgm_selected_<?php echo $gid; ?>';

function loadSelected() {
  try { return JSON.parse(sessionStorage.getItem(SGM_KEY) || '[]'); } catch (e) { return []; }
}
function saveSelected(ids) { sessionStorage.setItem(SGM_KEY, JSON.stringify(ids)); }

function toggleSelected(uid, checked) {
  uid = String(uid);
  var ids = loadSelected();
  if (checked) {
    if (ids.indexOf(uid) === -1) ids.push(uid);
  } else {
    ids = ids.filter(function (id) { return id !== uid; });
  }
  saveSelected(ids);
  updateCount();
}
function updateCount() {
  var el = document.getElementById('selCount');
  if (el) el.textContent = loadSelected().length;
}
function selectAll(checked) {
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) {
    cb.checked = checked;
    toggleSelected(cb.value, checked);
  });
}
function clearAllSelections() {
  saveSelected([]);
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
  updateCount();
}
function restoreSelections() {
  var ids = loadSelected();
  document.querySelectorAll('#studentList input[type=checkbox]').forEach(function (cb) {
    if (ids.indexOf(cb.value) !== -1) cb.checked = true;
  });
  updateCount();
}

<?php if ($flashType === 'success'): ?>
// A add/remove just succeeded — any previously selected candidates are stale
// (either now members, or the selection belongs to a finished action), so
// don't carry them forward.
sessionStorage.removeItem(SGM_KEY);
<?php endif; ?>

document.addEventListener('DOMContentLoaded', restoreSelections);

var addForm = document.getElementById('addStudentsForm');
if (addForm) {
  addForm.addEventListener('submit', function () {
    // Include selections made on other pages that aren't in this page's DOM.
    var ids = loadSelected();
    var visible = new Set(Array.prototype.map.call(
      document.querySelectorAll('#studentList input[type=checkbox]'), function (cb) { return cb.value; }));
    var form = this;
    ids.forEach(function (id) {
      if (!visible.has(id)) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'user_ids[]';
        inp.value = id;
        form.appendChild(inp);
      }
    });
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
