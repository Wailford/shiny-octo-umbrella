<?php
// download_generated_reports.php
// Download the generated reports ZIP file

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin();

if (!isset($_GET['file'])) {
    die('No file specified');
}

$file = $_GET['file'];
// Security: strip all directory components — only the filename is allowed
$file     = basename($file);
$filePath = __DIR__ . '/temp/pdf/' . $file;
// Verify the resolved path is still inside the expected directory (defence-in-depth)
$realBase = realpath(__DIR__ . '/temp/pdf');
$realFile = realpath($filePath);
if ($realFile === false || $realBase === false || strpos($realFile, $realBase) !== 0) {
    die('Invalid file path');
}

if (!file_exists($filePath)) {
    die('File not found');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache');

readfile($filePath);

// Optional: Delete file after download
// @unlink($filePath);
exit;
