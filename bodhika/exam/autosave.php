<?php
/**
 * exam/autosave.php — Receives periodic draft-save POSTs from write.php.
 *
 * Called by JS every 60 s (fetch POST, JSON body).
 * Returns JSON: {"ok":true} or {"ok":false,"error":"..."}
 *
 * Also handles action=clear (called on successful form submit) to wipe drafts.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

header('Content-Type: application/json');

/* Must be logged in */
if (!Auth::isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

/* Autosave pings count as activity — without this, a student who spends
   more than SESSION_TIMEOUT writing an exam (radio-clicks only trigger
   this endpoint, not a full page load) gets silently logged out the
   moment they hit Submit, losing the whole attempt. Auth::requireLogin()
   is what normally refreshes last_activity, but it isn't (and shouldn't
   be) called on every lightweight autosave ping, so we refresh it here
   directly instead. */
Auth::touchActivity();

/* Must be POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$uid = (int)Auth::currentUserId();

/* Accept JSON body */
$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true);

$examId = (int)($body['examId'] ?? 0);
$action = $body['action'] ?? 'save';

if ($examId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'bad_exam_id']);
    exit;
}

/* ── action=clear: delete all drafts for this user+exam ─────────────────── */
if ($action === 'clear') {
    unset($_SESSION['exam_deadline']);
    try {
        Database::execute(
            "DELETE FROM exam_drafts WHERE ExamInfoId = ? AND UserInfoId = ?",
            [$examId, $uid]);
        echo json_encode(['ok' => true, 'action' => 'cleared']);
    } catch (Exception $e) {
        echo json_encode(['ok' => true, 'action' => 'cleared_noop']); // table may not exist
    }
    exit;
}

/* ── action=save: upsert per-question draft rows ─────────────────────────── */
$answers = $body['answers'] ?? [];  // ['123' => 'Answer2', '124' => 'Answer1,Answer3', ...]

if (!is_array($answers) || empty($answers)) {
    echo json_encode(['ok' => true, 'saved' => 0]);
    exit;
}

$saved = 0;
try {
    foreach ($answers as $qid => $ans) {
        $qid = (int)$qid;
        $ans = is_string($ans) ? substr(trim($ans), 0, 200) : '';
        if ($qid <= 0) continue;

        Database::execute(
            "INSERT INTO exam_drafts (ExamInfoId, UserInfoId, QuestionId, SelectedAnswer)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE SelectedAnswer = VALUES(SelectedAnswer),
                                     UpdatedAt      = CURRENT_TIMESTAMP",
            [$examId, $uid, $qid, $ans]);
        $saved++;
    }
    echo json_encode(['ok' => true, 'saved' => $saved]);
} catch (Exception $e) {
    /* exam_drafts table not yet created — silently succeed */
    echo json_encode(['ok' => true, 'saved' => 0, 'note' => 'table_missing']);
}
