<?php
// generate_reports_job.php
// Background job to generate all class reports as PDFs

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/config/database.php';

// Check if mPDF is installed
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die(json_encode(['success' => false, 'message' => 'mPDF not installed']));
}

require_once __DIR__ . '/vendor/autoload.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin();

if (!isset($_POST['class_id']) || !is_numeric($_POST['class_id'])) {
    die(json_encode(['success' => false, 'message' => 'Invalid class ID']));
}

$classId = (int)$_POST['class_id'];
$jobId = 'job_' . $classId . '_' . time();

$db = Database::getInstance()->getConnection();
$studentController = new StudentController();

// Fetch class name
$stmt = $db->prepare("SELECT class_name FROM classes WHERE id = ? LIMIT 1");
$stmt->execute([$classId]);
$classRow = $stmt->fetch(PDO::FETCH_ASSOC);
$className = $classRow['class_name'] ?? 'class_' . $classId;

$students = $studentController->getStudentsByClass($classId);
if (!$students || count($students) === 0) {
    die(json_encode(['success' => false, 'message' => 'No students found']));
}

// Create job directory
$jobDir = __DIR__ . '/temp/pdf/' . $jobId;
if (!is_dir($jobDir)) {
    mkdir($jobDir, 0755, true);
}

// Save job status
$statusFile = $jobDir . '/status.json';
file_put_contents($statusFile, json_encode([
    'status' => 'processing',
    'total' => count($students),
    'completed' => 0,
    'class_name' => $className
]));

// Return job ID immediately
echo json_encode(['success' => true, 'job_id' => $jobId, 'total' => count($students)]);
flush();

// Close connection to browser
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Continue processing in background
set_time_limit(0);
ini_set('memory_limit', '1G');

$completed = 0;
$files = [];

foreach ($students as $student) {
    try {
        $_GET['class'] = $classId;
        $_GET['student'] = $student['id'];

        ob_start();
        $reportPath = __DIR__ . '/student-report.php';
        include $reportPath;
        $html = ob_get_clean();

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 3,
            'margin_right' => 3,
            'margin_top' => 3,
            'margin_bottom' => 3,
            'tempDir' => $jobDir
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
        $filePath = $jobDir . '/' . $safeName . '_' . $student['id'] . '.pdf';
        $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);
        
        $files[] = $filePath;
        $completed++;
        
        // Update progress
        file_put_contents($statusFile, json_encode([
            'status' => 'processing',
            'total' => count($students),
            'completed' => $completed,
            'class_name' => $className
        ]));
        
        unset($mpdf);
        gc_collect_cycles();
    } catch (Exception $e) {
        error_log("PDF failed for student {$student['id']}: " . $e->getMessage());
    }
}

// Create ZIP
$zipPath = $jobDir . '/' . $className . '_reports.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
    foreach ($files as $f) {
        $zip->addFile($f, basename($f));
    }
    $zip->close();
    
    // Update status to completed
    file_put_contents($statusFile, json_encode([
        'status' => 'completed',
        'total' => count($students),
        'completed' => $completed,
        'class_name' => $className,
        'download_file' => $jobId . '/' . $className . '_reports.zip'
    ]));
    
    // Delete individual PDFs to save space
    foreach ($files as $f) {
        @unlink($f);
    }
} else {
    file_put_contents($statusFile, json_encode([
        'status' => 'error',
        'message' => 'Failed to create ZIP file'
    ]));
}
