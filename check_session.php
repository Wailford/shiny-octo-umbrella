<?php
/**
 * Session Check
 * 
 * Verifies that a user is logged in before allowing access to a page.
 * This file is included in pages that require authentication.
 */

// config.php already handles session_start() and includes database.php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';

$auth = new Auth();
$auth->requireLogin();

// The user is logged in, and their session integrity has been validated.
// You can now access $_SESSION variables safely.
