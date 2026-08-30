<?php
/**
 * auth/logout.php
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';
Auth::logout();
// Preserve ?timeout=1 flag so login page shows the right message
$qs = isset($_GET['timeout']) ? '?timeout=1' : '';
header('Location: login.php' . $qs);
exit;
