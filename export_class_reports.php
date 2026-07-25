<?php
// export_class_reports.php
// Generates a ZIP containing PDF report files for every student in a class.

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/config/database.php';

// Check if mPDF is installed
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('Please install mPDF first. Run: composer require mpdf/mpdf');
}

require_once __DIR__ . '/vendor/autoload.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin();

if (!isset($_GET['class']) || !is_numeric($_GET['class'])) {
    http_response_code(400);
    echo "Missing or invalid class id";
    exit;
}
$classId = (int)$_GET['class'];

$db = Database::getInstance()->getConnection();
$studentController = new StudentController();

// Verify user has a school_id
$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id) {
    http_response_code(403);
    echo "School ID not found in session";
    exit;
}

// Fetch class name and verify it belongs to the user's school
$stmt = $db->prepare("SELECT class_name, school_id FROM classes WHERE id = ? LIMIT 1");
$stmt->execute([$classId]);
$classRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$classRow) {
    http_response_code(404);
    echo "Class not found";
    exit;
}

if ($classRow['school_id'] != $school_id) {
    http_response_code(403);
    echo "Unauthorized: Class does not belong to your school";
    exit;
}

$className = $classRow['class_name'] ?? 'class_' . $classId;

$students = $studentController->getStudentsByClass($classId);
if (!$students || count($students) === 0) {
    http_response_code(404);
    echo "No students found for selected class.";
    exit;
}

$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sba_reports_' . time();
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

$files = [];

// Set time limit for large batches
set_time_limit(300);
ini_set('memory_limit', '512M');

foreach ($students as $student) {
    // Render the student-report page for each student using output buffering
    $_GET_backup = $_GET;
    $_GET['class'] = $classId;
    $_GET['student'] = $student['id'];

    ob_start();
    // Include the standalone report template (use backup if present)
    $reportPath = __DIR__ . '/student-report.php';
    if (!file_exists($reportPath)) {
        // try backup version
        $reportPath = __DIR__ . '/student-report.php.backup';
    }
    include $reportPath;
    $html = ob_get_clean();

    // restore $_GET
    $_GET = $_GET_backup;

    // Convert HTML to PDF with optimized settings
    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 3,
            'margin_right' => 3,
            'margin_top' => 3,
            'margin_bottom' => 3,
            'margin_header' => 0,
            'margin_footer' => 0,
            'tempDir' => $tempDir
        ]);
        
        $mpdf->showImageErrors = false;
        
        // Extract only the report content and styles
        $styles = '';
        $reportContent = '';
        
        // Extract all styles
        if (preg_match('/<style[^>]*>(.*?)<\/style>/s', $html, $styleMatches)) {
            $styles = $styleMatches[1];
        }
        
        // Extract the report div with all its content
        if (preg_match('/<div id="report" class="report">(.*?)<\/div>\s*<\/div>\s*(?=<div class="buttons|<script)/s', $html, $reportMatches)) {
            $reportContent = '<div class="report">' . $reportMatches[1] . '</div></div>';
        } elseif (preg_match('/<div id="report"[^>]*>.*?<\/div>\s*<\/div>/s', $html, $reportMatches)) {
            $reportContent = $reportMatches[0];
        } else {
            // Fallback: use entire HTML
            $reportContent = $html;
        }
        
        // Build clean HTML with only report styles and content
        $cleanHTML = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $styles . '</style></head><body>' . $reportContent . '</body></html>';
        
        $mpdf->WriteHTML($cleanHTML);
        
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $student['student_name'] ?? 'student_' . $student['id']);
        $filePath = $tempDir . DIRECTORY_SEPARATOR . $safeName . '_' . $student['id'] . '.pdf';
        $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);
        $files[] = $filePath;
        
        // Free memory
        unset($mpdf);
        gc_collect_cycles();
    } catch (Exception $e) {
        error_log("PDF generation failed for student {$student['id']}: " . $e->getMessage());
    }
}

$zipPath = $tempDir . DIRECTORY_SEPARATOR . $className . '_reports.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
    http_response_code(500);
    echo "Could not create ZIP file";
    exit;
}

// Add files with compression
foreach ($files as $f) {
    $zip->addFile($f, basename($f));
    $zip->setCompressionName(basename($f), ZipArchive::CM_DEFLATE, 6);
}
$zip->close();

// Stream zip to user with optimized headers
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Stream file in chunks for better performance
$handle = fopen($zipPath, 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);

// Cleanup
foreach ($files as $f) {
    @unlink($f);
}
@unlink($zipPath);
@rmdir($tempDir);
exit;
