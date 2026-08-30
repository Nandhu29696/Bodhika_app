<?php
/**
 * exam/proctor-violation.php  — AJAX endpoint: log a proctoring violation.
 *
 * Called by write.php's lockdown JS each time a focus-loss / tab-switch /
 * fullscreen-exit event is detected.  Fire-and-forget from the client side.
 *
 * Request body (JSON):
 *   { examId, type, violationNum, csrf_token }
 *
 * Response (JSON):
 *   { ok: true|false }
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

header('Content-Type: application/json');

// Must be logged in and a POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// Decode JSON body
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
}

// CSRF check — compare token from JSON payload against session
// (Auth::validateCsrf() is a form-POST helper; for JSON AJAX we check directly)
$csrfToken   = $data['csrf_token'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$examId       = filter_var($data['examId']       ?? 0, FILTER_VALIDATE_INT);
$violationNum = filter_var($data['violationNum'] ?? 1, FILTER_VALIDATE_INT);
$rawType      = $data['type'] ?? 'focus_lost';

// Whitelist violation types
$allowedTypes = ['focus_lost', 'tab_switch', 'fullscreen_exit'];
$vType = in_array($rawType, $allowedTypes, true) ? $rawType : 'focus_lost';

if (!$examId || $violationNum < 1) {
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

$userInfoId = Auth::currentUserId();

// Verify the exam actually has proctor_lock enabled (ignore spoofed requests)
try {
    $row = Database::fetchOne(
        "SELECT proctor_lock FROM examinfo WHERE ExamInfoId = ? LIMIT 1",
        [$examId]);

    if (!$row || empty($row['proctor_lock'])) {
        echo json_encode(['ok' => false, 'error' => 'not_locked']);
        exit;
    }
} catch (Exception $e) {
    // proctor_lock column not yet added — migration_proctor.sql not run
    echo json_encode(['ok' => false, 'error' => 'migration_needed']);
    exit;
}

// Insert the violation log row
try {
    Database::execute(
        "INSERT INTO proctor_violations (ExamInfoId, UserInfoId, ViolationType, ViolationNum)
         VALUES (?, ?, ?, ?)",
        [$examId, $userInfoId, $vType, (int)$violationNum]);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    // Table not created yet — still return ok so JS doesn't break
    echo json_encode(['ok' => false, 'error' => 'db_error']);
}
