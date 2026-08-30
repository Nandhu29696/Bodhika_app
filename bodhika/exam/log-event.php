<?php
/**
 * exam/log-event.php — Cheating-detection event logger (AJAX, POST, JSON).
 *
 * Called by write.php JS whenever the student:
 *   • switches away from the tab      → type = tab_switch
 *   • copies text on the exam page    → type = copy
 *   • pastes text on the exam page    → type = paste
 *   • refreshes / navigates away      → type = browser_refresh
 *
 * Uses INSERT … ON DUPLICATE KEY UPDATE so a single row per
 * (UserId, ExamInfoId, EventType) accumulates counts cheaply.
 *
 * Requires migration_v29.sql (exam_events table).
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

header('Content-Type: application/json');

/* Must be logged in */
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    exit(json_encode(['ok' => false, 'error' => 'unauthenticated']));
}

/* Accept JSON body (sendBeacon sends text/plain; regular fetch sends JSON) */
$body = file_get_contents('php://input');
$data = $body ? json_decode($body, true) : null;

/* Also accept form-encoded (fallback) */
$examId    = (int)($data['examId']    ?? $_POST['examId']    ?? 0);
$eventType = trim($data['eventType']  ?? $_POST['eventType'] ?? '');

$allowed = ['tab_switch', 'copy', 'paste', 'browser_refresh'];
if ($examId <= 0 || !in_array($eventType, $allowed, true)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'invalid_params']));
}

$userId = Auth::currentUserId();
if ($userId <= 0) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'no_user']));
}

try {
    Database::execute(
        "INSERT INTO exam_events (UserId, ExamInfoId, EventType, EventCount, LastEventAt, CreatedAt)
              VALUES (?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
              EventCount  = EventCount + 1,
              LastEventAt = NOW()",
        [$userId, $examId, $eventType]
    );
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    /* Table not yet created (pre-migration_v29) — silently succeed so exam
       page doesn't break, but log the error server-side. */
    error_log('log-event.php: ' . $e->getMessage());
    echo json_encode(['ok' => true, 'note' => 'migration_v29 not applied']);
}
