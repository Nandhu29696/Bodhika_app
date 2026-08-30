<?php
/**
 * AdminUsers.php
 * Admin-only page: Registered Students, Teachers, Inactive Users, and
 * Login Activity, with search/filter by name, date range, institute, and
 * subject.
 *
 * The Inactive Users tab plus the per-row status-toggle switch and the
 * "Activate Selected" bulk action all post to ToggleUserActive.php, which
 * does the actual logininfo.Active update.
 *
 * Requires: PHP 7.4+, PDO (Database class), Auth class.
 * All queries use prepared statements — no SQL injection risk.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) {
    header('Location: ../exam/index.php');
    exit;
}

// This page's results depend entirely on the query string (sf_name, tf_name, ...).
// Force every layer (browser, proxy, CDN) to treat each query string as unique and
// never serve a cached response — otherwise a filtered search can appear to return
// the same cached unfiltered list.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── Active tab ────────────────────────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['students', 'teachers', 'inactive', 'logins'], true)
       ? $_GET['tab'] : 'students';

// CSRF token shared by every per-row status-toggle form and the bulk-activate
// form on the Inactive Users tab below.
$csrfToken = Auth::csrfToken();

// Flash message coming back from ToggleUserActive.php's redirect, e.g.
// "?flash=success|3 user(s) activated." — same success|message / error|message
// convention as BulkAssignInstitute.php.
$flash = isset($_GET['flash']) ? urldecode((string)$_GET['flash']) : '';
[$flashType, $flashMsg] = $flash !== '' ? array_pad(explode('|', $flash, 2), 2, '') : ['', ''];

// ── Shared filter helpers ─────────────────────────────────────────────────────
function safeStr(?string $v): string { return trim($v ?? ''); }
function safeDate(?string $v): string {
    if (!$v) return '';
    $d = DateTime::createFromFormat('Y-m-d', trim($v));
    return $d ? $d->format('Y-m-d') : '';
}

// ── Pagination constant ───────────────────────────────────────────────────────
const PAGE_SIZE = 25;
function currentPage(string $key = 'p'): int {
    return max(1, (int)($_GET[$key] ?? 1));
}

// ═════════════════════════════════════════════════════════════════════════════
// TAB 1 — Registered Students
// ═════════════════════════════════════════════════════════════════════════════
$students     = [];
$studentCount = 0;
$studentPage  = currentPage('sp');

// Load subject list for dropdown
$subjects = Database::fetchAll(
    "SELECT SubjectInfoId, SubjectName FROM subjectinfo WHERE Active='Y' ORDER BY SubjectName"
);

// Load institutes for filter dropdown (used in both tabs)
$institutes = Database::fetchAll(
    "SELECT InstituteId, InstituteName FROM institutes ORDER BY InstituteName"
);

// Cheap unconditional count for the "Inactive Users" tab badge — independent
// of that tab's own filters/pagination, so the admin sees at a glance how
// many accounts are waiting on activation without having to open the tab.
$inactiveBadgeCount = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS cnt
       FROM userinfo u
       LEFT JOIN logininfo l ON l.LoginName = u.LoginName
      WHERE l.Role IN ('STDNT','TEACH') AND l.Active = 'N'"
)['cnt'] ?? 0);

if ($tab === 'students') {
    // Filter inputs
    $sf_name      = safeStr($_GET['sf_name']      ?? '');
    $sf_mobile    = safeStr($_GET['sf_mobile']    ?? '');
    $sf_from      = safeDate($_GET['sf_from']     ?? '');
    $sf_to        = safeDate($_GET['sf_to']       ?? '');
    $sf_subject   = (int)($_GET['sf_subject']     ?? 0);
    $sf_institute = (int)($_GET['sf_institute']   ?? 0);
    $sf_status    = in_array($_GET['sf_status'] ?? '', ['active', 'inactive'], true)
                    ? $_GET['sf_status'] : '';

    // Build WHERE clauses + params
    $where  = ["1=1"];
    $params = [];

    if ($sf_name !== '') {
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
        $like     = "%{$sf_name}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($sf_mobile !== '') {
        $where[]  = "u.Mobile LIKE ?";
        $params[] = "%{$sf_mobile}%";
    }
    if ($sf_from !== '') {
        $where[]  = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) >= ?)";
        $params[] = $sf_from;
    }
    if ($sf_to !== '') {
        $where[]  = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) <= ?)";
        $params[] = $sf_to;
    }
    if ($sf_subject > 0) {
        $where[]  = "ep.SubjectInfoId = ?";
        $params[] = $sf_subject;
    }
    if ($sf_institute > 0) {
        $where[]  = "u.InstituteId = ?";
        $params[] = $sf_institute;
    }
    if ($sf_status === 'active') {
        $where[]  = "l.Active = 'Y'";
    } elseif ($sf_status === 'inactive') {
        $where[]  = "l.Active = 'N'";
    }

    $whereSQL = implode(' AND ', $where);

    // No registration-date column exists on userinfo, so "latest" means the
    // most recently created account, which for an auto-increment PK is the
    // highest UserInfoId. When hunting for inactive accounts to approve,
    // sort newest-first on UserInfoId so freshly registered users needing
    // activation surface at the top instead of being buried under whoever
    // last logged in.
    $studentOrderBy = $sf_status === 'inactive'
        ? 'u.UserInfoId DESC'
        : 'LastSeenAt DESC, u.UserInfoId DESC';

    $baseSQL = "FROM userinfo u
                LEFT JOIN logininfo l    ON l.LoginName   = u.LoginName
                LEFT JOIN enrollment_payments ep ON ep.UserInfoId = u.UserInfoId
                LEFT JOIN subjectinfo   s    ON s.SubjectInfoId   = ep.SubjectInfoId
                WHERE l.Role = 'STDNT' AND {$whereSQL}";

    // Count
    $countRow     = Database::fetchOne("SELECT COUNT(DISTINCT u.UserInfoId) AS cnt {$baseSQL}", $params);
    $studentCount = (int)($countRow['cnt'] ?? 0);

    // Fetch page
    $offset  = ($studentPage - 1) * PAGE_SIZE;
    $students = Database::fetchAll(
        "SELECT DISTINCT
                u.UserInfoId,
                u.FstName,
                u.LstName,
                u.LoginName,
                u.Mobile,
                u.EMail,
                l.Active,
                COALESCE(inst.InstituteName, '—') AS InstituteName,
                GROUP_CONCAT(DISTINCT s.SubjectName ORDER BY s.SubjectName SEPARATOR ', ') AS Subjects,
                (SELECT MAX(lt2.CreateDtm)
                   FROM logintrackinfo lt2
                  WHERE lt2.UserId = u.UserInfoId) AS LastSeenAt
         FROM userinfo u
         LEFT JOIN logininfo l    ON l.LoginName   = u.LoginName
         LEFT JOIN enrollment_payments ep ON ep.UserInfoId = u.UserInfoId
         LEFT JOIN subjectinfo   s    ON s.SubjectInfoId   = ep.SubjectInfoId
         LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
         WHERE l.Role = 'STDNT' AND {$whereSQL}
         GROUP BY u.UserInfoId
         ORDER BY {$studentOrderBy}
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

// ═════════════════════════════════════════════════════════════════════════════
// TAB 2 — Teachers
// ═════════════════════════════════════════════════════════════════════════════
$teachers     = [];
$teacherCount = 0;
$teacherPage  = currentPage('tp');

if ($tab === 'teachers') {
    // Filter inputs
    $tf_name      = safeStr($_GET['tf_name']      ?? '');
    $tf_from      = safeDate($_GET['tf_from']     ?? '');
    $tf_to        = safeDate($_GET['tf_to']       ?? '');
    $tf_subject   = (int)($_GET['tf_subject']     ?? 0);
    $tf_institute = (int)($_GET['tf_institute']   ?? 0);
    $tf_status    = in_array($_GET['tf_status'] ?? '', ['active', 'inactive'], true)
                    ? $_GET['tf_status'] : '';

    $where  = ["1=1"];
    $params = [];

    if ($tf_name !== '') {
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
        $like     = "%{$tf_name}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($tf_from !== '') {
        $where[]  = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) >= ?)";
        $params[] = $tf_from;
    }
    if ($tf_to !== '') {
        $where[]  = "EXISTS (SELECT 1 FROM logintrackinfo _lt WHERE _lt.UserId = u.UserInfoId AND DATE(_lt.CreateDtm) <= ?)";
        $params[] = $tf_to;
    }
    if ($tf_subject > 0) {
        $where[]  = "ts.SubjectInfoId = ?";
        $params[] = $tf_subject;
    }
    if ($tf_institute > 0) {
        $where[]  = "u.InstituteId = ?";
        $params[] = $tf_institute;
    }
    if ($tf_status === 'active') {
        $where[]  = "l.Active = 'Y'";
    } elseif ($tf_status === 'inactive') {
        $where[]  = "l.Active = 'N'";
    }

    $whereSQL = implode(' AND ', $where);

    $teacherOrderBy = $tf_status === 'inactive'
        ? 'u.UserInfoId DESC'
        : 'LastSeenAt DESC, u.UserInfoId DESC';

    $baseSQL = "FROM userinfo u
                LEFT JOIN logininfo l ON l.LoginName = u.LoginName
                LEFT JOIN teacher_profiles tp ON tp.UserInfoId = u.UserInfoId
                LEFT JOIN teacher_subjects ts ON ts.TeacherId = tp.TeacherId AND ts.Active='Y'
                WHERE l.Role = 'TEACH' AND {$whereSQL}";

    // Count
    $countRow     = Database::fetchOne("SELECT COUNT(DISTINCT u.UserInfoId) AS cnt {$baseSQL}", $params);
    $teacherCount = (int)($countRow['cnt'] ?? 0);

    // Fetch page
    $offset   = ($teacherPage - 1) * PAGE_SIZE;
    $teachers = Database::fetchAll(
        "SELECT DISTINCT
                u.UserInfoId,
                u.FstName,
                u.LstName,
                u.LoginName,
                u.Mobile,
                u.EMail,
                l.Active,
                tp.TeacherId,
                COALESCE(inst.InstituteName, '—') AS InstituteName,
                GROUP_CONCAT(DISTINCT COALESCE(ts.CourseName, s.SubjectName) ORDER BY COALESCE(ts.CourseName, s.SubjectName) SEPARATOR ', ') AS Subjects,
                (SELECT MAX(lt2.CreateDtm)
                   FROM logintrackinfo lt2
                  WHERE lt2.UserId = u.UserInfoId) AS LastSeenAt
         FROM userinfo u
         LEFT JOIN logininfo l ON l.LoginName = u.LoginName
         LEFT JOIN teacher_profiles tp ON tp.UserInfoId = u.UserInfoId
         LEFT JOIN teacher_subjects ts ON ts.TeacherId = tp.TeacherId AND ts.Active='Y'
         LEFT JOIN subjectinfo s ON s.SubjectInfoId = ts.SubjectInfoId
         LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
         WHERE l.Role = 'TEACH' AND {$whereSQL}
         GROUP BY u.UserInfoId
         ORDER BY {$teacherOrderBy}
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

// ═════════════════════════════════════════════════════════════════════════════
// TAB 3 — Inactive Users (students + teachers awaiting activation)
// ═════════════════════════════════════════════════════════════════════════════
$inactiveUsers = [];
$inactiveCount = 0;
$inactivePage  = currentPage('ip');

if ($tab === 'inactive') {
    $if_name      = safeStr($_GET['if_name']    ?? '');
    $if_institute = (int)($_GET['if_institute'] ?? 0);
    $if_role      = in_array($_GET['if_role'] ?? '', ['STDNT', 'TEACH'], true)
                     ? $_GET['if_role'] : '';

    // Always scoped to inactive students/teachers — this tab exists precisely
    // so admins never have to dig for these through the Registered Students
    // status filter. ADMIN/other roles never appear here.
    $where  = ["l.Role IN ('STDNT','TEACH')", "l.Active = 'N'"];
    $params = [];

    if ($if_name !== '') {
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
        $like     = "%{$if_name}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($if_institute > 0) {
        $where[]  = "u.InstituteId = ?";
        $params[] = $if_institute;
    }
    if ($if_role !== '') {
        $where[]  = "l.Role = ?";
        $params[] = $if_role;
    }

    $whereSQL = implode(' AND ', $where);
    $baseSQL  = "FROM userinfo u
                LEFT JOIN logininfo l ON l.LoginName = u.LoginName
                LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
                LEFT JOIN teacher_profiles tp ON tp.UserInfoId = u.UserInfoId
                WHERE {$whereSQL}";

    $countRow      = Database::fetchOne("SELECT COUNT(DISTINCT u.UserInfoId) AS cnt {$baseSQL}", $params);
    $inactiveCount = (int)($countRow['cnt'] ?? 0);

    $offset        = ($inactivePage - 1) * PAGE_SIZE;
    $inactiveUsers = Database::fetchAll(
        "SELECT DISTINCT
                u.UserInfoId,
                u.FstName,
                u.LstName,
                u.LoginName,
                u.Mobile,
                u.EMail,
                l.Role,
                tp.TeacherId,
                COALESCE(inst.InstituteName, '—') AS InstituteName,
                (SELECT MAX(lt2.CreateDtm)
                   FROM logintrackinfo lt2
                  WHERE lt2.UserId = u.UserInfoId) AS LastSeenAt
         FROM userinfo u
         LEFT JOIN logininfo l ON l.LoginName = u.LoginName
         LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
         LEFT JOIN teacher_profiles tp ON tp.UserInfoId = u.UserInfoId
         WHERE {$whereSQL}
         ORDER BY u.UserInfoId DESC
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

// ═════════════════════════════════════════════════════════════════════════════
// TAB 4 — Login Activity
// ═════════════════════════════════════════════════════════════════════════════
$logins     = [];
$loginCount = 0;
$loginPage  = currentPage('lp');

if ($tab === 'logins') {
    // Filter inputs — default "from" to 30 days ago when not set by the user
    $lf_name      = safeStr($_GET['lf_name']      ?? '');
    $lf_role      = safeStr($_GET['lf_role']      ?? '');
    $lf_institute = (int)($_GET['lf_institute']   ?? 0);
    $lf_from = isset($_GET['lf_from']) && $_GET['lf_from'] !== ''
               ? safeDate($_GET['lf_from'])
               : date('Y-m-d', strtotime('-30 days'));
    $lf_to   = safeDate($_GET['lf_to'] ?? '');

    // No super-admin exclusion here — show all login events including admin.
    $where  = ["1=1"];
    $params = [];

    if ($lf_name !== '') {
        $where[]  = "(u.FstName LIKE ? OR u.LstName LIKE ? OR u.LoginName LIKE ?)";
        $like     = "%{$lf_name}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($lf_role !== '') {
        $where[]  = "li.Role LIKE ?";
        $params[] = "%{$lf_role}%";
    }
    if ($lf_institute > 0) {
        $where[]  = "u.InstituteId = ?";
        $params[] = $lf_institute;
    }
    if ($lf_from !== '') {
        $where[]  = "DATE(lt.CreateDtm) >= ?";
        $params[] = $lf_from;
    }
    if ($lf_to !== '') {
        $where[]  = "DATE(lt.CreateDtm) <= ?";
        $params[] = $lf_to;
    }

    $whereSQL = implode(' AND ', $where);

    // lt.UserId is positive = userinfo.UserInfoId for normal users
    // lt.UserId is negative = -logininfo.LoginInfoId for accounts with no userinfo row
    $baseSQL = "FROM logintrackinfo lt
                LEFT JOIN userinfo  u  ON u.UserInfoId = lt.UserId AND lt.UserId > 0
                LEFT JOIN logininfo li ON (
                    (lt.UserId > 0  AND u.LoginName IS NOT NULL AND li.LoginName  = u.LoginName)
                    OR
                    (lt.UserId < 0  AND li.LoginInfoId = -lt.UserId)
                )
                LEFT JOIN institutes inst ON inst.InstituteId = u.InstituteId
                WHERE {$whereSQL}";

    $countRow   = Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params);
    $loginCount = (int)($countRow['cnt'] ?? 0);

    $offset = ($loginPage - 1) * PAGE_SIZE;
    $logins = Database::fetchAll(
        "SELECT lt.UserId,
                COALESCE(u.LoginName, li.LoginName, '') AS TrackLogin,
                COALESCE(u.FstName, '')  AS FstName,
                COALESCE(u.LstName, '')  AS LstName,
                COALESCE(u.EMail,   '')  AS EMail,
                COALESCE(li.Role,  '—') AS RoleDesc,
                COALESCE(inst.InstituteName, '—') AS InstituteName,
                lt.CreateDtm AS LoginAt
         {$baseSQL}
         ORDER BY lt.CreateDtm DESC
         LIMIT {$offset}, " . PAGE_SIZE,
        $params
    );
}

// ── Role list for login filter dropdown (from logininfo.Role string column) ───
$roles = Database::fetchAll(
    "SELECT DISTINCT Role AS RoleDesc FROM logininfo WHERE Role IS NOT NULL AND Role != '' ORDER BY Role");

// ── Pagination helper ─────────────────────────────────────────────────────────
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

// Build current querystring for paginators (preserves filters)
$currentQS_students = array_filter([
    'tab'          => 'students',
    'sf_name'      => $_GET['sf_name']      ?? '',
    'sf_mobile'    => $_GET['sf_mobile']    ?? '',
    'sf_from'      => $_GET['sf_from']      ?? '',
    'sf_to'        => $_GET['sf_to']        ?? '',
    'sf_subject'   => $_GET['sf_subject']   ?? '',
    'sf_institute' => $_GET['sf_institute'] ?? '',
    'sf_status'    => $_GET['sf_status']    ?? '',
]);
$currentQS_logins = array_filter([
    'tab'          => 'logins',
    'lf_name'      => $_GET['lf_name']      ?? '',
    'lf_role'      => $_GET['lf_role']      ?? '',
    'lf_from'      => $_GET['lf_from']      ?? '',
    'lf_to'        => $_GET['lf_to']        ?? '',
    'lf_institute' => $_GET['lf_institute'] ?? '',
]);
$currentQS_teachers = array_filter([
    'tab'          => 'teachers',
    'tf_name'      => $_GET['tf_name']      ?? '',
    'tf_from'      => $_GET['tf_from']      ?? '',
    'tf_to'        => $_GET['tf_to']        ?? '',
    'tf_subject'   => $_GET['tf_subject']   ?? '',
    'tf_institute' => $_GET['tf_institute'] ?? '',
    'tf_status'    => $_GET['tf_status']    ?? '',
]);
$currentQS_inactive = array_filter([
    'tab'          => 'inactive',
    'if_name'      => $_GET['if_name']      ?? '',
    'if_institute' => $_GET['if_institute'] ?? '',
    'if_role'      => $_GET['if_role']      ?? '',
]);

$pageTitle = 'Users';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ── Page wrapper ── */
.au-wrap { max-width:1100px; margin:0 auto; padding:0 16px; }

