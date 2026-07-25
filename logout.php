<?php
/**
 * Logout Script
 * 
 * Destroys session and redirects to login page
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';

$auth = new Auth();
$auth->logout();

header('Location: login.php?success=logout');
exit;
