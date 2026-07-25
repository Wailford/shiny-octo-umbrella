<?php
// Simulate the export_analysis_pdf.php request with basic params
$_GET['class_id'] = 1;
$_GET['term'] = '3';
$_GET['academic_year'] = '2025/2026';

// Fake session
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['school_id'] = 56;

ini_set('display_errors', 1);
error_reporting(E_ALL);

ob_start();
try {
    include __DIR__ . '/../export_analysis_pdf.php';
} catch (Throwable $e) {
    echo "CAUGHT: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine();
}
$out = ob_get_clean();
echo substr($out, 0, 1000);
