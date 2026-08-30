<?php
/**
 * Admin/TogglePiiMask.php — flips the global "Show/Hide PII" toggle
 * (Lib/Pii.php) for the current admin session, and logs the flip to
 * pii_access_log (migration_v62) for audit purposes.
 *
 * POST-only, CSRF-protected, admin/institute-admin only. Redirects back to
 * whichever page the toggle button was clicked from ('return' — validated
 * as a same-app relative path so this can't be turned into an open
 * redirect by a tampered POST body).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
require_once __DIR__ . '/../Lib/Pii.php';
Auth::requireLogin('../auth/login.php');
if (!Auth::isAdmin() && !Auth::isInstituteAdmin()) { header('Location: ../auth/login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../exam/search.php'); exit; }
Auth::validateCsrf();

/* ── Flip the toggle ──────────────────────────────────────────────────── */
$wasUnmasked = Pii::isUnmasked();
Pii::setUnmasked(!$wasUnmasked);

/* ── Audit log (best-effort — never block the toggle on a logging failure,
   and never let a pre-migration_v62 database 500 this page) ────────────── */
try {
    Database::execute(
        "INSERT INTO pii_access_log (LoginName, Action, IpAddress, PageContext)
         VALUES (?, ?, ?, ?)",
        [
            Auth::currentUser() ?: 'unknown',
            $wasUnmasked ? 'Remask' : 'Unmask',
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr((string)($_POST['return'] ?? ''), 0, 255),
        ]
    );
} catch (Exception $e) { /* migration_v62 not yet run — toggle still works, just unaudited */ }

/* ── Redirect back — only ever to a relative, same-app path ─────────────── */
$return = (string)($_POST['return'] ?? '');
$safe   = ($return !== '' && $return[0] === '/' && !str_starts_with($return, '//') && !str_contains($return, '://'))
    ? $return
    : '../exam/search.php';

header('Location: ' . $safe);
exit;
