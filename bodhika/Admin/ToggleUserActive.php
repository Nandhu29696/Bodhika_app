<?php
/**
 * Admin/ToggleUserActive.php
 *
 * Activates or deactivates one or more students/teachers from the
 * AdminUsers.php roster:
 *   - a single-row status-toggle switch on the Registered Students,
 *     Teachers, and Inactive Users tabs (posts one user_ids[] entry), or
 *   - the "Activate Selected" bulk action on the Inactive Users tab
 *     (posts many user_ids[] entries at once, new_status always 'Y').
 *
 * POST-only, CSRF-protected, admin-only.
 *
 * The UPDATE is a portable subquery — NOT a MySQL-only multi-table
 * "UPDATE ... JOIN ... SET" — because Lib/Database.php supports both the
 * MySQL driver and the Postgres cutover driver (see its docblock), and
 * only well-understood syntactic constructs get blindly translated between
 * the two. A subquery IN() works unchanged on both.
 *
 * Scoped to Role IN ('STDNT','TEACH') so this endpoint can never flip an
 * ADMIN (or any other role) account's status, even from a tampered request
 * body — defense in depth on top of AdminUsers.php never rendering a
 * toggle for non-student/teacher rows in the first place.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin()) {
    header('Location: ../exam/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: AdminUsers.php');
    exit;
}
Auth::validateCsrf();

/* ── Inputs ──────────────────────────────────────────────────────────── */
$userIds = array_values(array_unique(array_filter(
    array_map('intval', $_POST['user_ids'] ?? []),
    fn($id) => $id > 0
)));
$newStatus = ($_POST['new_status'] ?? '') === 'Y' ? 'Y' : 'N';

/* ── Apply ───────────────────────────────────────────────────────────── */
if (!$userIds) {
    $flash = 'error|Please select at least one user.';
} else {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    $affected = Database::execute(
        "UPDATE logininfo
            SET Active = ?
          WHERE Role IN ('STDNT','TEACH')
            AND LoginName IN (
                SELECT LoginName FROM userinfo WHERE UserInfoId IN ($placeholders)
            )",
        array_merge([$newStatus], $userIds)
    );

    $verb  = $newStatus === 'Y' ? 'activated' : 'deactivated';
    $flash = $affected > 0
        ? 'success|' . $affected . ' user(s) ' . $verb . '.'
        : 'error|No matching student/teacher accounts were updated — they may already be ' . ($newStatus === 'Y' ? 'active' : 'inactive') . '.';
}

/* ── Redirect back to the exact tab/filter/page the action came from ──────
   $_POST['return_qs'] is built server-side in AdminUsers.php from that
   tab's own already-known filter keys (see $currentQS_* there) — it never
   introduces anything beyond what AdminUsers.php itself already accepts as
   GET params, and header() rejects embedded newlines on its own, so this
   can't be turned into a header-injection or open-redirect vector. */
$tab = in_array($_POST['tab'] ?? '', ['students', 'teachers', 'inactive', 'logins'], true)
       ? $_POST['tab'] : 'inactive';

parse_str((string)($_POST['return_qs'] ?? ''), $qsArr);
$qsArr['tab']   = $tab;
$qsArr['flash'] = $flash;

header('Location: AdminUsers.php?' . http_build_query($qsArr));
exit;