/* ── Page title ── */
.au-page-title { font-size:1.3rem; font-weight:700; color:var(--clr-primary);
                 margin:0 0 16px; display:flex; align-items:center; gap:8px; }

/* ── Tab navigation ── */
.au-tabs       { margin: 0 0 16px; border-bottom: 2px solid var(--clr-primary); display:flex; gap:4px; }
.au-tab        { display:inline-block; padding:8px 22px; cursor:pointer;
                 background:#f1f5f9; border:1px solid #cbd5e1; border-bottom:none;
                 border-radius:6px 6px 0 0;
                 font-weight:600; font-size:13px; text-decoration:none; color:#475569; }
.au-tab.active { background:var(--clr-primary); color:#fff; border-color:var(--clr-primary); }
.au-tab:hover:not(.active) { background:#e2e8f0; color:var(--clr-primary); }

/* ── Search / filter bar ── */
.au-search { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
             padding:14px 16px; margin-bottom:14px; }
.au-search form { display:flex; flex-wrap:wrap; align-items:flex-end; gap:14px 18px; }

/* Each label+input is one unit so it can never wrap apart mid-pair */
.au-field { display:flex; flex-direction:column; gap:5px; }
.au-field label { font-size:.72rem; font-weight:800; color:var(--clr-primary);
                   text-transform:uppercase; letter-spacing:.06em; white-space:nowrap; }
.au-field input[type=text],
.au-field input[type=date],
.au-field select {
    height:34px; border:1px solid #cbd5e1; border-radius:5px;
    font-size:13px; padding:0 10px; color:#1e293b; background:#fff; box-sizing:border-box; }
.au-field input[type=text]:focus,
.au-field input[type=date]:focus,
.au-field select:focus { outline:none; border-color:var(--clr-primary); box-shadow:0 0 0 2px rgba(79,70,229,.15); }

.au-field-name input     { width:170px; }
.au-field-institute select,
.au-field-subject select,
.au-field-role select,
.au-field-status select  { width:140px; }
.au-field-date input     { width:150px; }

/* Search + Reset sit in their own group so they bottom-align with the inputs */
.au-actions { display:flex; align-items:center; gap:14px; }
.au-search .btn-search {
    background:var(--clr-gold); color:#fff; border:none; padding:0 20px; height:34px;
    border-radius:5px; cursor:pointer; font-size:13px; font-weight:600; }
.au-search .btn-search:hover { filter:brightness(1.1); }
.au-search a.reset-link { font-size:12px; color:#64748b; text-decoration:none; }
.au-search a.reset-link:hover { color:var(--clr-primary); }

@media (max-width: 700px) {
  .au-search form { gap:12px; }
  .au-field-name input,
  .au-field-institute select,
  .au-field-subject select,
  .au-field-role select,
  .au-field-status select,
  .au-field-date input { width:100%; min-width:0; }
  .au-field { flex:1 1 100%; }
  .au-actions { flex:1 1 100%; justify-content:flex-start; }
}

/* ── Results table ── */
.au-table     { width:100%; border-collapse:collapse; margin-top:4px;
                font-size:13px; border-radius:8px; overflow:hidden;
                box-shadow:0 1px 4px rgba(0,0,0,.08); }
.au-table th  { background:var(--clr-primary); color:#fff; padding:9px 12px;
                font-size:12px; text-align:left; white-space:nowrap; }
.au-table td  { padding:8px 12px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
.au-table tr.odd  td { background:#fff; }
.au-table tr.even td { background:#f8fafc; }
.au-table tr:hover td { background:#eff6ff; }

/* ── Badges ── */
.badge        { display:inline-block; padding:2px 9px; border-radius:12px;
                font-size:11px; font-weight:700; }
.badge-y      { background:#dcfce7; color:#15803d; }
.badge-n      { background:#fee2e2; color:#b91c1c; }

/* ── Pager ── */
.pager        { margin:10px 0; font-size:12px; display:flex; flex-wrap:wrap; gap:4px; }
.pager-link   { display:inline-block; padding:3px 10px;
                border:1px solid #cbd5e1; border-radius:4px;
                text-decoration:none; color:#475569; }
.pager-link:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
.pager-active { display:inline-block; padding:3px 10px; border-radius:4px;
                background:var(--clr-primary); color:#fff; border:1px solid var(--clr-primary); }

/* ── Count badge ── */
.result-count { font-size:12px; color:#64748b; margin-bottom:8px; }

/* ── Tab notification badge (Inactive Users count) ── */
.au-tab-badge { display:inline-block; min-width:16px; margin-left:6px; padding:1px 6px;
                border-radius:10px; background:#ef4444; color:#fff;
                font-size:10.5px; font-weight:800; line-height:1.4; text-align:center; }
.au-tab.active .au-tab-badge { background:rgba(255,255,255,.3); }

/* ── Active/Inactive status toggle switch ── */
.au-switch { position:relative; display:inline-block; width:40px; height:22px;
             border:none; border-radius:11px; padding:0; cursor:pointer;
             transition:background-color .15s ease; vertical-align:middle; }
.au-switch.on  { background:#22c55e; }
.au-switch.off { background:#cbd5e1; }
.au-switch:hover { filter:brightness(1.05); }
.au-switch-knob { position:absolute; top:2px; left:2px; width:18px; height:18px;
                   border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.35);
                   transition:left .15s ease; }
.au-switch.on .au-switch-knob { left:20px; }

/* ── Bulk-select action bar (Inactive Users tab) ── */
.au-bulkbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap;
              background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px;
              padding:10px 14px; margin-bottom:10px; }
.au-bulkbar .sel-count { font-size:12px; color:#1e3a8a; font-weight:700; }
.au-bulkbar button:disabled { opacity:.5; cursor:not-allowed; }
</style>

<div class="au-wrap">
<div class="au-page-title">&#x1F465; Users</div>

<?php if ($flashMsg !== ''): ?>
  <div class="alert alert-<?= $flashType === 'success' ? 'success' : 'danger' ?>" style="margin-bottom:14px;">
    <?= htmlspecialchars($flashMsg) ?>
  </div>
<?php endif; ?>

<!-- ── Tab bar ──────────────────────────────────────────────────────────── -->
<div class="au-tabs">
    <a href="?tab=students" class="au-tab <?= $tab==='students'?'active':'' ?>">
        &#x1F393; Registered Students
    </a>
    <a href="?tab=teachers" class="au-tab <?= $tab==='teachers'?'active':'' ?>">
        &#x1F9D1;&#x200D;&#x1F3EB; Teachers
    </a>
    <a href="?tab=inactive" class="au-tab <?= $tab==='inactive'?'active':'' ?>">
        &#x1F6AB; Inactive Users<?php if ($inactiveBadgeCount > 0): ?><span class="au-tab-badge"><?= number_format($inactiveBadgeCount) ?></span><?php endif; ?>
    </a>
    <a href="?tab=logins" class="au-tab <?= $tab==='logins'?'active':'' ?>">
        &#x1F511; Login Activity
    </a>
</div>

<?php if ($tab === 'students'): ?>
<!-- ════════════════════════════════════════════════════════════════════════
     TAB 1 — Registered Students
     ════════════════════════════════════════════════════════════════════════ -->

<div class="au-search">
<form method="get" action="">
  <input type="hidden" name="tab" value="students">
  <div class="au-field au-field-name">
    <label>Name / Username</label>
    <input type="text" name="sf_name" value="<?= htmlspecialchars($sf_name) ?>" placeholder="Search…">
  </div>
  <div class="au-field au-field-name">
    <label>Mobile Number</label>
    <input type="text" name="sf_mobile" value="<?= htmlspecialchars($sf_mobile) ?>" placeholder="Search…" inputmode="numeric">
  </div>
  <div class="au-field au-field-institute">
    <label>Institute</label>
    <select name="sf_institute">
      <option value="0">— All Institutes —</option>
      <?php foreach ($institutes as $inst): ?>
        <option value="<?= (int)$inst['InstituteId'] ?>" <?= $sf_institute===(int)$inst['InstituteId']?'selected':'' ?>>
          <?= htmlspecialchars($inst['InstituteName']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="au-field au-field-status">
    <label>Status</label>
    <select name="sf_status">
      <option value="">— All —</option>
      <option value="active"   <?= $sf_status==='active'   ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $sf_status==='inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <div class="au-field au-field-date">
    <label>Activity From</label>
    <input type="date" name="sf_from" value="<?= htmlspecialchars($sf_from) ?>">
  </div>
  <div class="au-field au-field-date">
    <label>To</label>
    <input type="date" name="sf_to" value="<?= htmlspecialchars($sf_to) ?>">
  </div>
  <div class="au-field au-field-subject">
    <label>Subject</label>
    <select name="sf_subject">
      <option value="0">— All Subjects —</option>
      <?php foreach ($subjects as $s): ?>
        <option value="<?= (int)$s['SubjectInfoId'] ?>" <?= $sf_subject===(int)$s['SubjectInfoId']?'selected':'' ?>>
          <?= htmlspecialchars($s['SubjectName']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="au-actions">
    <button type="submit" class="btn-search">Search</button>
    <a href="?tab=students" class="reset-link">Reset</a>
  </div>
</form>
</div>

<!-- Hidden helper form the per-row status-toggle switch submits into (see
     toggleUserActive() in the page script below) — keeps each switch a plain
     <button>, since a real <form> per row can't nest inside this tab's list. -->
<form method="post" action="ToggleUserActive.php" id="toggleForm_students" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="user_ids[]" value="">
  <input type="hidden" name="new_status" value="">
  <input type="hidden" name="tab" value="students">
  <input type="hidden" name="return_qs" value="<?= htmlspecialchars(http_build_query($currentQS_students)) ?>">
</form>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
  <div class="result-count"><?= number_format($studentCount) ?> student<?= $studentCount===1?'':'s' ?> found</div>
  <div style="display:flex;gap:8px;">
  <a href="BulkUploadStudents.php" style="display:inline-flex;align-items:center;gap:6px;background:#2563eb;color:#fff;
      padding:6px 14px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;">
    &#x2B06; Bulk Upload Students
  </a>
  <a href="../exam/export-excel.php?type=students&<?= http_build_query(array_filter([
      'sf_name'      => $sf_name,
      'sf_mobile'    => $sf_mobile,
      'sf_from'      => $sf_from,
      'sf_to'        => $sf_to,
      'sf_subject'   => $sf_subject   ?: '',
      'sf_institute' => $sf_institute ?: '',
  ])) ?>" style="display:inline-flex;align-items:center;gap:6px;background:#16a34a;color:#fff;
      padding:6px 14px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;">
    &#x1F4E5; Export to Excel
  </a>
  </div>
</div>

<?php if ($students): ?>
<?= paginator($studentCount, $studentPage, PAGE_SIZE, $currentQS_students, 'sp') ?>
<table class="au-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Name</th>
      <th>Username</th>
      <th>Institute</th>
      <th>Email</th>
      <th>Mobile</th>
      <th>Subjects Enrolled</th>
      <th>Last Seen</th>
      <th>Active</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php
    $rowNum = ($studentPage - 1) * PAGE_SIZE + 1;
    foreach ($students as $i => $r):
        $rowClass = $i % 2 === 0 ? 'odd' : 'even';
        $name     = htmlspecialchars(Pii::name(trim($r['FstName'] . ' ' . $r['LstName'])));
        $uname    = htmlspecialchars($r['LoginName'] ?? '');
        $email    = htmlspecialchars(Pii::email($r['EMail']  ?? ''));
        $mobile   = htmlspecialchars(Pii::mobile($r['Mobile'] ?? ''));
        $subjects = htmlspecialchars($r['Subjects'] ?? '—');
        $active   = ($r['Active'] ?? 'N') === 'Y';
        $lastSeen = !empty($r['LastSeenAt'])
                    ? date('d M Y H:i', strtotime($r['LastSeenAt'])) : '—';
    ?>
    <tr class="<?= $rowClass ?>">
      <td><?= $rowNum++ ?></td>
      <td><?= $name ?></td>
      <td><?= $uname ?></td>
      <td style="font-size:11px;"><?= htmlspecialchars($r['InstituteName'] ?? '—') ?></td>
      <td><?= $email ?></td>
      <td><?= $mobile ?: '—' ?></td>
      <td><?= $subjects ?></td>
      <td style="white-space:nowrap;font-size:11px;"><?= $lastSeen ?></td>
      <td>
        <button type="button" class="au-switch <?= $active?'on':'off' ?>"
                onclick="return toggleUserActive('toggleForm_students', <?= (int)$r['UserInfoId'] ?>, '<?= $active?'N':'Y' ?>', '<?= $active?'deactivate':'activate' ?>')"
                title="Click to <?= $active?'deactivate':'activate' ?>">
          <span class="au-switch-knob"></span>
        </button>
      </td>
      <td style="white-space:nowrap;">
        <?php if ($r['UserInfoId'] > 0): ?>
          <a href="../exam/history.php?UserInfoId=<?= (int)$r['UserInfoId'] ?>"
             class="btn btn-xs" title="View exam history">&#128200; History</a>
          <a href="../exam/history.php?UserInfoId=<?= (int)$r['UserInfoId'] ?>&filter=assigned"
             class="btn btn-xs" title="View this student's assigned exams (exam_assignments — individual assignments only, not student-group 'recommended' tags)">&#128203; Assigned Exams</a>
          <a href="EditUser.php?id=<?= (int)$r['UserInfoId'] ?>"
             class="btn btn-xs" title="Edit user">✏️ Edit</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?= paginator($studentCount, $studentPage, PAGE_SIZE, $currentQS_students, 'sp') ?>

<?php else: ?>
  <p style="color:#888;font-size:13px;">No students match the current filters.</p>
<?php endif; ?>


<?php elseif ($tab === 'teachers'): ?>
<!-- ════════════════════════════════════════════════════════════════════════
     TAB 2 — Teachers
     ════════════════════════════════════════════════════════════════════════ -->

<div class="au-search">
<form method="get" action="">
  <input type="hidden" name="tab" value="teachers">
  <div class="au-field au-field-name">
    <label>Name / Username</label>
    <input type="text" name="tf_name" value="<?= htmlspecialchars($tf_name) ?>" placeholder="Search…">
  </div>
  <div class="au-field au-field-institute">
    <label>Institute</label>
    <select name="tf_institute">
      <option value="0">— All Institutes —</option>
      <?php foreach ($institutes as $inst): ?>
        <option value="<?= (int)$inst['InstituteId'] ?>" <?= $tf_institute===(int)$inst['InstituteId']?'selected':'' ?>>
          <?= htmlspecialchars($inst['InstituteName']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="au-field au-field-status">
    <label>Status</label>
    <select name="tf_status">
      <option value="">— All —</option>
      <option value="active"   <?= $tf_status==='active'   ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $tf_status==='inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <div class="au-field au-field-date">
    <label>Activity From</label>
    <input type="date" name="tf_from" value="<?= htmlspecialchars($tf_from) ?>">
  </div>
  <div class="au-field au-field-date">
    <label>To</label>
    <input type="date" name="tf_to" value="<?= htmlspecialchars($tf_to) ?>">
  </div>
  <div class="au-field au-field-subject">
    <label>Subject Taught</label>
    <select name="tf_subject">
      <option value="0">— All Subjects —</option>
      <?php foreach ($subjects as $s): ?>
        <option value="<?= (int)$s['SubjectInfoId'] ?>" <?= $tf_subject===(int)$s['SubjectInfoId']?'selected':'' ?>>
          <?= htmlspecialchars($s['SubjectName']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="au-actions">
    <button type="submit" class="btn-search">Search</button>
    <a href="?tab=teachers" class="reset-link">Reset</a>
  </div>
</form>
</div>

<!-- Hidden helper form the per-row status-toggle switch submits into. -->
<form method="post" action="ToggleUserActive.php" id="toggleForm_teachers" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="user_ids[]" value="">
  <input type="hidden" name="new_status" value="">
  <input type="hidden" name="tab" value="teachers">
  <input type="hidden" name="return_qs" value="<?= htmlspecialchars(http_build_query($currentQS_teachers)) ?>">
</form>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
  <div class="result-count"><?= number_format($teacherCount) ?> teacher<?= $teacherCount===1?'':'s' ?> found</div>
  <a href="../exam/export-excel.php?type=teachers&<?= http_build_query(array_filter([
      'tf_name'      => $tf_name,
      'tf_from'      => $tf_from,
      'tf_to'        => $tf_to,
      'tf_subject'   => $tf_subject   ?: '',
      'tf_institute' => $tf_institute ?: '',
  ])) ?>" style="display:inline-flex;align-items:center;gap:6px;background:#16a34a;color:#fff;
      padding:6px 14px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;">
    &#x1F4E5; Export to Excel
  </a>
</div>

<?php if ($teachers): ?>
<?= paginator($teacherCount, $teacherPage, PAGE_SIZE, $currentQS_teachers, 'tp') ?>
<table class="au-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Name</th>
      <th>Username</th>
      <th>Institute</th>
      <th>Email</th>
      <th>Mobile</th>
      <th>Subjects Taught</th>
      <th>Last Seen</th>
      <th>Active</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php
    $rowNum = ($teacherPage - 1) * PAGE_SIZE + 1;
    foreach ($teachers as $i => $r):
        $rowClass  = $i % 2 === 0 ? 'odd' : 'even';
        $name      = htmlspecialchars(Pii::name(trim($r['FstName'] . ' ' . $r['LstName'])));
        $uname     = htmlspecialchars($r['LoginName'] ?? '');
        $email     = htmlspecialchars(Pii::email($r['EMail']  ?? ''));
        $mobile    = htmlspecialchars(Pii::mobile($r['Mobile'] ?? ''));
        $subjects  = htmlspecialchars($r['Subjects'] ?? '—');
        $active    = ($r['Active'] ?? 'N') === 'Y';
        $lastSeen  = !empty($r['LastSeenAt'])
                     ? date('d M Y H:i', strtotime($r['LastSeenAt'])) : '—';
        $teacherId = (int)($r['TeacherId'] ?? 0);
    ?>
    <tr class="<?= $rowClass ?>">
      <td><?= $rowNum++ ?></td>
      <td><?= $name ?></td>
      <td><?= $uname ?></td>
      <td style="font-size:11px;"><?= htmlspecialchars($r['InstituteName'] ?? '—') ?></td>
      <td><?= $email ?></td>
      <td><?= $mobile ?: '—' ?></td>
      <td><?= $subjects ?></td>
      <td style="white-space:nowrap;font-size:11px;"><?= $lastSeen ?></td>
      <td>
        <button type="button" class="au-switch <?= $active?'on':'off' ?>"
                onclick="return toggleUserActive('toggleForm_teachers', <?= (int)$r['UserInfoId'] ?>, '<?= $active?'N':'Y' ?>', '<?= $active?'deactivate':'activate' ?>')"
                title="Click to <?= $active?'deactivate':'activate' ?>">
          <span class="au-switch-knob"></span>
        </button>
      </td>
      <td style="white-space:nowrap;">
        <?php if ($teacherId > 0): ?>
          <a href="ManageTeachers.php?tab=profile&id=<?= $teacherId ?>"
             class="btn btn-xs" title="Manage profile">✏️ Edit</a>
        <?php elseif ($r['UserInfoId'] > 0): ?>
          <a href="EditUser.php?id=<?= (int)$r['UserInfoId'] ?>"
             class="btn btn-xs" title="Edit user">✏️ Edit</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?= paginator($teacherCount, $teacherPage, PAGE_SIZE, $currentQS_teachers, 'tp') ?>

<?php else: ?>
  <p style="color:#888;font-size:13px;">No teachers match the current filters.</p>
<?php endif; ?>


<?php elseif ($tab === 'inactive'): ?>
<!-- ════════════════════════════════════════════════════════════════════════
     TAB — Inactive Users (students + teachers awaiting activation)
     ════════════════════════════════════════════════════════════════════════ -->

<div class="au-search">
<form method="get" action="">
  <input type="hidden" name="tab" value="inactive">
  <div class="au-field au-field-name">
    <label>Name / Username</label>
    <input type="text" name="if_name" value="<?= htmlspecialchars($if_name) ?>" placeholder="Search…">
  </div>
  <div class="au-field au-field-role">
    <label>Role</label>
    <select name="if_role">
      <option value="">— Students &amp; Teachers —</option>
      <option value="STDNT" <?= $if_role==='STDNT' ? 'selected' : '' ?>>Students Only</option>
      <option value="TEACH" <?= $if_role==='TEACH' ? 'selected' : '' ?>>Teachers Only</option>
    </select>
  </div>
  <div class="au-field au-field-institute">
    <label>Institute</label>
    <select name="if_institute">
      <option value="0">— All Institutes —</option>
      <?php foreach ($institutes as $inst): ?>
        <option value="<?= (int)$inst['InstituteId'] ?>" <?= $if_institute===(int)$inst['InstituteId']?'selected':'' ?>>
          <?= htmlspecialchars($inst['InstituteName']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="au-actions">
    <button type="submit" class="btn-search">Search</button>
    <a href="?tab=inactive" class="reset-link">Reset</a>
  </div>
</form>
</div>

<!-- Hidden helper form the per-row status-toggle switch submits into. -->
<form method="post" action="ToggleUserActive.php" id="toggleForm_inactive" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="user_ids[]" value="">
  <input type="hidden" name="new_status" value="">
  <input type="hidden" name="tab" value="inactive">
  <input type="hidden" name="return_qs" value="<?= htmlspecialchars(http_build_query($currentQS_inactive)) ?>">
</form>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
  <div class="result-count"><?= number_format($inactiveCount) ?> inactive user<?= $inactiveCount===1?'':'s' ?> found</div>
</div>

<?php if ($inactiveUsers): ?>

<!-- One <form> wraps the whole table so the checkboxes can post together —
     the per-row toggle switch above therefore can't be its own nested
     <form>, hence the shared hidden #toggleForm_inactive instead. -->
<form method="post" action="ToggleUserActive.php" id="bulkActivateForm">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="new_status" value="Y">
  <input type="hidden" name="tab" value="inactive">
  <input type="hidden" name="return_qs" value="<?= htmlspecialchars(http_build_query($currentQS_inactive)) ?>">

  <div class="au-bulkbar">
    <button type="button" class="btn btn-sm btn-outline" onclick="auSelectAll(true)">&#x2611; Select All (this page)</button>
    <button type="button" class="btn btn-sm btn-outline" onclick="auClearSelection()">Clear Selection</button>
    <span class="sel-count"><span id="auSelCount">0</span> selected</span>
    <button type="submit" id="auActivateBtn" class="btn btn-sm"
            style="background:#16a34a;border-color:#16a34a;color:#fff;margin-left:auto;font-weight:700;" disabled>
      &#x2713; Activate Selected
    </button>
  </div>

  <?= paginator($inactiveCount, $inactivePage, PAGE_SIZE, $currentQS_inactive, 'ip') ?>
  <table class="au-table">
    <thead>
      <tr>
        <th style="width:34px;"><input type="checkbox" onclick="auSelectAll(this.checked)" style="transform:scale(1.2);" title="Select all on this page"></th>
        <th>#</th>
        <th>Name</th>
        <th>Username</th>
        <th>Role</th>
        <th>Institute</th>
        <th>Email</th>
        <th>Mobile</th>
        <th>Last Seen</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="inactiveList">
      <?php
      $rowNum = ($inactivePage - 1) * PAGE_SIZE + 1;
      foreach ($inactiveUsers as $i => $r):
          $rowClass  = $i % 2 === 0 ? 'odd' : 'even';
          $uid       = (int)$r['UserInfoId'];
          $name      = htmlspecialchars(Pii::name(trim($r['FstName'] . ' ' . $r['LstName'])));
          $uname     = htmlspecialchars($r['LoginName'] ?? '');
          $email     = htmlspecialchars(Pii::email($r['EMail']  ?? ''));
          $mobile    = htmlspecialchars(Pii::mobile($r['Mobile'] ?? ''));
          $isTeach   = ($r['Role'] ?? '') === 'TEACH';
          $lastSeen  = !empty($r['LastSeenAt']) ? date('d M Y H:i', strtotime($r['LastSeenAt'])) : 'Never';
          $teacherId = (int)($r['TeacherId'] ?? 0);
      ?>
      <tr class="<?= $rowClass ?>">
        <td>
          <input type="checkbox" name="user_ids[]" value="<?= $uid ?>" id="iuid<?= $uid ?>"
                 onchange="auToggleSelected(this.value, this.checked)"
                 style="transform:scale(1.2);accent-color:#2563eb;">
        </td>
        <td><?= $rowNum++ ?></td>
        <td><label for="iuid<?= $uid ?>" style="cursor:pointer;"><?= $name ?></label></td>
        <td><?= $uname ?></td>
        <td>
          <span class="badge" style="background:<?= $isTeach?'#e0e7ff':'#dcfce7' ?>;color:<?= $isTeach?'#3730a3':'#15803d' ?>;">
            <?= $isTeach?'Teacher':'Student' ?>
          </span>
        </td>
        <td style="font-size:11px;"><?= htmlspecialchars($r['InstituteName'] ?? '—') ?></td>
        <td><?= $email ?></td>
        <td><?= $mobile ?: '—' ?></td>
        <td style="white-space:nowrap;font-size:11px;"><?= $lastSeen ?></td>
        <td>
          <button type="button" class="au-switch off"
                  onclick="return toggleUserActive('toggleForm_inactive', <?= $uid ?>, 'Y', 'activate')"
                  title="Click to activate">
            <span class="au-switch-knob"></span>
          </button>
        </td>
        <td style="white-space:nowrap;">
          <?php if ($teacherId > 0): ?>
            <a href="ManageTeachers.php?tab=profile&id=<?= $teacherId ?>" class="btn btn-xs" title="Manage profile">✏️ Edit</a>
          <?php else: ?>
            <a href="EditUser.php?id=<?= $uid ?>" class="btn btn-xs" title="Edit user">✏️ Edit</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?= paginator($inactiveCount, $inactivePage, PAGE_SIZE, $currentQS_inactive, 'ip') ?>
</form>

<?php else: ?>
  <p style="color:#888;font-size:13px;">&#x1F389; No inactive users match the current filters.</p>
<?php endif; ?>


<?php else: // tab === 'logins' ?>
<!-- ════════════════════════════════════════════════════════════════════════
     TAB 4 — Login Activity
     ════════════════════════════════════════════════════════════════════════ -->

<div class="au-search">
<form method="get" action="">
  <input type="hidden" name="tab" value="logins">
  <div class="au-field au-field-name">
    <label>Name</label>
    <input type="text" name="lf_name" value="<?= htmlspecialchars($lf_name) ?>" placeholder="Search…">
  </div>
  <div class="au-field au-field-institute">
    <label>Institute</label>
    <select name="lf_institute">
      <option value="0">— All Institutes —</option>
      <?php foreach ($institutes as $inst): ?>
        <option value="<?= (int)$inst['InstituteId'] ?>" <?= $lf_institute===(int)$inst['InstituteId']?'selected':'' ?>>
          <?= htmlspecialchars($inst['InstituteName']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="au-field au-field-role">
    <label>Role</label>
    <select name="lf_role">
      <option value="">— All Roles —</option>
      <?php foreach ($roles as $r): ?>
        <option value="<?= htmlspecialchars($r['RoleDesc']) ?>" <?= $lf_role===trim($r['RoleDesc'])?'selected':'' ?>>
          <?= htmlspecialchars($r['RoleDesc']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="au-field au-field-date">
    <label>Login From</label>
    <input type="date" name="lf_from" value="<?= htmlspecialchars($lf_from) ?>"
           title="Defaults to last 30 days">
  </div>
  <div class="au-field au-field-date">
    <label>To</label>
    <input type="date" name="lf_to" value="<?= htmlspecialchars($lf_to) ?>">
  </div>
  <div class="au-actions">
    <button type="submit" class="btn-search">Search</button>
    <a href="?tab=logins" class="reset-link">Reset</a>
  </div>
</form>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
  <div class="result-count"><?= number_format($loginCount) ?> login event<?= $loginCount===1?'':'s' ?> found</div>
  <a href="../exam/export-excel.php?type=logins&<?= http_build_query(array_filter([
      'lf_name'      => $lf_name,
      'lf_role'      => $lf_role,
      'lf_from'      => $lf_from,
      'lf_to'        => $lf_to,
      'lf_institute' => $lf_institute ?: '',
  ])) ?>" style="display:inline-flex;align-items:center;gap:6px;background:#16a34a;color:#fff;
      padding:6px 14px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;">
    &#x1F4E5; Export to Excel
  </a>
</div>

<?php if ($logins): ?>
<?= paginator($loginCount, $loginPage, PAGE_SIZE, $currentQS_logins, 'lp') ?>
<table class="au-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Name</th>
      <th>Institute</th>
      <th>Email</th>
      <th>Role</th>
      <th>Login Date &amp; Time</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $rowNum = ($loginPage - 1) * PAGE_SIZE + 1;
    foreach ($logins as $i => $r):
        $rowClass  = $i % 2 === 0 ? 'odd' : 'even';
        $fullName  = trim(($r['FstName'] ?? '') . ' ' . ($r['LstName'] ?? ''));
        // Fall back to the stored LoginName when no userinfo row exists
        $name      = htmlspecialchars($fullName !== '' ? Pii::name($fullName) : ($r['TrackLogin'] ?? '—'));
        $email     = htmlspecialchars(Pii::email($r['EMail'] ?? ''));
        $role      = htmlspecialchars($r['RoleDesc'] ?? '');
        $loginAt   = !empty($r['LoginAt']) ? date('d M Y, H:i', strtotime($r['LoginAt'])) : '—';
    ?>
    <tr class="<?= $rowClass ?>">
      <td><?= $rowNum++ ?></td>
      <td><?= $name ?></td>
      <td style="font-size:11px;"><?= htmlspecialchars($r['InstituteName'] ?? '—') ?></td>
      <td><?= $email ?></td>
      <td><?= $role ?></td>
      <td><?= $loginAt ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?= paginator($loginCount, $loginPage, PAGE_SIZE, $currentQS_logins, 'lp') ?>

<?php else: ?>
  <p style="color:#888;font-size:13px;">No login events match the current filters.</p>
<?php endif; ?>

<?php endif; // end tabs ?>

</div><!-- /.au-wrap -->

<script>
// ── Per-row status toggle (Students / Teachers / Inactive Users tabs) ──────
// Each tab renders one hidden #toggleForm_<tab> form (see above); the switch
// buttons themselves stay plain <button> elements (not their own <form>) so
// they can sit inside the Inactive Users tab's #bulkActivateForm without
// creating an invalid nested <form>.
function toggleUserActive(formId, userId, newStatus, verb) {
  if (!confirm('Are you sure you want to ' + verb + ' this user?')) return false;
  var f = document.getElementById(formId);
  if (!f) return false;
  f.querySelector('[name="user_ids[]"]').value = userId;
  f.querySelector('[name="new_status"]').value = newStatus;
  f.submit();
  return false;
}

<?php if ($tab === 'inactive'): ?>
// ── Bulk "Activate Selected" (Inactive Users tab only) ──────────────────────
// Selections persist across search/pagination via sessionStorage, same
// pattern as BulkAssignInstitute.php's student picker, so paging through a
// long inactive-users list never silently drops a partial selection.
var AU_INACTIVE_KEY = 'au_selected_inactive_users';

function auLoadSelected() {
  try { return JSON.parse(sessionStorage.getItem(AU_INACTIVE_KEY) || '[]'); } catch (e) { return []; }
}
function auSaveSelected(ids) { sessionStorage.setItem(AU_INACTIVE_KEY, JSON.stringify(ids)); }

function auToggleSelected(uid, checked) {
  uid = String(uid);
  var ids = auLoadSelected();
  if (checked) {
    if (ids.indexOf(uid) === -1) ids.push(uid);
  } else {
    ids = ids.filter(function (id) { return id !== uid; });
  }
  auSaveSelected(ids);
  auUpdateCount();
}
function auUpdateCount() {
  var n = auLoadSelected().length;
  var el = document.getElementById('auSelCount');
  if (el) el.textContent = n;
  var btn = document.getElementById('auActivateBtn');
  if (btn) btn.disabled = n === 0;
}
function auSelectAll(checked) {
  document.querySelectorAll('#inactiveList input[type=checkbox]').forEach(function (cb) {
    cb.checked = checked;
    auToggleSelected(cb.value, checked);
  });
}
function auClearSelection() {
  auSaveSelected([]);
  document.querySelectorAll('#inactiveList input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
  auUpdateCount();
}
function auRestoreSelections() {
  var ids = auLoadSelected();
  document.querySelectorAll('#inactiveList input[type=checkbox]').forEach(function (cb) {
    if (ids.indexOf(cb.value) !== -1) cb.checked = true;
  });
  auUpdateCount();
}

<?php if ($flashType === 'success'): ?>
sessionStorage.removeItem(AU_INACTIVE_KEY); // last bulk action succeeded — start clean
<?php endif; ?>

document.addEventListener('DOMContentLoaded', auRestoreSelections);

var bulkForm = document.getElementById('bulkActivateForm');
if (bulkForm) {
  bulkForm.addEventListener('submit', function (e) {
    var ids = auLoadSelected();
    if (!ids.length) {
      e.preventDefault();
      alert('Please select at least one user to activate.');
      return;
    }
    if (!confirm('Activate ' + ids.length + ' selected user(s)?')) {
      e.preventDefault();
      return;
    }
    // Carry over selections made on OTHER pages of this filtered list too —
    // not just the checkboxes currently visible in the DOM.
    var visible = new Set(Array.prototype.map.call(
      document.querySelectorAll('#inactiveList input[type=checkbox]'),
      function (cb) { return cb.value; }
    ));
    ids.forEach(function (id) {
      if (!visible.has(id)) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'user_ids[]';
        inp.value = id;
        bulkForm.appendChild(inp);
      }
    });
  });
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
