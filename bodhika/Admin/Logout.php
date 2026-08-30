<?php
/**
 * Logout.php - Destroys session and clears the DB session token.
 */
require_once __DIR__ . '/../Lib/Config.php';
require_once __DIR__ . '/../Lib/Auth.php';

Auth::logout();

header('Location: ../index.php');
exit;
