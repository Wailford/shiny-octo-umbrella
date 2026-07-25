<?php
/**
 * School Based Assessment System Configuration File
 * 
 * Configure your database connection and application settings here
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookie: SameSite=Lax prevents cross-site request forgery
    session_set_cookie_params([
        'lifetime' => 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Error reporting (DISABLED FOR PRODUCTION - Enable only for debugging)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// Database Configuration
// Use environment variables when provided, otherwise fall back to local defaults.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'school_management_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// Application Configuration
define('APP_NAME', 'School Based Assessment System');
define('APP_VERSION', '1.0.0');
// Auto-detect URL on localhost; use env var or production domain on the server
if (getenv('APP_URL')) {
    define('APP_URL', rtrim(getenv('APP_URL'), '/'));
} elseif (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1'], true)) {
    $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__base   = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\');
    define('APP_URL', $__scheme . '://' . $_SERVER['HTTP_HOST'] . $__base);
    unset($__scheme, $__base);
} else {
    define('APP_URL', 'https://sba.techlawsoftwares.com');
}

// Directory Configuration
define('BASE_DIR', dirname(__DIR__));
define('UPLOAD_DIR', BASE_DIR . '/uploads');
define('TEMP_DIR', BASE_DIR . '/temp');

// Security Configuration
define('SESSION_LIFETIME', 3600); // 1 hour
define('PASSWORD_MIN_LENGTH', 6);

// PDF Configuration
define('PDF_LOGO_PATH', BASE_DIR . '/assets/images/logo.png');
define('PDF_TEMP_DIR', TEMP_DIR . '/pdf');

// File Upload Configuration
define('MAX_UPLOAD_SIZE', 5242880); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif']);

// Dropdown Options
define('DROPDOWN_OPTIONS', [
    'interest' => [
        'Leadership Role',
        'Culture',
        'Reading Arithmetics',
        'Drawing',
        'Experimenting',
        'Problem Solving',
        'Running Errands',
        'Socializing',
        'Singing'
    ],
    'conduct' => [
        'Very Good',
        'Good',
        'Satisfactory'
    ],
    'formMasterRemarks' => [
        'Could do better',
        'Should Sit up and learn',
        'Keep it up',
        'Must buck Up',
        'Hard working',
        'More room for improvement',
        'Not Serious with studies',
        'Has improved'
    ],
    'headMasterRemarks' => [
        'Should learn hard next term',
        'Should be encouraged at home',
        'Needs advice at home',
        'Could do better',
        'More room for improvement',
        'Keep it up',
        'Should be punctual at school'
    ],
    'promoted' => [
        'Promoted',
        'Not Promoted'
    ],
    'toClass' => [
        'Basic Seven',
        'Basic Eight',
        'Basic Nine'
    ],
    'term' => [
        'First Term',
        'Second Term',
        'Third Term'
    ]
]);

// School Types
define('SCHOOL_TYPES', [
    'PRIMARY' => [
        'id' => 1,
        'name' => 'Primary School',
        'code' => 'PRIMARY',
        'classes' => ['Basic1', 'Basic2', 'Basic3', 'Basic4', 'Basic5', 'Basic6']
    ],
    'JHS' => [
        'id' => 2,
        'name' => 'Junior High School',
        'code' => 'JHS',
        'classes' => ['BasicSeven', 'BasicEight', 'BasicNine']
    ],
    'BASIC' => [
        'id' => 3,
        'name' => 'Basic School',
        'code' => 'BASIC',
        'classes' => ['Basic1', 'Basic2', 'Basic3', 'Basic4', 'Basic5', 'Basic6', 'BasicSeven', 'BasicEight', 'BasicNine']
    ]
]);

// Score Components Configuration
define('SCORE_COMPONENTS', [
    'test1' => ['name' => 'Test 1', 'max' => 15, 'percentage' => 10],
    'group_work' => ['name' => 'Group Work', 'max' => 15, 'percentage' => 20],
    'test2' => ['name' => 'Test 2', 'max' => 15, 'percentage' => 10],
    'project_work' => ['name' => 'Project Work', 'max' => 15, 'percentage' => 10],
    'exam_score' => ['name' => 'Exam', 'max' => 100, 'percentage' => 50]
]);

// Grading System - Load from database (admin customizable)
require_once __DIR__ . '/../helpers/GradingSystem.php';
require_once __DIR__ . '/database.php';

// Load grading system from database or use default
$dynamicGrades = GradingSystem::getGradesArray();
$gradingSystem = [];
foreach ($dynamicGrades as $grade) {
    $gradingSystem[] = [
        'min' => floatval($grade['min_score']),
        'max' => floatval($grade['max_score']),
        'grade' => $grade['grade'],
        'remarks' => $grade['remarks']
    ];
}
define('GRADING_SYSTEM', $gradingSystem);

// Create necessary directories if they don't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0755, true);
}
if (!file_exists(PDF_TEMP_DIR)) {
    mkdir(PDF_TEMP_DIR, 0755, true);
}

// Timezone
date_default_timezone_set('Africa/Accra');

// Load language support
require_once __DIR__ . '/language.php';
