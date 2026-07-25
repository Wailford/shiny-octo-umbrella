<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/SchoolController.php';
require_once __DIR__ . '/controllers/PromotionController.php';
require_once __DIR__ . '/controllers/ExportController.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireAdmin(); // Only admins can access settings

$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin(); // Check if user is admin
$isSuperAdmin = $auth->isSuperAdmin(); // Check if user is super admin
$promotionController = new PromotionController();
$exportController = new ExportController();

// Get school info
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();
$schoolInfo = null;
if (isset($_SESSION['school_id'])) {
    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
    $stmt->execute([$_SESSION['school_id']]);
    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

$hasSchoolCodeColumn = false;
try {
    $columnCheckStmt = $db->query("SHOW COLUMNS FROM school_info LIKE 'school_code'");
    $hasSchoolCodeColumn = (bool) $columnCheckStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $hasSchoolCodeColumn = false;
}

if (empty($schoolInfo)) {
    die('School context not found. Please log out and log in again.');
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_school_info':
                try {
                    // Handle headmaster signature upload
                    $signatureUrl = $schoolInfo['headmaster_signature'] ?? null;
                    if (isset($_FILES['headmaster_signature']) && $_FILES['headmaster_signature']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/uploads/signatures/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        
                        $fileExtension = strtolower(pathinfo($_FILES['headmaster_signature']['name'], PATHINFO_EXTENSION));
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                        
                        if (in_array($fileExtension, $allowedExtensions)) {
                            // Delete old signature if exists
                            if (!empty($schoolInfo['headmaster_signature'])) {
                                $oldSignaturePath = __DIR__ . '/' . $schoolInfo['headmaster_signature'];
                                if (file_exists($oldSignaturePath)) {
                                    unlink($oldSignaturePath);
                                }
                            }
                            
                            // Load and resize signature
                            $sourceImage = null;
                            switch ($fileExtension) {
                                case 'jpeg':
                                case 'jpg':
                                    $sourceImage = imagecreatefromjpeg($_FILES['headmaster_signature']['tmp_name']);
                                    break;
                                case 'png':
                                    $sourceImage = imagecreatefrompng($_FILES['headmaster_signature']['tmp_name']);
                                    break;
                                case 'gif':
                                    $sourceImage = imagecreatefromgif($_FILES['headmaster_signature']['tmp_name']);
                                    break;
                            }
                            if ($sourceImage === false) {
                                $sourceImage = null;
                                error_log('settings.php: Failed to load signature image from ' . $_FILES['headmaster_signature']['tmp_name']);
                                $error = 'Could not process the signature image. Please upload a valid image file.';
                            }
                            
                            if ($sourceImage) {
                                $originalWidth = imagesx($sourceImage);
                                $originalHeight = imagesy($sourceImage);
                                
                                // Set max dimensions (200px wide for signature)
                                $maxWidth = 200;
                                $maxHeight = 100;
                                
                                $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
                                $newWidth = round($originalWidth * $ratio);
                                $newHeight = round($originalHeight * $ratio);
                                
                                $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                                
                                // Preserve transparency
                                if ($fileExtension === 'png' || $fileExtension === 'gif') {
                                    imagealphablending($resizedImage, false);
                                    imagesavealpha($resizedImage, true);
                                    $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                                    imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
                                }
                                
                                imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
                                
                                $fileName = 'signature_' . uniqid() . '.png';
                                $targetPath = $uploadDir . $fileName;
                                
                                imagepng($resizedImage, $targetPath, 6);
                                
                                imagedestroy($sourceImage);
                                imagedestroy($resizedImage);
                                
                                $signatureUrl = 'uploads/signatures/' . $fileName;
                            }
                        }
                    }
                    
                    // Handle logo upload
                    $logo1Url = $schoolInfo['logo1_url'] ?? null;
                    
                    if (isset($_FILES['logo1']) && $_FILES['logo1']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = __DIR__ . '/uploads/logos/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        $allowedMimeTypes  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $uploadedExt  = strtolower(pathinfo($_FILES['logo1']['name'], PATHINFO_EXTENSION));
                        $imageInfo    = getimagesize($_FILES['logo1']['tmp_name']);
                        $uploadedMime = $imageInfo ? $imageInfo['mime'] : '';
                        if (in_array($uploadedExt, $allowedExtensions) && in_array($uploadedMime, $allowedMimeTypes)) {
                            // Delete old logo file to prevent orphaned uploads
                            if (!empty($schoolInfo['logo1_url'])) {
                                $oldLogoPath = __DIR__ . '/' . $schoolInfo['logo1_url'];
                                if (file_exists($oldLogoPath)) {
                                    unlink($oldLogoPath);
                                }
                            }
                            $fileName = 'logo1_' . uniqid() . '.' . $uploadedExt;
                            move_uploaded_file($_FILES['logo1']['tmp_name'], $uploadDir . $fileName);
                            $logo1Url = 'uploads/logos/' . $fileName;
                        } else {
                            $error = 'Invalid logo file. Only JPG, PNG, GIF, or WebP images are allowed.';
                        }
                    }
                    
                    $feesEnabled = isset($_POST['fees_enabled']) ? 1 : 0;

                    $updateFields = [
                        'school_name = ?',
                        'location = ?',
                        'address = ?',
                        'phone = ?'
                    ];
                    $updateParams = [
                        $_POST['school_name'],
                        $_POST['location'],
                        $_POST['address'],
                        $_POST['phone']
                    ];

                    if ($hasSchoolCodeColumn) {
                        $updateFields[] = 'school_code = ?';
                        $updateParams[] = $_POST['school_code'] ?? null;
                    }

                    $updateFields = array_merge($updateFields, [
                        'email = ?',
                        'headmaster_name = ?',
                        'academic_year = ?',
                        'current_term = ?',
                        'reopen_date = ?',
                        'logo1_url = ?',
                        'headmaster_signature = ?',
                        'district = ?',
                        'circuit = ?',
                        'fees_enabled = ?'
                    ]);
                    $updateParams = array_merge($updateParams, [
                        $_POST['email'],
                        $_POST['headmaster_name'],
                        $_POST['academic_year'],
                        $_POST['current_term'],
                        $_POST['reopen_date'],
                        $logo1Url,
                        $signatureUrl,
                        $_POST['district'] ?? null,
                        $_POST['circuit'] ?? null,
                        $feesEnabled
                    ]);

                    $sql = "UPDATE school_info SET " . implode(",\n                            ", $updateFields) . "\n                            WHERE id = ?";
                    $updateParams[] = $_SESSION['school_id'];

                    $stmt = $db->prepare($sql);
                    $stmt->execute($updateParams);
                    $message = 'School information updated successfully!';
                    
                    // Reload school info
                    $stmt = $db->prepare("SELECT * FROM school_info WHERE id = ?");
                    $stmt->execute([$_SESSION['school_id']]);
                    $schoolInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $error = 'Failed to update school information: ' . $e->getMessage();
                }
                break;
                
            case 'change_password':
                if ($_POST['new_password'] !== $_POST['confirm_password']) {
                    $error = 'New passwords do not match!';
                } else {
                    try {
                        $hashedPassword = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                        $sql = "UPDATE users SET password = ? WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$hashedPassword, $currentUser['id']]);
                        $message = 'Password changed successfully!';
                    } catch (Exception $e) {
                        $error = 'Failed to change password: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'add_user':
                try {
                    // Check if username already exists
                    $checkStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                    $checkStmt->execute([$_POST['username']]);
                    if ($checkStmt->fetch()) {
                        $error = 'Username "' . htmlspecialchars($_POST['username']) . '" already exists! Please choose a different username.';
                        break;
                    }
                    
                    $requestedUserType = $_POST['user_type'] ?? '';
                    $allowedRequestedTypes = ['admin', 'class_teacher', 'subject_teacher'];
                    if (!in_array($requestedUserType, $allowedRequestedTypes, true)) {
                        throw new Exception('Invalid user role selected.');
                    }

                    $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);

                    // Determine a safe school_type_id fallback if session value is missing
                    $schoolTypeId = $_SESSION['school_type_id'] ?? null;
                    if (empty($schoolTypeId) && !empty($_SESSION['school_id'])) {
                        $schoolTypeStmt = $db->prepare("SELECT school_type_id FROM school_info WHERE id = ? LIMIT 1");
                        $schoolTypeStmt->execute([$_SESSION['school_id']]);
                        $schoolTypeId = $schoolTypeStmt->fetchColumn() ?: null;
                    }

                    // Map UI role to auth role used across the app
                    $role = 'teacher';
                    if ($requestedUserType === 'admin') {
                        $role = 'admin';
                    } elseif ($requestedUserType === 'class_teacher') {
                        $role = 'form_master';
                    } elseif ($requestedUserType === 'subject_teacher') {
                        $role = 'subject_master';
                    }

                    $sql = "INSERT INTO users (username, password, full_name, user_type, school_type_id, school_id, role) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([
                        $_POST['username'],
                        $hashedPassword,
                        $_POST['full_name'],
                        $requestedUserType,
                        $schoolTypeId,
                        $_SESSION['school_id'] ?? null,
                        $role
                    ]);
                    $message = 'User added successfully!';
                } catch (Exception $e) {
                    $error = 'Failed to add user: ' . $e->getMessage();
                }
                break;
                
            case 'clear_candidate_indexes':
                try {
                    $schoolId = $_SESSION['school_id'];
                    
                    // Get all Basic 9 classes for this school
                    $stmt = $db->prepare("SELECT id FROM classes WHERE school_id = ? AND (class_name LIKE '%Basic 9%' OR class_name LIKE '%Basic Nine%' OR class_name LIKE '%Basic9%')");
                    $stmt->execute([$schoolId]);
                    $basic9Classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($basic9Classes)) {
                        $error = 'No Basic 9 classes found!';
                        break;
                    }
                    
                    // Clear all candidate index numbers
                    $classIds = array_column($basic9Classes, 'id');
                    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
                    $clearStmt = $db->prepare("UPDATE students SET candidate_index_number = NULL WHERE class_id IN ($placeholders)");
                    $clearStmt->execute($classIds);
                    
                    $message = 'All candidate index numbers cleared successfully!';
                } catch (Exception $e) {
                    $error = 'Failed to clear candidate index numbers: ' . $e->getMessage();
                }
                break;
                
            case 'generate_candidate_indexes':
                try {
                    require_once __DIR__ . '/controllers/StudentController.php';
                    $studentController = new StudentController();
                    $schoolId = $_SESSION['school_id'];

                    if (!$hasSchoolCodeColumn) {
                        $error = 'Candidate index generation requires the school_code column. Please run database/add_candidate_index_generation.sql first.';
                        break;
                    }
                    
                    // First check if school code is set
                    $schoolCodeStmt = $db->prepare("SELECT school_code FROM school_info WHERE id = ?");
                    $schoolCodeStmt->execute([$schoolId]);
                    $schoolInfo = $schoolCodeStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$schoolInfo || empty($schoolInfo['school_code'])) {
                        $error = 'School code is not set! Please set your 7-digit school code in School Information section first.';
                        break;
                    }
                    
                    // Get all Basic 9 classes for this school (supports "Basic 9", "Basic Nine", "Basic9", etc.)
                    $stmt = $db->prepare("SELECT id, class_name FROM classes WHERE school_id = ? AND (class_name LIKE '%Basic 9%' OR class_name LIKE '%Basic Nine%' OR class_name LIKE '%Basic9%')");
                    $stmt->execute([$schoolId]);
                    $basic9Classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($basic9Classes)) {
                        $error = 'No Basic 9 classes found! Please create a class with "Basic 9" in the name.';
                        break;
                    }
                    
                    // Clear existing candidate index numbers for regeneration
                    $classIds = array_column($basic9Classes, 'id');
                    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
                    $clearStmt = $db->prepare("UPDATE students SET candidate_index_number = NULL WHERE class_id IN ($placeholders)");
                    $clearStmt->execute($classIds);
                    
                    // Get ALL students from ALL Basic 9 classes in one query, ordered alphabetically by name
                    $stmt = $db->prepare("SELECT id, student_id, student_name, class_id FROM students WHERE class_id IN ($placeholders) ORDER BY student_name ASC");
                    $stmt->execute($classIds);
                    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $generated = 0;
                    $skipped = 0;
                    $sequentialNumber = 1; // Start from 001 for this generation run
                    $totalStudents = count($allStudents);
                    
                    // Process all students sequentially
                    foreach ($allStudents as $student) {
                        $candidateIndex = $studentController->generateCandidateIndexNumber($student['class_id'], $schoolId, $sequentialNumber);
                        if ($candidateIndex) {
                            $stmt = $db->prepare("UPDATE students SET candidate_index_number = ? WHERE id = ?");
                            $stmt->execute([$candidateIndex, $student['id']]);
                            $generated++;
                            $sequentialNumber++; // Increment for next student
                        } else {
                            $skipped++;
                        }
                    }
                    
                    if ($generated > 0) {
                        $message = "Successfully generated $generated candidate index numbers (001-" . str_pad($generated, 3, '0', STR_PAD_LEFT) . ")!";
                        if ($skipped > 0) {
                            $message .= " ($skipped skipped)";
                        }
                    } elseif ($totalStudents == 0) {
                        $error = 'No Basic 9 students found! Classes are empty.';
                    } else {
                        $error = 'Failed to generate candidate index numbers. All ' . $totalStudents . ' students were skipped. Check error logs.';
                    }
                } catch (Exception $e) {
                    $error = 'Failed to generate candidate index numbers: ' . $e->getMessage();
                }
                break;
                
            case 'reset_user_password':
                if (!$isAdmin) {
                    $error = 'Only administrators can reset user passwords!';
                } else {
                    try {
                        $userId = $_POST['user_id'];
                        $newPassword = $_POST['new_password'];
                        
                        // Verify user belongs to this school
                        $verifyStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND school_id = ?");
                        $verifyStmt->execute([$userId, $_SESSION['school_id']]);
                        
                        if (!$verifyStmt->fetch()) {
                            $error = 'User not found or does not belong to your school!';
                        } else {
                            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                            $sql = "UPDATE users SET password = ? WHERE id = ? AND school_id = ?";
                            $stmt = $db->prepare($sql);
                            $stmt->execute([$hashedPassword, $userId, $_SESSION['school_id']]);
                            $message = 'User password reset successfully. Please share the new password with the user securely.';
                        }
                    } catch (Exception $e) {
                        $error = 'Failed to reset password: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'delete_user':
                if ($_POST['user_id'] == $currentUser['id']) {
                    $error = 'You cannot delete your own account!';
                } else {
                    try {
                        $sql = "DELETE FROM users WHERE id = ? AND school_id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$_POST['user_id'], $_SESSION['school_id']]);
                        $message = 'User deleted successfully!';
                    } catch (Exception $e) {
                        $error = 'Failed to delete user: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'export_students':
                try {
                    $result = $exportController->exportAllStudentsToExcel();
                    if ($result['success']) {
                        header('Content-Type: text/csv; charset=utf-8');
                        header('Content-Disposition: attachment; filename="' . $result['file'] . '"');
                        header('Pragma: no-cache');
                        header('Expires: 0');
                        readfile($result['path']);
                        unlink($result['path']);
                        exit;
                    } else {
                        $error = $result['error'];
                    }
                } catch (Exception $e) {
                    $error = 'Export failed: ' . $e->getMessage();
                }
                break;
                
            case 'export_term_scores':
                try {
                    $result = $exportController->exportTermScores();
                    if ($result['success']) {
                        header('Content-Type: text/csv; charset=utf-8');
                        header('Content-Disposition: attachment; filename="' . $result['file'] . '"');
                        header('Pragma: no-cache');
                        header('Expires: 0');
                        readfile($result['path']);
                        unlink($result['path']);
                        exit;
                    } else {
                        $error = $result['error'];
                    }
                } catch (Exception $e) {
                    $error = 'Export failed: ' . $e->getMessage();
                }
                break;
                
            case 'promote_class':
                if (!$promotionController->canPromote()) {
                    $error = 'Promotions are only allowed in Third Term!';
                } else {
                    try {
                        $result = $promotionController->promoteClass($_POST['class_id'], $currentUser['id']);
                        if ($result['success']) {
                            $message = "Successfully promoted {$result['promoted']} out of {$result['total']} students!";
                        } else {
                            $error = $result['error'];
                        }
                    } catch (Exception $e) {
                        $error = 'Promotion failed: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'prepare_new_term':
                try {
                    // First export current term
                    $exportResult = $exportController->exportTermScores();
                    
                    // Then prepare new term
                    $result = $promotionController->prepareNewTerm(true);
                    
                    if ($result['success']) {
                        if ($exportResult['success']) {
                            $message = 'System prepared for new term! Scores exported to: ' . $exportResult['file'];
                            // Optionally download the file
                        } else {
                            $message = 'System prepared for new term! (Export failed: ' . $exportResult['error'] . ')';
                        }
                    } else {
                        $error = $result['error'];
                    }
                } catch (Exception $e) {
                    $error = 'Preparation failed: ' . $e->getMessage();
                }
                break;
                
            case 'delete_old_scores':
                try {
                    $academicYear = $_POST['academic_year'] ?? '';
                    $confirmDelete = isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'YES';
                    
                    if (empty($academicYear)) {
                        $error = 'Please select an academic year to delete';
                    } else {
                        $result = $exportController->exportAndDeleteOldScores($academicYear, $confirmDelete);
                        
                        if ($result['success']) {
                            if ($result['deleted']) {
                                $message = $result['message'] . '<br>Backup saved: ' . $result['backup_file'];
                            } else {
                                // Just backup created, offer download
                                $message = 'Backup created successfully! ' . $result['score_count'] . ' scores backed up.<br>';
                                $message .= '<strong>Ready to delete.</strong> Re-submit with confirmation to permanently delete.';
                            }
                        } else {
                            $error = $result['error'];
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Delete operation failed: ' . $e->getMessage();
                }
                break;

            case 'update_notification_settings':
                if (!$isSuperAdmin) {
                    $error = 'Only system administrators can update notification settings.';
                    break;
                }
                try {
                    $notifKeys = [
                        'zenoph_api_key', 'zenoph_sender_id', 'zenoph_whatsapp_sender',
                        'notification_email_from', 'notification_email_name', 'report_base_url',
                    ];
                    $stmtUNS = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
                    foreach ($notifKeys as $k) {
                        $v = trim($_POST[$k] ?? '');
                        $stmtUNS->execute([$k, $v, $v]);
                    }
                    $message = 'Notification settings saved successfully.';
                } catch (Exception $e) {
                    $error = 'Failed to save notification settings: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get all users for this school
$users = [];
if (isset($_SESSION['school_id'])) {
    $stmt = $db->prepare("SELECT id, username, full_name, user_type, role, created_at FROM users WHERE school_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['school_id']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Settings';
require_once __DIR__ . '/components/header.php';
?>
    <style>
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .settings-grid {
            display: grid;
            gap: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .card-header {
            background: #f7fafc;
            padding: 1.5rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .card-header h2 {
            color: #2d3748;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2d3748;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.6rem;
            border: 2px solid #e1e8ed;
            border-radius: 5px;
            font-size: 1rem;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            border-color: #667eea;
            outline: none;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-success:hover {
            background: #38a169;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .btn-danger:hover {
            background: #e53e3e;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .users-table th {
            padding: 0.8rem;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .users-table td {
            padding: 0.8rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .users-table tr:hover {
            background: #f7fafc;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .badge-admin {
            background: #feebc8;
            color: #c05621;
        }
        
        .badge-teacher {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .badge-form-master {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .badge-subject-teacher {
            background: #e9d8fd;
            color: #553c9a;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: #718096;
        }
        
        @media (max-width: 768px) {
            
            .container {
                padding: 1rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-group label {
                font-size: 0.9rem;
            }
            
            .form-group input,
            .form-group select,
            .form-group textarea {
                font-size: 16px; /* Prevents zoom on iOS */
            }
            
            .btn {
                width: 100%;
                padding: 0.75rem;
                font-size: 1rem;
            }
            
            .settings-card {
                padding: 1rem;
            }
            
            .settings-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                max-height: 90vh;
                overflow-y: auto;
            }
        }
    </style>

    <div class="container">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h1 style="margin: 0 0 10px 0; font-size: 28px;">⚙️ System Settings</h1>
            <p style="margin: 0; opacity: 0.9;">Manage school information, users, and teacher assignments</p>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 30px;">
            <a href="assign_subject_masters.php" style="background: #fff; border: 2px solid #007bff; border-radius: 8px; padding: 20px; text-decoration: none; color: #007bff; display: flex; align-items: center; gap: 15px; transition: all 0.3s;">
                <span style="font-size: 32px;">👨‍🏫</span>
                <div>
                    <strong style="display: block; font-size: 16px;">Subject Masters</strong>
                    <span style="font-size: 13px; opacity: 0.8;">Assign teachers to subjects</span>
                </div>
            </a>
            
            <a href="assign_form_masters.php" style="background: #fff; border: 2px solid #28a745; border-radius: 8px; padding: 20px; text-decoration: none; color: #28a745; display: flex; align-items: center; gap: 15px; transition: all 0.3s;">
                <span style="font-size: 32px;">📋</span>
                <div>
                    <strong style="display: block; font-size: 16px;"><?php echo ($_SESSION['school_type_id'] == 1) ? 'Class Teachers' : 'Form Masters'; ?></strong>
                    <span style="font-size: 13px; opacity: 0.8;">Assign class teachers</span>
                </div>
            </a>
            
            <a href="manage_classes.php" style="background: #fff; border: 2px solid #fd7e14; border-radius: 8px; padding: 20px; text-decoration: none; color: #fd7e14; display: flex; align-items: center; gap: 15px; transition: all 0.3s;">
                <span style="font-size: 32px;">📚</span>
                <div>
                    <strong style="display: block; font-size: 16px;">Manage Classes</strong>
                    <span style="font-size: 13px; opacity: 0.8;">Add custom classes (e.g., Nursery)</span>
                </div>
            </a>
            
            <?php if (in_array($_SESSION['school_type_id'], [2, 3])): // For JHS and Basic schools ?>
            <a href="mock_settings.php" style="background: #fff; border: 2px solid #6f42c1; border-radius: 8px; padding: 20px; text-decoration: none; color: #6f42c1; display: flex; align-items: center; gap: 15px; transition: all 0.3s;">
                <span style="font-size: 32px;">📝</span>
                <div>
                    <strong style="display: block; font-size: 16px;">Mock Examination</strong>
                    <span style="font-size: 13px; opacity: 0.8;">BECE Mock Exam Settings</span>
                </div>
            </a>
            <?php endif; ?>
            
            <a href="dashboard.php" style="background: #fff; border: 2px solid #ffc107; border-radius: 8px; padding: 20px; text-decoration: none; color: #856404; display: flex; align-items: center; gap: 15px; transition: all 0.3s;">
                <span style="font-size: 32px;">👥</span>
                <div>
                    <strong style="display: block; font-size: 16px;">Student Management</strong>
                    <span style="font-size: 13px; opacity: 0.8;">Manage students & records</span>
                </div>
            </a>
            
            <a href="change_password.php" style="background: #fff; border: 2px solid #dc3545; border-radius: 8px; padding: 20px; text-decoration: none; color: #dc3545; display: flex; align-items: center; gap: 15px; transition: all 0.3s;">
                <span style="font-size: 32px;">🔑</span>
                <div>
                    <strong style="display: block; font-size: 16px;">Change Password</strong>
                    <span style="font-size: 13px; opacity: 0.8;">Update your account password</span>
                </div>
            </a>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="settings-grid">
            <!-- School Information -->
            <div class="card">
                <div class="card-header">
                    <h2>School Information</h2>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_school_info">
                        
                        <div class="form-group">
                            <label>School Name</label>
                            <input type="text" name="school_name" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['school_name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>District</label>
                                <input type="text" name="district" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['district'] ?? ''); ?>" placeholder="e.g., MAMPONG MUNICIPAL">
                            </div>
                            <div class="form-group">
                                <label>Circuit</label>
                                <input type="text" name="circuit" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['circuit'] ?? ''); ?>" placeholder="e.g., MAMPONG CENTRAL">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Location</label>
                                <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['location'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>School Code (7 digits) <small style="color: #999;">- For BECE candidate index generation</small></label>
                                <input type="text" name="school_code" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['school_code'] ?? ''); ?>" pattern="\d{7}" maxlength="7" placeholder="e.g., 0514096" title="Must be exactly 7 digits">
                                <small style="color: #666; display: block; margin-top: 5px;">Example: 0514096 - This will generate candidate index like 051409600125 (school code + 001 + 25)</small>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['email'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Academic Year</label>
                                <input type="text" name="academic_year" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['academic_year'] ?? date('Y')); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Current Term</label>
                                <select name="current_term" class="form-control" required>
                                    <option value="First Term" <?php echo ($schoolInfo['current_term'] ?? '') == 'First Term' ? 'selected' : ''; ?>>First Term</option>
                                    <option value="Second Term" <?php echo ($schoolInfo['current_term'] ?? '') == 'Second Term' ? 'selected' : ''; ?>>Second Term</option>
                                    <option value="Third Term" <?php echo ($schoolInfo['current_term'] ?? '') == 'Third Term' ? 'selected' : ''; ?>>Third Term</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Reopen Date</label>
                                <input type="date" name="reopen_date" class="form-control" value="<?php $rd = $schoolInfo['reopen_date'] ?? ''; echo htmlspecialchars(($rd === '0000-00-00' || $rd === '0000-00-00 00:00:00') ? '' : $rd); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Address</label>
                            <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['address'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Headmaster Name</label>
                            <input type="text" name="headmaster_name" class="form-control" value="<?php echo htmlspecialchars($schoolInfo['headmaster_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>School Logo</label>
                            <?php if (!empty($schoolInfo['logo1_url'])): ?>
                                <div style="margin-bottom: 10px;">
                                    <img src="<?php echo htmlspecialchars($schoolInfo['logo1_url']); ?>" alt="School Logo" style="max-width: 100px; border: 1px solid #ddd; padding: 5px;">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="logo1" class="form-control" accept="image/*">
                        </div>
                        
                        <div class="form-group">
                            <label>Headmaster Signature</label>
                            <?php if (!empty($schoolInfo['headmaster_signature'])): ?>
                                <div style="margin-bottom: 10px;">
                                    <img src="<?php echo htmlspecialchars($schoolInfo['headmaster_signature']); ?>" alt="Current Signature" style="max-width: 200px; border: 1px solid #ddd; padding: 5px;">
                                    <p style="font-size: 12px; color: #666;">Current signature</p>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="headmaster_signature" class="form-control" accept="image/*">
                            <small style="color: #666;">Upload headmaster's signature (will be resized automatically)</small>
                        </div>
                        
                        <!-- ── Feature Modules ── -->
                        <div style="margin-top:1.5rem;padding:1rem 1.25rem;background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;">
                            <div style="font-weight:700;font-size:.95rem;color:#2d3748;margin-bottom:.8rem;">⚙️ Feature Modules</div>

                            <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer;padding:.6rem .8rem;background:#fff;border:1px solid #e2e8f0;border-radius:6px;">
                                <div style="position:relative;">
                                    <input type="checkbox" id="fees_enabled_toggle" name="fees_enabled" value="1"
                                           onchange="updateFeesToggleStyle(this)"
                                           <?php echo !empty($schoolInfo['fees_enabled']) ? 'checked' : ''; ?>
                                           style="opacity:0;position:absolute;width:0;height:0;">
                                    <div id="fees_toggle_track" style="width:44px;height:24px;border-radius:12px;transition:background .2s;
                                        background:<?php echo !empty($schoolInfo['fees_enabled']) ? '#38a169' : '#cbd5e0'; ?>;">
                                        <div id="fees_toggle_thumb" style="width:20px;height:20px;background:#fff;border-radius:50%;margin:2px;
                                            transition:transform .2s;transform:<?php echo !empty($schoolInfo['fees_enabled']) ? 'translateX(20px)' : 'translateX(0)'; ?>;
                                            box-shadow:0 1px 3px rgba(0,0,0,.25);"></div>
                                    </div>
                                </div>
                                <div>
                                    <span style="font-weight:600;color:#2d3748;">💰 Fees Management Module</span>
                                    <small style="display:block;color:#718096;font-size:.8rem;">Enable to collect, track fees and show outstanding balances on parent report cards. Disable to hide all fees features.</small>
                                </div>
                            </label>
                        </div>

                        <script>
                        function updateFeesToggleStyle(cb) {
                            document.getElementById('fees_toggle_track').style.background = cb.checked ? '#38a169' : '#cbd5e0';
                            document.getElementById('fees_toggle_thumb').style.transform  = cb.checked ? 'translateX(20px)' : 'translateX(0)';
                        }
                        document.getElementById('fees_enabled_toggle').addEventListener('change', function(){ updateFeesToggleStyle(this); });
                        </script>

                        <button type="submit" class="btn btn-primary" style="margin-top:1rem;">Update School Information</button>
                    </form>
                </div>
            </div>
            
            <!-- Change Password -->
            <div class="card">
                <div class="card-header">
                    <h2>Change Password</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" required minlength="6">
                        </div>
                        
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" required minlength="6">
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </form>
                </div>
            </div>
            
            <!-- Class & Streams Management -->
            <div class="card">
                <div class="card-header">
                    <h2>Class & Streams Management</h2>
                </div>
                <div class="card-body">
                    <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
                        <a href="manage_streams.php" class="btn btn-primary" style="width: 100%; padding: 1rem; text-decoration: none; text-align: center;">
                            🎓 Manage Class Streams (A, B, C, D)
                        </a>
                        <p style="font-size: 0.85rem; color: #666; margin: -0.5rem 0 0 0;">
                            Create and manage multiple streams for each class level. Perfect for schools with parallel classes like "Basic 8 A", "Basic 8 B", etc.
                        </p>
                    </div>
                    <div class="info-note" style="background: #ebf8ff; border-left-color: #3182ce; margin-top: 1rem;">
                        <strong>💡 Stream Features:</strong>
                        <ul style="margin: 0.5rem 0 0 1.5rem;">
                            <li>Create multiple streams (A, B, C, D) for any class</li>
                            <li>Each stream has separate students but same subjects</li>
                            <li>Teachers can teach multiple streams of the same subject</li>
                            <li>Reports show both stream-based and combined rankings</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Data Management -->
            <div class="card">
                <div class="card-header">
                    <h2>Data Management & Export</h2>
                </div>
                <div class="card-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="export_students">
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">
                                📊 Export All Students to Excel
                            </button>
                            <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">Export complete student records with scores</p>
                        </form>
                        
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="action" value="export_term_scores">
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">
                                📋 Export Current Term Scores
                            </button>
                            <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">Export detailed scores for current term</p>
                        </form>
                    </div>
                    
                    <!-- Database Space Management -->
                    <div class="info-note" style="background: #fff3cd; border-left-color: #ffc107; margin-top: 1.5rem;">
                        <strong>🗄️ Database Space Management</strong><br>
                        Free up database space by permanently deleting old academic year scores after backing them up to CSV files.
                    </div>
                    
                    <?php
                    // Get old academic years available for deletion
                    $oldYearsResult = $exportController->getOldAcademicYears();
                    if ($oldYearsResult['success'] && !empty($oldYearsResult['old_years'])):
                    ?>
                    <form method="POST" style="margin-top: 1rem; padding: 1rem; border: 2px solid #ff9800; border-radius: 8px; background: #fff8e1;">
                        <input type="hidden" name="action" value="delete_old_scores">
                        
                        <h3 style="margin: 0 0 1rem 0; color: #e65100;">⚠️ Permanently Delete Old Scores</h3>
                        
                        <div style="background: white; padding: 1rem; border-radius: 5px; margin-bottom: 1rem;">
                            <p style="margin: 0 0 0.5rem 0;"><strong>Current Academic Year:</strong> <?php echo htmlspecialchars($oldYearsResult['current_year']); ?></p>
                            <p style="margin: 0; font-size: 0.9rem; color: #666;">This year's scores will NOT be deleted (protected)</p>
                        </div>
                        
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Select Academic Year to Delete:</label>
                            <select name="academic_year" required style="width: 100%; padding: 0.75rem; border: 2px solid #ddd; border-radius: 5px; font-size: 1rem;">
                                <option value="">-- Choose Academic Year --</option>
                                <?php foreach ($oldYearsResult['old_years'] as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year['academic_year']); ?>">
                                        <?php echo htmlspecialchars($year['academic_year']); ?> 
                                        (<?php echo number_format($year['score_count']); ?> scores, <?php echo $year['term_count']; ?> terms)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="background: #ffebee; padding: 1rem; border-radius: 5px; border: 1px solid #ef5350; margin-bottom: 1rem;">
                            <p style="margin: 0 0 0.5rem 0; font-weight: 600; color: #c62828;">⚠️ IMPORTANT - Read Carefully:</p>
                            <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.9rem;">
                                <li><strong>Step 1:</strong> First click "Backup & Prepare" to create CSV backup</li>
                                <li><strong>Step 2:</strong> Download and verify the backup file</li>
                                <li><strong>Step 3:</strong> Type "YES" and click "Permanently Delete" to remove from database</li>
                                <li><strong>This action CANNOT be undone!</strong> Scores will be permanently deleted</li>
                                <li>Backup files saved in /backups/ folder</li>
                            </ul>
                        </div>
                        
                        <div style="margin-bottom: 1rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; padding: 1rem; background: white; border-radius: 5px; border: 2px solid #ff5722;">
                                <input type="checkbox" name="confirm_delete" value="YES" style="width: 20px; height: 20px;">
                                <span style="font-weight: 600; color: #d32f2f;">I have downloaded the backup and want to PERMANENTLY DELETE these scores</span>
                            </label>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-weight: 600;">
                                💾 Backup & Prepare
                            </button>
                            <button type="submit" class="btn" style="width: 100%; padding: 1rem; font-weight: 600; background: #d32f2f; color: white;" onclick="return confirm('Are you absolutely sure? This will PERMANENTLY delete all scores for the selected academic year. This action CANNOT be undone!');">
                                🗑️ Permanently Delete
                            </button>
                        </div>
                        
                        <p style="margin: 1rem 0 0 0; font-size: 0.85rem; color: #666; text-align: center;">
                            Recommended: Delete only after academic year ends and all reports are generated
                        </p>
                    </form>
                    <?php else: ?>
                    <div style="margin-top: 1rem; padding: 1rem; background: #e8f5e9; border-left: 4px solid #4caf50; border-radius: 5px;">
                        <p style="margin: 0; color: #2e7d32;">✅ No old academic years available for deletion. Only current year scores exist.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Candidate Index Generation - Only for JHS/Basic Schools -->
            <?php if (in_array($_SESSION['school_type_id'], [2, 3])): // For JHS and Basic schools ?>
            <div class="card">
                <div class="card-header">
                    <h2>BECE Candidate Index Generator</h2>
                </div>
                <div class="card-body">
                    <div class="info-note" style="background: #e6f7ff; border-left-color: #1890ff;">
                        <strong>📋 Auto-Generate Candidate Index Numbers</strong><br>
                        <ul style="margin: 0.5rem 0 0 1.5rem; font-size: 0.9rem;">
                            <li>Automatically generates index numbers for Basic 9 students</li>
                            <li>Format: School Code (7 digits) + Sequential (3 digits) + Year (2 digits)</li>
                            <li>Example: 0514096 + 001 + 25 = <strong>051409600125</strong></li>
                            <li>Only generates for students without existing index numbers</li>
                        </ul>
                    </div>
                    
                    <?php if (empty($schoolInfo['school_code'])): ?>
                        <div class="alert alert-error" style="margin-top: 1rem;">
                            <strong>⚠️ School Code Required</strong><br>
                            Please set your 7-digit school code above before generating candidate index numbers.
                        </div>
                    <?php else: ?>
                        <div style="background: #f0f9ff; padding: 1rem; border-radius: 5px; margin-top: 1rem;">
                            <p style="margin: 0 0 0.5rem 0; font-size: 0.9rem;"><strong>Your School Code:</strong> <?php echo htmlspecialchars($schoolInfo['school_code']); ?></p>
                            <p style="margin: 0; font-size: 0.9rem; color: #666;"><strong>Current Year:</strong> <?php echo date('Y'); ?> (<?php echo date('y'); ?>)</p>
                            <p style="margin: 0.5rem 0 0 0; font-size: 0.9rem; color: #666;"><strong>Next Index:</strong> <?php echo htmlspecialchars($schoolInfo['school_code']) . '001' . date('y'); ?> (and incrementing)</p>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                            <form method="POST" onsubmit="return confirm('Generate candidate index numbers for all Basic 9 students? This will clear existing numbers and regenerate from 001.');" style="margin: 0;">
                                <input type="hidden" name="action" value="generate_candidate_indexes">
                                <button type="submit" class="btn btn-success" style="width: 100%; padding: 1rem; font-weight: 600;">
                                    🎓 Generate Index Numbers
                                </button>
                            </form>
                            
                            <form method="POST" onsubmit="return confirm('Clear all candidate index numbers? This will remove all existing index numbers from Basic 9 students.');" style="margin: 0;">
                                <input type="hidden" name="action" value="clear_candidate_indexes">
                                <button type="submit" class="btn btn-danger" style="width: 100%; padding: 1rem; font-weight: 600;">
                                    🗑️ Clear All Index Numbers
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; // End JHS/Basic schools check ?>
            
            <!-- Term Preparation -->
            <div class="card">
                <div class="card-header">
                    <h2>Term & Year Management</h2>
                </div>
                <div class="card-body">
                    <div class="info-note" style="background: #fff3cd; border-left-color: #ffc107;">
                        <strong>⚠️ Important:</strong> Preparing for a new term will:
                        <ul style="margin: 0.5rem 0 0 1.5rem;">
                            <li>Export all current scores automatically</li>
                            <li>Delete all current scores from the system</li>
                            <li>Prepare system for fresh term data entry</li>
                        </ul>
                    </div>
                    
                    <form method="POST" onsubmit="return confirm('Are you sure you want to prepare the system for a new term? All current scores will be exported and deleted. This cannot be undone!');" style="margin-top: 1rem;">
                        <input type="hidden" name="action" value="prepare_new_term">
                        <button type="submit" class="btn" style="background: #ffc107; color: #000; width: 100%; padding: 1rem; font-weight: 600;">
                            🔄 Prepare System for New Term
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Student Promotion -->
            <?php
            $canPromote = $promotionController->canPromote();
            $currentTerm = $schoolInfo['current_term'] ?? 'N/A';
            ?>
            <div class="card">
                <div class="card-header">
                    <h2>Student Promotion</h2>
                </div>
                <div class="card-body">
                    <?php if (!$canPromote): ?>
                        <div class="alert alert-error">
                            <strong>Promotions Not Available</strong><br>
                            Student promotions are only allowed in Third Term.<br>
                            Current Term: <strong><?php echo htmlspecialchars($currentTerm); ?></strong>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success">
                            <strong>Promotions Available</strong><br>
                            You can now promote students to the next class level.
                        </div>
                        
                        <div class="info-note">
                            <strong>📚 Promotion Process:</strong>
                            <ul style="margin: 0.5rem 0 0 1.5rem;">
                                <li>All student scores will be archived</li>
                                <li>Current scores will be deleted</li>
                                <li>Students will be moved to next class</li>
                                <li>Promotion history will be recorded</li>
                            </ul>
                        </div>
                        
                        <?php
                        require_once __DIR__ . '/controllers/ClassController.php';
                        $classController = new ClassController();
                        $classes = $classController->getAllClasses($_SESSION['school_type_id'] ?? null);
                        ?>
                        
                        <form method="POST" onsubmit="return confirm('Are you sure you want to promote all students in this class? This action cannot be undone!');" style="margin-top: 1rem;">
                            <input type="hidden" name="action" value="promote_class">
                            <div class="form-group">
                                <label>Select Class to Promote</label>
                                <select name="class_id" class="form-control" required>
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php 
                                                require_once __DIR__ . '/controllers/ClassController.php';
                                                echo htmlspecialchars(ClassController::getDisplayName($class));
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">
                                ⬆️ Promote Class to Next Level
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- User Management -->
            <div class="card">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h2>User Management</h2>
                    <button class="btn btn-success" onclick="showAddUserModal()">+ Add User</button>
                </div>
                <div class="card-body">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): 
                                // Determine display role
                                $displayRole = 'Teacher';
                                $roleClass = 'teacher';
                                $userRole = $user['role'] ?? $user['user_type'] ?? 'teacher';
                                
                                if ($userRole === 'admin' || $user['user_type'] === 'admin') {
                                    $displayRole = 'Administrator';
                                    $roleClass = 'admin';
                                } elseif ($userRole === 'form_master') {
                                    $displayRole = ($_SESSION['school_type_id'] == 1) ? 'Class Teacher' : 'Form Master';
                                    $roleClass = 'form-master';
                                } elseif ($userRole === 'subject_master' || $userRole === 'subject_teacher') {
                                    $displayRole = 'Subject Teacher';
                                    $roleClass = 'subject-teacher';
                                } elseif ($userRole === 'teacher' || $user['user_type'] === 'class_teacher') {
                                    $displayRole = 'Teacher';
                                    $roleClass = 'teacher';
                                }
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $roleClass; ?>">
                                            <?php echo $displayRole; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['id'] != $currentUser['id']): ?>
                                            <button type="button" class="btn btn-primary btn-sm" onclick="showResetPasswordModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Reset Password</button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color: #718096; font-size: 0.875rem;">Current User</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add User Modal -->
    <div class="modal" id="addUserModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New User</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label>Role</label>
                    <select name="user_type" required>
                        <option value="admin">Admin</option>
                        <option value="class_teacher">Class Teacher</option>
                        <option value="subject_teacher">Subject Teacher</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Add User</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reset Password Modal -->
    <div class="modal" id="resetPasswordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Reset User Password</h2>
                <button class="close-btn" onclick="closeResetModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_user_password">
                <input type="hidden" name="user_id" id="reset_user_id">
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="reset_username" readonly style="background: #f7fafc;">
                </div>
                
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="new_password" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" id="confirm_password" required minlength="6">
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeResetModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showAddUserModal() {
            document.getElementById('addUserModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('addUserModal').classList.remove('active');
        }
        
        function showResetPasswordModal(userId, username) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_username').value = username;
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_password').value = '';
            document.getElementById('resetPasswordModal').classList.add('active');
        }
        
        function closeResetModal() {
            document.getElementById('resetPasswordModal').classList.remove('active');
        }
        
        // Validate password confirmation
        document.addEventListener('DOMContentLoaded', function() {
            const resetForm = document.querySelector('#resetPasswordModal form');
            if (resetForm) {
                resetForm.addEventListener('submit', function(e) {
                    const newPass = document.getElementById('new_password').value;
                    const confirmPass = document.getElementById('confirm_password').value;
                    
                    if (newPass !== confirmPass) {
                        e.preventDefault();
                        alert('Passwords do not match!');
                        return false;
                    }
                });
            }
        });
    </script>

<?php
// ── Notification Settings Section (Super Admin Only) ──────────────────────────
if ($isSuperAdmin):
    require_once __DIR__ . '/helpers/NotificationService.php';
    $notifSettings = NotificationService::loadSettings($db);
?>
<!-- ── Notification Settings Section ───────────────────────────────────── -->
<div class="container" style="margin-top:0;">
    <div class="card">
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:0.25rem;">📲 Notification Settings</h2>
        <p style="color:#718096;font-size:0.88rem;margin-bottom:1.25rem;">
            Configure the SMS / WhatsApp (smsonlinegh.com) and email credentials used when sending report cards to parents.
        </p>

        <?php if ($message && strpos($message, 'Notification') !== false): ?>
            <div style="background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:0.75rem 1rem;border-radius:6px;margin-bottom:1rem;">
                ✅ <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="update_notification_settings">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">

                <div>
                    <label style="display:block;font-weight:600;margin-bottom:0.3rem;">
                        Zenoph / smsonlinegh API Key
                        <small style="font-weight:400;color:#718096;display:block;">From your smsonlinegh.com account → API settings</small>
                    </label>
                    <input type="text" name="zenoph_api_key"
                           value="<?php echo htmlspecialchars($notifSettings['zenoph_api_key']); ?>"
                           placeholder="Paste your API key here"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e0;border-radius:6px;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;margin-bottom:0.3rem;">
                        SMS Sender ID
                        <small style="font-weight:400;color:#718096;display:block;">Must be approved in your smsonlinegh account</small>
                    </label>
                    <input type="text" name="zenoph_sender_id"
                           value="<?php echo htmlspecialchars($notifSettings['zenoph_sender_id']); ?>"
                           placeholder="e.g. MYSCHOOL"
                           maxlength="11"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e0;border-radius:6px;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;margin-bottom:0.3rem;">
                        WhatsApp Sender ID
                        <small style="font-weight:400;color:#718096;display:block;">Leave blank to use SMS Sender ID</small>
                    </label>
                    <input type="text" name="zenoph_whatsapp_sender"
                           value="<?php echo htmlspecialchars($notifSettings['zenoph_whatsapp_sender']); ?>"
                           placeholder="Optional"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e0;border-radius:6px;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;margin-bottom:0.3rem;">
                        Email From Address
                        <small style="font-weight:400;color:#718096;display:block;">Used as the sender for email notifications</small>
                    </label>
                    <input type="email" name="notification_email_from"
                           value="<?php echo htmlspecialchars($notifSettings['notification_email_from']); ?>"
                           placeholder="noreply@yourschool.edu"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e0;border-radius:6px;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;margin-bottom:0.3rem;">
                        Email From Name
                        <small style="font-weight:400;color:#718096;display:block;">Display name shown to email recipients</small>
                    </label>
                    <input type="text" name="notification_email_name"
                           value="<?php echo htmlspecialchars($notifSettings['notification_email_name']); ?>"
                           placeholder="e.g. ABC School"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e0;border-radius:6px;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;margin-bottom:0.3rem;">
                        Report Base URL
                        <small style="font-weight:400;color:#718096;display:block;">Public URL included in messages so parents can view the full report online. Leave blank to omit the link.</small>
                    </label>
                    <input type="url" name="report_base_url"
                           value="<?php echo htmlspecialchars($notifSettings['report_base_url']); ?>"
                           placeholder="https://yourschool.edu/SBA"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e0;border-radius:6px;font-size:0.9rem;">
                </div>

            </div>

            <div style="margin-top:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">💾 Save Notification Settings</button>
                <a href="manage_parents.php" style="color:#667eea;text-decoration:none;font-size:0.9rem;">
                    👨‍👩‍👧 Go to Parent Contacts →
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/components/footer.php'; ?>
