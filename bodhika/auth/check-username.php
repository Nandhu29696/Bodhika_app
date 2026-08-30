<?php
/**
 * auth/check-username.php  — AJAX endpoint: is a username taken?
 * Returns JSON { "taken": true|false }
 */
require_once __DIR__ . '/../Lib/Config.php';
header('Content-Type: application/json');
$u = trim($_GET['u'] ?? '');
if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $u)) {
    echo json_encode(['taken' => true]);   // invalid format → treat as unavailable
    exit;
}
$row = Database::fetchOne(
    "SELECT LoginInfoId FROM logininfo WHERE LoginName = ? LIMIT 1", [$u]);
echo json_encode(['taken' => (bool)$row]);
